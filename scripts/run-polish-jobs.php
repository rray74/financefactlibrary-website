<?php
/**
 * Work through the polish queue: draft a page, polish it with the AI check
 * and rewrite loop, store the result as the page's AI draft, and email you.
 *
 * Usage, from the project root:
 *   php scripts/run-polish-jobs.php
 *
 * Run EVERY MINUTE by cron (see below). Each run takes at most one job: the
 * oldest page with status 'queued' in polish_jobs (migration 020), added by
 * the editor's "Draft and polish in the background" button. Most runs find
 * nothing and exit at once. A job takes about ten minutes; if the next
 * minute's run starts while it is still going, it finds nothing 'queued'
 * for that page and works on the next page, or exits. Two jobs can therefore
 * overlap, which is fine (each is its own page).
 *
 * The cron entry, in Hostinger's hPanel (Advanced > Cron Jobs), custom, every
 * minute:
 *   cd ~/domains/financefactlibrary.com/app && /usr/bin/php scripts/run-polish-jobs.php >/dev/null 2>&1
 *
 * Not recorded in cron_runs: it runs every minute and nearly always finds
 * nothing, which would flood the dashboard's run list. A failed job shows on
 * its page in the editor and is emailed.
 *
 * Needs ANTHROPIC_API_KEY in config/database.local.php, like the other AI
 * features.
 *
 * DRAFTS_PAUSED does NOT stop this script: it pauses the unattended nightly
 * drafts, whereas a job in this queue exists because someone clicked the
 * button. To stop the queue, remove the cron entry.
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
require_once __DIR__ . '/../includes/ai-polish.php';
require_once __DIR__ . '/../includes/alerts.php';

if (!aiProposalsAvailable()) {
    fwrite(STDERR, "No ANTHROPIC_API_KEY in config/database.local.php, so nothing can be polished.\n");
    exit(1);
}

$pdo = getDbConnection();
// A job waits minutes for the AI with the connection idle.
keepDbConnectionOpenForAi($pdo, 3600);

$ran = runNextPolishJob($pdo, function (array $job) use ($pdo) {
    $subjectId = (int) $job['subject_id'];
    $subject = getAdminSubject($subjectId);
    if (!$subject) {
        throw new RuntimeException('That page no longer exists.');
    }
    $facts = getAdminFactsForSubject($subjectId);
    if (!array_filter($facts, fn($f) => $f['status'] === 'published')) {
        throw new RuntimeException('This page has no published figures yet, so there is nothing to write from.');
    }

    $result = polishPageEndToEnd($pdo, $subject, $facts, 'editor');
    return $result['polish'] ? polishNote($result['polish']) : 'Drafted (polishing is switched off).';
});

if ($ran === null) {
    exit(0); // nothing waiting
}

$subject = getAdminSubject((int) $ran['job']['subject_id']);
$name = $subject['name'] ?? ('page ' . $ran['job']['subject_id']);
echo date('c') . " {$ran['status']}: $name. {$ran['note']}\n";

// Tell the admin. Failure to send is not a problem for the job.
$link = alertSiteUrl() . '/admin/subject.php?id=' . (int) $ran['job']['subject_id'];
if ($ran['status'] === 'done') {
    sendAdminAlertEmail("Polished draft ready: $name", "The polished draft for \"$name\" is ready.\n\n{$ran['note']}\n\nOpen the page, read the draft under each field, and use it:\n$link\n");
} else {
    sendAdminAlertEmail("Polish failed: $name", "The draft and polish for \"$name\" did not finish.\n\n{$ran['note']}\n\nYou can queue it again from the page:\n$link\n");
}
exit($ran['status'] === 'done' ? 0 : 1);
