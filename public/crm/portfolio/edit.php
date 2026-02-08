<?php
/**
 * Portfolio Project - Edit (redirect to create.php with id parameter)
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';

requireLogin();

$projectId = intval($_GET['id'] ?? 0);
if (!$projectId) {
    header("Location: index.php?error=invalid");
    exit;
}

// Redirect to create.php with the id parameter
header("Location: create.php?id={$projectId}");
exit;
