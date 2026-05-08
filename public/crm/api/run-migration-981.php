<?php
/**
 * Migration Runner — 981_accounting_phase2
 * Bank import staging tables, accounting alerts, transaction columns.
 *
 * Access: /crm/api/run-migration-981.php
 * Requires: admin login
 */
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
    }
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo '<h1>403 — Admin only</h1>';
    exit;
}

$db = getDB();

$statements981 = [];

$statements981[] = "CREATE TABLE IF NOT EXISTS bank_import_sessions (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    filename       VARCHAR(255)  NOT NULL,
    bank_name      VARCHAR(80)   NULL,
    account_name   VARCHAR(120)  NULL,
    row_count      INT           NOT NULL DEFAULT 0,
    imported_count INT           NOT NULL DEFAULT 0,
    skipped_count  INT           NOT NULL DEFAULT 0,
    duplicate_count INT          NOT NULL DEFAULT 0,
    date_from      DATE          NULL,
    date_to        DATE          NULL,
    status         ENUM('pending','imported','rolled_back') NOT NULL DEFAULT 'pending',
    notes          TEXT          NULL,
    created_by     INT           NULL,
    created_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_date   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

$statements981[] = "CREATE TABLE IF NOT EXISTS bank_import_rows (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    session_id       INT           NOT NULL,
    transaction_date DATE          NOT NULL,
    description      VARCHAR(500)  NOT NULL,
    raw_amount       DECIMAL(10,2) NOT NULL,
    type             ENUM('income','expense') NOT NULL,
    amount           DECIMAL(10,2) NOT NULL,
    account_id       INT           NULL,
    transaction_id   INT           NULL,
    is_duplicate     TINYINT(1)    NOT NULL DEFAULT 0,
    duplicate_of_id  INT           NULL,
    rule_id          INT           NULL,
    raw_row          TEXT          NULL,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session (session_id),
    FOREIGN KEY (session_id) REFERENCES bank_import_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

$statements981[] = "CREATE TABLE IF NOT EXISTS accounting_alerts (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    alert_type     VARCHAR(60)  NOT NULL,
    severity       ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
    title          VARCHAR(200) NOT NULL,
    body           TEXT         NULL,
    reference_type VARCHAR(40)  NULL,
    reference_id   INT          NULL,
    is_dismissed   TINYINT(1)   NOT NULL DEFAULT 0,
    dismissed_by   INT          NULL,
    dismissed_at   TIMESTAMP    NULL,
    created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type     (alert_type),
    INDEX idx_severity (severity),
    INDEX idx_active   (is_dismissed, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

// ALTER TABLE — run individually so failures don't block each other
$statements981[] = "ALTER TABLE accounting_transactions ADD COLUMN bank_account VARCHAR(100) NULL AFTER assigned_user_id";
$statements981[] = "ALTER TABLE accounting_transactions ADD COLUMN import_session_id INT NULL AFTER bank_account";

// ── Run ───────────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Migration 981 — Accounting Phase 2</title>
<style>
  body { font-family: monospace; padding: 2rem; background: #0d1117; color: #c9d1d9; }
  h1   { color: #2D8659; }
  .ok  { color: #3fb950; }
  .err { color: #f85149; }
  .note{ color: #8b949e; }
</style>
</head>
<body>
<h1>Migration 981 — Accounting Phase 2</h1>
<p class="note">Running as: <?= htmlspecialchars($user['email'] ?? '') ?></p>
<hr>
<?php

$successCount = 0;
$errorCount   = 0;

foreach ($statements981 as $stmt) {
    $firstLine = trim(strtok($stmt, "\n"));
    try {
        $db->exec($stmt);
        $successCount++;
        echo "<p class=\"ok\">✓ " . htmlspecialchars($firstLine) . "</p>\n";
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'already exists') ||
            str_contains($msg, 'Duplicate column') ||
            str_contains($msg, 'Duplicate entry') ||
            str_contains($msg, 'SQLSTATE[42S01]') ||
            str_contains($msg, 'SQLSTATE[42S21]')) {
            echo "<p class=\"note\">↷ " . htmlspecialchars($firstLine) . " (already exists)</p>\n";
            continue;
        }
        $errorCount++;
        echo "<p class=\"err\">✗ " . htmlspecialchars($firstLine) . "<br>" . htmlspecialchars($msg) . "</p>\n";
    }
}
?>
<hr>
<p class="<?= $errorCount > 0 ? 'err' : 'ok' ?>">
    Done — <?= $successCount ?> statements succeeded, <?= $errorCount ?> failed.
</p>
<?php if ($errorCount === 0): ?>
<p class="ok">✓ Bank import tables, alerts table, and transaction columns — all ready.</p>
<p class="note">Go to the <a href="/crm/accounting_appstack.php" style="color:#2D8659">Accounting Dashboard</a>
and click <strong>Sync Ledger</strong> to import your existing invoices and expenses.</p>
<?php endif; ?>
</body>
</html>
