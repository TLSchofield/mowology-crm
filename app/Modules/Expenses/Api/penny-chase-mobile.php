<?php
/**
 * Penny's missing-receipt chaser for the iOS app — JWT-authenticated. The phone's counterpart of
 * the session API penny-chase.php: the SAME MissingReceiptService calls (migration 1245), nothing
 * re-implemented here.
 *
 * Crew (anyone signed in — only their own items; admins/managers may answer any):
 *   GET  /api/expenses/penny-chase-mobile?mode=mine          → {ok, ready, name, count, total, items:[{id, ask, amount, date, time, vendor, basis_note}]}
 *   GET  ?mode=recent&id=N                                   → {ok, receipts:[{id, date, vendor, total, same_amount}]}   "It's already in"
 *   POST {mode: 'attach', id, expense_id}                    → that receipt answers the charge
 *   POST {mode: 'no_receipt', id, reason: lost|not_available, note}
 * Owner (expenses.approve):
 *   GET  ?mode=admin                                         → {ok, totals, summary, items (everyone's open), no_receipt[], people[], reasons}
 *   POST {mode: 'reassign', id, user_id}
 *
 * Rules, cards and the manual scan stay on the web (penny-chase.php). No CSRF: there is no
 * session — the user array is built from the JWT (id, role, name). ?mode=, never ?action=.
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
header('Cache-Control: no-store');

try {
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Modules/Expenses/Services/PennyQuestionService.php';
    require_once APP_ROOT . '/Modules/Expenses/Services/MissingReceiptService.php';

    $jwtUser = requireJwt();
    // MissingReceiptService reads id + role (isManager); firstName() reads full_name.
    $user = ['id' => (int)$jwtUser['id'], 'role' => $jwtUser['role'], 'full_name' => $jwtUser['name'], 'name' => $jwtUser['name']];
    $isOwner = jwtUserHasPermission($jwtUser, 'expenses.approve');

    $method = $_SERVER['REQUEST_METHOD'];
    $input  = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?? []) : [];
    $mode   = $method === 'POST' ? (string)($input['mode'] ?? '') : (string)($_GET['mode'] ?? 'mine');

    $db  = getDB();
    $svc = new MissingReceiptService($db);
    if (!$svc->ready()) {
        echo json_encode(['ok' => true, 'ready' => false, 'count' => 0, 'total' => 0, 'items' => [], 'message' => 'Needs migration 1245']);
        exit;
    }
    $uid = (int)$user['id'];
    $owner = function () use ($isOwner): void {
        if (!$isOwner) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Permission denied: expenses.approve required']);
            exit;
        }
    };
    $shape = function (array $i, string $first): array {
        return ['id' => (int)$i['id'], 'ask' => MissingReceiptService::ask($i, $first), 'amount' => round((float)$i['amount'], 2),
                'date' => (string)$i['charge_date'], 'time' => $i['charge_time'] ?? null, 'vendor' => (string)$i['vendor_label'],
                'basis_note' => (string)($i['basis_note'] ?? ''), 'who' => (string)($i['full_name'] ?? ''),
                'user_id' => (int)($i['user_id'] ?? 0), 'nudges' => (int)($i['nudges'] ?? 0)];
    };

    switch ($mode) {
        case 'mine': {
            $first = PennyQuestionService::firstName($user);
            $items = array_map(fn($i) => $shape($i, $first), $svc->openFor($uid));
            $t = $svc->totals($uid);
            echo json_encode(['ok' => true, 'ready' => true, 'name' => $first, 'count' => $t['open'], 'total' => $t['open_amount'], 'items' => $items]);
            break;
        }

        case 'recent':
            echo json_encode(['ok' => true, 'receipts' => $svc->recentReceipts((int)($_GET['id'] ?? 0), $user)]);
            break;

        case 'attach':
            if ($method !== 'POST') throw new RuntimeException('POST required');
            echo json_encode($svc->attach((int)($input['id'] ?? 0), (int)($input['expense_id'] ?? 0), $user));
            break;

        case 'no_receipt':
            if ($method !== 'POST') throw new RuntimeException('POST required');
            echo json_encode($svc->noReceipt((int)($input['id'] ?? 0), (string)($input['reason'] ?? ''), (string)($input['note'] ?? ''), $user));
            break;

        case 'admin': {
            $owner();
            $items = array_map(fn($i) => $shape($i, PennyQuestionService::firstName(['full_name' => (string)($i['full_name'] ?? '')])), $svc->openFor(null, 60));
            echo json_encode([
                'ok' => true, 'ready' => true, 'totals' => $svc->totals(), 'items' => $items,
                'summary' => MissingReceiptService::summaryText(...array_values($svc->totals())),
                'no_receipt' => $svc->noReceiptList(), 'people' => $svc->people(),
                'reasons' => MissingReceiptService::REASONS,
            ]);
            break;
        }

        case 'reassign':
            if ($method !== 'POST') throw new RuntimeException('POST required');
            $owner();
            echo json_encode($svc->reassign((int)($input['id'] ?? 0), (int)($input['user_id'] ?? 0), $uid));
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('[penny-chase-mobile] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Penny hit a snag — try again in a minute']);
}
