<?php
/**
 * Mia — Channels (Google Business Profile, social, website, listings). Marketing approvers only.
 *
 * GET  ?mode=summary                         Everything the Channels section of Mia's card shows.
 * POST {mode: 'prepare', force?}             This week's drafts + website read (at most once a week).
 * POST {mode: 'review_add', reviewer_name, rating, comment, review_date?, service?}
 *                                            A review Tim pasted in; Mia drafts the reply.
 * POST {mode: 'review_save', id, text}       Save Tim's edit of the reply draft.
 * POST {mode: 'review_done', id, text}       Tim pasted the reply into Google himself.
 * POST {mode: 'review_post', id, text}       Tim's click: post the reply to Google (live mode only).
 * POST {mode: 'review_dismiss', id}          No reply needed.
 * POST {mode: 'post_save', id, body}         Save Tim's edit of the Google post.
 * POST {mode: 'post_decide', id, action: publish|copied|dismiss, body?}
 *                                            publish = Tim's click, live mode only.
 * Nothing is ever posted without one of Tim's clicks. All POSTs need csrf_token.
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
    $uid = (int)($user['id'] ?? 0);

    $method = $_SERVER['REQUEST_METHOD'];
    $input  = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
    $mode   = $method === 'POST' ? (string)($input['mode'] ?? '') : (string)($_GET['mode'] ?? 'summary');

    if ($method === 'POST' && !verifyCSRFToken($input['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $db = getDB();
    session_write_close();

    require_once APP_ROOT . '/Modules/Marketing/Services/MiaChannelsService.php';
    $ch = new MiaChannelsService($db);
    $id = (int)($input['id'] ?? 0);
    $needReady = !in_array($mode, ['summary', 'prepare'], true);
    if ($needReady && !$ch->ready()) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Migration 1200 has not been run yet.']);
        exit;
    }

    switch ($mode) {
        case 'summary':
            echo json_encode(['ok' => true, 'channels' => $ch->summary()]);
            break;

        case 'prepare':
            // Website read and social drafts can take a few seconds; the card calls this in the background.
            @set_time_limit(90);
            echo json_encode(['ok' => true] + $ch->prepareWeek(new DateTimeImmutable('today'), !empty($input['force'])));
            break;

        case 'review_add':
            $r = $ch->addReview($input, $uid);
            if (empty($r['ok'])) http_response_code(422);
            echo json_encode($r);
            break;

        case 'review_save':
            echo json_encode($ch->saveReplyDraft($id, (string)($input['text'] ?? '')));
            break;

        case 'review_done':
        case 'review_post':
            $r = $ch->replyDone($id, (string)($input['text'] ?? ''), $mode === 'review_post', $uid);
            if (empty($r['ok'])) http_response_code(422);
            echo json_encode($r);
            break;

        case 'review_dismiss':
            echo json_encode($ch->dismissReview($id, $uid));
            break;

        case 'post_save':
            $r = $ch->savePost($id, (string)($input['body'] ?? ''));
            if (empty($r['ok'])) http_response_code(422);
            echo json_encode($r);
            break;

        case 'post_decide':
            $r = $ch->decidePost($id, (string)($input['action'] ?? ''), $uid, isset($input['body']) ? (string)$input['body'] : null);
            if (empty($r['ok'])) http_response_code(422);
            echo json_encode($r);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('Mia channels API: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Mia hit a problem — see the error log.']);
}
