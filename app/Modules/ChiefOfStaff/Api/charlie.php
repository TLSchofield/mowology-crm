<?php
/**
 * Charlie (Foreman) — card and calendar page endpoint. Owner only.
 *
 * GET  ?mode=today      The brief (bad news first), the one thing, each head's top items,
 *                       questions, the decision inbox and the urgent list.
 * GET  ?mode=calendar   Deadlines, history, the year-end pack, rules, rulings, settings.
 * POST {mode: 'act', key, what: open|dismiss|snooze, csrf_token}
 * POST {mode: 'answer', question_id, answer, csrf_token}
 * POST {mode: 'deadline_done', key, csrf_token}                    from the card / inbox
 * POST {mode: 'deadline_mark', occurrence_id, what: done|skip|snooze, days?, note?, csrf_token}
 * POST {mode: 'deadline_save', id?, title, category, repeat, date|dates|day|months, anchor_date?,
 *       lead_days, prepare, amount_hint, active, csrf_token}
 * POST {mode: 'inbox_done', key, head, label, batch_key?, ok, message, csrf_token}
 *                       Logged after Tim's click reached the head's OWN endpoint.
 * POST {mode: 'override', rule, key, csrf_token}                   release a held proposal
 * POST {mode: 'rule_save', slug, enabled, params: {…}, csrf_token}
 * POST {mode: 'settings_save', payment_min, csrf_token}
 *
 * ?mode=, not ?action= (the /api/ router appends its own action). Migrations 1170–1173.
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
    require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/CharlieForemanService.php';
    require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/AccountantPackService.php';
    $f = new CharlieForemanService($db);
    $desk = $f->desk;
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
    $uid = (int)($user['id'] ?? 0);

    $slim = static fn(?array $it) => $it === null ? null : [
        'key' => $it['key'], 'head' => $it['head'], 'text' => $it['text'], 'url' => $it['url'],
        'priority' => $it['priority'], 'value' => $it['value'],
        // For the dashboard's Action Board: what kind it is, how long it has waited, its rank.
        'kind' => $it['kind'] ?? null, 'since' => $it['since'] ?? null,
        'first_seen' => $it['first_seen'] ?? null, 'score' => $it['score'] ?? null,
    ];
    $need = static function (bool $ready, string $migration) {
        if ($ready) return true;
        echo json_encode(['ok' => false, 'error' => "Migration {$migration} has not run"]);
        return false;
    };

    switch ($mode) {
        case 'today':
            $card = $f->card($name);
            $view = $card['view'];
            $desk->markSeen();
            $heads = [];
            foreach ($view['heads'] as $h) {
                $heads[] = ['head' => $h['head'], 'name' => $h['name'], 'role' => $h['role'], 'headline' => $h['headline'],
                            'waiting' => $h['waiting'], 'items' => array_map($slim, $h['items'])];
            }
            echo json_encode([
                'ok'        => true,
                'bad'       => $view['bad'],
                'bad_lead'  => CharlieVoice::badLead($view['bad']),
                'say'       => $view['say'],
                'one'       => $slim($view['one']),
                'rest'      => array_map($slim, $view['rest']),
                'total'     => $view['total'],
                'heads'     => $heads,
                'failed'    => array_keys($view['failed']),
                'questions' => $desk->questions($name),
                'inbox'     => $card['inbox'],
            ]);
            break;

        case 'calendar':
            $out = ['ok' => true, 'deadlines' => null, 'history' => [], 'pack' => [], 'rules' => null, 'rulings' => [], 'inbox' => null,
                    'payment_min' => null, 'questions' => $desk->questions($name)];
            if ($f->deadlines->ready()) {
                $out['deadlines'] = $f->deadlines->all();
                $out['history'] = $f->deadlines->history();
                $out['pack'] = (new AccountantPackService($db))->lines();
                $out['pack_year'] = (int)date('Y') - 1;
            }
            if ($f->inbox->ready()) {
                $out['rules'] = array_values($f->inbox->rules());
                $out['rulings'] = $f->inbox->rulings();
                $out['inbox'] = $f->inbox->view($name);
            }
            if ($f->urgent->ready()) $out['payment_min'] = $f->urgent->threshold();
            echo json_encode($out);
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

        case 'deadline_done':
            if (!$need($f->deadlines->ready(), '1171')) break;
            $occ = $f->deadlines->occurrenceIdForKey((string)($input['key'] ?? ''));
            echo json_encode($occ ? $f->deadlines->mark($occ, 'done', $uid) : ['ok' => false, 'message' => "I can't find that deadline"]);
            break;

        case 'deadline_mark':
            if (!$need($f->deadlines->ready(), '1171')) break;
            echo json_encode($f->deadlines->mark((int)($input['occurrence_id'] ?? 0), (string)($input['what'] ?? ''), $uid,
                (int)($input['days'] ?? 3), (string)($input['note'] ?? '')));
            break;

        case 'deadline_save':
            if (!$need($f->deadlines->ready(), '1171')) break;
            echo json_encode($f->deadlines->save((array)$input));
            break;

        case 'inbox_done':
            if (!$need($f->inbox->ready(), '1173')) break;
            $key = substr((string)($input['key'] ?? ''), 0, 120);
            $ok = !empty($input['ok']);
            $f->inbox->logAction($key, (string)($input['head'] ?? ''), (string)($input['label'] ?? ''),
                isset($input['batch_key']) ? (string)$input['batch_key'] : null, $ok, (string)($input['message'] ?? ''), $uid);
            if ($ok) $desk->act($key, 'open');   // the inbox click counts as going to it (learning)
            echo json_encode(['ok' => true]);
            break;

        case 'override':
            if (!$need($f->inbox->ready(), '1172')) break;
            echo json_encode($f->inbox->override((string)($input['rule'] ?? ''), substr((string)($input['key'] ?? ''), 0, 120), $uid));
            break;

        case 'rule_save':
            if (!$need($f->inbox->ready(), '1172')) break;
            echo json_encode($f->inbox->saveRule((string)($input['slug'] ?? ''), !empty($input['enabled']), (array)($input['params'] ?? [])));
            break;

        case 'settings_save':
            if (!$need($f->urgent->ready(), '1173')) break;
            $v = $input['payment_min'] ?? null;
            if (!is_numeric($v) || (float)$v < 0) { echo json_encode(['ok' => false, 'message' => 'Enter a dollar amount']); break; }
            $f->urgent->setThreshold((float)$v);
            echo json_encode(['ok' => true, 'message' => "Saved — I'll email you about failed payments of $" . number_format((float)$v, 0) . ' or more.']);
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
