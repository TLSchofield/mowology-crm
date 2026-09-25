<?php
/**
 * Migration Quiz 7 — Pre-Shift Quiz Gate
 * Creates quiz_preshift_log table + seeds ops_settings defaults.
 * Safe to run multiple times (idempotent).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();
session_write_close();

header('Content-Type: application/json');

$db    = getDB();
$steps = [];
$errs  = [];

// ── Step 1: Create quiz_preshift_log ──────────────────────────────────────────
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS quiz_preshift_log (
            id                INT          AUTO_INCREMENT PRIMARY KEY,
            user_id           INT          NOT NULL,
            log_date          DATE         NOT NULL,
            session_id        INT          NULL,
            questions_asked   TINYINT      NOT NULL DEFAULT 3,
            questions_correct TINYINT      NOT NULL DEFAULT 0,
            score_pct         TINYINT      NOT NULL DEFAULT 0,
            completed_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_user_date (user_id, log_date),
            INDEX idx_log_date (log_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $steps[] = '✅ quiz_preshift_log table ready';
} catch (PDOException $e) {
    $errs[] = 'quiz_preshift_log: ' . $e->getMessage();
}

// ── Step 2: Seed ops_settings defaults ────────────────────────────────────────
$seeds = [
    ['quiz_preshift_enabled',        '0',  'Enable pre-shift quiz gate on the schedule page'],
    ['quiz_preshift_session_length', '3',  'Number of questions in the pre-shift quiz (3 or 5)'],
];

foreach ($seeds as [$key, $val, $desc]) {
    try {
        $db->prepare(
            "INSERT IGNORE INTO ops_settings (setting_key, setting_value, description, updated_at)
             VALUES (?, ?, ?, NOW())"
        )->execute([$key, $val, $desc]);
        $steps[] = "✅ ops_settings '{$key}' ready";
    } catch (PDOException $e) {
        $errs[] = "ops_settings {$key}: " . $e->getMessage();
    }
}

echo json_encode([
    'success' => empty($errs),
    'steps'   => $steps,
    'errors'  => $errs,
    'done'    => true,
], JSON_PRETTY_PRINT);
