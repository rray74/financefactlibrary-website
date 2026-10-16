<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/site-info.php';

$canonicalPath = '/privacy/';
redirectToCanonical($canonicalPath);

$pageTitle = 'Privacy';
$metaDescription = 'How Finance Fact Library handles your data: no accounts, no tracking cookies and no advertising.';

// Change this whenever the wording below changes. Several of the privacy
// laws that apply to visitors (UK GDPR, California's CalOPPA, Australia's
// Privacy Act) expect a policy to show when it was last revised.
$lastUpdated = '15 October 2026';

include __DIR__ . '/../includes/header.php';
?>

<article class="max-w-2xl">
    <h1 class="font-display text-4xl font-semibold mb-6">Privacy</h1>

    <?php // Keep this page accurate: update it if analytics, forms or advertising are ever added. ?>
    <p class="text-lg opacity-80 mb-8">
        Finance Fact Library is a reference site. You don't need an account, and it doesn't ask you for
        any personal information.
    </p>

    <?php
    // UK GDPR (and the Australian and New Zealand privacy laws) expect the
    // policy to say who is responsible for the data. Names the owner once
    // SITE_OWNER_NAME is filled in, in includes/site-info.php.
    ?>
    <h2 class="font-display text-2xl font-semibold mb-3">Who is responsible for your data</h2>
    <p class="mb-8">
        Finance Fact Library is run from the United Kingdom<?= SITE_OWNER_NAME !== '' ? ' by ' . e(SITE_OWNER_NAME) : '' ?>,
        who is responsible for any personal information described on this page.
    </p>

    <h2 class="font-display text-2xl font-semibold mb-3">Cookies and tracking</h2>
    <p class="mb-4">
        The public site doesn't set any cookies, and there is no advertising, analytics or tracking.
    </p>
    <?php // California's CalOPPA requires a policy to say how the site responds to Do Not Track signals. ?>
    <p class="mb-8">
        Because nothing on the site tracks you, it works the same way whether or not your browser
        sends a Do Not Track or Global Privacy Control signal.
    </p>

    <h2 class="font-display text-2xl font-semibold mb-3">Server logs</h2>
    <?php
    // The legal basis sentence is for UK GDPR, which requires one for any
    // processing. Legitimate interests is the right basis for security logs.
    ?>
    <p class="mb-8">
        Like almost every website, the servers that host Finance Fact Library automatically record
        technical information about each visit, such as your IP address, browser type and the pages
        requested. This is used only to keep the site running securely, is not used to identify you,
        and is kept for a limited period before being deleted. Under UK data protection law, the
        legal basis for this is the legitimate interest in keeping the site secure and working.
    </p>

    <h2 class="font-display text-2xl font-semibold mb-3">Fonts</h2>
    <p class="mb-8">
        The site's fonts are loaded from Google Fonts, which means your browser connects to Google's
        servers and shares your IP address with Google when a page loads. See Google's privacy policy
        for how it handles this.
    </p>

    <?php
    // Australia (APP 8), New Zealand (IPP 12) and UK GDPR all ask sites to
    // say when information may go to another country. The site's hosting
    // and fonts both involve overseas providers, so it's stated here.
    ?>
    <h2 class="font-display text-2xl font-semibold mb-3">Where your information goes</h2>
    <p class="mb-8">
        The site is hosted by Hostinger, and its fonts come from Google. Both may process the
        technical information above in countries other than the one you're in. The site doesn't
        sell or share personal information with anyone else.
    </p>

    <?php if (SITE_CONTACT_EMAIL !== ''): ?>
    <h2 class="font-display text-2xl font-semibold mb-3">Contacting the site</h2>
    <p class="mb-8">
        If you email Finance Fact Library, your email is used only to reply to you, and isn't added
        to any mailing list.
    </p>
    <?php endif; ?>

    <?php // COPPA (US) and similar rules elsewhere: say plainly the site isn't aimed at children. ?>
    <h2 class="font-display text-2xl font-semibold mb-3">Children</h2>
    <p class="mb-8">
        The site isn't aimed at children and doesn't knowingly collect personal information from
        anyone under 13.
    </p>

    <?php
    // Each target country gives people rights over their information and a
    // regulator to complain to, so all five are named in one place.
    ?>
    <h2 class="font-display text-2xl font-semibold mb-3">Your rights</h2>
    <p class="mb-4">
        Depending on where you live, you may have the right to ask what personal information is held
        about you, and to have it corrected or deleted.<?php if (SITE_CONTACT_EMAIL !== ''): ?>
        To do this, or for any privacy question, email
        <a href="mailto:<?= e(SITE_CONTACT_EMAIL) ?>" class="underline hover:text-accent"><?= e(SITE_CONTACT_EMAIL) ?></a>.<?php endif; ?>
    </p>
    <p class="mb-8">
        If you're not happy with the reply, you can complain to the privacy regulator where you live:
        the Information Commissioner's Office in the UK, the Office of the Privacy Commissioner of
        Canada, the Office of the Australian Information Commissioner, or the Office of the Privacy
        Commissioner in New Zealand. In the US, you can contact your state attorney general or the
        Federal Trade Commission.
    </p>

    <p class="text-sm opacity-70">Last updated <?= e($lastUpdated) ?>.</p>
</article>

<?php include __DIR__ . '/../includes/footer.php'; ?>
