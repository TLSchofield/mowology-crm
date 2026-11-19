<?php
/**
 * Draft quotes from the phone: /api/quotes/quote-mobile (Add → Quote, 2026-11-08).
 *
 * GET  → { success, gst_percent, products: [{id, name, unit, price}] }  kept on the phone for no-signal use
 * POST { property_id, title, notes?, line_items: [{product_id?, description, quantity, unit_price, unit_type?}], client_request_id }
 *      → { success, quote_id, quote_number } or { success: false, error }
 *
 * Authorization: Bearer <jwt>; billing.edit (as the web's quote page) or admin/manager. Nothing is sent to a customer
 * here: the quote is a draft in Sam's "Quotes to send". `client_request_id` lets the offline outbox resend safely.
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
header('X-Content-Type-Options: nosniff');

try {
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once APP_ROOT . '/Core/IdempotencyHelper.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Modules/Accounting/Services/TaxEngine.php';
    require_once APP_ROOT . '/Modules/Quotes/Services/QuoteService.php';
    require_once APP_ROOT . '/Modules/Quotes/Services/QuoteFromPhoneService.php';

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];
    if (!jwtIsAdmin($jwtUser['role']) && !jwtUserHasPermission($jwtUser, 'billing.edit')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => "Your account can't make quotes. Ask the office."]);
        exit;
    }

    $db      = getDB();
    $service = new QuoteFromPhoneService($db);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(['success' => true] + $service->priceList());
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $idempKey = trim((string)($input['client_request_id'] ?? ''));
    if ($idempKey !== '') {
        $cached = idempotencyCheck($db, $idempKey, $userId);
        if ($cached !== null) { echo $cached; exit; }
    }

    $result = $service->create($input, $userId);
    if (empty($result['success'])) {
        http_response_code((int)($result['status'] ?? 422));
        echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Could not save the quote.']);
        exit;
    }

    $json = json_encode($result);
    if ($idempKey !== '') {
        idempotencyStore($db, $idempKey, $userId, 'quote-mobile', 'create', $json, 720);
    }
    echo $json;
} catch (Throwable $e) {
    error_log('quotes/quote-mobile API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error. Please try again.']);
}
