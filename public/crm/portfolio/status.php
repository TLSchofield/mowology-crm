<?php
/**
 * Portfolio Project - Publish / Unpublish handler
 *
 * Switches one project between 'draft' (hidden) and 'published' (shown on the public portfolio page).
 * Same rules as delete.php: portfolio.edit permission + POST + CSRF, and it only ever goes back to the project page.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('portfolio.edit');

$projectId = intval($_POST['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
    header('Location: ' . ($projectId ? "view.php?id={$projectId}" : 'index.php'));
    exit;
}
$target = (string)($_POST['status'] ?? '');
if (!$projectId || !in_array($target, ['draft', 'published'], true)) {
    header('Location: index.php?error=invalid');
    exit;
}

$project = getPortfolioProject($projectId);
if (!$project) {
    header('Location: index.php?error=not_found');
    exit;
}

if ($project['status'] !== $target) {
    if (!updatePortfolioProject($projectId, ['status' => $target])) {
        header("Location: view.php?id={$projectId}&error=status_failed");
        exit;
    }
    logActivityExtended($user['id'], 'Portfolio project ' . ($target === 'published' ? 'published' : 'unpublished'),
        "Project '{$project['project_name']}' ({$project['project_number']}) set to {$target}", null, null, null, null, null);
}
header("Location: view.php?id={$projectId}&status_changed=" . $target);
exit;
