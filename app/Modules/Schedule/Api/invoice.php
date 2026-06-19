<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/invoice.php
 *
 * Mobile Schedule API — Invoice a completed visit (timed extras + invoice)
 * Authorization: Bearer <jwt>
 * Content-Type: application/json
 *
 * POST { action: 'preview', visit_id }
 *   → { success, contact_name, contact_email, amount, plan_title, extras_minutes, extras_rate, existing_invoice }
 * POST { action: 'create',  visit_id, extras_minutes?, notes? }
 *   → { success, invoice_id, invoice_number, line_items[], subtotal, tax_rate, tax_amount, total }
 * POST { action: 'send',    invoice_id }
 *   → { success, invoice_number, sent_to[] }
 *
 * JWT-authenticated counterpart of public/crm/api/schedule-invoice.php.
 * All crew may invoice their visits (no role gate). No CSRF — JWT is stateless.
 */

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    require_once APP_ROOT . '/Core/config.php';
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once CRM_INCLUDES . '/messaging.php';
    require_once APP_ROOT . '/Modules/Invoices/Services/InvoiceFromVisitService.php';

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }

    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = trim($input['action'] ?? '');

    $db      = getDB();
    $service = new InvoiceFromVisitService($db);

    switch ($action) {
        case 'preview':
            echo json_encode($service->preview((int)($input['visit_id'] ?? 0)));
            break;

        case 'create':
            echo json_encode($service->createFromVisit(
                (int)($input['visit_id'] ?? 0),
                (int)($input['extras_minutes'] ?? 0),
                (string)($input['notes'] ?? ''),
                $userId
            ));
            break;

        case 'send':
            echo json_encode($service->send((int)($input['invoice_id'] ?? 0), $userId));
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Unknown action: {$action}"]);
    }

} catch (Throwable $e) {
    error_log('schedule/invoice.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (isset($db) && $db->inTransaction()) { try { $db->rollBack(); } catch (Throwable $re) {} }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error. Please try again.']);
}
