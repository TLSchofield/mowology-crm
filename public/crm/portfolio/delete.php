<?php
/**
 * Portfolio Project - Delete Handler
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

$projectId = intval($_GET['id'] ?? 0);
if (!$projectId) {
    header("Location: index.php?error=invalid");
    exit;
}

$project = getPortfolioProject($projectId);
if (!$project) {
    header("Location: index.php?error=not_found");
    exit;
}

// Require CSRF token for safety (GET-based delete, so using referrer check)
if ($_SERVER['HTTP_REFERER'] && strpos($_SERVER['HTTP_REFERER'], '/crm/portfolio/') !== false) {
    if (deletePortfolioProject($projectId)) {
        logActivityExtended($user['id'], 'Portfolio project deleted', "Project '{$project['project_name']}' deleted", null, null, null, null, null);
        header("Location: index.php?success=deleted");
        exit;
    } else {
        header("Location: view.php?id={$projectId}&error=delete_failed");
        exit;
    }
} else {
    header("Location: view.php?id={$projectId}");
    exit;
}
