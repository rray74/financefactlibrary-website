<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/fact-writer.php';
require_once __DIR__ . '/../../includes/admin-auth.php';
require_once __DIR__ . '/../../includes/admin-queries.php';
require_once __DIR__ . '/../../includes/ai-drafts.php'; // AI drafts of the four text fields
require_once __DIR__ . '/../../includes/ai-polish.php'; // the polish loop and its background queue
require_once __DIR__ . '/../../includes/text-checks.php'; // quick checks and the AI check of the text
require_once __DIR__ . '/../../includes/ai-fixes.php';     // "Propose fix": an AI fix for one finding of the check
require_once __DIR__ . '/../../includes/review-flags.php'; // why this page needs reviewing, if it does
require_once __DIR__ . '/../../includes/lessons.php';       // keeps drafts with the saved text, to learn from the edits

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
 * Propose fix (includes/ai-fixes.php): each finding of the AI check has a
 * button that asks the AI for the smallest change that fixes just that finding.
 * The proposal (old words, new words, why) is shown under the finding, with
 * "Apply to the box", which changes that one passage in the text box. Nothing is
 * saved until you save the page. Proposals are kept in the session, per page and
 * finding number, and cleared when the AI check is run again or the page is saved.
 *
 * Checks (includes/text-checks.php): quick house-style checks run on the
 * saved text whenever the page opens. "Check with AI" reads whatever is in
 * the boxes against the page's figures and source pages, without saving,
 * and the result stays on the page, marked if the text has changed since.
 *
 * Learning (includes/lessons.php): when a page is saved with an explanation
 * while it still has an AI draft, the draft and the saved text are kept
 * together, so the lessons page can suggest what the AI should learn from
 * how it was edited. The AI draft and the AI check are both given the
 * approved lessons.
 *
 * Review flags (includes/review-flags.php): if the page needs reviewing,
 * the reasons are shown at the top of Page details. Saving the page
 * details marks the text as reviewed, which clears some of them.
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
// This page's latest polish job (migration 020), or NULL.
$polishJob = getPolishJob($subjectId);

// This page's latest AI text check (subject_text_checks), or NULL.
$textCheck = getSubjectTextCheck($subjectId);

// Fixes proposed for that check's findings, by finding number (1 = the first), kept in
// the session by the "Propose fix" buttons. See includes/ai-fixes.php.
$fixProposals = $_SESSION['fix_proposals'][$subjectId] ?? [];

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

            // Reading the source pages and the AI together can take a couple of minutes.
            set_time_limit(300);
            // The result is saved afterwards, so keep the connection open meanwhile.
            keepDbConnectionOpenForAi($pdo);

            $typed = [];
            foreach (array_keys(TEXT_FIELD_LABELS) as $field) {
                $typed[$field] = (string) ($_POST[$field] ?? '');
            }
            $result = aiTextCheck($subject, $facts, $typed);
            saveSubjectTextCheck($pdo, $subjectId, $result, textCheckHash($typed));
            // The old finding numbers no longer mean anything, so neither do proposals made for them.
            unset($_SESSION['fix_proposals'][$subjectId]);
            rememberAdminInput($_POST);

            $found = count($result['findings']);
            setAdminFlash($found ? 'info' : 'success', ($found
                ? "AI check: $found thing" . ($found === 1 ? '' : 's') . ' to look at, listed under Page details.'
                : 'AI check: no problems found.') . ' Nothing has been saved.');

        } elseif ($action === 'save_subject' && !empty($_POST['queue_polish'])) {
            // "Draft and polish in the background": add the page to the polish
            // queue (migration 020). A cron job (scripts/run-polish-jobs.php)
            // drafts it, runs the AI check and rewrite loop, stores the result
            // as the page's draft and emails. Nothing is drafted here, so this
            // returns at once; what is typed comes back unchanged.
            $returnAnchor = '#subject-details';
            $jobId = queuePolishJob($pdo, $subjectId);
            rememberAdminInput($_POST);

            if ($jobId === null) {
                setAdminFlash('info', 'This page is already waiting in the polish queue, or being polished now.');
            } else {
                setAdminFlash('info', 'Queued. The draft will be written and polished in the background, which takes about five to ten minutes. '
                    . 'You will get an email, and the draft appears here. You can leave this page.');
            }

        } elseif ($action === 'save_subject' && isset($_POST['propose_fix'])) {
            // "Propose fix" beside one finding of the AI check. The button is inside
            // the page details form, so everything typed comes back too and is put
            // back in the boxes unchanged. Nothing is saved or changed: the proposal
            // is shown under the finding, and "Apply to the box" (JavaScript) makes the
            // change in the text box. The number is the finding's place in the check
            // plus one, so that the first finding's value is not 0 (which isset()
            // accepts but which reads as empty elsewhere).
            $returnAnchor = '#text-check';

            // Reading the source pages and the AI together can take a minute or two.
            set_time_limit(300);
            keepDbConnectionOpenForAi($pdo);

            $typed = [];
            foreach (array_keys(TEXT_FIELD_LABELS) as $field) {
                $typed[$field] = (string) ($_POST[$field] ?? '');
            }
            $number = (int) $_POST['propose_fix'];
            $finding = $textCheck['findings'][$number - 1] ?? null;
            if (!$finding) {
                throw new RuntimeException('That finding is not in the latest AI check any more. Run Check with AI again.');
            }

            $proposal = proposeFix($pdo, $subject, $facts, $typed, $finding);
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            $_SESSION['fix_proposals'][$subjectId][$number] = $proposal;
            rememberAdminInput($_POST);

            setAdminFlash('info', ($proposal['ok'] && $proposal['action'] !== 'none')
                ? 'Fix proposed under the finding. Nothing has changed: click "Apply to the box" to use it, then save the page as usual.'
                : 'No change proposed: ' . $proposal['reason']);

        } elseif ($action === 'save_subject' && !empty($_POST['draft_page'])) {
            // "Draft this page": the button is inside the page details form,
            // so everything typed comes back too, and is put back in the
            // boxes unchanged (nothing typed is lost or saved). The new draft
            // replaces any stored one and appears under each field.
            $returnAnchor = '#subject-details';

            // Reading the source pages and writing the draft can take a couple of minutes.
            set_time_limit(300);
            // The draft is saved afterwards, so keep the connection open meanwhile.
            keepDbConnectionOpenForAi($pdo);

            // One automatic rewrite round, and only if drafting left time for it
            // (the defaults): a web request has limited time.
            $newDraft = draftSubjectPage($pdo, $subject, $facts);
            saveSubjectDraft($pdo, $subjectId, $newDraft, 'editor');
            rememberAdminInput($_POST);

            setAdminFlash('info', 'New AI draft below each field. Click "Use draft" to copy one into its box. '
                . 'Nothing on the page has changed.');

        } elseif ($action === 'save_subject') {
            $returnAnchor = '#subject-details';
            // Proposals were made for the text as it was; once it is saved they are done with.
            unset($_SESSION['fix_proposals'][$subjectId]);
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
            // Saving is the review: it clears the "figure changed" and
            // "yearly review" flags (see includes/review-flags.php).
            markSubjectTextReviewed($pdo, $subjectId);

            // Once the page has an explanation, the draft has done its job.
            // (A page saved with only an intro, say, keeps its draft.) Before
            // it goes, it's kept with the text it was saved as, so the lessons
            // page can learn from how it was edited. This is best-effort: a
            // problem here must not make the save look as if it failed.
            $editRecorded = false;
            if (cleanEditorText($_POST['explanation'] ?? null) !== null) {
                $draftToKeep = getSubjectDraft($subjectId);
                if ($draftToKeep) {
                    try {
                        $savedRow = getAdminSubject($subjectId);
                        recordDraftEdit($pdo, $subjectId, $draftToKeep, [
                            'intro'            => $savedRow['intro'],
                            'explanation'      => $savedRow['explanation'],
                            'meta_title'       => $savedRow['meta_title'],
                            'meta_description' => $savedRow['meta_description'],
                        ]);
                        $editRecorded = true;
                    } catch (Throwable $e) {
                        // Not recorded (for example migration 017 isn't applied yet).
                    }
                }
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
                . ($toCheck ? " $toCheck thing" . ($toCheck === 1 ? '' : 's') . ' to check, listed under Page details.' : '')
                . ($editRecorded ? ' Your changes to the draft are kept, for the lessons page to learn from.' : ''));

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
                // How the weekly source check finds this figure (see updateFactDetails()).
                'source_url'            => $_POST['source_url'] ?? null,
                'source_publisher'      => $_POST['source_publisher'] ?? null,
                'source_match_text'     => $_POST['source_match_text'] ?? null,
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

// Why this page is on the dashboard's "Needs reviewing" list, if it is.
$reviewFlags = getReviewFlagsForSubject($subjectId);

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

    <?php if ($reviewFlags): ?>
    <?php // The same reasons the dashboard's "Needs reviewing" list shows. ?>
    <div class="alert alert-warning mb-6 text-sm max-w-2xl">
        <span>
            <strong>This page needs reviewing:</strong>
            <?php foreach ($reviewFlags as $flag): ?>
            <br><strong><?= e(REVIEW_FLAG_LABELS[$flag['kind']]) ?>.</strong> <?= e($flag['detail']) ?>
            <?php endforeach; ?>
            <br><span class="opacity-80">Saving the page details marks the text as reviewed. AI check and placeholder
            problems clear once they're fixed and checked again.</span>
        </span>
    </div>
    <?php endif; ?>

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

    <form method="post" id="details-form" class="space-y-6 max-w-2xl">
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
                <div class="flex flex-wrap gap-2">
                    <?php // Submits this form with queue_polish set: nothing is drafted here, a job is queued. ?>
                    <button type="submit" name="queue_polish" value="1" formnovalidate
                        class="btn btn-xs btn-outline" id="polish-button">Draft and polish in the background</button>
                    <?php // Submits this form with draft_page set: what's typed comes back unchanged. ?>
                    <button type="submit" name="draft_page" value="1" formnovalidate
                        class="btn btn-xs btn-outline" id="draft-button"><?= $draft ? 'Redraft this page' : 'Draft this page' ?></button>
                </div>
                <?php endif; ?>
            </div>
            <p class="text-xs opacity-70 mt-1">
                <?= $draft
                    ? 'Shown under each field. "Use draft" copies it into the box to rewrite in your own words. Nothing changes until you save.'
                    : 'Pages without an explanation are drafted overnight, most-searched first. Or draft this one now: it reads the sources, writes the draft and fixes what it can, which takes two to four minutes.' ?>
            </p>
            <?php // Where this page's polish job has got to (migration 020). A finished job needs no line: its draft is below. ?>
            <?php if ($polishJob && in_array($polishJob['status'], ['queued', 'running'], true)): ?>
            <p class="text-xs mt-2">
                <strong>Polish:</strong>
                <?= $polishJob['status'] === 'queued'
                    ? 'waiting in the queue since ' . e(date('H:i', strtotime($polishJob['requested_at']))) . '. A cron job starts it within a minute or two; if nothing has started after ten minutes, the cron job for scripts/run-polish-jobs.php may not be set up.'
                    : 'being drafted and polished now (started ' . e(date('H:i', strtotime($polishJob['started_at']))) . '). It takes about five to ten minutes; reload to see it.' ?>
            </p>
            <?php elseif ($polishJob && $polishJob['status'] === 'failed'): ?>
            <p class="text-xs mt-2"><strong>Polish failed</strong> (<?= e(date('j M, H:i', strtotime($polishJob['finished_at']))) ?>): <?= e((string) $polishJob['note']) ?></p>
            <?php endif; ?>
            <?php // What the automatic rewrite did to this draft (migration 019), if anything. ?>
            <?php if ($draft && !empty($draft['rewrite_note'])): ?>
            <?php // The note, then (after " | Details: ") how many tokens the writing took, in small type. ?>
            <?php [$rewriteSummary, $rewriteDetails] = array_pad(explode(' | Details: ', (string) $draft['rewrite_note'], 2), 2, ''); ?>
            <?php if ($rewriteSummary !== ''): ?>
            <p class="text-xs mt-2"><strong>Automatic rewrite:</strong> <?= e($rewriteSummary) ?></p>
            <?php endif; ?>
            <?php if ($rewriteDetails !== ''): ?>
            <p class="text-xs mt-1 opacity-60"><?= e($rewriteDetails) ?></p>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($draft && $draft['problems']): ?>
            <?php // What is still wrong with the draft: near-copies, length, numbers, placeholders. ?>
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
                Reads the text in the boxes, with figures filled in, against this page's figures, their
                source pages, the house style and the <a href="/admin/lessons.php" class="underline hover:text-accent">lessons
                learned so far</a>. Saved or not, nothing is changed. Up to two minutes.
            </p>

            <?php if ($textCheck): ?>
            <?php if (!$textCheck['findings']): ?>
            <p class="mt-3 text-secondary">No problems found.</p>
            <?php else: ?>
            <ul class="mt-3 space-y-3">
                <?php foreach ($textCheck['findings'] as $findingIndex => $finding): ?>
                <?php $findingNumber = $findingIndex + 1; $proposal = $fixProposals[$findingNumber] ?? null; ?>
                <li class="border-l-2 border-error/50 pl-3">
                    <span class="font-mono text-xs uppercase tracking-wide text-error">
                        <?= e(TEXT_CHECK_KINDS[$finding['kind']] ?? $finding['kind']) ?>
                        &middot; <?= e(TEXT_FIELD_LABELS[$finding['field']] ?? $finding['field']) ?>
                    </span>
                    <span class="block italic opacity-80">&ldquo;<?= e($finding['quote']) ?>&rdquo;</span>
                    <span class="block"><?= e($finding['problem']) ?></span>

                    <?php // Propose fix: submits this form with the finding's number, as Check with AI does. ?>
                    <?php if (aiProposalsAvailable()): ?>
                    <span class="block mt-2">
                        <button type="submit" name="propose_fix" value="<?= (int) $findingNumber ?>" formnovalidate
                            class="btn btn-xs btn-outline" data-propose-fix>Propose fix</button>
                        <span class="text-xs opacity-60 ml-1">asks the AI for the smallest change that fixes this one; nothing changes until you apply it</span>
                    </span>
                    <?php endif; ?>

                    <?php if ($proposal): ?>
                    <?php
                    // The proposal's changes. A proposal made before changes could span fields has
                    // one change in 'field', 'old' and 'new': read that as a list of one.
                    $changes = $proposal['changes'] ?? (($proposal['old'] ?? '') !== ''
                        ? [['field' => $proposal['field'], 'old' => $proposal['old'], 'new' => $proposal['new'] ?? '']]
                        : []);
                    ?>
                    <div class="mt-2 rounded border border-base-300 p-3 text-xs space-y-2">
                        <?php if ($proposal['ok'] && $changes): ?>
                        <p class="font-semibold">Proposed change<?= count($changes) === 1 ? '' : 's (' . count($changes) . ', applied together)' ?></p>
                        <?php foreach ($changes as $change): ?>
                        <div class="space-y-1">
                            <p class="opacity-60 uppercase tracking-wide">
                                <?= e(TEXT_FIELD_LABELS[$change['field']] ?? $change['field']) ?>:
                                <?= $change['new'] === '' ? 'delete this text' : 'replace this text' ?>
                            </p>
                            <p class="font-mono whitespace-pre-wrap line-through opacity-80"><?= e($change['old']) ?></p>
                            <?php if ($change['new'] !== ''): ?>
                            <p class="opacity-60 uppercase tracking-wide">Instead</p>
                            <p class="font-mono whitespace-pre-wrap"><?= e($change['new']) ?></p>
                            <?php if (strpos($change['new'], '{') !== false): ?>
                            <p class="opacity-70">Shows as: <?= e($fillPreview($change['new'])) ?></p>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php if ($proposal['reason'] !== ''): ?>
                        <p><?= e($proposal['reason']) ?></p>
                        <?php endif; ?>
                        <p>
                            <?php // The page's JavaScript makes the changes in the boxes, all or none; each passage must still be in its box exactly once. ?>
                            <button type="button" class="btn btn-xs btn-primary" data-fix-apply
                                data-fix-changes="<?= e(json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">Apply to the box<?= count($changes) === 1 ? '' : 'es' ?></button>
                            <span class="opacity-70 ml-1" data-fix-status>Nothing is saved until you save the page.</span>
                        </p>
                        <?php else: ?>
                        <p><strong>No change proposed.</strong> <?= e($proposal['reason']) ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
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
        <?php // Saving is how a review is recorded (subjects.text_reviewed_at). ?>
        <p class="text-xs opacity-60 mt-2">Saving also marks this page's text as reviewed today.</p>
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
                </div>

                <?php // How the weekly source check (scripts/check-sources.php) finds this figure. ?>
                <details class="text-sm" <?= $oldFactId === $factId ? 'open' : '' ?>>
                    <summary class="cursor-pointer text-secondary">Source and how it's checked</summary>
                    <div class="space-y-3 mt-3">
                        <div>
                            <label for="source-<?= $factId ?>" class="block text-sm font-semibold mb-1">Source page</label>
                            <input type="url" id="source-<?= $factId ?>" name="source_url"
                                class="input input-bordered input-sm w-full font-mono"
                                value="<?= e((string) ($factForm['source_url'] ?? '')) ?>">
                            <p class="text-xs opacity-60 mt-1">
                                The page the weekly check reads. Change it if the figure has moved to another page.
                            </p>
                        </div>
                        <div>
                            <label for="publisher-<?= $factId ?>" class="block text-sm font-semibold mb-1">Publisher</label>
                            <input type="text" id="publisher-<?= $factId ?>" name="source_publisher" maxlength="255"
                                class="input input-bordered input-sm w-full"
                                value="<?= e((string) ($factForm['source_publisher'] ?? $factForm['source_name'] ?? '')) ?>">
                            <p class="text-xs opacity-60 mt-1">Only used when the source page is new to the site, e.g. GOV.UK — DWP.</p>
                        </div>
                        <div>
                            <label for="match-<?= $factId ?>" class="block text-sm font-semibold mb-1">
                                Text to look for <span class="font-normal opacity-60">(optional)</span>
                            </label>
                            <input type="text" id="match-<?= $factId ?>" name="source_match_text" maxlength="255"
                                class="input input-bordered input-sm w-full"
                                value="<?= e((string) ($factForm['source_match_text'] ?? '')) ?>">
                            <p class="text-xs opacity-60 mt-1">
                                Leave blank to look for the figure itself. Fill in for figures the source writes in
                                words, copying its exact wording, e.g. "one week's pay for each full year".
                            </p>
                        </div>
                    </div>
                </details>

                <button type="submit" class="btn btn-sm btn-primary">Save fact</button>
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
    // ---- Confirmation before the AI buttons ----
    // Draft / Redraft and Check with AI each take minutes, and Redraft replaces the
    // drafts under the fields, so each asks first, in a box that DEFAULTS TO CANCEL:
    // Enter and Escape cancel, and the action has to be clicked on purpose. The box
    // also says if the four text boxes hold changes that aren't saved yet.
    (function () {
        var ids = ['intro', 'explanation', 'meta_title', 'meta_description'];
        var saved = <?= json_encode([
            'intro'            => (string) ($subject['intro'] ?? ''),
            'explanation'      => (string) ($subject['explanation'] ?? ''),
            'meta_title'       => (string) ($subject['meta_title'] ?? ''),
            'meta_description' => (string) ($subject['meta_description'] ?? ''),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
        var flat = function (text) { return String(text || '').replace(/\s+/g, ' ').trim(); };

        // True if any of the four boxes differs from the text saved on the page.
        function hasUnsavedChanges() {
            return ids.some(function (id) {
                var box = document.getElementById(id);
                return box && flat(box.value) !== flat(saved[id]);
            });
        }

        // Ask in a box. Resolves true only if the action button is clicked.
        // lines: paragraphs of plain text. warning: an extra, highlighted paragraph (or '').
        function ask(title, lines, warning, actionLabel) {
            return new Promise(function (resolve) {
                if (typeof HTMLDialogElement !== 'function') { // a very old browser
                    resolve(window.confirm(title + '\n\n' + lines.concat(warning ? [warning] : []).join('\n\n')));
                    return;
                }
                if (!document.getElementById('ffl-ask-style')) {
                    var style = document.createElement('style');
                    style.id = 'ffl-ask-style';
                    style.textContent = 'dialog.ffl-ask::backdrop { background: rgba(27, 42, 74, 0.55); }';
                    document.head.appendChild(style);
                }

                var dialog = document.createElement('dialog');
                dialog.className = 'ffl-ask';
                dialog.style.cssText = 'width: min(32rem, calc(100% - 2rem)); padding: 1.5rem; border: 2px solid #1b2a4a; '
                    + 'background: #f7f5f0; color: #1b2a4a; box-shadow: 6px 6px 0 rgba(27, 42, 74, 0.25);';

                var heading = document.createElement('h3');
                heading.textContent = title;
                heading.style.cssText = 'font-weight: 700; font-size: 1.125rem; margin: 0 0 0.75rem;';
                dialog.appendChild(heading);

                lines.forEach(function (line) {
                    var p = document.createElement('p');
                    p.textContent = line;
                    p.style.cssText = 'margin: 0 0 0.75rem;';
                    dialog.appendChild(p);
                });
                if (warning) {
                    var note = document.createElement('p');
                    note.textContent = warning;
                    note.style.cssText = 'margin: 0 0 0.75rem; padding: 0.5rem 0.75rem; border-left: 4px solid #b3452c; background: #ece6da; font-weight: 600;';
                    dialog.appendChild(note);
                }

                var row = document.createElement('div');
                row.style.cssText = 'display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1rem;';
                var cancel = document.createElement('button');
                cancel.type = 'button';
                cancel.className = 'btn btn-outline';
                cancel.textContent = 'Cancel';
                var go = document.createElement('button');
                go.type = 'button';
                go.className = 'btn btn-primary';
                go.textContent = actionLabel;
                row.appendChild(cancel);
                row.appendChild(go);
                dialog.appendChild(row);

                var answer = false;
                go.addEventListener('click', function () { answer = true; dialog.close(); });
                cancel.addEventListener('click', function () { dialog.close(); });
                // Escape closes the box too, which counts as Cancel.
                dialog.addEventListener('close', function () { dialog.remove(); resolve(answer); });

                document.body.appendChild(dialog);
                dialog.showModal();
                cancel.focus(); // the safe answer is the default
            });
        }
        window.fflAsk = ask;
        window.fflHasUnsavedChanges = hasUnsavedChanges;

        var UNSAVED = 'You have changes in the boxes that are not saved. They are kept here and in this browser, '
            + 'but they are not on the live page yet.';

        // Put the question in front of an AI button. After a Yes the button is clicked
        // again for real, so the form is sent with that button as its submitter.
        function guard(buttonId, question, workingLabel) {
            var button = document.getElementById(buttonId);
            if (!button) return;
            var confirmed = false;
            button.addEventListener('click', function (event) {
                if (confirmed) { confirmed = false; return; } // second pass, after Yes
                event.preventDefault();
                var q = question(button);
                ask(q.title, q.lines, hasUnsavedChanges() ? UNSAVED : '', q.action).then(function (yes) {
                    if (!yes) return;
                    if (window.fflRememberNow) window.fflRememberNow(); // keep a copy of what's typed
                    button.textContent = workingLabel;
                    confirmed = true;
                    button.click();
                });
            });
        }

        guard('draft-button', function (button) {
            var again = /^Redraft/i.test(button.textContent.trim());
            return {
                title: again ? 'Redraft this page?' : 'Draft this page with the AI?',
                lines: (again ? ['This replaces the AI drafts under each field with new ones. The old drafts cannot be brought back.'] : [])
                    .concat(['It reads the source pages and takes two to four minutes.',
                             'What is in the boxes is kept, and nothing on the page is saved.']),
                action: again ? 'Yes, redraft' : 'Yes, draft'
            };
        }, 'Drafting… (two to four minutes)');

        guard('polish-button', function () {
            var hasDraft = !!document.getElementById('draft-button') && /^Redraft/i.test(document.getElementById('draft-button').textContent.trim());
            return {
                title: 'Draft and polish this page in the background?',
                lines: (hasDraft ? ['This replaces the current AI drafts under the fields. The old drafts cannot be brought back.'] : [])
                    .concat(['It writes a draft, checks it with the AI, rewrites what the check finds, and checks again. This takes about five to ten minutes and runs in the background, so you can leave this page. What is left over is for you to fix, with the Propose fix buttons.',
                             'You will get an email when it is ready. What is in the boxes is kept, and nothing on the page is saved.']),
                action: 'Yes, queue it'
            };
        }, 'Queued…');

        guard('check-button', function () {
            return {
                title: 'Check this page with the AI?',
                lines: ['It reads the text in the boxes against the sources and the house style, and takes up to two minutes.',
                        'Nothing is saved or changed.'],
                action: 'Yes, check'
            };
        }, 'Checking… (up to two minutes)');
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

        // Saving with an explanation deletes the AI draft, including any field
        // drafts not used. So before saving, name the drafts that haven't been
        // dealt with and ask. A draft counts as dealt with if the box differs
        // from what is SAVED: you used it, or you wrote your own text. (The
        // saved text, not the page-load text, is compared, because Check with
        // AI reloads the page with your edits in the boxes.)
        var saved = <?= json_encode([
            'intro'            => (string) ($subject['intro'] ?? ''),
            'explanation'      => (string) ($subject['explanation'] ?? ''),
            'meta_title'       => (string) ($subject['meta_title'] ?? ''),
            'meta_description' => (string) ($subject['meta_description'] ?? ''),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
        var fieldLabels = {intro: 'Intro', explanation: 'Explanation', meta_title: 'Meta title', meta_description: 'Meta description'};

        // Labels of the fields whose draft is unused: a draft exists that differs
        // from the saved text, and the box is still the saved text.
        function unusedDrafts(drafts, saved, current) {
            var flat = function (text) { return String(text || '').replace(/\s+/g, ' ').trim(); };
            var unused = [];
            Object.keys(fieldLabels).forEach(function (name) {
                var draft = flat(drafts[name]);
                if (draft !== '' && draft !== flat(saved[name]) && flat(current[name]) === flat(saved[name])) {
                    unused.push(fieldLabels[name]);
                }
            });
            return unused;
        }

        var detailsForm = document.getElementById('details-form');
        if (detailsForm) {
            detailsForm.addEventListener('submit', function (event) {
                // Only the Save button. Draft this page and Check with AI submit this form too.
                var submitter = event.submitter;
                if (!submitter || submitter.name === 'draft_page' || submitter.name === 'check_page' || submitter.name === 'queue_polish' || submitter.name === 'propose_fix') return;
                // The draft is only deleted when the page is saved with an explanation.
                if (document.getElementById('explanation').value.trim() === '') return;

                var current = {};
                Object.keys(fieldLabels).forEach(function (name) { current[name] = document.getElementById(name).value; });
                var unused = unusedDrafts(drafts, saved, current);
                if (unused.length && !confirm(
                    'These AI drafts have not been used, and saving deletes the draft:\n\n\u2022 ' + unused.join('\n\u2022 ')
                    + '\n\nSave anyway? Choose Cancel to go back, then use the Use draft button under the field.'
                )) {
                    event.preventDefault();
                }
            });
        }
    })();
</script>

<script>
    // ---- Safety net for typed text ----
    // The four text boxes are copied into this browser's local storage every couple
    // of seconds once they differ from what the page loaded with. If the page is
    // ever opened without that text (a button pressed by mistake, a timeout, a
    // crash), a bar offers to put it back. Nothing is sent anywhere: it stays in
    // this browser, for this page only.
    (function () {
        var ids = ['intro', 'explanation', 'meta_title', 'meta_description'];
        var key = 'ffl-unsaved-text-<?= (int) $subject['id'] ?>';
        var fields = {};
        for (var i = 0; i < ids.length; i++) {
            fields[ids[i]] = document.getElementById(ids[i]);
            if (!fields[ids[i]]) return;
        }

        function read() {
            var values = {};
            ids.forEach(function (id) { values[id] = fields[id].value; });
            return values;
        }
        function same(a, b) {
            return ids.every(function (id) { return (a[id] || '') === (b[id] || ''); });
        }
        function blank(values) {
            return ids.every(function (id) { return (values[id] || '').trim() === ''; });
        }

        var baseline = read(); // what the page loaded with
        var stored = null;
        try { stored = JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) { stored = null; }
        var lastWritten = stored && stored.fields ? JSON.stringify(stored.fields) : '';
        var paused = false;

        // Copy the boxes, but only when they differ from the page as loaded and aren't all
        // empty (so an empty page never overwrites good text), and not while the bar below
        // is waiting for an answer.
        function remember() {
            if (paused) return;
            var now = read();
            if (same(now, baseline) || blank(now)) return;
            var text = JSON.stringify(now);
            if (text === lastWritten) return;
            try {
                localStorage.setItem(key, JSON.stringify({ savedAt: Date.now(), fields: now }));
                lastWritten = text;
            } catch (e) { /* storage full or off: nothing to do */ }
        }
        window.fflRememberNow = remember; // the AI buttons call this just before they submit
        setInterval(remember, 2000);

        // Earlier text that this page doesn't show: offer it back.
        if (stored && stored.fields && !blank(stored.fields) && !same(stored.fields, baseline)) {
            paused = true;
            var when = '';
            try {
                when = ' from ' + new Date(stored.savedAt).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
            } catch (e) { /* leave the time out */ }

            var bar = document.createElement('div');
            bar.className = 'alert alert-info mb-6 flex flex-wrap items-center gap-3';
            bar.setAttribute('role', 'alert');
            bar.innerHTML = '<span>Text you typed' + when + ' was kept in this browser, and the boxes below show something different. '
                + 'Restoring puts it back in the boxes; nothing is saved until you click Save page details.</span>'
                + '<span class="flex gap-2"><button type="button" class="btn btn-sm btn-primary" data-restore>Restore it</button>'
                + '<button type="button" class="btn btn-sm btn-outline" data-dismiss>No thanks</button></span>';

            var form = document.getElementById('details-form');
            var heading = document.querySelector('#subject-details h2');
            if (heading && heading.parentNode) {
                heading.parentNode.insertBefore(bar, heading.nextSibling);
            } else if (form && form.parentNode) {
                form.parentNode.insertBefore(bar, form);
            }

            bar.querySelector('[data-restore]').addEventListener('click', function () {
                ids.forEach(function (id) {
                    fields[id].value = stored.fields[id] || '';
                    fields[id].dispatchEvent(new Event('input', { bubbles: true })); // updates counts and previews
                });
                paused = false;
                lastWritten = JSON.stringify(stored.fields);
                bar.remove();
            });
            bar.querySelector('[data-dismiss]').addEventListener('click', function () {
                try { localStorage.removeItem(key); } catch (e) { /* ignore */ }
                lastWritten = '';
                paused = false;
                bar.remove();
            });
        }
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

<script>
    // ---- Propose fix: Apply to the box, and a working label on the button ----
    // "Apply to the box" makes the proposed changes in the text boxes, and nothing
    // else: each passage must still be in its box exactly once, or nothing is
    // changed and you are told. A proposal can change up to three places, in
    // different boxes; they are applied together or not at all. The blank lines a deletion leaves are tidied the
    // same way as on the server (3 or more in a row become one blank line), so the
    // text that was checked there is the text that ends up here. Nothing is saved.
    (function () {
        document.querySelectorAll('[data-fix-apply]').forEach(function (button) {
            button.addEventListener('click', function () {
                var status = button.parentNode.querySelector('[data-fix-status]');
                var changes;
                try { changes = JSON.parse(button.dataset.fixChanges); } catch (e) { changes = []; }
                if (!changes.length) return;

                // All or none: work out every new value first, on copies of the boxes' text.
                var values = {};
                for (var i = 0; i < changes.length; i++) {
                    var box = document.getElementById(changes[i].field);
                    if (!box) return;
                    if (!(changes[i].field in values)) values[changes[i].field] = box.value.replace(/\r\n/g, '\n');
                    var text = values[changes[i].field];
                    var first = text.indexOf(changes[i].old);
                    if (first === -1 || text.indexOf(changes[i].old, first + 1) !== -1) {
                        if (status) status.textContent = 'The text in a box has changed, so this cannot be applied safely. Run Check with AI again.';
                        return;
                    }
                    values[changes[i].field] = (text.slice(0, first) + changes[i].new + text.slice(first + changes[i].old.length)).replace(/\n{3,}/g, '\n\n');
                }
                Object.keys(values).forEach(function (field) {
                    var box = document.getElementById(field);
                    box.value = values[field];
                    box.dispatchEvent(new Event('input')); // updates the count and preview
                });
                button.disabled = true;
                button.textContent = 'Applied';
                if (status) status.textContent = 'Changed in the box' + (Object.keys(values).length === 1 ? '' : 'es') + '. Save the page to keep it, or run Check with AI again first.';
            });
        });

        // Propose fix reads the source pages and asks the AI, which takes up to a minute.
        // The label changes after the form is on its way (a disabled button would not be sent).
        document.querySelectorAll('[data-propose-fix]').forEach(function (button) {
            button.addEventListener('click', function () {
                setTimeout(function () { button.textContent = 'Working… (up to a minute)'; }, 50);
            });
        });
    })();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
