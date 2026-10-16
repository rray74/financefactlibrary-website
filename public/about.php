<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/site-info.php';

$canonicalPath = '/about/';
redirectToCanonical($canonicalPath);

$pageTitle = 'About';
// Country-neutral, because this page is shared by every country on the site.
$metaDescription = 'Finance Fact Library publishes current personal finance figures, each taken from an official source, dated and kept up to date.';

// The live countries drive the wording below, so the page only ever claims
// coverage (and sources) for countries that are actually on the site.
$liveCountries = getActiveCountries();
$coveredNames = joinWithAnd(array_map('countryShortName', $liveCountries));

// "GOV.UK, HMRC ... for the UK; the IRS ... for the US". With one country
// live it's just that country's list, so the sentence reads as before.
$sourceExamples = [];
foreach ($liveCountries as $liveCountry) {
    $examples = countrySourceExamples($liveCountry);
    if ($examples === '') {
        continue;
    }
    $sourceExamples[] = count($liveCountries) > 1
        ? $examples . ' for ' . countryShortName($liveCountry)
        : $examples;
}

// "More countries to follow" only while some of the planned countries
// (those with wording in COUNTRY_WORDING) are still to go live.
$moreToCome = count($liveCountries) < count(COUNTRY_WORDING);

include __DIR__ . '/../includes/header.php';
?>

<article class="max-w-2xl">
    <h1 class="font-display text-4xl font-semibold mb-6">About Finance Fact Library</h1>

    <p class="text-lg opacity-80 mb-8">
        Finance Fact Library is a reference site for the figures that matter in personal finance:
        tax rates and allowances, pension limits, benefit rates, savings allowances and more.
        It currently covers <?= e($coveredNames) ?><?= $moreToCome ? ', with more countries to follow' : '' ?>.
    </p>

    <h2 class="font-display text-2xl font-semibold mb-3">What makes it different</h2>
    <p class="mb-4">
        Every figure on the site is a single, dated fact taken from an official source<?php
        // Examples come from COUNTRY_WORDING for each live country (see includes/functions.php).
        if ($sourceExamples): ?>, such as <?= e(implode('; ', $sourceExamples)) ?><?php endif; ?>.
        Each one links to where it came from and shows when it was last checked, so you can always
        see how current it is and confirm it yourself.
    </p>
    <p class="mb-4">
        When a figure changes, it's updated once and the change appears everywhere that figure is
        used, including the worked examples. The site keeps a record of every change, so each page
        shows what has changed and when.
    </p>
    <p class="mb-8">
        Read <a href="/how-we-check-our-figures/" class="underline hover:text-accent">how we check our figures</a>
        for the full detail of how sources are chosen and figures kept up to date.
    </p>

    <?php // Only shown once includes/site-info.php is filled in (see that file). ?>
    <?php if (SITE_OWNER_NAME !== ''): ?>
    <h2 class="font-display text-2xl font-semibold mb-3">Who runs this site</h2>
    <p class="mb-2 font-semibold"><?= e(SITE_OWNER_NAME) ?></p>
    <?php if (SITE_OWNER_BIO !== ''): ?>
    <p class="mb-8"><?= e(SITE_OWNER_BIO) ?></p>
    <?php endif; ?>
    <?php endif; ?>

    <h2 class="font-display text-2xl font-semibold mb-3">Independent and advert-free</h2>
    <p class="mb-8">
        The site isn't paid to feature any provider or product, and figures are chosen because they
        are useful, not because anyone is selling something.
    </p>

    <h2 class="font-display text-2xl font-semibold mb-3">Information, not advice</h2>
    <p class="mb-8">
        Finance Fact Library explains the rules and figures but can't tell you what to do in your own
        situation. See the <a href="/disclaimer/" class="underline hover:text-accent">disclaimer</a>
        for more.
    </p>

    <?php if (SITE_CONTACT_EMAIL !== ''): ?>
    <h2 class="font-display text-2xl font-semibold mb-3">Spotted a mistake?</h2>
    <p>
        Please email <a href="mailto:<?= e(SITE_CONTACT_EMAIL) ?>" class="underline hover:text-accent"><?= e(SITE_CONTACT_EMAIL) ?></a>
        with the page and figure, and it will be checked against its source.
    </p>
    <?php endif; ?>
</article>

<?php include __DIR__ . '/../includes/footer.php'; ?>
