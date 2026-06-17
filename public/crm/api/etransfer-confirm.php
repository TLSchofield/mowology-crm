<?php
/**
 * Confirm or dismiss a pending Interac e-Transfer notification.
 *
 * POST:
 *   csrf_token       string (required)
 *   action           string 'record' | 'dismiss'
 *   notification_id  int    (required)
 *   invoice_id       int    (required for 'record')
 *   amount           float  (optional override; defaults to invoice balance)
 *
 * Returns JSON: { ok: bool, message: string }
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Modules/Accounting/Services/EtransferInboxService.php';

requireLogin();
$user = getCurrentUser();
requirePermission('billing.edit');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$action = $_POST['action'] ?? '';
$noteId = (int) ($_POST['notification_id'] ?? 0);
if ($noteId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Missing notification_id']);
    exit;
}

$service = new EtransferInboxService(getDB());

try {
    if ($action === 'dismiss') {
        $result = $service->dismiss($noteId, (int) $user['id']);
    } elseif ($action === 'record') {
        $invoiceId  = (int) ($_POST['invoice_id'] ?? 0);
        $invoiceNo  = trim((string) ($_POST['invoice_number'] ?? ''));
        // Resolve an invoice number (e.g. "INV-2026-0096") to its id.
        if ($invoiceId <= 0 && $invoiceNo !== '') {
            $norm = EtransferInboxService::extractInvoiceNumber($invoiceNo) ?? $invoiceNo;
            $look = getDB()->prepare("SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1");
            $look->execute([$norm]);
            $invoiceId = (int) ($look->fetchColumn() ?: 0);
            if ($invoiceId <= 0) {
                echo json_encode(['ok' => false, 'message' => "No invoice found matching “{$invoiceNo}”"]);
                exit;
            }
        }
        if ($invoiceId <= 0) {
            echo json_encode(['ok' => false, 'message' => 'Enter an invoice number to record against']);
            exit;
        }
        $amount = isset($_POST['amount']) && $_POST['amount'] !== '' ? (float) $_POST['amount'] : null;
        $result = $service->recordPayment($noteId, $invoiceId, $amount, (int) $user['id']);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Unknown action']);
        exit;
    }
    echo json_encode($result);
} catch (\Throwable $e) {
    error_log('[etransfer-confirm] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Server error']);
}
