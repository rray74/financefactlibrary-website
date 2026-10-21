<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/admin-auth.php';
require_once __DIR__ . '/../../includes/admin-queries.php';
require_once __DIR__ . '/../../includes/lessons.php';

// Must be first: sends anyone not logged in to /admin/login.php.
requireAdmin();

/*
 * Lessons for the AI (/admin/lessons.php).
 *
 * Lessons are short, general rules learned from how AI drafts get edited
 * (includes/lessons.php explains how). Approved ones are sent to the AI
 * with every draft and every AI check, together with the house style.
 *
 *   - "Look for lessons" asks the AI to compare saved pages with the
 *     drafts they started as, and propose lessons. They do nothing until
 *     approved here. (scripts/learn-from-edits.php does the same by cron.)
 *   - Proposed lessons can have their wording tidied before approval.
 *   - You can also write a lesson yourself.
 *   - A retired lesson stops being used but is kept.
 *
 * Nothing here changes a page.
 */

$pdo = getDbConnection();

// ------------------------------------------------------------
// Actions. Each redirects afterwards (Post/Redirect/Get), so refreshing
// can't repeat it.
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireValidCsrf();
        $action = $_POST['action'] ?? '';
        $lessonId = (int) ($_POST['lesson_id'] ?? 0);

        if ($action === 'approve_lesson') {
            // The wording can be tidied in the box before approving.
            if (isset($_POST['lesson'])) {
                setLessonText($pdo, $lessonId, (string) $_POST['lesson']);
            }
            setLessonStatus($pdo, $lessonId, 'approved');
            setAdminFlash('success', 'Lesson approved. It is used from the next draft or AI check.');

        } elseif ($action === 'reject_lesson') {
            setLessonStatus($pdo, $lessonId, 'rejected');
            setAdminFlash('success', 'Lesson rejected.');

        } elseif ($action === 'retire_lesson') {
            setLessonStatus($pdo, $lessonId, 'retired');
            setAdminFlash('success', 'Lesson retired. It is no longer sent to the AI.');

        } elseif ($action === 'add_lesson') {
            addLesson($pdo, (string) ($_POST['lesson'] ?? ''), (string) ($_POST['scope'] ?? 'all'), 'approved', 'manual');
            setAdminFlash('success', 'Lesson added and approved. It is used from the next draft or AI check.');

        } elseif ($action === 'learn_now') {
            // Each page is a call to the AI, so only a couple at a time.
            set_time_limit(300);
            $result = processPendingEditRecords($pdo, 2);
            $message = $result['looked_at'] === 0 && !$result['errors']
                ? 'Every saved edit has already been looked at.'
                : implode(' ', $result['notes']);
            if ($result['errors']) {
                setAdminFlash('error', trim($message . ' ' . implode(' ', $result['errors'])));
            } else {
                setAdminFlash($result['proposed'] > 0 ? 'info' : 'success', $message);
            }

        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        setAdminFlash('error', $e->getMessage());
    }
    header('Location: /admin/lessons.php');
    exit;
}

$proposed = getLessonsByStatus($pdo, 'proposed');
$approved = getLessonsByStatus($pdo, 'approved');
$pendingRecords = countPendingEditRecords($pdo);
$recentEdits = getRecentEditRecords($pdo, 10);

// Countries a lesson can be limited to (a country has no parent region).
$countries = $pdo->query('SELECT code, name FROM jurisdictions WHERE parent_id IS NULL ORDER BY id')->fetchAll();
$countryNames = array_column($countries, 'name', 'code');

/** "All countries" or the country's name, for a lesson's scope. */
$scopeLabel = fn(string $scope) => $scope === 'all' ? 'All countries' : ($countryNames[$scope] ?? $scope) . ' only';

$pageTitle = 'Lessons for the AI';
$noindex = true; // admin page, keep it out of search results
$adminSection = 'lessons';

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/admin-nav.php';
?>

<section class="mb-10">
    <h1 class="font-display text-3xl font-semibold mb-2">Lessons for the AI</h1>
    <p class="opacity-80 max-w-2xl">
        The AI remembers nothing between pages, so what it learns from your edits has to be written down.
        A lesson is a short, general rule. <strong>Approved lessons are sent to the AI with every draft and every
        AI check</strong>, alongside the house style, so the same mistake is less likely next time.
        Nothing here changes a page.
    </p>
</section>

<?php // ---------- Look for lessons in saved edits ---------- ?>
<section class="mb-10">
    <h2 class="font-display text-2xl font-semibold mb-2">Look for lessons</h2>
    <?php if ($pendingRecords === 0): ?>
    <p class="opacity-80 text-sm max-w-2xl">
        Every saved edit has been looked at. When you save a page that started as an AI draft, the draft and
        what you saved are kept, and show up here.
    </p>
    <?php else: ?>
    <p class="opacity-80 text-sm max-w-2xl mb-3">
        <?= $pendingRecords ?> saved page<?= $pendingRecords === 1 ? '' : 's' ?> not looked at yet. The AI compares
        each draft with the text you saved, and proposes general lessons. Two pages at a time, about a minute each.
    </p>
    <form method="post" id="learn-form">
        <?= adminCsrfField() ?>
        <input type="hidden" name="action" value="learn_now">
        <button type="submit" class="btn btn-sm btn-primary" id="learn-button">Look for lessons</button>
    </form>
    <?php endif; ?>
</section>

<?php // ---------- Proposed ---------- ?>
<section class="mb-12">
    <h2 class="font-display text-2xl font-semibold mb-2">Waiting for your decision (<?= count($proposed) ?>)</h2>
    <?php if (!$proposed): ?>
    <p class="opacity-80 text-sm">Nothing waiting.</p>
    <?php else: ?>
    <p class="opacity-80 text-sm max-w-2xl mb-4">
        Read each one. Approve it if it is a rule worth following on every page, tidy the wording first if it
        needs it, and reject it if it is only about this page, or only a matter of taste.
    </p>
    <div class="space-y-5">
        <?php foreach ($proposed as $lesson): ?>
        <form method="post" class="fact-card p-5">
            <?= adminCsrfField() ?>
            <input type="hidden" name="lesson_id" value="<?= (int) $lesson['id'] ?>">
            <p class="font-mono text-xs uppercase tracking-wide text-secondary mb-2">
                <?= e($scopeLabel($lesson['scope'])) ?>
                <?php if ($lesson['subject_name']): ?>
                &middot; from <?= e($lesson['subject_name']) ?>
                <?php endif; ?>
            </p>
            <textarea name="lesson" rows="3" maxlength="1000" class="textarea textarea-bordered w-full text-sm"><?= e($lesson['lesson']) ?></textarea>
            <?php if ($lesson['example_before'] || $lesson['example_after']): ?>
            <?php // The wording from the draft and from the saved text that showed the problem. ?>
            <details class="text-sm mt-3">
                <summary class="cursor-pointer text-secondary">What was changed</summary>
                <div class="mt-2 space-y-2">
                    <?php if ($lesson['example_before']): ?>
                    <p><span class="font-mono text-xs uppercase tracking-wide opacity-60">Draft</span><br>
                        <span class="italic">&ldquo;<?= e($lesson['example_before']) ?>&rdquo;</span></p>
                    <?php endif; ?>
                    <?php if ($lesson['example_after']): ?>
                    <p><span class="font-mono text-xs uppercase tracking-wide opacity-60">Saved</span><br>
                        <span class="italic">&ldquo;<?= e($lesson['example_after']) ?>&rdquo;</span></p>
                    <?php endif; ?>
                </div>
            </details>
            <?php endif; ?>
            <div class="flex gap-2 mt-4">
                <button type="submit" name="action" value="approve_lesson" class="btn btn-xs btn-primary">Approve</button>
                <button type="submit" name="action" value="reject_lesson" class="btn btn-xs btn-outline">Reject</button>
            </div>
        </form>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<?php // ---------- Add one by hand ---------- ?>
<details id="add-lesson" class="fact-card p-5 mb-12">
    <summary class="font-semibold cursor-pointer">Add a lesson yourself</summary>
    <form method="post" class="space-y-4 mt-4 max-w-2xl">
        <?= adminCsrfField() ?>
        <input type="hidden" name="action" value="add_lesson">
        <div>
            <label for="new-lesson" class="block text-sm font-semibold mb-1">The rule</label>
            <textarea id="new-lesson" name="lesson" rows="3" maxlength="1000" required
                class="textarea textarea-bordered w-full text-sm"></textarea>
            <p class="text-xs opacity-60 mt-1">
                One or two sentences, in the imperative, general enough to follow on any page. For example: "Say each rule once."
                It is approved straight away.
            </p>
        </div>
        <div>
            <label for="new-scope" class="block text-sm font-semibold mb-1">Applies to</label>
            <select id="new-scope" name="scope" class="select select-bordered select-sm">
                <option value="all">All countries</option>
                <?php foreach ($countries as $country): ?>
                <option value="<?= e($country['code']) ?>"><?= e($country['name']) ?> only</option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Add lesson</button>
    </form>
</details>

<?php // ---------- Approved ---------- ?>
<section class="mb-12">
    <h2 class="font-display text-2xl font-semibold mb-2">In use (<?= count($approved) ?>)</h2>
    <p class="opacity-80 text-sm max-w-2xl mb-4">
        Sent to the AI with every draft and AI check, oldest first. If this list grows past
        <?= (int) LESSONS_SENT_MAX ?>, only the newest <?= (int) LESSONS_SENT_MAX ?> are sent, so retire the
        ones that no longer earn their place.
    </p>
    <?php if (!$approved): ?>
    <p class="opacity-80 text-sm">No approved lessons yet.</p>
    <?php else: ?>
    <ul class="space-y-3 max-w-3xl">
        <?php foreach (array_reverse($approved) as $lesson): ?>
        <li class="border-l-2 border-primary/30 pl-4">
            <form method="post" class="flex items-start gap-4">
                <?= adminCsrfField() ?>
                <input type="hidden" name="lesson_id" value="<?= (int) $lesson['id'] ?>">
                <input type="hidden" name="action" value="retire_lesson">
                <div class="flex-1">
                    <span class="font-mono text-xs uppercase tracking-wide text-secondary block">
                        <?= e($scopeLabel($lesson['scope'])) ?>
                        &middot; <?= e(date('j M Y', strtotime($lesson['decided_at'] ?? $lesson['created_at']))) ?>
                        &middot; <?= e($lesson['origin'] === 'manual' ? 'written by you' : ($lesson['origin'] === 'chat' ? 'from a review' : 'from an edit')) ?>
                    </span>
                    <span class="text-sm"><?= e($lesson['lesson']) ?></span>
                </div>
                <button type="submit" class="btn btn-xs btn-outline shrink-0">Retire</button>
            </form>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>

<?php // ---------- How much drafts are being changed ---------- ?>
<section class="mb-12">
    <h2 class="font-display text-2xl font-semibold mb-2">How much you changed the drafts</h2>
    <?php if (!$recentEdits): ?>
    <p class="opacity-80 text-sm max-w-2xl">
        Nothing yet. After you save a page that started as an AI draft, this shows how much of the explanation you
        changed, 0% meaning none. The aim is for the figure to fall as the lessons build up.
    </p>
    <?php else: ?>
    <p class="opacity-80 text-sm max-w-2xl mb-4">
        How much of the explanation was changed between the draft and the saved text, on words. The aim is for
        these to fall as the lessons build up.
    </p>
    <div class="overflow-x-auto">
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="border-b-2 border-primary text-left font-mono text-xs uppercase tracking-wide">
                    <th class="py-2 pr-4">Page</th>
                    <th class="py-2 pr-4 text-right">Explanation changed</th>
                    <th class="py-2 pr-4">Saved</th>
                    <th class="py-2 pr-4">Lessons</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentEdits as $edit): ?>
                <tr class="border-b border-primary/20 align-top">
                    <td class="py-3 pr-4">
                        <a href="/admin/subject.php?id=<?= (int) $edit['subject_id'] ?>" class="font-semibold hover:text-accent">
                            <?= e($edit['subject_name']) ?>
                        </a>
                    </td>
                    <td class="py-3 pr-4 text-right">
                        <?= $edit['explanation_change_pct'] === null ? '&mdash;' : (int) $edit['explanation_change_pct'] . '%' ?>
                    </td>
                    <td class="py-3 pr-4 text-xs"><?= e(date('j M Y', strtotime($edit['saved_at']))) ?></td>
                    <td class="py-3 pr-4 text-xs"><?= $edit['learned_at'] ? 'Looked at' : '<span class="opacity-60">Not looked at yet</span>' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<script>
    // Looking for lessons calls the AI for each page, so show that it's working.
    (function () {
        var form = document.getElementById('learn-form');
        if (!form) return;
        form.addEventListener('submit', function () {
            var button = document.getElementById('learn-button');
            button.textContent = 'Looking… (up to two minutes)';
        });
    })();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
