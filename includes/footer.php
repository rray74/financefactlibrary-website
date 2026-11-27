    </main>

    <?php
    // Restyle Step 3: daisyUI footer. Stacks on a phone and sits in columns from sm up. The trust pages
    // are linked from every page, which is where readers and search engines look for them.
    ?>
    <footer class="bg-base-200 border-t border-base-300 mt-8">
        <div class="footer sm:footer-horizontal max-w-4xl mx-auto px-4 sm:px-6 py-8 text-sm gap-y-6">
            <nav aria-label="About this site">
                <h2 class="footer-title">About this site</h2>
                <a href="/about/" class="link link-hover py-3">About</a>
                <a href="/how-we-check-our-figures/" class="link link-hover py-3">How we check our figures</a>
                <a href="/disclaimer/" class="link link-hover py-3">Disclaimer</a>
                <a href="/privacy/" class="link link-hover py-3">Privacy</a>
            </nav>
            <aside class="max-w-md">
                <?php // SITE_NAME comes from includes/site-info.php, loaded by header.php, so the name is set in one place. ?>
                <p>&copy; <?= date('Y') ?> <?= e(SITE_NAME) ?>. Every figure is sourced and dated individually.</p>
                <?php
                // States plainly that this is an independent information site, not a
                // regulated firm, so no one mistakes it for a broker or adviser with a
                // similar name. Links to the full disclaimer for the detail.
                ?>
                <p class="opacity-70">
                    Independent information site. General information, not regulated financial advice.
                    <a href="/disclaimer/" class="link">Read the disclaimer</a>.
                </p>
            </aside>
        </div>
    </footer>

</body>
</html>
