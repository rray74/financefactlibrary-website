<?php
/**
 * Bank Rate watch for MPC decision days.
 *
 * On a Bank of England MPC decision day, reads the Bank's Bank Rate page
 * and compares it with the site's uk_base_rate figure. If they differ it
 * emails you straight away, with a draft CSV for the update. On any other
 * day it does nothing. It never changes the database or any content.
 * The logic and the decision dates are in includes/bank-rate-watch.php.
 *
 * Usage, from the project root:
 *   php scripts/check-bank-rate.php --dry-run                     show what it would do; send and write nothing
 *   php scripts/check-bank-rate.php --dry-run --simulate-date=2026-12-17
 *                                                                 pretend it is that day (and past 12:30)
 *   php scripts/check-bank-rate.php --simulate-date=2026-12-17 --from-file=page.txt
 *                                                                 use saved page text instead of fetching
 *   php scripts/check-bank-rate.php --no-email                    do everything except send the email
 *   php scripts/check-bank-rate.php                               the live run (cron)
 *
 * Cron (hPanel), three times an afternoon so a slow page update is caught:
 *   0 13,15,17 * * *   php ~/domains/financefactlibrary.com/app/scripts/check-bank-rate.php
 * (Hostinger's cron clock may not be London time; the script works in
 * London time itself, so just run it at those hours or a little later.)
 *
 * Emails are sent at most once per decision day and kind (changed, held,
 * page not updated, unreadable), remembered in admin_alerts_sent under keys
 * starting "bankrate:" (see includes/alerts.php). No AI is used, so there is
 * no AI key and no cost.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/source-pages.php';
require_once __DIR__ . '/../includes/alerts.php';
require_once __DIR__ . '/../includes/bank-rate-watch.php';

$options = getopt('', ['dry-run', 'no-email', 'simulate-date:', 'from-file:']);
$dryRun = isset($options['dry-run']);
$noEmail = isset($options['no-email']);
$simulating = isset($options['simulate-date']);

// Everything is judged in London time, whatever the server's clock says.
$london = new DateTimeZone('Europe/London');
$now = new DateTimeImmutable('now', $london);
$today = $simulating ? (string) $options['simulate-date'] : $now->format('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) || strtotime($today) === false) {
    fwrite(STDERR, "--simulate-date must be a date like 2026-12-17.\n");
    exit(1);
}
$time = $now->format('H:i');
// A simulated day counts as the final run, so every email can be previewed.
$finalRun = $simulating || (int) $now->format('G') >= BANK_RATE_FINAL_RUN_HOUR;

$pdo = getDbConnection();

$runId = null;
if (!$dryRun) {
    $pdo->prepare("INSERT INTO cron_runs (script) VALUES ('check-bank-rate.php')")->execute();
    $runId = (int) $pdo->lastInsertId();
}

/** Finish the cron_runs row (shown on the dashboard) and exit. */
function finishRun(PDO $pdo, ?int $runId, bool $failed, string $summary): void
{
    echo "\nDone. $summary\n";
    if ($runId) {
        $pdo->prepare(
            'UPDATE cron_runs SET status = :status, jobs_processed = 1, jobs_failed = :failed,
             error = :note, finished_at = NOW() WHERE id = :id'
        )->execute(['status' => $failed ? 'error' : 'ok', 'failed' => $failed ? 1 : 0,
                    'note' => substr($summary, 0, 1000), 'id' => $runId]);
    }
    exit($failed ? 1 : 0);
}

try {
    echo ($dryRun ? "DRY RUN: nothing is sent or written.\n" : '')
        . "London time: $today $time" . ($simulating ? ' (simulated date)' : '') . "\n";

    if (!bankRateIsDecisionDay($today)) {
        finishRun($pdo, $runId, false, 'Not an MPC decision day; nothing to do.');
    }
    if (!$simulating && $time < BANK_RATE_EARLIEST_CHECK) {
        finishRun($pdo, $runId, false, 'Decision day, but before the ' . BANK_RATE_EARLIEST_CHECK . ' check time; nothing to do.');
    }

    // The site's current figure.
    $stmt = $pdo->prepare("SELECT value_numeric FROM facts WHERE fact_key = 'uk_base_rate' AND status = 'published' LIMIT 1");
    $stmt->execute();
    $storedRaw = $stmt->fetchColumn();
    $stored = $storedRaw !== false && $storedRaw !== null ? (float) $storedRaw : null;

    // The Bank's page (or a saved copy, for testing).
    $onPage = null;
    $nextDue = null;
    $readError = null;
    try {
        if (isset($options['from-file'])) {
            $text = file_get_contents((string) $options['from-file']);
            if ($text === false) {
                throw new RuntimeException("Can't read " . $options['from-file']);
            }
        } else {
            $text = fetchPageText(BANK_RATE_PAGE_URL);
        }
        $onPage = bankRateFromPage($text);
        $nextDue = bankRateNextDueFromPage($text);
    } catch (Throwable $e) {
        $readError = $e->getMessage();
    }

    echo 'Site shows: ' . ($stored !== null ? bankRateShort($stored) . '%' : 'not found') . "\n"
        . 'Bank page:  ' . ($onPage !== null ? bankRateShort($onPage) . '%' : 'unreadable' . ($readError ? " ($readError)" : '')) . "\n"
        . 'Next due:   ' . ($nextDue ?? 'not found') . "\n";

    $state = bankRateAssess($today, $time, $stored, $onPage, $nextDue, $simulating);
    echo "Result:     $state\n";

    // Which states email now, and which wait for the day's last run.
    $emailNow = ['changed', 'unchanged'];
    $emailFinalOnly = ['page_stale', 'unreadable'];
    $wantsEmail = in_array($state, $emailNow, true) || (in_array($state, $emailFinalOnly, true) && $finalRun);

    if (!$wantsEmail) {
        finishRun($pdo, $runId, false, in_array($state, $emailFinalOnly, true)
            ? "$state; will email on the last run of the day (from " . BANK_RATE_FINAL_RUN_HOUR . ':00 London time).'
            : "$state; no email needed.");
    }

    $sentKeys = $pdo->query("SELECT alert_key FROM admin_alerts_sent WHERE alert_key LIKE 'bankrate:%'")->fetchAll(PDO::FETCH_COLUMN);
    $key = "bankrate:$today:$state";

    // Once the "changed" email has gone, a later run that finds the site updated
    // (you imported the CSV) must not email "rate held".
    if ($state === 'unchanged' && in_array("bankrate:$today:changed", $sentKeys, true)) {
        finishRun($pdo, $runId, false, 'Site already updated after the change email; nothing more to send.');
    }
    if (in_array($key, $sentKeys, true)) {
        finishRun($pdo, $runId, false, "Already emailed ($state) for $today.");
    }

    // For a change, draft the CSV and save it beside the other imports.
    $csv = null;
    $csvFile = null;
    if ($state === 'changed') {
        $csv = bankRateDraftCsv($stored ?? $onPage, $onPage, $today);
        $relative = "data/imports/bank-rate-$today.csv";
        $path = __DIR__ . '/../' . $relative;
        if ($dryRun) {
            echo "Would save the draft as $relative.\n";
        } elseif (file_exists($path)) {
            echo "$relative already exists; not overwritten.\n";
            $csvFile = $relative;
        } elseif (@file_put_contents($path, $csv) !== false) {
            echo "Draft CSV saved as $relative.\n";
            $csvFile = $relative;
        } else {
            echo "Couldn't save $relative; the CSV is in the email instead.\n";
        }
    }

    $email = bankRateEmail($state, $today, $stored, $onPage, $csv, $csvFile);
    echo "\nEmail:\nSubject: {$email['subject']}\n\n{$email['body']}\n";

    if ($dryRun || $noEmail) {
        finishRun($pdo, $runId, false, "$state; email not sent (" . ($dryRun ? 'dry run' : '--no-email') . ').');
    }
    if (!alertEmailConfigured()) {
        finishRun($pdo, $runId, true, "$state; alerts are off: ALERT_EMAIL_TO isn't set in config/database.local.php.");
    }
    if (!sendAdminAlertEmail($email['subject'], $email['body'])) {
        finishRun($pdo, $runId, true, "$state; the email could not be sent (mail() failed). Will try again on the next run.");
    }

    $pdo->prepare('INSERT IGNORE INTO admin_alerts_sent (alert_key) VALUES (:k)')->execute(['k' => $key]);
    finishRun($pdo, $runId, false, "$state; email sent.");

} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    if ($runId) {
        $pdo->prepare("UPDATE cron_runs SET status = 'error', error = :e, finished_at = NOW() WHERE id = :id")
            ->execute(['e' => substr($e->getMessage(), 0, 1000), 'id' => $runId]);
    }
    exit(1);
}
