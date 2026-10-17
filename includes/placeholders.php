<?php
/**
 * Placeholders in page text.
 *
 * The four text fields of a subject page (meta title, meta description,
 * intro and explanation) can contain placeholders, filled in each time the
 * page is shown, so the text never goes out of date when a figure changes:
 *
 *   {fact:fact_key}  the fact's published value, formatted exactly as on its
 *                    card, e.g. {fact:ca_weekly_rate} -> £86.45
 *   {tax_year}       the tax year of the page's figures, written the way the
 *                    page's country writes it (2026/27 in the UK)
 *
 * Single braces, unlike the dormant article renderer's {{fact:key}} in
 * functions.php, which shows "label: value" and is a separate feature.
 *
 * The rules (see docs/house-style/core.md):
 *   - Saving refuses anything in {braces} that isn't a valid placeholder,
 *     and facts that don't exist or aren't published, so readers never see
 *     a raw {placeholder}. Both the editor and the importer check this.
 *   - A fact should be one shown on the same page. That's only a warning,
 *     since the figure is still correct.
 *   - If a fact is retired after the text was saved, its placeholder shows
 *     as nothing on the public page, and the editor flags it when opened.
 *
 * The checks only need a database connection, so the importer can use them
 * without loading functions.php. Filling placeholders in uses
 * formatFactValue() and getFactByKey() from functions.php, which loads this
 * file.
 */

/**
 * When each country's tax year starts, and how it's written:
 *   slash   2026/27  (UK, New Zealand)
 *   dash    2026–27  (Australia, with an en dash)
 *   single  2026     (US, Canada: the tax year is the calendar year)
 * Keyed by jurisdiction code, like COUNTRY_WORDING. A country missing from
 * the list is treated as a calendar-year country.
 */
const COUNTRY_TAX_YEARS = [
    'GB' => ['month' => 4, 'day' => 6, 'style' => 'slash'],
    'NZ' => ['month' => 4, 'day' => 1, 'style' => 'slash'],
    'AU' => ['month' => 7, 'day' => 1, 'style' => 'dash'],
    'US' => ['month' => 1, 'day' => 1, 'style' => 'single'],
    'CA' => ['month' => 1, 'day' => 1, 'style' => 'single'],
];

/**
 * Anything in single braces on one line. Every match must be a valid
 * placeholder, so a typo like {fact:Personal Allowance} is caught on save
 * rather than shown to readers.
 */
const PLACEHOLDER_PATTERN = '/\{([^{}\n]{1,100})\}/';

/**
 * What a placeholder means, from the text between its braces:
 * ['type' => 'tax_year'], ['type' => 'fact', 'key' => ...], or NULL if
 * it isn't one.
 */
function parsePlaceholder(string $inner): ?array
{
    if ($inner === 'tax_year') {
        return ['type' => 'tax_year'];
    }
    if (preg_match('/^fact:([a-z0-9]+(?:_[a-z0-9]+)*)$/', $inner, $m)) {
        return ['type' => 'fact', 'key' => $m[1]];
    }
    return null;
}

/** The tax year starting in $startYear, written the country's way. */
function formatTaxYear(int $startYear, string $countryCode): string
{
    $style = COUNTRY_TAX_YEARS[$countryCode]['style'] ?? 'single';
    $nextShort = sprintf('%02d', ($startYear + 1) % 100);

    if ($style === 'slash') {
        return $startYear . '/' . $nextShort;
    }
    if ($style === 'dash') {
        return $startYear . "\u{2013}" . $nextShort;
    }
    return (string) $startYear;
}

/** The start year of the country's tax year on a given date (default today). */
function taxYearStartForDate(string $countryCode, ?int $timestamp = null): int
{
    $timestamp = $timestamp ?? time();
    $start = COUNTRY_TAX_YEARS[$countryCode] ?? ['month' => 1, 'day' => 1];
    $year = (int) date('Y', $timestamp);

    // Before the start date, it's still the tax year that began last year.
    $monthDay = (int) date('n', $timestamp) * 100 + (int) date('j', $timestamp);
    return $monthDay < $start['month'] * 100 + $start['day'] ? $year - 1 : $year;
}

/**
 * What {tax_year} shows on a page: the latest tax year among the page's
 * published figures (their tax_year column), so the year only moves on
 * when new figures are imported. Pages whose figures have no tax year use
 * today's date instead.
 */
function pageTaxYear(string $countryCode, array $pageFacts): string
{
    $latest = null;
    foreach ($pageFacts as $fact) {
        if (($fact['status'] ?? 'published') !== 'published') {
            continue;
        }
        if (!empty($fact['tax_year']) && preg_match('/^(\d{4})/', (string) $fact['tax_year'], $m)) {
            $latest = max($latest ?? 0, (int) $m[1]);
        }
    }
    return formatTaxYear($latest ?? taxYearStartForDate($countryCode), $countryCode);
}

// ------------------------------------------------------------
// Checking (editor and importer)
// ------------------------------------------------------------

/**
 * Check the placeholders in one or more text fields.
 *
 * $texts         field name => text, e.g. ['Meta title' => '...'], used to
 *                say where each problem is
 * $pageFactKeys  fact keys shown on this page, for the "not on this page"
 *                warning; NULL to skip that check
 *
 * Returns ['errors' => [...], 'warnings' => [...]], as readable sentences.
 */
function checkPlaceholders(PDO $pdo, array $texts, ?array $pageFactKeys = null): array
{
    $errors = [];
    $warnings = [];
    $factUses = []; // fact key => field names it's used in

    foreach ($texts as $field => $text) {
        if ($text === null || $text === '' || strpos($text, '{') === false) {
            continue;
        }
        preg_match_all(PLACEHOLDER_PATTERN, $text, $matches, PREG_SET_ORDER);
        foreach ($matches as [$token, $inner]) {
            $parsed = parsePlaceholder(trim($inner));
            if ($parsed === null) {
                $errors[] = "$field: $token isn't a placeholder. Use {fact:fact_key} or {tax_year}, "
                    . 'with the fact key in lower case.';
            } elseif ($parsed['type'] === 'fact') {
                $factUses[$parsed['key']][$field] = true;
            }
        }
    }

    if ($factUses) {
        // One query for every fact used, to see whether each exists and is published.
        $keys = array_keys($factUses);
        $stmt = $pdo->prepare(
            'SELECT fact_key, status FROM facts WHERE fact_key IN ('
            . implode(',', array_fill(0, count($keys), '?')) . ')'
        );
        $stmt->execute($keys);
        $statuses = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($factUses as $key => $fields) {
            $where = implode(', ', array_keys($fields));
            if (!isset($statuses[$key])) {
                $errors[] = "$where: {fact:$key} doesn't match any fact. Check the key in the placeholder list.";
            } elseif ($statuses[$key] !== 'published') {
                $errors[] = "$where: {fact:$key} is {$statuses[$key]}, so it can't be shown.";
            } elseif ($pageFactKeys !== null && !in_array($key, $pageFactKeys, true)) {
                $warnings[] = "$where: {fact:$key} isn't one of this page's figures, so readers can't see "
                    . 'its card. The house style is to use figures shown on the same page.';
            }
        }
    }

    return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
}

/** Throw if any placeholder in the texts is invalid. Used before every save. */
function assertValidPlaceholders(PDO $pdo, array $texts): void
{
    $errors = checkPlaceholders($pdo, $texts)['errors'];
    if ($errors) {
        throw new RuntimeException('Not saved. ' . implode(' ', $errors));
    }
}

// ------------------------------------------------------------
// Filling in (public pages and the editor's previews)
// ------------------------------------------------------------

/**
 * Replace the placeholders in a text with live values. Returns plain text:
 * escape it (or pass it to renderExplanation(), which does) before output.
 *
 * $pageFacts  the page's facts, for {tax_year}
 *
 * A fact that's missing or no longer published shows as nothing. Anything
 * else in braces is left as typed, since saving won't allow it anyway.
 */
function fillPlaceholders(?string $text, string $countryCode, array $pageFacts): ?string
{
    if ($text === null || strpos($text, '{') === false) {
        return $text;
    }

    return preg_replace_callback(PLACEHOLDER_PATTERN, function ($m) use ($countryCode, $pageFacts) {
        $parsed = parsePlaceholder(trim($m[1]));
        if ($parsed === null) {
            return $m[0];
        }
        if ($parsed['type'] === 'tax_year') {
            return pageTaxYear($countryCode, $pageFacts);
        }
        $fact = getFactByKey($parsed['key']); // published facts only, cached per request
        return $fact ? formatFactValue($fact) : '';
    }, $text);
}

/**
 * fillPlaceholders() for listing pages, which have the subject but not its
 * facts. The facts are only loaded if the text needs {tax_year}, so text
 * without placeholders costs nothing.
 */
function fillSubjectPlaceholders(?string $text, int $subjectId, string $countryCode): ?string
{
    if ($text === null || strpos($text, '{') === false) {
        return $text;
    }
    $facts = strpos($text, '{tax_year}') !== false ? getFactsForSubject($subjectId) : [];
    return fillPlaceholders($text, $countryCode, $facts);
}

/**
 * Every placeholder a page can use, with what it shows now, for the
 * editor's list and its live character counts: token => value. Only the
 * page's published facts, in page order.
 */
function placeholderValuesForPage(string $countryCode, array $pageFacts): array
{
    $values = ['{tax_year}' => pageTaxYear($countryCode, $pageFacts)];
    foreach ($pageFacts as $fact) {
        if (($fact['status'] ?? 'published') === 'published') {
            $values['{fact:' . $fact['fact_key'] . '}'] = formatFactValue($fact);
        }
    }
    return $values;
}
