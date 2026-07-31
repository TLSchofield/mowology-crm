<?php
/**
 * Accounting Transactions API
 *
 * GET  ?action=list[&type=&date_from=&date_to=&account_id=&status=&source=&search=&page=]
 * GET  ?action=get&id=X
 * GET  ?action=find_match&id=X  — find matching expense/invoice for a bank import tx
 * GET  ?action=sync          — sync invoices + expenses into ledger
 * GET  ?action=dashboard     — real-time dashboard metrics
 * POST {action: 'create', ...}
 * POST {action: 'update', id, ...}
 * POST {action: 'delete', id}
 * POST {action: 'recategorize', id, account_id}
 * POST {action: 'flag_review', id, flag}
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
    requirePermission('expenses.view');

    $canEdit = userHasPermission('expenses.edit');

    $db = getDB();
    session_write_close(); // release session lock — heavy queries ahead

    require_once APP_ROOT . '/Modules/Accounting/Services/AccountingService.php';
    require_once APP_ROOT . '/Modules/Accounting/Services/RulesEngine.php';
    require_once APP_ROOT . '/Modules/Accounting/Services/TaxEngine.php';

    $svc = new AccountingService($db);

    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'list';
    } else {
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $input['action'] ?? '';
    }

    switch ($action) {

        // ── List with filters ────────────────────────────────────────────────
        case 'list':
            $filters = [
                'type'         => $_GET['type']         ?? '',
                'date_from'    => $_GET['date_from']    ?? '',
                'date_to'      => $_GET['date_to']      ?? '',
                'account_id'   => $_GET['account_id']   ?? '',
                'status'       => $_GET['status']       ?? '',
                'source'       => $_GET['source']       ?? '',
                'needs_review' => $_GET['needs_review'] ?? '',
                'job_id'       => $_GET['job_id']       ?? '',
                'search'       => $_GET['search']       ?? '',
            ];
            $page    = max(1, (int)($_GET['page'] ?? 1));
            $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 50)));

            echo json_encode(['ok' => true, 'result' => $svc->listTransactions($filters, $page, $perPage)]);
            break;

        // ── Find match for a bank import row ────────────────────────────────────
        case 'find_match':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) throw new Exception('Missing id', 400);
            $match = $svc->findMatchForBankTransaction($id);
            echo json_encode(['ok' => true, 'match' => $match]);
            break;

        // ── Single record ────────────────────────────────────────────────────
        case 'get':
            $id   = (int)($_GET['id'] ?? 0);
            $rows = $svc->listTransactions(['id' => $id], 1, 1);
            $tx   = $rows['data'][0] ?? null;
            if (!$tx) throw new Exception('Transaction not found', 404);
            echo json_encode(['ok' => true, 'transaction' => $tx]);
            break;

        // ── Sync from invoices + expenses ────────────────────────────────────
        case 'sync':
            if (!$canEdit) throw new Exception('Permission denied', 403);
            $result = $svc->syncAll();
            echo json_encode(['ok' => true, 'result' => $result]);
            break;

        // ── Dashboard metrics ─────────────────────────────────────────────────
        case 'dashboard':
            $metrics = $svc->getDashboardMetrics();
            echo json_encode(['ok' => true, 'metrics' => $metrics]);
            break;

        // ── Create manual transaction ─────────────────────────────────────────
        case 'create':
            if (!$canEdit) throw new Exception('Permission denied', 403);

            // Validate required fields
            foreach (['transaction_date', 'type', 'account_id', 'amount'] as $f) {
                if (empty($input[$f])) throw new Exception("Missing required field: $f");
            }

            $id = $svc->createManualTransaction($input, (int)$user['id']);
            echo json_encode(['ok' => true, 'id' => $id, 'message' => 'Transaction created']);
            break;

        // ── Update ────────────────────────────────────────────────────────────
        case 'update':
            if (!$canEdit) throw new Exception('Permission denied', 403);
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('Missing id');
            $svc->updateTransaction($id, $input);
            echo json_encode(['ok' => true, 'message' => 'Transaction updated']);
            break;

        // ── Delete (manual only) ──────────────────────────────────────────────
        case 'delete':
            if (!$canEdit) throw new Exception('Permission denied', 403);
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('Missing id');
            $ok = $svc->deleteTransaction($id);
            if (!$ok) throw new Exception('Cannot delete — only manual transactions can be deleted');
            echo json_encode(['ok' => true, 'message' => 'Transaction deleted']);
            break;

        // ── Recategorize (change account) ─────────────────────────────────────
        case 'recategorize':
            if (!$canEdit) throw new Exception('Permission denied', 403);
            $id        = (int)($input['id']        ?? 0);
            $accountId = (int)($input['account_id'] ?? 0);
            if (!$id || !$accountId) throw new Exception('Missing id or account_id');

            $svc->updateTransaction($id, [
                'account_id'         => $accountId,
                'is_auto_categorized' => 0,  // manually set — override any auto-cat
            ]);
            echo json_encode(['ok' => true, 'message' => 'Transaction recategorized']);
            break;

        // ── Flag / unflag for review ──────────────────────────────────────────
        case 'flag_review':
            if (!$canEdit) throw new Exception('Permission denied', 403);
            $id   = (int)($input['id']   ?? 0);
            $flag = (int)($input['flag'] ?? 1);
            if (!$id) throw new Exception('Missing id');
            $svc->updateTransaction($id, ['needs_review' => $flag]);
            echo json_encode(['ok' => true, 'flagged' => (bool)$flag]);
            break;

        default:
            throw new Exception("Unknown action: $action", 400);
    }

} catch (Throwable $e) {
    $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
