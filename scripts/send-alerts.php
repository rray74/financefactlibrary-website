<?php
/**
 * Emails the site owner about anything new that needs attention.
 *
 * Usage, from the project root:
 *   php scripts/send-alerts.php --dry-run   show what it would send; send and record nothing
 *   php scripts/send-alerts.php --test      send a test email now, to check delivery
 *   php scripts/send-alerts.php             email anything new since the last email
 *
 * Run daily by cron at 08:00, after the other jobs. What it watches, and
 * how it avoids repeating itself, is explained in includes/alerts.php.
 * Needs ALERT_EMAIL_TO in config/database.local.php; without it the script
 * does nothing except say so in its result on the dashboard.
 *
 * It never changes any content.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin-queries.php';
require_once __DIR__ . '/../includes/alerts.php';

$options = getopt('', ['dry-run', 'test']);
$dryRun = isset($options['dry-run']);
$test = isset($options['test']);

// --- Test email: no state is read or written. ---
if ($test) {
    if (!alertEmailConfigured()) {
        fwrite(STDERR, "ALERT_EMAIL_TO isn't set (or isn't a valid address) in config/database.local.php.\n");
        exit(1);
    }
    $sent = sendAdminAlertEmail(
        '[Finance Fact Library] Test alert',
        "This is a test from scripts/send-alerts.php.\n\nIf you can read this, alert emails reach you.\n"
        . 'Sent ' . date('D j M Y, H:i') . ".\n"
    );
    echo $sent
        ? 'Test email handed to the mail system for ' . implode(', ', alertRecipients()) . ". Check the inbox and the spam folder.\n"
        : "The test email could not be sent (mail() failed).\n";
    exit($sent ? 0 : 1);
}

$pdo = getDbConnection();

$runId = null;
if (!$dryRun) {
    $pdo->prepare("INSERT INTO cron_runs (script) VALUES ('send-alerts.php')")->execute();
    $runId = (int) $pdo->lastInsertId();
}

try {
    $result = processAdminAlerts($pdo, $dryRun);

    echo ($dryRun ? "DRY RUN: nothing is sent or recorded.\n" : '')
        . "{$result['waiting']} thing(s) need attention; {$result['new']} new, {$result['cleared']} cleared since the last email.\n";

    if ($result['email']) {
        echo "\n" . ($dryRun ? 'This email would be sent' : 'Email') . ":\nSubject: {$result['email']['subject']}\n\n{$result['email']['body']}\n";
    }

    // One line for the dashboard's Scheduled jobs table.
    if ($result['error']) {
        $summary = $result['error'];
    } elseif ($result['emailed']) {
        $summary = "{$result['new']} new emailed, {$result['waiting']} waiting in all";
    } else {
        $summary = $result['new'] === 0
            ? "Nothing new ({$result['waiting']} waiting in all)"
            : "{$result['new']} new (dry run)";
    }
    echo $result['error'] ? "\n{$result['error']}\n" : "\nDone. $summary.\n";

    // "Alerts are off" is a setting, not a failure; a failed send is a failure.
    $failed = $result['configured'] && $result['error'] !== null;

    if ($runId) {
        $pdo->prepare(
            'UPDATE cron_runs SET status = :status, jobs_processed = :done, jobs_failed = :failed,
             error = :note, finished_at = NOW() WHERE id = :id'
        )->execute([
            'status' => $failed ? 'error' : 'ok',
            'done'   => $result['emailed'] ? $result['new'] : 0,
            'failed' => $failed ? 1 : 0,
            'note'   => substr($summary, 0, 1000),
            'id'     => $runId,
        ]);
    }
    exit($failed ? 1 : 0);

} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    if ($runId) {
        $pdo->prepare("UPDATE cron_runs SET status = 'error', error = :e, finished_at = NOW() WHERE id = :id")
            ->execute(['e' => substr($e->getMessage(), 0, 1000), 'id' => $runId]);
    }
    exit(1);
}
