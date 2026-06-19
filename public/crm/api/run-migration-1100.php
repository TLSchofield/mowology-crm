<?php
/**
 * Migration 1100 — email-in expense capture.
 * Mirrors database/migrations/1100_receipt_inbox.sql. Admin-only, idempotent.
 * Also reports whether the Imagick extension is available (needed to OCR PDF receipts).
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }

$db = getDB();
$results = [];

function tryExec1100(PDO $db, string $sql, string $label, array &$results): void {
    try {
        $db->exec($sql);
        $results[] = ['step' => $label, 'status' => 'ok'];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        $already = strpos($msg, 'Duplicate') !== false
                || strpos($msg, 'already exists') !== false
                || strpos($msg, 'check that column/key exists') !== false;
        $results[] = ['step' => $label, 'status' => $already ? 'already_exists' : 'error', 'msg' => $msg];
    }
}

tryExec1100($db, "CREATE TABLE IF NOT EXISTS receipt_inbox_messages (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dedup_key        VARCHAR(255) NOT NULL,
  sender_email     VARCHAR(255) NULL,
  subject          VARCHAR(255) NULL,
  email_date       DATETIME NULL,
  attachment_name  VARCHAR(255) NULL,
  media_id         INT NULL,
  expense_id       INT NULL,
  outcome          VARCHAR(20) NOT NULL DEFAULT 'pending',
  match_confidence INT NULL,
  note             VARCHAR(500) NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_receipt_inbox_dedup (dedup_key),
  KEY idx_receipt_inbox_outcome (outcome),
  KEY idx_receipt_inbox_expense (expense_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci", 'create receipt_inbox_messages', $results);

tryExec1100($db, "ALTER TABLE expenses ADD COLUMN source VARCHAR(20) NULL DEFAULT NULL COMMENT 'manual|ios|email_inbox'", 'expenses: add source', $results);
tryExec1100($db, "ALTER TABLE expenses ADD INDEX idx_expenses_source (source)", 'expenses: add source index', $results);

$ok = !in_array('error', array_column($results, 'status'));
echo json_encode([
    'ok'        => $ok,
    'migration' => '1100',
    'results'   => $results,
    'imagick'   => extension_loaded('imagick') ? 'available (PDF OCR enabled)' : 'MISSING (PDF receipts will route to review only)',
], JSON_PRETTY_PRINT);
