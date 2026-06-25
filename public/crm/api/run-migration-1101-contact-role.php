<?php
/**
 * Migration 1101 — Contact Role & Employer Company.
 *
 * Adds two columns to contacts:
 *   contact_role        ENUM — person's role in the PM workflow (property_manager, strata_rep, …)
 *   employer_company_id INT  — FK to companies.id (the firm this person works for)
 *
 * Idempotent + admin-only. Safe to re-run.
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

$db   = getDB();
$done = [];

try {
    $cols = $db->query("SHOW COLUMNS FROM contacts")->fetchAll(PDO::FETCH_COLUMN);

    // 1. contact_role ENUM
    if (!in_array('contact_role', $cols, true)) {
        $db->exec("
            ALTER TABLE contacts
            ADD COLUMN contact_role
                ENUM('property_manager','strata_rep','owner','billing_contact','site_supervisor','other')
                NULL DEFAULT NULL
                COMMENT 'Person-level role in the property management workflow'
                AFTER notes
        ");
        $done['contact_role'] = 'added';
    } else {
        $done['contact_role'] = 'already_present';
    }

    // 2. employer_company_id FK
    if (!in_array('employer_company_id', $cols, true)) {
        $db->exec("
            ALTER TABLE contacts
            ADD COLUMN employer_company_id INT NULL DEFAULT NULL
                COMMENT 'FK to companies.id — the firm this person works for'
                AFTER contact_role
        ");
        $done['employer_company_id'] = 'added';
    } else {
        $done['employer_company_id'] = 'already_present';
    }

    // 3. FK constraint (only add if column was just created)
    $hasFk = $db->query("
        SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'contacts'
          AND CONSTRAINT_NAME = 'fk_contacts_employer_company'
    ")->fetchColumn();
    if (!$hasFk) {
        try {
            $db->exec("
                ALTER TABLE contacts
                ADD CONSTRAINT fk_contacts_employer_company
                    FOREIGN KEY (employer_company_id) REFERENCES companies(id)
                    ON DELETE SET NULL
            ");
            $done['fk_contacts_employer_company'] = 'added';
        } catch (Throwable $fkErr) {
            // Non-fatal — FK may fail if orphan data exists; column is still usable
            $done['fk_contacts_employer_company'] = 'skipped: ' . $fkErr->getMessage();
        }
    } else {
        $done['fk_contacts_employer_company'] = 'already_present';
    }

    // 4. Index
    $hasIdx = $db->query("SHOW INDEX FROM contacts WHERE Key_name = 'idx_contacts_employer_company'")->rowCount() > 0;
    if (!$hasIdx) {
        $db->exec("CREATE INDEX idx_contacts_employer_company ON contacts (employer_company_id)");
        $done['idx_contacts_employer_company'] = 'added';
    } else {
        $done['idx_contacts_employer_company'] = 'already_present';
    }

    echo json_encode(['success' => true, 'migration' => '1101', 'result' => $done]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'done_so_far' => $done]);
}
