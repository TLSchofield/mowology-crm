<?php
/**
 * Run Migration 608: Visit Flag + Has Reviewed
 *
 * Adds:
 *   job_visits.is_flagged   — crew endorsement flag (quality gate for review
 *                             requests + BA marketing import feed)
 *   contacts.has_reviewed   — permanent review stop (set manually when client
 *                             leaves a Google review)
 *
 * MySQL 8.0-compatible: per-column try/catch instead of IF NOT EXISTS.
 * Error 42S21 = Duplicate column (safe to skip).
 * Error 42000 + "Duplicate key name" = Index already exists (safe to skip).
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
session_write_close();
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$db      = getDB();
$results = [];

function tryAlter608(PDO $db, string $sql, string $label, array &$results): void {
    try {
        $db->exec($sql);
        $results[] = ['step' => $label, 'status' => 'added'];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if ($e->getCode() === '42S21' || strpos($msg, 'Duplicate column') !== false) {
            $results[] = ['step' => $label, 'status' => 'already exists'];
        } elseif (strpos($msg, 'Duplicate key name') !== false) {
            $results[] = ['step' => $label, 'status' => 'index already exists'];
        } else {
            $results[] = ['step' => $label, 'status' => 'error', 'msg' => $msg];
        }
    }
}

// ── job_visits.is_flagged ─────────────────────────────────────────────────────
tryAlter608($db,
    "ALTER TABLE `job_visits`
     ADD COLUMN `is_flagged` TINYINT(1) NOT NULL DEFAULT 0
     COMMENT 'Crew endorsed this visit — quality gate for review requests + BA marketing'
     AFTER `review_request_sent_at`",
    'job_visits.is_flagged', $results
);

tryAlter608($db,
    "CREATE INDEX `idx_job_visits_flagged` ON `job_visits` (`is_flagged`)",
    'idx_job_visits_flagged', $results
);

// ── contacts.has_reviewed ─────────────────────────────────────────────────────
tryAlter608($db,
    "ALTER TABLE `contacts`
     ADD COLUMN `has_reviewed` TINYINT(1) NOT NULL DEFAULT 0
     COMMENT 'Client has left a Google review — permanently stops all future review requests'
     AFTER `review_request_opted_out`",
    'contacts.has_reviewed', $results
);

echo json_encode(['success' => true, 'results' => $results], JSON_PRETTY_PRINT);
