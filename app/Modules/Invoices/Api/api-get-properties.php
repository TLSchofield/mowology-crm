<?php
/**
 * API Endpoint: Get Properties for a Contact or Company
 *
 * GET /crm/invoices/api-get-properties.php?contact_id=X
 * GET /crm/invoices/api-get-properties.php?company_id=X
 *
 * Production note: properties link to contacts via site_contact_id.
 * owner_company_id / property_manager_id do NOT exist on production.
 *
 * Output: { success: bool, properties: array, count: int }
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once PUBLIC_ROOT . '/includes/functions.php';

requireLogin();

header('Content-Type: application/json');

$contactId = intval($_GET['contact_id'] ?? 0);
$companyId = intval($_GET['company_id'] ?? 0);

if (!$contactId && !$companyId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'contact_id or company_id required']);
    exit;
}

try {
    $db = getDB();

    // Detect whether the new properties.company_id column exists (migration 1012).
    // If it does, include it in the SELECT so callers can auto-link invoices to the paying company.
    $hasPropCompany = false;
    try {
        $check = $db->query("SHOW COLUMNS FROM properties LIKE 'company_id'")->fetch(PDO::FETCH_ASSOC);
        $hasPropCompany = $check !== false;
    } catch (Throwable $e) { /* ignore */ }

    $companyCol = $hasPropCompany ? 'p.company_id,' : 'NULL as company_id,';

    if ($contactId) {
        // Primary: look up properties by site_contact_id (production model)
        $stmt = $db->prepare("
            SELECT
                p.id,
                $companyCol
                p.address,
                p.city,
                p.province,
                p.postal_code,
                p.property_type
            FROM properties p
            WHERE p.site_contact_id = ?
            ORDER BY p.city, p.address
        ");
        $stmt->execute([$contactId]);
    } else {
        // Company fallback: join via company_properties junction table (legacy) OR
        // via the new properties.company_id column (migration 1012+).
        if ($hasPropCompany) {
            $stmt = $db->prepare("
                SELECT
                    p.id,
                    p.company_id,
                    p.address,
                    p.city,
                    p.province,
                    p.postal_code,
                    p.property_type
                FROM properties p
                WHERE p.company_id = ?
                   OR p.id IN (SELECT property_id FROM company_properties WHERE company_id = ?)
                ORDER BY p.city, p.address
            ");
            $stmt->execute([$companyId, $companyId]);
        } else {
            $stmt = $db->prepare("
                SELECT
                    p.id,
                    NULL as company_id,
                    p.address,
                    p.city,
                    p.province,
                    p.postal_code,
                    p.property_type
                FROM properties p
                JOIN company_properties cp ON cp.property_id = p.id
                WHERE cp.company_id = ?
                ORDER BY p.city, p.address
            ");
            $stmt->execute([$companyId]);
        }
    }

    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // For the contact lookup, also report the contact's own company_id (if the column exists).
    // This is what create.php uses to set companyIdInput when the user picks a contact.
    $contactCompanyId = null;
    if ($contactId) {
        try {
            $c = $db->prepare("SELECT company_id FROM contacts WHERE id = ?");
            $c->execute([$contactId]);
            $r = $c->fetch(PDO::FETCH_ASSOC);
            if ($r && $r['company_id'] !== null) {
                $contactCompanyId = (int)$r['company_id'];
            }
        } catch (Throwable $e) { /* contacts.company_id column may not exist yet */ }

        // If the contact has no direct company link but all their properties share a company, use that
        if ($contactCompanyId === null && $hasPropCompany) {
            $uniqueCompanies = array_unique(array_filter(array_map(
                fn($p) => isset($p['company_id']) ? (int)$p['company_id'] : null,
                $properties
            )));
            if (count($uniqueCompanies) === 1) {
                $contactCompanyId = (int)array_values($uniqueCompanies)[0];
            }
        }
    }

    echo json_encode([
        'success'            => true,
        'properties'         => $properties,
        'count'              => count($properties),
        'contact_company_id' => $contactCompanyId,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Server error loading properties',
    ]);
    error_log('api-get-properties error: ' . $e->getMessage());
}
