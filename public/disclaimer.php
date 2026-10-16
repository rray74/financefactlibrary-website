<?php
require_once __DIR__ . '/../includes/functions.php';

$canonicalPath = '/disclaimer/';
redirectToCanonical($canonicalPath);

$pageTitle = 'Disclaimer';
// Country-neutral: one disclaimer covers every country on the site.
$metaDescription = 'Finance Fact Library provides general information about personal finance figures, not financial, tax or legal advice.';

// Change this whenever the wording below changes, so readers can see
// which version they're reading.
$lastUpdated = '15 October 2026';

include __DIR__ . '/../includes/header.php';
?>

<article class="max-w-2xl">
    <h1 class="font-display text-4xl font-semibold mb-6">Disclaimer</h1>

    <h2 class="font-display text-2xl font-semibold mb-3">General information only</h2>
    <?php
    // "Objectives, financial situation or needs" is the phrase Australian
    // readers expect in a general-information warning, and it reads just as
    // plainly in the UK, US, Canada and New Zealand. "Licensed or regulated
    // where you live" covers the different adviser systems in each country.
    ?>
    <p class="mb-8">
        The information on Finance Fact Library is general guidance about the rules and figures in
        each country the site covers. It is not financial, tax, investment or legal advice, and it
        doesn't take into account your objectives, financial situation or needs. For advice about
        your own situation, speak to a qualified adviser who is licensed or regulated where you live.
    </p>

    <h2 class="font-display text-2xl font-semibold mb-3">Figures can change</h2>
    <?php
    // "A Budget" was UK-specific. Every country here changes figures at
    // budget time or at the start of a tax year, though their tax years
    // start on different dates.
    ?>
    <p class="mb-4">
        Every figure is taken from an official source and shows when it was last checked, but rates
        and allowances can change at short notice, for example when a government announces a budget
        or a new tax year begins. Always confirm a figure with its official source, linked beside it,
        before relying on it for a decision.
    </p>
    <?php // New for international visitors: the same topic has different figures in each country. ?>
    <p class="mb-8">
        Rules and figures differ between countries, and sometimes between states, provinces or
        nations within a country, so check you're reading the pages for the right place.
    </p>

    <h2 class="font-display text-2xl font-semibold mb-3">Worked examples</h2>
    <p class="mb-8">
        Worked examples are simplified illustrations based on the assumptions stated with them.
        Your own tax or entitlement may be different.
    </p>

    <h2 class="font-display text-2xl font-semibold mb-3">Not regulated advice</h2>
    <?php
    // Previously this only mentioned the FCA. Each target country has its
    // own licensing system, so the site says plainly that it isn't part of
    // any of them, in the terms readers in each country will recognise.
    ?>
    <p class="mb-4">
        Finance Fact Library doesn't hold any financial services licence, registration or
        authorisation, and doesn't recommend any product or provider. In particular, it is not:
    </p>
    <ul class="list-disc pl-6 space-y-1 mb-4">
        <li>authorised or regulated by the Financial Conduct Authority (UK)</li>
        <li>a registered investment adviser or broker-dealer (US)</li>
        <li>registered with any provincial or territorial securities regulator (Canada)</li>
        <li>the holder of an Australian financial services licence (Australia)</li>
        <li>a licensed financial advice provider (New Zealand)</li>
    </ul>
    <p class="mb-8">
        Nothing on the site is a recommendation, or an offer to buy or sell any financial product.
    </p>

    <h2 class="font-display text-2xl font-semibold mb-3">Links to other websites</h2>
    <p class="mb-8">
        Source links go to official websites that Finance Fact Library doesn't control. Their content
        may change or move after a figure was checked.
    </p>

    <h2 class="font-display text-2xl font-semibold mb-3">Liability</h2>
    <p class="mb-4">
        The site is provided free of charge and "as is". While every effort is made to keep the
        figures accurate and current, Finance Fact Library can't guarantee that all information is
        complete or error-free, and accepts no liability for any loss arising from reliance on it.
    </p>
    <?php
    // Consumer laws in the UK, Australia and New Zealand don't allow some
    // rights to be excluded. Without this line, a blanket exclusion can be
    // treated as misleading or unenforceable there.
    ?>
    <p class="mb-8">
        Nothing in this disclaimer limits or excludes any liability, or any of your rights, that
        can't be limited or excluded under the law that applies to you, such as consumer protection
        law in your country.
    </p>

    <?php
    // The site is run from the UK, so English law is the natural choice.
    // Remove this section if you'd rather not name a governing law.
    ?>
    <h2 class="font-display text-2xl font-semibold mb-3">Governing law</h2>
    <p class="mb-8">
        This disclaimer is governed by the law of England and Wales.
    </p>

    <p class="text-sm opacity-70">Last updated <?= e($lastUpdated) ?>.</p>
</article>

<?php include __DIR__ . '/../includes/footer.php'; ?>
