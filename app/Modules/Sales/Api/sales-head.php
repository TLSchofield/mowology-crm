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
 * POST {mode: 'maybe_accept'|'maybe_dismiss', candidate_id, csrf_token}   A "maybe a lead" from
 *                          email (EmailLeadService, Tim's iCloud): make it a real lead, or not.
 *                          The desk response carries `maybe_leads`.
 * POST {mode: 'test_push', csrf_token}   "Send me a test push": APNs configured? how many active
 *                          iOS tokens the current user has, and APNs' answer per token
 *                          (QuoteViewNotifier::testPush — the quote-opened push uses the same path).
 * GET  ?mode=thread&contact_id=N  The emails/texts Sam holds for one contact (read-only).
 * POST {mode: 'ask_build', observation_id, price?, csrf_token}   They said yes to an
 *                          Ask-first note → build the quote on that observation (not sent).
 * POST {mode: 'ask_send_quote', observation_id, csrf_token}      Send that quote to the
 *                          billing contact (refuses $0).
 * POST {mode: 'ask_close', observation_id, csrf_token}           "Not now".
 *                          The desk response carries `asks` (FieldAskService::forSam()).
 *                          and `unclaimed` (UnclaimedReplyService, 'quote' lane): replies about a quote
 *                          no other net caught (other client replies are Yui's — /crm/api/yui.php).
 *                          "Handled" is POST /crm/api/charlie.php {mode: act, key, what: dismiss}.
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
    require_once APP_ROOT . '/Modules/Products/Services/FieldAskService.php';
    $asks = new FieldAskService($db);
    $fu = new SamFollowupService($db);
    $sq = new SamQuestionService($db);
    $name = SalesDeskService::ownerName((array)$user);
    require_once APP_ROOT . '/Modules/Sales/Services/EmailLeadService.php';
    $emailLeads = new EmailLeadService($db);   // maybes() is empty until migration 1223

    $findCard = function (string $key) use ($desk): ?array {
        foreach ($desk->queue() as $c) if ($c['key'] === $key) return $c;
        return null;
    };

    switch ($mode) {
        case 'desk': {
            $sq->scan();
            $all = $desk->queue();
            $cards = array_slice($all, 0, 25);
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
                'asks'      => $asks->forSam(),                          // Ask-first notes: replied / no reply yet / crew drafts
                'unclaimed' => array_slice($desk->unclaimed($all), 0, 12), // replies about a quote nobody answered ("Handled" = Charlie dismiss)
                'maybe_leads' => $emailLeads->maybes(8),                // enquiries from email the rules weren't sure about
            ]);
            break;
        }

        case 'ask_build': {
            $price = isset($input['price']) && $input['price'] !== '' ? (float)$input['price'] : null;
            echo json_encode($asks->buildQuote((int)($input['observation_id'] ?? 0), (array)$user, $price));
            break;
        }

        case 'ask_send_quote': {
            echo json_encode($asks->sendQuote((int)($input['observation_id'] ?? 0), (array)$user));
            break;
        }

        case 'ask_close': {
            echo json_encode($asks->close((int)($input['observation_id'] ?? 0), (array)$user, 'Ask first: not now (Sam)'));
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

        case 'thread': {
            // Read-only: the conversation Sam holds for one contact (office@ email + texts).
            $cid = (int)($_GET['contact_id'] ?? 0);
            if ($cid <= 0) { echo json_encode(['ok' => false, 'error' => 'contact_id required']); break; }
            $who = $desk->hasColumn('sales_messages', 'joined_by')
                ? ", CASE WHEN direction = 'inbound' AND joined_by IS NOT NULL THEN COALESCE(NULLIF(from_name, ''), from_addr) END AS sender"
                : '';   // migration 1314: someone other than the contact wrote it
            $s = $db->prepare("
                SELECT direction, channel, subject, snippet, sent_at{$who}
                FROM sales_messages WHERE contact_id = ?
                ORDER BY sent_at DESC, id DESC LIMIT 30
            ");
            $s->execute([$cid]);
            echo json_encode(['ok' => true, 'messages' => $s->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        case 'test_push': {
            require_once APP_ROOT . '/Modules/Sales/Services/QuoteViewNotifier.php';
            echo json_encode((new QuoteViewNotifier($db))->testPush((int)$user['id']));
            break;
        }

        case 'maybe_accept': {
            $qr = $emailLeads->accept((int)($input['candidate_id'] ?? 0), (int)$user['id']);
            echo json_encode(['ok' => $qr !== null, 'lead_id' => $qr,
                              'message' => $qr !== null ? 'Added to your leads.' : 'Already handled.']);
            break;
        }

        case 'maybe_dismiss': {
            $ok = $emailLeads->dismiss((int)($input['candidate_id'] ?? 0), (int)$user['id']);
            echo json_encode(['ok' => $ok, 'message' => $ok ? 'Got it — not a lead.' : 'Already handled.']);
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
