<?php
/**
 * Penny's desk for the iOS app — JWT-authenticated.
 *
 * GET  /api/expenses/bookkeeper-mobile?mode=queue[&limit=15]
 *      → {ok, dupes:[{pairs, members[]}], queue[], categories[], asset_tags[{value,label}]}
 * POST {mode: 'decide', suggestion_id, overrides?: {vendor, vendor_id, expense_date,
 *       accounting_category, asset_tag, job, subtotal, gst, pst, total}, save_draft?: bool}
 * POST {mode: 'reject', suggestion_id, reason}
 * POST {mode: 'not_dupe', pairs: [[a, b], ...]}
 *
 * Auth: Authorization: Bearer <jwt>, admin/manager role or the expenses.approve
 * permission (no CSRF token: there is no session). Skip is client-side, as on the web.
 *
 * The mobile counterpart of bookkeeper.php's dashboard-card modes: the SAME service
 * calls (BookkeeperDeskService::queue/decide, DuplicateReceiptService::dismiss,
 * ExpenseApprovalService::reject) — nothing is re-implemented here. Two differences:
 *   - photo links are re-signed as the app's /api/expenses/receipt-image links (the
 *     web's /crm/api/serve-receipt.php needs a browser session);
 *   - the select options (categories, "For" tags) travel with the queue, so the app
 *     offers exactly what the web card offers.
 * "Remove the copy" on a duplicate is the web's expenses.php merge (session-only) —
 * not offered here yet.
 *
 * ?mode=, never ?action= (the /api/ router appends its own action).
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

    $jwtUser = requireJwt();
    if (!jwtIsAdmin($jwtUser['role']) && !jwtUserHasPermission($jwtUser, 'expenses.approve')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Permission denied: expenses.approve required']);
        exit;
    }
    // The services read only 'id' (decided_by / dismissed_by / approved_by).
    $user = ['id' => (int)$jwtUser['id'], 'role' => $jwtUser['role'], 'full_name' => $jwtUser['name']];

    $method = $_SERVER['REQUEST_METHOD'];
    $input  = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?? []) : [];
    $mode   = $method === 'POST' ? (string)($input['mode'] ?? '') : (string)($_GET['mode'] ?? 'queue');

    $db = getDB();

    require_once APP_ROOT . '/Modules/Expenses/Services/ReceiptBookkeeperService.php';
    require_once APP_ROOT . '/Modules/Expenses/Services/BookkeeperDeskService.php';
    require_once APP_ROOT . '/Modules/Expenses/Services/DuplicateReceiptService.php';
    require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseApprovalService.php';
    require_once APP_ROOT . '/Modules/Expenses/Services/ReceiptImageLinks.php';

    $desk = new BookkeeperDeskService($db, new ReceiptBookkeeperService($db));
    if (!$desk->ready()) {
        throw new RuntimeException('Bookkeeper not set up (migration 1125)');
    }

    switch ($mode) {
        case 'queue': {
            // Possible duplicates are sorted first and never offered for approval (as on the web).
            $dupSvc = new DuplicateReceiptService($db);
            $pairs  = $dupSvc->pairsInLine(60);
            $queue  = $desk->queue((int)($_GET['limit'] ?? 15), DuplicateReceiptService::heldIds($pairs));
            [$queue, $dupes] = ReceiptImageLinks::resignDesk(
                $queue, DuplicateReceiptService::groups($pairs), time() + 21600, jwtSecret()
            );
            $tagLabels = ['truck' => 'Truck', 'equipment' => 'Equipment', 'stock' => 'Shop stock'];
            $tags = [['value' => 'none', 'label' => 'None']];
            foreach (ReceiptBookkeeperRules::TAGS as $t) {
                $tags[] = ['value' => $t, 'label' => $tagLabels[$t] ?? ucfirst($t)];
            }
            echo json_encode([
                'ok'         => true,
                'dupes'      => $dupes,
                'queue'      => $queue,
                'categories' => array_values(EXPENSE_ACCOUNTING_CATEGORIES),
                'asset_tags' => $tags,
            ]);
            break;
        }

        case 'decide': {
            if ($method !== 'POST') throw new RuntimeException('POST required');
            $overrides = is_array($input['overrides'] ?? null) ? $input['overrides'] : [];
            echo json_encode($desk->decide((int)($input['suggestion_id'] ?? 0), $overrides, $user, empty($input['save_draft'])));
            break;
        }

        case 'reject': {
            if ($method !== 'POST') throw new RuntimeException('POST required');
            $stmt = $db->prepare("
                SELECT s.expense_id FROM expense_suggestions s JOIN expenses e ON e.id = s.expense_id
                WHERE s.id = ? AND s.source = 'live' AND s.status = 'pending' AND e.status IN ('draft', 'pending_approval')
            ");
            $stmt->execute([(int)($input['suggestion_id'] ?? 0)]);
            $expenseId = (int)$stmt->fetchColumn();
            if (!$expenseId) {
                echo json_encode(['ok' => false, 'message' => 'This receipt has already been handled']);
                break;
            }
            try {
                $res = (new ExpenseApprovalService($db))->reject($expenseId, $user, (string)($input['reason'] ?? ''));
                echo json_encode(['ok' => !empty($res['success']), 'message' => $res['message'] ?? 'Rejected']);
            } catch (Exception $e) {
                echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
            }
            break;
        }

        case 'not_dupe': {
            if ($method !== 'POST') throw new RuntimeException('POST required');
            // One or many pairs: [[a, b], ...] — "none of these are duplicates" for a group.
            $dup = new DuplicateReceiptService($db);
            $res = ['ok' => false, 'message' => 'Nothing to save'];
            foreach ((array)($input['pairs'] ?? []) as $pr) {
                $res = $dup->dismiss((int)($pr[0] ?? 0), (int)($pr[1] ?? 0), $user);
                if (!$res['ok']) break;
            }
            echo json_encode($res);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('bookkeeper-mobile.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
