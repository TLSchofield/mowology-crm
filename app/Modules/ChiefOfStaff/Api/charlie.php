<?php
/**
 * Charlie (Chief of Staff) — dashboard card endpoint. Owner only.
 *
 * GET  ?mode=today      The one thing, the rest, each head's top items, Charlie's questions.
 * POST {mode: 'act', key, what: open|dismiss|snooze, csrf_token}
 * POST {mode: 'answer', question_id, answer, csrf_token}
 *
 * ?mode=, not ?action= (the /api/ router appends its own action). Migration 1170.
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
    $user = getCurrentUser() ?? [];

    $method = $_SERVER['REQUEST_METHOD'];
    $input  = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
    $mode   = $method === 'POST' ? (string)($input['mode'] ?? '') : (string)($_GET['mode'] ?? 'today');

    if ($method === 'POST' && !verifyCSRFToken((string)($input['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $db = getDB();
    session_write_close();

    if (!function_exists('getWorkQueueItems')) require_once APP_ROOT . '/Services/CrmFunctions.php';
    require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/CharlieDeskService.php';
    $desk = new CharlieDeskService($db);
    if (!$desk->ready()) {
        echo json_encode(['ok' => false, 'ready' => false, 'error' => 'Migration 1170 has not run']);
        exit;
    }
    if (!$desk->isOwner($user)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Charlie works for the owner only']);
        exit;
    }
    $name = CharlieVoice::firstName($user);

    $slim = static fn(?array $it) => $it === null ? null : [
        'key' => $it['key'], 'head' => $it['head'], 'text' => $it['text'], 'url' => $it['url'],
        'priority' => $it['priority'], 'value' => $it['value'],
    ];

    switch ($mode) {
        case 'today':
            $view = $desk->today($name);
            $desk->markSeen();
            $heads = [];
            foreach ($view['heads'] as $h) {
                $heads[] = ['head' => $h['head'], 'name' => $h['name'], 'role' => $h['role'], 'headline' => $h['headline'],
                            'waiting' => $h['waiting'], 'items' => array_map($slim, $h['items'])];
            }
            echo json_encode([
                'ok'        => true,
                'say'       => $view['say'],
                'one'       => $slim($view['one']),
                'rest'      => array_map($slim, $view['rest']),
                'total'     => $view['total'],
                'heads'     => $heads,
                'failed'    => array_keys($view['failed']),
                'questions' => $desk->questions($name),
            ]);
            break;

        case 'act':
            $what = (string)($input['what'] ?? '');
            if (!in_array($what, ['open', 'dismiss', 'snooze'], true)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Unknown action']);
                break;
            }
            echo json_encode($desk->act(substr((string)($input['key'] ?? ''), 0, 120), $what));
            break;

        case 'answer':
            echo json_encode($desk->answer((int)($input['question_id'] ?? 0), (string)($input['answer'] ?? '')));
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('Charlie API: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Charlie is unavailable right now']);
}
