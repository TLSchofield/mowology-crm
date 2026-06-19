<?php
/**
 * Migration 1066 — Double-entry journal layer (Phase 1, Step 1)
 *
 * Creates the authoritative double-entry tables that sit ALONGSIDE the existing
 * single-entry accounting_transactions table (which is left untouched):
 *   - cost_types        : reference list for GGOB cost drill-down dimension
 *   - journal_entries   : balanced entry header (one per economic event)
 *   - journal_lines     : debit/credit lines + drill-down dimensions
 *
 * Also adds two chart_of_accounts rows needed by the posting recipes:
 *   - 1300 Due from Shareholder (asset)
 *   - 3900 Opening Balance Equity (equity)
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS + INSERT IGNORE. Safe to re-run.
 */
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') { http_response_code(403); die('Admin only'); }

$db = getDB();
$results = [];

$steps = [];

// ── cost_types (drill-down dimension) ───────────────────────────────────────
$steps['Create cost_types'] = "
    CREATE TABLE IF NOT EXISTS cost_types (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        code        VARCHAR(30)  NOT NULL,
        name        VARCHAR(60)  NOT NULL,
        sort_order  INT          NOT NULL DEFAULT 0,
        is_active   TINYINT(1)   NOT NULL DEFAULT 1,
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cost_type_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
      COMMENT='GGOB cost drill-down dimension (Labour/Materials/Equipment/...)'";

// ── journal_entries (header) ────────────────────────────────────────────────
$steps['Create journal_entries'] = "
    CREATE TABLE IF NOT EXISTS journal_entries (
        id                   INT AUTO_INCREMENT PRIMARY KEY,
        entry_date           DATE NOT NULL,
        memo                 VARCHAR(255) NULL,
        source_type          ENUM('invoice','payment','expense','bill','bank_import','manual','adjusting','opening') NOT NULL DEFAULT 'manual',
        source_id            INT NULL,
        period_id            INT NULL,
        status               ENUM('draft','posted','void') NOT NULL DEFAULT 'posted',
        is_adjusting         TINYINT(1) NOT NULL DEFAULT 0,
        reversed_by_entry_id INT NULL,
        created_by           INT NULL,
        created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_source (source_type, source_id),
        INDEX idx_entry_date (entry_date),
        INDEX idx_source (source_type, source_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
      COMMENT='Double-entry journal header; one balanced entry per economic event'";

// ── journal_lines (debit/credit + dimensions) ───────────────────────────────
$steps['Create journal_lines'] = "
    CREATE TABLE IF NOT EXISTS journal_lines (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        entry_id      INT NOT NULL,
        account_id    INT NOT NULL,
        debit         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        credit        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        gst_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        pst_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        description   VARCHAR(255) NULL,
        job_id        INT NULL,
        contact_id    INT NULL,
        vendor_id     INT NULL,
        crew_user_id  INT NULL,
        cost_type_id  INT NULL,
        service_type  VARCHAR(60) NULL,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_jl_entry (entry_id),
        INDEX idx_jl_account (account_id),
        INDEX idx_jl_job (job_id),
        INDEX idx_jl_cost_type (cost_type_id),
        INDEX idx_jl_service_type (service_type),
        CONSTRAINT chk_jl_nonneg CHECK (debit >= 0 AND credit >= 0),
        CONSTRAINT fk_jl_entry FOREIGN KEY (entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
      COMMENT='Double-entry lines; debit XOR credit per line (enforced in LedgerService)'";

foreach ($steps as $label => $sql) {
    try {
        $db->exec($sql);
        $results[] = ['label' => $label, 'status' => 'OK'];
    } catch (PDOException $e) {
        $results[] = ['label' => $label, 'status' => 'Note: ' . $e->getMessage()];
    }
}

// ── Seed cost_types ─────────────────────────────────────────────────────────
$costTypes = [
    ['LABOUR',       'Labour',        10],
    ['MATERIALS',    'Materials',     20],
    ['EQUIPMENT',    'Equipment',     30],
    ['FUEL',         'Fuel',          40],
    ['SUBCONTRACTOR','Subcontractor', 50],
    ['OVERHEAD',     'Overhead',      60],
    ['OTHER',        'Other',         70],
];
$ct = $db->prepare("INSERT IGNORE INTO cost_types (code, name, sort_order) VALUES (?, ?, ?)");
foreach ($costTypes as $row) {
    try { $ct->execute($row); $results[] = ['label' => "Seed cost_type {$row[0]}", 'status' => 'OK']; }
    catch (PDOException $e) { $results[] = ['label' => "Seed cost_type {$row[0]}", 'status' => 'Note: ' . $e->getMessage()]; }
}

// ── Add accounts needed by posting recipes (idempotent via UNIQUE code) ──────
$accounts = [
    // code, name, type, sub_type, normal_balance, display_order
    ['1300', 'Due from Shareholder',   'asset',  'shareholder_loan', 'debit',  130],
    ['3900', 'Opening Balance Equity',  'equity', 'opening',          'credit', 390],
];
$acc = $db->prepare("INSERT IGNORE INTO chart_of_accounts (code, name, type, sub_type, normal_balance, is_system, is_active, display_order)
                     VALUES (?, ?, ?, ?, ?, 1, 1, ?)");
foreach ($accounts as $a) {
    try { $acc->execute($a); $results[] = ['label' => "Seed account {$a[0]} {$a[1]}", 'status' => 'OK']; }
    catch (PDOException $e) { $results[] = ['label' => "Seed account {$a[0]}", 'status' => 'Note: ' . $e->getMessage()]; }
}
?>
<!DOCTYPE html><html><head><title>Migration 1066</title></head><body>
<h2>Migration 1066 — Double-entry journal layer</h2>
<table border="1" cellpadding="8" style="border-collapse:collapse">
<tr><th>Step</th><th>Status</th></tr>
<?php foreach ($results as $r): ?>
<tr><td><?= htmlspecialchars($r['label']) ?></td><td><?= htmlspecialchars($r['status']) ?></td></tr>
<?php endforeach; ?>
</table>
<p><a href="/crm/accounting/chart-of-accounts.php">← Chart of Accounts</a></p>
</body></html>
