<?php
/**
 * Fetches average mortgage and cash ISA rates from the Bank of England's
 * statistical database and updates (or confirms) the matching facts.
 *
 * Usage, from the project root:
 *   php scripts/fetch-boe-rates.php --dry-run   show what would change, write nothing
 *   php scripts/fetch-boe-rates.php             fetch and apply
 *   php scripts/fetch-boe-rates.php --describe=IUMBV34,IUMTLMV
 *                                               show the Bank's title and latest
 *                                               value for any series codes
 *   php scripts/fetch-boe-rates.php --from-file=boe.csv --dry-run
 *                                               use a saved download instead of
 *                                               fetching (for testing)
 *
 * Runs on the live server from a cron job (see README). It has to run
 * there, not from Claude's tools, because the Bank's database is only
 * reachable from an ordinary server or browser.
 *
 * Safety checks, in order, for every series:
 *   1. The Bank's own description of the series must match the words we
 *      expect (e.g. "2 year", "75%", "fixed"). A wrong series code can
 *      never publish a wrong figure.
 *   2. The value must be a plausible interest rate (between 0% and 20%).
 *   3. A change bigger than the series' limit (e.g. 0.75 percentage
 *      points in a month) is held for review instead of published, and
 *      recorded in fact_changes.
 * Unchanged values are marked as verified. Changes go through
 * updateFactValue(), so they're recorded in fact_history and appear in
 * the page's "What's changed" section.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

// Show errors (Hostinger's command line hides them by default).
ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/fact-writer.php';

const BOE_DATABASE_URL = 'https://www.bankofengland.co.uk/boeapps/database/';
const BOE_CSV_ENDPOINT = 'https://www.bankofengland.co.uk/boeapps/database/_iadb-fromshowcolumns.asp';

/**
 * The series this script maintains, keyed by fact_key.
 *
 *   code        the Bank's series code
 *   match       patterns the Bank's own series description must ALL match
 *   max_change  biggest month-to-month move (percentage points) that is
 *               published automatically; bigger moves are held for review
 *   context     the fact's explanatory note; %s becomes the data month,
 *               and a literal percent sign must be written %% (sprintf)
 *   create      if the fact doesn't exist yet, create it with these
 *               details (and retire the fact it replaces, if any)
 */
const BOE_SERIES = [
    'boe_2yr_fixed_75ltv' => [
        'code'       => 'IUMBV34',
        'match'      => ['/2\s*-?\s*year/i', '/75\s*%/', '/fixed/i'],
        'max_change' => 0.75,
        'context'    => 'Bank of England average of rates advertised by UK lenders in %s, for borrowers with a 25%% deposit.',
    ],
    'boe_2yr_fixed_90ltv' => [
        'code'       => 'IUMB482',
        'match'      => ['/2\s*-?\s*year/i', '/90\s*%/', '/fixed/i'],
        'max_change' => 0.75,
        'context'    => 'Bank of England average of rates advertised by UK lenders in %s, for borrowers with a 10%% deposit.',
    ],
    'boe_2yr_fixed_95ltv' => [
        'code'       => 'IUM2WTL',
        'match'      => ['/2\s*-?\s*year/i', '/95\s*%/', '/fixed/i'],
        'max_change' => 0.75,
        'context'    => 'Bank of England average of rates advertised by UK lenders in %s, for borrowers with a 5%% deposit.',
    ],
    'boe_5yr_fixed_75ltv' => [
        'code'       => 'IUMBV42',
        'match'      => ['/5\s*-?\s*year/i', '/75\s*%/', '/fixed/i'],
        'max_change' => 0.75,
        'context'    => 'Bank of England average of rates advertised by UK lenders in %s. For comparison with the 2-year rate at the same deposit size.',
    ],
    'boe_svr' => [
        'code'       => 'IUMTLMV',
        // The Bank now calls the SVR the "revert-to rate", so accept either name.
        'match'      => ['/revert[\s-]*to[\s-]*rate|standard variable/i'],
        'max_change' => 0.5,
        'context'    => 'Bank of England average of the standard variable rates (which the Bank calls revert-to rates) UK lenders charged in %s. Borrowers usually move onto their lender\'s SVR when a fixed or tracker deal ends.',
        'create'     => [
            'subject_slug' => 'standard-variable-rate',
            'label'        => 'Average standard variable rate (SVR)',
            'replaces'     => 'avg_svr',
        ],
    ],

    // Cash ISA averages, shown on the ISA allowance page. Rates include
    // unconditional bonuses, matching the headline rates savers see
    // advertised.
    // The site's existing easy-access ISA figure was already taken from
    // this Bank of England series, so the job maintains that fact rather
    // than replacing it, keeping its history and label.
    'avg_easy_access_isa' => [
        'code'       => 'IUMB6VL',
        'match'      => ['/variable[\s-]*rate cash ISA/i', '/including unconditional/i'],
        'max_change' => 0.5,
        'context'    => 'Bank of England average of the rates UK banks and building societies advertised on variable-rate (easy access) cash ISAs in %s, including bonuses. Individual accounts vary widely around this.',
    ],
    'boe_cash_isa_1yr_fixed' => [
        'code'       => 'IUMB6VN',
        'match'      => ['/(one|1)[\s-]*year fixed[\s-]*rate cash ISA/i'],
        'max_change' => 0.5,
        'context'    => 'Bank of England average of the rates advertised on 1-year fixed-rate cash ISAs in %s. Your money is tied up for the year in return for a guaranteed rate.',
        'create'     => [
            'subject_slug' => 'cash-isa-allowance',
            'label'        => 'Average 1-year fixed cash ISA rate',
        ],
    ],
    'boe_cash_isa_2yr_fixed' => [
        'code'       => 'IUMZID2',
        'match'      => ['/(two|2)[\s-]*year fixed[\s-]*rate cash ISA/i'],
        'max_change' => 0.5,
        'context'    => 'Bank of England average of the rates advertised on 2-year fixed-rate cash ISAs in %s.',
        'create'     => [
            'subject_slug' => 'cash-isa-allowance',
            'label'        => 'Average 2-year fixed cash ISA rate',
        ],
    ],
];

// ------------------------------------------------------------
// Options
// ------------------------------------------------------------
$options = getopt('', ['dry-run', 'describe:', 'from-file:']);
$dryRun = isset($options['dry-run']);

// --describe: just print what the Bank calls these series. Useful for
// checking a code before adding it to BOE_SERIES.
if (isset($options['describe'])) {
    $codes = array_filter(array_map('trim', explode(',', $options['describe'])));
    $data = fetchBoeSeries($codes, $options['from-file'] ?? null);
    foreach ($codes as $code) {
        $latest = latestValues($data['values'][$code] ?? []);
        echo $code . "\n  " . ($data['titles'][$code] ?? '(no description returned)') . "\n";
        echo '  Latest: ' . ($latest ? $latest['value'] . ' (' . $latest['date'] . ')' : 'no data') . "\n\n";
    }
    exit(0);
}

$pdo = getDbConnection();

// Log the run, so a failed or stuck run is visible later.
$runId = null;
if (!$dryRun) {
    $pdo->prepare("INSERT INTO cron_runs (script) VALUES ('fetch-boe-rates.php')")->execute();
    $runId = (int) $pdo->lastInsertId();
}

$counts = ['changed' => 0, 'verified' => 0, 'created' => 0, 'held' => 0, 'failed' => 0];

try {
    $codes = array_column(BOE_SERIES, 'code');
    $data = fetchBoeSeries($codes, $options['from-file'] ?? null);
    $sourceId = getOrCreateSource($pdo, BOE_DATABASE_URL, 'Bank of England', null);

    echo ($dryRun ? "DRY RUN: nothing will be written.\n\n" : '');

    foreach (BOE_SERIES as $factKey => $series) {
        $code = $series['code'];
        $title = $data['titles'][$code] ?? '';
        echo "$factKey ($code)\n";

        try {
            // Check 1: the Bank's description matches what we expect.
            foreach ($series['match'] as $pattern) {
                if (!preg_match($pattern, $title)) {
                    throw new RuntimeException("the Bank's description doesn't match. It says: " . ($title ?: '(none)'));
                }
            }

            $latest = latestValues($data['values'][$code] ?? []);
            if (!$latest) {
                throw new RuntimeException('no data returned for this series');
            }

            // Check 2: a plausible interest rate.
            $value = (float) $latest['value'];
            if ($value <= 0 || $value >= 20) {
                throw new RuntimeException("implausible value {$latest['value']}");
            }
            $valueText = number_format($value, 2, '.', '');
            $month = date('F Y', strtotime($latest['date']));
            $context = sprintf($series['context'], $month);

            echo "  Bank says: {$valueText}% for $month\n";

            $stmt = $pdo->prepare('SELECT id, value_numeric, status FROM facts WHERE fact_key = :key');
            $stmt->execute(['key' => $factKey]);
            $fact = $stmt->fetch();

            // A new series: create its fact (and retire the one it replaces).
            if (!$fact) {
                if (empty($series['create'])) {
                    throw new RuntimeException('fact does not exist and has no creation details');
                }
                echo "  Will create the fact" . (!empty($series['create']['replaces'])
                    ? " and retire '{$series['create']['replaces']}'" : '') . "\n";
                if (!$dryRun) {
                    createBoeFact($pdo, $factKey, $series, $valueText, $context, $sourceId, $latest['previous']);
                }
                $counts['created']++;
                echo "\n";
                continue;
            }

            $current = $fact['value_numeric'] !== null ? (float) $fact['value_numeric'] : null;

            // Check 3: hold unusually large moves for a human to look at.
            if ($current !== null && abs($value - $current) > $series['max_change']) {
                echo "  HELD: change from {$current}% is bigger than the {$series['max_change']} point limit.\n";
                if (!$dryRun) {
                    holdChange($pdo, (int) $fact['id'], $valueText, $sourceId, $code, $title, $latest['date'],
                        $runId, "Change from {$current}% exceeds the {$series['max_change']} point limit");
                }
                $counts['held']++;
                echo "\n";
                continue;
            }

            if ($current !== null && abs($value - $current) < 0.005) {
                echo "  Unchanged, will mark as verified\n";
            } else {
                echo "  Will change from " . ($current ?? '?') . "% to {$valueText}%\n";
            }

            if (!$dryRun) {
                $changed = updateFactValue($pdo, (int) $fact['id'], $valueText, [
                    'change_source' => 'pipeline',
                    'note'          => "Bank of England series $code, $month",
                ]);
                // Keep the note under the figure naming the right month.
                $pdo->prepare('UPDATE facts SET context = :ctx, source_id = :src WHERE id = :id')
                    ->execute(['ctx' => $context, 'src' => $sourceId, 'id' => $fact['id']]);
                $counts[$changed ? 'changed' : 'verified']++;
            } else {
                $counts[$current !== null && abs($value - $current) < 0.005 ? 'verified' : 'changed']++;
            }
        } catch (Throwable $e) {
            echo '  SKIPPED: ' . $e->getMessage() . "\n";
            $counts['failed']++;
        }
        echo "\n";
    }

    $summary = "{$counts['changed']} changed, {$counts['verified']} verified, {$counts['created']} created, "
        . "{$counts['held']} held for review, {$counts['failed']} skipped";
    echo "Done. $summary.\n";

    if ($runId) {
        $pdo->prepare(
            "UPDATE cron_runs SET status = :status, jobs_processed = :done, jobs_failed = :failed,
             error = :note, finished_at = NOW() WHERE id = :id"
        )->execute([
            'status' => $counts['failed'] > 0 ? 'error' : 'ok',
            'done'   => $counts['changed'] + $counts['verified'] + $counts['created'],
            'failed' => $counts['failed'] + $counts['held'],
            'note'   => $summary,
            'id'     => $runId,
        ]);
    }

    // A non-zero exit code makes the cron job report a problem.
    exit($counts['failed'] > 0 || $counts['held'] > 0 ? 1 : 0);

} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    if ($runId) {
        $pdo->prepare("UPDATE cron_runs SET status = 'error', error = :e, finished_at = NOW() WHERE id = :id")
            ->execute(['e' => $e->getMessage(), 'id' => $runId]);
    }
    exit(1);
}

// ============================================================

/**
 * Download series from the Bank's database as CSV (or read a saved
 * file), returning ['titles' => [code => description],
 * 'values' => [code => [date => value]]].
 *
 * The "TT" CSV format starts with SERIES/DESCRIPTION rows, then a DATE
 * header row followed by one row per month.
 */
function fetchBoeSeries(array $codes, ?string $fromFile): array
{
    if ($fromFile !== null) {
        $csv = file_get_contents($fromFile);
        if ($csv === false) {
            throw new RuntimeException("Can't read $fromFile");
        }
    } else {
        $query = http_build_query([
            'csv.x'       => 'yes',
            'Datefrom'    => date('d/M/Y', strtotime('-14 months')),
            'Dateto'      => 'now',
            'SeriesCodes' => implode(',', $codes),
            'CSVF'        => 'TT',
            'UsingCodes'  => 'Y',
            'VPD'         => 'Y',
            'VFD'         => 'N',
        ]);

        $curl = curl_init(BOE_CSV_ENDPOINT . '?' . $query);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            // The Bank's site rejects requests that don't look like a browser.
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/124.0 Safari/537.36 (financefactlibrary.com data check)',
        ]);
        $csv = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($csv === false || $status !== 200) {
            throw new RuntimeException("Bank of England request failed (HTTP $status) $error");
        }
    }

    // An HTML page instead of CSV means the Bank returned an error page.
    if (stripos(ltrim($csv), '<') === 0) {
        throw new RuntimeException('The Bank of England returned a web page instead of data. '
            . 'The request format may have changed.');
    }

    $titles = [];
    $values = [];
    $columns = null;

    foreach (preg_split('/\R/', $csv) as $line) {
        if (trim($line) === '') {
            continue;
        }
        $row = str_getcsv($line, ',', '"', '\\');
        $first = strtoupper(trim($row[0] ?? ''));

        if ($columns === null) {
            if ($first === 'DATE') {
                $columns = array_map('trim', $row);
            } elseif ($first !== 'SERIES' && isset($row[1])) {
                $titles[trim($row[0])] = trim($row[1]);
            }
            continue;
        }

        $date = trim($row[0]);
        foreach ($columns as $i => $code) {
            if ($i === 0 || !isset($row[$i])) {
                continue;
            }
            $cell = trim($row[$i]);
            if ($cell !== '' && is_numeric($cell)) {
                $values[$code][$date] = $cell;
            }
        }
    }

    if ($columns === null) {
        throw new RuntimeException('No data table found in the Bank of England response.');
    }

    return ['titles' => $titles, 'values' => $values];
}

/**
 * The most recent value and the one before it, by date. Returns NULL if
 * there's no data.
 */
function latestValues(array $byDate): ?array
{
    if (!$byDate) {
        return null;
    }
    uksort($byDate, fn($a, $b) => strtotime($a) <=> strtotime($b));
    $dates = array_keys($byDate);
    $last = end($dates);
    $previous = count($dates) > 1 ? $dates[count($dates) - 2] : null;

    return [
        'date'     => $last,
        'value'    => $byDate[$last],
        'previous' => $previous !== null ? $byDate[$previous] : null,
    ];
}

/**
 * Create a fact for a newly added series, show it on its subject page,
 * record the previous month as history, and retire the fact it replaces.
 */
function createBoeFact(PDO $pdo, string $factKey, array $series, string $value, string $context,
                       int $sourceId, ?string $previous): void
{
    $create = $series['create'];

    $stmt = $pdo->prepare('SELECT id FROM subjects WHERE slug = :slug');
    $stmt->execute(['slug' => $create['subject_slug']]);
    $subjectId = $stmt->fetchColumn();
    if (!$subjectId) {
        throw new RuntimeException("subject '{$create['subject_slug']}' not found");
    }

    withTransaction($pdo, function () use ($pdo, $factKey, $create, $value, $context, $sourceId, $previous, $subjectId) {
        $factId = createFact($pdo, [
            'primary_subject_id'    => (int) $subjectId,
            'jurisdiction_id'       => (int) getJurisdictionByCode($pdo, 'GB')['id'],
            'fact_key'              => $factKey,
            'label'                 => $create['label'],
            'value'                 => $value,
            'unit'                  => '%',
            'context'               => $context,
            'source_id'             => $sourceId,
            'review_frequency_days' => 35,
            'status'                => 'published',
            'change_source'         => 'pipeline',
        ]);

        if ($previous !== null) {
            recordPreviousValue($pdo, $factId, number_format((float) $previous, 2, '.', ''), null);
        }

        if (!empty($create['replaces'])) {
            $pdo->prepare("UPDATE facts SET status = 'retired' WHERE fact_key = :key")
                ->execute(['key' => $create['replaces']]);
        }
    });
}

/**
 * Record a change that failed a safety check, for a human to review,
 * instead of publishing it. Held changes appear on /admin/review.php
 * with Approve and Reject buttons.
 */
function holdChange(PDO $pdo, int $factId, string $value, int $sourceId, string $code, string $title,
                    string $date, ?int $runId, string $reason): void
{
    // Already waiting for review with the same value? Then don't add it
    // again on every run.
    $stmt = $pdo->prepare(
        "SELECT 1 FROM fact_changes WHERE fact_id = :fid AND status = 'held' AND proposed_value = :value"
    );
    $stmt->execute(['fid' => $factId, 'value' => $value]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $pdo->prepare(
        "INSERT INTO fact_changes
            (change_type, fact_id, proposed_value, proposed_value_numeric, source_id, evidence_snippet,
             extraction_method, gate_allowlist, gate_verbatim, gate_threshold, status, status_reason)
         VALUES ('update', :fid, :value, :num, :src, :evidence, 'parser', 1, 1, 0, 'held', :reason)"
    )->execute([
        'fid'      => $factId,
        'value'    => $value,
        'num'      => $value,
        'src'      => $sourceId,
        'evidence' => "$code: $title. Value $value for $date." . ($runId ? " (run $runId)" : ''),
        'reason'   => $reason,
    ]);
}
