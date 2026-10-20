<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/fact-writer.php';
require_once __DIR__ . '/../../includes/admin-auth.php';

// Must be first: sends anyone not logged in to /admin/login.php. It also
// starts the admin session that the code below uses.
requireAdmin();

// Session is used for a CSRF token (so another site can't submit these
// forms on your behalf while you're logged in) and for the one-off
// message shown after an action.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

// ------------------------------------------------------------
// Actions. Both go through fact-writer.php, so every value change
// writes a fact_history row, which editing in phpMyAdmin wouldn't.
// After handling, redirect back (Post/Redirect/Get) so refreshing the
// page can't resubmit the form.
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $factId = (int) ($_POST['fact_id'] ?? 0);
    $changeId = (int) ($_POST['change_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    try {
        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            throw new RuntimeException('Form expired. Please try again.');
        }

        $pdo = getDbConnection();

        if ($action === 'approve_change' || $action === 'reject_change') {
            // Held changes from the pipeline (see "Held for review" below).
            $stmt = $pdo->prepare("SELECT * FROM fact_changes WHERE id = :id AND status = 'held'");
            $stmt->execute(['id' => $changeId]);
            $change = $stmt->fetch();
            if (!$change) {
                throw new RuntimeException('That change has already been dealt with.');
            }

            if ($action === 'approve_change') {
                // Publish through updateFactValue(), so it's recorded in the
                // fact's history and linked back to this proposal.
                withTransaction($pdo, function () use ($pdo, $change) {
                    // The proposal's effective date and tax year go with it
                    // (AI proposals read them from the source page). A date
                    // still in the future is refused by updateFactValue(),
                    // with a message saying to approve it once it's in effect.
                    updateFactValue($pdo, (int) $change['fact_id'], $change['proposed_value'], [
                        'change_source'  => 'pipeline',
                        'fact_change_id' => (int) $change['id'],
                        'effective_from' => $change['effective_from'],
                        'tax_year'       => $change['tax_year'],
                        'note'           => 'Approved on the review page',
                    ]);
                    // Approved means checked against the source, so the
                    // figure also leaves "Needs a look".
                    markFactVerified($pdo, (int) $change['fact_id']);
                    $pdo->prepare(
                        "UPDATE fact_changes SET status = 'applied', decided_at = NOW(), applied_at = NOW() WHERE id = :id"
                    )->execute(['id' => $change['id']]);
                });
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'Change approved and published.'];
            } else {
                $pdo->prepare("UPDATE fact_changes SET status = 'rejected', decided_at = NOW() WHERE id = :id")
                    ->execute(['id' => $change['id']]);
                $_SESSION['flash'] = ['type' => 'info', 'text' => 'Change rejected. The current figure stays.'];
            }

            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }

        if ($factId <= 0) {
            throw new RuntimeException('No fact selected.');
        }

        if ($action === 'verify') {
            // Figure checked and still correct, so just reset the review window.
            markFactVerified($pdo, $factId);
            $_SESSION['flash'] = ['type' => 'success', 'text' => 'Marked as verified.'];

        } elseif ($action === 'update') {
            $newValue = trim($_POST['new_value'] ?? '');
            $effectiveFrom = trim($_POST['effective_from'] ?? '');

            if ($newValue === '') {
                throw new RuntimeException('Enter the new value.');
            }
            if ($effectiveFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom)) {
                throw new RuntimeException('Effective date must be a valid date.');
            }

            $changed = updateFactValue($pdo, $factId, $newValue, [
                'change_source'  => 'manual',
                'effective_from' => $effectiveFrom ?: null,
                'note'           => 'Updated from the review page',
            ]);

            $_SESSION['flash'] = $changed
                ? ['type' => 'success', 'text' => 'Value updated and recorded in history.']
                : ['type' => 'info', 'text' => 'Same value as before, so it was marked as verified instead.'];

        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => $e->getMessage()];
    }

    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// The message from the last action ($_SESSION['flash']) is now shown by
// includes/admin-nav.php, which every admin page shares.

// Button and input styling, written out in full on this page. The theme's
// own filled-button style (btn-primary) shows as a dark box with no
// readable label, and its default input border is too faint to see, which
// hid the Update and Approve buttons and the New value box. Using the
// theme's own colours directly (primary for the box, base-100 for the
// text) keeps them readable.
$filledButton = 'btn btn-xs border-primary bg-primary text-base-100 hover:opacity-80';
$inputField = 'input input-xs border border-primary/40 bg-base-100';

$pageTitle = 'Facts Due for Review';
$noindex = true; // admin page, keep it out of search results
$adminSection = 'review';
$dueFacts = getFactsDueForReview();
$heldChanges = getHeldChanges();
$needsAttention = getFactsNeedingAttention();
$overlaps = getOverlappingSubjects(0.5);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/admin-nav.php'; // shared admin links, log out and messages
?>

<section class="mb-8">
    <h1 class="font-display text-3xl font-semibold mb-2">Facts Due for Review</h1>
    <p class="opacity-80 max-w-xl">
        Facts not checked within their own <code class="font-mono text-sm">review_frequency_days</code>
        window, most overdue first. Being listed here means it's time to check the
        figure against its source — not necessarily that it's wrong.
    </p>
</section>

<?php // Changes the pipeline found but didn't publish, because they failed a safety check. ?>
<?php if (!empty($heldChanges)): ?>
<section class="mb-12">
    <h2 class="font-display text-2xl font-semibold mb-2">Held for review</h2>
    <p class="opacity-80 max-w-xl mb-4">
        New figures the automated checks didn't publish, usually because the change was unusually
        large. Check the source, then approve to publish or reject to keep the current figure.
    </p>
    <div class="overflow-x-auto">
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="border-b-2 border-primary text-left font-mono text-xs uppercase tracking-wide">
                    <th class="py-2 pr-4">Fact</th>
                    <th class="py-2 pr-4">Current</th>
                    <th class="py-2 pr-4">Proposed</th>
                    <th class="py-2 pr-4">Why held</th>
                    <th class="py-2 pr-4">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($heldChanges as $change): ?>
                <tr class="border-b border-primary/20 align-top">
                    <td class="py-3 pr-4">
                        <a href="<?= e(subjectUrlById((int) $change['primary_subject_id'])) ?>" target="_blank" class="hover:text-accent">
                            <?= e($change['label']) ?>
                        </a>
                        <?php if ($change['source_url']): ?>
                        <a href="<?= e($change['source_url']) ?>" target="_blank" rel="noopener"
                            class="block text-xs text-secondary underline hover:text-accent"><?= e($change['source_name'] ?? 'Source') ?> &#8599;</a>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 pr-4 fact-value"><?= e(formatFactValue($change)) ?></td>
                    <td class="py-3 pr-4 fact-value font-semibold">
                        <?= e(formatFactValue(['value' => $change['proposed_value'], 'value_numeric' => $change['proposed_value_numeric'],
                            'value_type' => $change['value_type'], 'unit' => $change['unit'], 'value_display' => null])) ?>
                    </td>
                    <td class="py-3 pr-4 text-xs opacity-80">
                        <?= e($change['status_reason'] ?? '') ?>
                        <span class="block opacity-60 mt-1"><?= e($change['evidence_snippet'] ?? '') ?></span>
                    </td>
                    <td class="py-3 pr-4">
                        <form method="post" class="flex gap-1">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="change_id" value="<?= (int) $change['id'] ?>">
                            <button type="submit" name="action" value="approve_change" class="<?= $filledButton ?>">Approve</button>
                            <button type="submit" name="action" value="reject_change" class="btn btn-xs btn-outline">Reject</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php // Figures the source check couldn't find on their source page (scripts/check-sources.php, run daily). ?>
<?php if (!empty($needsAttention)): ?>
<section class="mb-12">
    <h2 class="font-display text-2xl font-semibold mb-2">Needs a look</h2>
    <p class="opacity-80 max-w-xl mb-4">
        The source check couldn't find these figures on their source pages, usually because the page
        now shows a new value. Open the source, then confirm the figure or enter the new one.
    </p>
    <div class="overflow-x-auto">
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="border-b-2 border-primary text-left font-mono text-xs uppercase tracking-wide">
                    <th class="py-2 pr-4">Fact</th>
                    <th class="py-2 pr-4">Current value</th>
                    <th class="py-2 pr-4">Problem</th>
                    <th class="py-2 pr-4">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($needsAttention as $fact): ?>
                <tr class="border-b border-primary/20 align-top">
                    <td class="py-3 pr-4">
                        <a href="<?= e(subjectUrlById((int) $fact['primary_subject_id'])) ?>" target="_blank" class="hover:text-accent">
                            <?= e($fact['label']) ?>
                        </a>
                        <span class="block font-mono text-xs opacity-50"><?= e($fact['fact_key']) ?></span>
                        <?php if ($fact['source_url']): ?>
                        <a href="<?= e($fact['source_url']) ?>" target="_blank" rel="noopener"
                            class="block text-xs text-secondary underline hover:text-accent"><?= e($fact['source_name'] ?? 'Source') ?> &#8599;</a>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 pr-4 fact-value font-semibold"><?= e(formatFactValue($fact)) ?></td>
                    <td class="py-3 pr-4 text-xs">
                        <?php if ($fact['check_result'] === 'source_error'): ?>
                        Source page could not be opened
                        <?php elseif ($fact['check_note'] === 'tax_year_ended'): ?>
                        <?php // Year-specific figures need the new year's value, and often a new source page. ?>
                        Tax year <?= e((string) $fact['tax_year']) ?> has ended. Enter the new year's figure
                        <?php else: ?>
                        Not found on the source page
                        <?php endif; ?>
                        <span class="block opacity-60"><?= date('j M Y', strtotime($fact['checked_at'])) ?></span>
                    </td>
                    <td class="py-3 pr-4">
                        <form method="post" class="mb-2">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="fact_id" value="<?= (int) $fact['id'] ?>">
                            <input type="hidden" name="action" value="verify">
                            <button type="submit" class="btn btn-xs btn-outline">Still correct</button>
                        </form>
                        <form method="post" class="flex flex-wrap gap-1 items-center">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="fact_id" value="<?= (int) $fact['id'] ?>">
                            <input type="hidden" name="action" value="update">
                            <input type="text" name="new_value" placeholder="New value" required
                                class="<?= $inputField ?> w-24">
                            <input type="date" name="effective_from" title="Effective from (optional)"
                                class="<?= $inputField ?>">
                            <button type="submit" class="<?= $filledButton ?>">Update</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php if (empty($dueFacts)): ?>
<p class="fact-card p-6 text-secondary font-display text-lg">
    Nothing due right now — every fact is within its review window.
</p>
<?php else: ?>
<div class="overflow-x-auto">
    <table class="w-full text-sm border-collapse">
        <thead>
            <tr class="border-b-2 border-primary text-left font-mono text-xs uppercase tracking-wide">
                <th class="py-2 pr-4">Subject</th>
                <th class="py-2 pr-4">Fact</th>
                <th class="py-2 pr-4">Current value</th>
                <th class="py-2 pr-4">Last checked</th>
                <th class="py-2 pr-4">Overdue by</th>
                <th class="py-2 pr-4">Source</th>
                <th class="py-2 pr-4">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($dueFacts as $fact): ?>
            <tr class="border-b border-primary/20 align-top">
                <td class="py-3 pr-4">
                    <a href="<?= e(subjectUrlById((int) $fact['primary_subject_id'])) ?>" class="hover:text-accent" target="_blank">
                        <?= e($fact['subject_name']) ?>
                    </a>
                </td>
                <td class="py-3 pr-4">
                    <?= e($fact['label']) ?>
                    <span class="block font-mono text-xs opacity-50"><?= e($fact['fact_key']) ?></span>
                </td>
                <td class="py-3 pr-4 fact-value font-semibold">
                    <?= e(formatFactValue($fact)) ?>
                </td>
                <td class="py-3 pr-4 opacity-70">
                    <?= date('j M Y', strtotime($fact['last_verified_at'] ?? $fact['last_updated'])) ?>
                </td>
                <td class="py-3 pr-4">
                    <span class="font-mono text-xs px-2 py-1 rounded bg-error/10 text-error">
                        <?= (int) $fact['days_overdue'] ?> days
                    </span>
                </td>
                <td class="py-3 pr-4">
                    <?php if ($fact['source_url']): ?>
                    <a href="<?= e($fact['source_url']) ?>" target="_blank" rel="noopener"
                        class="text-secondary hover:text-accent underline">
                        <?= e($fact['source_name'] ?? 'Check source') ?> &#8599;
                    </a>
                    <?php // Non-allowlisted sources will always need a human check once the pipeline runs. ?>
                    <?php if (!$fact['source_allowlisted']): ?>
                    <span class="block font-mono text-xs opacity-50">not allowlisted</span>
                    <?php endif; ?>
                    <?php else: ?>
                    <span class="opacity-50">No source</span>
                    <?php endif; ?>
                </td>
                <td class="py-3 pr-4">
                    <?php // Figure is unchanged: one click resets its review window. ?>
                    <form method="post" class="mb-2">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="fact_id" value="<?= (int) $fact['id'] ?>">
                        <input type="hidden" name="action" value="verify">
                        <button type="submit" class="btn btn-xs btn-outline">Still correct</button>
                    </form>
                    <?php // Figure has changed: record the new value (and when it took effect, if known). ?>
                    <form method="post" class="flex flex-wrap gap-1 items-center">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="fact_id" value="<?= (int) $fact['id'] ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="text" name="new_value" placeholder="New value" required
                            class="<?= $inputField ?> w-24">
                        <input type="date" name="effective_from" title="Effective from (optional)"
                            class="<?= $inputField ?>">
                        <button type="submit" class="<?= $filledButton ?>">Update</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<p class="text-xs opacity-50 mt-6 font-mono">
    Use these buttons rather than editing values in phpMyAdmin. Changes made here are
    recorded in fact_history. The unit is optional when entering a new value, so 4.5 and 4.5% both work.
</p>
<?php endif; ?>

<?php // Pages sharing most of their facts compete for the same searches, so flag them. ?>
<section class="mt-12">
    <h2 class="font-display text-2xl font-semibold mb-2">Overlapping subjects</h2>
    <p class="opacity-80 max-w-xl mb-4">
        Pairs of published subjects sharing at least half their facts. Give each a distinct
        focus, or merge them, so they don't compete for the same searches.
    </p>
    <?php if (empty($overlaps)): ?>
    <p class="fact-card p-6 text-secondary">No overlapping subjects.</p>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="border-b-2 border-primary text-left font-mono text-xs uppercase tracking-wide">
                    <th class="py-2 pr-4">Subject</th>
                    <th class="py-2 pr-4">Overlaps with</th>
                    <th class="py-2 pr-4">Shared facts</th>
                    <th class="py-2 pr-4">Overlap</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($overlaps as $pair): ?>
                <tr class="border-b border-primary/20">
                    <td class="py-3 pr-4">
                        <a href="<?= e(subjectUrlById((int) $pair['subject_a_id'])) ?>" target="_blank" class="hover:text-accent">
                            <?= e($pair['subject_a_name']) ?>
                        </a>
                    </td>
                    <td class="py-3 pr-4">
                        <a href="<?= e(subjectUrlById((int) $pair['subject_b_id'])) ?>" target="_blank" class="hover:text-accent">
                            <?= e($pair['subject_b_name']) ?>
                        </a>
                    </td>
                    <td class="py-3 pr-4"><?= (int) $pair['shared_facts'] ?></td>
                    <td class="py-3 pr-4 font-mono"><?= round($pair['overlap'] * 100) ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../../includes/footer.php'; ?>