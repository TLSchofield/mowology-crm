<?php
/**
 * Migration: Add product archiving support
 * Run this once to update existing installations
 *
 * Usage: Visit this URL in your browser after backing up your database
 */

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/../app_config/config.php';

// Security: Only admins can run migrations
requireLogin();
$user = getCurrentUser();

if ($user['role'] !== 'admin') {
    http_response_code(403);
    die('Migration requires admin access.');
}

$db = getDB();

try {
    // Check if is_archived column exists
    $result = $db->query("SHOW COLUMNS FROM products LIKE 'is_archived'");
    if ($result->rowCount() > 0) {
        $status = 'Column is_archived already exists. Skipping.';
    } else {
        // Add is_archived and archive tracking columns
        $db->exec("ALTER TABLE products ADD COLUMN is_archived TINYINT(1) DEFAULT 0 AFTER featured");
        $db->exec("ALTER TABLE products ADD COLUMN archived_at TIMESTAMP NULL AFTER updated_at");
        $db->exec("ALTER TABLE products ADD COLUMN archived_by INT AFTER archived_at");
        $db->exec("ALTER TABLE products ADD INDEX idx_archived (is_archived)");

        $status = 'Migration successful! Added is_archived, archived_at, and archived_by columns.';
    }

    // Check if category management tables exist
    $result = $db->query("SHOW COLUMNS FROM product_categories LIKE 'active'");
    if ($result->rowCount() === 0) {
        $db->exec("ALTER TABLE product_categories ADD COLUMN active TINYINT(1) DEFAULT 1 AFTER display_order");
    }

} catch (Exception $e) {
    $status = 'Error: ' . htmlspecialchars($e->getMessage());
    $error = true;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Migration</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 2rem; }
        .alert { padding: 1rem; border-radius: 4px; margin: 1rem 0; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        a { color: #0066cc; }
    </style>
</head>
<body>
    <h1>Mowology Products Migration</h1>
    <div class="alert <?= isset($error) ? 'alert-error' : 'alert-success' ?>">
        <?= htmlspecialchars($status) ?>
    </div>
    <p><a href="products-manager.php">← Back to Products Manager</a></p>
</body>
</html>
