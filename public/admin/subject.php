<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/fact-writer.php';
require_once __DIR__ . '/../../includes/admin-auth.php';
require_once __DIR__ . '/../../includes/admin-queries.php';
require_once __DIR__ . '/../../includes/ai-drafts.php'; // AI drafts of the four text fields
require_once __DIR__ . '/../../includes/text-checks.php'; // quick checks and the AI check of the text

// Must be first: sends anyone not logged in to /admin/login.php.
requireAdmin();

/*
 * Edit one subject page: its intro, explanation, search listing (meta
 * title and description) and status, plus the label, context and review cadence of
 * each fact the page owns, and add new facts to it.
 *
 * AI drafts: pages without an explanation are drafted overnight by
 * scripts/draft-pages.php, and "Draft this page" redrafts one now. The
 * draft (all four text fields) is stored in subject_drafts and shown
 * under each field with a "Use draft" button, which copies it into the
 * box. Nothing reaches the live page until you click Save page details,
 * and saving with an explanation clears the draft.
 *
 * Checks (includes/text-checks.php): quick house-style checks run on the
 * saved text whenever the page opens. "Check with AI" reads whatever is in
 * the boxes against the page's figures and source pages, without saving,
 * and the result stays on the page, marked if the text has changed since.
 *
 * Placeholders: the four text fields can use {fact:fact_key} and
 * {tax_year} (includes/placeholders.php). The page lists them, inserts
 * one at the cursor when clicked, counts characters with them filled in,
 * and refuses to save an invalid one. Problems in the saved text (a fact
 * retired since, say) are flagged when the page opens.
 *
 * Fact VALUES aren't edited here. They change on the review page or via
 * the importer, through updateFactValue(), so every change is recorded
 * in fact_history.
 */

$subjectId = (int) ($_GET['id'] ?? 0);

// Opened without choosing a subject (e.g. /admin/subject.php typed in
// directly), so go to the list to pick one rather than show a 404.
if ($subjectId <= 0) {
    header('Location: /admin/subjects.php');
    exit;
}

$subject = getAdminSubject($subjectId);
if (!$subject) {
    showErrorPage(404, 'Subject not found.');
}

// Loaded before handling the form, so a fact edit can be checked against
// the facts this page actually owns.
$facts = getAdminFactsForSubject($subjectId);
$ownedFactIds = [];
foreach ($facts as $fact) {
    if ((int) $fact['is_primary'] === 1) {
        $ownedFactIds[] = (int) $fact['id'];
    }
}

// Placeholders this page can use, and what each shows now. Only the
// page's published facts, since only they appear on the public page.
$countryCode = (string) $subject['country_code'];
$placeholderValues = placeholderValuesForPage($countryCode, $facts);
$pageFactKeys = [];
$pageFactLabels = [];
foreach ($facts as $fact) {
    if ($fact['status'] === 'published') {
        $pageFactKeys[] = $fact['fact_key'];
        $pageFactLabels['{fact:' . $fact['fact_key'] . '}'] = $fact['label'];
    }
}

// Text with its placeholders filled in, as the public page shows it.
$fillPreview = fn(?string $text) => fillPlaceholders($text, $countryCode, $facts);

// This page's stored AI draft (subject_drafts), or NULL.
$draft = getSubjectDraft($subjectId);

// This page's latest AI text check (subject_text_checks), or NULL.
$textCheck = getSubjectTextCheck($subjectId);

// Where in the page to land after saving, so you're returned to the form
// you just used rather than the top.
$returnAnchor = '';

// ------------------------------------------------------------
// Actions. Both writes go through fact-writer.php. After handling,
// redirect back (Post/Redirect/Get) so refreshing can't resubmit.
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        requireValidCsrf();
        $pdo = getDbConnection();

        if ($action === 'save_subject' && !empty($_POST['check_page'])) {
            // "Check with AI": checks the text in the boxes as typed, saved
            // or not, and saves nothing but the check's result. What's typed
            // comes back unchanged.
            $returnAnchor = '#text-check';

            // Reading the source pages and the AI together can take a minute or two.
            set_time_limit(240);
            // The result is saved afterwards, so keep the connection open meanwhile.
            keepDbConnectionOpenForAi($pdo);

            $typed = [];
            foreach (array_keys(TEXT_FIELD_LABELS) as $field) {
                $typed[$field] = (string) ($_POST[$field] ?? '');
            }
            $result = aiTextCheck($subject, $facts, $typed);
            saveSubjectTextCheck($pdo, $subjectId, $result, textCheckHash($typed));
            rememberAdminInput($_POST);

            $found = count($result['findings']);
            setAdminFlash($found ? 'info' : 'success', ($found
                ? "AI check: $found thing" . ($found === 1 ? '' : 's') . ' to look at, listed under Page details.'
                : 'AI check: no problems found.') . ' Nothing has been saved.');

        } elseif ($action === 'save_subject' && !empty($_POST['draft_page'])) {
            // "Draft this page": the button is inside the page details form,
            // so everything typed comes back too, and is put back in the
            // boxes unchanged (nothing typed is lost or saved). The new draft
            // replaces any stored one and appears under each field.
            $returnAnchor = '#subject-details';

            // The AI can take up to a minute or so; allow for that.
            set_time_limit(180);
            // The draft is saved afterwards, so keep the connection open meanwhile.
            keepDbConnectionOpenForAi($pdo);

            $newDraft = draftSubjectPage($pdo, $subject, $facts);
            saveSubjectDraft($pdo, $subjectId, $newDraft, 'editor');
            rememberAdminInput($_POST);

            setAdminFlash('info', 'New AI draft below each field. Click "Use draft" to copy one into its box. '
                . 'Nothing on the page has changed.');

        } elseif ($action === 'save_subject') {
            $returnAnchor = '#subject-details';
            updateSubjectDetails($pdo, $subjectId, [
                'intro'            => $_POST['intro'] ?? null,
                'explanation'      => $_POST['explanation'] ?? null,
                'meta_title'       => $_POST['meta_title'] ?? null,
                'meta_description' => $_POST['meta_description'] ?? null,
                'status'           => $_POST['status'] ?? '',
            ]);

            // Saved. Placeholders that are valid but use a figure from
            // another page are allowed, but worth knowing about.
            $warnings = checkPlaceholders($pdo, [
                'Intro'            => $_POST['intro'] ?? null,
                'Explanation'      => $_POST['explanation'] ?? null,
                'Meta title'       => $_POST['meta_title'] ?? null,
                'Meta description' => $_POST['meta_description'] ?? null,
            ], $pageFactKeys)['warnings'];
            // Once the page has an explanation, the draft has done its job.
            // (A page saved with only an intro, say, keeps its draft.)
            if (cleanEditorText($_POST['explanation'] ?? null) !== null) {
                deleteSubjectDraft($pdo, $subjectId);
            }

            // Quick house-style checks on what was just saved. They're listed
            // under Page details, so the message only says how many.
            $quickNotes = quickTextChecks([
                'intro'            => $_POST['intro'] ?? null,
                'explanation'      => $_POST['explanation'] ?? null,
                'meta_title'       => $_POST['meta_title'] ?? null,
                'meta_description' => $_POST['meta_description'] ?? null,
            ], $facts);
            $toCheck = count($warnings) + count($quickNotes);
            setAdminFlash($toCheck ? 'info' : 'success', 'Subject saved.'
                . ($toCheck ? " $toCheck thing" . ($toCheck === 1 ? '' : 's') . ' to check, listed under Page details.' : ''));

        } elseif ($action === 'save_fact') {
            $factId = (int) ($_POST['fact_id'] ?? 0);
            $returnAnchor = '#fact-' . $factId;

            // Shared facts are edited on the page that owns them, so one
            // fact's wording isn't changed from several places.
            if (!in_array($factId, $ownedFactIds, true)) {
                throw new RuntimeException('That fact belongs to another page. Edit it there.');
            }

            updateFactDetails($pdo, $factId, [
                'label'                 => $_POST['label'] ?? null,
                'context'               => $_POST['context'] ?? null,
                'review_frequency_days' => $_POST['review_frequency_days'] ?? null,
            ]);
            setAdminFlash('success', 'Fact saved.');

        } elseif ($action === 'add_fact') {
            $returnAnchor = '#add-fact';

            // New facts belong to this page. addFactToSubject() checks every
            // field, then creates the fact through createFact(), the same
            // path the importer uses, so its first value goes into
            // fact_history and it's added to the end of this page.
            $result = addFactToSubject($pdo, $subjectId, [
                'fact_key'              => $_POST['fact_key'] ?? null,
                'label'                 => $_POST['label'] ?? null,
                'value'                 => $_POST['value'] ?? null,
                'unit'                  => $_POST['unit'] ?? null,
                'context'               => $_POST['context'] ?? null,
                'jurisdiction_id'       => $_POST['jurisdiction_id'] ?? 0,
                'source_url'            => $_POST['source_url'] ?? null,
                'source_publisher'      => $_POST['source_publisher'] ?? null,
                'effective_from'        => $_POST['effective_from'] ?? null,
                'tax_year'              => $_POST['tax_year'] ?? null,
                'review_frequency_days' => $_POST['review_frequency_days'] ?? null,
                'status'                => $_POST['status'] ?? 'draft',
            ]);

            $returnAnchor = '#fact-' . $result['id'];
            if ($result['note'] !== null) {
                // Asked to publish, but the source isn't allowlisted (or is missing).
                setAdminFlash('info', 'Fact added. ' . $result['note']);
            } else {
                setAdminFlash('success', $result['status'] === 'published'
                    ? 'Fact added and published.'
                    : 'Fact added as a draft. It won\'t show on the page until it\'s published.');
            }

        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        // Keep what was typed, so a failed save doesn't lose it.
        rememberAdminInput($_POST);
        setAdminFlash('error', $e->getMessage());
    }

    header('Location: /admin/subject.php?id=' . $subjectId . $returnAnchor);
    exit;
}

// Input from a save that failed, put back into the matching form below.
$old = takeAdminInput();
$oldSubject = ($old['action'] ?? '') === 'save_subject' ? $old : null;
$oldFactId = ($old['action'] ?? '') === 'save_fact' ? (int) ($old['fact_id'] ?? 0) : 0;
$addFactFailed = ($old['action'] ?? '') === 'add_fact';

// The "Add a fact" form starts blank, with sensible defaults, unless a
// previous attempt failed, in which case it shows what was typed.
$newFact = $addFactFailed ? $old : [];
$newFact += [
    'fact_key' => '', 'label' => '', 'value' => '', 'unit' => '', 'context' => '',
    'jurisdiction_id' => (int) $subject['country_id'], 'source_url' => '', 'source_publisher' => '',
    'effective_from' => '', 'tax_year' => '', 'review_frequency_days' => 365, 'status' => 'published',
];

// The page's country and its nations, for "Applies to".
$jurisdictionOptions = getJurisdictionOptions((int) $subject['country_id']);

// Google search figures for this page (Search Console, last 28 days),
// matched on the page's public path. NULL if Google hasn't shown it yet.
$searchStats = getSearchStatsForPath(subjectUrlById($subjectId));
$searchQueries = $searchStats ? getTopQueriesForPath($searchStats['page_path'], 10) : [];

$form = [
    'intro'            => $oldSubject['intro'] ?? (string) $subject['intro'],
    'explanation'      => $oldSubject['explanation'] ?? (string) ($subject['explanation'] ?? ''),
    'meta_title'       => $oldSubject['meta_title'] ?? (string) $subject['meta_title'],
    'meta_description' => $oldSubject['meta_description'] ?? (string) $subject['meta_description'],
    'status'           => $oldSubject['status'] ?? $subject['status'],
];

// The public page address, and what search results show when the meta
// fields are left blank (fact.php falls back to the name and intro).
$publicUrl = subjectUrlById($subjectId);
$fallbackTitle = $subject['name'];
$fallbackDescription = (string) cleanEditorText($fillPreview($subject['intro']), true);

// Problems in the text as saved, e.g. a placeholder whose fact has been
// retired since, which the public page would show as nothing.
$savedPlaceholderCheck = checkPlaceholders(getDbConnection(), [
    'Intro'            => $subject['intro'],
    'Explanation'      => $subject['explanation'] ?? null,
    'Meta title'       => $subject['meta_title'],
    'Meta description' => $subject['meta_description'],
], $pageFactKeys);

// Quick house-style checks on the saved text, shown with the placeholder
// problems until they're fixed.
$savedFields = [
    'intro'            => $subject['intro'],
    'explanation'      => $subject['explanation'] ?? null,
    'meta_title'       => $subject['meta_title'],
    'meta_description' => $subject['meta_description'],
];
$savedQuickNotes = quickTextChecks($savedFields, $facts);

/**
 * The AI draft for one field, shown under the field: the draft text and a
 * "Use draft" button that copies it into the box (see the script at the
 * end). Nothing is shown if there's no draft for the field, or it's
 * already what's in the box. Open when the box is empty, folded away
 * when you've already written something there.
 */
function renderFieldDraft(?array $draft, string $field, string $currentValue): string
{
    $text = $draft[$field] ?? null;
    if ($text === null || $text === '' || trim($text) === trim($currentValue)) {
        return '';
    }

    $open = trim($currentValue) === '' ? ' open' : '';
    $when = date('j M', strtotime($draft['created_at']));
    return '<details class="mt-2 border-l-2 border-secondary/40 pl-3"' . $open . '>'
        . '<summary class="text-xs text-secondary cursor-pointer">AI draft (' . e($when) . ')</summary>'
        . '<div class="whitespace-pre-wrap text-sm opacity-80 my-2' . ($field === 'explanation' ? ' font-mono' : '') . '">'
        . e($text) . '</div>'
        . '<button type="button" class="btn btn-xs btn-outline" data-use-draft="' . e($field) . '">Use draft</button>'
        . '</details>';
}

// What each status means for the public page, shown next to the choice.
$statusHelp = [
    'draft'     => 'Hidden. The page answers 404 and is left out of listings and the sitemap.',
    'published' => 'Live, listed, and in the sitemap.',
    'retired'   => 'Removed on purpose. The page answers 410, so search engines drop it.',
];

$pageTitle = 'Edit: ' . $subject['name'];
$noindex = true; // admin page, keep it out of search results
$adminSection = 'subjects';

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/admin-nav.php';
?>

<nav class="font-mono text-xs uppercase tracking-wide text-secondary mb-4">
    <a href="/admin/subjects.php" class="hover:text-accent">Subjects</a>
    <span class="opacity-50">/</span>
    <?= e($subject['category_name']) ?>
    <span class="opacity-50">/</span>
    <?= e($subject['subcategory_name']) ?>
</nav>

<section class="mb-10">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <h1 class="font-display text-3xl font-semibold"><?= e($subject['name']) ?></h1>
        <?php if ($subject['status'] === 'published'): ?>
        <a href="<?= e($publicUrl) ?>" target="_blank" class="text-sm text-secondary underline hover:text-accent">
            View page &#8599;
        </a>
        <?php endif; ?>
    </div>
    <p class="font-mono text-xs opacity-50 mt-1"><?= e($publicUrl) ?></p>
</section>

<?php // ---------- Google search (Search Console, last 28 days) ---------- ?>
<section id="search" class="mb-10 scroll-mt-6">
    <?php if (!$searchStats): ?>
    <p class="text-sm opacity-60">
        No Google search data for this page in the last 28 days.
    </p>
    <?php else: ?>
    <div class="fact-card p-5">
        <p class="font-mono text-xs uppercase tracking-wide text-secondary mb-2">
            Google search, <?= date('j M', strtotime($searchStats['period_start'])) ?> to <?= date('j M Y', strtotime($searchStats['period_end'])) ?>
        </p>
        <p class="text-sm">
            <strong><?= number_format((int) $searchStats['impressions']) ?></strong> impressions &middot;
            <strong><?= number_format((int) $searchStats['clicks']) ?></strong> clicks &middot;
            <?= number_format((float) $searchStats['ctr'] * 100, 1) ?>% click rate &middot;
            average position <strong><?= number_format((float) $searchStats['position'], 1) ?></strong>
        </p>
        <?php if ($searchQueries): ?>
        <?php // What people searched for: useful for the intro, explanation and meta title. ?>
        <details class="mt-3">
            <summary class="text-sm text-secondary cursor-pointer">Top searches showing this page</summary>
            <table class="w-full text-sm border-collapse mt-2">
                <thead>
                    <tr class="border-b border-primary/40 text-left font-mono text-xs uppercase tracking-wide">
                        <th class="py-1 pr-4">Search</th>
                        <th class="py-1 pr-4 text-right">Impr.</th>
                        <th class="py-1 pr-4 text-right">Clicks</th>
                        <th class="py-1 text-right">Pos.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($searchQueries as $query): ?>
                    <tr class="border-b border-primary/10">
                        <td class="py-1 pr-4"><?= e($query['query']) ?></td>
                        <td class="py-1 pr-4 text-right"><?= number_format((int) $query['impressions']) ?></td>
                        <td class="py-1 pr-4 text-right"><?= number_format((int) $query['clicks']) ?></td>
                        <td class="py-1 text-right"><?= number_format((float) $query['position'], 1) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </details>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>

<?php // ---------- Subject details ---------- ?>
<section id="subject-details" class="mb-14 scroll-mt-6">
    <h2 class="font-display text-2xl font-semibold mb-4">Page details</h2>

    <?php $savedNotes = array_merge($savedPlaceholderCheck['warnings'], $savedQuickNotes); ?>
    <?php if ($savedPlaceholderCheck['errors'] || $savedNotes): ?>
    <?php // About the SAVED text, so it shows even if nothing has been edited. ?>
    <div class="alert <?= $savedPlaceholderCheck['errors'] ? 'alert-error' : 'alert-info' ?> mb-6 text-sm max-w-2xl">
        <span>
            <strong>Checks on the saved text:</strong>
            <?php // Placeholder errors first (the public page shows those as nothing), then house-style checks. ?>
            <?php foreach (array_merge($savedPlaceholderCheck['errors'], $savedNotes) as $problem): ?>
            <br><?= e($problem) ?>
            <?php endforeach; ?>
            <?php if ($savedPlaceholderCheck['errors']): ?>
            <br>The public page shows these as nothing until they're fixed.
            <?php endif; ?>
        </span>
    </div>
    <?php endif; ?>

    <form method="post" class="space-y-6 max-w-2xl">
        <?= adminCsrfField() ?>
        <input type="hidden" name="action" value="save_subject">

        <?php // AI draft: what's stored, anything to check, and the button to redraft now. ?>
        <?php if ($draft || aiProposalsAvailable()): ?>
        <div class="fact-card p-4 text-sm">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="font-semibold">
                    <?php if ($draft): ?>
                    AI draft from <?= e(date('j M Y, H:i', strtotime($draft['created_at']))) ?>
                    <span class="font-normal opacity-60">(<?= $draft['made_by'] === 'nightly' ? 'made overnight' : 'made here' ?>)</span>
                    <?php else: ?>
                    No AI draft for this page yet
                    <?php endif; ?>
                </p>
                <?php if (aiProposalsAvailable()): ?>
                <?php // Submits this form with draft_page set: what's typed comes back unchanged. ?>
                <button type="submit" name="draft_page" value="1" formnovalidate
                    class="btn btn-xs btn-outline" id="draft-button"><?= $draft ? 'Redraft this page' : 'Draft this page' ?></button>
                <?php endif; ?>
            </div>
            <p class="text-xs opacity-70 mt-1">
                <?= $draft
                    ? 'Shown under each field. "Use draft" copies it into the box to rewrite in your own words. Nothing changes until you save.'
                    : 'Pages without an explanation are drafted overnight, most-searched first. Or draft this one now (up to a minute).' ?>
            </p>
            <?php if ($draft && $draft['problems']): ?>
            <?php // Numbers that aren't among the page's figures, and placeholder problems. ?>
            <div class="alert alert-error mt-3 text-sm">
                <span>
                    <strong>Check before using:</strong>
                    <?php foreach (explode("\n", $draft['problems']) as $problem): ?>
                    <br><?= e($problem) ?>
                    <?php endforeach; ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php // AI check: the latest result, and the button to check the text in the boxes now. ?>
        <?php if ($textCheck || aiProposalsAvailable()): ?>
        <?php $checkMatchesBoxes = $textCheck && textCheckHash($form) === $textCheck['text_hash']; ?>
        <div id="text-check" class="fact-card p-4 text-sm scroll-mt-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="font-semibold">
                    <?php if ($textCheck): ?>
                    AI check from <?= e(date('j M Y, H:i', strtotime($textCheck['checked_at']))) ?>
                    <?php // Matched by fingerprint, so a check of unsaved text still counts once it's saved unchanged. ?>
                    <span class="font-normal <?= $checkMatchesBoxes ? 'opacity-60' : 'text-error' ?>">
                        (<?= $checkMatchesBoxes ? 'of the text in the boxes' : 'the text has changed since' ?>)
                    </span>
                    <?php else: ?>
                    Not checked by AI yet
                    <?php endif; ?>
                </p>
                <?php if (aiProposalsAvailable()): ?>
                <?php // Submits this form with check_page set: nothing is saved, what's typed comes back. ?>
                <button type="submit" name="check_page" value="1" formnovalidate
                    class="btn btn-xs btn-outline" id="check-button">Check with AI</button>
                <?php endif; ?>
            </div>
            <p class="text-xs opacity-70 mt-1">
                Reads the text in the boxes, with figures filled in, against this page's figures and their
                source pages. Saved or not, nothing is changed. Up to two minutes.
            </p>

            <?php if ($textCheck): ?>
            <?php if (!$textCheck['findings']): ?>
            <p class="mt-3 text-secondary">No problems found.</p>
            <?php else: ?>
            <ul class="mt-3 space-y-3">
                <?php foreach ($textCheck['findings'] as $finding): ?>
                <li class="border-l-2 border-error/50 pl-3">
                    <span class="font-mono text-xs uppercase tracking-wide text-error">
                        <?= e(TEXT_CHECK_KINDS[$finding['kind']] ?? $finding['kind']) ?>
                        &middot; <?= e(TEXT_FIELD_LABELS[$finding['field']] ?? $finding['field']) ?>
                    </span>
                    <span class="block italic opacity-80">&ldquo;<?= e($finding['quote']) ?>&rdquo;</span>
                    <span class="block"><?= e($finding['problem']) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <?php // What it read, so a source that couldn't be read is visible. ?>
            <p class="text-xs opacity-60 mt-3">
                <?php if (!$textCheck['sources']): ?>
                No source pages to read (figures without a source, or Bank of England data only).
                <?php else: ?>
                Sources:
                <?php foreach ($textCheck['sources'] as $i => $source): ?>
                <?= $i > 0 ? '&middot;' : '' ?>
                <?= e($source['name']) ?> <?= $source['read'] ? '(read)' : '(couldn\'t be read: ' . e((string) $source['error']) . ')' ?>
                <?php endforeach; ?>
                <?php endif; ?>
                <?php if ((int) $textCheck['dropped'] > 0): ?>
                <br><?= (int) $textCheck['dropped'] ?> finding(s) dropped because they misquoted the text.
                <?php endif; ?>
            </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php // Placeholders: click one to put it where the cursor was in any of the four fields. ?>
        <details open class="fact-card p-4">
            <summary class="font-semibold cursor-pointer text-sm">Placeholders for this page</summary>
            <p class="text-xs opacity-70 mt-2 mb-3">
                Use these instead of typing figures that change, in any of the fields below. Click one to
                insert it where your cursor was. The page shows the live value. See
                <span class="font-mono">docs/house-style/</span> for when to use them.
            </p>
            <ul class="space-y-1 text-sm">
                <?php foreach ($placeholderValues as $token => $value): ?>
                <li class="flex flex-wrap items-baseline gap-x-3">
                    <button type="button" class="btn btn-xs btn-outline font-mono" data-insert="<?= e($token) ?>"><?= e($token) ?></button>
                    <span class="fact-value font-semibold"><?= e($value) ?></span>
                    <span class="text-xs opacity-60"><?= e($pageFactLabels[$token] ?? 'Tax year of this page\'s figures') ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </details>

        <div>
            <label for="intro" class="block font-semibold mb-1">Intro</label>
            <p class="text-xs opacity-70 mb-2">
                The line under the page heading. Also shown on this page's card on the subcategory
                page, and used as the search result description when the meta description below is
                blank. One sentence, 120 to 200 characters, no figures.
            </p>
            <textarea id="intro" name="intro" rows="3" class="textarea textarea-bordered w-full"
                data-counter="intro-count" data-limit="200" data-min="120"><?= e($form['intro']) ?></textarea>
            <p id="intro-count" class="text-xs opacity-60 mt-1"></p>
            <?= renderFieldDraft($draft, 'intro', $form['intro']) ?>
        </div>

        <div id="explanation-field" class="scroll-mt-6">
            <label for="explanation" class="block font-semibold mb-1">Explanation</label>
            <p class="text-xs opacity-70 mb-2">
                The written guide shown below the figures. Leave blank to hide the section.
                Formatting: <code class="font-mono">## Heading</code>, <code class="font-mono">### Smaller heading</code>,
                <code class="font-mono">- bullet</code>, <code class="font-mono">1. numbered</code>,
                <code class="font-mono">**bold**</code> and <code class="font-mono">[link text](/uk/tax/)</code>.
                A blank line starts a new paragraph.
            </p>
            <textarea id="explanation" name="explanation" rows="16" class="textarea textarea-bordered w-full font-mono text-sm"
                data-counter="explanation-count" data-words="1"><?= e($form['explanation']) ?></textarea>
            <p id="explanation-count" class="text-xs opacity-60 mt-1"></p>
            <?= renderFieldDraft($draft, 'explanation', $form['explanation']) ?>

            <?php // Preview of the SAVED version, rendered exactly as the public page does it. ?>
            <?php // Placeholders filled in, as on the public page. ?>
            <?php $savedExplanationHtml = renderExplanation($fillPreview($subject['explanation'] ?? null)); ?>
            <?php if ($savedExplanationHtml !== ''): ?>
            <details class="mt-3">
                <summary class="text-sm text-secondary cursor-pointer">Preview (as last saved)</summary>
                <div class="border border-primary/20 rounded p-5 mt-2 leading-relaxed [&>:first-child]:mt-0">
                    <?= $savedExplanationHtml ?>
                </div>
            </details>
            <?php endif; ?>
        </div>

        <div>
            <label for="meta_title" class="block font-semibold mb-1">Meta title</label>
            <p class="text-xs opacity-70 mb-2">
                The search result headline. " — Finance Fact Library" is added after it, so aim for
                42 characters or fewer to keep the whole title within about 60. Blank uses the
                subject name.
            </p>
            <input type="text" id="meta_title" name="meta_title" maxlength="255"
                class="input input-bordered w-full" value="<?= e($form['meta_title']) ?>"
                placeholder="<?= e($fallbackTitle) ?>" data-counter="meta-title-count" data-limit="42"
                data-preview="meta-title-preview">
            <p id="meta-title-count" class="text-xs opacity-60 mt-1"></p>
            <?php // Filled in by the script below when the title uses placeholders. ?>
            <p id="meta-title-preview" class="text-xs mt-1"></p>
            <?= renderFieldDraft($draft, 'meta_title', $form['meta_title']) ?>
        </div>

        <div>
            <label for="meta_description" class="block font-semibold mb-1">Meta description</label>
            <p class="text-xs opacity-70 mb-2">
                The search result snippet. Anything over 160 characters is cut short on the
                page, so aim for 140 to 155. Blank uses the intro.
            </p>
            <textarea id="meta_description" name="meta_description" rows="3" maxlength="255"
                class="textarea textarea-bordered w-full" placeholder="<?= e($fallbackDescription) ?>"
                data-counter="meta-description-count" data-limit="155" data-min="140"
                data-preview="meta-description-preview"><?= e($form['meta_description']) ?></textarea>
            <p id="meta-description-count" class="text-xs opacity-60 mt-1"></p>
            <p id="meta-description-preview" class="text-xs mt-1"></p>
            <?= renderFieldDraft($draft, 'meta_description', $form['meta_description']) ?>
        </div>

        <fieldset>
            <legend class="font-semibold mb-2">Status</legend>
            <?php foreach (SUBJECT_STATUSES as $status): ?>
            <label class="flex items-start gap-3 mb-2 cursor-pointer">
                <input type="radio" name="status" value="<?= e($status) ?>" class="radio radio-sm mt-1"
                    <?= $form['status'] === $status ? 'checked' : '' ?>>
                <span>
                    <span class="font-mono text-sm"><?= e($status) ?></span>
                    <span class="block text-xs opacity-70"><?= e($statusHelp[$status]) ?></span>
                </span>
            </label>
            <?php endforeach; ?>
        </fieldset>

        <button type="submit" class="btn btn-primary">Save page details</button>
    </form>
</section>

<?php // ---------- Facts on this page ---------- ?>
<section id="facts" class="mb-14">
    <h2 class="font-display text-2xl font-semibold mb-2">Facts on this page</h2>
    <p class="opacity-80 max-w-xl mb-6 text-sm">
        In page order. Facts this page owns can be edited here, and new ones added at the bottom. Shared facts belong to another
        page and are edited there. Values change on the
        <a href="/admin/review.php" class="underline hover:text-accent">review page</a> or through the
        importer, so every change is recorded in the fact's history.
    </p>

    <?php if (empty($facts)): ?>
    <p class="fact-card p-6 text-secondary">No facts linked to this page yet.</p>
    <?php endif; ?>

    <div class="space-y-5">
        <?php foreach ($facts as $fact): ?>
        <?php
        $factId = (int) $fact['id'];
        $isOwned = (int) $fact['is_primary'] === 1;
        // Put back what was typed if this fact's save just failed.
        $factForm = $oldFactId === $factId ? $old : $fact;
        ?>
        <div id="fact-<?= $factId ?>" class="fact-card p-5 scroll-mt-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2 mb-3">
                <p class="fact-value font-display text-2xl font-semibold"><?= e(formatFactValue($fact)) ?></p>
                <p class="font-mono text-xs">
                    <?php // Only published facts show on the public page. ?>
                    <?php if ($fact['status'] !== 'published'): ?>
                    <span class="px-2 py-1 rounded bg-error/10 text-error"><?= e($fact['status']) ?>, not shown</span>
                    <?php endif; ?>
                    <?php if (isRegionalFact($fact)): ?>
                    <span class="px-2 py-1 rounded bg-secondary/10 text-secondary"><?= e($fact['jurisdiction_name']) ?></span>
                    <?php endif; ?>
                    <span class="opacity-50 ml-1"><?= e($fact['fact_key']) ?></span>
                </p>
            </div>

            <?php if ($isOwned): ?>
            <form method="post" class="space-y-3">
                <?= adminCsrfField() ?>
                <input type="hidden" name="action" value="save_fact">
                <input type="hidden" name="fact_id" value="<?= $factId ?>">

                <div>
                    <label for="label-<?= $factId ?>" class="block text-sm font-semibold mb-1">Label</label>
                    <input type="text" id="label-<?= $factId ?>" name="label" required maxlength="255"
                        class="input input-bordered input-sm w-full" value="<?= e((string) $factForm['label']) ?>">
                </div>

                <div>
                    <label for="context-<?= $factId ?>" class="block text-sm font-semibold mb-1">Context</label>
                    <input type="text" id="context-<?= $factId ?>" name="context"
                        class="input input-bordered input-sm w-full" value="<?= e((string) ($factForm['context'] ?? '')) ?>"
                        placeholder="One line under the figure, e.g. who it applies to">
                    <?php if ((int) $fact['linked_pages'] > 1): ?>
                    <?php // Shared pages show this context unless they have their own override. ?>
                    <p class="text-xs opacity-60 mt-1">
                        Also shown on <?= (int) $fact['linked_pages'] - 1 ?> other
                        page<?= (int) $fact['linked_pages'] - 1 === 1 ? '' : 's' ?>, which use this context
                        unless they have their own.
                    </p>
                    <?php endif; ?>
                </div>

                <div class="flex flex-wrap items-end gap-4">
                    <div>
                        <label for="review-<?= $factId ?>" class="block text-sm font-semibold mb-1">Review every</label>
                        <span class="flex items-center gap-2">
                            <input type="number" id="review-<?= $factId ?>" name="review_frequency_days"
                                min="1" max="3650" step="1" required class="input input-bordered input-sm w-24"
                                value="<?= e((string) $factForm['review_frequency_days']) ?>">
                            <span class="text-sm opacity-70">days</span>
                        </span>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary">Save fact</button>
                </div>
            </form>
            <?php else: ?>
            <?php // Shared fact: read-only here, with a link to the page that owns it. ?>
            <p class="text-sm"><?= e($fact['label']) ?></p>
            <p class="text-sm opacity-80">
                <?= e((string) ($fact['context_override'] ?? $fact['context'] ?? '')) ?>
                <?php if ($fact['context_override'] !== null): ?>
                <span class="font-mono text-xs opacity-60">(this page's own context)</span>
                <?php endif; ?>
            </p>
            <p class="text-xs mt-2">
                Shared from
                <a href="/admin/subject.php?id=<?= (int) $fact['owner_id'] ?>#fact-<?= $factId ?>"
                    class="text-secondary underline hover:text-accent"><?= e($fact['owner_name']) ?></a>.
                Edit it there.
            </p>
            <?php endif; ?>

            <p class="text-xs opacity-60 mt-3">
                <?php if ($fact['source_url']): ?>
                <a href="<?= e($fact['source_url']) ?>" target="_blank" rel="noopener" class="underline hover:text-accent">
                    <?= e($fact['source_name'] ?? 'Source') ?> &#8599;
                </a>
                <?php else: ?>
                No source
                <?php endif; ?>
                &middot; checked <?= date('j M Y', strtotime($fact['last_verified_at'] ?? $fact['last_updated'])) ?>
            </p>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<?php // ---------- Add a fact (folded away unless a previous attempt failed) ---------- ?>
<section class="mb-14">
<details id="add-fact" class="fact-card p-5 scroll-mt-6" <?= $addFactFailed ? 'open' : '' ?>>
    <summary class="font-semibold cursor-pointer">Add a fact to this page</summary>

    <p class="text-sm opacity-80 mt-3 max-w-xl">
        The new fact belongs to this page and goes to the end of it. Facts from an allowlisted
        (official) source can publish straight away. Anything else is saved as a draft.
    </p>

    <form method="post" class="space-y-4 mt-4 max-w-2xl">
        <?= adminCsrfField() ?>
        <input type="hidden" name="action" value="add_fact">

        <div>
            <label for="nf-label" class="block text-sm font-semibold mb-1">Label</label>
            <input type="text" id="nf-label" name="label" required maxlength="255"
                class="input input-bordered input-sm w-full" value="<?= e((string) $newFact['label']) ?>"
                placeholder="e.g. Junior ISA annual allowance">
        </div>

        <div>
            <label for="nf-key" class="block text-sm font-semibold mb-1">Key</label>
            <input type="text" id="nf-key" name="fact_key" required maxlength="100" pattern="[a-z0-9]+(_[a-z0-9]+)*"
                class="input input-bordered input-sm w-full font-mono" value="<?= e((string) $newFact['fact_key']) ?>"
                placeholder="junior_isa_allowance">
            <p class="text-xs opacity-60 mt-1">
                The fact's permanent id, used by the importer, worked examples and checks. Filled in from
                the label. Must be unique, and can't be changed later.
            </p>
        </div>

        <div class="flex flex-wrap gap-4">
            <div>
                <label for="nf-value" class="block text-sm font-semibold mb-1">Value</label>
                <input type="text" id="nf-value" name="value" required
                    class="input input-bordered input-sm w-40" value="<?= e((string) $newFact['value']) ?>"
                    placeholder="9,000">
            </div>
            <div>
                <label for="nf-unit" class="block text-sm font-semibold mb-1">Unit</label>
                <?php // Suggestions only. Any short unit can be typed. ?>
                <input type="text" id="nf-unit" name="unit" maxlength="20" list="unit-suggestions"
                    class="input input-bordered input-sm w-28" value="<?= e((string) $newFact['unit']) ?>"
                    placeholder="£">
                <datalist id="unit-suggestions">
                    <option value="£"><option value="%"><option value="years"><option value="weeks"><option value="days">
                </datalist>
            </div>
            <div>
                <label for="nf-jurisdiction" class="block text-sm font-semibold mb-1">Applies to</label>
                <select id="nf-jurisdiction" name="jurisdiction_id" class="select select-bordered select-sm">
                    <?php foreach ($jurisdictionOptions as $option): ?>
                    <option value="<?= (int) $option['id'] ?>"
                        <?= (int) $newFact['jurisdiction_id'] === (int) $option['id'] ? 'selected' : '' ?>>
                        <?= e($option['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <p class="text-xs opacity-60 -mt-2">
            Enter the number as the source shows it. The unit is added on display, so 9000 with £ shows as £9,000.
        </p>

        <div>
            <label for="nf-context" class="block text-sm font-semibold mb-1">Context <span class="font-normal opacity-60">(optional)</span></label>
            <input type="text" id="nf-context" name="context"
                class="input input-bordered input-sm w-full" value="<?= e((string) $newFact['context']) ?>"
                placeholder="One line under the figure, e.g. who it applies to">
        </div>

        <div class="flex flex-wrap gap-4">
            <div class="flex-1 min-w-64">
                <label for="nf-source" class="block text-sm font-semibold mb-1">Source page</label>
                <input type="url" id="nf-source" name="source_url"
                    class="input input-bordered input-sm w-full" value="<?= e((string) $newFact['source_url']) ?>"
                    placeholder="https://www.gov.uk/...">
            </div>
            <div>
                <label for="nf-publisher" class="block text-sm font-semibold mb-1">Publisher</label>
                <input type="text" id="nf-publisher" name="source_publisher" maxlength="255"
                    class="input input-bordered input-sm w-40" value="<?= e((string) $newFact['source_publisher']) ?>"
                    placeholder="GOV.UK">
            </div>
        </div>
        <p class="text-xs opacity-60 -mt-2">
            Use the exact page the figure appears on, since the weekly check looks for it there. Publisher
            is only used if this page hasn't been used as a source before.
        </p>

        <div class="flex flex-wrap gap-4 items-end">
            <div>
                <label for="nf-effective" class="block text-sm font-semibold mb-1">In effect from <span class="font-normal opacity-60">(optional)</span></label>
                <?php // Future dates are refused: figures go live the moment they're saved. ?>
                <input type="date" id="nf-effective" name="effective_from" max="<?= date('Y-m-d') ?>"
                    class="input input-bordered input-sm" value="<?= e((string) $newFact['effective_from']) ?>">
            </div>
            <div>
                <label for="nf-tax-year" class="block text-sm font-semibold mb-1">Tax year <span class="font-normal opacity-60">(optional)</span></label>
                <input type="text" id="nf-tax-year" name="tax_year" pattern="\d{4}/\d{2}"
                    class="input input-bordered input-sm w-28" value="<?= e((string) $newFact['tax_year']) ?>"
                    placeholder="2026/27">
            </div>
            <div>
                <label for="nf-review" class="block text-sm font-semibold mb-1">Review every</label>
                <span class="flex items-center gap-2">
                    <input type="number" id="nf-review" name="review_frequency_days" min="1" max="3650" step="1" required
                        class="input input-bordered input-sm w-24" value="<?= e((string) $newFact['review_frequency_days']) ?>">
                    <span class="text-sm opacity-70">days</span>
                </span>
            </div>
        </div>

        <fieldset>
            <legend class="text-sm font-semibold mb-2">Status</legend>
            <?php foreach (NEW_FACT_STATUSES as $status): ?>
            <label class="inline-flex items-center gap-2 mr-6 cursor-pointer">
                <input type="radio" name="status" value="<?= e($status) ?>" class="radio radio-sm"
                    <?= $newFact['status'] === $status ? 'checked' : '' ?>>
                <span class="font-mono text-sm"><?= e($status) ?></span>
            </label>
            <?php endforeach; ?>
            <?php if ($subject['status'] !== 'published'): ?>
            <?php // A published fact on a draft page still isn't public until the page is published. ?>
            <p class="text-xs opacity-60 mt-2">This page is <?= e($subject['status']) ?>, so its facts aren't public yet either way.</p>
            <?php endif; ?>
        </fieldset>

        <button type="submit" class="btn btn-sm btn-primary">Add fact</button>
    </form>
</details>
</section>

<script>
    // Fill the key from the label as you type (e.g. "Junior ISA annual
    // allowance" becomes junior_isa_annual_allowance), until the key is
    // edited by hand.
    (function () {
        var label = document.getElementById('nf-label');
        var key = document.getElementById('nf-key');
        var edited = key.value !== '';

        key.addEventListener('input', function () { edited = key.value !== ''; });
        label.addEventListener('input', function () {
            if (edited) return;
            key.value = label.value.toLowerCase()
                .replace(/£/g, '')
                .replace(/&/g, ' and ')
                .replace(/%/g, ' percent ')
                .replace(/[^a-z0-9]+/g, '_')
                .replace(/^_+|_+$/g, '');
        });
    })();
</script>

<script>
    // "Draft this page": show that it's working, since the AI can take up
    // to a minute. What's typed in the boxes is kept either way.
    (function () {
        var button = document.getElementById('draft-button');
        if (!button) return;
        button.addEventListener('click', function () {
            button.textContent = 'Drafting… (up to a minute)';
        });
    })();

    // "Check with AI": show that it's working while the sources are read.
    (function () {
        var button = document.getElementById('check-button');
        if (!button) return;
        button.addEventListener('click', function () {
            button.textContent = 'Checking… (up to two minutes)';
        });
    })();

    // "Use draft": copy a field's stored draft into its box, asking first
    // if the box already has something in it. Nothing is saved.
    (function () {
        var drafts = <?= json_encode($draft ? array_intersect_key($draft, array_flip(DRAFT_FIELDS)) : new stdClass(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

        document.querySelectorAll('[data-use-draft]').forEach(function (button) {
            button.addEventListener('click', function () {
                var field = document.getElementById(button.dataset.useDraft);
                if (field.value.trim() !== '' && !confirm('Replace what\'s in this box with the draft? Nothing is saved until you click Save page details.')) {
                    return;
                }
                field.value = drafts[button.dataset.useDraft];
                field.dispatchEvent(new Event('input')); // updates the count and preview
                field.focus();
            });
        });
    })();
</script>

<script>
    // This page's placeholders and what each shows now (token => value),
    // so counts and previews below match the public page.
    var PAGE_PLACEHOLDERS = <?= json_encode($placeholderValues, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

    // Same pattern as PLACEHOLDER_PATTERN in includes/placeholders.php.
    // Placeholders this page doesn't list are left as typed.
    function fillPagePlaceholders(text) {
        return text.replace(/\{[^{}\n]{1,100}\}/g, function (token) {
            return Object.prototype.hasOwnProperty.call(PAGE_PLACEHOLDERS, token) ? PAGE_PLACEHOLDERS[token] : token;
        });
    }

    // Live counts under the text fields, with placeholders filled in.
    // data-limit / data-min turn the count red outside the house style's
    // range; data-words counts words instead (the explanation);
    // data-preview shows the filled-in text when it uses placeholders.
    // Array.from counts characters, so £ counts as one, as on the server.
    document.querySelectorAll('[data-counter]').forEach(function (field) {
        var output = document.getElementById(field.dataset.counter);
        var limit = field.dataset.limit ? parseInt(field.dataset.limit, 10) : null;
        var min = field.dataset.min ? parseInt(field.dataset.min, 10) : null;
        var preview = field.dataset.preview ? document.getElementById(field.dataset.preview) : null;

        function update() {
            var raw = field.value.trim();
            var filled = fillPagePlaceholders(raw);
            var hasPlaceholders = filled !== raw;

            if (field.dataset.words) {
                var words = filled === '' ? 0 : filled.split(/\s+/).length;
                output.textContent = words + ' words (house style: usually 300 to 600)';
            } else {
                var length = Array.from(filled).length;
                var range = min && limit ? min + ' to ' + limit : (limit ? limit + ' or fewer' : null);
                output.textContent = length + ' characters' + (hasPlaceholders ? ' with figures filled in' : '')
                    + (range ? ' (aim for ' + range + ')' : '');
                output.classList.toggle('text-error',
                    raw !== '' && ((limit !== null && length > limit) || (min !== null && length < min)));
            }

            if (preview) {
                preview.textContent = hasPlaceholders ? 'Shows as: ' + filled : '';
            }
        }

        field.addEventListener('input', update);
        update();
    });

    // Placeholder buttons: insert at the cursor in whichever of the four
    // fields was used last (the explanation if none has been yet).
    (function () {
        var fields = ['intro', 'explanation', 'meta_title', 'meta_description']
            .map(function (id) { return document.getElementById(id); });
        var lastField = document.getElementById('explanation');

        fields.forEach(function (field) {
            field.addEventListener('focus', function () { lastField = field; });
        });

        document.querySelectorAll('[data-insert]').forEach(function (button) {
            button.addEventListener('click', function () {
                var token = button.dataset.insert;
                var start = lastField.selectionStart ?? lastField.value.length;
                var end = lastField.selectionEnd ?? start;
                lastField.value = lastField.value.slice(0, start) + token + lastField.value.slice(end);
                lastField.focus();
                lastField.setSelectionRange(start + token.length, start + token.length);
                // Updates the count and preview.
                lastField.dispatchEvent(new Event('input'));
            });
        });
    })();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
