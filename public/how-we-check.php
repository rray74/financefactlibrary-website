<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/site-info.php';

$canonicalPath = '/how-we-check-our-figures/';
redirectToCanonical($canonicalPath);

$pageTitle = 'How we check our figures';
$metaDescription = 'How Finance Fact Library sources, dates and checks every figure: official sources only, a review schedule for each fact, and a record of every change.';

// Live numbers, so everything this page says about the data stays true.
$stats = getSourcingStats();
$totals = $stats['totals'];

include __DIR__ . '/../includes/header.php';
?>

<article class="max-w-2xl">
    <h1 class="font-display text-4xl font-semibold mb-6">How we check our figures</h1>

    <p class="text-lg opacity-80 mb-8">
        Accuracy is the whole point of this site. This page explains where the figures come from,
        how they're kept current, and what you can check for yourself.
    </p>

    <?php // Live summary from the database. ?>
    <div class="grid sm:grid-cols-3 gap-4 mb-10">
        <div class="fact-card p-5">
            <p class="text-sm opacity-70">Figures published</p>
            <p class="fact-value font-display text-3xl font-semibold text-primary"><?= number_format((int) $totals['facts']) ?></p>
        </div>
        <div class="fact-card p-5">
            <p class="text-sm opacity-70">Source pages used</p>
            <p class="fact-value font-display text-3xl font-semibold text-primary"><?= number_format((int) $totals['sources']) ?></p>
        </div>
        <div class="fact-card p-5">
            <p class="text-sm opacity-70">Most recent check</p>
            <p class="fact-value font-display text-2xl font-semibold text-primary">
                <?= $totals['last_checked'] ? date('j M Y', strtotime($totals['last_checked'])) : '—' ?>
            </p>
        </div>
    </div>

    <h2 class="font-display text-2xl font-semibold mb-3">Official sources first</h2>
    <p class="mb-4">
        Figures are taken from the organisation that sets or publishes them: government
        departments, tax authorities and public bodies. Each fact links to the exact page it came
        from. The figures on this site currently come from:
    </p>
    <ul class="mb-8 space-y-1">
        <?php foreach ($stats['publishers'] as $publisher): ?>
        <li class="flex justify-between border-b border-primary/20 py-1">
            <span><?= e($publisher['publisher']) ?></span>
            <span class="font-mono text-xs opacity-60"><?= (int) $publisher['facts'] ?> figures</span>
        </li>
        <?php endforeach; ?>
    </ul>

    <h2 class="font-display text-2xl font-semibold mb-3">Every figure has its own review schedule</h2>
    <p class="mb-4">
        How often a figure is checked depends on how often it can change. Savings rates such as the
        Premium Bonds prize fund rate are checked monthly. Tax rates and allowances, which normally
        change once a year, are checked at least twice a year, including after each Budget.
    </p>
    <p class="mb-8">
        Each figure shows the date it was last checked against its source. Where a figure has a
        known start date, such as the start of a tax year, that is shown too.
    </p>

    <h2 class="font-display text-2xl font-semibold mb-3">Changes are recorded, not overwritten</h2>
    <p class="mb-8">
        When a figure changes, the old and new values and the date of the change are kept. That
        history is what powers the "What's changed" section on each page, so you can see exactly
        how a rate or allowance has moved.
    </p>

    <h2 class="font-display text-2xl font-semibold mb-3">One figure, used everywhere</h2>
    <p class="mb-8">
        Each figure is stored once, even when it appears on several pages. The Personal Allowance,
        for example, is used on the Income Tax, Scottish Income Tax and savings pages. When it
        changes, every page and every worked example updates together, so the site can't disagree
        with itself.
    </p>

    <h2 class="font-display text-2xl font-semibold mb-3">Figures that differ around the UK</h2>
    <p class="mb-8">
        Some rules are different in Scotland, Wales or Northern Ireland, such as Income Tax bands
        and the taxes on buying a home. Those figures are labelled with the nation they apply to.
    </p>

    <h2 class="font-display text-2xl font-semibold mb-3">Worked examples</h2>
    <p class="mb-8">
        Worked examples are calculated from the current figures on the page, using the assumptions
        stated beneath each one. They are illustrations of how the rules work, not a calculation of
        your own position.
    </p>

    <?php if (SITE_CONTACT_EMAIL !== ''): ?>
    <h2 class="font-display text-2xl font-semibold mb-3">Reporting an error</h2>
    <p>
        If you think a figure is wrong or out of date, please email
        <a href="mailto:<?= e(SITE_CONTACT_EMAIL) ?>" class="underline hover:text-accent"><?= e(SITE_CONTACT_EMAIL) ?></a>.
        It will be checked against its source and corrected if needed.
    </p>
    <?php endif; ?>
</article>

<?php include __DIR__ . '/../includes/footer.php'; ?>
