<?php
/**
 * Accounting reconciliation API — invoice ↔ bank-deposit AND expense ↔ bank-transaction
 *
 * GET  ?action=candidates&invoice_id=X                — scored deposit candidates for one invoice
 * POST {action:'attach', transaction_id, allocations:[{invoice_id, amount}, ...]}
 * POST {action:'detach', transaction_id[, invoice_id]}
 * Reads require billing.view; writes require billing.edit + CSRF.
 *
 * GET  ?action=expense_candidates&expense_id=X        — scored transaction candidates for one expense
 * GET  ?action=transaction_expense_candidates&transaction_id=X — scored expense candidates for one transaction
 * POST {action:'attach_expense', transaction_id, expense_id}
 * POST {action:'detach_expense', transaction_id}
 * Reads require expenses.view; writes require expenses.edit + CSRF.
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

    $method = $_SERVER['REQUEST_METHOD'];
    $input  = [];
    if ($method === 'POST') {
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $input['action'] ?? '';
    } else {
        $action = $_GET['action'] ?? 'candidates';
    }

    // Expense-side actions gate on expenses.*, invoice-side actions gate on billing.*
    // (billing.view/edit stays the default for the original 'candidates'/'attach'/'detach' actions).
    $isExpenseAction = in_array($action, ['expense_candidates', 'transaction_expense_candidates', 'attach_expense', 'detach_expense'], true);
    $viewPermission  = $isExpenseAction ? 'expenses.view' : 'billing.view';
    $editPermission  = $isExpenseAction ? 'expenses.edit' : 'billing.edit';

    requirePermission($viewPermission);
    if ($method === 'POST') {
        // Writes need edit permission + CSRF (verify before releasing the session lock)
        requirePermission($editPermission);
        if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }
    }

    $db = getDB();
    session_write_close(); // release session lock — DB work ahead

    require_once APP_ROOT . '/Modules/Accounting/Services/InvoiceReconciliationService.php';
    $svc = new InvoiceReconciliationService($db);

    require_once APP_ROOT . '/Modules/Accounting/Services/BankImportService.php';
    $bankImportSvc = new BankImportService($db);

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

        case 'expense_candidates': {
            $expenseId = (int)($_GET['expense_id'] ?? 0);
            if (!$expenseId) throw new InvalidArgumentException('Missing expense_id');
            echo json_encode(['ok' => true, 'candidates' => $bankImportSvc->candidateTransactionsForExpense($expenseId)]);
            break;
        }

        case 'transaction_expense_candidates': {
            $txId = (int)($_GET['transaction_id'] ?? 0);
            if (!$txId) throw new InvalidArgumentException('Missing transaction_id');
            echo json_encode(['ok' => true, 'candidates' => $bankImportSvc->candidateExpensesForTransaction($txId)]);
            break;
        }

        case 'attach_expense': {
            $txId      = (int)($input['transaction_id'] ?? 0);
            $expenseId = (int)($input['expense_id'] ?? 0);
            if (!$txId)      throw new InvalidArgumentException('Missing transaction_id');
            if (!$expenseId) throw new InvalidArgumentException('Missing expense_id');

            $result = $bankImportSvc->attachExpenseMatch($txId, $expenseId, (int)$user['id']);

            if (function_exists('logActivityExtended')) {
                logActivityExtended((int)$user['id'], 'Receipt matched to transaction',
                    'Bank transaction #' . $txId . ' matched to expense #' . $expenseId
                    . ' (confidence ' . $result['confidence'] . '%)', null, null, null, $expenseId);
            }

            echo json_encode(['ok' => true, 'result' => $result]);
            break;
        }

        case 'detach_expense': {
            $txId = (int)($input['transaction_id'] ?? 0);
            if (!$txId) throw new InvalidArgumentException('Missing transaction_id');

            $result = $bankImportSvc->detachExpenseMatch($txId, (int)$user['id']);

            if (function_exists('logActivityExtended')) {
                logActivityExtended((int)$user['id'], 'Receipt match removed',
                    'Bank transaction #' . $txId . ' unmatched from expense #' . $result['expense_id'],
                    null, null, null, $result['expense_id']);
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
