<?php
/**
 * Checks on a subject page's written text (page-writing procedure, step 4).
 *
 * Two kinds:
 *
 *   1. Quick checks, quickTextChecks(): house style rules that can be
 *      checked exactly, in a fraction of a second. The editor runs them on
 *      the saved text every time it opens, so a problem stays in view
 *      until it's fixed.
 *
 *   2. The AI check, aiTextCheck(): reads the text, with placeholders
 *      filled in, against the page's figures and the official source
 *      pages they cite (downloaded fresh), and lists statements that
 *      contradict them or look wrong. It takes up to a minute, so it runs
 *      when you click "Check with AI", on whatever is in the boxes, saved
 *      or not. The result is kept in subject_text_checks (migration 013)
 *      with a fingerprint of the text, so the editor can say whether the
 *      text has changed since.
 *
 * Neither ever stops a save. (Invalid placeholders do; see placeholders.php.)
 */

require_once __DIR__ . '/ai-proposer.php';  // callClaude()
require_once __DIR__ . '/source-pages.php'; // fetchPageText(), SKIP_SOURCE_URLS, readSourcePagesForFacts()
require_once __DIR__ . '/lessons.php';       // writingGuidanceText(): house style and lessons

/** The four text fields, with the names the editor shows. */
const TEXT_FIELD_LABELS = [
    'intro'            => 'Intro',
    'explanation'      => 'Explanation',
    'meta_title'       => 'Meta title',
    'meta_description' => 'Meta description',
];

/** The house style's ceiling for an explanation (docs/house-style/core.md, section 6). */
const EXPLANATION_MAX_WORDS = 800;
/** The meta description's length in characters, with figures filled in (the editor's counter uses the same). */
const META_DESCRIPTION_MIN = 140;
const META_DESCRIPTION_MAX = 155;
/** The meta title's most characters with figures filled in; " — Finance Fact Library" is added after it (docs/house-style/core.md). */
const META_TITLE_MAX = 42;

/** A figure checked this often or more is a rate (house style, section 2). */
const RATE_REVIEW_DAYS = 42;

/** Most characters of each source page sent to the AI check. */
const TEXT_CHECK_SOURCE_CHARACTERS = 50000;

/** Most tokens the AI check's reply may use. The check asks for at most 12 findings; the ceiling is well above that so a long list is not cut off mid-finding. */
const TEXT_CHECK_MAX_TOKENS = 8000;

// ------------------------------------------------------------
// Numbers in text
// ------------------------------------------------------------

/**
 * Every way one of a fact's figures might be typed, as plain numbers
 * without thousands separators: 241.30 -> 241.30 and 241.3; 3.5 -> 3.50;
 * a 2026/27 tax year -> 2026 and 27.
 */
function factNumberForms(array $fact): array
{
    $forms = [];
    foreach ([$fact['value'], formatFactValue($fact)] as $written) {
        if (preg_match_all('/\d[\d,]*(?:\.\d+)?/', (string) $written, $m)) {
            foreach ($m[0] as $number) {
                $forms[str_replace(',', '', $number)] = true;
            }
        }
    }
    if ($fact['value_numeric'] !== null) {
        $n = (float) $fact['value_numeric'];
        $forms[rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.')] = true; // 241.30 -> 241.3
        $forms[number_format($n, 2, '.', '')] = true;                         // 3.5 -> 3.50
    }
    if (!empty($fact['tax_year']) && preg_match('#^(\d{4})/(\d{2})$#', $fact['tax_year'], $m)) {
        $forms[$m[1]] = true;
        $forms[$m[2]] = true;
    }
    return array_keys($forms);
}

/**
 * The numbers in a fact's label: the 21 in "National Living Wage (21 and
 * over)", the 18 and 20 in "18 to 20". Readers see the label on the figure
 * card, so a text that mentions "21" is saying the same thing the page
 * already does, and isn't flagged as an unknown number.
 *
 * Deliberately NOT part of factNumberForms(): those map a typed number to
 * the fact it's the value of, to suggest its placeholder, and a label
 * number is a category, not a value. (It could also hide a wrong figure that
 * happens to equal a label number, which is why the AI check still reads
 * the text.)
 */
function factLabelNumbers(array $fact): array
{
    $numbers = [];
    if (preg_match_all('/(?<![\w.])\d[\d,]*(?:\.\d+)?/', (string) ($fact['label'] ?? ''), $m)) {
        foreach ($m[0] as $written) {
            $numbers[str_replace(',', '', rtrim($written, ',.'))] = true;
        }
    }
    return array_keys($numbers);
}

/**
 * The numbers typed in a text, as written => plain (no commas). Left out:
 * anything in a {placeholder} (fact keys like sl_plan2_threshold), typed
 * tax years (checked separately), whole numbers under 10 ("Plan 2",
 * "3 steps") and years from 1900 to 2100.
 */
function typedNumbers(string $text): array
{
    $text = preg_replace(PLACEHOLDER_PATTERN, ' ', $text);
    $text = preg_replace('#\b\d{4}\s*[/\x{2013}-]\s*\d{2}\b#u', ' ', $text);

    $numbers = [];
    if (preg_match_all('/(?<![\w.])\d[\d,]*(?:\.\d+)?/', $text, $m)) {
        foreach ($m[0] as $written) {
            // A number at the end of a clause picks up its comma or full stop.
            $written = rtrim($written, ',.');
            $plain = str_replace(',', '', $written);
            $value = (float) $plain;
            $isSmallWhole = $value < 10 && strpos($plain, '.') === false;
            $isYear = strpos($plain, '.') === false && $value >= 1900 && $value <= 2100;
            if (!$isSmallWhole && !$isYear) {
                $numbers[$written] = $plain;
            }
        }
    }
    return $numbers;
}

/**
 * Numbers in a text that don't match any of the given figures, as
 * written. Used for AI drafts and by the quick checks.
 */
function findUnmatchedNumbers(string $text, array $facts): array
{
    $known = [];
    foreach ($facts as $fact) {
        foreach (factNumberForms($fact) as $form) {
            $known[$form] = true;
        }
        foreach (factLabelNumbers($fact) as $number) {
            $known[$number] = true;
        }
    }

    $unmatched = [];
    foreach (typedNumbers($text) as $written => $plain) {
        if (!isset($known[$plain])) {
            $unmatched[] = $written;
        }
    }
    return array_values(array_unique($unmatched));
}

// ------------------------------------------------------------
// Sentences that follow a source too closely
// ------------------------------------------------------------

/** A sentence is flagged when this share of its words match a source sentence, in order. */
const CLOSE_COPY_THRESHOLD = 0.6;

/** Sentences with fewer words than this are not compared (short facts can't be reworded). */
const CLOSE_COPY_MIN_WORDS = 8;
/** The most words of each source page the second (word-stream) pass reads. */
const CLOSE_COPY_STREAM_WORDS = 40000;
/**
 * Sentences in a text that follow a source page's wording too closely.
 *
 * When drafts are written from the sources, the writer tends to copy
 * their sentences with a few words changed. That is poor for readers (the
 * page adds nothing the source doesn't say) and for search (it is not
 * original), and a person rewriting from the draft tends to keep the
 * structure. The check is deterministic: each sentence is compared with
 * each source sentence by the words they share IN THE SAME ORDER. A score
 * of 1 is identical; the threshold is 0.6.
 *
 * $text         the text to check (Markdown headings are skipped)
 * $sourceTexts  url => page text, from readSourcePagesForFacts()
 * $sourceNames  url => name for the message (optional)
 *
 * Returns, strongest match first, a list of
 *   ['quote' => the sentence as written, 'score' => 0..1, 'source' => name,
 *    'matched' => the source sentence it follows, as the source wrote it]
 * The quote is an exact part of $text. 'matched' is shown to the writer by the
 * automatic rewrite (includes/ai-drafts.php), so it knows what to avoid.
 */
function findCloseCopies(string $text, array $sourceTexts, array $sourceNames = []): array
{
    $words = function (string $s): array {
        preg_match_all('/[\p{L}\p{N}]+/u', strtolower(str_replace(["\u{2019}", "\u{2018}"], "'", $s)), $m);
        return $m[0];
    };
    $sentencesOf = fn(string $t): array => preg_split('/(?<=[.!?])\s+|\n+/u', $t, -1, PREG_SPLIT_NO_EMPTY);

    // The source sentences, as word lists (and word sets, for a quick first test).
    $source = [];
    foreach ($sourceTexts as $url => $pageText) {
        foreach (array_slice($sentencesOf($pageText), 0, 4000) as $sentence) {
            $w = $words($sentence);
            if (count($w) >= 6) {
                $source[] = ['w' => $w, 'set' => array_flip($w), 'name' => $sourceNames[$url] ?? $url, 'text' => trim($sentence)];
            }
        }
    }

    // The source pages as streams of words, for the second pass below. The pages
    // the site reads have every run of whitespace turned into one space (see
    // readPageText()), so they have no line breaks: a bullet with no full stop is
    // glued to the sentences around it, and splitting into sentences can't be relied on.
    $streams = [];
    foreach ($sourceTexts as $url => $pageText) {
        if (preg_match_all('/[\p{L}\p{N}]+/u', (string) $pageText, $m, PREG_OFFSET_CAPTURE)) {
            $tokens = array_slice($m[0], 0, CLOSE_COPY_STREAM_WORDS);
            $streams[] = [
                'name' => $sourceNames[$url] ?? $url,
                'text' => (string) $pageText,
                'w'    => array_map(fn($t) => strtolower($t[0]), $tokens),
                'from' => array_column($tokens, 1),
                'to'   => array_map(fn($t) => $t[1] + strlen($t[0]), $tokens),
            ];
        }
    }
    if (!$source) {
        return [];
    }

    $found = [];
    foreach ($sentencesOf($text) as $sentence) {
        $sentence = trim($sentence);
        // Headings are labels, not sentences.
        if ($sentence === '' || $sentence[0] === '#') {
            continue;
        }
        $a = $words(preg_replace('/^\s*(?:[-*]|\d+\.)\s+/', '', $sentence));
        $n = count($a);
        if ($n < CLOSE_COPY_MIN_WORDS) {
            continue;
        }

        $best = 0.0;
        $bestName = '';
        $bestText = '';
        foreach ($source as $candidate) {
            $m = count($candidate['w']);
            // A score of 0.6 needs the two sentences to share at least 0.3 x (n + m) words.
            $shared = 0;
            foreach ($a as $word) {
                if (isset($candidate['set'][$word])) {
                    $shared++;
                }
            }
            if ($shared < CLOSE_COPY_THRESHOLD / 2 * ($n + $m)) {
                continue;
            }
            // Words in common, in the same order (longest common subsequence).
            $previous = array_fill(0, $m + 1, 0);
            for ($i = 1; $i <= $n; $i++) {
                $current = [0];
                for ($j = 1; $j <= $m; $j++) {
                    $current[$j] = $a[$i - 1] === $candidate['w'][$j - 1]
                        ? $previous[$j - 1] + 1
                        : max($previous[$j], $current[$j - 1]);
                }
                $previous = $current;
            }
            $score = 2 * $previous[$m] / ($n + $m);
            if ($score > $best) {
                $best = $score;
                $bestName = $candidate['name'];
                $bestText = $candidate['text'];
            }
        }
        // Second pass, for what the sentence-by-sentence comparison can miss: a
        // sentence that follows a stretch of the page that doesn't line up with
        // the page's own sentences (a list, a wrapped line, a heading in between).
        if ($best < CLOSE_COPY_THRESHOLD && $streams) {
            $window = closeCopyWindowMatch($a, $streams);
            if ($window !== null && $window['score'] > $best) {
                $best = $window['score'];
                $bestName = $window['name'];
                $bestText = $window['text'];
            }
        }
        if ($best >= CLOSE_COPY_THRESHOLD) {
            $found[] = ['quote' => $sentence, 'score' => $best, 'source' => $bestName, 'matched' => preg_replace('/\s+/u', ' ', $bestText)];
        }
    }

    usort($found, fn($x, $y) => $y['score'] <=> $x['score']);
    return $found;
}

/**
 * The stretch of a source page's words that a sentence follows most closely,
 * wherever it sits, whatever the page's sentence or line breaks.
 *
 * A window a little longer than the sentence slides along each page's words.
 * Where the window shares enough words with the sentence, the words they
 * have in common IN THE SAME ORDER are counted, and the score is the same as in
 * findCloseCopies() (2 x common / (sentence + stretch)), where the stretch is the
 * part of the window from the first matching word to the last, so a window that
 * is longer than the passage doesn't lower the score.
 *
 * $a        the sentence's words (lower case)
 * $streams  the pages, as built in findCloseCopies()
 *
 * Returns ['score', 'name', 'text' (the source's own words for the stretch)] or null.
 */
function closeCopyWindowMatch(array $a, array $streams): ?array
{
    $n = count($a);
    $length = max($n + 6, (int) ceil($n * 1.4));
    $step = max(2, intdiv($n, 4));
    $set = array_flip($a);
    $best = null;

    foreach ($streams as $stream) {
        $w = $stream['w'];
        $total = count($w);
        if ($total < 6) {
            continue;
        }
        // prefix[i]: how many of the first i words are words the sentence uses.
        $prefix = [0];
        foreach ($w as $i => $word) {
            $prefix[$i + 1] = $prefix[$i] + (isset($set[$word]) ? 1 : 0);
        }

        $start = 0;
        while (true) {
            $end = min($start + $length, $total);
            $m = $end - $start;
            if ($prefix[$end] - $prefix[$start] >= CLOSE_COPY_THRESHOLD / 2 * ($n + $m)) {
                // Longest common subsequence of the sentence and this window.
                $table = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
                for ($i = 1; $i <= $n; $i++) {
                    for ($j = 1; $j <= $m; $j++) {
                        $table[$i][$j] = $a[$i - 1] === $w[$start + $j - 1]
                            ? $table[$i - 1][$j - 1] + 1
                            : max($table[$i - 1][$j], $table[$i][$j - 1]);
                    }
                }
                $common = $table[$n][$m];
                if ($common >= CLOSE_COPY_MIN_WORDS && $common >= CLOSE_COPY_THRESHOLD / 2 * $n) {
                    // Walk back through the table to find where the match begins and ends.
                    $i = $n;
                    $j = $m;
                    $lastJ = null;
                    $firstJ = null;
                    while ($i > 0 && $j > 0) {
                        if ($a[$i - 1] === $w[$start + $j - 1]) {
                            $lastJ ??= $j;
                            $firstJ = $j;
                            $i--;
                            $j--;
                        } elseif ($table[$i - 1][$j] >= $table[$i][$j - 1]) {
                            $i--;
                        } else {
                            $j--;
                        }
                    }
                    $span = $lastJ - $firstJ + 1;
                    $score = 2 * $common / ($n + $span);
                    if ($best === null || $score > $best['score']) {
                        $from = $stream['from'][$start + $firstJ - 1];
                        $to = $stream['to'][$start + $lastJ - 1];
                        $best = [
                            'score' => $score,
                            'name'  => $stream['name'],
                            'text'  => substr($stream['text'], $from, min($to - $from, 600)),
                        ];
                    }
                }
            }
            if ($end >= $total) {
                break;
            }
            $start += $step;
        }
    }
    return $best;
}

/** A quote cut to at most $max words, as an exact beginning of the sentence. */
function quoteWords(string $sentence, int $max = 25): string
{
    return preg_match('/^(?:\S+\s+){0,' . ($max - 1) . '}\S+/u', $sentence, $m) ? $m[0] : $sentence;
}

/**
 * The numbers a fact's VALUE is written as (12570 for £12,570; 4.35 and 4.350 for 4.35%),
 * without the tax-year parts that factNumberForms() also returns. Used to tell when a
 * typed number is a card's figure typed out by hand (see quickTextChecks()). The tax-year
 * parts are left out because "27" in "2026/27" is not the figure.
 */
function factValueForms(array $fact): array
{
    $forms = [];
    foreach ([$fact['value'] ?? '', formatFactValue($fact)] as $written) {
        if (preg_match_all('/\d[\d,]*(?:\.\d+)?/', (string) $written, $m)) {
            foreach ($m[0] as $number) {
                $forms[str_replace(',', '', rtrim($number, ',.'))] = true;
            }
        }
    }
    if (($fact['value_numeric'] ?? null) !== null) {
        $n = (float) $fact['value_numeric'];
        $forms[rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.')] = true;
        $forms[number_format($n, 2, '.', '')] = true;
    }
    return array_keys($forms);
}

// ------------------------------------------------------------
// 1. Quick checks
// ------------------------------------------------------------

/**
 * House style rules checked exactly, on the text as typed (placeholders
 * not filled in). Returns a list of sentences, one per problem.
 *
 * $fields  intro, explanation, meta_title, meta_description => text
 * $facts   the page's facts (only published ones count)
 */
function quickTextChecks(array $fields, array $facts): array
{
    $published = array_values(array_filter($facts, fn($f) => ($f['status'] ?? 'published') === 'published'));

    // Which fact each typed number belongs to, which numbers appear in the
    // figures' labels (see factLabelNumbers()), and which facts are rates.
    $factForNumber = [];
    $valueForNumber = []; // as $factForNumber, but only the figures' values (no tax-year parts)
    $labelNumbers = [];
    $rateKeys = [];
    foreach ($published as $fact) {
        foreach (factNumberForms($fact) as $form) {
            $factForNumber[$form] ??= $fact;
        }
        foreach (factValueForms($fact) as $form) {
            $valueForNumber[$form] ??= $fact;
        }
        foreach (factLabelNumbers($fact) as $number) {
            $labelNumbers[$number] = true;
        }
        if ((int) $fact['review_frequency_days'] > 0 && (int) $fact['review_frequency_days'] <= RATE_REVIEW_DAYS) {
            $rateKeys[$fact['fact_key']] = $fact;
        }
    }

    $notes = [];
    foreach (TEXT_FIELD_LABELS as $field => $label) {
        $text = (string) ($fields[$field] ?? '');
        if (trim($text) === '') {
            continue;
        }
        $isMeta = in_array($field, ['meta_title', 'meta_description'], true);

        // --- Typed numbers. ---
        $unmatched = [];
        foreach (typedNumbers($text) as $written => $plain) {
            $fact = $factForNumber[$plain] ?? null;
            if ($fact === null) {
                // A number in a card's label is on the page already, so it isn't unknown.
                if (!isset($labelNumbers[$plain])) {
                    $unmatched[] = $written;
                }
            } elseif ($isMeta && (!empty($fact['tax_year']) || isset($rateKeys[$fact['fact_key']]))) {
                // Search listings must stay current without anyone editing
                // them. Only figures that change are flagged: rates, and
                // figures tied to a tax year. Fixed ones (like the £100,000
                // taper threshold) may be typed, as the house style allows.
                if ($field === 'meta_description' && isset($rateKeys[$fact['fact_key']])) {
                    // A search description is text too: the rate-page rule applies (see below).
                    $notes[] = "$label: $written is typed, and it's a rate ({$fact['label']}). "
                        . "The house style is not to state rates on rate pages, and a search description counts as text.";
                } else {
                    $notes[] = "$label: $written is typed. Use {fact:{$fact['fact_key']}} so it stays current.";
                }
            } elseif (!$isMeta && isset($rateKeys[$fact['fact_key']])) {
                $notes[] = "$label: $written is typed, and it's a rate ({$fact['label']}). "
                    . "The house style is not to state rates in the text.";
            } elseif (!$isMeta && ($valueFact = $valueForNumber[$plain] ?? null) !== null && !isset($labelNumbers[$plain])) {
                // A card's figure typed by hand. The card updates when the figure changes and the text
                // doesn't: on VAT a typed 90,000 and 88,000 sat in the text and nothing flagged them,
                // because they matched the cards. (If it is a different figure that happens to be
                // equal, or one fixed in law until a date the text gives, it can stay.)
                $notes[] = "$label: $written is typed, and it is the same as the {$valueFact['label']} figure. "
                    . "Use {fact:{$valueFact['fact_key']}} so it updates with the card, unless it is a different figure "
                    . "that happens to be equal or one fixed in law until a date the text gives.";
            }
        }
        if ($unmatched) {
            $notes[] = "$label: " . implode(', ', $unmatched) . ' '
                . (count($unmatched) === 1 ? "isn't one of" : "aren't among") . ' the page\'s figures or their labels. '
                . 'Fine if fixed or worked out from fixed figures; otherwise check '
                . (count($unmatched) === 1 ? 'it.' : 'them.');
        }

        // --- Rates shown through a placeholder, in the intro, explanation or meta description. ---
        // The meta description is included: on Premium Bonds the first draft stated the prize
        // fund rate and the odds there, through placeholders, and only the AI check noticed.
        // (The meta TITLE is left alone: rate pages' titles name the subject, not its rate.)
        if ($field !== 'meta_title' && preg_match_all('/\{fact:([a-z0-9_]+)\}/', $text, $m)) {
            foreach (array_unique($m[1]) as $key) {
                if (isset($rateKeys[$key])) {
                    $notes[] = "$label: {fact:$key} shows a rate ({$rateKeys[$key]['label']}). "
                        . 'The house style is not to state rates in the text, even as a placeholder'
                        . ($isMeta ? ', and a search description counts as text.' : '.');
                }
            }
        }

        // --- Typed tax years: {tax_year} moves on by itself. ---
        if (preg_match_all('#\b\d{4}\s*[/\x{2013}-]\s*\d{2}\b#u', $text, $m)) {
            $notes[] = "$label: " . implode(', ', array_unique($m[0])) . ' typed. Use {tax_year} for the current tax year.';
        }

        // --- Words that date the text. "now" and "new" only in lower case,
        // so "New Zealand" and a sentence starting "Now" in a quote don't count. ---
        $dating = [];
        if (preg_match_all('/\b(currently|this year|at the moment|recently|latest|today)\b/i', $text, $m)) {
            $dating = array_merge($dating, array_map('strtolower', $m[1]));
        }
        if (preg_match_all('/\b(now|new)\b/', $text, $m)) {
            $dating = array_merge($dating, $m[1]);
        }
        if ($dating) {
            $notes[] = "$label: \"" . implode('", "', array_unique($dating)) . '" dates the text. '
                . 'The house style avoids these words, since pages are read for years.';
        }

        // --- Advice. ---
        if (preg_match_all('/\b(you should|we recommend|we suggest|it\'?s best to|make sure you)\b/i', $text, $m)) {
            $notes[] = "$label: \"" . implode('", "', array_unique(array_map('strtolower', $m[1]))) . '" reads as advice. '
                . 'Say what the rules are rather than what to do.';
        }
    }

    // --- Length. ---
    $explanationWords = preg_match_all('/\S+/u', (string) ($fields['explanation'] ?? ''));
    if ($explanationWords > EXPLANATION_MAX_WORDS) {
        $notes[] = "Explanation: $explanationWords words, over the house style's limit of " . EXPLANATION_MAX_WORDS
            . '. Cut it to the points readers most often get wrong; the cards show the figures.';
    }

    // --- A generic closing section. ---
    if (preg_match('/^##\s*(what to check|get advice|next steps|find out more)\b/im', (string) ($fields['explanation'] ?? ''), $m)) {
        $notes[] = "Explanation: the \"" . trim($m[0], "# \t") . '" section is the generic kind the house style leaves out; '
            . 'the site disclaimer covers it.';
    }

    return $notes;
}

// ------------------------------------------------------------
// 2. The AI check
// ------------------------------------------------------------

/** What each kind of AI finding means, as the editor shows it. */
const TEXT_CHECK_KINDS = [
    'contradicts_source' => 'Contradicts a source',
    'contradicts_figure' => 'Conflicts with a figure',
    'likely_wrong'       => 'Probably wrong',
    'missing_region'     => 'Regional difference missing',
    'missing_exception'  => 'Exception or condition missing',
    'heading_mismatch'   => "Heading doesn't match its text",
    'duplicated'         => 'Said twice',
    'unneeded_section'   => 'Section not needed',
    'house_style'        => 'Breaks the house style',
    'copied_from_source' => 'Too close to a source',
    'advice'             => 'Reads as advice',
];

/**
 * The kinds that are about how the page is written rather than whether it
 * is right. The editor shows them all, but only the others count towards
 * the "AI check found problems" review flag (includes/review-flags.php):
 * a style finding you've decided to accept would otherwise flag the page
 * for good, since an AI finding can't be dismissed.
 */
const TEXT_CHECK_STYLE_KINDS = ['duplicated', 'unneeded_section', 'house_style', 'copied_from_source', 'advice'];

/**
 * A fingerprint of the four fields, so a stored check can be matched to
 * the text it checked. Whitespace differences don't count, so the text
 * in the boxes and the same text once saved match.
 */
function textCheckHash(array $fields): string
{
    $normalised = [];
    foreach (array_keys(TEXT_FIELD_LABELS) as $field) {
        $normalised[$field] = trim(preg_replace('/\s+/u', ' ', (string) ($fields[$field] ?? '')));
    }
    return hash('sha256', json_encode($normalised, JSON_UNESCAPED_UNICODE));
}

/**
 * Check the four fields against the page's figures and source pages.
 *
 * $subject  from getAdminSubject()
 * $facts    from getAdminFactsForSubject() (only published ones are used)
 * $fields   intro, explanation, meta_title, meta_description => text as
 *           typed (placeholders are filled in here)
 *
 * Returns:
 *   findings  list of ['field', 'kind', 'quote', 'problem']; only those
 *             whose quote really is in the text are kept
 *   sources   list of ['name', 'url', 'read' => true/false, 'error', 'hash'];
 *             hash is the same fingerprint check-sources.php stores in
 *             sources.content_hash, so a later change to the page shows
 *   dropped   how many findings were dropped for misquoting the text
 */
function aiTextCheck(array $subject, array $facts, array $fields): array
{
    $published = array_values(array_filter($facts, fn($f) => $f['status'] === 'published'));
    $countryCode = (string) $subject['country_code'];

    // The text as readers see it.
    $filled = [];
    foreach (array_keys(TEXT_FIELD_LABELS) as $field) {
        $filled[$field] = (string) fillPlaceholders((string) ($fields[$field] ?? ''), $countryCode, $published);
    }
    if (trim(implode('', $filled)) === '') {
        throw new RuntimeException('There is no text to check yet.');
    }

    // --- The source pages the page's figures cite, read fresh. Each page's
    // fingerprint is kept too, so scripts/check-page-text.php can tell when
    // a page has changed since this check. ---
    $read = readSourcePagesForFacts($published, TEXT_CHECK_SOURCE_CHARACTERS);
    $sources = $read['sources'];
    $sourceTexts = $read['texts'];

    // --- Ask, and read the reply. ---
    // The JSON is taken from the first { to the last }, so a sentence or a
    // ```json fence around it doesn't matter. (Findings are short, so JSON
    // is safe here, unlike the long explanations in AI drafts.)
    //
    // A reply that is not valid JSON as a whole is no longer fatal. The first
    // polish test on Pension Tax Relief failed at check 1 with a reply that
    // began as a findings list and then stopped being valid JSON (cut off at the
    // old 4,000-token ceiling, or an unescaped quote inside a finding). So:
    //   1. the ceiling is 8,000 tokens (findings are short, the ceiling is not
    //      what makes them long, it only stops a long list being cut off);
    //   2. if the whole reply won't parse, the complete findings in it are
    //      kept (salvageCheckFindings()), and only a broken or cut-off one is lost;
    //   3. if nothing at all can be read, the check is asked once more before
    //      it fails.
    $data = null;
    $reply = '';
    $stop = '';
    for ($attempt = 1; $attempt <= 2 && $data === null; $attempt++) {
        $reply = callClaude(
            textCheckInstructions((string) $subject['country_prefix'], $countryCode),
            textCheckRequest($subject, $published, $filled, $sources, $sourceTexts),
            TEXT_CHECK_MAX_TOKENS
        );
        $stop = (string) (claudeLastCallInfo()['stop_reason'] ?? '');

        $start = strpos($reply, '{');
        $end = strrpos($reply, '}');
        $parsed = ($start !== false && $end > $start) ? json_decode(substr($reply, $start, $end - $start + 1), true) : null;
        if (is_array($parsed) && isset($parsed['findings']) && is_array($parsed['findings'])) {
            $data = $parsed;
        } elseif ($items = salvageCheckFindings($reply)) {
            $data = ['findings' => $items];
        }
    }
    if ($data === null) {
        throw new RuntimeException('The AI reply was not in the expected format, even after asking again'
            . ($stop !== '' ? " (stop reason: $stop)" : '') . '. It began: "' . aiReplyStart($reply) . '"');
    }

    // Compare without caring about spacing or curly versus straight quotes.
    $flatten = fn(string $s) => trim(preg_replace('/\s+/u', ' ', str_replace(["\u{2019}", "\u{2018}", "\u{201C}", "\u{201D}"], ["'", "'", '"', '"'], $s)));

    $findings = [];
    $dropped = 0;
    foreach ($data['findings'] as $finding) {
        $field = $finding['field'] ?? '';
        $kind = $finding['kind'] ?? '';
        $quote = trim((string) ($finding['quote'] ?? ''));
        $problem = trim((string) ($finding['problem'] ?? ''));
        if (!isset(TEXT_FIELD_LABELS[$field]) || !isset(TEXT_CHECK_KINDS[$kind]) || $quote === '' || $problem === '') {
            $dropped++;
            continue;
        }
        if (strpos($flatten($filled[$field]), $flatten($quote)) === false) {
            // Not really in the text: an AI mistake, not a problem with the page.
            $dropped++;
            continue;
        }
        // A "typed figure" complaint is always a mistake here: the AI reads the text
        // with placeholders already filled in, so it can't tell them from typed
        // figures. Typed figures are checked on the original text by quickTextChecks().
        if (in_array($kind, ['house_style', 'likely_wrong'], true)
            && preg_match('/\b(typed|hard-?coded|should be a placeholder|use \{?(fact|tax_year)|must use \{)/i', $problem)) {
            $dropped++;
            continue;
        }
        $findings[] = ['field' => $field, 'kind' => $kind, 'quote' => $quote, 'problem' => $problem];
    }

    // Sentences that follow a source's wording closely. These are worked out here
    // rather than asked of the AI, which reads for meaning and doesn't notice
    // near-copies. At most 6, the closest first; the quote is an exact part of
    // the field, so it is shown like any other finding.
    $names = array_map(fn($s) => $s['name'], $sources);
    foreach (['explanation', 'intro'] as $field) {
        foreach (array_slice(findCloseCopies($filled[$field], $sourceTexts, $names), 0, 6 - min(6, count(array_filter($findings, fn($f) => $f['kind'] === 'copied_from_source')))) as $copy) {
            $findings[] = [
                'field'   => $field,
                'kind'    => 'copied_from_source',
                'quote'   => quoteWords($copy['quote'], 25),
                'problem' => 'Follows the wording of ' . $copy['source'] . ' closely (' . round($copy['score'] * 100)
                    . '% of the words match, in order). Restate the point in your own words and your own order.',
            ];
        }
    }

    return ['findings' => $findings, 'sources' => array_values($sources), 'dropped' => $dropped];
}

/**
 * The complete findings in a check reply that is not valid JSON as a whole
 * (cut off at the token ceiling, or with a broken finding in the middle).
 * It walks the text after "findings": [ and decodes each top-level {...} on its
 * own, tracking quotes and escapes so braces inside a finding's text don't
 * confuse it. An object that won't decode (the cut-off last one, say) is
 * skipped. Returns the findings that decoded, possibly none.
 */
function salvageCheckFindings(string $reply): array
{
    $key = strpos($reply, '"findings"');
    $open = $key === false ? false : strpos($reply, '[', $key);
    if ($open === false) {
        return [];
    }

    $found = [];
    $depth = 0;
    $inString = false;
    $escaped = false;
    $objectStart = null;
    for ($i = $open + 1, $length = strlen($reply); $i < $length; $i++) {
        $ch = $reply[$i];
        if ($inString) {
            if ($escaped) {
                $escaped = false;
            } elseif ($ch === '\\') {
                $escaped = true;
            } elseif ($ch === '"') {
                $inString = false;
            }
            continue;
        }
        if ($ch === '"') {
            $inString = true;
        } elseif ($ch === '{') {
            if ($depth === 0) {
                $objectStart = $i;
            }
            $depth++;
        } elseif ($ch === '}' && $depth > 0) {
            $depth--;
            if ($depth === 0 && $objectStart !== null) {
                $object = json_decode(substr($reply, $objectStart, $i - $objectStart + 1), true);
                if (is_array($object)) {
                    $found[] = $object;
                }
                $objectStart = null;
            }
        } elseif ($ch === ']' && $depth === 0) {
            break;
        }
    }
    return $found;
}

/**
 * The standing instructions for the AI check: the job, the kinds of
 * finding, the reply format, then the house style and the lessons learned
 * from past edits, so the check judges the text by the same rules the
 * writer was given.
 */
function textCheckInstructions(string $countryPrefix = '', string $countryCode = ''): string
{
    $job = <<<'TXT'
You check the written text of a page on Finance Fact Library, a personal finance website that publishes official figures. You're given the page's four text fields (intro, explanation, meta_title, meta_description) with the figures filled in, the figures shown on the page as cards, and the text of the official source pages those figures come from.

Work through the explanation ONE HEADING AT A TIME. For each section ask:
1. Is the heading true of the text under it, and of the sources? A heading that states or implies something the text then qualifies is wrong.
2. Does the text state anything the sources contradict or don't support, including any count or total (such as "two rates" when a source lists four)?
3. Is any rule or point stated again in another section?
4. Does the section earn its place under the house style?
Then check the intro and meta fields the same way. Finally, compare the whole explanation with the sources for exceptions, conditions and "what does not count" points that the sources state but the text leaves out or gets wrong.

Report only real problems, most serious first, at most 12 in all. Report problems of fact before problems of style. Kinds:
- contradicts_source: a statement the source pages contradict.
- contradicts_figure: a statement that conflicts with the page's figures or their notes.
- likely_wrong: a statement the sources don't cover that you have good reason to think is wrong or out of date. Say what is correct and why. Don't report a statement just because the sources don't mention it. Your own knowledge may be older than the sources and the figures: where they disagree with what you know, they win.
- missing_region: a rule stated as applying across the whole country when part of it (for example Scotland, Wales or Northern Ireland in the UK) has different rules.
- missing_exception: an exception, condition or "what does not count" point that the sources state and the text leaves out or states in a misleading way. Quote the sentence the omission affects.
- heading_mismatch: a heading that is false, or overstated, given the text under it or the sources.
- duplicated: a rule or point already made in another section. Quote the second place it appears.
- unneeded_section: a section the house style says to leave out (a generic closing section, one only saying a rule is the same everywhere, or one that only restates what the figure cards already show), or one that adds nothing.
- house_style: a clear breach of the house style or the lessons that none of the kinds above covers.
- advice: text telling the reader what to do, or recommending products or providers.

Every {fact:...} and {tax_year} placeholder in the text has been replaced by its current value before you read it, so you CANNOT tell a typed figure from a placeholder. Never report that a figure, rate or year is typed, hard-coded or "should be a placeholder", and ignore that rule of the house style: a program checks it on the original text.

Don't report matters of taste: tone, word choice, length or formatting that the house style doesn't mention. Figures you worked out yourself from the given figures are fine if the working is right; report them as contradicts_figure if it's wrong. In the source text, thousands separators have been removed (12570 means 12,570).

For each problem, quote the exact words from the field, copied character for character, at most 25 words. For a heading, quote its words without the leading # characters.

Reply with JSON only, no other text:
{"findings": [{"field": "explanation", "kind": "likely_wrong", "quote": "...", "problem": "..."}]}
or {"findings": []} if there are no problems.

The house style follows, then the lessons learned from past edits. Judge the text against them.

TXT;

    return $job . writingGuidanceText($countryPrefix, $countryCode);
}

/** The request for one page: what it is, today's date, its figures, its text and its sources. */
function textCheckRequest(array $subject, array $facts, array $filled, array $sources, array $sourceTexts): string
{
    $lines = [];
    foreach ($facts as $fact) {
        $context = $fact['context_override'] ?? $fact['context'];
        $line = '- ' . $fact['label'] . ': ' . formatFactValue($fact);
        if (isRegionalFact($fact)) {
            $line .= ' (applies in ' . $fact['jurisdiction_name'] . ')';
        }
        if ($fact['tax_year']) {
            $line .= ', tax year ' . $fact['tax_year'];
        }
        if ($context) {
            $line .= "\n  note: " . $context;
        }
        $lines[] = $line;
    }

    $text = '';
    foreach (TEXT_FIELD_LABELS as $field => $label) {
        $text .= "\n<$field>\n" . ($filled[$field] !== '' ? $filled[$field] : '(empty)') . "\n</$field>\n";
    }

    $sourceBlock = '';
    foreach ($sources as $url => $source) {
        $sourceBlock .= $source['read']
            ? "\n<source name=\"" . $source['name'] . "\" url=\"$url\">\n" . $sourceTexts[$url] . "\n</source>\n"
            : "\n(Source {$source['name']}, $url, could not be read: {$source['error']})\n";
    }

    return "Page: {$subject['name']}\n"
        . "Country: {$subject['country_name']}\n"
        . "Section: {$subject['category_name']} > {$subject['subcategory_name']}\n"
        . 'Today: ' . date('j F Y') . "\n"
        . "\nFigures on the page:\n" . implode("\n", $lines) . "\n"
        . "\nThe page's text:\n" . $text
        . "\nSource pages:\n" . ($sourceBlock !== '' ? $sourceBlock : "(none)\n");
}
