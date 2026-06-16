<?php
/**
 * Invoice ↔ bank-deposit reconciliation API
 *
 * GET  ?action=candidates&invoice_id=X      — scored deposit candidates for one invoice
 * POST {action:'attach', transaction_id, allocations:[{invoice_id, amount}, ...]}
 * POST {action:'detach', transaction_id[, invoice_id]}
 *
 * Reads require billing.view; writes require billing.edit + CSRF.
 */
declare(strict_types=1);
header('Content-Type: application/json');

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

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';

    requireLogin();
    $user = getCurrentUser();
    requirePermission('billing.view');

    $method = $_SERVER['REQUEST_METHOD'];
    $input  = [];
    if ($method === 'POST') {
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $input['action'] ?? '';
        // Writes need edit permission + CSRF (verify before releasing the session lock)
        requirePermission('billing.edit');
        if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }
    } else {
        $action = $_GET['action'] ?? 'candidates';
    }

    $db = getDB();
    session_write_close(); // release session lock — DB work ahead

    require_once APP_ROOT . '/Modules/Accounting/Services/InvoiceReconciliationService.php';
    $svc = new InvoiceReconciliationService($db);

    switch ($action) {

        case 'candidates': {
            $invoiceId = (int)($_GET['invoice_id'] ?? 0);
            if (!$invoiceId) throw new InvalidArgumentException('Missing invoice_id');
            echo json_encode(['ok' => true, 'candidates' => $svc->candidatesForInvoice($invoiceId)]);
            break;
        }

        case 'attach': {
            $txId        = (int)($input['transaction_id'] ?? 0);
            $allocations = $input['allocations'] ?? [];
            if (!$txId)              throw new InvalidArgumentException('Missing transaction_id');
            if (!is_array($allocations) || empty($allocations)) {
                throw new InvalidArgumentException('Missing allocations');
            }

            $result = $svc->attach($txId, $allocations, (int)$user['id']);

            // Activity log + marketing attribution (kept out of the pure service)
            foreach ($result['recorded'] as $r) {
                $detail = 'Bank deposit attached: $' . number_format($r['applied'], 2)
                        . ' to ' . $r['invoice_number']
                        . ' (' . ($r['fully_paid'] ? 'paid in full' : 'partial') . ')';
                if (function_exists('logActivityExtended')) {
                    logActivityExtended((int)$user['id'], 'Payment reconciled', $detail, null, null, null, (int)$r['invoice_id']);
                }
                if ($r['fully_paid']) {
                    $attr = APP_ROOT . '/Modules/Marketing/Services/AttributionService.php';
                    if (is_file($attr)) {
                        require_once $attr;
                        try { AttributionService::onInvoicePaid($db, (int)$r['invoice_id'], (float)$r['applied']); }
                        catch (\Throwable $e) { error_log('[reconciliation] attribution: ' . $e->getMessage()); }
                    }
                }
            }

            echo json_encode(['ok' => true, 'result' => $result]);
            break;
        }

        case 'detach': {
            $txId      = (int)($input['transaction_id'] ?? 0);
            $invoiceId = isset($input['invoice_id']) ? (int)$input['invoice_id'] : null;
            if (!$txId) throw new InvalidArgumentException('Missing transaction_id');

            $result = $svc->detach($txId, $invoiceId ?: null, (int)$user['id']);

            if (function_exists('logActivityExtended')) {
                foreach ($result['reversed'] as $r) {
                    logActivityExtended((int)$user['id'], 'Payment detached',
                        'Bank deposit detached: $' . number_format($r['amount'], 2), null, null, null, (int)$r['invoice_id']);
                }
            }

            echo json_encode(['ok' => true, 'result' => $result]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    }

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[accounting-reconciliation] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
