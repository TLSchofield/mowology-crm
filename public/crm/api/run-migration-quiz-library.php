<?php
/**
 * Migration: quiz_plant_library table
 * Creates the plant & weed reference library used for quiz question authoring.
 * Run once as admin via the CRM, then leave in place (idempotent).
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

session_write_close();
$db = getDB();
$steps = [];
$errors = [];

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS quiz_plant_library (
            id                  INT AUTO_INCREMENT PRIMARY KEY,
            common_name         VARCHAR(120)  NOT NULL,
            latin_name          VARCHAR(120)  DEFAULT NULL,
            type                ENUM('plant','weed','grass','shrub','tree','fungus') NOT NULL DEFAULT 'plant',
            description         TEXT          DEFAULT NULL,
            identification_notes TEXT         DEFAULT NULL,
            image_path          VARCHAR(500)  DEFAULT NULL,
            tags                VARCHAR(500)  DEFAULT NULL,
            is_active           TINYINT(1)    NOT NULL DEFAULT 1,
            created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_type   (type),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $steps[] = 'quiz_plant_library table created (or already exists)';
} catch (PDOException $e) {
    $errors[] = 'Table creation failed: ' . $e->getMessage();
}

echo json_encode([
    'success' => empty($errors),
    'steps'   => $steps,
    'errors'  => $errors,
]);
