<?php
/**
 * iOS Receipt Actions API — JWT-authenticated
 *
 * POST {action: 'approve'|'reject'|'send', expense_id: int, rejection_reason?: string}
 * POST {action: 'batch_approve'|'batch_reject', expense_ids: int[], rejection_reason?: string}
 * POST {action: 'archive_export', date_from?: 'YYYY-MM-DD', date_to?: 'YYYY-MM-DD'}
 *
 * Auth: Authorization: Bearer <jwt>  (no CSRF token needed)
 *
 * The mobile-facing counterpart to the session-authenticated approve/reject/send actions
 * on expenses.php and receipt-send.php — same business logic (ExpenseApprovalService,
 * sendReceiptToAccounting()), same self-approval + forwarded-guard checks, just reachable
 * from a JWT client with no PHP session. This is what lets the iOS receipts feature move
 * a receipt past 'draft' — previously the only JWT endpoints were capture/save.
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
    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseApprovalService.php';
    require_once APP_ROOT . '/Services/Receipts/ReceiptService.php';

    $jwtUser = requireJwt();
    $currentUser = ['id' => (int)$jwtUser['id']];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    $expenseId = (int)($input['expense_id'] ?? 0);

    $db = getDB();

    switch ($action) {
        case 'approve':
            if (!jwtUserHasPermission($jwtUser, 'expenses.approve')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied: expenses.approve required']);
                exit;
            }
            $result = (new ExpenseApprovalService($db))->approve($expenseId, $currentUser);
            echo json_encode($result);
            break;

        case 'reject':
            if (!jwtUserHasPermission($jwtUser, 'expenses.approve')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied: expenses.approve required']);
                exit;
            }
            $result = (new ExpenseApprovalService($db))->reject(
                $expenseId,
                $currentUser,
                (string)($input['rejection_reason'] ?? '')
            );
            echo json_encode($result);
            break;

        case 'batch_approve':
            if (!jwtUserHasPermission($jwtUser, 'expenses.approve')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied: expenses.approve required']);
                exit;
            }
            $result = (new ExpenseApprovalService($db))->approveBatch($input['expense_ids'] ?? [], $currentUser);
            echo json_encode($result);
            break;

        case 'batch_reject':
            if (!jwtUserHasPermission($jwtUser, 'expenses.approve')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied: expenses.approve required']);
                exit;
            }
            $result = (new ExpenseApprovalService($db))->rejectBatch(
                $input['expense_ids'] ?? [],
                $currentUser,
                (string)($input['rejection_reason'] ?? '')
            );
            echo json_encode($result);
            break;

        case 'archive_export':
            // Same expenses.send gate as the desktop trigger (public/crm/api/receipt-export.php)
            // — CRA-retention archival is a deliberate, permissioned action, not a routine one.
            if (!jwtUserHasPermission($jwtUser, 'expenses.send')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied: expenses.send required']);
                exit;
            }
            require_once APP_ROOT . '/Modules/Expenses/Services/ReceiptArchiveService.php';

            $validDate = static function ($d): ?string {
                $d = trim((string) $d);
                if ($d === '') return null;
                $dt = DateTime::createFromFormat('Y-m-d', $d);
                return ($dt && $dt->format('Y-m-d') === $d) ? $d : null;
            };
            $from = $validDate($input['date_from'] ?? '');
            $to   = $validDate($input['date_to'] ?? '');

            // Archival + email delivery can be slow on shared hosting.
            @set_time_limit(0);
            ignore_user_abort(true);

            $svc    = new ReceiptArchiveService($db);
            $result = $svc->run($from, $to, 'manual', $currentUser['id'], 0);
            echo json_encode(['success' => true, 'result' => $result]);
            break;

        case 'send':
            if (!jwtUserHasPermission($jwtUser, 'expenses.send')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied: expenses.send required']);
                exit;
            }
            if (!$expenseId) {
                throw new Exception('Expense ID is required');
            }

            $stmt = $db->prepare("
                SELECT e.*, v.name AS vendor_name,
                       c.first_name AS client_first, c.last_name AS client_last,
                       p.address AS property_address
                FROM expenses e
                LEFT JOIN vendors v ON v.id = e.vendor_id
                LEFT JOIN contacts c ON c.id = e.contact_id
                LEFT JOIN properties p ON p.id = e.property_id
                WHERE e.id = ?
            ");
            $stmt->execute([$expenseId]);
            $expense = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$expense) {
                throw new Exception('Expense not found');
            }

            // Idempotency: block duplicate forwards (same guard as receipt-send.php)
            if (!empty($expense['forwarded_to_accounting'])) {
                $forwardedAt = $expense['forwarded_at'] ? date('M j, Y g:ia', strtotime($expense['forwarded_at'])) : 'previously';
                echo json_encode([
                    'success' => false,
                    'error'   => 'This receipt was already forwarded to QuickBooks on ' . $forwardedAt . '.',
                    'log_id'  => null,
                ]);
                exit;
            }

            $clientName = trim(($expense['client_first'] ?? '') . ' ' . ($expense['client_last'] ?? ''));
            if (!empty($expense['property_address'])) {
                $clientName .= $clientName ? ' - ' . $expense['property_address'] : $expense['property_address'];
            }

            $mediaId = $expense['receipt_media_id'] ? (int)$expense['receipt_media_id'] : null;
            if (empty($mediaId)) {
                throw new Exception('No receipt image attached to this expense');
            }

            $opts = [
                'expense_id'          => $expenseId,
                'media_id'            => $mediaId,
                'vendor'              => $expense['vendor_name'] ?? $expense['vendor_name_raw'] ?? 'Unknown',
                'subtotal'            => (string)($expense['amount'] ?? '0.00'),
                'gst_amount'          => (string)($expense['gst_amount'] ?? '0.00'),
                'total'               => (string)$expense['total'],
                'date'                => $expense['expense_date'],
                'job_id'              => $expense['job_id'],
                'client_name'         => $clientName,
                'description'         => $expense['description'] ?? '',
                'accounting_category' => $expense['accounting_category'] ?? '',
            ];

            $result = sendReceiptToAccounting($opts, $currentUser['id']);
            echo json_encode($result);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action: ' . htmlspecialchars((string)$action)]);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Receipt action failed: ' . $e->getMessage()]);
}
