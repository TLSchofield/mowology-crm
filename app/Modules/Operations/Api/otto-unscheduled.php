<?php
/**
 * Otto — work with nothing scheduled: the READ-ONLY dry run.
 *
 * GET ?mode=dryrun&from=YYYY-MM-DD&to=YYYY-MM-DD[&cache=1]
 *     What Otto would flag on each day, and why — with the evidence: which sources had points
 *     (truck pings, each person's phone fixes, clock punches, timer start/stops), every truck
 *     stop and crew dwell at a client property, and the candidates he IGNORED (scheduled that
 *     day / too short / "not work" twice). Nothing is written: no suggestions, no cache.
 *     Default from = to = yesterday; at most 31 days. cache=1 reads stored days where present
 *     (the default recomputes from the raw GPS).
 *
 * Admin only. Acting on an item goes through /crm/api/otto.php (mode=decide).
 * ?mode=, never ?action= (see the /api/ router note in the vault).
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
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'The dry run is for admins.']);
        exit;
    }
    $db = getDB();
    session_write_close();

    $mode = (string)($_GET['mode'] ?? 'dryrun');
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' || $mode !== 'dryrun') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Only GET ?mode=dryrun here. Decisions go through /crm/api/otto.php.']);
        exit;
    }
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $from = (string)($_GET['from'] ?? $yesterday);
    $to = (string)($_GET['to'] ?? $from);
    foreach ([$from, $to] as $d) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || strtotime($d) === false) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Dates are YYYY-MM-DD.']);
            exit;
        }
    }
    if ($to < $from) [$from, $to] = [$to, $from];
    if ((strtotime($to) - strtotime($from)) / 86400 > 30) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'At most 31 days at a time.']);
        exit;
    }

    require_once APP_ROOT . '/Modules/Operations/Services/UnscheduledWorkService.php';
    $svc = new UnscheduledWorkService($db);
    $r = $svc->review($from, $to, !empty($_GET['cache']));

    $names = [];
    foreach ($db->query("SELECT id, full_name, device_type FROM users")->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $names[(int)$u['id']] = trim((string)$u['full_name']) . (($u['device_type'] ?? '') === 'truck' ? ' (truck tablet)' : '');
    }
    $hm = fn(int $t) => date('H:i', $t);
    $summary = [];
    $days = [];
    foreach ($r['days'] as $day) {
        $src = $day['sources'];
        $phones = [];
        foreach ((array)($src['phone_fixes'] ?? []) as $uid => $n) $phones[$names[(int)$uid] ?? ('#' . $uid)] = $n;
        $cands = [];
        foreach ($day['candidates'] as $c) {
            $label = date('D M j', strtotime($c['date'])) . ' — ' . ($c['flag'] ? 'FLAG ' : 'ignored (' . $c['ignored'] . ') ')
                . UnscheduledWorkService::street($c['address']) . ' ' . $c['window'] . ' (' . UnscheduledWorkRules::hours($c['minutes']) . ')'
                . ' [' . $c['basis'] . ', ' . $c['confidence'] . '] — ' . $c['evidence'];
            $summary[] = $label;
            $cands[] = [
                'flag' => $c['flag'], 'ignored' => $c['ignored'],
                'property_id' => $c['property_id'], 'address' => $c['address'], 'client' => $c['client'],
                'window' => $c['window'], 'minutes' => $c['minutes'], 'truck_min' => $c['truck_min'], 'crew_min' => $c['crew_min'],
                'basis' => $c['basis'], 'confidence' => $c['confidence'],
                'otto_would_say' => $c['flag'] ? UnscheduledWorkRules::text($c, UnscheduledWorkService::street($c['address'])) : null,
                'evidence' => $c['evidence'],
                'truck_stops' => array_map(fn($s) => ['from' => $hm($s['from']), 'to' => $hm($s['to']), 'minutes' => $s['minutes'], 'trip_label' => $s['label']], $c['truck_stops']),
                'crew' => array_map(fn($d) => ['who' => $names[$d['user_id']] ?? ('#' . $d['user_id']), 'from' => $hm($d['from']), 'to' => $hm($d['to']),
                    'minutes' => $d['minutes'], 'fixes' => $d['fixes'], 'sources' => $d['sources']], $c['crew']),
                'plans_on_property' => $c['plans'],
                'visits_within_3_days' => $c['visits_near'],
                'invoices_within_21_days' => $c['invoices'],
            ];
        }
        $days[] = [
            'date' => $day['date'],
            'data_sources' => [
                'truck_pings' => (int)($src['truck_pings'] ?? 0),
                'phone_fixes_after_thinning' => $phones,
                'clock_punch_fixes' => (int)($src['clock_fixes'] ?? 0),
                'timer_start_stop_fixes' => (int)($src['timer_fixes'] ?? 0),
                'exclusion_zones' => (int)($src['zones'] ?? 0),
                'truck_stops_all_day' => array_map(fn($s) => ['from' => $hm((int)$s['from']), 'to' => $hm((int)$s['to']),
                    'minutes' => (int)round(($s['to'] - $s['from']) / 60), 'label' => $s['label'], 'excluded_by' => $s['excluded_by'],
                    'nearest_property' => $s['nearest_property'], 'nearest_m' => $s['nearest_m']], (array)($src['stops'] ?? [])),
                'from_cache' => !empty($src['cached']),
            ],
            'candidates' => $cands,
        ];
    }
    echo json_encode([
        'ok' => true, 'read_only' => true, 'from' => $from, 'to' => $to, 'flagged' => $r['flagged'],
        'rules' => [
            'truck_stop_flags_alone_min' => UnscheduledWorkRules::MIN_TRUCK_MIN,
            'crew_only_flags_min' => UnscheduledWorkRules::MIN_CREW_MIN, 'crew_only_min_fixes' => UnscheduledWorkRules::MIN_CREW_FIXES,
            'property_radius_m' => UnscheduledWorkRules::RADIUS_M, 'or_inside_job_geofence' => true,
            'excluded' => 'ops_places (dump, supplier, yard, fuel), the office (ops_settings), crew homes (users.home_lat/lng)',
            'scheduled_means' => 'a visit scheduled/in progress/completed that day, completed that day, or a calendar stop not skipped',
        ],
        'summary' => $summary,
        'days' => $days,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Otto unscheduled dry run: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'The dry run hit a snag — the PHP error log has the details ("Otto unscheduled dry run").']);
}
