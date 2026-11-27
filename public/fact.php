<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/worked-examples.php';

// PREVIEW MODE. public/admin/preview.php (admin login required) defines FFL_PREVIEW and then loads
// this file, so a reviewer can see exactly what a page will look like, including a hidden draft or a
// page in a country that is not switched on yet. In preview mode only, the draft, retired,
// country-not-live and canonical-address checks below are skipped, the page is marked noindex and a
// banner says it is a preview. The public route never defines FFL_PREVIEW, so visitors still get
// "Subject not found" for drafts and for countries that are not live.
$previewMode = defined('FFL_PREVIEW');

// Subject slugs are unique, so the slug alone identifies the page. The
// country, category and subcategory in the URL are checked below by
// redirecting to the subject's real address.
$slug = $_GET['slug'] ?? '';
$subject = $slug ? getSubjectBySlug($slug) : null;

// Draft subjects don't exist as far as the public is concerned, so they
// get the same 404 as a missing slug. So do subjects in a country that
// isn't live yet.
if (!$subject || (!$previewMode && ($subject['status'] === 'draft' || !$subject['country_active'])) || !$subject['country_prefix']) {
    showErrorPage(404, 'Subject not found.');
}

// Retired subjects answer 410 Gone, which tells search engines the page
// was removed on purpose and can be dropped from the index. When a
// retired page has a natural replacement, a 301 redirect to it is
// better. That can be added here once there's a column to store it.
if ($subject['status'] === 'retired' && !$previewMode) {
    showErrorPage(410, 'This page is no longer available.');
}

// Sends old /fact/... URLs, missing trailing slashes, and links to a
// subject's previous subcategory to its current address with a 301.
$canonicalPath = subjectUrl(
    $subject['country_prefix'], $subject['category_slug'], $subject['subcategory_slug'], $subject['slug']
);
if (!$previewMode) {
    redirectToCanonical($canonicalPath);
}

// getCountryByPrefix() only knows live countries, so a preview of a hidden country reads the row directly.
$country = getCountryByPrefix($subject['country_prefix']);
if (!$country && $previewMode) {
    $countryStmt = getDbConnection()->prepare('SELECT * FROM jurisdictions WHERE url_prefix = :p AND parent_id IS NULL LIMIT 1');
    $countryStmt->execute(['p' => $subject['country_prefix']]);
    $country = $countryStmt->fetch() ?: null;
}
if (!$country) {
    showErrorPage(404, 'Subject not found.');
}
if ($previewMode) {
    $noindex = true; // header.php adds noindex, nofollow, so a preview can never be indexed
}

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

<?php if ($previewMode): ?>
<?php // Preview banner (restyle Step 4): a daisyUI warning alert, since this view is not public. ?>
<div role="alert" class="alert alert-warning mb-6 text-sm">
    <span>
        <strong>Preview, not public.</strong>
        Status: <?= e($subject['status']) ?>. Country live: <?= $subject['country_active'] ? 'yes' : 'no' ?>.
        Links in the header and footer may point at pages that do not exist yet.
        <a href="/admin/subject.php?id=<?= (int) $subject['id'] ?>" class="link">Back to the editor</a>
    </span>
</div>
<?php endif; ?>

<script type="application/ld+json"><?= $breadcrumbJson ?></script>

<?php // daisyUI breadcrumbs (restyle Step 3). The component scrolls sideways inside itself if the trail is too wide for a phone. ?>
<nav class="breadcrumbs text-sm mb-2 [&_a]:inline-flex [&_a]:items-center [&_a]:min-h-11" aria-label="Breadcrumb">
    <ul>
        <li><a href="<?= e(categoryUrl($subject['country_prefix'], $subject['category_slug'])) ?>"><?= e($subject['category_name']) ?></a></li>
        <li><a href="<?= e(subcategoryUrl($subject['country_prefix'], $subject['category_slug'], $subject['subcategory_slug'])) ?>"><?= e($subject['subcategory_name']) ?></a></li>
    </ul>
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
<?php
// Figure cards (restyle Step 4): a daisyUI card on the page surface. The value is the biggest thing on the card,
// in the display font with tabular figures so numbers line up. Source and dates sit small underneath.
?>
<div class="grid sm:grid-cols-2 gap-4 sm:gap-5">
    <?php foreach ($facts as $fact): ?>
    <div class="card card-border border-base-content/20 bg-base-100">
        <div class="card-body p-5 gap-1">
            <p class="text-sm opacity-80">
                <?= e($fact['label']) ?>
                <?php if (isRegionalFact($fact)): ?>
                <?php // Nation-specific figure, e.g. Scottish income tax, so say where it applies. ?>
                <span class="badge badge-outline badge-sm ml-1"><?= e($fact['jurisdiction_name']) ?></span>
                <?php endif; ?>
            </p>
            <p class="font-display text-3xl font-semibold tabular-nums break-words">
                <?= e(formatFactValue($fact)) ?>
            </p>
            <?php if ($fact['context']): ?>
            <p class="text-sm opacity-80"><?= e($fact['context']) ?></p>
            <?php endif; ?>
            <p class="text-xs opacity-70 mt-1">
                <?php if ($fact['source_url']): ?>
                <?php // Linking the source lets readers check the figure themselves, which builds trust. ?>
                <a href="<?= e($fact['source_url']) ?>" target="_blank" rel="noopener" class="link">
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
            <p class="text-xs mt-1">
                <a href="<?= e(subjectUrlById((int) $fact['primary_subject_id'])) ?>" class="link">
                    More on <?= e($fact['owner_name']) ?> &rarr;
                </a>
            </p>
            <?php endif; ?>
        </div>
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
    <?php // daisyUI table (restyle Step 4). It scrolls sideways inside its own box on a phone, so wide tables never widen the page. ?>
    <div class="overflow-x-auto rounded-box border border-base-300 bg-base-100">
        <table class="table">
            <thead>
                <tr>
                    <?php foreach ($example['columns'] as $column): ?>
                    <th class="whitespace-nowrap"><?= e($column) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($example['rows'] as $row): ?>
                <tr>
                    <?php foreach ($row as $i => $cell): ?>
                    <td class="<?= $i === 0 ? 'font-semibold' : 'tabular-nums' ?>"><?= e((string) $cell) ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="text-xs opacity-70 mt-3"><?= e($example['note']) ?></p>
</section>
<?php endforeach; ?>

<?php // What's changed: recorded value changes for this page's facts, newest first. ?>
<?php if (!empty($changes)): ?>
<section class="mt-14">
    <h2 class="font-display text-2xl font-semibold mb-4">What's changed</h2>
    <?php // daisyUI timeline (restyle Step 4), one compact column with the date above each change. ?>
    <ul class="timeline timeline-vertical timeline-compact max-w-2xl">
        <?php foreach ($changes as $i => $change): ?>
        <?php
        // Describe the direction of numeric changes in words as well as figures.
        $direction = 'changed';
        if ($change['old_value_numeric'] !== null && $change['new_value_numeric'] !== null) {
            $direction = (float) $change['new_value_numeric'] > (float) $change['old_value_numeric'] ? 'rose' : 'fell';
        }
        ?>
        <li>
            <?php if ($i > 0): ?><hr /><?php endif; ?>
            <div class="timeline-middle">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="h-4 w-4" aria-hidden="true">
                    <circle cx="10" cy="10" r="5" fill="currentColor" />
                </svg>
            </div>
            <div class="timeline-end mb-6">
                <time class="text-xs opacity-70 block"><?= date('j F Y', strtotime($change['changed_on'])) ?></time>
                <?= e($change['label']) ?> <?= $direction ?> from
                <span class="font-semibold tabular-nums"><?= e(formatHistoryValue($change, 'old')) ?></span>
                to
                <span class="font-semibold tabular-nums"><?= e(formatHistoryValue($change, 'new')) ?></span>.
            </div>
            <?php if ($i < count($changes) - 1): ?><hr /><?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>