<?php
/**
 * Nightly AI drafts for pages that haven't been written yet.
 *
 * Usage, from the project root:
 *   php scripts/draft-pages.php --dry-run      list the pages it would draft, call nothing
 *   php scripts/draft-pages.php                draft up to DRAFTS_PER_NIGHT pages
 *   php scripts/draft-pages.php --limit=3      draft up to 3 pages this run
 *
 * Run daily by cron. Each run takes the writing queue (published and
 * draft pages with no explanation, most-searched first, the same order as
 * the dashboard), skips pages that already have a draft or have no
 * published figures, and drafts all four text fields of the next few
 * through draftSubjectPage() in includes/ai-drafts.php.
 *
 * Drafts go into subject_drafts (migration 012). They never change a live
 * page: you use them from the editor. Each page is one AI call, roughly a
 * minute, at well under 5p.
 *
 * Needs ANTHROPIC_API_KEY in config/database.local.php, like the other AI
 * features.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fact-writer.php';
require_once __DIR__ . '/../includes/admin-queries.php';
require_once __DIR__ . '/../includes/ai-drafts.php';

/**
 * Pages drafted per run. With about 50 pages waiting, the backlog is
 * cleared in about a week; after that only new pages need drafts.
 * Override in config/database.local.php with define('DRAFTS_PER_NIGHT', 5);
 */
if (!defined('DRAFTS_PER_NIGHT')) {
    define('DRAFTS_PER_NIGHT', 10);
}

$options = getopt('', ['dry-run', 'limit:']);
$dryRun = isset($options['dry-run']);
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : DRAFTS_PER_NIGHT;

$pdo = getDbConnection();

$runId = null;
if (!$dryRun) {
    $pdo->prepare("INSERT INTO cron_runs (script) VALUES ('draft-pages.php')")->execute();
    $runId = (int) $pdo->lastInsertId();
}

$drafted = 0;
$failed = 0;

try {
    if (!aiProposalsAvailable()) {
        throw new RuntimeException('No ANTHROPIC_API_KEY in config/database.local.php, so drafts can\'t be made.');
    }

    // Pages that still need a draft: no draft yet, and something to write from.
    $queue = array_values(array_filter(getWritingQueue(),
        fn($page) => !$page['has_draft'] && (int) $page['published_facts'] > 0));
    $batch = array_slice($queue, 0, $limit);

    echo ($dryRun ? "DRY RUN: no AI calls, nothing written.\n" : '')
        . count($queue) . ' page(s) waiting for a draft; ' . count($batch) . " this run.\n\n";

    foreach ($batch as $page) {
        $label = sprintf('%-50s %5d impressions  %s', $page['name'], $page['impressions'], $page['status']);

        if ($dryRun) {
            echo "  would draft: $label\n";
            continue;
        }

        // One page failing (a timeout, an unreadable reply) doesn't stop the rest.
        try {
            $subject = getAdminSubject((int) $page['id']);
            $draft = draftSubjectPage($pdo, $subject, getAdminFactsForSubject((int) $page['id']));
            saveSubjectDraft($pdo, (int) $page['id'], $draft, 'nightly');
            $drafted++;
            echo "  drafted: $label" . ($draft['problems'] ? ' (' . count($draft['problems']) . ' thing(s) to check)' : '') . "\n";
        } catch (Throwable $e) {
            $failed++;
            echo "  FAILED: $label: " . $e->getMessage() . "\n";
        }
    }

    $remaining = count($queue) - $drafted;
    $summary = "$drafted drafted, $failed failed, $remaining still waiting";
    echo "\nDone. $summary.\n";

    if ($runId) {
        // A run where every page failed is an error, so the dashboard shows it.
        $pdo->prepare(
            'UPDATE cron_runs SET status = :status, jobs_processed = :done, jobs_failed = :failed,
             error = :note, finished_at = NOW() WHERE id = :id'
        )->execute([
            'status' => ($failed > 0 && $drafted === 0) ? 'error' : 'ok',
            'done'   => $drafted,
            'failed' => $failed,
            'note'   => $summary,
            'id'     => $runId,
        ]);
    }
    exit($failed > 0 && $drafted === 0 ? 1 : 0);

} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    if ($runId) {
        $pdo->prepare("UPDATE cron_runs SET status = 'error', error = :e, finished_at = NOW() WHERE id = :id")
            ->execute(['e' => substr($e->getMessage(), 0, 1000), 'id' => $runId]);
    }
    exit(1);
}
