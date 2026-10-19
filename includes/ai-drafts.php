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
 * House style: the AI is sent docs/house-style/core.md and the page's
 * country file (e.g. docs/house-style/uk.md) with every request, so drafts
 * follow the same rules as hand-written pages. Edit those files to change
 * how drafts are written.
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

/** The four fields a draft covers, in the order the editor shows them. */
const DRAFT_FIELDS = ['intro', 'explanation', 'meta_title', 'meta_description'];

/**
 * Draft all four fields for a subject.
 *
 * $subject  the subject row from getAdminSubject() (needs the country
 *           code and prefix it adds)
 * $facts    the page's facts, as getAdminFactsForSubject() returns them;
 *           only published ones are used, since only they're public
 *
 * Returns the four fields (text, or NULL if the AI left one empty) plus
 * 'problems': a list of things to check, in plain sentences.
 */
function draftSubjectPage(PDO $pdo, array $subject, array $facts): array
{
    $published = array_values(array_filter($facts, fn($f) => $f['status'] === 'published'));
    if (!$published) {
        throw new RuntimeException('This page has no published figures yet, so there is nothing to write from. Add facts first.');
    }

    $reply = callClaude(
        pageDraftInstructions((string) $subject['country_prefix']),
        pageDraftRequest($subject, $published),
        5000
    );
    $draft = parsePageDraft($reply);

    // --- Checks, stored with the draft and shown in the editor. ---
    $problems = [];

    // Numbers typed in any field that aren't among the page's figures.
    $unmatched = findUnmatchedNumbers(implode("\n", array_filter($draft)), $published);
    if ($unmatched) {
        $problems[] = 'Check these numbers, which aren\'t among the page\'s figures: ' . implode(', ', $unmatched) . '.';
    }

    // Placeholders: invalid ones would be refused on save anyway, but
    // it's better to see them now.
    $check = checkPlaceholders($pdo, [
        'Intro'            => $draft['intro'],
        'Explanation'      => $draft['explanation'],
        'Meta title'       => $draft['meta_title'],
        'Meta description' => $draft['meta_description'],
    ], array_column($published, 'fact_key'));
    $problems = array_merge($problems, $check['errors'], $check['warnings']);

    return $draft + ['problems' => $problems];
}

/**
 * The house style for a country: docs/house-style/core.md followed by the
 * country's own file (uk.md for /uk/ pages). The country file is optional,
 * so a new country works with the core rules until its file is written.
 */
function houseStyleText(string $countryPrefix): string
{
    $folder = __DIR__ . '/../docs/house-style/';
    if (!is_readable($folder . 'core.md')) {
        throw new RuntimeException('docs/house-style/core.md is missing, so there is no house style to draft from.');
    }

    $text = file_get_contents($folder . 'core.md');
    $countryFile = $folder . preg_replace('/[^a-z]/', '', strtolower($countryPrefix)) . '.md';
    if (is_readable($countryFile)) {
        $text .= "\n\n" . file_get_contents($countryFile);
    }
    return $text;
}

/** The standing instructions: the job and the reply format, then the house style. */
function pageDraftInstructions(string $countryPrefix): string
{
    $job = <<<'TXT'
You write first drafts of the four text fields of a page on Finance Fact Library, a website that publishes official personal finance figures, each sourced from the government and similar official bodies. Each page shows its figures as cards; the explanation appears below them.

Draft all four fields: intro, meta_title, meta_description and explanation. Follow the house style below exactly, in particular:
- Each figure you are given comes with its placeholder, such as {fact:personal_allowance}. Write the placeholder, not the figure, for anything that changes. Only type a figure if the house style allows it (fixed until a known date, which you must then give).
- Never introduce any amount, rate, threshold or date you weren't given, and never calculate new figures, except a rate worked out directly from fixed figures, with the working shown.
- A page whose figures are checked every 42 days or less is a rate page: don't state its rates at all.
- If you're given the searches that showed this page on Google, use the words people actually search for in the meta title and description.
- Count characters with placeholders filled in (you are told what each shows) when keeping to the house style's lengths.

Reply with the four fields between tags, in this order, and nothing else:
<intro>...</intro>
<meta_title>...</meta_title>
<meta_description>...</meta_description>
<explanation>
...
</explanation>
The explanation uses the editor's format from the house style, written exactly as it should appear, with real line breaks.

The house style follows.

TXT;

    return $job . houseStyleText($countryPrefix);
}

/** The request for one subject: what the page is, its current text, its searches and its figures. */
function pageDraftRequest(array $subject, array $facts): string
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

    return "Page: {$subject['name']}\n"
        . "Country: {$subject['country_name']}\n"
        . "Section: {$subject['category_name']} > {$subject['subcategory_name']}\n"
        . "Tax year of the figures: $taxYear (placeholder: {tax_year})\n"
        . ($subject['intro'] ? "Current intro (improve on it or keep its substance): {$subject['intro']}\n" : '')
        . ($searches ? "\nSearches that showed this page on Google:\n" . implode("\n", $searches) . "\n" : '')
        . "\nFigures on the page:\n" . implode("\n", $lines);
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
function parsePageDraft(string $reply): array
{
    $data = [];
    foreach (DRAFT_FIELDS as $field) {
        if (preg_match('#<' . $field . '>(.*?)</' . $field . '>#s', $reply, $m)) {
            $data[$field] = $m[1];
        }
    }

    if (!isset($data['explanation'])) {
        // An opening tag with no closing one means the reply was cut off.
        $cutOff = strpos($reply, '<explanation>') !== false;
        throw new RuntimeException(($cutOff
            ? 'The AI reply was cut off before the end of the explanation.'
            : 'The AI reply was not in the expected format.')
            . ' Try again. It began: "' . aiReplyStart($reply) . '"');
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
    return $draft;
}
