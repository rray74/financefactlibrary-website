<?php
/**
 * "Draft this for me" (Phase 2): a first draft of a subject page's
 * explanation, written by Claude from the page's own published figures.
 *
 * The draft is never saved by itself. The editor puts it into the
 * Explanation box, and nothing reaches the database (or the public page)
 * until you've read it, rewritten it in your own words, and clicked
 * Save page details. It's a starting point, not finished copy: pages
 * that are recognisably human-edited are what search engines reward.
 *
 * House style: the AI is sent docs/house-style/core.md and the page's
 * country file (e.g. docs/house-style/uk.md) with every request, so drafts
 * follow the same rules as hand-written pages. Edit those files to change
 * how drafts are written.
 *
 * Figures: the AI is given each figure with its placeholder
 * ({fact:fact_key}) and told to use the placeholder for anything that
 * changes, and never to introduce other figures. As a safety net, every
 * number typed in the draft is compared with the page's figures, and any
 * that don't match are listed when the draft appears.
 *
 * Uses callClaude() and the API key from includes/ai-proposer.php.
 */

require_once __DIR__ . '/ai-proposer.php';

/**
 * Write a draft explanation for a subject.
 *
 * $subject  the subject row (name, intro, category and subcategory names)
 * $facts    the page's facts, as getAdminFactsForSubject() returns them;
 *           only published ones are used, since only they're public
 *
 * Returns ['text' => the draft, 'unmatched' => numbers in the draft that
 * aren't among the page's figures].
 */
function draftSubjectExplanation(array $subject, array $facts): array
{
    $published = array_values(array_filter($facts, fn($f) => $f['status'] === 'published'));
    if (!$published) {
        throw new RuntimeException('This page has no published figures yet, so there is nothing to write from. Add facts first.');
    }

    $reply = callClaude(
        explanationDraftInstructions((string) $subject['country_prefix']),
        explanationDraftRequest($subject, $published),
        3000
    );

    // Tidy the reply: drop any ```markdown fences, normalise line endings,
    // and keep it within the editor's 20,000-character limit.
    $text = preg_replace('/^```[a-z]*\s*$/mi', '', str_replace(["\r\n", "\r"], "\n", $reply));
    $text = trim(preg_replace("/\n{3,}/", "\n\n", $text));
    if ($text === '') {
        throw new RuntimeException('The AI returned an empty draft. Try again.');
    }
    if (textLength($text) > 20000) {
        $text = substr($text, 0, 20000);
    }

    return ['text' => $text, 'unmatched' => findUnmatchedNumbers($text, $published)];
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

/** The standing instructions for writing an explanation: the job, then the house style. */
function explanationDraftInstructions(string $countryPrefix): string
{
    $job = <<<'TXT'
You write first drafts of the explanation section for Finance Fact Library, a website that publishes official personal finance figures, each sourced from the government and similar official bodies.

Each page shows its figures as cards. Your explanation appears below them and helps a general reader understand what the figures mean and how they apply.

You are drafting the EXPLANATION field only. Follow the house style below exactly, in particular:
- Each figure you are given comes with its placeholder, such as {fact:personal_allowance}. Write the placeholder, not the figure, for anything that changes. Only type a figure if the house style allows it (fixed until a known date, which you must then give).
- Never introduce any amount, rate, threshold or date you weren't given, and never calculate new figures, except a rate worked out directly from fixed figures, with the working shown.
- A page whose figures are checked every 42 days or less is a rate page: don't state its rates at all.

Reply with the explanation text only: no title, no preamble, no notes.

The house style follows.

TXT;

    return $job . houseStyleText($countryPrefix);
}

/** The request for one subject: what the page is, its intro, and its figures. */
function explanationDraftRequest(array $subject, array $facts): string
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

    $taxYear = pageTaxYear((string) $subject['country_code'], $facts);

    return "Page: {$subject['name']}\n"
        . "Country: {$subject['country_name']}\n"
        . "Section: {$subject['category_name']} > {$subject['subcategory_name']}\n"
        . ($subject['intro'] ? "Intro shown above the figures: {$subject['intro']}\n" : '')
        . "Tax year of the figures: $taxYear (placeholder: {tax_year})\n"
        . "\nFigures on the page:\n" . implode("\n", $lines);
}

/**
 * Numbers in the draft that don't match any of the page's figures, so
 * they can be checked before saving. Ignored: whole numbers under 10
 * (like "Plan 2" or "3 steps"), years from 1900 to 2100, and the parts
 * of the page's tax years (2026/27).
 */
function findUnmatchedNumbers(string $text, array $facts): array
{
    // Every form a page figure might take, without thousands separators.
    $known = [];
    foreach ($facts as $fact) {
        foreach ([$fact['value'], formatFactValue($fact)] as $written) {
            if (preg_match_all('/\d[\d,]*(?:\.\d+)?/', (string) $written, $m)) {
                foreach ($m[0] as $number) {
                    $known[str_replace(',', '', $number)] = true;
                }
            }
        }
        if ($fact['value_numeric'] !== null) {
            $n = (float) $fact['value_numeric'];
            $known[rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.')] = true; // 241.30 -> 241.3
            $known[number_format($n, 2, '.', '')] = true;                         // 3.5 -> 3.50
        }
        if ($fact['tax_year'] && preg_match('#^(\d{4})/(\d{2})$#', $fact['tax_year'], $m)) {
            $known[$m[1]] = true;
            $known[$m[2]] = true;
        }
    }

    $unmatched = [];
    if (preg_match_all('/(?<![\w.])\d[\d,]*(?:\.\d+)?/', $text, $m)) {
        foreach ($m[0] as $number) {
            $plain = rtrim(str_replace(',', '', $number), '.');
            $value = (float) $plain;
            $isSmallWhole = $value < 10 && strpos($plain, '.') === false;
            $isYear = strpos($plain, '.') === false && $value >= 1900 && $value <= 2100;
            if (!$isSmallWhole && !$isYear && !isset($known[$plain])) {
                $unmatched[$number] = true;
            }
        }
    }
    return array_keys($unmatched);
}
