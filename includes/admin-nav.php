<?php
/*
 * Navigation bar and one-off message for admin pages. Include it straight
 * after header.php on any admin page.
 *
 * Variables a page can set before including this file:
 *   $adminSection   'dashboard', 'subjects', 'review' or 'lessons', to highlight that link
 *
 * Shows the message saved with setAdminFlash() (if any) under the bar,
 * so pages don't each need their own flash markup.
 */
$adminSection = $adminSection ?? '';
$adminFlash = takeAdminFlash();

// Links in the order they appear.
$adminLinks = [
    'dashboard' => ['/admin/', 'Dashboard'],
    'subjects'  => ['/admin/subjects.php', 'Subjects'],
    'review'    => ['/admin/review.php', 'Review'],
    // Rules for the AI learned from your edits (includes/lessons.php).
    'lessons'   => ['/admin/lessons.php', 'Lessons'],
];
?>
<?php
// Restyle Step 6: daisyUI tabs for the admin sections, with Log out pushed to the right. The old <style> block that
// forced light text on alerts and buttons is gone: the daisyUI theme sets those colours, in light and dark.
?>
<nav class="tabs tabs-border mb-8 items-center" aria-label="Admin">
    <span class="tab font-mono text-xs uppercase tracking-wide opacity-70 pointer-events-none">Admin</span>
    <?php foreach ($adminLinks as $key => [$href, $text]): ?>
    <a href="<?= e($href) ?>" class="tab <?= $adminSection === $key ? 'tab-active font-semibold' : '' ?>"
        <?= $adminSection === $key ? 'aria-current="page"' : '' ?>><?= e($text) ?></a>
    <?php endforeach; ?>
    <a href="/admin/logout.php" class="tab ml-auto">Log out</a>
</nav>

<?php if ($adminFlash): ?>
<?php // DaisyUI alert colour matches the outcome of the last action. ?>
<div class="alert <?= $adminFlash['type'] === 'error' ? 'alert-error' : ($adminFlash['type'] === 'info' ? 'alert-info' : 'alert-success') ?> mb-6">
    <span><?= e($adminFlash['text']) ?></span>
</div>
<?php endif; ?>
