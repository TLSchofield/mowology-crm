<?php
/**
 * Run Migration 606: Google Review Requests
 *
 * Adds review request tracking columns to contacts and job_visits,
 * and seeds google_review_url in ops_settings.
 *
 * MySQL 8.0-compatible: uses per-column try/catch instead of IF NOT EXISTS.
 * Error 1060 = Duplicate column (safe to skip).
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$db      = getDB();
$results = [];

// ── Helper ────────────────────────────────────────────────────────────────────
function tryAlter(PDO $db, string $sql, string $label, array &$results): void {
    try {
        $db->exec($sql);
        $results[] = ['step' => $label, 'status' => 'added'];
    } catch (PDOException $e) {
        if ($e->getCode() === '42S21' || str_contains($e->getMessage(), 'Duplicate column')) {
            $results[] = ['step' => $label, 'status' => 'already exists'];
        } else {
            $results[] = ['step' => $label, 'status' => 'error', 'msg' => $e->getMessage()];
        }
    }
}

// ── contacts columns ──────────────────────────────────────────────────────────
tryAlter($db,
    "ALTER TABLE `contacts` ADD COLUMN `review_request_sent_at` DATETIME NULL COMMENT 'When last review request was sent' AFTER `total_lifetime_value`",
    'contacts.review_request_sent_at', $results
);
tryAlter($db,
    "ALTER TABLE `contacts` ADD COLUMN `review_request_sent_count` INT NOT NULL DEFAULT 0 COMMENT 'Total review requests sent' AFTER `review_request_sent_at`",
    'contacts.review_request_sent_count', $results
);
tryAlter($db,
    "ALTER TABLE `contacts` ADD COLUMN `review_request_opted_out` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = do not send review requests' AFTER `review_request_sent_count`",
    'contacts.review_request_opted_out', $results
);

// ── job_visits column ─────────────────────────────────────────────────────────
tryAlter($db,
    "ALTER TABLE `job_visits` ADD COLUMN `review_request_sent_at` DATETIME NULL COMMENT 'When review request was triggered for this visit' AFTER `completion_notes`",
    'job_visits.review_request_sent_at', $results
);

// ── ops_settings seed ─────────────────────────────────────────────────────────
try {
    $db->exec("INSERT IGNORE INTO `ops_settings` (`setting_key`, `setting_value`, `description`)
               VALUES ('google_review_url', 'https://g.page/r/mowology/review', 'Google Business Profile review short URL')");
    $results[] = ['step' => 'ops_settings.google_review_url', 'status' => 'seeded (INSERT IGNORE)'];
} catch (PDOException $e) {
    $results[] = ['step' => 'ops_settings.google_review_url', 'status' => 'error', 'msg' => $e->getMessage()];
}

echo json_encode(['success' => true, 'results' => $results], JSON_PRETTY_PRINT);
