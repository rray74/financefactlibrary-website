<?php
/**
 * Bank Rate watch: the logic behind scripts/check-bank-rate.php.
 *
 * WHY IT EXISTS. The Bank of England announces Bank Rate at about 12:00 on
 * each MPC decision day. The daily check-sources.php runs at 07:30, so it
 * only spots a change the next morning, and its email is generic. This
 * watch runs in the afternoon of a decision day only, tells you plainly
 * what changed, and drafts the CSV for the update (the manual step in
 * docs/TODO-HANDOVER.md). It never changes the database.
 *
 * Kept in its own file, free of database and network calls where possible,
 * so each decision can be tested with plain values.
 */

/**
 * MPC announcement dates (Thursdays), from the Bank's page
 * https://www.bankofengland.co.uk/monetary-policy/upcoming-mpc-dates
 * (read live on 21 Nov 2026). Add the 2028 dates when the Bank publishes them.
 */
const BANK_RATE_MPC_DATES = [
    '2026-12-17',
    '2027-02-04', '2027-03-18', '2027-04-29', '2027-06-17',
    '2027-07-29', '2027-09-16', '2027-11-04', '2027-12-16',
];

/** The Bank's page that shows the current Bank Rate. */
const BANK_RATE_PAGE_URL = 'https://www.bankofengland.co.uk/monetary-policy/the-interest-rate-bank-rate';

/** The decision is announced at about 12:00 London time; don't look before this. */
const BANK_RATE_EARLIEST_CHECK = '12:30';

/** From this London hour on, a run is the day's last, so "page not updated" is worth an email. */
const BANK_RATE_FINAL_RUN_HOUR = 17;

/** Same shape as CURRENT_VALUE_PATTERNS['uk_base_rate'] in scripts/check-sources.php. */
const BANK_RATE_CURRENT_PATTERN = '/current\s+(?:official\s+)?bank\s+rate\D{0,40}?(\d+(?:\.\d+)?)\s*%/i';

/** The CSV columns, matching data/imports/mortgages-boe-2026-11.csv (the template). */
const BANK_RATE_CSV_HEADER = [
    'category_slug', 'category_name', 'category_description', 'subcategory_slug', 'subcategory_name',
    'subcategory_description', 'subject_slug', 'subject_name', 'subject_intro', 'subject_meta_title',
    'subject_meta_description', 'subject_status', 'fact_key', 'label', 'value', 'unit', 'context',
    'source_name', 'source_url', 'last_updated', 'review_frequency_days', 'jurisdiction_code',
    'link_type', 'value_display', 'effective_from', 'previous_value', 'tax_year', 'status',
];

/** True if $date (Y-m-d, London) is an MPC decision day. */
function bankRateIsDecisionDay(string $date): bool
{
    return in_array($date, BANK_RATE_MPC_DATES, true);
}

/** The Bank's current rate from its page text, or null if the wording has changed. */
function bankRateFromPage(string $pageText): ?float
{
    return preg_match(BANK_RATE_CURRENT_PATTERN, $pageText, $m) ? (float) $m[1] : null;
}

/**
 * The "Next due: 17 December 2026" date from the page text as Y-m-d, or null.
 * Until the Bank updates the page after a decision, this is still the
 * decision day, which is how we tell "page not updated yet" from "rate held".
 */
function bankRateNextDueFromPage(string $pageText): ?string
{
    if (!preg_match('/next\s+due:?\s*(\d{1,2}\s+[A-Za-z]+\s+\d{4})/i', $pageText, $m)) {
        return null;
    }
    $time = strtotime($m[1]);
    return $time === false ? null : date('Y-m-d', $time);
}

/** A rate written the way the site writes it: 4, 3.75, 3.5 (no trailing zeros). */
function bankRateShort(float $rate): string
{
    return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
}

/**
 * Decide what a run should do.
 *
 *   $today      Y-m-d, London time
 *   $time       H:i, London time
 *   $stored     the rate on the site (facts.value_numeric), or null if not found
 *   $onPage     the Bank page's current rate, or null if unreadable
 *   $nextDue    the page's "Next due" date (Y-m-d), or null if not found
 *   $simulating true when testing with --simulate-date (skips the time-of-day check)
 *
 * Returns one of:
 *   not_decision_day  nothing to do
 *   too_early         decision day, but before the announcement
 *   changed           the Bank's rate differs from the site: alert now, draft the CSV
 *   page_stale        page still shows the old "Next due": alert only on the last run
 *   unreadable        couldn't read the rate: alert only on the last run
 *   unchanged         page updated and matches the site (rate held): confirm once
 */
function bankRateAssess(string $today, string $time, ?float $stored, ?float $onPage, ?string $nextDue, bool $simulating = false): string
{
    if (!bankRateIsDecisionDay($today)) {
        return 'not_decision_day';
    }
    if (!$simulating && $time < BANK_RATE_EARLIEST_CHECK) {
        return 'too_early';
    }
    if ($onPage === null) {
        return 'unreadable';
    }
    if ($stored === null || abs($onPage - $stored) >= 0.005) {
        return 'changed';
    }
    // Same number as ours. Is that because the Bank held, or because the page
    // hasn't been updated yet? A page updated after today's decision no longer
    // says the next decision is today.
    if ($nextDue !== null && $nextDue <= $today) {
        return 'page_stale';
    }
    return 'unchanged';
}

/**
 * The draft CSV for the update, as text. Same columns and wording as
 * data/imports/mortgages-boe-2026-11.csv. previous_value is left blank, as in
 * that file: the importer records the old value in the fact's history itself.
 */
function bankRateDraftCsv(float $oldRate, float $newRate, string $decisionDate): string
{
    $direction = $newRate > $oldRate ? 'Increased' : 'Reduced';
    $when = date('j F Y', strtotime($decisionDate));
    $context = 'Set by the Monetary Policy Committee, which meets eight times a year. '
        . $direction . ' to ' . bankRateShort($newRate) . '% on ' . $when . '.';

    $row = array_fill_keys(BANK_RATE_CSV_HEADER, '');
    $row['category_slug']         = 'mortgages';
    $row['subcategory_slug']      = 'fixed-rate-mortgages';
    $row['subject_slug']          = '2-year-fixed-mortgage-rates';
    $row['fact_key']              = 'uk_base_rate';
    $row['label']                 = 'Bank of England Bank Rate';
    $row['value']                 = number_format($newRate, 2, '.', '');
    $row['unit']                  = '%';
    $row['context']               = $context;
    $row['source_name']           = 'Bank of England';
    $row['source_url']            = BANK_RATE_PAGE_URL;
    $row['last_updated']          = $decisionDate;
    $row['review_frequency_days'] = '42';
    $row['jurisdiction_code']     = 'GB';
    $row['effective_from']        = $decisionDate;
    $row['status']                = 'published';

    $handle = fopen('php://temp', 'w+');
    fputcsv($handle, BANK_RATE_CSV_HEADER, ',', '"', '\\');
    fputcsv($handle, array_values($row), ',', '"', '\\');
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);
    return $csv;
}

/**
 * The email for a state: ['subject' => ..., 'body' => ...], or null if that
 * state doesn't need one. $csvFile is the path (from the project root) of
 * the saved draft CSV, or null if it couldn't be saved (the CSV text is in
 * the email either way).
 */
function bankRateEmail(string $state, string $decisionDate, ?float $stored, ?float $onPage, ?string $csv, ?string $csvFile): ?array
{
    $when = date('j F Y', strtotime($decisionDate));
    $prefix = '[Finance Fact Library] ';

    switch ($state) {
        case 'changed':
            $new = bankRateShort((float) $onPage);
            $old = $stored !== null ? bankRateShort($stored) : 'not found';
            $file = $csvFile ?? 'data/imports/bank-rate-' . $decisionDate . '.csv';
            return [
                'subject' => $prefix . "ACTION: Bank Rate is now {$new}% (the site shows {$old}%)",
                'body' => "The Bank of England's page shows Bank Rate at {$new}% after the {$when} MPC decision.\n"
                    . "The site still shows {$old}%.\n\n"
                    . "A draft CSV is below" . ($csvFile ? " and saved on the server as $csvFile" : '') . ". To update:\n"
                    . "1. Mac: save it as $file, check it, run  php scripts/import-facts.php $file  against MAMP.\n"
                    . "2. Server (over SSH): run the same command from ~/domains/financefactlibrary.com/app.\n"
                    . "3. Check the Base Rate page, and that no page text says the old rate.\n\n"
                    . "The Bank's page was read live just now. Please still look at it before importing:\n"
                    . BANK_RATE_PAGE_URL . "\n\n"
                    . "--- $file ---\n" . $csv . "---\n",
            ];

        case 'unchanged':
            $rate = bankRateShort((float) $onPage);
            return [
                'subject' => $prefix . "Bank Rate held at {$rate}%, no update needed",
                'body' => "The {$when} MPC decision left Bank Rate at {$rate}%, and the Bank's page shows that.\n"
                    . "The site already shows {$rate}%. Nothing to do.\n"
                    . "(The context line on the Base Rate page may still name the last change; that is fine.)\n",
            ];

        case 'page_stale':
            return [
                'subject' => $prefix . "Bank Rate: the Bank's page hasn't updated after the {$when} decision",
                'body' => "It is after 17:00 on the {$when} MPC decision day and the Bank's Bank Rate page still says the next decision is due on {$when}.\n"
                    . "That usually means the page hasn't been updated yet, so the watch can't tell whether the rate changed.\n\n"
                    . "Check the announcement yourself: " . BANK_RATE_PAGE_URL . "\n"
                    . "The daily source check tomorrow at 07:30 will also compare the page with the site.\n"
                    . "To re-run the watch by hand (server):  php scripts/check-bank-rate.php --simulate-date={$decisionDate}\n",
            ];

        case 'unreadable':
            return [
                'subject' => $prefix . "Bank Rate: couldn't read the Bank's page on the {$when} decision day",
                'body' => "The Bank Rate watch couldn't read the current rate from the Bank's page after the {$when} MPC decision.\n"
                    . "Either the page couldn't be fetched or its wording has changed.\n\n"
                    . "Check the rate yourself: " . BANK_RATE_PAGE_URL . "\n"
                    . "The pattern is BANK_RATE_CURRENT_PATTERN in includes/bank-rate-watch.php.\n",
            ];
    }
    return null;
}
