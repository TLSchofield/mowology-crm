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
            // Check for related records that would block deletion
            $quoteCount = $db->prepare("SELECT COUNT(*) FROM quotes WHERE company_id = ?");
            $quoteCount->execute([$companyId]);
            $qc = (int)$quoteCount->fetchColumn();

            $jobCount = $db->prepare("SELECT COUNT(*) FROM job_plans WHERE company_id = ?");
            $jobCount->execute([$companyId]);
            $jc = (int)$jobCount->fetchColumn();

            $invoiceCount = $db->prepare("SELECT COUNT(*) FROM invoices WHERE company_id = ?");
            $invoiceCount->execute([$companyId]);
            $ic = (int)$invoiceCount->fetchColumn();

            if ($qc > 0 || $jc > 0 || $ic > 0) {
                $refs = [];
                if ($qc) $refs[] = "{$qc} quote(s)";
                if ($jc) $refs[] = "{$jc} job plan(s)";
                if ($ic) $refs[] = "{$ic} invoice(s)";
                echo json_encode([
                    'success' => false,
                    'error' => "Cannot delete: company has " . implode(', ', $refs) . ". Archive it instead."
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

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (Exception $e) {
    error_log("Companies API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
