<?php
/**
 * Special requests — session API (web schedule / crew pages / Yui + Otto cards).
 *
 * Crew (any logged-in user; empty unless the feature applies to them):
 *   GET  ?mode=visits&ids=1,2,3        Requests on these visits, as the crew see them.
 *   POST {mode:'ack', request_visit_id}                       "Got it".
 *   POST {mode:'outcome', request_visit_id, outcome: done|not_done|extra_done, reason?,
 *         extra_description?, extra_minutes?}
 * Office (jobs.edit):
 *   GET  ?mode=dryrun&text=...&contact_id=47[&date=YYYY-MM-DD]   Read-only: what it would attach to.
 *   GET  ?mode=office[&head=yui|otto]  Pending to confirm (scans recent client messages first) +
 *                                      this week's answers.
 *   GET  ?mode=clients&date=           Clients with visits in the next two weeks (manual form).
 *   POST {mode:'propose', text, contact_id}                    A pending request from pasted text.
 *   POST {mode:'confirm', request_id, visit_ids?, included?, extra?}  Attach + text the leader + push.
 *   POST {mode:'dismiss', request_id}
 *   POST {mode:'create', visit_ids[], client_words, included?, extra?}  Add one by hand (attached).
 *
 * POSTs need csrf_token (body) or X-CSRF-Token. ?mode=, never ?action=. Inert (enabled:false)
 * unless ops_settings.special_requests_enabled = '1'.
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
    $user = getCurrentUser();
    $userId = (int)($user['id'] ?? 0);

    $method = $_SERVER['REQUEST_METHOD'];
    $input  = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?? []) : [];
    $mode   = $method === 'POST' ? (string)($input['mode'] ?? '') : (string)($_GET['mode'] ?? '');

    // CSRF before session_write_close() — closing empties $_SESSION.
    if ($method === 'POST' && !verifyCSRFToken((string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token — reload the page and try again.', 'code' => 'CSRF_INVALID']);
        exit;
    }
    $canOffice = userHasPermission('jobs.edit');
    $db = getDB();
    session_write_close();

    require_once APP_ROOT . '/Modules/Operations/Services/SpecialRequestService.php';
    $svc = new SpecialRequestService($db);

    $officeModes = ['dryrun', 'office', 'clients', 'propose', 'confirm', 'dismiss', 'create'];
    if (in_array($mode, $officeModes, true) && !$canOffice) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Special requests are managed by people who run the schedule (jobs.edit).']);
        exit;
    }
    if ($mode !== 'dryrun' && $mode !== 'visits' && !$svc->ready()) {
        echo json_encode(['ok' => true, 'enabled' => false, 'error' => 'Special requests need migrations 1295–1299.']);
        exit;
    }

    switch ($mode) {
        case 'visits':
            $ids = array_slice(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? '')))), 0, 200);
            $on = $svc->ready() && $svc->appliesToUser($userId);
            echo json_encode(['ok' => true, 'enabled' => $on, 'requests' => $on ? (object)$svc->forVisits($ids, $userId) : (object)[]]);
            break;

        case 'ack':
            $r = $svc->ack((int)($input['request_visit_id'] ?? 0), $userId, 'web');
            if (!$r['ok']) http_response_code(400);
            echo json_encode($r);
            break;

        case 'outcome':
            $r = $svc->outcome(
                (int)($input['request_visit_id'] ?? 0), $userId, (string)($input['outcome'] ?? ''),
                (string)($input['reason'] ?? ''), (string)($input['extra_description'] ?? ''), (int)($input['extra_minutes'] ?? 0)
            );
            if (!$r['ok']) http_response_code(400);
            echo json_encode($r);
            break;

        case 'dryrun':
            $text = (string)($_GET['text'] ?? '');
            $contactId = (int)($_GET['contact_id'] ?? 0);
            $date = (string)($_GET['date'] ?? '');
            if ($text === '' || $contactId < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'text and contact_id are required.']);
                break;
            }
            if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = '';
            echo json_encode(['ok' => true, 'enabled' => $svc->enabled(), 'dry_run' => true] + $svc->dryRun($text, $contactId, $date ?: null));
            break;

        case 'office':
            $made = $svc->scanInbound();
            $head = (string)($_GET['head'] ?? '');
            echo json_encode(['ok' => true, 'scanned_new' => $made] + $svc->office($head ?: null));
            break;

        case 'clients':
            $from = date('Y-m-d');
            $st = $db->prepare("SELECT DISTINCT c.id, c.first_name, c.last_name, co.company_name
                FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id
                JOIN properties p ON p.id = jp.property_id
                JOIN contacts c ON c.id = p.site_contact_id
                LEFT JOIN companies co ON co.id = c.company_id
                WHERE jv.scheduled_date BETWEEN ? AND DATE_ADD(?, INTERVAL 14 DAY) AND jv.status IN ('scheduled','in_progress')
                ORDER BY co.company_name, c.last_name LIMIT 300");
            $st->execute([$from, $from]);
            echo json_encode(['ok' => true, 'clients' => $st->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'propose':
            if (!$svc->enabled()) { echo json_encode(['ok' => false, 'error' => 'Special requests are switched off.']); break; }
            $text = trim((string)($input['text'] ?? ''));
            $contactId = (int)($input['contact_id'] ?? 0);
            if ($text === '' || $contactId < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Paste the message and pick the client.']);
                break;
            }
            $r = $svc->propose(['text' => $text, 'contact_id' => $contactId, 'source' => 'manual', 'head' => 'otto', 'created_by' => $userId]);
            echo json_encode(['ok' => true] + $r);
            break;

        case 'confirm':
            $edits = [];
            foreach (['visit_ids', 'included', 'extra'] as $k) {
                if (isset($input[$k]) && is_array($input[$k])) $edits[$k] = $input[$k];
            }
            $r = $svc->confirm((int)($input['request_id'] ?? 0), $userId, $edits);
            if (!$r['ok']) http_response_code(400);
            echo json_encode($r);
            break;

        case 'dismiss':
            $r = $svc->dismiss((int)($input['request_id'] ?? 0), $userId);
            if (!$r['ok']) http_response_code(400);
            echo json_encode($r);
            break;

        case 'create':
            $r = $svc->createManual($input, $userId);
            if (!$r['ok']) http_response_code(400);
            echo json_encode($r);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown mode.']);
    }
} catch (Throwable $e) {
    error_log('special-requests.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Special requests hit a snag — try again.']);
}
