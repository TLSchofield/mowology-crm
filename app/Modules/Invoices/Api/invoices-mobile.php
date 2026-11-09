<?php
/**
 * Add → Invoice on the phone: /api/invoices/invoices-mobile (2026-11-08).
 *
 * GET                                                 → { success, unbilled: [...], drafts: [...] }  kept on the phone
 * POST { action: 'create', visit_id, notes?, client_request_id } → a draft invoice (InvoiceFromVisitService::createFromVisit)
 * POST { action: 'send', invoice_id }                 → emails it with its PDF (InvoiceFromVisitService::send); online, one tap
 *
 * Authorization: Bearer <jwt>; admin/manager. Crew invoice their own visits from the visit screen, as before.
 * `client_request_id` lets the offline outbox resend safely; creating is also refused once the visit has an invoice.
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
    require_once CRM_INCLUDES . '/messaging.php';
    require_once APP_ROOT . '/Modules/Invoices/Services/UnbilledVisitService.php';
    require_once APP_ROOT . '/Modules/Invoices/Services/InvoiceFromVisitService.php';
    require_once APP_ROOT . '/Modules/Invoices/Services/InvoiceFromPhoneService.php';

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];
    if (!jwtIsAdmin($jwtUser['role'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Only the office can invoice from here. Invoice your own visits from the visit.']);
        exit;
    }

    $db = getDB();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $phone = new InvoiceFromPhoneService($db);
        echo json_encode(['success' => true, 'unbilled' => $phone->unbilled(), 'drafts' => $phone->drafts($userId)]);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $input   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action  = (string)($input['action'] ?? '');
    $service = new InvoiceFromVisitService($db);

    if ($action === 'send') {
        echo json_encode($service->send((int)($input['invoice_id'] ?? 0), $userId));
        exit;
    }
    if ($action !== 'create') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
    }

    $idempKey = trim((string)($input['client_request_id'] ?? ''));
    if ($idempKey !== '') {
        $cached = idempotencyCheck($db, $idempKey, $userId);
        if ($cached !== null) { echo $cached; exit; }
    }

    $result = $service->createFromVisit((int)($input['visit_id'] ?? 0), 0, (string)($input['notes'] ?? ''), $userId);
    $json = json_encode($result);
    if (empty($result['success'])) {
        http_response_code(422);
        echo $json;
        exit;
    }
    if ($idempKey !== '') {
        idempotencyStore($db, $idempKey, $userId, 'invoices-mobile', 'create', $json, 720);
    }
    echo $json;
} catch (Throwable $e) {
    error_log('invoices/invoices-mobile API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error. Please try again.']);
}
