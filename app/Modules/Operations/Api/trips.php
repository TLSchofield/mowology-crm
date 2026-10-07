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
        echo json_encode([
            'ok' => true, 'date' => $date, 'pings' => $day['pings'], 'first' => $day['first'], 'last' => $day['last'],
            'stops' => array_values(array_map(fn($s) => [
                'from' => $hm($s['start']), 'to' => $hm($s['end']), 'minutes' => $s['minutes'], 'lat' => $s['lat'], 'lng' => $s['lng'],
                'type' => $s['label']['type'], 'name' => $s['label']['name'], 'kind' => $s['label']['kind'],
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
                ], $r['legs']),
            ], $day['runs']),
            'unnamed' => array_map(fn($u) => ['lat' => $u['lat'], 'lng' => $u['lng'], 'from' => $hm($u['start']), 'to' => $hm($u['end']), 'minutes' => $u['minutes']], $day['unnamed']),
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

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
} catch (Throwable $e) {
    error_log('Trips API: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Otto hit a snag reading the trail. Try again in a minute.']);
}
