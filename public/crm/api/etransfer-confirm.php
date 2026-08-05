<?php
/**
 * Confirm or dismiss a pending Interac e-Transfer notification.
 *
 * POST:
 *   csrf_token        string   (required)
 *   action            string   'record' | 'dismiss' | 'merge' | 'unmerge'
 *   notification_id   int      (required)
 *
 *   Single-invoice form (unchanged):
 *     invoice_id       int    OR invoice_number string
 *     amount           float  (optional override; defaults to remaining transfer amount)
 *
 *   Multi-invoice split (one e-Transfer covering several invoices):
 *     invoice_numbers[]  string[]  invoice number per split line
 *     amounts[]          float[]   amount per split line, index-aligned with invoice_numbers[]
 *
 *   merge (invoice is already closed — link without recording a new payment):
 *     invoice_id       int    (required)
 *
 *   unmerge (undo a merge — back to pending; refuses if a real payment exists):
 *     (no extra fields)
 *
 * Returns JSON: { ok: bool, message: string, fully_allocated?: bool, remaining?: float,
 *                 can_merge?: bool, merge_invoice_id?: int, merge_invoice_number?: string,
 *                 merged?: bool }
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
    } elseif ($action === 'merge') {
        $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
        if ($invoiceId <= 0) {
            echo json_encode(['ok' => false, 'message' => 'Missing invoice_id']);
            exit;
        }
        $result = $service->mergeAlreadyRecorded($noteId, $invoiceId, (int) $user['id']);
    } elseif ($action === 'unmerge') {
        $result = $service->unmerge($noteId, (int) $user['id']);
    } elseif ($action === 'record') {
        $db = getDB();

        // Resolve an invoice number (e.g. "INV-2026-0096") to its id.
        $resolveInvoiceId = function (string $invoiceNo) use ($db): ?int {
            $invoiceNo = trim($invoiceNo);
            if ($invoiceNo === '') {
                return null;
            }
            $norm = EtransferInboxService::extractInvoiceNumber($invoiceNo) ?? $invoiceNo;
            $look = $db->prepare("SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1");
            $look->execute([$norm]);
            $id = (int) ($look->fetchColumn() ?: 0);
            return $id > 0 ? $id : null;
        };

        $allocations = [];

        if (isset($_POST['invoice_numbers']) && is_array($_POST['invoice_numbers'])) {
            // Multi-invoice split: parallel invoice_numbers[] / amounts[] arrays.
            $numbers = $_POST['invoice_numbers'];
            $amounts = is_array($_POST['amounts'] ?? null) ? $_POST['amounts'] : [];
            foreach ($numbers as $i => $invoiceNo) {
                $invoiceNo = trim((string) $invoiceNo);
                if ($invoiceNo === '') {
                    continue;
                }
                $invoiceId = $resolveInvoiceId($invoiceNo);
                if ($invoiceId === null) {
                    echo json_encode(['ok' => false, 'message' => "No invoice found matching “{$invoiceNo}”"]);
                    exit;
                }
                $amtRaw = $amounts[$i] ?? '';
                $allocations[] = [
                    'invoice_id' => $invoiceId,
                    'amount'     => $amtRaw !== '' ? (float) $amtRaw : null,
                ];
            }
        } else {
            // Single-invoice form (unchanged contract).
            $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
            if ($invoiceId <= 0) {
                $invoiceId = $resolveInvoiceId((string) ($_POST['invoice_number'] ?? '')) ?? 0;
                if ($invoiceId <= 0) {
                    $invoiceNo = (string) ($_POST['invoice_number'] ?? '');
                    echo json_encode(['ok' => false, 'message' => $invoiceNo !== ''
                        ? "No invoice found matching “{$invoiceNo}”"
                        : 'Enter an invoice number to record against']);
                    exit;
                }
            }
            $amount = isset($_POST['amount']) && $_POST['amount'] !== '' ? (float) $_POST['amount'] : null;
            $allocations[] = ['invoice_id' => $invoiceId, 'amount' => $amount];
        }

        if (empty($allocations)) {
            echo json_encode(['ok' => false, 'message' => 'Enter an invoice number to record against']);
            exit;
        }

        $result = $service->recordPayment($noteId, $allocations, (int) $user['id']);
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
