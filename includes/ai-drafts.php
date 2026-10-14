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
 * Figures: the AI is told to use only the figures it's given, written
 * exactly as given. As a safety net, every number in the draft is
 * compared with the page's figures, and any that don't match are listed
 * when the draft appears, so you can check or remove them.
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

    $reply = callClaude(explanationDraftInstructions(), explanationDraftRequest($subject, $published), 3000);

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

/** The standing instructions for writing an explanation. */
function explanationDraftInstructions(): string
{
    return <<<'TXT'
You write first drafts of explanations for Finance Fact Library, a UK website that publishes official personal finance figures (tax, pensions, savings, mortgages, work and benefits), each sourced from GOV.UK, HMRC and similar.

Each page shows its figures as cards. Your explanation appears below them and helps a general reader understand what the figures mean and how they apply: who they affect, how they work together, common points of confusion, and what to check.

Rules:
- UK English, plain and friendly, short sentences. Write for someone with no financial background.
- Use ONLY the figures you are given, written exactly as given (e.g. £12,570, 20%). Never introduce any other amount, rate, threshold or date, and never calculate new figures. If something needs a figure you weren't given, describe it in words.
- Stay factual. No financial advice or recommendations ("you should..."). Where a reader's situation matters, suggest they check GOV.UK or speak to a regulated adviser.
- Don't repeat the figure cards as a list; explain them.
- 350 to 600 words.

Format (the site's editor only understands these):
- "## Heading" for 3 to 5 section headings, no other heading levels.
- Paragraphs separated by a blank line.
- "- item" for bullet lists, "1. item" for numbered steps, used sparingly.
- **bold** only for a few key terms.
- No links, tables, images, HTML or other Markdown.

Reply with the explanation text only: no title, no preamble, no notes.
TXT;
}

/** The request for one subject: what the page is, its intro, and its figures. */
function explanationDraftRequest(array $subject, array $facts): string
{
    $lines = [];
    foreach ($facts as $fact) {
        // The context readers see on this page (an override, if the page has one).
        $context = $fact['context_override'] ?? $fact['context'];
        $line = '- ' . $fact['label'] . ': ' . formatFactValue($fact);
        if (isRegionalFact($fact)) {
            $line .= ' (applies in ' . $fact['jurisdiction_name'] . ')';
        }
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

    return "Page: {$subject['name']}\n"
        . "Section: {$subject['category_name']} > {$subject['subcategory_name']}\n"
        . ($subject['intro'] ? "Intro shown above the figures: {$subject['intro']}\n" : '')
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
