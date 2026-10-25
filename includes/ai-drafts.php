<?php
/**
 * AI first drafts of a subject page's four text fields: intro,
 * explanation, meta title and meta description.
 *
 * Used in two places:
 *   - scripts/draft-pages.php, nightly, for pages with no explanation yet
 *     (most-searched first), so a draft is waiting when you open a page
 *   - the editor's "Draft this page" button, to redraft one page now
 *
 * Drafts are stored in subject_drafts (migration 012) and never touch the
 * live page. The editor shows each draft under its field with a "Use
 * draft" button, and nothing changes until you click it, edit, and click
 * Save page details. They're a starting point, not finished copy.
 *
 * Sources: the AI is given the text of the official pages the figures come
 * from (read fresh, as the AI check reads them) and told to write from them
 * rather than from memory. Before this, the writer saw only the figures and
 * their notes, so it filled the gaps from what it remembered, and some of
 * that was wrong (for example that the type of work doesn't change the
 * minimum wage rate, when apprentices have their own).
 *
 * Automatic rewrite: after drafting, the checks that need no AI (near-copies
 * of the sources, length, typed figures in the meta fields, dating words,
 * advice phrasing, bad placeholders) are run on the draft. If any fail, the
 * writer is sent the draft back with ONLY those problems and asked to fix
 * them, and the rewrite is kept only if it measurably improves the draft
 * (reviseDraft()). One round in the editor, two overnight. It does not judge
 * accuracy: that is still the AI check, which needs the sources and takes
 * minutes.
 *
 * House style and lessons: the AI is sent docs/house-style/core.md, the
 * page's country file (e.g. docs/house-style/uk.md) and the approved lessons
 * learned from past edits (includes/lessons.php) with every request, so
 * drafts follow the same rules as hand-written pages.
 *
 * Figures: the AI is given each figure with its placeholder
 * ({fact:fact_key}) and told to use the placeholder for anything that
 * changes, and never to introduce other figures. Every draft is then
 * checked: numbers typed in it that aren't among the page's figures, and
 * any placeholder problems, are stored with it and shown in the editor.
 *
 * Uses callClaude() and the API key from includes/ai-proposer.php.
 */

require_once __DIR__ . '/ai-proposer.php';
require_once __DIR__ . '/admin-queries.php'; // getTopQueriesForPath() for the page's searches
require_once __DIR__ . '/text-checks.php';   // findUnmatchedNumbers(), shared with the checks on saved text
require_once __DIR__ . '/source-pages.php';  // readSourcePagesForFacts()
require_once __DIR__ . '/lessons.php';       // writingGuidanceText(): house style and lessons

/** The four fields a draft covers, in the order the editor shows them. */
const DRAFT_FIELDS = ['intro', 'explanation', 'meta_title', 'meta_description'];

/** Most characters of each source page, and of all of them together, sent to the writer. */
const DRAFT_SOURCE_CHARACTERS_EACH = 30000;

/**
 * Most tokens the AI may produce for a draft. This ceiling covers EVERYTHING
 * it produces, including any reasoning before it writes (the model reasons by
 * default; callClaude() asks it not to, see claudeThinkingParamAccepted()).
 * A draft that keeps to the length limit (650 words) with its short plan is
 * about 1,500 tokens. The ceiling is set well above that because pages with
 * long sources made the model reason for ~3,500 tokens first: with 4,500 the
 * writing was cut off after ~700 words, and with 3,000 nothing was written at
 * all. If it can't be switched off, 6,000 still leaves room for both.
 */
const DRAFT_MAX_TOKENS = 6000;

/** The length the writer is told to stay within (the house style's own ceiling is 800). */
const DRAFT_MAX_WORDS = 650;

/**
 * Automatic rewrite rounds: one in the editor, where a web request has
 * limited time, and up to two overnight. In the editor the rewrite is
 * skipped if drafting alone took longer than the time budget (seconds).
 */
/**
 * The model for drafting and rewriting: AI_DRAFT_MODEL from
 * config/database.local.php if set, else the site's usual ANTHROPIC_MODEL. A
 * model that reasons by default spends its token ceiling on reasoning before
 * it writes, which cut drafts off or left them empty. Drafting is structured
 * writing from supplied text, so a model that doesn't reason by default is
 * the better fit. The AI check keeps using ANTHROPIC_MODEL.
 */
function draftModel(): ?string
{
    return defined('AI_DRAFT_MODEL') && AI_DRAFT_MODEL !== '' ? AI_DRAFT_MODEL : null;
}

/**
 * Overnight only: if a draft's reply is empty because all the room went on
 * reasoning, ask once more with this larger ceiling. There is no web time
 * limit overnight; the editor doesn't retry.
 */
const DRAFT_RETRY_MAX_TOKENS_NIGHTLY = 20000;

const DRAFT_REVISIONS_EDITOR = 1;
const DRAFT_REVISIONS_NIGHTLY = 2;
const DRAFT_REVISION_TIME_BUDGET_EDITOR = 100;
const DRAFT_REVISION_TIME_BUDGET_NIGHTLY = 900;

/** A rewrite that shortens the explanation below this share of its length is rejected as having thrown content away. */
const DRAFT_REVISION_MIN_LENGTH_SHARE = 0.6;
const DRAFT_SOURCE_CHARACTERS_TOTAL = 120000;

/**
 * Draft all four fields for a subject, then fix what can be fixed
 * automatically.
 *
 * $subject       the subject row from getAdminSubject() (needs the country
 *                code and prefix it adds)
 * $facts         the page's facts, as getAdminFactsForSubject() returns them;
 *                only published ones are used, since only they're public
 * $maxRevisions  how many automatic rewrite rounds to allow (0 for none)
 * $timeBudget    seconds; the rewrite is skipped if drafting took longer
 * $retryTokens   a larger token ceiling to retry with if all the room went on
 *                reasoning (0: don't retry; see DRAFT_RETRY_MAX_TOKENS_NIGHTLY)
 *
 * Returns the four fields (text, or NULL if the AI left one empty) plus
 * 'problems' (what is still wrong, in plain sentences) and 'rewrite_note'
 * (what the automatic rewrite did, or '').
 */
function draftSubjectPage(
    PDO $pdo,
    array $subject,
    array $facts,
    int $maxRevisions = DRAFT_REVISIONS_EDITOR,
    int $timeBudget = DRAFT_REVISION_TIME_BUDGET_EDITOR,
    int $retryTokens = 0
): array {
    $started = microtime(true);

    $published = array_values(array_filter($facts, fn($f) => $f['status'] === 'published'));
    if (!$published) {
        throw new RuntimeException('This page has no published figures yet, so there is nothing to write from. Add facts first.');
    }

    // The official pages the figures come from, so the draft is written from
    // them. A page that can't be read is reported to the AI, not fatal.
    $read = readSourcePagesForFacts($published, DRAFT_SOURCE_CHARACTERS_EACH);

    $reply = callClaude(
        pageDraftInstructions((string) $subject['country_prefix'], (string) $subject['country_code']),
        pageDraftRequest($subject, $published, $read['sources'], $read['texts']),
        DRAFT_MAX_TOKENS,
        null,
        draftModel(),
        true,
        $retryTokens
    );
    // How the reply ended, so a reply cut off at the limit is told apart from
    // one that was merely missing its last tag.
    $firstInfo = claudeLastCallInfo();
    $draft = parsePageDraft($reply, $firstInfo);
    $truncated = !empty($draft['truncated']);
    $dropped = ['words' => (int) ($draft['dropped_words'] ?? 0), 'start' => (string) ($draft['dropped_start'] ?? '')];
    unset($draft['truncated'], $draft['dropped_words'], $draft['dropped_start']);

    $checks = draftChecks($pdo, $draft, $published, $read, $subject);
    $firstWords = $checks['words'];

    // --- Automatic rewrite of what the checks found. ---
    $rewriteNotes = [];
    $rewriteAccepted = false;
    $rewriteTokens = [];
    if ($maxRevisions > 0 && draftFixList($checks) !== []) {
        if (microtime(true) - $started > $timeBudget) {
            $rewriteNotes[] = 'Not rewritten automatically: drafting took too long to leave time for it. Click Redraft this page to try again.';
        } else {
            // A cut-off draft is still a usable draft (it stops at its last complete
            // paragraph), so it is rewritten too: the rewrite fixes its near-copies
            // and brings it within the length limit.
            $result = reviseDraft($pdo, $subject, $published, $read, $draft, $checks, $maxRevisions, $truncated, $retryTokens);
            [$draft, $checks, $rewriteNotes] = [$result['draft'], $result['checks'], $result['notes']];
            $rewriteAccepted = $result['accepted'];
            $rewriteTokens = $result['tokens'];
        }
    }

    // What the draft cost, in tokens, and what the reply held. "thinking" in the
    // blocks means the model reasoned before writing, which uses the same room as
    // the writing (see claudeThinkingParamAccepted()). Shown small, under the note.
    $details = 'First draft: ' . number_format((int) ($firstInfo['output_tokens'] ?? 0)) . ' tokens written, '
        . $firstWords . ' words kept' . ($truncated ? ' (cut off)' : '')
        . ', reply held ' . (($firstInfo['blocks'] ?? '') !== '' ? $firstInfo['blocks'] : 'unknown')
        . ', model ' . (($firstInfo['model'] ?? '') !== '' ? $firstInfo['model'] : 'unknown')
        . ', no-reasoning setting ' . (($firstInfo['thinking_switch'] ?? '') !== '' ? $firstInfo['thinking_switch'] : 'unknown') . '.';
    foreach ($rewriteTokens as $i => $tokens) {
        $details .= ' Rewrite ' . ($i + 1) . ': ' . number_format($tokens) . ' tokens written.';
    }

    // A successful rewrite replaces the cut-off text, so the red box doesn't
    // describe it as if it were still there (the note says what happened).
    $stillCutOff = $truncated && !$rewriteAccepted;
    if ($truncated && $rewriteAccepted) {
        array_unshift($rewriteNotes, 'The first draft ran past the length limit and was cut off.');
    }

    return $draft + [
        'problems'     => draftProblemLines($checks, $stillCutOff, $dropped),
        // The editor shows what follows " | Details: " in small type.
        'rewrite_note' => implode(' ', $rewriteNotes) . ' | Details: ' . $details,
    ];
}

// ------------------------------------------------------------
// Checks that need no AI, on a draft
// ------------------------------------------------------------

/**
 * Run the checks on a draft. Returns:
 *   words               words in the explanation
 *   copies              sentences that follow a source closely (text-checks.php)
 *   unmatched           typed numbers that aren't among the page's figures or labels
 *   placeholder_errors  placeholders that wouldn't be accepted on save
 *   placeholder_warnings  placeholders for a figure not shown on this page
 *   notes               house-style notes that can be fixed by rewriting: typed
 *                       figures or years in the meta fields, dating words, advice
 *                       phrasing, a generic closing section (not the number or
 *                       length notes, which are handled separately)
 */
function draftChecks(PDO $pdo, array $draft, array $published, array $read, array $subject): array
{
    $names = array_map(fn($s) => $s['name'], $read['sources']);
    $copies = array_merge(
        findCloseCopies((string) $draft['explanation'], $read['texts'], $names),
        findCloseCopies((string) $draft['intro'], $read['texts'], $names)
    );

    $placeholders = checkPlaceholders($pdo, [
        'Intro'            => $draft['intro'],
        'Explanation'      => $draft['explanation'],
        'Meta title'       => $draft['meta_title'],
        'Meta description' => $draft['meta_description'],
    ], array_column($published, 'fact_key'));

    $notes = array_values(array_filter(
        quickTextChecks([
            'intro'            => $draft['intro'],
            'explanation'      => $draft['explanation'],
            'meta_title'       => $draft['meta_title'],
            'meta_description' => $draft['meta_description'],
        ], $published),
        fn($note) => !preg_match("/isn't one of|aren't among|over the house style's limit/", $note)
    ));

    // A note about a word ("today" dates the text) doesn't say where the word is,
    // and the automatic rewrite twice failed to find a single "today" in 470 words.
    // So say which sentence it is in, both here and in what the writer is sent.
    $fieldText = [
        'Intro'            => (string) $draft['intro'],
        'Explanation'      => (string) $draft['explanation'],
        'Meta title'       => (string) $draft['meta_title'],
        'Meta description' => (string) $draft['meta_description'],
    ];
    $notes = array_map(fn($note) => noteWithSentence($note, $fieldText), $notes);

    // The meta description is counted as search results show it, with the figures
    // filled in. The writer can't count characters: one draft came back at 158 and
    // the rewrite of it at 129, against a house style of 140 to 155.
    $meta = trim((string) $draft['meta_description']);
    if ($meta !== '' && function_exists('fillPlaceholders')) {
        try {
            $metaFilled = trim((string) fillPlaceholders($meta, (string) $subject['country_code'], $published));
        } catch (Throwable $e) {
            $metaFilled = '';
        }
        $length = (int) preg_match_all('/./us', $metaFilled); // characters, without needing mbstring
        if ($metaFilled !== '' && ($length < META_DESCRIPTION_MIN || $length > META_DESCRIPTION_MAX)) {
            $notes[] = "Meta description: $length characters with the figures filled in; the house style asks for "
                . META_DESCRIPTION_MIN . ' to ' . META_DESCRIPTION_MAX . '. '
                . ($length < META_DESCRIPTION_MIN
                    ? 'Add at least ' . (META_DESCRIPTION_MIN - $length) . ' characters'
                    : 'Cut at least ' . ($length - META_DESCRIPTION_MAX) . ' characters')
                . ', aiming for about ' . intdiv(META_DESCRIPTION_MIN + META_DESCRIPTION_MAX, 2) . '. It reads: "' . $metaFilled . '"';
        }
    }

    return [
        'words'                => preg_match_all('/\S+/u', (string) $draft['explanation']),
        'copies'               => $copies,
        'unmatched'            => findUnmatchedNumbers(implode("\n", array_filter($draft)), $published),
        'placeholder_errors'   => $placeholders['errors'],
        'placeholder_warnings' => $placeholders['warnings'],
        'notes'                => $notes,
    ];
}

/**
 * A house-style note about particular words ('Explanation: "today" dates the
 * text.' or '... "you should" reads as advice.'), with the sentence each word
 * is in added, so it can be found and fixed. Other notes come back unchanged.
 *
 * $fieldText  the field's label (as in the note) => its text
 */
function noteWithSentence(string $note, array $fieldText): string
{
    if (!preg_match('/^(Intro|Explanation|Meta title|Meta description): "(.+?)" (?:dates the text|reads as advice)/', $note, $m)
        || !isset($fieldText[$m[1]])) {
        return $note;
    }

    $sentences = [];
    foreach (explode('", "', $m[2]) as $word) {
        foreach (preg_split('/(?<=[.!?])\s+|\n+/u', $fieldText[$m[1]], -1, PREG_SPLIT_NO_EMPTY) as $sentence) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/i', $sentence)) {
                $sentences[trim($sentence)] = true;
            }
        }
    }
    if (!$sentences) {
        return $note;
    }
    return $note . ' It is in: "' . quoteWords(implode('" and "', array_slice(array_keys($sentences), 0, 2)), 60) . '"';
}

/** How many problems the automatic rewrite can be asked to fix. The rewrite must lower this to be kept. */
function draftScore(array $checks): int
{
    return count($checks['copies'])
        + ($checks['words'] > EXPLANATION_MAX_WORDS ? 1 : 0)
        + count($checks['placeholder_errors'])
        + count($checks['notes']);
}

/**
 * The problems to send back to the writer, one instruction each. The near-
 * copies come with the source sentence they follow, so the writer can avoid
 * it. At most 12 near-copies, the closest first.
 */
function draftFixList(array $checks): array
{
    $fixes = [];

    foreach (array_slice($checks['copies'], 0, 12) as $copy) {
        $fixes[] = 'This sentence follows ' . $copy['source'] . ' too closely (' . round($copy['score'] * 100)
            . '% of the words match, in order). Restate the same point in your own words AND a different sentence '
            . 'structure, keeping every fact, condition and placeholder. Your sentence: "' . quoteWords($copy['quote'], 40)
            . '" The source sentence it follows, to avoid: "' . quoteWords((string) $copy['matched'], 40) . '"';
    }
    if ($checks['words'] > EXPLANATION_MAX_WORDS) {
        $fixes[] = 'The explanation is ' . $checks['words'] . ' words; the limit is ' . DRAFT_MAX_WORDS
            . '. Cut it to ' . DRAFT_MAX_WORDS . ' words or fewer by leaving out the least important points, not by shortening '
            . 'every sentence. Keep the exceptions and conditions readers most often get wrong.';
    }
    foreach ($checks['placeholder_errors'] as $error) {
        $fixes[] = $error;
    }
    foreach ($checks['notes'] as $note) {
        $fixes[] = $note;
    }
    return $fixes;
}

/**
 * The problems still left in a draft, as the plain sentences the editor
 * shows in its red box. $truncated means the explanation was cut at the
 * token limit, which means the writer ignored the length limit completely.
 */
function draftProblemLines(array $checks, bool $truncated, array $dropped = []): array
{
    $problems = [];

    if ($truncated) {
        $problems[] = 'The draft ran past the length limit and was cut off, so the explanation stops at its last complete paragraph '
            . "({$checks['words']} words kept). Choose the few points readers most often get wrong and leave the rest to the cards."
            . (($dropped['words'] ?? 0) > 0
                ? " The writer then went on for about {$dropped['words']} more words without a paragraph break, beginning: \"{$dropped['start']}...\""
                : '');
    } elseif ($checks['words'] > EXPLANATION_MAX_WORDS) {
        $problems[] = "The explanation is {$checks['words']} words, over the house style's limit of " . EXPLANATION_MAX_WORDS
            . '. Cut it to the points readers most often get wrong.';
    }

    $copies = $checks['copies'];
    if ($copies) {
        $problems[] = count($copies) . ' sentence' . (count($copies) === 1 ? ' follows' : 's follow') . ' a source\'s wording closely, for example "'
            . quoteWords($copies[0]['quote'], 14) . '..." (' . round($copies[0]['score'] * 100) . '% of the words match '
            . $copies[0]['source'] . '). Rewrite them in your own words; the AI check lists each one.';
    }

    if ($checks['unmatched']) {
        $problems[] = 'Check these numbers, which aren\'t among the page\'s figures or their labels: ' . implode(', ', $checks['unmatched']) . '.';
    }

    return array_merge($problems, $checks['placeholder_errors'], $checks['placeholder_warnings'], $checks['notes']);
}

// ------------------------------------------------------------
// The automatic rewrite
// ------------------------------------------------------------

/**
 * Send the draft back to the writer with the problems the checks found, and
 * keep the rewrite only if it is better. Up to $maxRounds rounds; stops as
 * soon as nothing is left to fix or a round doesn't help.
 *
 * A rewrite is rejected (and the earlier text kept) if the AI call fails, if
 * the reply was cut off, if it doesn't lower draftScore(), or if it shortens
 * the explanation below DRAFT_REVISION_MIN_LENGTH_SHARE of what it was, since
 * that means it fixed the problems by throwing content away. Fields the
 * rewrite leaves out keep their earlier text.
 *
 * Returns ['draft', 'checks', 'notes' (what happened, in sentences),
 * 'accepted' (whether any round's rewrite was kept), 'tokens' (tokens each
 * round's reply used)].
 */
function reviseDraft(PDO $pdo, array $subject, array $published, array $read, array $draft, array $checks, int $maxRounds, bool $cutOff = false, int $retryTokens = 0): array
{
    $notes = [];
    $tokens = [];
    $accepted = false;

    for ($round = 1; $round <= $maxRounds; $round++) {
        $fixes = draftFixList($checks);
        if (!$fixes) {
            break;
        }
        $before = draftScore($checks);

        try {
            $reply = callClaude(
                pageRevisionInstructions((string) $subject['country_prefix'], (string) $subject['country_code']),
                pageRevisionRequest($subject, $published, $draft, $fixes, $cutOff && $round === 1),
                DRAFT_MAX_TOKENS,
                null,
                draftModel(),
                true,
                $retryTokens
            );
            $revised = parsePageDraft($reply, claudeLastCallInfo());
            $tokens[] = (int) (claudeLastCallInfo()['output_tokens'] ?? 0);
        } catch (Throwable $e) {
            $notes[] = 'The automatic rewrite failed (' . $e->getMessage() . '), so the draft is as first written.';
            break;
        }

        if (!empty($revised['truncated'])) {
            $notes[] = 'The automatic rewrite ran past the length limit, so the earlier text was kept.';
            break;
        }
        unset($revised['truncated']);

        // A field the rewrite left out keeps its earlier text.
        foreach (DRAFT_FIELDS as $field) {
            if ($revised[$field] === null) {
                $revised[$field] = $draft[$field];
            }
        }

        $revisedChecks = draftChecks($pdo, $revised, $published, $read, $subject);
        $after = draftScore($revisedChecks);
        $wordsBefore = $checks['words'];

        if ($wordsBefore <= EXPLANATION_MAX_WORDS && $revisedChecks['words'] < $wordsBefore * DRAFT_REVISION_MIN_LENGTH_SHARE) {
            $notes[] = "Rewrite round $round fixed the problems by cutting the explanation from $wordsBefore to "
                . "{$revisedChecks['words']} words, so the earlier text was kept.";
            break;
        }
        if ($after >= $before) {
            $notes[] = "Rewrite round $round did not improve it ($before problems before, $after after), so the earlier text was kept.";
            break;
        }

        $draft = $revised;
        $checks = $revisedChecks;
        $accepted = true;
        $notes[] = "Rewrite round $round: $before problems found, $after left.";
    }

    return ['draft' => $draft, 'checks' => $checks, 'notes' => $notes, 'accepted' => $accepted, 'tokens' => $tokens];
}

/** The instructions for the rewrite: fix only what's listed, then the house style and the lessons. */
function pageRevisionInstructions(string $countryPrefix, string $countryCode): string
{
    $job = <<<'TXT'
You revise a first draft of the four text fields of a page on Finance Fact Library, a personal finance reference site: intro, meta_title, meta_description and explanation.

Fix ONLY the problems listed in the request. Leave everything else exactly as it is. Do not rewrite sentences that are not listed, do not change headings or the order of sections unless a fix needs it, and do not add new content, figures, claims or links.

- For a sentence that follows a source too closely: restate the same point in your own words AND a different sentence structure and order. Swapping a few words is not enough: a tool compares the result with the source again. Keep every fact, condition, exception and placeholder in the sentence. The source sentence is shown so you can avoid its wording.
- For length: cut the least important points. Keep the exceptions and conditions readers most often get wrong.
- For a typed figure or year in a meta field: use the placeholder named in the problem.
- Never state a count, an age, a date or a rule that is not already in the draft.

Reply with the four fields between tags, in this order, and nothing else:
<intro>...</intro>
<meta_title>...</meta_title>
<meta_description>...</meta_description>
<explanation>
...
</explanation>
The explanation keeps the editor's format from the house style, with real line breaks.

The house style follows, then the lessons learned from past edits. The rewrite must follow them too.

TXT;

    return $job . writingGuidanceText($countryPrefix, $countryCode);
}

/** The request for a rewrite: the draft, the placeholders it may use, and the numbered problems. */
function pageRevisionRequest(array $subject, array $facts, array $draft, array $fixes, bool $cutOff = false): string
{
    $placeholders = ['- {tax_year}: the tax year of the figures'];
    foreach ($facts as $fact) {
        $placeholders[] = '- {fact:' . $fact['fact_key'] . '}: ' . $fact['label'] . ' (' . formatFactValue($fact) . ')';
    }

    $numbered = [];
    foreach ($fixes as $i => $fix) {
        $numbered[] = ($i + 1) . '. ' . $fix;
    }

    return "Page: {$subject['name']}\nCountry: {$subject['country_name']}\n\n"
        . "The draft:\n"
        . '<intro>' . (string) $draft['intro'] . "</intro>\n"
        . '<meta_title>' . (string) $draft['meta_title'] . "</meta_title>\n"
        . '<meta_description>' . (string) $draft['meta_description'] . "</meta_description>\n"
        . "<explanation>\n" . (string) $draft['explanation'] . "\n</explanation>\n\n"
        . "Placeholders you may use:\n" . implode("\n", $placeholders) . "\n\n"
        . ($cutOff ? "Note: the draft was cut off at the length limit, so its last section may be missing. Do not try to complete it or add a section.\n\n" : '')
        . "Problems to fix (and nothing else):\n" . implode("\n", $numbered) . "\n";
}

/** The standing instructions: the job and the reply format, then the house style and the lessons. */
function pageDraftInstructions(string $countryPrefix, string $countryCode): string
{
    $job = <<<'TXT'
You write first drafts of the four text fields of a page on Finance Fact Library, a website that publishes official personal finance figures, each sourced from the government and similar official bodies. Each page shows its figures as cards; the explanation appears below them.

Draft all four fields: intro, meta_title, meta_description and explanation. Follow the house style below exactly, in particular:
- LENGTH LIMIT: the explanation must be 300 to 650 words, and never more than 650, however much the sources cover and however many figures the page has. The figure cards show the figures, so do not walk through them. Choose the few points readers most often get wrong (exceptions, conditions, what does not count) and stop. A long, thorough explanation is a failure here, not a success.
- Each figure you are given comes with its placeholder, such as {fact:personal_allowance}. Write the placeholder, not the figure, for anything that changes. Only type a figure if the house style allows it (fixed until a known date, which you must then give).
- Never introduce any amount, rate, threshold or date you weren't given, and never calculate new figures, except a rate worked out directly from fixed figures, with the working shown.
- A page whose figures are checked every 42 days or less is a rate page: don't state its rates at all.
- If you're given the searches that showed this page on Google, use the words people actually search for in the meta title and description.
- Count characters with placeholders filled in (you are told what each shows) when keeping to the house style's lengths.

You are also given the text of the official source pages the figures come from. Write from them, not from memory:
- Everything you state must come from the sources, not from memory: take rules, conditions, exceptions and examples from them. Where a source and what you remember differ, the source wins. The sources hold far more than fits, so you must CHOOSE: leave out whatever the figure cards or the sources already make clear.
- Of what you choose, the exceptions, special cases and "what does not count" points come first. They are what readers most often get wrong.
- Use the sources' own worked examples, with placeholders for any figures.
- Never state a rule, count, age or date the sources don't support. If the sources don't say, leave it out.
- Write in your own words and your own order. Never reuse a sentence from a source, even with a few words changed: restate the point. Only fixed names, terms and figures may match. A tool compares every sentence with the sources and reports close copies.
- If a source could not be read, say nothing that depends on it.
- In the source text, thousands separators have been removed (12570 means 12,570).
- Before you reply, check that each heading is true of the text under it, that no rule is stated twice, and that every section earns its place.

Reply with a plan and then the four fields between tags, in this order, and nothing else:
<plan>
One line per section of the explanation, at most five lines, as "Heading: the one point it makes". Pick the points readers most often get wrong. Whatever you leave out is covered by the figure cards and the source pages.
</plan>
<intro>...</intro>
<meta_title>...</meta_title>
<meta_description>...</meta_description>
<explanation>
...
</explanation>
Write exactly the sections in your plan, each in one to three short paragraphs of at most 130 words in all. No tables, no numbered walkthroughs, and no more than one bullet list of at most four items. The explanation uses the editor's format from the house style, written exactly as it should appear, with real line breaks.

The house style follows, then the lessons learned from past edits.

TXT;

    return $job . writingGuidanceText($countryPrefix, $countryCode);
}

/**
 * The request for one subject: what the page is, its current text, its
 * searches and its figures, then the source pages to write from.
 *
 * $sources / $sourceTexts come from readSourcePagesForFacts(). The pages
 * are cut to fit DRAFT_SOURCE_CHARACTERS_TOTAL; a page left out for length
 * or that couldn't be read is named, so the AI says nothing that depends on it.
 */
function pageDraftRequest(array $subject, array $facts, array $sources = [], array $sourceTexts = []): string
{
    $lines = [];
    foreach ($facts as $fact) {
        // The context readers see on this page (an override, if the page has one).
        $context = $fact['context_override'] ?? $fact['context'];
        $line = '- ' . $fact['label'] . ': ' . formatFactValue($fact)
            . "\n  placeholder: {fact:" . $fact['fact_key'] . '}';
        if (isRegionalFact($fact)) {
            $line .= "\n  applies in: " . $fact['jurisdiction_name'];
        }
        // How often it's checked: 42 days or less makes this a rate page.
        $line .= "\n  checked every: " . (int) $fact['review_frequency_days'] . ' days';
        if ($context) {
            $line .= "\n  note: " . $context;
        }
        if ($fact['tax_year']) {
            $line .= "\n  tax year: " . $fact['tax_year'];
        }
        if ($fact['source_name']) {
            $line .= "\n  source: " . $fact['source_name'];
        }
        $lines[] = $line;
    }

    // What people searched for when Google showed this page (Search
    // Console, last 28 days). Empty for pages Google hasn't shown yet.
    $searches = [];
    foreach (getTopQueriesForPath(subjectUrlById((int) $subject['id']), 10) as $query) {
        $searches[] = '- ' . $query['query'] . ' (' . (int) $query['impressions'] . ' impressions)';
    }

    $taxYear = pageTaxYear((string) $subject['country_code'], $facts);

    // The source pages, within the total allowance.
    $sourceBlock = requestSourceBlock($sources, $sourceTexts);

    return "Page: {$subject['name']}\n"
        . "Country: {$subject['country_name']}\n"
        . "Section: {$subject['category_name']} > {$subject['subcategory_name']}\n"
        . "Tax year of the figures: $taxYear (placeholder: {tax_year})\n"
        . ($subject['intro'] ? "Current intro (improve on it or keep its substance): {$subject['intro']}\n" : '')
        . ($searches ? "\nSearches that showed this page on Google:\n" . implode("\n", $searches) . "\n" : '')
        . "\nFigures on the page:\n" . implode("\n", $lines) . "\n"
        . "\nSource pages (the official pages the figures come from; write from these):\n"
        . ($sourceBlock !== '' ? $sourceBlock : "(none: the figures cite no readable source page)\n")
        . "\nReminder: the explanation must be " . DRAFT_MAX_WORDS . " words or fewer. Choose the points readers most often get wrong; leave the rest to the figure cards.\n";
}

/**
 * The source pages as the AI is shown them: each between <source> tags, within
 * the total allowance (DRAFT_SOURCE_CHARACTERS_TOTAL), with a note for any page
 * that could not be read or was left out for length. Used by the draft request
 * and by the polish rewrite (ai-polish.php), so both see the same pages.
 *
 * $sources      url => ['name', 'read' => bool, 'error'], from readSourcePagesForFacts()
 * $sourceTexts  url => the page's text
 */
function requestSourceBlock(array $sources, array $sourceTexts): string
{
    $sourceBlock = '';
    $used = 0;
    foreach ($sources as $url => $source) {
        if (!$source['read']) {
            $sourceBlock .= "\n(Source {$source['name']}, $url, could not be read: {$source['error']})\n";
            continue;
        }
        $text = $sourceTexts[$url];
        $room = DRAFT_SOURCE_CHARACTERS_TOTAL - $used;
        if ($room < 2000) {
            $sourceBlock .= "\n(Source {$source['name']}, $url, left out for length.)\n";
            continue;
        }
        if (strlen($text) > $room) {
            $text = preg_match('/^.{0,' . $room . '}/su', $text, $m) ? $m[0] : substr($text, 0, $room);
        }
        $used += strlen($text);
        $sourceBlock .= "\n<source name=\"{$source['name']}\" url=\"$url\">\n$text\n</source>\n";
    }
    return $sourceBlock;
}

/**
 * Turn the AI's reply into the four fields, tidied the same way the
 * editor tidies what you type. Throws if the reply has no explanation.
 *
 * The reply has each field between tags (<intro>...</intro> and so on).
 * This replaced JSON, which broke on long explanations: one unescaped
 * line break or quote mark in the explanation made the whole reply
 * unreadable (the first live run failed on the minimum wage page). Text
 * outside the tags is ignored.
 */
function parsePageDraft(string $reply, array $info = []): array
{
    $data = [];
    $truncated = false;
    $droppedWords = 0;
    $droppedStart = '';
    foreach (DRAFT_FIELDS as $field) {
        if (preg_match('#<' . $field . '>(.*?)</' . $field . '>#s', $reply, $m)) {
            $data[$field] = $m[1];
        }
    }

    if (!isset($data['explanation'])) {
        $stop = (string) ($info['stop_reason'] ?? '');
        $opened = strpos($reply, '<explanation>');

        if ($opened !== false && $stop === 'end_turn') {
            // The AI finished normally but left off the closing tag. The
            // explanation is the last field, so everything after the opening
            // tag is the explanation, and is complete.
            $data['explanation'] = substr($reply, $opened + strlen('<explanation>'));
        } elseif ($opened !== false && $stop === 'max_tokens') {
            // Cut off at the token limit, which means the AI ignored the length
            // limit. Rather than fail, keep what was written up to the last
            // complete paragraph (dropping a heading left with nothing under
            // it) and report it, so there is a draft to work from.
            $partial = substr($reply, $opened + strlen('<explanation>'));
            $break = strrpos($partial, "\n\n");
            $dropped = $break !== false ? substr($partial, $break) : '';
            $partial = $break !== false ? substr($partial, 0, $break) : $partial;
            $data['explanation'] = preg_replace('/\n+#{2,3} [^\n]*\s*$/', '', $partial);
            $truncated = true;
            // What was dropped, so the editor can say what the writer was doing
            // after its last blank line (a long list, say).
            $droppedWords = preg_match_all('/\S+/u', $dropped);
            $droppedStart = preg_match('/^.{0,220}/su', trim(preg_replace('/\s+/u', ' ', $dropped)), $m) ? $m[0] : '';
        } else {
            // Cut off at the token limit, or not in the format asked for.
            $tokens = isset($info['output_tokens']) ? ' It wrote ' . number_format((int) $info['output_tokens']) . ' tokens' : '';
            throw new RuntimeException(($opened !== false
                ? 'The AI reply was cut off before the end of the explanation.'
                    . ($stop === 'max_tokens' ? $tokens . ', the most it was allowed.' : ($stop !== '' ? " (stop reason: $stop)." : ''))
                : 'The AI reply was not in the expected format.' . ($stop !== '' ? " (stop reason: $stop)." : ''))
                . ' Try again. It began: "' . aiReplyStart($reply) . '"');
        }
    }

    $draft = [];
    foreach (DRAFT_FIELDS as $field) {
        $value = $data[$field] ?? null;
        if ($field === 'explanation') {
            // Normalise line endings and stray blank lines; keep within the editor's limit.
            $value = trim(preg_replace("/\n{3,}/", "\n\n", str_replace(["\r\n", "\r"], "\n", (string) $value)));
            $value = $value === '' ? null : $value;
            if ($value !== null && textLength($value) > 20000) {
                $value = substr($value, 0, 20000);
            }
        } else {
            // Intro and meta fields are one line each; the meta fields also
            // have to fit their 255-character columns.
            $value = cleanEditorText($value, true);
            if ($value !== null && $field !== 'intro' && textLength($value) > 255) {
                $value = preg_match('/^.{0,255}/su', $value, $m) ? $m[0] : substr($value, 0, 255);
            }
        }
        $draft[$field] = $value;
    }

    if ($draft['explanation'] === null) {
        throw new RuntimeException('The AI returned an empty explanation. Try again.');
    }
    // 'truncated' says the explanation was cut at the token limit (see above),
    // and what was dropped.
    return $draft + [
        'truncated'       => $truncated,
        'dropped_words'   => $droppedWords ?? 0,
        'dropped_start'   => $droppedStart ?? '',
    ];
}
