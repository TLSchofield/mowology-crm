<?php
/**
 * Mia — marketing & relationships head (dashboard card). Owner/marketing approvers only.
 *
 * GET  ?mode=stats       The card's numbers and headline.
 * GET  ?mode=queue       Waiting suggestions with Mia's drafted messages.
 * GET  ?mode=questions   Mia's open questions.
 * GET  ?mode=brain       What she has learned (for the brain).
 * GET  ?mode=brief       Read-only summary for Charlie.
 * POST {mode: 'prepare', force?, csrf_token}   Find people + draft (at most every few hours).
 * POST {mode: 'decide', id, action: 'send', subject, body, sms?: bool, sms_text?, csrf_token}
 *      Tim sends — consent and Sam's open quotes are checked again first.
 * POST {mode: 'decide', id, action: 'skip', reason: not_fit|talked|not_now|never, csrf_token}
 * POST {mode: 'answer', question_id, answer, csrf_token}
 * GET  ?mode=campaign    The campaign Mia proposes (list size, consent count, words, photo).
 * POST {mode: 'campaign_decide', id, action: 'approve'|'dismiss', subject?, body?, csrf_token}
 *      Approve = Tim's one tap: the campaign is created for everyone who passes consent now.
 *
 * Approval queue and websites (MiaApiHandler, Phase 1.12):
 * GET  ?mode=websites                 Connected websites (+ how to prove ownership while pending).
 * POST {mode: 'connect_website', address, csrf_token}
 * POST {mode: 'verify_website', id, method?: meta|file|dns|any, csrf_token}
 * POST {mode: 'disconnect_website', id, csrf_token}
 * GET  ?mode=actions&status=proposed|approved|rejected|expired|all    The queue, with draft wording.
 * GET  ?mode=action_detail&id=        One proposal with its evidence and audit trail.
 * POST {mode: 'draft_edit', id, title, meta_description, csrf_token}  The owner changes the wording.
 * POST {mode: 'action_decide', id, decision: approve|reject|later, reason_code?, reason_text?, seen_hash, csrf_token}
 *      (named action_decide because 'decide' above is the email suggestions). Records the decision only.
 *
 * ?mode=, not ?action= (the /api/ router rewrite appends its own `action`).
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
    requirePermission('marketing.approve');
    $user = getCurrentUser();

    $method = $_SERVER['REQUEST_METHOD'];
    $input  = $method === 'POST' ? (json_decode((string)file_get_contents('php://input', false, null, 0, 262144), true) ?? []) : [];
    if (!is_array($input)) { $input = []; }
    $mode   = $method === 'POST' ? ($input['mode'] ?? '') : ($_GET['mode'] ?? 'stats');

    if ($method === 'POST' && !verifyCSRFToken($input['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $db = getDB();
    session_write_close();

    // Approval queue and websites: their own readiness check (migrations 1400-1402), independent of the email desk below.
    require_once APP_ROOT . '/Modules/Marketing/Services/MiaApiHandler.php';
    if (MiaApiHandler::handles((string)$mode)) {
        $handler = new MiaApiHandler($db, (int)($user['id'] ?? 0));
        list($http, $body) = $handler->handle((string)$mode, $method, $_GET, $input);
        http_response_code($http);
        echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }

    require_once APP_ROOT . '/Modules/Marketing/Services/MiaDeskService.php';
    $desk = new MiaDeskService($db);
    if (!$desk->ready()) {
        echo json_encode(['ok' => false, 'error' => 'Migration 1160 has not been run yet.']);
        exit;
    }
    $name = MiaDeskService::firstName((array)$user);

    switch ($mode) {
        case 'stats':
            $st = $desk->stats();
            echo json_encode(['ok' => true, 'stats' => $st, 'headline' => MiaDeskService::headline($st, $name)]);
            break;

        case 'queue':
            echo json_encode(['ok' => true, 'items' => $desk->queue()]);
            break;

        case 'questions':
            require_once APP_ROOT . '/Modules/Marketing/Services/MiaQuestionService.php';
            echo json_encode(['ok' => true, 'questions' => (new MiaQuestionService($db))->open(5)]);
            break;

        case 'brain':
            require_once APP_ROOT . '/Modules/Marketing/Services/MiaBrainService.php';
            echo json_encode(['ok' => true, 'brain' => (new MiaBrainService($db))->learned()]);
            break;

        case 'brief':
            echo json_encode(['ok' => true, 'brief' => $desk->brief($name)]);
            break;

        case 'prepare':
            $r = $desk->prepare(!empty($input['force']));
            echo json_encode(['ok' => true] + $r);
            break;

        case 'decide':
            $r = $desk->decide((int)($input['id'] ?? 0), (string)($input['action'] ?? ''), $input, (array)$user);
            if (empty($r['ok'])) http_response_code(422);
            echo json_encode($r);
            break;

        case 'answer':
            require_once APP_ROOT . '/Modules/Marketing/Services/MiaQuestionService.php';
            $r = (new MiaQuestionService($db))->answer((int)($input['question_id'] ?? 0), (string)($input['answer'] ?? ''), (int)$user['id']);
            if (empty($r['ok'])) http_response_code(422);
            echo json_encode($r);
            break;

        case 'campaign':
            require_once APP_ROOT . '/Modules/Marketing/Services/MiaCampaignService.php';
            echo json_encode(['ok' => true, 'campaign' => (new MiaCampaignService($db))->current(new DateTimeImmutable('today'))]);
            break;

        case 'campaign_decide':
            require_once APP_ROOT . '/Modules/Marketing/Services/MiaCampaignService.php';
            $camp = new MiaCampaignService($db);
            $r = ($input['action'] ?? '') === 'approve'
                ? $camp->approve((int)($input['id'] ?? 0), (array)$user, $input['subject'] ?? null, $input['body'] ?? null)
                : $camp->dismiss((int)($input['id'] ?? 0), (array)$user);
            if (empty($r['ok'])) http_response_code(422);
            echo json_encode($r);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('Mia API: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Mia hit a problem — see the error log.']);
}
