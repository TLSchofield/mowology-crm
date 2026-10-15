<?php
/**
 * Sam's desk for the iOS app — JWT-authenticated. The mobile counterpart of sales-head.php.
 *
 * GET  /api/sales/sales-head-mobile?mode=desk
 *      → {ok, name, stats, queue[] (each with draft + sms_ok), leads[], questions[],
 *         unclaimed[], inbox, texts}
 * GET  ?mode=thread&contact_id=N      → {ok, messages[]}  (read-only)
 * POST {mode: 'send', card_key | reply_key, channel: email|sms, subject, body,
 *       suggested_subject, suggested_body, template, drafted_by}
 * POST {mode: 'park', card_key, how: skip|snooze, days?}
 * POST {mode: 'draft_reply', card_key | reply_key}   Claude, Tim's tap only, daily-capped
 * POST {mode: 'handled', key}                         a waiting reply → Charlie's act/dismiss
 * POST {mode: 'answer', question_id, answer: lost|keep|won}
 * POST {mode: 'accept_signed' | 'not_acceptance', id}   a "Looks like an acceptance" item (SamAcceptanceService)
 * POST {mode: 'send_quote', quote_id}                 a draft quote → its client (QuoteSendService, as the web page's Send)
 * POST {mode: 'move', key | card_key, to: penny|sam|otto|mia|yui}
 *                                                     "Move to…" (admins): InboundRouteService::move —
 *                                                     re-routes the message and learns sender + topic.
 *
 * Auth: Authorization: Bearer <jwt>; admin/manager role or the billing.edit permission
 * (no CSRF token: there is no session). Nothing sends without Tim's tap.
 *
 * The SAME service calls as sales-head.php — nothing is re-implemented here:
 *   SalesDeskService::queue/stats/leads/unclaimed, SamFollowupService::draft/send/park/
 *   draftReply, SamQuestionService::scan/open/answer, TextBridgeService::status.
 * Differences from the web:
 *   - the user array is built from the JWT (id, role, full_name) — the services read only
 *     'id' and the name Sam calls the owner;
 *   - reply_key: "Draft reply" / "Send" on a Replies-waiting item. Those replies have no
 *     follow-up card, so SamReplyCard builds one from the CRM (contact + thread) and the
 *     same draftReply()/send() run on it (email only, from office@);
 *   - "Handled" calls CharlieDeskService::act(key, 'dismiss') in-process, exactly what the
 *     web's POST /crm/api/charlie.php {mode: act} does, including its one retry after
 *     Charlie's list is read (CharlieForemanService::card()). Owner only, as on the web;
 *   - Ask-first notes (asks / ask_*) and "Not a lead" are not offered in the app yet.
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
    require_once CRM_INCLUDES . '/messaging.php';

    $jwtUser = requireJwt();
    if (!jwtIsAdmin($jwtUser['role']) && !jwtUserHasPermission($jwtUser, 'billing.edit')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Permission denied: billing.edit required']);
        exit;
    }
    // The services read 'id' (decided_by, activity log) and the name (SalesDeskService::ownerName).
    $user = ['id' => (int)$jwtUser['id'], 'role' => $jwtUser['role'], 'full_name' => $jwtUser['name'], 'name' => $jwtUser['name']];

    $method = $_SERVER['REQUEST_METHOD'];
    $input  = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?? []) : [];
    $mode   = $method === 'POST' ? (string)($input['mode'] ?? '') : (string)($_GET['mode'] ?? 'desk');

    $db = getDB();

    require_once APP_ROOT . '/Modules/Sales/Services/SalesDeskService.php';
    require_once APP_ROOT . '/Modules/Sales/Services/SamFollowupService.php';
    require_once APP_ROOT . '/Modules/Sales/Services/SamQuestionService.php';
    require_once APP_ROOT . '/Modules/Sales/Services/TextBridgeService.php';
    require_once APP_ROOT . '/Modules/Sales/Services/SamReplyCard.php';
    $desk = new SalesDeskService($db);
    if (!$desk->ready()) {
        echo json_encode(['ok' => false, 'error' => 'Migration 1140 has not run yet']);
        exit;
    }
    $fu = new SamFollowupService($db);
    $sq = new SamQuestionService($db);
    require_once APP_ROOT . '/Modules/Sales/Services/QuoteApprovalService.php';
    $approvals = new QuoteApprovalService($db);
    $name = SalesDeskService::ownerName($user);
    $gone = ['ok' => false, 'message' => 'That customer is no longer waiting — pull to refresh.'];

    $findCard = function (string $key) use ($desk): ?array {
        foreach ($desk->queue() as $c) if ($c['key'] === $key) return $c;
        return null;
    };
    // A Replies-waiting item, re-read on the server (never trusted from the app).
    $findReplyCard = function (string $key) use ($db, $desk): ?array {
        $reply = SamReplyCard::find($desk->unclaimed(), $key);
        if (!$reply) return null;
        $card = SamReplyCard::load($db, $desk, $reply);
        return $card ? ['card' => $card, 'reply' => $reply] : null;
    };

    // Draft quotes, sent from the phone through the same QuoteSendService as the web page's Send (2026-10-15).
    $toSend = static function () use ($db): array {
        try {
            require_once APP_ROOT . '/Modules/Quotes/Services/QuoteSendService.php';
            return (new QuoteSendService($db))->drafts(20);
        } catch (Throwable $e) {
            error_log('[sales-head-mobile] drafts: ' . $e->getMessage());
            return [];
        }
    };

    // A client email that looks like it accepts quotes, each quote with its signed copy (a short-lived signed link
    // the phone can open in Safari — InboundAttachmentService::appUrl).
    $acceptances = static function () use ($db): array {
        try {
            require_once APP_ROOT . '/Modules/Sales/Services/SamAcceptanceService.php';
            require_once APP_ROOT . '/Modules/Comms/Services/InboundAttachmentService.php';
            $groups = (new SamAcceptanceService($db))->open(10);
            $exp = time() + 3600;
            foreach ($groups as &$g) foreach ($g['quotes'] as &$q) {
                if ($q['file']) $q['file']['url'] = InboundAttachmentService::appUrl($q['file']['id'], $exp, jwtSecret());
            }
            unset($g, $q);
            return $groups;
        } catch (Throwable $e) {
            error_log('[sales-head-mobile] acceptances: ' . $e->getMessage());
            return [];
        }
    };

    switch ($mode) {
        case 'accept_signed':
        case 'not_acceptance': {
            if ($method !== 'POST') throw new RuntimeException('POST required');
            require_once APP_ROOT . '/Modules/Sales/Services/SamAcceptanceService.php';
            $acc = new SamAcceptanceService($db);
            $res = $mode === 'accept_signed' ? $acc->accept((int)($input['id'] ?? 0), $user) : $acc->dismiss((int)($input['id'] ?? 0), $user);
            echo json_encode(['ok' => $res['ok'], 'message' => $res['message'], 'error' => $res['ok'] ? null : $res['message']]);
            break;
        }

        case 'send_quote': {
            if ($method !== 'POST') throw new RuntimeException('POST required');
            $quoteId = (int)($input['quote_id'] ?? 0);
            // From the phone: drafts only (a re-send stays a deliberate act on the quote page); one send at a time
            // per quote is QuoteSendService's own lock.
            require_once APP_ROOT . '/Modules/Quotes/Services/QuoteSendService.php';
            $res = (new QuoteSendService($db))->send($quoteId, $user, true);
            echo json_encode(['ok' => $res['ok'], 'message' => $res['message'], 'error' => $res['ok'] ? null : $res['message'],
                              'status' => $res['status'], 'sent_via' => $res['sent_via']]);
            break;
        }

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
                'approved'  => $approvals->pending(),   // just-approved quotes, every line ✓ / ✗ (migration 1316)
                'inbox'     => $desk->hasTable('sales_messages'),
                'texts'     => (new TextBridgeService($db))->status(),
                'unclaimed' => array_slice($desk->unclaimed($all), 0, 12),
                'to_send'   => $toSend(),                    // draft quotes waiting to go out (2026-10-15)
                'acceptances' => $acceptances(),              // "Looks like an acceptance" — signed quotes by email (2026-10-15)
            ]);
            break;
        }

        case 'approval_seen': {
            // "Got it" on a Just approved item.
            echo json_encode(['ok' => $approvals->acknowledge((int)($input['quote_id'] ?? 0), (int)$user['id'])]);
            break;
        }

        case 'thread': {
            // Read-only, the same query as sales-head.php.
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

        case 'send': {
            if ($method !== 'POST') throw new RuntimeException('POST required');
            if (!empty($input['reply_key'])) {
                $r = $findReplyCard((string)$input['reply_key']);
                if (!$r) { echo json_encode($gone); break; }
                $input['channel'] = 'email';            // replies are answered by email from office@
                $input['template'] = 'reply';
                echo json_encode($fu->send($r['card'], $input, $user, $name));
                break;
            }
            $card = $findCard((string)($input['card_key'] ?? ''));
            // Tim wrote it — it goes, even when the card went stale while he typed (its quotes accepted): to the same
            // person, without quote links (2026-10-15).
            if (!$card && trim((string)($input['body'] ?? '')) !== '') $card = $desk->cardForKey((string)($input['card_key'] ?? ''));
            if (!$card) { echo json_encode($gone); break; }
            $res = $fu->send($card, $input, $user, $name);
            if (!empty($card['gone']) && !empty($res['ok'])) $res['message'] = 'Sent to ' . $card['name'] . " — their quotes had changed since the card opened, so it went without quote links.";
            echo json_encode($res);
            break;
        }

        case 'park': {
            if ($method !== 'POST') throw new RuntimeException('POST required');
            $card = $findCard((string)($input['card_key'] ?? ''));
            if (!$card) { echo json_encode($gone); break; }
            $how = ($input['how'] ?? '') === 'skip' ? 'skip' : 'snooze';
            echo json_encode($fu->park($card, $how, $user, (int)($input['days'] ?? SamFollowupService::SNOOZE_DAYS)));
            break;
        }

        case 'draft_reply': {
            if ($method !== 'POST') throw new RuntimeException('POST required');
            if (!empty($input['reply_key'])) {
                $r = $findReplyCard((string)$input['reply_key']);
                if (!$r) { echo json_encode($gone); break; }
                if ($r['card']['email'] === '') { echo json_encode(['ok' => false, 'message' => 'No email address on file for ' . $r['card']['name'] . '.']); break; }
                $res = $fu->draftReply($r['card'], $name, (int)$user['id']);
                if (!empty($res['ok'])) $res['subject'] = SamReplyCard::subject($r['reply']);
                echo json_encode($res);
                break;
            }
            $card = $findCard((string)($input['card_key'] ?? ''));
            if (!$card) { echo json_encode($gone); break; }
            echo json_encode($fu->draftReply($card, $name, (int)$user['id']));
            break;
        }

        case 'handled': {
            if ($method !== 'POST') throw new RuntimeException('POST required');
            $key = substr((string)($input['key'] ?? ''), 0, 120);
            if (strpos($key, 'sam:reply:') !== 0) { echo json_encode(['ok' => false, 'message' => 'That isn\'t one of Sam\'s replies.']); break; }
            $charlie = APP_ROOT . '/Modules/ChiefOfStaff/Services/CharlieForemanService.php';
            if (!is_file($charlie)) { echo json_encode(['ok' => false, 'message' => 'Charlie isn\'t set up on this server.']); break; }
            if (!function_exists('getWorkQueueItems')) require_once APP_ROOT . '/Services/CrmFunctions.php';
            require_once $charlie;
            $f = new CharlieForemanService($db);
            if (!$f->desk->ready()) { echo json_encode(['ok' => false, 'message' => 'Migration 1170 has not run']); break; }
            if (!$f->desk->isOwner($user)) { echo json_encode(['ok' => false, 'message' => 'Only the owner can mark replies handled.']); break; }
            $res = $f->desk->act($key, 'dismiss');
            if (empty($res['ok'])) {
                // Charlie only knows an item once his list has been read today (as the web card retries).
                $f->card(CharlieVoice::firstName($user));
                $res = $f->desk->act($key, 'dismiss');
            }
            echo json_encode($res);
            break;
        }

        case 'move': {
            // "Move to…" on a waiting reply (key sam:reply:…) or a "they replied" card (card_key c<id>):
            // the message goes to Penny / Otto / Mia / Yui and the sender + topic is learned (InboundRouteService).
            if ($method !== 'POST') throw new RuntimeException('POST required');
            if (!jwtIsAdmin($jwtUser['role'])) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Admins only']); break; }
            require_once APP_ROOT . '/Modules/Comms/Services/InboundRouteService.php';
            $key = !empty($input['card_key']) ? 'sam:contact:' . substr((string)$input['card_key'], 0, 40) : substr((string)($input['key'] ?? ''), 0, 120);
            echo json_encode((new InboundRouteService($db))->move(['key' => $key], (string)($input['to'] ?? ''), (int)$user['id']));
            break;
        }

        case 'answer': {
            if ($method !== 'POST') throw new RuntimeException('POST required');
            echo json_encode($sq->answer((int)($input['question_id'] ?? 0), (string)($input['answer'] ?? ''), (int)$user['id'], $name));
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('[sales-head-mobile] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Sam hit a snag — try again.']);
}
