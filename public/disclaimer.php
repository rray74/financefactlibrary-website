<?php
require_once __DIR__ . '/../includes/functions.php';

$canonicalPath = '/disclaimer/';
redirectToCanonical($canonicalPath);

$pageTitle = 'Disclaimer';
$metaDescription = 'Finance Fact Library provides general information about UK personal finance figures, not financial, tax or legal advice.';

include __DIR__ . '/../includes/header.php';
?>

<article class="max-w-2xl">
    <h1 class="font-display text-4xl font-semibold mb-6">Disclaimer</h1>

    <h2 class="font-display text-2xl font-semibold mb-3">General information only</h2>
    <p class="mb-8">
        The information on Finance Fact Library is for general guidance about UK rules and figures. It is
        not financial, tax, investment or legal advice, and it doesn't take your personal
        circumstances into account. For advice about your own situation, speak to a qualified,
        regulated adviser.
    </p>

    <h2 class="font-display text-2xl font-semibold mb-3">Figures can change</h2>
    <p class="mb-8">
        Every figure is taken from an official source and shows when it was last checked, but rates
        and allowances can change at short notice, for example in a Budget. Always confirm a figure
        with its official source, linked beside it, before relying on it for a decision.
    </p>

    <h2 class="font-display text-2xl font-semibold mb-3">Worked examples</h2>
    <p class="mb-8">
        Worked examples are simplified illustrations based on the assumptions stated with them.
        Your own tax or entitlement may be different.
    </p>

    <h2 class="font-display text-2xl font-semibold mb-3">Not regulated advice</h2>
    <p class="mb-8">
        Finance Fact Library is not authorised or regulated by the Financial Conduct Authority and does
        not recommend any product or provider.
    </p>

    <h2 class="font-display text-2xl font-semibold mb-3">Links to other websites</h2>
    <p class="mb-8">
        Source links go to official websites that Finance Fact Library doesn't control. Their content
        may change or move after a figure was checked.
    </p>

    <h2 class="font-display text-2xl font-semibold mb-3">Liability</h2>
    <p>
        While every effort is made to keep the figures accurate and current, Finance Fact Library can't
        guarantee that all information is complete or error-free, and accepts no liability for any
        loss arising from reliance on it.
    </p>
</article>

<?php include __DIR__ . '/../includes/footer.php'; ?>
