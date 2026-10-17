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

<nav class="font-mono text-xs uppercase tracking-wide text-secondary mb-4">
    <a href="<?= e(categoryUrl($country['url_prefix'], $subcategory['category_slug'])) ?>" class="hover:text-accent">
        <?= e($subcategory['category_name']) ?>
    </a>
    <span class="opacity-50">/</span>
    <?= e($subcategory['name']) ?>
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
<div class="grid sm:grid-cols-2 gap-6">
    <?php foreach ($subjects as $subject): ?>
    <a href="<?= e(subjectUrl($country['url_prefix'], $subcategory['category_slug'], $subcategory['slug'], $subject['slug'])) ?>"
        class="fact-card p-6 block">
        <h3 class="font-display text-xl font-semibold mb-2"><?= e($subject['name']) ?></h3>
        <?php // Intros can contain placeholders, filled in the same way as on the subject page. ?>
        <?php $cardIntro = fillSubjectPlaceholders($subject['intro'], (int) $subject['id'], $country['code']); ?>
        <?php if ($cardIntro): ?>
        <p class="text-sm opacity-80"><?= e($cardIntro) ?></p>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
