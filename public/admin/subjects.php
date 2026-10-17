<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/fact-writer.php'; // only for textLength(); this page never writes
require_once __DIR__ . '/../../includes/admin-auth.php';
require_once __DIR__ . '/../../includes/admin-queries.php';

// Must be first: sends anyone not logged in to /admin/login.php.
requireAdmin();

/*
 * Every subject page, whatever its status, grouped the way the site's
 * navigation is. Each row links to the editor (subject.php).
 *
 * Also has the "Add a subject" form. A new subject is created as a draft
 * (hidden from the public), and you're taken straight to its editor to
 * add facts.
 */

// ------------------------------------------------------------
// Action: add a subject, through createSubject() in fact-writer.php.
// Redirects afterwards (Post/Redirect/Get) so refreshing can't resubmit.
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireValidCsrf();
        if (($_POST['action'] ?? '') !== 'add_subject') {
            throw new RuntimeException('Unknown action.');
        }

        $newId = createSubject(getDbConnection(), [
            'subcategory_id' => $_POST['subcategory_id'] ?? 0,
            'name'           => $_POST['name'] ?? null,
            'slug'           => $_POST['slug'] ?? null,
            'intro'          => $_POST['intro'] ?? null,
            'status'         => 'draft',
        ]);

        setAdminFlash('success', 'Subject added as a draft. Add its facts, then publish it when it\'s ready.');
        header('Location: /admin/subject.php?id=' . $newId);
        exit;
    } catch (Throwable $e) {
        // Keep what was typed and reopen the form, so nothing is lost.
        rememberAdminInput($_POST);
        setAdminFlash('error', $e->getMessage());
        header('Location: /admin/subjects.php#add-subject');
        exit;
    }
}

// Input from an add that failed, put back into the form below.
$old = takeAdminInput();
$addFailed = ($old['action'] ?? '') === 'add_subject';
$subcategoryOptions = getAdminSubcategoryOptions();

$subjects = getAdminSubjectList();

// Group by "Category / Subcategory" so the list reads like the site.
$groups = [];
foreach ($subjects as $subject) {
    $groups[$subject['category_name'] . ' / ' . $subject['subcategory_name']][] = $subject;
}

// Status counts for the summary line.
$statusCounts = array_count_values(array_column($subjects, 'status'));

// Intros shorter than SHORT_INTRO_CHARACTERS (in admin-queries.php, shared
// with the dashboard) are flagged as short. A rough guide, not a rule.

$pageTitle = 'Subjects';
$noindex = true; // admin page, keep it out of search results
$adminSection = 'subjects';

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/admin-nav.php';
?>

<section class="mb-8">
    <h1 class="font-display text-3xl font-semibold mb-2">Subjects</h1>
    <p class="opacity-80 max-w-xl">
        <?= count($subjects) ?> subject pages:
        <?= (int) ($statusCounts['published'] ?? 0) ?> published,
        <?= (int) ($statusCounts['draft'] ?? 0) ?> draft,
        <?= (int) ($statusCounts['retired'] ?? 0) ?> retired.
        Choose one to edit its intro, search listing, status and facts, or add a new one.
    </p>
</section>

<?php // ---------- Add a subject (folded away unless a previous attempt failed) ---------- ?>
<details id="add-subject" class="fact-card p-5 mb-10 scroll-mt-6" <?= $addFailed ? 'open' : '' ?>>
    <summary class="font-semibold cursor-pointer">Add a subject</summary>

    <form method="post" class="space-y-4 mt-4 max-w-2xl">
        <?= adminCsrfField() ?>
        <input type="hidden" name="action" value="add_subject">

        <div>
            <label for="new-subcategory" class="block text-sm font-semibold mb-1">Goes in</label>
            <select id="new-subcategory" name="subcategory_id" required class="select select-bordered select-sm w-full">
                <option value="">Choose a category and subcategory</option>
                <?php // One group per "Country: Category", so the list reads like the site's navigation. ?>
                <?php $currentGroup = null; ?>
                <?php foreach ($subcategoryOptions as $option): ?>
                <?php $group = $option['country_name'] . ': ' . $option['category_name']; ?>
                <?php if ($group !== $currentGroup): ?>
                <?php if ($currentGroup !== null): ?></optgroup><?php endif; ?>
                <optgroup label="<?= e($group) ?>">
                <?php $currentGroup = $group; ?>
                <?php endif; ?>
                    <option value="<?= (int) $option['id'] ?>"
                        <?= $addFailed && (int) ($old['subcategory_id'] ?? 0) === (int) $option['id'] ? 'selected' : '' ?>>
                        <?= e($option['name']) ?>
                    </option>
                <?php endforeach; ?>
                <?php if ($currentGroup !== null): ?></optgroup><?php endif; ?>
            </select>
            <p class="text-xs opacity-60 mt-1">New categories and subcategories are still added through the importer.</p>
        </div>

        <div>
            <label for="new-name" class="block text-sm font-semibold mb-1">Name</label>
            <input type="text" id="new-name" name="name" required maxlength="255"
                class="input input-bordered input-sm w-full" value="<?= e($addFailed ? (string) ($old['name'] ?? '') : '') ?>"
                placeholder="e.g. Junior ISA Allowance">
        </div>

        <div>
            <label for="new-slug" class="block text-sm font-semibold mb-1">Address (slug)</label>
            <input type="text" id="new-slug" name="slug" required maxlength="120" pattern="[a-z0-9]+(-[a-z0-9]+)*"
                class="input input-bordered input-sm w-full font-mono" value="<?= e($addFailed ? (string) ($old['slug'] ?? '') : '') ?>"
                placeholder="junior-isa-allowance">
            <p class="text-xs opacity-60 mt-1">
                The last part of the page address. Filled in from the name, and best not changed once the
                page is published, since it changes the URL.
            </p>
        </div>

        <div>
            <label for="new-intro" class="block text-sm font-semibold mb-1">Intro <span class="font-normal opacity-60">(optional, can be added later)</span></label>
            <textarea id="new-intro" name="intro" rows="3" class="textarea textarea-bordered w-full"><?= e($addFailed ? (string) ($old['intro'] ?? '') : '') ?></textarea>
        </div>

        <button type="submit" class="btn btn-sm btn-primary">Add as draft</button>
    </form>
</details>

<script>
    // Fill the slug from the name as you type (e.g. "Junior ISA Allowance"
    // becomes junior-isa-allowance), until the slug is edited by hand.
    (function () {
        var name = document.getElementById('new-name');
        var slug = document.getElementById('new-slug');
        var edited = slug.value !== '';

        slug.addEventListener('input', function () { edited = slug.value !== ''; });
        name.addEventListener('input', function () {
            if (edited) return;
            slug.value = name.value.toLowerCase()
                .replace(/&/g, ' and ')
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        });
    })();
</script>

<?php if (empty($subjects)): ?>
<p class="fact-card p-6 text-secondary">No subjects yet.</p>
<?php endif; ?>

<?php foreach ($groups as $groupName => $groupSubjects): ?>
<section class="mb-10">
    <h2 class="font-mono text-xs uppercase tracking-wide text-secondary mb-2"><?= e($groupName) ?></h2>
    <div class="overflow-x-auto">
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="border-b-2 border-primary text-left font-mono text-xs uppercase tracking-wide">
                    <th class="py-2 pr-4">Subject</th>
                    <th class="py-2 pr-4">Status</th>
                    <th class="py-2 pr-4">Figures shown</th>
                    <th class="py-2 pr-4">Intro / explanation</th>
                    <th class="py-2 pr-4">Search listing</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groupSubjects as $subject): ?>
                <?php $introLength = textLength($subject['intro']); ?>
                <tr class="border-b border-primary/20 align-top">
                    <td class="py-3 pr-4">
                        <a href="/admin/subject.php?id=<?= (int) $subject['id'] ?>" class="font-semibold hover:text-accent">
                            <?= e($subject['name']) ?>
                        </a>
                        <span class="block font-mono text-xs opacity-50"><?= e($subject['slug']) ?></span>
                    </td>
                    <td class="py-3 pr-4">
                        <?php // Draft pages 404 publicly and retired ones 410, so make those stand out. ?>
                        <span class="font-mono text-xs px-2 py-1 rounded <?= $subject['status'] === 'published' ? 'bg-secondary/10 text-secondary' : 'bg-error/10 text-error' ?>">
                            <?= e($subject['status']) ?>
                        </span>
                    </td>
                    <td class="py-3 pr-4">
                        <?= (int) $subject['published_facts'] ?>
                        <?php if ((int) $subject['owned_facts'] > 0): ?>
                        <span class="block text-xs opacity-50"><?= (int) $subject['owned_facts'] ?> owned</span>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 pr-4">
                        <?php if ($introLength === 0): ?>
                        <span class="text-error">None</span>
                        <?php else: ?>
                        <?= $introLength ?> chars
                        <?php if ($introLength < SHORT_INTRO_CHARACTERS): ?>
                        <span class="block text-xs opacity-60">short</span>
                        <?php endif; ?>
                        <?php endif; ?>
                        <?php // The longer section below the figures (migration 010). ?>
                        <span class="block text-xs <?= (int) $subject['explanation_length'] === 0 ? 'opacity-60' : '' ?>">
                            <?= (int) $subject['explanation_length'] === 0
                                ? 'No explanation'
                                : 'Explanation: ' . number_format((int) $subject['explanation_length']) . ' chars' ?>
                        </span>
                    </td>
                    <td class="py-3 pr-4 text-xs">
                        <?php // Blank meta fields fall back to the name and intro, which is fine but not tailored. ?>
                        <?= $subject['meta_title'] ? 'Title set' : '<span class="opacity-60">Title from name</span>' ?><br>
                        <?= $subject['meta_description'] ? 'Description set' : '<span class="opacity-60">Description from intro</span>' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endforeach; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
