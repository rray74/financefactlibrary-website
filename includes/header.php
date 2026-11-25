<?php
/*
 * Variables a page can set before including this file:
 *   $pageTitle        shown before " — <site name>" in the <title>
 *   $metaDescription  the search result snippet (trimmed to 160 characters)
 *   $canonicalPath    the page's one true path, e.g. /uk/mortgages/, which
 *                     becomes the canonical tag's full URL
 *   $noindex          true to keep the page out of search results (admin,
 *                     previews, error pages)
 *   $country          the page's country, which decides the navigation
 */

// The site's name lives in one place, includes/site-info.php, so a future
// rename is a one-line change. require_once is safe even if another file
// has already loaded it.
require_once __DIR__ . '/site-info.php';

/**
 * A site asset's address with its last-changed time on the end, such as
 * /assets/css/tailwind.css?v=1761170000. When the file changes the address
 * changes with it, so browsers and any server cache fetch the new file
 * instead of keeping the old one. Without this, a rebuilt stylesheet can
 * be deployed and still not show: the address never changed, so an old copy
 * stays in use (this hid the dark-text and invisible-button fixes).
 *
 * $path is the address from the site root. Falls back to the plain address
 * if the file can't be found.
 */
if (!function_exists('assetUrl')) {
    function assetUrl(string $path): string
    {
        $file = __DIR__ . '/../public' . $path;
        $modified = is_file($file) ? filemtime($file) : false;
        return $modified ? $path . '?v=' . $modified : $path;
    }
}

// Fall back to the site name on pages that don't set their own title.
$pageTitle = $pageTitle ?? SITE_NAME;

// Pages that aren't tied to a country (admin, articles, error pages) get
// the default country's navigation.
$navCountry = $country ?? getDefaultCountry();
$navCategories = $navCountry ? getCategoriesForCountry((int) $navCountry['id']) : [];
$homeUrl = $navCountry ? countryUrl($navCountry['url_prefix']) : '/';

// e.g. en-GB for the UK, so browsers and search engines know the regional
// variant of English the page is written in.
$htmlLang = $navCountry['locale'] ?? 'en';

// Search engines show roughly 155 to 160 characters, so trim anything
// longer (such as a long subject intro). The /u regex counts characters
// rather than bytes, so £ and other symbols aren't cut in half, and it
// doesn't depend on the mbstring extension being installed.
if (!empty($metaDescription)) {
    $metaDescription = preg_replace('/^(.{157}).{4,}$/su', '$1…', trim($metaDescription));
}
?>
<!DOCTYPE html>
<?php // No data-theme here: with none set, daisyUI follows the device's light or dark setting. The script in <head> adds one only if the visitor has picked a theme. ?>
<html lang="<?= e($htmlLang) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php // Site name taken from SITE_NAME rather than typed out here. ?>
    <title><?= e($pageTitle) ?> — <?= e(SITE_NAME) ?></title>

    <?php if (!empty($metaDescription)): ?>
    <meta name="description" content="<?= e($metaDescription) ?>">
    <?php endif; ?>

    <?php // One address per page, stated explicitly so search engines never split rankings between variants. ?>
    <?php if (!empty($canonicalPath)): ?>
    <link rel="canonical" href="<?= e(absoluteUrl($canonicalPath)) ?>">
    <?php endif; ?>

    <?php if (!empty($noindex)): ?>
    <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>

    <?php
    // Applies a saved light or dark choice before the page is drawn, so there is no flash of the wrong
    // theme. Storage can be blocked (private windows), so every use is in try/catch and the page works
    // without it. Also wires the toggle button(s) marked data-theme-toggle: they switch to the other
    // theme, save the choice, and keep their accessible name right ("Switch to dark mode"). A
    // [data-theme-label] span inside the button, if present, shows the same words on wider screens.
    ?>
    <script>
    (function () {
        var KEY = 'ffl-theme', root = document.documentElement;
        try {
            var saved = localStorage.getItem(KEY);
            if (saved === 'light' || saved === 'dark') { root.setAttribute('data-theme', saved); }
        } catch (e) {}
        function current() {
            return root.getAttribute('data-theme') ||
                (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        }
        function label() {
            document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
                var text = current() === 'dark' ? 'Light mode' : 'Dark mode';
                b.setAttribute('aria-label', 'Switch to ' + text.toLowerCase());
                var span = b.querySelector('[data-theme-label]');
                if (span) { span.textContent = text; }
            });
        }
        document.addEventListener('DOMContentLoaded', function () {
            label();
            document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
                b.addEventListener('click', function () {
                    var next = current() === 'dark' ? 'light' : 'dark';
                    root.setAttribute('data-theme', next);
                    try { localStorage.setItem(KEY, next); } catch (e) {}
                    label();
                });
            });
        });
    })();
    </script>

    <!-- Compiled by Tailwind CLI + DaisyUI (see package.json / README "Node build tooling").
         Run `npm run build` after any src/ change, then commit the output. -->
    <?php // assetUrl() puts the file's last-changed time on the address, so a rebuilt file is never missed. ?>
    <link rel="stylesheet" href="<?= e(assetUrl('/assets/css/tailwind.css')) ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,600;9..144,700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@500&display=swap"
        rel="stylesheet">

    <script src="<?= e(assetUrl('/assets/js/main.js')) ?>" defer></script>
</head>

<body class="bg-base-100 text-base-content font-sans min-h-screen flex flex-col">

    <?php
    // Restyle Step 3: daisyUI navbar. Below the md breakpoint the category links collapse into a dropdown
    // menu; from md up they sit on a second row (no JavaScript needed: daisyUI's dropdown opens on focus), because five or more links don't fit
    // on a phone. The wordmark and links come from SITE_NAME and the country's categories, not typed here.
    // Buttons are the default size (3rem tall) so they are comfortable touch targets.
    ?>
    <header class="bg-base-100 border-b border-base-300">
        <div class="navbar max-w-4xl mx-auto px-4 sm:px-6 min-h-16 gap-1">
            <div class="navbar-start w-auto flex-1 min-w-0 gap-1">
                <?php if (!empty($navCategories)): ?>
                <div class="dropdown md:hidden">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-square" aria-label="Open menu">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </div>
                    <ul tabindex="0" class="menu dropdown-content bg-base-100 rounded-box z-10 mt-2 w-56 p-2 shadow-lg border border-base-300">
                        <?php foreach ($navCategories as $cat): ?>
                        <li><a href="<?= e(categoryUrl($navCountry['url_prefix'], $cat['slug'])) ?>"><?= e($cat['name']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <?php // Wordmark uses SITE_NAME so it always matches the <title>. ?>
                <a href="<?= e($homeUrl) ?>" class="font-display text-lg sm:text-2xl font-semibold tracking-tight leading-tight py-2">
                    <?= e(SITE_NAME) ?>
                </a>
            </div>
            <?php // Light and dark toggle, wired by the script in <head>. Icon only on a phone, icon and words from sm up. ?>
            <div class="navbar-end w-auto flex-none">
                <button type="button" class="btn btn-ghost px-3" data-theme-toggle aria-label="Switch to dark mode">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="12" cy="12" r="9" />
                        <path d="M12 3a9 9 0 0 0 0 18z" fill="currentColor" />
                    </svg>
                    <span data-theme-label class="hidden sm:inline">Dark mode</span>
                </button>
            </div>
        </div>
        <?php
        // From md up the category links sit on their own row under the wordmark, so any number of categories
        // fits at any width (five or more beside the wordmark and toggle do not). menu-horizontal wraps if needed.
        ?>
        <?php if (!empty($navCategories)): ?>
        <nav class="hidden md:block max-w-4xl mx-auto px-4 sm:px-6 pb-2" aria-label="Main">
            <ul class="menu menu-horizontal -ml-5 flex-wrap">
                <?php foreach ($navCategories as $cat): ?>
                <li><a href="<?= e(categoryUrl($navCountry['url_prefix'], $cat['slug'])) ?>"><?= e($cat['name']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <?php endif; ?>
    </header>

    <main class="flex-1 max-w-4xl mx-auto px-4 sm:px-6 py-8 sm:py-10 w-full">
