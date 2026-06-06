<?php
/**
 * Migration 1049 — backfill the Client/Account model.
 * Client/Account model Phase 1. Mirrors database/migrations/1049_backfill_client_account_model.sql.
 *
 * Run by visiting this URL as an admin. IDEMPOTENT — safe to re-run:
 *   INSERT IGNORE + UNIQUE(legacy_*) → no dup clients
 *   INSERT IGNORE + UNIQUE(client_id,contact_id,role) → no dup links
 *   UPDATE ... WHERE client_id IS NULL → only fills unset rows
 *
 * Add ?dry=1 to preview row counts WITHOUT writing (runs in a rolled-back txn).
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
$dry     = isset($_GET['dry']) && $_GET['dry'] === '1';

function step1049(PDO $db, string $sql, string $label, array &$results): void {
    try {
        $n = $db->exec($sql);
        $results[] = ['step' => $label, 'status' => 'ok', 'rows_affected' => $n];
    } catch (PDOException $e) {
        $results[] = ['step' => $label, 'status' => 'error', 'msg' => $e->getMessage()];
        throw $e; // abort the run (and roll back if dry) on first hard error
    }
}

// NOTE: contains DDL-free DML only, so wrapping in a transaction is safe here
// (unlike the ALTERs in 1048). Dry-run rolls back; live run commits.
$db->beginTransaction();
try {
    // STEP A — organization clients from companies
    step1049($db,
        "INSERT IGNORE INTO clients
           (site_id, client_type, display_name, billing_address, billing_city,
            billing_province, billing_postal_code, billing_email, billing_phone,
            payment_terms, payment_method, lifecycle_stage, status, legacy_company_id)
         SELECT 1, COALESCE(co.company_type,'business'), co.company_name,
            co.billing_address, co.billing_city, co.billing_province, co.billing_postal_code,
            co.billing_email, co.billing_phone,
            COALESCE(co.payment_terms,'Net 30'), COALESCE(co.payment_method,'invoice'),
            COALESCE(co.lifecycle_stage,'prospect'),
            CASE co.account_status WHEN 'inactive' THEN 'inactive'
                                   WHEN 'suspended' THEN 'suspended' ELSE 'active' END,
            co.id
         FROM companies co",
        'A: org clients from companies', $results);

    // STEP B — individual clients from contacts (every named contact)
    step1049($db,
        "INSERT IGNORE INTO clients
           (site_id, client_type, display_name, billing_email, billing_phone,
            lifecycle_stage, status, legacy_contact_id)
         SELECT 1, 'individual',
            NULLIF(TRIM(CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,''))),''),
            c.email, COALESCE(NULLIF(c.mobile,''), c.phone),
            COALESCE(c.lifecycle_stage,'lead'),
            CASE WHEN c.is_active=1 THEN 'active' ELSE 'inactive' END,
            c.id
         FROM contacts c
         WHERE TRIM(CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,''))) <> ''",
        'B: individual clients from contacts', $results);

    // STEP C — link individual client to its own contact
    step1049($db,
        "INSERT IGNORE INTO client_contacts
           (client_id, contact_id, role, is_primary, receives_invoices, receives_notifications)
         SELECT cl.id, cl.legacy_contact_id, 'owner', 1, 1, 1
         FROM clients cl WHERE cl.legacy_contact_id IS NOT NULL",
        'C: link individual owners', $results);

    // STEP D.1 — org primary contact (routing flag from old enum)
    step1049($db,
        "INSERT IGNORE INTO client_contacts
           (client_id, contact_id, role, is_primary, receives_invoices, receives_notifications)
         SELECT cl.id, co.primary_contact_id, 'owner', 1,
            CASE WHEN co.invoice_routing_method IN ('primary_contact','both_contacts')
                      OR co.invoice_routing_method IS NULL THEN 1 ELSE 0 END, 1
         FROM clients cl JOIN companies co ON co.id = cl.legacy_company_id
         WHERE cl.legacy_company_id IS NOT NULL AND co.primary_contact_id IS NOT NULL",
        'D1: link org primary contacts', $results);

    // STEP D.2 — org billing contact (when distinct)
    step1049($db,
        "INSERT IGNORE INTO client_contacts
           (client_id, contact_id, role, is_primary, receives_invoices, receives_notifications)
         SELECT cl.id, co.billing_contact_id, 'billing', 0,
            CASE WHEN co.invoice_routing_method IN ('billing_contact','both_contacts') THEN 1 ELSE 0 END, 0
         FROM clients cl JOIN companies co ON co.id = cl.legacy_company_id
         WHERE cl.legacy_company_id IS NOT NULL AND co.billing_contact_id IS NOT NULL
           AND co.billing_contact_id <> COALESCE(co.primary_contact_id, 0)",
        'D2: link org billing contacts', $results);

    // STEP E — stamp client_id onto billable entities
    step1049($db,
        "UPDATE properties p
         LEFT JOIN clients oc ON oc.legacy_company_id = p.company_id
         LEFT JOIN clients ic ON ic.legacy_contact_id = p.site_contact_id
         SET p.client_id = COALESCE(oc.id, ic.id)
         WHERE p.client_id IS NULL AND COALESCE(oc.id, ic.id) IS NOT NULL",
        'E: stamp properties.client_id', $results);

    step1049($db,
        "UPDATE invoices i
         LEFT JOIN clients oc ON oc.legacy_company_id = i.company_id
         LEFT JOIN clients ic ON ic.legacy_contact_id = i.contact_id
         LEFT JOIN properties p ON p.id = i.property_id
         SET i.client_id = COALESCE(oc.id, ic.id, p.client_id)
         WHERE i.client_id IS NULL AND COALESCE(oc.id, ic.id, p.client_id) IS NOT NULL",
        'E: stamp invoices.client_id', $results);

    step1049($db,
        "UPDATE quotes q
         LEFT JOIN clients oc ON oc.legacy_company_id = q.company_id
         LEFT JOIN properties p ON p.id = q.property_id
         SET q.client_id = COALESCE(oc.id, p.client_id)
         WHERE q.client_id IS NULL AND COALESCE(oc.id, p.client_id) IS NOT NULL",
        'E: stamp quotes.client_id', $results);

    step1049($db,
        "UPDATE job_plans jp
         LEFT JOIN clients oc ON oc.legacy_company_id = jp.company_id
         LEFT JOIN properties p ON p.id = jp.property_id
         SET jp.client_id = COALESCE(oc.id, p.client_id)
         WHERE jp.client_id IS NULL AND COALESCE(oc.id, p.client_id) IS NOT NULL",
        'E: stamp job_plans.client_id', $results);

    if ($dry) {
        $db->rollBack();
        echo json_encode(['ok' => true, 'migration' => '1049', 'dry_run' => true,
            'note' => 'rolled back — no changes written', 'results' => $results], JSON_PRETTY_PRINT);
    } else {
        $db->commit();
        echo json_encode(['ok' => true, 'migration' => '1049', 'dry_run' => false,
            'results' => $results], JSON_PRETTY_PRINT);
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    http_response_code(500);
    echo json_encode(['ok' => false, 'migration' => '1049',
        'error' => $e->getMessage(), 'results' => $results], JSON_PRETTY_PRINT);
}
