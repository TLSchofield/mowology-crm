<?php
/**
 * Otto — the operations head's dashboard card API.
 *
 * GET  ?mode=suggestions  What Otto suggests now (records new ones so decisions can be
 *                         learned from; expires ones fixed elsewhere) + open questions.
 * GET  ?mode=brief        Charlie's 7 am brief for this head (read-only).
 * GET  ?mode=find_slot&visit_id=N   The weather guard's next good slot for a flagged visit.
 * POST {mode: 'decide', suggestion_id, choice, date?, time?, clock_out?, minutes?, csrf_token}
 *        weather: move (with date/time) | keep · clock_out / job_timer / no_time: apply
 *        silent: real | fine · any: dismiss
 * POST {mode: 'answer', question_id, answer, csrf_token}
 *
 * ?mode=, never ?action= (see the /api/ router note in the vault). jobs.edit only.
 * Nothing here texts a crew member; every change is the owner's click.
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
    if (!userHasPermission('jobs.edit')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Otto is for people who run the schedule (jobs.edit).']);
        exit;
    }
    $user = getCurrentUser();

    $method = $_SERVER['REQUEST_METHOD'];
    $input  = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?? []) : [];
    $mode   = $method === 'POST' ? (string)($input['mode'] ?? '') : (string)($_GET['mode'] ?? 'suggestions');

    if ($method === 'POST' && !verifyCSRFToken((string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token — reload the page and try again.']);
        exit;
    }

    $db = getDB();
    session_write_close();

    require_once APP_ROOT . '/Modules/Operations/Services/OpsDeskService.php';
    $desk = new OpsDeskService($db);
    if (!$desk->ready()) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Otto needs migrations 1150–1152.']);
        exit;
    }

    if ($method === 'GET' && $mode === 'suggestions') {
        require_once APP_ROOT . '/Modules/Operations/Services/OttoQuestionService.php';
        $items = $desk->current(true);
        $qs = new OttoQuestionService($db);
        $qs->scanWeather($items);
        $items = array_values(array_filter($items, fn($i) => $i['sid'] !== null));
        echo json_encode(['ok' => true, 'items' => array_map(fn($i) => [
            'id' => $i['sid'], 'key' => $i['key'], 'kind' => $i['kind'], 'priority' => (int)$i['priority'],
            'text' => $i['text'], 'detail' => $i['detail'] ?? '', 'url' => $i['url'], 'propose' => $i['propose'] ?? [],
        ], $items), 'questions' => $qs->open()]);
        exit;
    }

    if ($method === 'GET' && $mode === 'brief') {
        require_once APP_ROOT . '/Modules/Expenses/Services/PennyQuestionService.php';
        echo json_encode(['ok' => true] + $desk->brief(PennyQuestionService::firstName((array)$user)));
        exit;
    }

    if ($method === 'GET' && $mode === 'find_slot') {
        require_once APP_ROOT . '/Modules/Operations/Services/OttoActionService.php';
        $slot = (new OttoActionService($db))->findSlot((int)($_GET['visit_id'] ?? 0));
        echo json_encode(['ok' => true, 'slot' => $slot]);
        exit;
    }

    if ($method === 'POST' && $mode === 'decide') {
        require_once APP_ROOT . '/Modules/Operations/Services/OttoActionService.php';
        $r = (new OttoActionService($db))->decide((int)($input['suggestion_id'] ?? 0), $input, (int)$user['id']);
        if (!$r['ok']) http_response_code(400);
        if ($r['ok']) {
            try {   // the schedule strip shows the change at once (migration 1286)
                require_once APP_ROOT . '/Modules/Operations/Services/OttoScheduleService.php';
                (new OttoScheduleService($db))->forget();
            } catch (Throwable $e) { /* the cache is a bonus */ }
        }
        echo json_encode($r);
        exit;
    }

    if ($method === 'POST' && $mode === 'answer') {
        require_once APP_ROOT . '/Modules/Operations/Services/OttoQuestionService.php';
        $r = (new OttoQuestionService($db))->answer((int)($input['question_id'] ?? 0), (string)($input['answer'] ?? ''), (int)$user['id']);
        if (!$r['ok']) http_response_code(400);
        echo json_encode($r);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
} catch (Throwable $e) {
    error_log('Otto API: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Otto hit a snag. Try again in a minute.']);
}
