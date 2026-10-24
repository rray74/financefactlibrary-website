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
 * Pages drafted per run. Each draft now reads the page's source pages as
 * well as calling the AI, so a page takes a couple of minutes and a run is
 * kept shorter than it used to be. Override in config/database.local.php
 * with define('DRAFTS_PER_NIGHT', 3);
 */
if (!defined('DRAFTS_PER_NIGHT')) {
    define('DRAFTS_PER_NIGHT', 5);
}

/**
 * Pause switch. With define('DRAFTS_PAUSED', true); in
 * config/database.local.php, a scheduled run makes no drafts, but still
 * records itself as a successful run ("Paused"), so the cron job can stay
 * in place and the dashboard doesn't report it as stopped. Remove the line
 * to resume. A run started by hand with --force ignores the pause, and
 * --dry-run is never affected.
 */
$options = getopt('', ['dry-run', 'limit:', 'force']);
$dryRun = isset($options['dry-run']);
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : DRAFTS_PER_NIGHT;
$paused = defined('DRAFTS_PAUSED') && DRAFTS_PAUSED && !isset($options['force']) && !$dryRun;

$pdo = getDbConnection();

// Each page waits about a minute for the AI with the connection idle, and
// Hostinger's MySQL drops idle connections sooner than that.
keepDbConnectionOpenForAi($pdo);

$runId = null;
if (!$dryRun) {
    $pdo->prepare("INSERT INTO cron_runs (script) VALUES ('draft-pages.php')")->execute();
    $runId = (int) $pdo->lastInsertId();
}

// Paused: record a clean run and stop, before any AI call or page is touched.
if ($paused) {
    echo "Paused (DRAFTS_PAUSED is on in config/database.local.php). No drafts made.\n";
    if ($runId) {
        $pdo->prepare("UPDATE cron_runs SET status = 'ok', jobs_processed = 0, jobs_failed = 0, error = :note, finished_at = NOW() WHERE id = :id")
            ->execute(['note' => 'Paused: no drafts made (DRAFTS_PAUSED is on)', 'id' => $runId]);
    }
    exit(0);
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

        // One page failing (a timeout, an unreadable reply) doesn't stop the
        // rest. A database failure does, since every later page would fail
        // the same way (the first live run failed 10 pages on one dropped
        // connection).
        try {
            $subject = getAdminSubject((int) $page['id']);
            // Overnight there is time for two automatic rewrite rounds.
            $draft = draftSubjectPage(
                $pdo, $subject, getAdminFactsForSubject((int) $page['id']),
                DRAFT_REVISIONS_NIGHTLY, DRAFT_REVISION_TIME_BUDGET_NIGHTLY
            );
            saveSubjectDraft($pdo, (int) $page['id'], $draft, 'nightly');
            $drafted++;
            echo "  drafted: $label" . ($draft['problems'] ? ' (' . count($draft['problems']) . ' thing(s) to check)' : '') . "\n";
            if (trim($draft['rewrite_note'], ' |') !== '') {
                echo '    ' . ltrim($draft['rewrite_note'], ' |') . "\n";
            }
        } catch (PDOException $e) {
            $failed++;
            echo "  FAILED: $label: " . $e->getMessage() . "\n";
            throw new RuntimeException('Stopped: database error (' . $e->getMessage() . ')', 0, $e);
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
        $note = "$drafted drafted before stopping. " . $e->getMessage();
        // Recording the failure can itself fail if the connection is gone.
        // Try once more on the same connection, and if that fails too, say
        // so in the cron output rather than ending with a PHP fatal error.
        try {
            $pdo->prepare("UPDATE cron_runs SET status = 'error', error = :e, finished_at = NOW() WHERE id = :id")
                ->execute(['e' => substr($note, 0, 1000), 'id' => $runId]);
        } catch (Throwable $recordError) {
            fwrite(STDERR, 'Could not record the failure in cron_runs: ' . $recordError->getMessage() . "\n");
        }
    }
    exit(1);
}
