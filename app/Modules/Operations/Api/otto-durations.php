<?php
/**
 * Otto — real visit lengths.
 *
 * GET  ?mode=dryrun[&all=1]   READ-ONLY (admin): timer coverage (last 60 days) and, per recurring
 *                             lawn plan (all=1: every recurring plan), the samples used and dropped,
 *                             crew- and person-minute medians, spread, and the proposed length.
 * GET  ?mode=review           the same for the review page (jobs.edit) + recent changes.
 * POST {mode: 'apply', plan_id, minutes, csrf_token}          one plan (jobs.edit)
 * POST {mode: 'apply_all', csrf_token}                         every confident proposal, one batch
 * POST {mode: 'undo', change_id, csrf_token} | {mode: 'undo_batch', batch, csrf_token}
 *
 * Writes only job_plans.estimated_duration_minutes and otto_duration_changes (migration 1266).
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
    if (!userHasPermission('jobs.edit')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Visit lengths are for people who run the schedule (jobs.edit).']);
        exit;
    }
    $user = getCurrentUser();
    $method = $_SERVER['REQUEST_METHOD'];
    $input = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?? []) : [];
    $mode = $method === 'POST' ? (string)($input['mode'] ?? '') : (string)($_GET['mode'] ?? 'dryrun');

    if ($method === 'POST' && !verifyCSRFToken((string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token — reload the page and try again.']);
        exit;
    }
    $db = getDB();
    session_write_close();

    require_once APP_ROOT . '/Modules/Operations/Services/VisitDurationService.php';
    $svc = new VisitDurationService($db);

    if ($method === 'GET' && ($mode === 'dryrun' || $mode === 'review')) {
        if ($mode === 'dryrun' && !isAdmin()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'The dry run is for admins.']);
            exit;
        }
        $rows = $svc->review(!empty($_GET['all']));
        $plans = array_map(fn($r) => [
            'plan_id' => $r['plan_id'], 'plan_number' => $r['plan_number'], 'street' => $r['street'], 'service_type' => $r['service_type'],
            'planned_min' => $r['planned'], 'completed_visits' => $r['completed'], 'timed_visits' => $r['timed'],
            'samples_used' => $r['samples'], 'median_crew_min' => $r['median_crew'], 'median_person_min' => $r['median_person'],
            'avg_people' => $r['crew_size'], 'spread' => $r['spread'], 'stable' => $r['stable'], 'confident' => $r['confident'],
            'proposed_min' => $r['proposed'], 'change_min' => $r['change'], 'reason' => $r['reason'],
            'otto_would_say' => $r['line'], 'detail' => $r['detail'],
            'used' => array_map(fn($u) => ['visit_id' => $u['visit_id'], 'date' => $u['date'], 'crew_min' => $u['crew_min'], 'person_min' => $u['person_min'], 'people' => $u['people'], 'truck_only' => $u['truck_only']], $r['used']),
            'dropped' => $r['dropped'],
        ], $rows);
        $out = [
            'ok' => true, 'read_only' => true, 'coverage' => $svc->coverage(),
            'proposals' => count(array_filter($rows, fn($r) => $r['proposed'] !== null)),
            'confident' => count(array_filter($rows, fn($r) => $r['proposed'] !== null && $r['confident'])),
            'rules' => [
                'samples' => VisitDurationRules::SAMPLES, 'min_to_propose' => VisitDurationRules::MIN_TO_PROPOSE,
                'confident' => VisitDurationRules::CONFIDENT_SAMPLES . '+ samples and spread (p75−p25)/median ≤ ' . VisitDurationRules::STABLE_SPREAD,
                'dropped' => 'timer under ' . VisitDurationRules::MIN_MINUTES . ' min; over ' . VisitDurationRules::OUTLIER_X . '× the median of the others; auto-stopped with no end GPS and no visit departure GPS',
                'compares' => 'crew-minutes (union of timers = on-site time) with job_plans.estimated_duration_minutes; truck-login timers count only when no person timed the visit',
                'proposes_when' => 'difference ≥ max(' . VisitDurationRules::MIN_CHANGE_MIN . ' min, ' . (int)(VisitDurationRules::MIN_CHANGE_PCT * 100) . '% of plan), rounded to ' . VisitDurationRules::ROUND_TO,
            ],
            'plans' => $plans,
        ];
        if ($mode === 'review') {
            $out['read_only'] = false;
            $out['ready'] = $svc->ready();
            $out['history'] = $svc->history(30);
        }
        echo json_encode($out, $mode === 'dryrun' ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE : 0);
        exit;
    }

    if ($method === 'POST' && !$svc->ready()) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Visit lengths need migration 1266.']);
        exit;
    }

    if ($method === 'POST' && $mode === 'apply') {
        $planId = (int)($input['plan_id'] ?? 0);
        $basis = [];
        foreach ($svc->review(true) as $r) if ($r['plan_id'] === $planId) $basis = $r;
        $r = $svc->apply($planId, (int)($input['minutes'] ?? 0), (int)$user['id'], $basis);
        if (!$r['ok']) http_response_code(400);
        echo json_encode($r);
        exit;
    }
    if ($method === 'POST' && $mode === 'apply_all') {
        echo json_encode($svc->applyAllConfident((int)$user['id']));
        exit;
    }
    if ($method === 'POST' && $mode === 'undo') {
        $r = $svc->undo((int)($input['change_id'] ?? 0), (int)$user['id']);
        if (!$r['ok']) http_response_code(400);
        echo json_encode($r);
        exit;
    }
    if ($method === 'POST' && $mode === 'undo_batch') {
        $r = $svc->undoBatch((string)($input['batch'] ?? ''), (int)$user['id']);
        if (!$r['ok']) http_response_code(400);
        echo json_encode($r);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
} catch (Throwable $e) {
    error_log('Otto durations API: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Otto hit a snag reading the timers. Try again in a minute.']);
}
