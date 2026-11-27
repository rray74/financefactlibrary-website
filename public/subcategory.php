<?php
require_once __DIR__ . '/../includes/functions.php';

$country = resolveCountryFromRequest();
$categorySlug = $_GET['category'] ?? '';
$subcategorySlug = $_GET['slug'] ?? '';
$subcategory = ($country && $categorySlug && $subcategorySlug)
    ? getSubcategoryBySlug((int) $country['id'], $categorySlug, $subcategorySlug)
    : null;

if (!$subcategory) {
    showErrorPage(404, 'Subcategory not found.');
}

// Old /category/.../... URLs and missing trailing slashes are sent to /uk/.../
$canonicalPath = subcategoryUrl($country['url_prefix'], $subcategory['category_slug'], $subcategory['slug']);
redirectToCanonical($canonicalPath);

$pageTitle = $subcategory['name'];
$metaDescription = $subcategory['description'] ?? '';
$subjects = getSubjectsBySubcategoryId($subcategory['id']);

include __DIR__ . '/../includes/header.php';
?>

<?php // daisyUI breadcrumbs (restyle Step 3). The last item is the current page, so it is not a link. ?>
<nav class="breadcrumbs text-sm mb-2 [&_a]:inline-flex [&_a]:items-center [&_a]:min-h-11" aria-label="Breadcrumb">
    <ul>
        <li><a href="<?= e(categoryUrl($country['url_prefix'], $subcategory['category_slug'])) ?>"><?= e($subcategory['category_name']) ?></a></li>
        <li><?= e($subcategory['name']) ?></li>
    </ul>
</nav>

<section class="mb-10">
    <h1 class="font-display text-4xl font-semibold mb-3"><?= e($subcategory['name']) ?></h1>
    <?php if ($subcategory['description']): ?>
    <p class="opacity-80 max-w-xl"><?= e($subcategory['description']) ?></p>
    <?php endif; ?>
</section>

<?php if (empty($subjects)): ?>
<p class="opacity-70">No subjects here yet.</p>
<?php else: ?>
<div class="grid sm:grid-cols-2 gap-4 sm:gap-5">
    <?php foreach ($subjects as $subject): ?>
    <?php // daisyUI card as a link (restyle Step 5): the whole card is the tap target. ?>
    <a href="<?= e(subjectUrl($country['url_prefix'], $subcategory['category_slug'], $subcategory['slug'], $subject['slug'])) ?>"
        class="card card-border border-base-content/20 bg-base-100 hover:bg-base-200 transition-colors">
        <div class="card-body p-5 gap-1">
            <h3 class="card-title font-display text-xl"><?= e($subject['name']) ?></h3>
            <?php // Intros can contain placeholders, filled in the same way as on the subject page. ?>
            <?php $cardIntro = fillSubjectPlaceholders($subject['intro'], (int) $subject['id'], $country['code']); ?>
            <?php if ($cardIntro): ?>
            <p class="text-sm opacity-80"><?= e($cardIntro) ?></p>
            <?php endif; ?>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
