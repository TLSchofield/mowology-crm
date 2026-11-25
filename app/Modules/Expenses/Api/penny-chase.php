<?php
/**
 * Penny's missing-receipt chaser — session API for the crew app card and Tim's dashboard strip.
 * Thin controller: every rule lives in MissingReceiptService (migration 1245).
 *
 * Crew (any logged-in user — only their own items; managers may answer any):
 *   GET  ?mode=mine                       → {ok, name, count, total, items:[{id, ask, amount, date, time, vendor, basis_note}]}
 *   GET  ?mode=recent&id=N                → {ok, receipts:[{id, date, vendor, total, same_amount}]}  "It's already in"
 *   POST {mode:'attach', id, expense_id}  → the receipt answers the charge (Snap it / It's already in)
 *   POST {mode:'no_receipt', id, reason: lost|not_available, note}
 * Owner (expenses.approve):
 *   GET  ?mode=admin                      → totals, open items (everyone's), "no receipt" list, rules, cards, people
 *   POST {mode:'rule_save', match_on, pattern, label} | {mode:'rule_delete', id}
 *   POST {mode:'card_save', last4, user_id, label}    | {mode:'card_delete', last4}
 *   POST {mode:'reassign', id, user_id}
 *   POST {mode:'scan'}                    → run the scan now (no pushes)
 *
 * POSTs need csrf_token in the body. The crew pages are NOT AppStack (no window.MW_CSRF_TOKEN):
 * the client fetches /crm/api/get-csrf.php and retries once on 403 (crew-team.js). CSRF is checked
 * BEFORE session_write_close (Known-Failure-Patterns: the session is empty after the close).
 * ?mode=, not ?action=.
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
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Modules/Expenses/Services/PennyQuestionService.php';
    require_once APP_ROOT . '/Modules/Expenses/Services/MissingReceiptService.php';

    requireLogin();
    $user = getCurrentUser();
    $method = $_SERVER['REQUEST_METHOD'];
    $input = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?? []) : [];
    $mode = $method === 'POST' ? (string)($input['mode'] ?? '') : (string)($_GET['mode'] ?? 'mine');

    if ($method === 'POST' && !verifyCSRFToken((string)($input['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF_INVALID']);
        exit;
    }
    $isOwner = userHasPermission('expenses.approve');
    session_write_close();

    $db = getDB();
    $svc = new MissingReceiptService($db);
    if (!$svc->ready()) {
        echo json_encode(['ok' => true, 'ready' => false, 'count' => 0, 'items' => [], 'message' => 'Needs migration 1245']);
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
                'basis_note' => (string)($i['basis_note'] ?? ''), 'who' => (string)($i['full_name'] ?? ''), 'nudges' => (int)($i['nudges'] ?? 0)];
    };

    switch ($mode) {
        case 'mine': {
            $first = PennyQuestionService::firstName((array)$user);
            $items = array_map(fn($i) => $shape($i, $first), $svc->openFor($uid));
            $one = null;
            if (!empty($_GET['id'])) {   // a push tap for an item that is no longer in "mine" (answered, reassigned)
                $it = $svc->item((int)$_GET['id']);
                if ($it && ((int)$it['user_id'] === $uid || MissingReceiptService::isManager((array)$user))) {
                    $one = ['id' => (int)$it['id'], 'status' => (string)$it['status']];
                }
            }
            $t = $svc->totals($uid);
            echo json_encode(['ok' => true, 'ready' => true, 'name' => $first, 'count' => $t['open'], 'total' => $t['open_amount'],
                              'items' => $items, 'focus' => $one]);
            break;
        }

        case 'recent':
            echo json_encode(['ok' => true, 'receipts' => $svc->recentReceipts((int)($_GET['id'] ?? 0), (array)$user)]);
            break;

        case 'attach':
            echo json_encode($svc->attach((int)($input['id'] ?? 0), (int)($input['expense_id'] ?? 0), (array)$user));
            break;

        case 'no_receipt':
            echo json_encode($svc->noReceipt((int)($input['id'] ?? 0), (string)($input['reason'] ?? ''), (string)($input['note'] ?? ''), (array)$user));
            break;

        case 'admin': {
            $owner();
            $items = array_map(fn($i) => $shape($i, PennyQuestionService::firstName(['full_name' => (string)($i['full_name'] ?? '')])), $svc->openFor(null, 60));
            echo json_encode([
                'ok' => true, 'totals' => $svc->totals(), 'items' => $items,
                'summary' => MissingReceiptService::summaryText(...array_values($svc->totals())),
                'no_receipt' => $svc->noReceiptList(), 'rules' => $svc->rules(false), 'cards' => $svc->cards(), 'people' => $svc->people(),
                'reasons' => MissingReceiptService::REASONS,
            ]);
            break;
        }

        case 'rule_save':
            $owner();
            echo json_encode($svc->saveRule((string)($input['match_on'] ?? ''), (string)($input['pattern'] ?? ''), (string)($input['label'] ?? ''), $uid));
            break;

        case 'rule_delete':
            $owner();
            echo json_encode($svc->deleteRule((int)($input['id'] ?? 0)));
            break;

        case 'card_save':
            $owner();
            echo json_encode($svc->saveCard((string)($input['last4'] ?? ''), (int)($input['user_id'] ?? 0) ?: null, (string)($input['label'] ?? ''), $uid));
            break;

        case 'card_delete':
            $owner();
            echo json_encode($svc->deleteCard((string)($input['last4'] ?? '')));
            break;

        case 'reassign':
            $owner();
            echo json_encode($svc->reassign((int)($input['id'] ?? 0), (int)($input['user_id'] ?? 0), $uid));
            break;

        case 'scan':
            $owner();
            echo json_encode(['ok' => true, 'scan' => $svc->scan()]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('penny-chase.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Penny hit a snag — try again in a minute']);
}
