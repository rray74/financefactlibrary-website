<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/site-info.php';

$canonicalPath = '/privacy/';
redirectToCanonical($canonicalPath);

$pageTitle = 'Privacy';
$metaDescription = 'How Finance Fact Library handles your data: no accounts, no tracking cookies and no advertising.';

include __DIR__ . '/../includes/header.php';
?>

<article class="max-w-2xl">
    <h1 class="font-display text-4xl font-semibold mb-6">Privacy</h1>

    <?php // Keep this page accurate: update it if analytics, forms or advertising are ever added. ?>
    <p class="text-lg opacity-80 mb-8">
        Finance Fact Library is a reference site. You don't need an account, and it doesn't ask you for
        any personal information.
    </p>

    <h2 class="font-display text-2xl font-semibold mb-3">Cookies</h2>
    <p class="mb-8">
        The public site doesn't set any cookies, and there is no advertising or tracking.
    </p>

    <h2 class="font-display text-2xl font-semibold mb-3">Server logs</h2>
    <p class="mb-8">
        Like almost every website, the servers that host Finance Fact Library automatically record
        technical information about each visit, such as your IP address, browser type and the pages
        requested. This is used only to keep the site running securely and is not used to identify
        you.
    </p>

    <h2 class="font-display text-2xl font-semibold mb-3">Fonts</h2>
    <p class="mb-8">
        The site's fonts are loaded from Google Fonts, which means your browser connects to Google's
        servers and shares your IP address with Google when a page loads. See Google's privacy policy
        for how it handles this.
    </p>

    <?php if (SITE_CONTACT_EMAIL !== ''): ?>
    <h2 class="font-display text-2xl font-semibold mb-3">Contact</h2>
    <p>
        If you email Finance Fact Library, your email is used only to reply to you. For any privacy
        question, contact <a href="mailto:<?= e(SITE_CONTACT_EMAIL) ?>" class="underline hover:text-accent"><?= e(SITE_CONTACT_EMAIL) ?></a>.
    </p>
    <?php endif; ?>
</article>

<?php include __DIR__ . '/../includes/footer.php'; ?>
