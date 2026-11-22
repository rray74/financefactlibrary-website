<?php
/**
 * Mark pages' text as reviewed, from the command line.
 *
 * WHY IT EXISTS. subjects.text_reviewed_at is normally stamped when a person saves the page in the
 * admin editor (markSubjectTextReviewed() in includes/fact-writer.php). Text that goes in through
 * scripts/import-page-text.php is deliberately NOT stamped, because importing is not reviewing. When
 * a person HAS reviewed and approved text that arrived by import (the Claude Code drafting test,
 * 22 Nov 2026: Marriage Allowance, Statutory Sick Pay and Auto-Enrolment), the only way to record it
 * was to open each page in the editor and save it. This script does the same thing for a named page,
 * using the editor's own function, so the stamp means exactly what the editor's stamp means.
 *
 * It is a record of a human's approval, so it is never run by a cron job and never by default:
 * every page must be named, and the person running it is saying "I have reviewed these".
 *
 * Usage, from the project root:
 *   php scripts/mark-page-reviewed.php --dry-run --page=marriage-allowance,statutory-sick-pay
 *   php scripts/mark-page-reviewed.php --page=marriage-allowance,statutory-sick-pay
 * Always run it with --dry-run first.
 *
 * It refuses a page that has no explanation yet (nothing to have reviewed) and a slug that does not
 * exist. It changes only text_reviewed_at, and no text, figure or status. A page already stamped is
 * stamped again with the current time, as saving it in the editor again would.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

// No AI is used, but the shared code wants a purpose to be named. It is never used to call anything.
define('AI_KEY_PURPOSE', 'SOURCES');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fact-writer.php';

$options = getopt('', ['page:', 'dry-run']);
$dryRun = isset($options['dry-run']);
$slugs = array_values(array_filter(array_map('trim', explode(',', (string) ($options['page'] ?? '')))));
if (!$slugs) {
    fwrite(STDERR, "Usage: php scripts/mark-page-reviewed.php [--dry-run] --page=slug[,slug...]\n");
    exit(2);
}

$pdo = getDbConnection();
$find = $pdo->prepare('SELECT id, name, explanation, text_reviewed_at FROM subjects WHERE slug = :slug');

if ($dryRun) {
    echo "DRY RUN: nothing will be written.\n";
}
$errors = 0;
foreach ($slugs as $slug) {
    $find->execute(['slug' => $slug]);
    $page = $find->fetch(PDO::FETCH_ASSOC);
    if (!$page) {
        echo "$slug: no page with that slug\n";
        $errors++;
        continue;
    }
    if (!preg_match('/[^\s]/u', (string) $page['explanation'])) {
        echo "$slug: has no explanation yet, so there is nothing to mark as reviewed\n";
        $errors++;
        continue;
    }
    $was = $page['text_reviewed_at'] ?: 'never';
    if (!$dryRun) {
        markSubjectTextReviewed($pdo, (int) $page['id']);   // the editor's own stamp (NOW())
    }
    echo "$slug: " . ($dryRun ? 'WOULD mark' : 'marked') . " as reviewed (was: $was)\n";
}
exit($errors ? 1 : 0);
