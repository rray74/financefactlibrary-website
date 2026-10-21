<?php
/**
 * Looks for lessons in pages you have saved since an AI draft, so the
 * lessons page has proposals waiting without anyone clicking "Look for
 * lessons". OPTIONAL: the same thing is available by hand on
 * /admin/lessons.php, and nothing expects this script to be scheduled.
 *
 * Usage, from the project root:
 *   php scripts/learn-from-edits.php --dry-run    show how many records are waiting; no AI calls
 *   php scripts/learn-from-edits.php              look at up to LEARN_RECORDS_PER_RUN records
 *   php scripts/learn-from-edits.php --limit=1    look at up to 1 this run
 *
 * To schedule it, add a daily cron job (for example 08:30, after the other
 * jobs). It only ever PROPOSES lessons; they do nothing until you approve
 * them on /admin/lessons.php (see includes/lessons.php). It never changes a
 * page.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin-queries.php';
require_once __DIR__ . '/../includes/lessons.php';

/** Records looked at per run; each is one AI call of about a minute. */
if (!defined('LEARN_RECORDS_PER_RUN')) {
    define('LEARN_RECORDS_PER_RUN', 5);
}

$options = getopt('', ['dry-run', 'limit:']);
$dryRun = isset($options['dry-run']);
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : LEARN_RECORDS_PER_RUN;

$pdo = getDbConnection();
$waiting = countPendingEditRecords($pdo);

if ($dryRun) {
    echo "DRY RUN: no AI calls, nothing written.\n$waiting saved edit(s) not looked at yet; up to $limit would be this run.\n";
    exit(0);
}

$runId = null;
$pdo->prepare("INSERT INTO cron_runs (script) VALUES ('learn-from-edits.php')")->execute();
$runId = (int) $pdo->lastInsertId();

try {
    if ($waiting > 0 && !aiProposalsAvailable()) {
        throw new RuntimeException('No ANTHROPIC_API_KEY in config/database.local.php, so lessons can\'t be looked for.');
    }

    $result = $waiting > 0
        ? processPendingEditRecords($pdo, $limit)
        : ['looked_at' => 0, 'proposed' => 0, 'notes' => [], 'errors' => []];

    foreach ($result['notes'] as $note) {
        echo "  $note\n";
    }
    foreach ($result['errors'] as $error) {
        echo "  FAILED: $error\n";
    }

    $summary = "{$result['looked_at']} edit(s) looked at, {$result['proposed']} lesson(s) proposed, "
        . count($result['errors']) . ' failed, ' . max(0, $waiting - $result['looked_at']) . ' still waiting';
    echo "\nDone. $summary.\n";

    $failed = count($result['errors']);
    $pdo->prepare(
        'UPDATE cron_runs SET status = :status, jobs_processed = :done, jobs_failed = :failed,
         error = :note, finished_at = NOW() WHERE id = :id'
    )->execute([
        'status' => ($failed > 0 && $result['looked_at'] === 0) ? 'error' : 'ok',
        'done'   => $result['looked_at'],
        'failed' => $failed,
        'note'   => substr($summary, 0, 1000),
        'id'     => $runId,
    ]);
    exit($failed > 0 && $result['looked_at'] === 0 ? 1 : 0);

} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    // Recording the failure can itself fail if the connection is gone.
    try {
        $pdo->prepare("UPDATE cron_runs SET status = 'error', error = :e, finished_at = NOW() WHERE id = :id")
            ->execute(['e' => substr($e->getMessage(), 0, 1000), 'id' => $runId]);
    } catch (Throwable $recordError) {
        fwrite(STDERR, 'Could not record the failure in cron_runs: ' . $recordError->getMessage() . "\n");
    }
    exit(1);
}
