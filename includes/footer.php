    </main>

    <footer class="border-t-2 border-primary">
        <div class="max-w-4xl mx-auto px-6 py-8 text-sm">
            <?php // Links to the trust pages appear site-wide, which is where readers and search engines look for them. ?>
            <nav class="flex flex-wrap gap-x-6 gap-y-2 mb-4">
                <a href="/about/" class="hover:text-accent">About</a>
                <a href="/how-we-check-our-figures/" class="hover:text-accent">How we check our figures</a>
                <a href="/disclaimer/" class="hover:text-accent">Disclaimer</a>
                <a href="/privacy/" class="hover:text-accent">Privacy</a>
            </nav>
            <div class="flex flex-col sm:flex-row justify-between gap-4">
                <?php // SITE_NAME comes from includes/site-info.php, loaded by header.php, so the name is set in one place. ?>
                <p>&copy; <?= date('Y') ?> <?= e(SITE_NAME) ?>. Every figure is sourced and dated individually.</p>
                <?php
                // States plainly that this is an independent information site, not a
                // regulated firm, so no one mistakes it for a broker or adviser with a
                // similar name. Links to the full disclaimer for the detail.
                ?>
                <p class="font-mono text-xs opacity-70 sm:text-right">
                    Independent information site. General information, not regulated financial advice.
                    <a href="/disclaimer/" class="underline hover:text-accent">Read the disclaimer</a>.
                </p>
            </div>
        </div>
    </footer>

</body>
</html>
