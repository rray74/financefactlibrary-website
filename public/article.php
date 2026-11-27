<?php
require_once __DIR__ . '/../includes/functions.php';

$slug = $_GET['slug'] ?? '';
$article = $slug ? getArticleBySlug($slug) : null;

if (!$article) {
    showErrorPage(404, 'Article not found.');
}

$pageTitle = $article['title'];

// Articles are a preview, not linked from navigation yet, so keep them
// out of search results until that stage is built.
$noindex = true;
$renderedBody = renderArticleBody($article['body']);
$sourceFacts = getFactsByKeys(extractFactKeysFromBody($article['body']));

include __DIR__ . '/../includes/header.php';
?>

<article>
    <p class="font-mono text-xs uppercase tracking-wide opacity-70">Article (preview — not yet linked in navigation)</p>

    <h1 class="font-display text-4xl font-semibold mt-2 mb-6 leading-tight">
        <?= e($article['title']) ?>
    </h1>

    <?php // Body is stored HTML with no classes, so the child elements are styled from here (replaces the old .article-body CSS, restyle Step 5). ?>
    <div class="max-w-2xl leading-relaxed [&_p]:mb-4 [&_h2]:font-display [&_h2]:text-2xl [&_h2]:font-semibold [&_h2]:mt-8 [&_h2]:mb-3 [&_h3]:font-display [&_h3]:text-xl [&_h3]:font-semibold [&_h3]:mt-6 [&_h3]:mb-2 [&_ul]:list-disc [&_ol]:list-decimal [&_ul]:pl-6 [&_ol]:pl-6 [&_ul]:mb-4 [&_ol]:mb-4 [&_a]:link">
        <?= $renderedBody ?>
    </div>

    <?php if (!empty($sourceFacts)): ?>
        <aside class="mt-12 border-t border-base-300 pt-6">
            <h2 class="font-mono text-xs uppercase tracking-wide opacity-70 mb-4">
                Figures used in this article
            </h2>
            <div class="grid sm:grid-cols-2 gap-4">
                <?php foreach ($sourceFacts as $fact): ?>
                    <div class="card card-border border-base-content/20 bg-base-100">
                        <div class="card-body p-4 gap-1">
                            <p class="text-sm opacity-80"><?= e($fact['label']) ?></p>
                            <?php // Same value formatting as the fact pages (e.g. £125,000, 4.25%). ?>
                            <p class="font-display text-2xl font-semibold tabular-nums break-words">
                                <?= e(formatFactValue($fact)) ?>
                            </p>
                            <p class="text-xs opacity-70">
                                <?= e($fact['source_name'] ?? '') ?>
                                &middot;
                                checked <?= date('j M Y', strtotime($fact['last_verified_at'] ?? $fact['last_updated'])) ?>
                            </p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </aside>
    <?php endif; ?>
</article>

<?php include __DIR__ . '/../includes/footer.php'; ?>
