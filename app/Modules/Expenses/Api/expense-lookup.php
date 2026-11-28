<?php
/**
 * iOS Expense Lookup API — JWT-authenticated, read-only.
 *
 * GET /api/expenses/expense-lookup?type=vendors&q=home
 *     → { success, vendors: [{id, name, aliases, default_accounting_category, default_gbp_category}] }
 * GET /api/expenses/expense-lookup?type=jobs&q=smith
 *     → { success, jobs: [{id, plan_number, service_type, status, property_id, contact_id, address, contact_name}] }
 * GET /api/expenses/expense-lookup?type=categories
 *     → { success, accounting_categories: [], gbp_categories: [], payment_methods: [] }
 * GET /api/expenses/expense-lookup?type=duplicates&total=12.34&expense_date=YYYY-MM-DD[&vendor_name=&vendor_id=&exclude_id=]
 *     → { success, has_duplicates, duplicates: [...] }
 *
 * POST {action:'adopt_commons', kb_vendor_id} (expenses.edit) → { success, vendor_id, created, vendor }: makes a commons
 *      type-ahead row (GET type=vendors&commons=1, id null, source 'commons') a local vendor, linked, once.
 *
 * Uses `type=` rather than `action=` on purpose — the /api/ router's QSA rewrite
 * appends its own `action` param and PHP keeps the last one, so `?action=` 404s.
 *
 * The session-authenticated web twins (vendors.php?action=search|categories,
 * expenses.php?action=search_jobs|check_duplicates) call the same
 * ExpenseLookupService methods, so the Android WebView and native iOS review forms
 * see identical vendor/job/category/duplicate data.
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

header('Content-Type: application/json');

try {
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once APP_ROOT . '/Core/ApiResponse.php'; // generic errors to the user, details to the log
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseLookupService.php';

    $jwtUser = requireJwt();

    // The one write in this family: the owner picked a commons vendor in the type-ahead (row with id null, source
    // 'commons'). POST {action:'adopt_commons', kb_vendor_id} creates + links the local vendor once (idempotent).
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true);
        if (!is_array($in) || ($in['action'] ?? '') !== 'adopt_commons') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
            exit;
        }
        if (!jwtUserHasPermission($jwtUser, 'expenses.edit')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Permission denied: expenses.edit required']);
            exit;
        }
        require_once APP_ROOT . '/Modules/Knowledge/Services/VendorKnowledgeService.php';
        $r = (new VendorKnowledgeService(getDB()))->adoptCommonsVendorForClient((int)($in['kb_vendor_id'] ?? 0));
        if ($r === null) {
            echo json_encode(['success' => false, 'error' => 'That vendor is not available right now']);
            exit;
        }
        echo json_encode(['success' => true] + $r);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'GET or POST required']);
        exit;
    }

    $svc  = new ExpenseLookupService(getDB());
    $type = (string)($_GET['type'] ?? '');

    switch ($type) {
        case 'vendors':
            echo json_encode(['success' => true, 'vendors' => $svc->searchVendors($_GET['q'] ?? '', ($_GET['commons'] ?? '') === '1')]);
            break;

        case 'jobs':
            echo json_encode(['success' => true, 'jobs' => $svc->searchJobs($_GET['q'] ?? '')]);
            break;

        case 'categories':
            echo json_encode(['success' => true] + ExpenseLookupService::categories());
            break;

        case 'products':
            echo json_encode(['success' => true, 'products' => $svc->searchProducts($_GET['q'] ?? '')]);
            break;

        case 'vendor_items':
            // The "Add Item" picker: this vendor's usual items, its catalogue, other products on search.
            echo json_encode(['success' => true] + $svc->vendorItemSuggestions((int)($_GET['vendor_id'] ?? 0), (string)($_GET['q'] ?? '')));
            break;

        case 'duplicates':
            $dups = $svc->findDuplicates(
                $_GET['vendor_name'] ?? null,
                !empty($_GET['vendor_id']) ? (int)$_GET['vendor_id'] : null,
                isset($_GET['total']) ? (float)$_GET['total'] : null,
                $_GET['expense_date'] ?? null,
                !empty($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : null
            );
            echo json_encode(['success' => true, 'has_duplicates' => count($dups) > 0, 'duplicates' => $dups]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown lookup type']);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Lookup failed: ' . ApiResponse::message($e, 'expense-lookup')]);
}
