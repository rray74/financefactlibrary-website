<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/worked-examples.php';

// Subject slugs are unique, so the slug alone identifies the page. The
// country, category and subcategory in the URL are checked below by
// redirecting to the subject's real address.
$slug = $_GET['slug'] ?? '';
$subject = $slug ? getSubjectBySlug($slug) : null;

// Draft subjects don't exist as far as the public is concerned, so they
// get the same 404 as a missing slug. So do subjects in a country that
// isn't live yet.
if (!$subject || $subject['status'] === 'draft' || !$subject['country_active'] || !$subject['country_prefix']) {
    showErrorPage(404, 'Subject not found.');
}

// Retired subjects answer 410 Gone, which tells search engines the page
// was removed on purpose and can be dropped from the index. When a
// retired page has a natural replacement, a 301 redirect to it is
// better. That can be added here once there's a column to store it.
if ($subject['status'] === 'retired') {
    showErrorPage(410, 'This page is no longer available.');
}

// Sends old /fact/... URLs, missing trailing slashes, and links to a
// subject's previous subcategory to its current address with a 301.
$canonicalPath = subjectUrl(
    $subject['country_prefix'], $subject['category_slug'], $subject['subcategory_slug'], $subject['slug']
);
redirectToCanonical($canonicalPath);

$country = getCountryByPrefix($subject['country_prefix']);

$facts = getFactsForSubject($subject['id']);

// The four written fields can contain {fact:key} and {tax_year}
// placeholders (includes/placeholders.php), filled in here with the live
// figures, so the text never goes out of date when a figure changes.
$fill = fn(?string $text) => fillPlaceholders($text, $country['code'], $facts);
$intro = $fill($subject['intro']);

// meta_title / meta_description come from the subjects table when set,
// falling back to the subject name and intro.
$pageTitle = $fill($subject['meta_title']) ?: $subject['name'];
$metaDescription = $fill($subject['meta_description']) ?: ($intro ?? '');

// Generated sections below the facts. Both come from the database, so
// every page gains content that updates itself when the figures change.
$workedExamples = getWorkedExamplesForSubject($subject['slug']);
$changes = getRecentChangesForSubject((int) $subject['id']);

// The longer written explanation (migration 010), edited in the admin.
// renderExplanation() escapes everything and returns '' when there's
// none, in which case the section isn't shown.
$explanationHtml = renderExplanation($fill($subject['explanation'] ?? null));

// Breadcrumb structured data, so search results can show the
// Category > Subcategory > Page trail instead of a bare URL.
$breadcrumbJson = json_encode([
    '@context'        => 'https://schema.org',
    '@type'           => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => $subject['category_name'],
         'item' => absoluteUrl(categoryUrl($subject['country_prefix'], $subject['category_slug']))],
        ['@type' => 'ListItem', 'position' => 2, 'name' => $subject['subcategory_name'],
         'item' => absoluteUrl(subcategoryUrl($subject['country_prefix'], $subject['category_slug'], $subject['subcategory_slug']))],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $subject['name'],
         'item' => absoluteUrl($canonicalPath)],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

include __DIR__ . '/../includes/header.php';
?>

<script type="application/ld+json"><?= $breadcrumbJson ?></script>

<nav class="font-mono text-xs uppercase tracking-wide text-secondary mb-4">
    <a href="<?= e(categoryUrl($subject['country_prefix'], $subject['category_slug'])) ?>" class="hover:text-accent">
        <?= e($subject['category_name']) ?>
    </a>
    <span class="opacity-50">/</span>
    <a href="<?= e(subcategoryUrl($subject['country_prefix'], $subject['category_slug'], $subject['subcategory_slug'])) ?>"
        class="hover:text-accent">
        <?= e($subject['subcategory_name']) ?>
    </a>
</nav>

<section class="mb-10">
    <h1 class="font-display text-4xl font-semibold mb-3"><?= e($subject['name']) ?></h1>
    <?php if ($intro): ?>
    <?php // $intro has its placeholders filled in (see the top of this file). ?>
    <p class="text-lg opacity-80 max-w-xl"><?= e($intro) ?></p>
    <?php endif; ?>
</section>

<?php if (empty($facts)): ?>
<p class="opacity-70">No facts added for this subject yet.</p>
<?php else: ?>
<div class="grid sm:grid-cols-2 gap-5">
    <?php foreach ($facts as $fact): ?>
    <div class="fact-card p-5">
        <p class="text-sm opacity-70">
            <?= e($fact['label']) ?>
            <?php if (isRegionalFact($fact)): ?>
            <?php // Nation-specific figure, e.g. Scottish income tax, so say where it applies. ?>
            <span
                class="font-mono text-xs uppercase tracking-wide ml-1 px-1.5 py-0.5 rounded bg-secondary/10 text-secondary">
                <?= e($fact['jurisdiction_name']) ?>
            </span>
            <?php endif; ?>
        </p>
        <p class="fact-value font-display text-3xl font-semibold text-primary my-1">
            <?= e(formatFactValue($fact)) ?>
        </p>
        <?php if ($fact['context']): ?>
        <p class="text-sm opacity-80 mb-2"><?= e($fact['context']) ?></p>
        <?php endif; ?>
        <p class="text-xs opacity-60">
            <?php if ($fact['source_url']): ?>
            <?php // Linking the source lets readers check the figure themselves, which builds trust. ?>
            <a href="<?= e($fact['source_url']) ?>" target="_blank" rel="noopener" class="underline hover:text-accent">
                <?= e($fact['source_name'] ?? 'Source') ?>
            </a>
            <?php elseif ($fact['source_name']): ?>
            <?= e($fact['source_name']) ?>
            <?php endif; ?>
            <?php if ($fact['effective_from']): ?>
            &middot; since <?= date('j M Y', strtotime($fact['effective_from'])) ?>
            <?php endif; ?>
            <?php // "Checked" is the last verification, which can be newer than the last change. ?>
            &middot; checked <?= date('j M Y', strtotime($fact['last_verified_at'] ?? $fact['last_updated'])) ?>
        </p>
        <?php if (!$fact['is_primary'] && $fact['owner_status'] === 'published'): ?>
        <?php // Shared fact: link to the page that owns it, for readers and for internal linking. ?>
        <p class="text-xs mt-2">
            <a href="<?= e(subjectUrlById((int) $fact['primary_subject_id'])) ?>" class="text-secondary hover:text-accent">
                More on <?= e($fact['owner_name']) ?> &rarr;
            </a>
        </p>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php // Explanation: the written guide below the figures. Already escaped HTML from renderExplanation(). ?>
<?php if ($explanationHtml !== ''): ?>
<section class="mt-14 max-w-2xl leading-relaxed [&>:first-child]:mt-0">
    <?= $explanationHtml ?>
</section>
<?php endif; ?>

<?php // Worked examples: calculated from the live figures above (see includes/worked-examples.php). ?>
<?php foreach ($workedExamples as $example): ?>
<section class="mt-14">
    <h2 class="font-display text-2xl font-semibold mb-2"><?= e($example['title']) ?></h2>
    <p class="opacity-80 max-w-xl mb-4"><?= e($example['intro']) ?></p>
    <div class="overflow-x-auto">
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="border-b-2 border-primary text-left font-mono text-xs uppercase tracking-wide">
                    <?php foreach ($example['columns'] as $column): ?>
                    <th class="py-2 pr-4"><?= e($column) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($example['rows'] as $row): ?>
                <tr class="border-b border-primary/20">
                    <?php foreach ($row as $i => $cell): ?>
                    <td class="py-3 pr-4 <?= $i === 0 ? 'font-semibold' : 'fact-value' ?>"><?= e((string) $cell) ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="text-xs opacity-60 mt-3"><?= e($example['note']) ?></p>
</section>
<?php endforeach; ?>

<?php // What's changed: recorded value changes for this page's facts, newest first. ?>
<?php if (!empty($changes)): ?>
<section class="mt-14">
    <h2 class="font-display text-2xl font-semibold mb-4">What's changed</h2>
    <ul class="space-y-3 max-w-2xl">
        <?php foreach ($changes as $change): ?>
        <?php
        // Describe the direction of numeric changes in words as well as figures.
        $direction = 'changed';
        if ($change['old_value_numeric'] !== null && $change['new_value_numeric'] !== null) {
            $direction = (float) $change['new_value_numeric'] > (float) $change['old_value_numeric'] ? 'rose' : 'fell';
        }
        ?>
        <li class="border-l-2 border-primary/30 pl-4">
            <span class="font-mono text-xs uppercase tracking-wide text-secondary block">
                <?= date('j F Y', strtotime($change['changed_on'])) ?>
            </span>
            <?= e($change['label']) ?> <?= $direction ?> from
            <span class="fact-value font-semibold"><?= e(formatHistoryValue($change, 'old')) ?></span>
            to
            <span class="fact-value font-semibold"><?= e(formatHistoryValue($change, 'new')) ?></span>.
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>