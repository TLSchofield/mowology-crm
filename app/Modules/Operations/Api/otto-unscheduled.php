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
 *     Each flagged candidate also says whether it is a CONTRACT site and what Otto does there by
 *     himself (contract: {log, reason, contract, plan, invoice}) and what he already did (auto).
 *
 * POST {mode: 'undo_auto', id, csrf_token}   (jobs.edit)
 *     Undo a visit Otto logged at a contract site (otto_auto_visits, migration 1267): the visit is
 *     cancelled and Otto asks about that day instead.
 *
 * GET is admin only. Acting on an item goes through /crm/api/otto.php (mode=decide).
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
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode((string)file_get_contents('php://input'), true) ?? [];
        if (!userHasPermission('jobs.edit')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'This is for people who run the schedule (jobs.edit).']);
            exit;
        }
        if (!verifyCSRFToken((string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token — reload the page and try again.']);
            exit;
        }
        $user = getCurrentUser();
        $db = getDB();
        session_write_close();
        if ((string)($input['mode'] ?? '') !== 'undo_auto') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
            exit;
        }
        require_once APP_ROOT . '/Modules/Operations/Services/OttoContractLogService.php';
        require_once APP_ROOT . '/Modules/Jobs/Services/PlanFunctions.php';   // VisitLifecycleService (stop status)
        $r = (new OttoContractLogService($db))->undo((int)($input['id'] ?? 0), (int)$user['id']);
        if (!$r['ok']) http_response_code(400);
        echo json_encode($r);
        exit;
    }
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
            $label = date('D M j', strtotime($c['date'])) . ' — ' . ($c['flag'] ? ($c['kind'] === 'extra' ? 'FLAG EXTRA WORK ' : 'FLAG ') : 'ignored (' . $c['ignored'] . ') ')
                . UnscheduledWorkService::street($c['address']) . ' ' . $c['window'] . ' (' . UnscheduledWorkRules::hours($c['minutes']) . ')'
                . ' [' . $c['basis'] . ', ' . $c['confidence'] . '] — ' . $c['evidence']
                . ($c['scheduled_visits'] ? ' — scheduled: ' . implode('; ', array_map(fn($v) => 'visit ' . ($v['visit_id'] ?? '—') . ' ' . $v['plan_number']
                    . ' ' . $v['service_type'] . ' (' . $v['status'] . ', plan ' . ($v['planned_min'] ?? '—') . ' min, timer ' . ($v['timer_min'] ?? '—') . ' min)', $c['scheduled_visits'])) : '')
                . (count($c['site_props'] ?? []) > 1 ? ' — site: ' . implode(' + ', array_map(fn($p) => $c['site_names'][$p]['street'] ?? ('#' . $p), $c['site_props'])) . ', stay ' . $c['window_site'] : '')
                . (!empty($c['explained']) ? ' — explained: ' . implode('; ', array_map(fn($x) => 'visit ' . ($x['visit_id'] ?? '—') . ' ' . $x['plan_number'] . ' ' . $x['service_type']
                    . ' ~' . $x['minutes'] . ' min (' . $x['basis'] . ') ' . $hm($x['from']) . '–' . $hm($x['to']), $c['explained'])) : '')
                . (!empty($c['contract']) ? ' — CONTRACT ' . $c['contract']['contract']['number'] . ': ' . ($c['contract']['log'] ? 'Otto logs it himself' : $c['contract']['reason']) : '')
                . (!empty($c['auto']) ? ' — Otto ' . $c['auto']['status'] . ($c['auto']['visit_id'] ? ' visit ' . $c['auto']['visit_id'] : '') : '');
            $summary[] = $label;
            $cands[] = [
                'site' => ['properties' => array_map(fn($p) => ['id' => $p, 'street' => $c['site_names'][$p]['street'] ?? null, 'client' => $c['site_names'][$p]['client'] ?? null], $c['site_props'] ?? []),
                    'from' => $hm((int)($c['site_start'] ?? $c['start'])), 'to' => $hm((int)($c['site_end'] ?? $c['end'])), 'minutes' => (int)($c['site_minutes'] ?? $c['minutes'])],
                'explained_by_scheduled_visits' => array_map(fn($x) => $x + ['from_hm' => $hm($x['from']), 'to_hm' => $hm($x['to'])], $c['explained'] ?? []),
                'crew_fixes_in_remainder_by_property' => $c['crew_votes'] ?? [],
                'client_properties_within_250m' => array_map(function ($n) use ($db) {
                    static $addr = [];
                    if (!isset($addr[$n['id']])) {
                        $q = $db->prepare("SELECT address FROM properties WHERE id = ?");
                        $q->execute([$n['id']]);
                        $addr[$n['id']] = (string)$q->fetchColumn();
                    }
                    return ['property_id' => $n['id'], 'address' => $addr[$n['id']], 'm_from_stop_centre' => $n['m_centroid'],
                        'm_from_nearest_truck_ping' => $n['m_nearest'], 'included_in_site' => $n['included']];
                }, $c['nearby'] ?? []),
                'contract' => $c['contract'] ?? null,
                'otto_auto_visit' => $c['auto'] ?? null,
                'flag' => $c['flag'], 'kind' => $c['kind'] === 'extra' ? 'extra_work' : 'unscheduled', 'ignored' => $c['ignored'],
                'scheduled_visits' => $c['scheduled_visits'],
                'planned_min' => $c['planned_min'], 'planned_basis' => $c['planned_basis'], 'extra_min' => $c['extra_min'],
                'property_id' => $c['property_id'], 'address' => $c['address'], 'client' => $c['client'],
                'window' => $c['window'], 'minutes' => $c['minutes'], 'truck_min' => $c['truck_min'], 'crew_min' => $c['crew_min'],
                'basis' => $c['basis'], 'confidence' => $c['confidence'],
                'otto_would_say' => $c['flag'] ? UnscheduledWorkRules::text($c, UnscheduledWorkService::street($c['address']), $c['site_names'] ?? []) : null,
                'evidence' => $c['evidence'],
                'truck_stops' => array_map(fn($s) => ['from' => $hm($s['from']), 'to' => $hm($s['to']), 'minutes' => $s['minutes'], 'trip_label' => $s['label']], $c['truck_stops']),
                'crew' => array_map(fn($d) => ['who' => $names[$d['user_id']] ?? ('#' . $d['user_id']), 'from' => $hm($d['from']), 'to' => $hm($d['to']),
                    'minutes' => $d['minutes'], 'fixes' => $d['fixes'], 'sources' => $d['sources']], $c['crew']),
                'plans_on_property' => $c['plans'],
                'visits_within_3_days' => $c['visits_near'],
                'invoices_within_21_days' => $c['invoices'],
                'empty_calendar_stops' => array_map(fn($st) => $st + ['line' => UnscheduledWorkRules::emptyStopLine($st)], $c['empty_stops']),
                'timers_not_on_a_visit_that_day' => array_map(fn($t) => ['who' => $t['who'], 'from' => $hm($t['start']), 'to' => $t['end'] ? $hm($t['end']) : null,
                    'minutes' => $t['minutes'], 'visit_id' => $t['visit_id'], 'visit_date' => $t['visit_date'], 'plan_number' => $t['plan_number'],
                    'line' => UnscheduledWorkRules::strayTimerLine($t)], $c['stray_timers']),
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
            'visits_done_this_day_but_scheduled_another' => array_map(fn($v) => ['visit_id' => $v['visit_id'], 'plan' => $v['plan_number'],
                'service' => $v['service_type'], 'property_id' => $v['property_id'], 'scheduled' => $v['moved_from'], 'status' => $v['status'],
                'timer_min_this_day' => $v['timer_min']], $svc->movedVisits($day['date'])),
        ];
    }
    echo json_encode([
        'ok' => true, 'read_only' => true, 'from' => $from, 'to' => $to, 'flagged' => $r['flagged'],
        // If these differ, or differ from build_on_disk, production OPcache is serving an older copy — reset it.
        'build' => ['rules' => UnscheduledWorkRules::BUILD, 'service' => UnscheduledWorkService::BUILD,
            'rules_on_disk' => preg_match("/BUILD = '([^']+)'/", (string)@file_get_contents(APP_ROOT . '/Modules/Operations/Services/UnscheduledWorkRules.php'), $__m) ? $__m[1] : null,
            'service_on_disk' => preg_match("/BUILD = '([^']+)'/", (string)@file_get_contents(APP_ROOT . '/Modules/Operations/Services/UnscheduledWorkService.php'), $__m2) ? $__m2[1] : null],
        'rules' => [
            'truck_stop_flags_alone_min' => UnscheduledWorkRules::MIN_TRUCK_MIN,
            'crew_only_flags_min' => UnscheduledWorkRules::MIN_CREW_MIN, 'crew_only_min_fixes' => UnscheduledWorkRules::MIN_CREW_FIXES,
            'property_radius_m' => UnscheduledWorkRules::RADIUS_M, 'or_inside_job_geofence' => true,
            'excluded' => 'ops_places (dump, supplier, yard, fuel), the office (ops_settings), crew homes (users.home_lat/lng)',
            'scheduled_means' => 'a visit scheduled/in progress/completed that day, completed that day, or scheduled within ±7 days and TIMED that day (done that day); skipped and cancelled do not count, nor a visit whose timers all ran on another day, nor an empty calendar stop (shown as evidence). Same rule on every path.',
            'site' => 'truck stops and crew dwells sharing any client property within 120 m of ANY truck ping / crew fix, or a crew dwell overlapping a truck stop within 250 m',
            'extra_work_when' => 'scheduled, but the stay is >= ' . UnscheduledWorkRules::EXTRA_MIN . ' min longer than the plan length of that day\'s visit(s) and >= '
                . UnscheduledWorkRules::EXTRA_X . 'x it (timer minutes stand in only when no plan length is set)',
        ],
        'summary' => $summary,
        'days' => $days,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Otto unscheduled dry run: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'The dry run hit a snag — the PHP error log has the details ("Otto unscheduled dry run").']);
}
