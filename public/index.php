<?php
require_once __DIR__ . '/../includes/functions.php';

// The bare domain (/) has no country. Instead of redirecting visitors
// to a country home (which was a 302 and meant the homepage never
// returned a page of its own), / now serves a real 200 page: a short
// "choose your country" landing page. This is what Google's Change of
// Address tool, and any old financial-facts.com links, land on, so it
// must load as a page and not bounce on to somewhere else.
if (($_GET['country'] ?? '') === '') {
    $default = getDefaultCountry();
    if (!$default) {
        showErrorPage(404, 'No countries are live yet.');
    }

    // Same variable the country home sets below, so the header treats
    // the bare domain as its own canonical address ("/").
    $canonicalPath = '/';

    $pageTitle = 'Finance Fact Library';
    $metaDescription = 'Rates, thresholds and figures that matter for personal finance, '
        . 'checked against primary sources and dated.';

    include __DIR__ . '/../includes/header.php';
    ?>

    <section class="mb-14">
        <h1 class="font-display text-4xl sm:text-5xl font-semibold leading-tight mb-4 max-w-2xl">
            Finance facts, kept current.
        </h1>
        <p class="text-lg opacity-80 max-w-xl">
            Rates, thresholds and figures that matter for personal finance,
            checked against primary sources and dated, so you always know how current a figure is.
        </p>
    </section>

    <section class="mb-14">
        <h2 class="font-mono text-xs uppercase tracking-wide opacity-70 mb-4">
            Choose your country
        </h2>
        <ul class="grid sm:grid-cols-2 gap-4 max-w-xl">
            <?php // One card per live country. A country appears here as soon
                  // as it's marked is_active in the jurisdictions table, so
                  // nothing needs editing on this page when the US, Canada,
                  // Australia or New Zealand go live. ?>
            <?php foreach (getActiveCountries() as $liveCountry): ?>
            <li>
                <?php // daisyUI card as a link (restyle Step 5): the whole card is the tap target. ?>
                <a href="<?= e(countryUrl($liveCountry['url_prefix'])) ?>"
                    class="card card-border border-base-content/20 bg-base-100 hover:bg-base-200 transition-colors">
                    <span class="card-body p-5">
                        <span class="card-title font-display text-2xl"><?= e($liveCountry['name']) ?></span>
                    </span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$country = getCountryByPrefix($_GET['country']);
if (!$country) {
    showErrorPage(404, 'Page not found.');
}

$canonicalPath = countryUrl($country['url_prefix']);
redirectToCanonical($canonicalPath);

// "UK", "Canadian", "Australian"... from COUNTRY_WORDING in functions.php,
// so the title, description and intro read naturally for each country
// instead of always saying UK (or the stiffer "United Kingdom").
$countryAdjective = countryAdjective($country);

$pageTitle = $countryAdjective . ' finance facts';
$metaDescription = 'Rates, thresholds and figures that matter for ' . $countryAdjective
    . ' personal finance, checked against primary sources and dated.';
$categories = getCategoriesForCountry((int) $country['id']);

include __DIR__ . '/../includes/header.php';
?>

<section class="mb-14">
    <h1 class="font-display text-4xl sm:text-5xl font-semibold leading-tight mb-4 max-w-2xl">
        Finance facts, kept current.
    </h1>
    <p class="text-lg opacity-80 max-w-xl">
        <?php // Country taken from the URL, not hard-coded, so each country home gets its own intro. ?>
        Rates, thresholds and figures that matter for <?= e($countryAdjective) ?> personal finance,
        checked against primary sources and dated, so you always know how current a figure is.
    </p>
</section>

<?php foreach ($categories as $category): ?>
<?php $subcategories = getSubcategoriesByCategoryId($category['id']); ?>
<?php if (empty($subcategories)) continue; ?>

<section class="mb-14">
    <div class="flex items-baseline justify-between border-b border-base-300 pb-2 mb-6">
        <h2 class="font-display text-2xl font-semibold">
            <a href="<?= e(categoryUrl($country['url_prefix'], $category['slug'])) ?>" class="link link-hover">
                <?= e($category['name']) ?>
            </a>
        </h2>
    </div>

    <div class="grid sm:grid-cols-2 gap-8">
        <?php foreach ($subcategories as $subcategory): ?>
        <?php $subjects = getSubjectsBySubcategoryId($subcategory['id']); ?>
        <div>
            <h3 class="font-mono text-xs uppercase tracking-wide opacity-70 mb-2">
                <?= e($subcategory['name']) ?>
            </h3>
            <ul>
                <?php foreach ($subjects as $subject): ?>
                <li>
                    <a href="<?= e(subjectUrl($country['url_prefix'], $category['slug'], $subcategory['slug'], $subject['slug'])) ?>"
                        class="link link-hover inline-block py-2">
                        <?= e($subject['name']) ?>
                    </a>
                </li>
                <?php endforeach; ?>
                <?php if (empty($subjects)): ?>
                <li class="opacity-70 text-sm py-2">Nothing here yet.</li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endforeach; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>