<?php
/**
 * Run Migration — Contracts Layer
 * Creates contracts table, adds contract_id to job_plans, auto-migrates existing plans.
 * Idempotent — safe to run multiple times.
 */
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
requirePermission('admin');

header('Content-Type: text/plain; charset=utf-8');

$db = getDB();
$results = [];

// ── 1. DDL — table + column + index + FK ─────────────────────────────────
$ddl = [

    "CREATE TABLE IF NOT EXISTS contracts (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        contract_number VARCHAR(20)  NOT NULL,
        property_id     INT          NOT NULL,
        contact_id      INT          NOT NULL,
        quote_id        INT          DEFAULT NULL,
        title           VARCHAR(255) DEFAULT NULL,
        status          ENUM('active','paused','expired','cancelled') NOT NULL DEFAULT 'active',
        billing_cycle   ENUM('monthly','per_visit','seasonal','annual','custom') NOT NULL DEFAULT 'monthly',
        billing_amount  DECIMAL(10,2) DEFAULT NULL,
        start_date      DATE         NOT NULL,
        end_date        DATE         DEFAULT NULL,
        renewal_date    DATE         DEFAULT NULL,
        notes           TEXT         DEFAULT NULL,
        created_by      INT          NOT NULL,
        created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_contract_number (contract_number),
        KEY idx_property (property_id),
        KEY idx_contact  (contact_id),
        KEY idx_quote    (quote_id),
        KEY idx_status   (status),
        CONSTRAINT fk_ctr_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
        CONSTRAINT fk_ctr_contact  FOREIGN KEY (contact_id)  REFERENCES contacts(id)   ON DELETE RESTRICT,
        CONSTRAINT fk_ctr_quote    FOREIGN KEY (quote_id)    REFERENCES quotes(id)     ON DELETE SET NULL,
        CONSTRAINT fk_ctr_created  FOREIGN KEY (created_by)  REFERENCES users(id)      ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    "ALTER TABLE job_plans ADD COLUMN contract_id INT DEFAULT NULL AFTER quote_id",

    "ALTER TABLE job_plans ADD KEY idx_contract_id (contract_id)",

    "ALTER TABLE job_plans ADD CONSTRAINT fk_plan_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE SET NULL",
];

foreach ($ddl as $i => $sql) {
    try {
        $db->exec($sql);
        $results[] = "OK  [$i] " . substr(trim($sql), 0, 80) . '...';
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        $isSkippable = (
            strpos($msg, 'Duplicate column') !== false ||   // 1060
            strpos($msg, '1060') !== false ||
            strpos($msg, 'Duplicate key name') !== false || // 1061
            strpos($msg, '1061') !== false ||
            strpos($msg, 'already exists') !== false ||     // 1826 FK duplicate
            strpos($msg, '1826') !== false
        );
        if ($isSkippable) {
            $results[] = "SKIP[$i] Already exists — " . substr(trim($sql), 0, 60);
        } else {
            $results[] = "ERR [$i] " . $msg . ' | SQL: ' . substr(trim($sql), 0, 80);
        }
    }
}

// ── 2. Auto-migrate existing plans into contracts ─────────────────────────
$results[] = '';
$results[] = '── Auto-migration: existing plans → contracts ──';

// Get lowest user id for created_by default
$adminId = (int)($db->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1);

// Each distinct accepted quote that has plans but no contract yet
$quoteGroups = $db->query("
    SELECT jp.quote_id,
           q.property_id,
           q.accepted_at,
           q.quote_number,
           q.title        AS quote_title,
           q.total_amount,
           COALESCE(qr.contact_id, p.site_contact_id) AS contact_id,
           q.created_by
    FROM job_plans jp
    JOIN quotes q    ON jp.quote_id = q.id AND q.status = 'accepted'
    JOIN properties p ON q.property_id = p.id
    LEFT JOIN (
        SELECT quote_id, contact_id FROM quote_requests GROUP BY quote_id
    ) qr ON qr.quote_id = q.id
    WHERE jp.contract_id IS NULL
      AND jp.quote_id IS NOT NULL
    GROUP BY jp.quote_id
")->fetchAll(PDO::FETCH_ASSOC);

$migrated = 0;
$skipped  = 0;

foreach ($quoteGroups as $group) {
    if (!$group['contact_id']) {
        $results[] = "SKIP quote_id={$group['quote_id']} — no contact_id found";
        $skipped++;
        continue;
    }

    try {
        $year   = date('Y', strtotime($group['accepted_at'] ?: 'now'));
        $maxNum = (int)$db->query("
            SELECT MAX(CAST(SUBSTRING(contract_number, 10) AS UNSIGNED))
            FROM contracts
            WHERE contract_number LIKE 'CTR-{$year}-%'
        ")->fetchColumn();
        $contractNumber = sprintf('CTR-%s-%04d', $year, $maxNum + 1);

        $startDate = $group['accepted_at'] ? date('Y-m-d', strtotime($group['accepted_at'])) : date('Y-m-d');
        $title     = $group['quote_title'] ?: ('Contract from ' . $group['quote_number']);
        $createdBy = $group['created_by'] ?: $adminId;

        $ins = $db->prepare("
            INSERT INTO contracts
                (contract_number, property_id, contact_id, quote_id, title,
                 status, billing_cycle, billing_amount, start_date, created_by)
            VALUES (?, ?, ?, ?, ?, 'active', 'monthly', ?, ?, ?)
        ");
        $ins->execute([
            $contractNumber,
            $group['property_id'],
            $group['contact_id'],
            $group['quote_id'],
            $title,
            $group['total_amount'] ?: null,
            $startDate,
            $createdBy,
        ]);
        $contractId = (int)$db->lastInsertId();

        $upd = $db->prepare("UPDATE job_plans SET contract_id = ? WHERE quote_id = ? AND contract_id IS NULL");
        $upd->execute([$contractId, $group['quote_id']]);

        $results[] = "OK  Created {$contractNumber} (id={$contractId}) for quote {$group['quote_number']}, linked {$upd->rowCount()} plan(s)";
        $migrated++;
    } catch (PDOException $e) {
        $results[] = "ERR quote_id={$group['quote_id']}: " . $e->getMessage();
        $skipped++;
    }
}

$results[] = '';
$results[] = "Contracts created: {$migrated}. Skipped: {$skipped}.";

echo "Migration — Contracts Layer\n\n" . implode("\n", $results) . "\n\nDone.";
