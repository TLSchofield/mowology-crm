<?php
/**
 * Migration 1047 — clients + client_contacts.
 * Client/Account model Phase 1. Mirrors database/migrations/1047_clients_tables.sql.
 * Run by visiting this URL as an admin. Idempotent (CREATE TABLE IF NOT EXISTS).
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
if (!isAdmin()) { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }

$db      = getDB();
$results = [];

function tryExec1047(PDO $db, string $sql, string $label, array &$results): void {
    try {
        $db->exec($sql);
        $results[] = ['step' => $label, 'status' => 'ok'];
    } catch (PDOException $e) {
        $msg     = $e->getMessage();
        $already = strpos($msg, 'Duplicate') !== false || strpos($msg, 'already exists') !== false;
        $results[] = ['step' => $label, 'status' => $already ? 'already_exists' : 'error', 'msg' => $msg];
    }
}

tryExec1047($db,
    "CREATE TABLE IF NOT EXISTS clients (
       id INT AUTO_INCREMENT PRIMARY KEY,
       site_id INT NULL DEFAULT 1,
       client_type ENUM('individual','business','strata','property_manager') NOT NULL DEFAULT 'individual',
       display_name VARCHAR(200) NOT NULL,
       billing_address VARCHAR(255) NULL DEFAULT NULL,
       billing_city VARCHAR(100) NULL DEFAULT NULL,
       billing_province VARCHAR(50) NULL DEFAULT NULL,
       billing_postal_code VARCHAR(10) NULL DEFAULT NULL,
       billing_email VARCHAR(255) NULL DEFAULT NULL,
       billing_phone VARCHAR(50) NULL DEFAULT NULL,
       payment_terms VARCHAR(50) DEFAULT 'Net 30',
       payment_method ENUM('invoice','credit_card','bank_transfer','cheque') DEFAULT 'invoice',
       lifecycle_stage VARCHAR(50) DEFAULT 'prospect',
       status ENUM('active','inactive','suspended') DEFAULT 'active',
       managed_by_client_id INT NULL DEFAULT NULL,
       legacy_company_id INT NULL DEFAULT NULL,
       legacy_contact_id INT NULL DEFAULT NULL,
       created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
       updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       CONSTRAINT fk_clients_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL,
       CONSTRAINT fk_clients_managed_by FOREIGN KEY (managed_by_client_id) REFERENCES clients(id) ON DELETE SET NULL,
       INDEX idx_clients_site (site_id),
       INDEX idx_clients_type (client_type),
       INDEX idx_clients_display (display_name),
       INDEX idx_clients_status (status),
       UNIQUE KEY uq_client_site_legacy_company (site_id, legacy_company_id),
       UNIQUE KEY uq_client_site_legacy_contact (site_id, legacy_contact_id)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'create clients', $results);

tryExec1047($db,
    "CREATE TABLE IF NOT EXISTS client_contacts (
       id INT AUTO_INCREMENT PRIMARY KEY,
       client_id INT NOT NULL,
       contact_id INT NOT NULL,
       role ENUM('owner','council_member','property_manager','billing','site') NOT NULL DEFAULT 'owner',
       is_primary TINYINT(1) DEFAULT 0,
       receives_invoices TINYINT(1) DEFAULT 0,
       receives_notifications TINYINT(1) DEFAULT 0,
       created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
       updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       CONSTRAINT fk_cc_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
       CONSTRAINT fk_cc_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
       UNIQUE KEY uq_client_contact_role (client_id, contact_id, role),
       INDEX idx_cc_client (client_id),
       INDEX idx_cc_contact (contact_id),
       INDEX idx_cc_receives_invoices (receives_invoices)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'create client_contacts', $results);

$ok = !in_array('error', array_column($results, 'status'));
echo json_encode(['ok' => $ok, 'migration' => '1047', 'results' => $results], JSON_PRETTY_PRINT);
