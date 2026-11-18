<?php
/**
 * Portfolio Project - Delete Handler
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
// Deleting a project is a manager's call, and only from the confirm button:
// portfolio.edit + POST + CSRF (Fable review 2026-10-12 — it was a GET with a Referer check).
requirePermission('portfolio.edit');

$projectId = intval($_POST['id'] ?? $_GET['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
    header('Location: ' . ($projectId ? "view.php?id={$projectId}" : 'index.php'));
    exit;
}
if (!$projectId) {
    header("Location: index.php?error=invalid");
    exit;
}

$project = getPortfolioProject($projectId);
if (!$project) {
    header("Location: index.php?error=not_found");
    exit;
}

if (deletePortfolioProject($projectId)) {
    logActivityExtended($user['id'], 'Portfolio project deleted', "Project '{$project['project_name']}' deleted", null, null, null, null, null);
    header("Location: index.php?success=deleted");
    exit;
}
header("Location: view.php?id={$projectId}&error=delete_failed");
exit;
