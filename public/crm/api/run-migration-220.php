<?php
/**
 * One-time migration runner — Migration 220
 * Contract E-Signature + Versioning
 *
 * Run once at: https://mowology.ca/crm/api/run-migration-220.php
 * Requires CRM admin login.
 */
declare(strict_types=1);

$__dir = __DIR__;
for ($__i = 0; $__i < 7; $__i++) {
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
    $__dir = dirname($__dir);
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

header('Content-Type: text/plain; charset=utf-8');
$db = getDB();

$statements = [
    // 1 — Add signature_status to contracts
    "ALTER TABLE contracts
        ADD COLUMN signature_status ENUM('unsigned','pending','signed','declined')
            NOT NULL DEFAULT 'unsigned' AFTER status",

    // 2 — Add current_version to contracts
    "ALTER TABLE contracts
        ADD COLUMN current_version SMALLINT UNSIGNED NOT NULL DEFAULT 1
            AFTER signature_status",

    // 3 — contract_signatures table
    "CREATE TABLE contract_signatures (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        contract_id      INT NOT NULL,
        contract_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        signature_token  VARCHAR(64) NOT NULL,
        token_expires_at DATETIME NOT NULL,
        signer_name      VARCHAR(255) DEFAULT NULL,
        signer_email     VARCHAR(255) DEFAULT NULL,
        status           ENUM('pending','signed','declined','expired') NOT NULL DEFAULT 'pending',
        signature_data   LONGTEXT DEFAULT NULL,
        signed_at        DATETIME DEFAULT NULL,
        signed_ip        VARCHAR(45) DEFAULT NULL,
        decline_reason   TEXT DEFAULT NULL,
        declined_at      DATETIME DEFAULT NULL,
        declined_ip      VARCHAR(45) DEFAULT NULL,
        sent_by          INT NOT NULL,
        created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_token (signature_token),
        KEY idx_contract (contract_id),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    // 4 — contract_versions table
    "CREATE TABLE contract_versions (
        id                   INT AUTO_INCREMENT PRIMARY KEY,
        contract_id          INT NOT NULL,
        version_number       SMALLINT UNSIGNED NOT NULL,
        changed_by           INT NOT NULL,
        change_reason        VARCHAR(500) DEFAULT NULL,
        title                VARCHAR(255) DEFAULT NULL,
        billing_cycle        VARCHAR(20)  DEFAULT NULL,
        billing_amount       DECIMAL(10,2) DEFAULT NULL,
        invoice_timing       VARCHAR(20)  DEFAULT NULL,
        start_date           DATE DEFAULT NULL,
        end_date             DATE DEFAULT NULL,
        renewal_date         DATE DEFAULT NULL,
        auto_renew           TINYINT(1) DEFAULT 1,
        renewal_increase_pct DECIMAL(5,2) DEFAULT 0.00,
        notes                TEXT DEFAULT NULL,
        signature_status     VARCHAR(20) DEFAULT 'unsigned',
        signed_at            DATETIME DEFAULT NULL,
        signed_by_name       VARCHAR(255) DEFAULT NULL,
        changed_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_version (contract_id, version_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
];

$ok = 0;
$errors = [];

foreach ($statements as $i => $sql) {
    try {
        $db->exec($sql);
        echo "OK: statement " . ($i + 1) . "\n";
        $ok++;
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        // Idempotency: "already exists" or "Duplicate column name" are not real errors
        if (
            stripos($msg, 'already exists') !== false ||
            stripos($msg, 'Duplicate column') !== false
        ) {
            echo "SKIP (already applied): statement " . ($i + 1) . "\n";
            $ok++;
        } else {
            echo "ERROR: statement " . ($i + 1) . " — {$msg}\n";
            $errors[] = $msg;
        }
    }
}

echo "\n--- Migration 220 complete: {$ok}/" . count($statements) . " OK, " . count($errors) . " errors ---\n";
