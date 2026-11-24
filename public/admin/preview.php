<?php
/**
 * Preview a subject page exactly as the public site would show it, for review.
 *
 * WHY IT EXISTS. A draft, or a page in a country that is not switched on yet (New Zealand before launch),
 * answers "Subject not found" on the public address, so a reviewer had no way to see the real layout:
 * the figure cards, the filled-in placeholders and the explanation. This page renders the public
 * template (public/fact.php) in preview mode for any subject, whatever its status.
 *
 * SAFETY. requireAdmin() comes first, so only a logged-in admin can see it, and the page is sent
 * with noindex, nofollow. The public route is untouched: it never defines FFL_PREVIEW.
 *
 * Usage: /admin/preview.php?id=<subject id>   (the editor has a "Preview page" link)
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/admin-auth.php';

// Must be first: sends anyone not logged in to /admin/login.php.
requireAdmin();

$subjectId = (int) ($_GET['id'] ?? 0);
$stmt = getDbConnection()->prepare('SELECT slug FROM subjects WHERE id = :id');
$stmt->execute(['id' => $subjectId]);
$slug = $stmt->fetchColumn();

if (!$slug) {
    showErrorPage(404, 'Subject not found.');
}

// fact.php finds the subject by slug (slugs are unique across countries).
$_GET['slug'] = (string) $slug;
define('FFL_PREVIEW', true);
require __DIR__ . '/../fact.php';
