<?php
/**
 * Schedule Invoice API (web / session-auth controller)
 *
 * Handles the post-job-completion invoice flow from the desktop schedule sheet.
 * Business logic lives in InvoiceFromVisitService — this file is a thin controller.
 * The mobile (JWT) counterpart is app/Modules/Schedule/Api/invoice.php.
 *
 * POST actions:
 *   preview — return visit/client data for the sheet header
 *   create  — create a draft invoice from a completed visit + extras + notes
 *   send    — send a draft invoice by id
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/messaging.php';
require_once APP_ROOT . '/Modules/Invoices/Services/InvoiceFromVisitService.php';

requireLogin();
$user = getCurrentUser();

header('Content-Type: application/json; charset=utf-8');
session_write_close();

$db    = getDB();
$input = json_decode(file_get_contents('php://input'), true) ?? [];

function siOut(array $data): void {
    echo json_encode($data);
    exit;
}

$action  = $input['action'] ?? '';
$service = new InvoiceFromVisitService($db);

try {
    if ($action === 'preview') {
        siOut($service->preview((int)($input['visit_id'] ?? 0)));
    }
    elseif ($action === 'create') {
        siOut($service->createFromVisit(
            (int)($input['visit_id'] ?? 0),
            (int)($input['extras_minutes'] ?? 0),
            (string)($input['notes'] ?? ''),
            (int)$user['id']
        ));
    }
    elseif ($action === 'send') {
        siOut($service->send((int)($input['invoice_id'] ?? 0), (int)$user['id']));
    }
    else {
        siOut(['success' => false, 'error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    error_log('schedule-invoice.php [' . $action . '] error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (isset($db) && $db->inTransaction()) { try { $db->rollBack(); } catch (Throwable $re) {} }
    siOut(['success' => false, 'error' => 'Server error. Please try again.']);
}
