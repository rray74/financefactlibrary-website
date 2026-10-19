<?php
/**
 * Daily AI check of written pages, so the "AI check found problems" review
 * flag stays up to date without anyone clicking "Check with AI".
 *
 * Usage, from the project root:
 *   php scripts/check-page-text.php --dry-run    list the pages it would check, call nothing
 *   php scripts/check-page-text.php              check up to PAGE_TEXT_CHECKS_PER_RUN pages
 *   php scripts/check-page-text.php --limit=2    check up to 2 pages this run
 *
 * A written page (one with an explanation) is checked when:
 *   - its saved text has changed since its last check (or it's never been
 *     checked), or
 *   - one of its source pages has changed since its last check (the Monday
 *     source check records each page's fingerprint in sources.content_hash), or
 *   - its last check is PAGE_TEXT_CHECK_MAX_AGE_DAYS old, which catches
 *     things like a date in the text that has now passed.
 * Unchanged pages aren't checked again, which keeps the cost down.
 *
 * Each check is aiTextCheck() from includes/text-checks.php, the same as
 * the editor's button, run on the saved text. Results go into
 * subject_text_checks and show on the dashboard's "Needs reviewing" list
 * (includes/review-flags.php). Nothing on any page is changed.
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
require_once __DIR__ . '/../includes/text-checks.php';

/**
 * Pages checked per run. Each is up to two minutes (reading the sources
 * and the AI), so runs stay short. Override in config/database.local.php
 * with define('PAGE_TEXT_CHECKS_PER_RUN', 3);
 */
if (!defined('PAGE_TEXT_CHECKS_PER_RUN')) {
    define('PAGE_TEXT_CHECKS_PER_RUN', 5);
}

/** Recheck a page anyway once its last check is this old. */
const PAGE_TEXT_CHECK_MAX_AGE_DAYS = 90;

/** Why a page needs checking, most urgent first (used for ordering). */
const PAGE_TEXT_CHECK_REASONS = [
    'text_changed'   => 'text changed since its last check',
    'never_checked'  => 'never checked',
    'source_changed' => 'a source page has changed',
    'due'            => 'last check over ' . PAGE_TEXT_CHECK_MAX_AGE_DAYS . ' days ago',
];

$options = getopt('', ['dry-run', 'limit:']);
$dryRun = isset($options['dry-run']);
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : PAGE_TEXT_CHECKS_PER_RUN;

$pdo = getDbConnection();

// Each check waits on the AI with the connection idle, and Hostinger's
// MySQL drops idle connections quickly (see keepDbConnectionOpenForAi()).
keepDbConnectionOpenForAi($pdo);

$runId = null;
if (!$dryRun) {
    $pdo->prepare("INSERT INTO cron_runs (script) VALUES ('check-page-text.php')")->execute();
    $runId = (int) $pdo->lastInsertId();
}

$checked = 0;
$failed = 0;
$withFindings = 0;

try {
    if (!aiProposalsAvailable()) {
        throw new RuntimeException('No ANTHROPIC_API_KEY in config/database.local.php, so pages can\'t be checked.');
    }

    // Current fingerprints of every source page, from the Monday source check.
    $sourceHashes = $pdo->query('SELECT url, content_hash FROM sources WHERE content_hash IS NOT NULL')
        ->fetchAll(PDO::FETCH_KEY_PAIR);

    $pages = $pdo->query(
        "SELECT id, name, intro, explanation, meta_title, meta_description
         FROM subjects
         WHERE status IN ('published', 'draft') AND COALESCE(explanation, '') <> ''"
    )->fetchAll();

    // --- Which pages need checking, and why. ---
    $due = [];
    foreach ($pages as $page) {
        $fields = [
            'intro'            => $page['intro'],
            'explanation'      => $page['explanation'],
            'meta_title'       => $page['meta_title'],
            'meta_description' => $page['meta_description'],
        ];
        $check = getSubjectTextCheck((int) $page['id']);

        $reason = null;
        if (!$check) {
            $reason = 'never_checked';
        } elseif ($check['text_hash'] !== textCheckHash($fields)) {
            $reason = 'text_changed';
        } else {
            // A source counts as changed only if this check recorded its
            // fingerprint and the Monday check has since seen a different one.
            foreach ($check['sources'] as $source) {
                if (!empty($source['hash']) && isset($sourceHashes[$source['url']])
                    && $sourceHashes[$source['url']] !== $source['hash']) {
                    $reason = 'source_changed';
                    break;
                }
            }
            if ($reason === null && strtotime($check['checked_at']) < time() - PAGE_TEXT_CHECK_MAX_AGE_DAYS * 86400) {
                $reason = 'due';
            }
        }

        if ($reason !== null) {
            $search = getSearchStatsForPath(subjectUrlById((int) $page['id']));
            $due[] = $page + ['fields' => $fields, 'reason' => $reason,
                'impressions' => (int) ($search['impressions'] ?? 0)];
        }
    }

    // Most urgent reason first, then the most-searched page.
    $rank = array_flip(array_keys(PAGE_TEXT_CHECK_REASONS));
    usort($due, fn($a, $b) => [$rank[$a['reason']], $b['impressions'], $a['name']]
        <=> [$rank[$b['reason']], $a['impressions'], $b['name']]);
    $batch = array_slice($due, 0, $limit);

    echo ($dryRun ? "DRY RUN: no AI calls, nothing written.\n" : '')
        . count($pages) . ' written page(s); ' . count($due) . ' need checking; ' . count($batch) . " this run.\n\n";

    foreach ($batch as $page) {
        $label = sprintf('%-50s %s', $page['name'], PAGE_TEXT_CHECK_REASONS[$page['reason']]);

        if ($dryRun) {
            echo "  would check: $label\n";
            continue;
        }

        // One page failing doesn't stop the rest; a database failure does,
        // since every later page would fail the same way.
        try {
            $subject = getAdminSubject((int) $page['id']);
            $result = aiTextCheck($subject, getAdminFactsForSubject((int) $page['id']), $page['fields']);
            saveSubjectTextCheck($pdo, (int) $page['id'], $result, textCheckHash($page['fields']));
            $checked++;
            $found = count($result['findings']);
            if ($found) {
                $withFindings++;
            }
            echo "  checked: $label: " . ($found ? "$found problem(s)" : 'no problems') . "\n";
        } catch (PDOException $e) {
            $failed++;
            echo "  FAILED: $label: " . $e->getMessage() . "\n";
            throw new RuntimeException('Stopped: database error (' . $e->getMessage() . ')', 0, $e);
        } catch (Throwable $e) {
            $failed++;
            echo "  FAILED: $label: " . $e->getMessage() . "\n";
        }
    }

    $remaining = count($due) - $checked;
    $summary = "$checked checked ($withFindings with problems), $failed failed, $remaining still to check";
    echo "\nDone. $summary.\n";

    if ($runId) {
        // A run where every page failed is an error, so the dashboard shows it.
        $pdo->prepare(
            'UPDATE cron_runs SET status = :status, jobs_processed = :done, jobs_failed = :failed,
             error = :note, finished_at = NOW() WHERE id = :id'
        )->execute([
            'status' => ($failed > 0 && $checked === 0) ? 'error' : 'ok',
            'done'   => $checked,
            'failed' => $failed,
            'note'   => $summary,
            'id'     => $runId,
        ]);
    }
    exit($failed > 0 && $checked === 0 ? 1 : 0);

} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    if ($runId) {
        // Recording the failure can itself fail if the connection is gone;
        // say so in the cron output rather than ending with a PHP fatal error.
        try {
            $pdo->prepare("UPDATE cron_runs SET status = 'error', error = :e, finished_at = NOW() WHERE id = :id")
                ->execute(['e' => substr("$checked checked before stopping. " . $e->getMessage(), 0, 1000), 'id' => $runId]);
        } catch (Throwable $recordError) {
            fwrite(STDERR, 'Could not record the failure in cron_runs: ' . $recordError->getMessage() . "\n");
        }
    }
    exit(1);
}
