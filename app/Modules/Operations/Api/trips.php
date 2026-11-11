<?php
/**
 * Otto — trip overhead API (dump / supplier runs from the truck trail).
 *
 * GET  ?mode=day&date=YYYY-MM-DD   The day as stops and drives, its overhead runs priced, and
 *                                  unnamed stops to name. Read-only (nothing is stored).
 * GET  ?mode=baseline              One-man baseline per place, from stored runs.
 * POST {mode: 'name_stop', lat, lng, name, kind, date?, csrf_token}
 *        creates the ops_places row; with date, re-prices that day.
 * POST {mode: 'set_crew', trip_key, one_man: 1|0|null, csrf_token}
 *        the owner's one-man / two-man toggle for a stored run (null clears it).
 * POST {mode: 'confirm_stop', lat, lng, date, name, kind, vendor_id?, evidence?, answer: 1|0, csrf_token}
 *        Yes / No to Penny's guess (a supplier card charge) for an unnamed stop.
 * POST {mode: 'learn_places', days?: 1–120, csrf_token}   admin only
 *        walk past supplier / dump / fuel receipts against the truck trail and create a place for
 *        every vendor seen at the same truck stop on 2+ days (the cron does this nightly).
 *
 * ?mode=, never ?action= (see the /api/ router note in the vault). jobs.edit only.
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
        echo json_encode(['ok' => false, 'error' => 'Trips are for people who run the schedule (jobs.edit).']);
        exit;
    }
    $user = getCurrentUser();

    $method = $_SERVER['REQUEST_METHOD'];
    $input  = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?? []) : [];
    $mode   = $method === 'POST' ? (string)($input['mode'] ?? '') : (string)($_GET['mode'] ?? 'day');

    if ($method === 'POST' && !verifyCSRFToken((string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token — reload the page and try again.']);
        exit;
    }

    $db = getDB();
    session_write_close();

    require_once APP_ROOT . '/Modules/Operations/Services/TripCostService.php';
    $svc = new TripCostService($db);
    if (!$svc->ready()) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Trips need migration 1216.']);
        exit;
    }

    if ($method === 'GET' && $mode === 'day') {
        $date = (string)($_GET['date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid date (expected YYYY-MM-DD)']);
            exit;
        }
        $day = $svc->pricedDay($date);
        $hm = fn($t) => $t === null ? null : date('H:i', (int)$t);
        $evOut = fn(?array $e) => $e === null ? null : [
            'basis' => $e['basis'], 'strength' => $e['strength'], 'receipt_id' => $e['receipt_id'], 'vendor_id' => $e['vendor_id'],
            'name' => $e['name'], 'kind' => $e['kind'], 'total' => $e['total'], 'line' => StopEvidenceService::evidenceLine($e),
            'ticket' => $e['ticket'] ? ['in' => $hm($e['ticket']['in']), 'out' => $hm($e['ticket']['out']), 'minutes' => $e['ticket']['minutes']] : null,
        ];
        echo json_encode([
            'ok' => true, 'date' => $date, 'pings' => $day['pings'], 'first' => $day['first'], 'last' => $day['last'],
            'stops' => array_values(array_map(fn($s) => [
                'from' => $hm($s['start']), 'to' => $hm($s['end']), 'minutes' => $s['minutes'], 'lat' => $s['lat'], 'lng' => $s['lng'],
                'type' => $s['label']['type'], 'name' => $s['label']['name'], 'kind' => $s['label']['kind'],
                'evidence' => $evOut($day['evidence'][$s['start']][0] ?? null),
            ], array_filter($day['segments'], fn($s) => $s['type'] === 'stop'))),
            'runs' => array_map(fn($r) => [
                'trip_key' => $r['trip_key'], 'left' => $hm($r['left_at']), 'back' => $hm($r['returned_at']),
                'from' => $r['from']['name'] ?? null, 'to' => $r['to']['name'] ?? null, 'minutes' => $r['minutes'],
                'drive_min' => $r['drive_min'], 'km' => $r['km'], 'total' => $r['total'], 'people' => $r['people'],
                'one_man' => $r['crew']['one_man'], 'basis' => $r['crew']['basis'], 'driver' => $r['driver'],
                'legs' => array_map(fn($l) => [
                    'place_id' => $l['place_id'], 'name' => $l['name'], 'kind' => $l['kind'], 'arrived' => $hm($l['arrived_at']),
                    'departed' => $hm($l['departed_at']), 'onsite_min' => $l['onsite_min'], 'drive_min' => $l['drive_min'],
                    'km' => $l['km'], 'cost' => $l['cost'], 'receipt_ids' => $l['receipt_ids'],
                    'onsite_basis' => $l['onsite_basis'], 'evidence' => $evOut($l['evidence']),
                ], $r['legs']),
            ], $day['runs']),
            'unnamed' => array_map(fn($u) => ['lat' => $u['lat'], 'lng' => $u['lng'], 'from' => $hm($u['start']), 'to' => $hm($u['end']), 'minutes' => $u['minutes'],
                'penny_thinks' => $evOut($day['proposals'][$u['start']] ?? null)], $day['unnamed']),
            'penny_named' => array_map(fn($c) => ['place_id' => $c['place_id'], 'name' => $c['name'], 'kind' => $c['kind'], 'at' => $hm($c['start']),
                'evidence' => StopEvidenceService::evidenceLine($c['evidence'])], $day['penny_named']),
            'settings' => ['per_km' => $day['settings']['per_km'], 'per_km_default' => $day['settings']['per_km_default'], 'burden_pct' => $day['settings']['burden_pct']],
        ]);
        exit;
    }

    if ($method === 'GET' && $mode === 'baseline') {
        $b = $svc->baseline();
        echo json_encode(['ok' => true, 'places' => $b, 'lines' => array_map([TripCostService::class, 'baselineLine'], $b)]);
        exit;
    }

    if ($method === 'POST' && $mode === 'name_stop') {
        $r = $svc->nameStop((float)($input['lat'] ?? 0), (float)($input['lng'] ?? 0), (string)($input['name'] ?? ''),
            (string)($input['kind'] ?? ''), (int)$user['id'], isset($input['date']) ? (string)$input['date'] : null);
        if (!$r['ok']) http_response_code(400);
        echo json_encode($r);
        exit;
    }

    if ($method === 'POST' && $mode === 'set_crew') {
        $v = $input['one_man'] ?? null;
        $r = $svc->setCrew((string)($input['trip_key'] ?? ''), $v === null || $v === '' ? null : (int)(bool)$v);
        if (!$r['ok']) http_response_code(400);
        echo json_encode($r);
        exit;
    }

    if ($method === 'POST' && $mode === 'confirm_stop') {
        $r = $svc->confirmStop((float)($input['lat'] ?? 0), (float)($input['lng'] ?? 0), (string)($input['name'] ?? ''),
            (string)($input['kind'] ?? 'supplier'), isset($input['vendor_id']) ? (int)$input['vendor_id'] : null,
            (int)($input['answer'] ?? 0) === 1, (int)$user['id'], (string)($input['date'] ?? ''), (string)($input['evidence'] ?? ''));
        if (!$r['ok']) http_response_code(400);
        echo json_encode($r);
        exit;
    }

    if ($method === 'POST' && $mode === 'learn_places') {
        if (!isAdmin()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Learning places is for admins.']);
            exit;
        }
        $r = $svc->ev->learnPlaces((int)($input['days'] ?? 120));
        echo json_encode(['ok' => true] + $r);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
} catch (Throwable $e) {
    error_log('Trips API: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Otto hit a snag reading the trail. Try again in a minute.']);
}
