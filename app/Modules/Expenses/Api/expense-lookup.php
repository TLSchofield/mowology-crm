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
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseLookupService.php';

    $jwtUser = requireJwt();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'GET required']);
        exit;
    }

    $svc  = new ExpenseLookupService(getDB());
    $type = (string)($_GET['type'] ?? '');

    switch ($type) {
        case 'vendors':
            echo json_encode(['success' => true, 'vendors' => $svc->searchVendors($_GET['q'] ?? '')]);
            break;

        case 'jobs':
            echo json_encode(['success' => true, 'jobs' => $svc->searchJobs($_GET['q'] ?? '')]);
            break;

        case 'categories':
            echo json_encode(['success' => true] + ExpenseLookupService::categories());
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
    echo json_encode(['success' => false, 'error' => 'Lookup failed: ' . $e->getMessage()]);
}
