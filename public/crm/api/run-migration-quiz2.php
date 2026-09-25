<?php
/**
 * One-time Migration Runner — Knowledge Quiz v2 (Mastery + Flashcard)
 * Adds quiz_user_mastery table and learn_notes column to quiz_questions.
 * Run once via authenticated GET (admin only), then DELETE this file.
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
session_write_close();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$db      = getDB();
$results = [];

// ── 1. quiz_user_mastery table ─────────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS quiz_user_mastery (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        user_id         INT      NOT NULL,
        question_id     INT      NOT NULL,
        mastery_level   TINYINT  NOT NULL DEFAULT 0
            COMMENT '0=unseen,1=learning,2=familiar,3=good,4=mastered,5=expert',
        correct_streak  INT      NOT NULL DEFAULT 0,
        total_attempts  INT      NOT NULL DEFAULT 0,
        total_correct   INT      NOT NULL DEFAULT 0,
        last_seen_at    TIMESTAMP NULL DEFAULT NULL,
        next_review_at  TIMESTAMP NULL DEFAULT NULL,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_question (user_id, question_id),
        INDEX idx_user_review (user_id, next_review_at),
        INDEX idx_user_level  (user_id, mastery_level)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $results[] = ['step' => 'quiz_user_mastery table', 'status' => 'created_or_exists'];
} catch (PDOException $e) {
    $results[] = ['step' => 'quiz_user_mastery table', 'status' => 'error', 'msg' => $e->getMessage()];
}

// ── 2. Add learn_notes column to quiz_questions (idempotent) ───────────────
$colCheck = $db->query("SHOW COLUMNS FROM quiz_questions LIKE 'learn_notes'")->fetch();
if (!$colCheck) {
    try {
        $db->exec("ALTER TABLE quiz_questions
            ADD COLUMN learn_notes TEXT NULL
                COMMENT 'Key facts shown on flashcard back'
                AFTER question_text");
        $results[] = ['step' => 'quiz_questions.learn_notes column', 'status' => 'added'];
    } catch (PDOException $e) {
        $results[] = ['step' => 'quiz_questions.learn_notes column', 'status' => 'error', 'msg' => $e->getMessage()];
    }
} else {
    $results[] = ['step' => 'quiz_questions.learn_notes column', 'status' => 'already_exists'];
}

echo json_encode(['migration' => 'quiz2', 'results' => $results, 'done' => true], JSON_PRETTY_PRINT);
