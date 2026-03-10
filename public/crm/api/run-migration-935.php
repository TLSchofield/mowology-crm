<?php
/**
 * One-time Migration Runner — Migration 935
 * Adds site_id column to CMS tables: cms_pages, cms_menus, media_assets, cms_page_redirects.
 * Run once via authenticated GET/POST, then DELETE this file.
 */
declare(strict_types=1);
header('Content-Type: application/json');

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$db = getDB();
$results = [];

$steps = [
    'cms_pages'         => "ALTER TABLE cms_pages ADD COLUMN site_id INT NOT NULL DEFAULT 1 COMMENT 'Tenant site ID' AFTER id, ADD KEY idx_site_id (site_id)",
    'cms_menus'         => "ALTER TABLE cms_menus ADD COLUMN site_id INT NOT NULL DEFAULT 1 COMMENT 'Tenant site ID' AFTER id, ADD KEY idx_site_id (site_id)",
    'media_assets'      => "ALTER TABLE media_assets ADD COLUMN site_id INT NOT NULL DEFAULT 1 COMMENT 'Tenant site ID' AFTER id, ADD KEY idx_site_id (site_id)",
    'cms_page_redirects'=> "ALTER TABLE cms_page_redirects ADD COLUMN site_id INT NOT NULL DEFAULT 1 COMMENT 'Tenant site ID' AFTER id, ADD KEY idx_site_id (site_id)",
];

foreach ($steps as $table => $sql) {
    try {
        $db->exec($sql);
        $results[] = ['table' => $table, 'status' => 'column_added'];
    } catch (PDOException $e) {
        // 42S21 = duplicate column — already exists, safe to ignore
        if (str_contains($e->getMessage(), 'Duplicate column') || $e->getCode() === '42S21') {
            $results[] = ['table' => $table, 'status' => 'already_exists'];
        } else {
            $results[] = ['table' => $table, 'status' => 'error', 'msg' => $e->getMessage()];
        }
    }
}

echo json_encode(['migration' => '935', 'results' => $results, 'done' => true], JSON_PRETTY_PRINT);
