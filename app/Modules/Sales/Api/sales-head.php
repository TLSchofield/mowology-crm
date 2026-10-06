<?php
/**
 * Sam, the sales head — dashboard card endpoint.
 *
 * GET  ?mode=desk          Numbers, the follow-up queue (one card per customer, each with
 *                          Sam's suggested email + text), ranked new leads, open questions.
 * POST {mode: 'send', card_key, channel: email|sms, subject, body, suggested_subject,
 *       suggested_body, template, drafted_by, csrf_token}
 *                          Tim sends the follow-up (CRM messaging functions only).
 * POST {mode: 'park', card_key, how: skip|snooze, days?, csrf_token}
 * POST {mode: 'draft_reply', card_key, csrf_token}   Claude drafts a reply to the customer's
 *                          last email — Tim's click only, capped per day.
 * POST {mode: 'answer', question_id, answer: lost|keep|won, csrf_token}
 * POST {mode: 'lead_dismiss', lead_id, csrf_token}    "Not a lead" (spam, out of area…)
 *
 * The card is always re-read on the server (card_key → SalesDeskService::queue()), so the
 * customer's address and quotes come from the CRM, never from the browser.
 * ?mode=, not ?action= (see the /api/ router note in the vault). Permission: billing.edit.
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
    require_once CRM_INCLUDES . '/messaging.php';
    requireLogin();
    requirePermission('billing.edit');
    $user = getCurrentUser();

    $method = $_SERVER['REQUEST_METHOD'];
    $input  = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
    $mode   = $method === 'POST' ? ($input['mode'] ?? '') : ($_GET['mode'] ?? 'desk');

    if ($method === 'POST' && !verifyCSRFToken($input['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $db = getDB();
    session_write_close();

    require_once APP_ROOT . '/Modules/Sales/Services/SalesDeskService.php';
    require_once APP_ROOT . '/Modules/Sales/Services/SamFollowupService.php';
    require_once APP_ROOT . '/Modules/Sales/Services/SamQuestionService.php';
    require_once APP_ROOT . '/Modules/Sales/Services/TextBridgeService.php';
    $desk = new SalesDeskService($db);
    if (!$desk->ready()) {
        echo json_encode(['ok' => false, 'error' => 'Migration 1140 has not run yet']);
        exit;
    }
    $fu = new SamFollowupService($db);
    $sq = new SamQuestionService($db);
    $name = SalesDeskService::ownerName((array)$user);

    $findCard = function (string $key) use ($desk): ?array {
        foreach ($desk->queue() as $c) if ($c['key'] === $key) return $c;
        return null;
    };

    switch ($mode) {
        case 'desk': {
            $sq->scan();
            $cards = array_slice($desk->queue(), 0, 25);
            foreach ($cards as &$c) {
                $c['draft'] = $fu->draft($c, $name);
                $c['sms_ok'] = $c['phone'] !== '' && $c['contact_id'] && function_exists('hasSmConsent') && hasSmConsent((int)$c['contact_id']);
            }
            unset($c);
            echo json_encode([
                'ok'        => true,
                'name'      => $name,
                'stats'     => $desk->stats(),
                'queue'     => $cards,
                'leads'     => $desk->leads(8),
                'questions' => $sq->open($name),
                'inbox'     => $desk->hasTable('sales_messages'),
                'texts'     => (new TextBridgeService($db))->status(),   // messages bridge heartbeat, null = not set up
            ]);
            break;
        }

        case 'send': {
            $card = $findCard((string)($input['card_key'] ?? ''));
            if (!$card) { echo json_encode(['ok' => false, 'message' => 'That customer is no longer waiting — refresh.']); break; }
            echo json_encode($fu->send($card, $input, (array)$user, $name));
            break;
        }

        case 'park': {
            $card = $findCard((string)($input['card_key'] ?? ''));
            if (!$card) { echo json_encode(['ok' => false, 'message' => 'That customer is no longer waiting — refresh.']); break; }
            $how = ($input['how'] ?? '') === 'skip' ? 'skip' : 'snooze';
            echo json_encode($fu->park($card, $how, (array)$user, (int)($input['days'] ?? SamFollowupService::SNOOZE_DAYS)));
            break;
        }

        case 'draft_reply': {
            $card = $findCard((string)($input['card_key'] ?? ''));
            if (!$card) { echo json_encode(['ok' => false, 'message' => 'That customer is no longer waiting — refresh.']); break; }
            echo json_encode($fu->draftReply($card, $name, (int)$user['id']));
            break;
        }

        case 'answer': {
            echo json_encode($sq->answer((int)($input['question_id'] ?? 0), (string)($input['answer'] ?? ''), (int)$user['id'], $name));
            break;
        }

        case 'lead_dismiss': {
            $ok = $desk->dismissLead((int)($input['lead_id'] ?? 0));
            echo json_encode(['ok' => $ok, 'message' => $ok ? "Got it — off the list." : 'Already handled.']);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('[sales-head] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Sam hit a snag — try again.']);
}
