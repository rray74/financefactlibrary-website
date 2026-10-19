<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/admin-auth.php';
require_once __DIR__ . '/../../includes/admin-queries.php';
require_once __DIR__ . '/../../includes/review-flags.php'; // the "Needs reviewing" list

// Must be first: sends anyone not logged in to /admin/login.php.
requireAdmin();

/*
 * Admin home page (/admin/). Read-only overview:
 *   - what's waiting for a decision (held changes, failed source checks,
 *     facts due a review), each linking to the review page
 *   - whether the weekly cron jobs ran, and how they went
 *   - headline counts, including content gaps (short intros, pages
 *     without an explanation)
 *   - pages needing a review, and why (includes/review-flags.php)
 *   - the writing queue: pages without an explanation, most-searched
 *     first, and whether an AI draft is waiting (scripts/draft-pages.php)
 *   - Google search performance for the last 28 days (Search Console)
 *   - the latest figure changes across the site
 *
 * Everything here is worked out live from the database on each load.
 */

// Waiting items: the same lists the review page shows, so the numbers
// always match what you find when you click through.
$waiting = [
    ['Held for review', count(getHeldChanges()), 'Changes the automated checks didn\'t publish'],
    ['Needs a look', count(getFactsNeedingAttention()), 'Figures not found on their source page'],
    ['Due for review', count(getFactsDueForReview()), 'Past their own review window'],
];
$waitingTotal = array_sum(array_column($waiting, 1));

$counts = getDashboardCounts();

// Written pages that need another look, most important first.
$reviewFlagged = getReviewFlags();

// Pages still to be written, in the order the nightly drafts follow.
// Only the top of the list is shown; the count covers all of it.
$writingQueue = getWritingQueue();
$writingQueueDrafted = count(array_filter($writingQueue, fn($page) => $page['has_draft']));
const WRITING_QUEUE_SHOWN = 15;
$recentChanges = getRecentFactChanges(10);

// Search Console: the latest 28 days, fetched daily by
// scripts/fetch-search-console.php. NULL until the first run.
$searchTotals = getSearchTotals();
$searchPages = $searchTotals ? getTopSearchPages(10) : [];

// Page paths to subject names, so the table shows names where it can.
$subjectNamesByPath = [];
foreach (getAdminSubjectList() as $listedSubject) {
    $subjectNamesByPath[subjectUrlById((int) $listedSubject['id'])] = $listedSubject;
}

// Cron runs: one row per expected script, plus any other script that has
// logged runs, so a new cron job shows up even before it's added to
// ADMIN_EXPECTED_CRON_SCRIPTS.
$latestRuns = getLatestCronRuns();
$cronScripts = array_unique(array_merge(array_keys(ADMIN_EXPECTED_CRON_SCRIPTS), array_keys($latestRuns)));

/**
 * Describe one script's latest run for the table: when it started, and a
 * status of ok / error / running / stale / never, with a colour.
 */
function describeCronRun(?array $run): array
{
    if ($run === null) {
        return ['started' => null, 'state' => 'never', 'label' => 'No runs recorded', 'bad' => true];
    }

    // The start-time column's name isn't fixed across environments, so
    // use whichever exists.
    $startedRaw = $run['started_at'] ?? $run['created_at'] ?? null;
    $started = $startedRaw ? strtotime($startedRaw) : null;
    $finished = !empty($run['finished_at']);
    $ageDays = $started ? (time() - $started) / 86400 : null;

    if (!$finished) {
        // Still marked as running more than an hour after starting means
        // it died without recording an outcome.
        $stuck = $started !== null && time() - $started > 3600;
        return ['started' => $started, 'state' => $stuck ? 'stuck' : 'running',
                'label' => $stuck ? 'Didn\'t finish' : 'Running now', 'bad' => $stuck];
    }
    if ($ageDays !== null && $ageDays > ADMIN_CRON_STALE_DAYS) {
        // Weekly jobs: a last run over 8 days ago means the schedule stopped.
        return ['started' => $started, 'state' => 'stale',
                'label' => 'Last run ' . floor($ageDays) . ' days ago', 'bad' => true];
    }
    $ok = ($run['status'] ?? '') === 'ok';
    return ['started' => $started, 'state' => $ok ? 'ok' : 'error',
            'label' => $ok ? 'OK' : 'Problem', 'bad' => !$ok];
}

$pageTitle = 'Dashboard';
$noindex = true; // admin page, keep it out of search results
$adminSection = 'dashboard';

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/admin-nav.php';
?>

<section class="mb-10">
    <h1 class="font-display text-3xl font-semibold mb-2">Dashboard</h1>
    <p class="opacity-80 max-w-xl">
        <?= $waitingTotal === 0
            ? 'Nothing is waiting for you.'
            : $waitingTotal . ' item' . ($waitingTotal === 1 ? '' : 's') . ' waiting on the review page.' ?>
    </p>
</section>

<?php // ---------- Waiting for a decision ---------- ?>
<section class="mb-12">
    <div class="grid sm:grid-cols-3 gap-5">
        <?php foreach ($waiting as [$title, $count, $help]): ?>
        <a href="/admin/review.php" class="fact-card p-5 block hover:border-accent">
            <p class="text-sm opacity-70"><?= e($title) ?></p>
            <?php // Red when something is waiting, so it stands out. ?>
            <p class="font-display text-3xl font-semibold my-1 <?= $count > 0 ? 'text-error' : '' ?>"><?= (int) $count ?></p>
            <p class="text-xs opacity-60"><?= e($help) ?></p>
        </a>
        <?php endforeach; ?>
    </div>
</section>

<?php // ---------- Scheduled jobs ---------- ?>
<section class="mb-12">
    <h2 class="font-display text-2xl font-semibold mb-2">Scheduled jobs</h2>
    <p class="opacity-80 max-w-xl mb-4 text-sm">The latest run of each cron script.</p>
    <div class="overflow-x-auto">
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="border-b-2 border-primary text-left font-mono text-xs uppercase tracking-wide">
                    <th class="py-2 pr-4">Script</th>
                    <th class="py-2 pr-4">Last started</th>
                    <th class="py-2 pr-4">Status</th>
                    <th class="py-2 pr-4">Result</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cronScripts as $script): ?>
                <?php
                $run = $latestRuns[$script] ?? null;
                $state = describeCronRun($run);
                ?>
                <tr class="border-b border-primary/20 align-top">
                    <td class="py-3 pr-4">
                        <span class="font-mono"><?= e($script) ?></span>
                        <?php if (array_key_exists($script, ADMIN_EXPECTED_CRON_SCRIPTS)): ?>
                        <span class="block text-xs opacity-60"><?= e(ADMIN_EXPECTED_CRON_SCRIPTS[$script]) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 pr-4 opacity-80">
                        <?= $state['started'] ? date('D j M Y, H:i', $state['started']) : '—' ?>
                    </td>
                    <td class="py-3 pr-4">
                        <span class="font-mono text-xs px-2 py-1 rounded <?= $state['bad'] ? 'bg-error/10 text-error' : 'bg-secondary/10 text-secondary' ?>">
                            <?= e($state['label']) ?>
                        </span>
                    </td>
                    <td class="py-3 pr-4 text-xs opacity-80">
                        <?php // The scripts store their summary (or the error) in the error column. ?>
                        <?= e((string) ($run['error'] ?? '')) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php // ---------- Content ---------- ?>
<section class="mb-12">
    <h2 class="font-display text-2xl font-semibold mb-4">Content</h2>
    <div class="grid sm:grid-cols-3 gap-5">
        <div class="fact-card p-5">
            <p class="text-sm opacity-70">Published pages</p>
            <p class="font-display text-3xl font-semibold my-1"><?= (int) $counts['published_subjects'] ?></p>
            <p class="text-xs opacity-60"><?= (int) $counts['draft_subjects'] ?> draft</p>
        </div>
        <div class="fact-card p-5">
            <p class="text-sm opacity-70">Published figures</p>
            <p class="font-display text-3xl font-semibold my-1"><?= (int) $counts['published_facts'] ?></p>
            <p class="text-xs opacity-60"><?= (int) $counts['draft_facts'] ?> draft &middot; <?= (int) $counts['sources_used'] ?> source pages</p>
        </div>
        <?php // Jumps to the writing queue below. ?>
        <a href="#writing-queue" class="fact-card p-5 block hover:border-accent">
            <p class="text-sm opacity-70">Pages needing writing</p>
            <?php // Content gaps on published pages: the plan is to expand intros and add explanations. ?>
            <p class="font-display text-3xl font-semibold my-1"><?= (int) $counts['no_explanation'] ?></p>
            <p class="text-xs opacity-60">
                without an explanation &middot; <?= (int) $counts['short_intros'] ?> with a short intro
            </p>
        </a>
    </div>
</section>

<?php // ---------- Needs reviewing ---------- ?>
<section id="needs-reviewing" class="mb-12 scroll-mt-6">
    <h2 class="font-display text-2xl font-semibold mb-2">Needs reviewing</h2>
    <?php if (!$reviewFlagged): ?>
    <p class="opacity-80 max-w-xl text-sm">No written page needs reviewing.</p>
    <?php else: ?>
    <p class="opacity-80 max-w-xl mb-4 text-sm">
        <?= count($reviewFlagged) ?> written page<?= count($reviewFlagged) === 1 ? '' : 's' ?> to look at again,
        most important first. Each page's editor shows the same reasons at the top.
    </p>
    <div class="overflow-x-auto">
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="border-b-2 border-primary text-left font-mono text-xs uppercase tracking-wide">
                    <th class="py-2 pr-4">Page</th>
                    <th class="py-2 pr-4">Why</th>
                    <th class="py-2 pr-4 text-right">Impressions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reviewFlagged as $page): ?>
                <tr class="border-b border-primary/20 align-top">
                    <td class="py-3 pr-4">
                        <a href="/admin/subject.php?id=<?= (int) $page['id'] ?>#subject-details" class="font-semibold hover:text-accent">
                            <?= e($page['name']) ?>
                        </a>
                    </td>
                    <td class="py-3 pr-4 text-xs">
                        <?php // One line per reason; the details are on the page's editor. ?>
                        <?php foreach ($page['reasons'] as $reason): ?>
                        <span class="block"><?= e(REVIEW_FLAG_LABELS[$reason['kind']]) ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td class="py-3 pr-4 text-right"><?= number_format((int) $page['impressions']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<?php // ---------- Writing queue ---------- ?>
<section id="writing-queue" class="mb-12 scroll-mt-6">
    <h2 class="font-display text-2xl font-semibold mb-2">Writing queue</h2>
    <p class="opacity-80 max-w-xl mb-4 text-sm">
        <?php if (!$writingQueue): ?>
        Every page has an explanation.
        <?php else: ?>
        <?= count($writingQueue) ?> page<?= count($writingQueue) === 1 ? '' : 's' ?> without an explanation,
        <?= $writingQueueDrafted ?> with an AI draft waiting. Most-searched first, the order the nightly
        drafts follow. Start at the top.
        <?php endif; ?>
    </p>
    <?php if ($writingQueue): ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="border-b-2 border-primary text-left font-mono text-xs uppercase tracking-wide">
                    <th class="py-2 pr-4">Page</th>
                    <th class="py-2 pr-4">Status</th>
                    <th class="py-2 pr-4 text-right">Impressions</th>
                    <th class="py-2 pr-4">AI draft</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($writingQueue, 0, WRITING_QUEUE_SHOWN) as $page): ?>
                <tr class="border-b border-primary/20 align-top">
                    <td class="py-3 pr-4">
                        <a href="/admin/subject.php?id=<?= (int) $page['id'] ?>#subject-details" class="font-semibold hover:text-accent">
                            <?= e($page['name']) ?>
                        </a>
                    </td>
                    <td class="py-3 pr-4 font-mono text-xs"><?= e($page['status']) ?></td>
                    <td class="py-3 pr-4 text-right"><?= number_format((int) $page['impressions']) ?></td>
                    <td class="py-3 pr-4 text-xs">
                        <?php if ($page['has_draft']): ?>
                        <span class="font-mono px-2 py-1 rounded bg-secondary/10 text-secondary">
                            Ready <?= e(date('j M', strtotime($page['draft_created_at']))) ?>
                        </span>
                        <?php elseif ((int) $page['published_facts'] === 0): ?>
                        <?php // Drafts are written from the page's figures, so it needs some first. ?>
                        <span class="opacity-60">Needs published figures first</span>
                        <?php else: ?>
                        <span class="opacity-60">Waiting</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($writingQueue) > WRITING_QUEUE_SHOWN): ?>
    <p class="text-xs opacity-60 mt-2">And <?= count($writingQueue) - WRITING_QUEUE_SHOWN ?> more.</p>
    <?php endif; ?>
    <?php endif; ?>
</section>

<?php // ---------- Search (Google Search Console) ---------- ?>
<section class="mb-12">
    <h2 class="font-display text-2xl font-semibold mb-2">Google search</h2>
    <?php if (!$searchTotals): ?>
    <p class="fact-card p-6 text-secondary">
        No Search Console data yet. It appears after the first run of
        <span class="font-mono">scripts/fetch-search-console.php</span>.
    </p>
    <?php else: ?>
    <p class="opacity-80 max-w-xl mb-4 text-sm">
        <?= date('j M', strtotime($searchTotals['period_start'])) ?> to
        <?= date('j M Y', strtotime($searchTotals['period_end'])) ?>
        (Search Console runs about 3 days behind).
    </p>
    <div class="grid sm:grid-cols-3 gap-5 mb-6">
        <div class="fact-card p-5">
            <p class="text-sm opacity-70">Impressions</p>
            <p class="font-display text-3xl font-semibold my-1"><?= number_format((int) $searchTotals['impressions']) ?></p>
            <p class="text-xs opacity-60">times a page appeared in results</p>
        </div>
        <div class="fact-card p-5">
            <p class="text-sm opacity-70">Clicks</p>
            <p class="font-display text-3xl font-semibold my-1"><?= number_format((int) $searchTotals['clicks']) ?></p>
            <p class="text-xs opacity-60"><?= (int) $searchTotals['pages'] ?> pages seen in search</p>
        </div>
        <div class="fact-card p-5">
            <p class="text-sm opacity-70">Average position</p>
            <?php // Lower is better: 1 is the top result. ?>
            <p class="font-display text-3xl font-semibold my-1">
                <?= $searchTotals['position'] !== null ? number_format((float) $searchTotals['position'], 1) : '—' ?>
            </p>
            <p class="text-xs opacity-60">1 is the top of the results</p>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="border-b-2 border-primary text-left font-mono text-xs uppercase tracking-wide">
                    <th class="py-2 pr-4">Page</th>
                    <th class="py-2 pr-4 text-right">Impressions</th>
                    <th class="py-2 pr-4 text-right">Clicks</th>
                    <th class="py-2 pr-4 text-right">CTR</th>
                    <th class="py-2 pr-4 text-right">Position</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($searchPages as $searchPage): ?>
                <?php $pageSubject = $subjectNamesByPath[$searchPage['page_path']] ?? null; ?>
                <tr class="border-b border-primary/20 align-top">
                    <td class="py-3 pr-4">
                        <?php if ($pageSubject): ?>
                        <?php // Subject pages link to their editor, where the page's top searches are listed. ?>
                        <a href="/admin/subject.php?id=<?= (int) $pageSubject['id'] ?>#search" class="font-semibold hover:text-accent">
                            <?= e($pageSubject['name']) ?>
                        </a>
                        <?php endif; ?>
                        <span class="block font-mono text-xs opacity-60"><?= e($searchPage['page_path']) ?></span>
                    </td>
                    <td class="py-3 pr-4 text-right"><?= number_format((int) $searchPage['impressions']) ?></td>
                    <td class="py-3 pr-4 text-right"><?= number_format((int) $searchPage['clicks']) ?></td>
                    <td class="py-3 pr-4 text-right"><?= number_format((float) $searchPage['ctr'] * 100, 1) ?>%</td>
                    <td class="py-3 pr-4 text-right"><?= number_format((float) $searchPage['position'], 1) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<?php // ---------- Recent changes ---------- ?>
<section class="mb-12">
    <h2 class="font-display text-2xl font-semibold mb-4">Latest figure changes</h2>
    <?php if (empty($recentChanges)): ?>
    <p class="fact-card p-6 text-secondary">No changes recorded yet.</p>
    <?php else: ?>
    <ul class="space-y-3 max-w-2xl">
        <?php foreach ($recentChanges as $change): ?>
        <li class="border-l-2 border-primary/30 pl-4">
            <span class="font-mono text-xs uppercase tracking-wide text-secondary block">
                <?= date('j M Y', strtotime($change['changed_at'])) ?> &middot; <?= e((string) $change['change_source']) ?>
            </span>
            <a href="<?= e(subjectUrlById((int) $change['primary_subject_id'])) ?>" target="_blank" class="hover:text-accent">
                <?= e($change['label']) ?></a>:
            <span class="fact-value"><?= e(formatHistoryValue($change, 'old')) ?></span>
            &rarr;
            <span class="fact-value font-semibold"><?= e(formatHistoryValue($change, 'new')) ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
