<?php
require_once __DIR__ . '/../../includes/admin-auth.php';

startAdminSession();

if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(16));
}

$next = safeAdminRedirect($_GET['next'] ?? $_POST['next'] ?? null);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['login_csrf'], $token)) {
        $error = 'The form expired. Please try again.';
    } elseif (adminCredentialsValid(trim($_POST['username'] ?? ''), $_POST['password'] ?? '')) {
        logAdminIn();
        header('Location: ' . $next);
        exit;
    } else {
        // Slow down repeated guessing. One deliberate second per failed
        // attempt doesn't bother a real user but makes guessing very slow.
        sleep(1);
        $error = 'Username or password not recognised.';
    }
}

$pageTitle = 'Admin login';
$noindex = true;
include __DIR__ . '/../../includes/header.php';
?>

<section class="max-w-sm">
    <h1 class="font-display text-3xl font-semibold mb-6">Admin login</h1>

    <?php if (!adminLoginConfigured()): ?>
    <?php // Fail closed: without login details in the config, nobody can log in. ?>
    <div class="alert alert-error mb-6">
        <span>Admin login isn't set up on this server yet. Add ADMIN_USERNAME and
            ADMIN_PASSWORD_HASH to config/database.local.php
            (run php scripts/make-admin-password.php to create them).</span>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-error mb-6"><span><?= e($error) ?></span></div>
    <?php endif; ?>

    <form method="post" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['login_csrf']) ?>">
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <label class="block">
            <span class="text-sm">Username</span>
            <input type="text" name="username" autocomplete="username" required autofocus
                class="input w-full mt-1">
        </label>
        <label class="block">
            <span class="text-sm">Password</span>
            <input type="password" name="password" autocomplete="current-password" required
                class="input w-full mt-1">
        </label>
        <button type="submit" class="btn btn-primary w-full">Log in</button>
    </form>
</section>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
