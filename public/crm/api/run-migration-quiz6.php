<?php
/**
 * /public/crm/api/run-migration-quiz6.php
 * Adds multi-image support to quiz questions.
 *
 * Creates: quiz_question_images table
 *   (question_id, image_path, sort_order, caption)
 * Migrates: existing image_path values from quiz_questions into the new table
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

$db     = getDB();
$steps  = [];
$errors = [];

// ── 1. Create quiz_question_images table ────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS quiz_question_images (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        question_id INT          NOT NULL,
        image_path  VARCHAR(255) NOT NULL,
        sort_order  TINYINT      NOT NULL DEFAULT 0,
        caption     VARCHAR(255) DEFAULT NULL,
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_question (question_id, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $steps[] = '✅ quiz_question_images table ready';
} catch (PDOException $e) {
    $errors[] = 'create table: ' . $e->getMessage();
}

// ── 2. Migrate existing image_path values ────────────────────────────────────
// For every question that has an image_path but no entry in the new table, insert one.
try {
    $migrated = 0;
    $existing = $db->query(
        "SELECT q.id, q.image_path
         FROM quiz_questions q
         WHERE q.image_path IS NOT NULL AND q.image_path != ''
           AND NOT EXISTS (
               SELECT 1 FROM quiz_question_images qi WHERE qi.question_id = q.id
           )"
    );
    $rows = $existing->fetchAll(PDO::FETCH_ASSOC);

    if ($rows) {
        $ins = $db->prepare(
            "INSERT INTO quiz_question_images (question_id, image_path, sort_order)
             VALUES (?, ?, 0)"
        );
        foreach ($rows as $row) {
            $ins->execute([(int)$row['id'], $row['image_path']]);
            $migrated++;
        }
    }
    $steps[] = "✅ Migrated $migrated existing question images into new table";
} catch (PDOException $e) {
    $errors[] = 'migration: ' . $e->getMessage();
}

// ── Done ─────────────────────────────────────────────────────────────────────
echo json_encode([
    'success' => empty($errors),
    'steps'   => $steps,
    'errors'  => $errors,
    'done'    => true,
], JSON_PRETTY_PRINT);
