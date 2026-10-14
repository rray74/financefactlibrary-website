<?php
require_once __DIR__ . '/../includes/functions.php';

// The bare domain (/) has no country, so send visitors to the default
// country's home. It's a temporary (302) redirect so that / can become a
// country picker later without search engines having cached the redirect.
if (($_GET['country'] ?? '') === '') {
    $default = getDefaultCountry();
    if (!$default) {
        showErrorPage(404, 'No countries are live yet.');
    }
    header('Location: ' . countryUrl($default['url_prefix']), true, 302);
    exit;
}

$country = getCountryByPrefix($_GET['country']);
if (!$country) {
    showErrorPage(404, 'Page not found.');
}

$canonicalPath = countryUrl($country['url_prefix']);
redirectToCanonical($canonicalPath);

$pageTitle = $country['name'] . ' finance facts';
$metaDescription = 'Rates, thresholds and figures that matter for ' . $country['name']
    . ' personal finance, checked against primary sources and dated.';
$categories = getCategoriesForCountry((int) $country['id']);

include __DIR__ . '/../includes/header.php';
?>

<section class="mb-14">
    <h1 class="font-display text-4xl sm:text-5xl font-semibold leading-tight mb-4 max-w-2xl">
        Finance facts, kept current.
    </h1>
    <p class="text-lg opacity-80 max-w-xl">
        Rates, thresholds and figures that matter for UK personal finance —
        checked against primary sources and dated, so you always know how current a figure is.
    </p>
</section>

<?php foreach ($categories as $category): ?>
<?php $subcategories = getSubcategoriesByCategoryId($category['id']); ?>
<?php if (empty($subcategories)) continue; ?>

<section class="mb-14">
    <div class="flex items-baseline justify-between border-b-2 border-primary pb-2 mb-6">
        <h2 class="font-display text-2xl font-semibold">
            <a href="<?= e(categoryUrl($country['url_prefix'], $category['slug'])) ?>" class="hover:text-accent">
                <?= e($category['name']) ?>
            </a>
        </h2>
    </div>

    <div class="grid sm:grid-cols-2 gap-8">
        <?php foreach ($subcategories as $subcategory): ?>
        <?php $subjects = getSubjectsBySubcategoryId($subcategory['id']); ?>
        <div>
            <h3 class="font-mono text-xs uppercase tracking-wide text-secondary mb-3">
                <?= e($subcategory['name']) ?>
            </h3>
            <ul class="space-y-2">
                <?php foreach ($subjects as $subject): ?>
                <li>
                    <a href="<?= e(subjectUrl($country['url_prefix'], $category['slug'], $subcategory['slug'], $subject['slug'])) ?>"
                        class="hover:text-accent transition-colors">
                        <?= e($subject['name']) ?>
                    </a>
                </li>
                <?php endforeach; ?>
                <?php if (empty($subjects)): ?>
                <li class="opacity-50 text-sm">Nothing here yet.</li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endforeach; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>