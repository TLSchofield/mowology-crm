<?php
/**
 * One-time Migration Runner — Knowledge Quiz
 * Creates quiz tables and seeds 6 categories.
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

$db = getDB();
$results = [];

// ── 1. quiz_categories ─────────────────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS quiz_categories (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(100) NOT NULL,
        description TEXT,
        icon        VARCHAR(50)  DEFAULT 'help-circle',
        colour      VARCHAR(7)   DEFAULT '#2D8659',
        is_active   TINYINT(1)   DEFAULT 1,
        sort_order  INT          DEFAULT 0,
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $results[] = ['step' => 'quiz_categories', 'status' => 'created_or_exists'];
} catch (PDOException $e) {
    $results[] = ['step' => 'quiz_categories', 'status' => 'error', 'msg' => $e->getMessage()];
}

// ── 2. quiz_questions ──────────────────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS quiz_questions (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        category_id  INT          NOT NULL,
        question_text TEXT        NOT NULL,
        image_path   VARCHAR(512) DEFAULT NULL,
        difficulty   ENUM('easy','medium','hard') DEFAULT 'medium',
        is_active    TINYINT(1)   DEFAULT 1,
        created_by   INT          NOT NULL,
        created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_category (category_id),
        INDEX idx_active   (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $results[] = ['step' => 'quiz_questions', 'status' => 'created_or_exists'];
} catch (PDOException $e) {
    $results[] = ['step' => 'quiz_questions', 'status' => 'error', 'msg' => $e->getMessage()];
}

// ── 3. quiz_options ────────────────────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS quiz_options (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        question_id INT          NOT NULL,
        option_text VARCHAR(255) NOT NULL,
        is_correct  TINYINT(1)  DEFAULT 0,
        sort_order  INT         DEFAULT 0,
        INDEX idx_question (question_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $results[] = ['step' => 'quiz_options', 'status' => 'created_or_exists'];
} catch (PDOException $e) {
    $results[] = ['step' => 'quiz_options', 'status' => 'error', 'msg' => $e->getMessage()];
}

// ── 4. quiz_sessions ───────────────────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS quiz_sessions (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        user_id         INT          NOT NULL,
        category_id     INT          DEFAULT NULL,
        question_ids    VARCHAR(255) DEFAULT NULL COMMENT 'Comma-separated ordered question IDs',
        started_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        completed_at    TIMESTAMP    NULL DEFAULT NULL,
        questions_count INT          DEFAULT 10,
        correct_count   INT          DEFAULT 0,
        total_points    INT          DEFAULT 0,
        month_year      CHAR(7)      NOT NULL,
        INDEX idx_user       (user_id),
        INDEX idx_month      (month_year),
        INDEX idx_user_month (user_id, month_year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $results[] = ['step' => 'quiz_sessions', 'status' => 'created_or_exists'];
} catch (PDOException $e) {
    $results[] = ['step' => 'quiz_sessions', 'status' => 'error', 'msg' => $e->getMessage()];
}

// ── 5. quiz_answers ────────────────────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS quiz_answers (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        session_id          INT          NOT NULL,
        question_id         INT          NOT NULL,
        selected_option_id  INT          DEFAULT NULL,
        is_correct          TINYINT(1)  DEFAULT 0,
        time_taken_seconds  INT         DEFAULT 0,
        answered_at         TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_session  (session_id),
        INDEX idx_question (question_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $results[] = ['step' => 'quiz_answers', 'status' => 'created_or_exists'];
} catch (PDOException $e) {
    $results[] = ['step' => 'quiz_answers', 'status' => 'error', 'msg' => $e->getMessage()];
}

// ── 6. quiz_monthly_prizes ─────────────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS quiz_monthly_prizes (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        month_year       CHAR(7)      NOT NULL,
        winner_user_id   INT          NOT NULL,
        prize_description VARCHAR(255) NOT NULL,
        awarded_at       TIMESTAMP    NULL DEFAULT NULL,
        awarded_by       INT          NOT NULL,
        notes            TEXT,
        created_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_month  (month_year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $results[] = ['step' => 'quiz_monthly_prizes', 'status' => 'created_or_exists'];
} catch (PDOException $e) {
    $results[] = ['step' => 'quiz_monthly_prizes', 'status' => 'error', 'msg' => $e->getMessage()];
}

// ── 7. Seed categories (INSERT IGNORE to be idempotent) ───────────────────
$categories = [
    [1, 'Weed Identification',  'Identify common weeds found on lawns and landscapes.',     'alert-triangle', '#e85d04', 1],
    [2, 'Plant & Grass ID',     'Identify turf grasses, ornamental plants, and shrubs.',    'feather',        '#2D8659', 2],
    [3, 'Equipment & Tools',    'Know your equipment — safe use, names, and maintenance.',  'tool',           '#1A5F4A', 3],
    [4, 'Safety & PPE',         'WHMIS, PPE, and safe working procedures.',                 'shield',         '#c0392b', 4],
    [5, 'Pest & Disease ID',    'Identify lawn pests, insects, and turf diseases.',         'zap',            '#8e44ad', 5],
    [6, 'Turf & Soil',          'Grass types, soil health, and fertilizer knowledge.',      'layers',         '#7FD858', 6],
];

$catStmt = $db->prepare(
    "INSERT IGNORE INTO quiz_categories (id, name, description, icon, colour, is_active, sort_order)
     VALUES (?, ?, ?, ?, ?, 1, ?)"
);

foreach ($categories as $cat) {
    try {
        $catStmt->execute($cat);
        $results[] = ['step' => "seed category: {$cat[1]}", 'status' => 'ok'];
    } catch (PDOException $e) {
        $results[] = ['step' => "seed category: {$cat[1]}", 'status' => 'error', 'msg' => $e->getMessage()];
    }
}

// ── 8. Create uploads/quiz directory marker ────────────────────────────────
$uploadDir = PUBLIC_ROOT . '/uploads/quiz';
if (!is_dir($uploadDir)) {
    if (mkdir($uploadDir, 0755, true)) {
        $results[] = ['step' => 'uploads/quiz dir', 'status' => 'created'];
    } else {
        $results[] = ['step' => 'uploads/quiz dir', 'status' => 'failed to create — create manually'];
    }
} else {
    $results[] = ['step' => 'uploads/quiz dir', 'status' => 'already_exists'];
}

echo json_encode(['migration' => 'quiz', 'results' => $results, 'done' => true], JSON_PRETTY_PRINT);
