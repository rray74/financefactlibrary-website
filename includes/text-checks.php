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

/** A figure checked this often or more is a rate (house style, section 2). */
const RATE_REVIEW_DAYS = 42;

/** Most characters of each source page sent to the AI check. */
const TEXT_CHECK_SOURCE_CHARACTERS = 50000;

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
    $labelNumbers = [];
    $rateKeys = [];
    foreach ($published as $fact) {
        foreach (factNumberForms($fact) as $form) {
            $factForNumber[$form] ??= $fact;
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
                $notes[] = "$label: $written is typed. Use {fact:{$fact['fact_key']}} so it stays current.";
            } elseif (!$isMeta && isset($rateKeys[$fact['fact_key']])) {
                $notes[] = "$label: $written is typed, and it's a rate ({$fact['label']}). "
                    . "The house style is not to state rates in the text.";
            }
        }
        if ($unmatched) {
            $notes[] = "$label: " . implode(', ', $unmatched) . ' '
                . (count($unmatched) === 1 ? "isn't one of" : "aren't among") . ' the page\'s figures or their labels. '
                . 'Fine if fixed or worked out from fixed figures; otherwise check '
                . (count($unmatched) === 1 ? 'it.' : 'them.');
        }

        // --- Rates shown through a placeholder, in the intro or explanation. ---
        if (!$isMeta && preg_match_all('/\{fact:([a-z0-9_]+)\}/', $text, $m)) {
            foreach (array_unique($m[1]) as $key) {
                if (isset($rateKeys[$key])) {
                    $notes[] = "$label: {fact:$key} shows a rate ({$rateKeys[$key]['label']}). "
                        . 'The house style is not to state rates in the text, even as a placeholder.';
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
        if (preg_match_all('/\b(currently|this year|at the moment|recently|latest)\b/i', $text, $m)) {
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
    'advice'             => 'Reads as advice',
];

/**
 * The kinds that are about how the page is written rather than whether it
 * is right. The editor shows them all, but only the others count towards
 * the "AI check found problems" review flag (includes/review-flags.php):
 * a style finding you've decided to accept would otherwise flag the page
 * for good, since an AI finding can't be dismissed.
 */
const TEXT_CHECK_STYLE_KINDS = ['duplicated', 'unneeded_section', 'house_style', 'advice'];

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

    $reply = callClaude(
        textCheckInstructions((string) $subject['country_prefix'], $countryCode),
        textCheckRequest($subject, $published, $filled, $sources, $sourceTexts),
        4000
    );

    // --- Read the reply, keeping only findings that quote the text exactly. ---
    // The JSON is taken from the first { to the last }, so a sentence or a
    // ```json fence around it doesn't matter. (Findings are short, so JSON
    // is safe here, unlike the long explanations in AI drafts.)
    $start = strpos($reply, '{');
    $end = strrpos($reply, '}');
    $data = ($start !== false && $end > $start) ? json_decode(substr($reply, $start, $end - $start + 1), true) : null;
    if (!is_array($data) || !isset($data['findings']) || !is_array($data['findings'])) {
        throw new RuntimeException('The AI reply was not in the expected format. Try again. It began: "'
            . aiReplyStart($reply) . '"');
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
        $findings[] = ['field' => $field, 'kind' => $kind, 'quote' => $quote, 'problem' => $problem];
    }

    return ['findings' => $findings, 'sources' => array_values($sources), 'dropped' => $dropped];
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
- unneeded_section: a section the house style says to leave out (a generic closing section, or one only saying a rule is the same everywhere), or one that adds nothing.
- house_style: a clear breach of the house style or the lessons that none of the kinds above covers.
- advice: text telling the reader what to do, or recommending products or providers.

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
