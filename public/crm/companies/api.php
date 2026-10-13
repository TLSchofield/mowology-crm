<?php
/**
 * Companies - API Endpoints
 * Handles archive, restore, and delete actions via AJAX.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('clients.view');

header('Content-Type: application/json');

// Every action here changes a company (archive/restore/delete/link) — office only.
// Managers hold clients.edit; crew hold clients.view only (Fable review 2026-10-12).
requirePermission('clients.edit');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$action = $input['action'] ?? '';
$companyId = (int)($input['company_id'] ?? 0);

if (!$companyId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid company ID']);
    exit;
}

$db = getDB();

// Verify company exists
$check = $db->prepare("SELECT id, company_name FROM companies WHERE id = ?");
$check->execute([$companyId]);
$company = $check->fetch(PDO::FETCH_ASSOC);

if (!$company) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Company not found']);
    exit;
}

try {
    switch ($action) {
        case 'archive':
            $db->prepare("UPDATE companies SET account_status = 'inactive' WHERE id = ?")->execute([$companyId]);
            logActivityExtended($user['id'], 'Company archived', "Archived: {$company['company_name']}", $companyId);
            echo json_encode(['success' => true]);
            break;

        case 'restore':
            $db->prepare("UPDATE companies SET account_status = 'active' WHERE id = ?")->execute([$companyId]);
            logActivityExtended($user['id'], 'Company restored', "Restored: {$company['company_name']}", $companyId);
            echo json_encode(['success' => true]);
            break;

        case 'delete':
            // A company with any quote, job plan or invoice is archived, never deleted
            // (invoices.company_id cascades on delete).
            require_once APP_ROOT . '/Modules/Contacts/Services/CompanyDeleteGuard.php';
            $guard    = new CompanyDeleteGuard($db);
            $blockers = $guard->blockers([$companyId]);
            if ($blockers) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Cannot delete: company has ' . $guard->describe($blockers) . '. Archive it instead.'
                ]);
                break;
            }

            // Delete junction records first
            $db->prepare("DELETE FROM company_properties WHERE company_id = ?")->execute([$companyId]);

            // Delete the company
            $db->prepare("DELETE FROM companies WHERE id = ?")->execute([$companyId]);
            logActivityExtended($user['id'], 'Company deleted', "Deleted: {$company['company_name']}");
            echo json_encode(['success' => true]);
            break;

        case 'link_property':
            $propertyId       = (int)($input['property_id'] ?? 0);
            $relationshipType = $input['relationship_type'] ?? 'manager';
            $isPrimary        = !empty($input['is_primary']) ? 1 : 0;

            if (!$propertyId) {
                echo json_encode(['success' => false, 'error' => 'Invalid property ID']);
                break;
            }
            $validRelTypes = ['owner', 'manager', 'tenant', 'billing'];
            if (!in_array($relationshipType, $validRelTypes, true)) {
                $relationshipType = 'manager';
            }

            // Verify property exists
            $propCheck = $db->prepare("SELECT id, address, city FROM properties WHERE id = ?");
            $propCheck->execute([$propertyId]);
            $prop = $propCheck->fetch(PDO::FETCH_ASSOC);
            if (!$prop) {
                echo json_encode(['success' => false, 'error' => 'Property not found']);
                break;
            }

            // Upsert the junction row
            $db->prepare("
                INSERT INTO company_properties (company_id, property_id, relationship_type, is_primary)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE relationship_type = VALUES(relationship_type), is_primary = VALUES(is_primary)
            ")->execute([$companyId, $propertyId, $relationshipType, $isPrimary]);

            logActivityExtended($user['id'], 'company_property_linked',
                json_encode(['company_id' => $companyId, 'property_id' => $propertyId,
                             'relationship_type' => $relationshipType, 'is_primary' => $isPrimary]));
            echo json_encode(['success' => true, 'property' => $prop]);
            break;

        case 'unlink_property':
            $propertyId = (int)($input['property_id'] ?? 0);
            if (!$propertyId) {
                echo json_encode(['success' => false, 'error' => 'Invalid property ID']);
                break;
            }
            $db->prepare("DELETE FROM company_properties WHERE company_id = ? AND property_id = ?")
               ->execute([$companyId, $propertyId]);
            echo json_encode(['success' => true]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (Exception $e) {
    error_log("Companies API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
