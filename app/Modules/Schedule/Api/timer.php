<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/timer.php
 *
 * Mobile Job Timer API — Start / Stop / Active status
 * Authorization: Bearer <jwt>
 *
 * GET  ?action=active
 * POST {action: 'start', visit_id, lat?, lng?}
 * POST {action: 'stop',  visit_id, lat?, lng?, complete_visit?}
 * POST {action: 'skip',  visit_id, reason?}
 */

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

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    require_once APP_ROOT . '/Core/config.php';
    require_once APP_ROOT . '/Core/ApiResponse.php'; // generic errors to the user, details to the log
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once APP_ROOT . '/Core/IdempotencyHelper.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once CRM_INCLUDES . '/timeclock-functions.php';

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];

    // Idempotency key — supplied by iOS/Capacitor clients as a UUID per action attempt.
    $idempKey = trim($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // ?mode= — the /api router's QSA rewrite owns `action`, so ?action=active never arrived.
        $action = trim($_GET['mode'] ?? $_GET['action'] ?? '');
    } else {
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = trim($input['action'] ?? '');
    }

    switch ($action) {

        case 'active':
            $timer = getActiveVisitTimer($userId);
            echo json_encode([
                'success'      => true,
                'active_timer' => $timer ? [
                    'id'               => (int)$timer['id'],
                    'visit_id'         => (int)$timer['visit_id'],
                    'job_title'        => $timer['job_title']        ?? null,
                    'job_number'       => $timer['job_number']       ?? null,
                    'property_address' => $timer['property_address'] ?? null,
                    'start_time'       => $timer['start_time'],
                    'elapsed_seconds'  => max(0, time() - strtotime($timer['start_time'])),
                ] : null,
            ]);
            break;

        case 'start':
            $visitId = (int)($input['visit_id'] ?? 0);
            if (!$visitId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'visit_id is required']);
                exit;
            }

            // Crew on the visit or office (#15, Fable audit 2026-10-12) — checked here, before the gates,
            // idempotency replay and auto clock-in, not only deep inside startJobTimer().
            if (!userMayActOnVisit($visitId, $userId)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'You are not assigned to this visit']);
                exit;
            }

            // Return cached response if this key was already processed.
            if ($idempKey) {
                $db = getDB();
                $cached = idempotencyCheck($db, $idempKey, $userId);
                if ($cached !== null) { echo $cached; exit; }
            }

            $lat         = isset($input['lat']) ? (float)$input['lat'] : null;
            $lng         = isset($input['lng']) ? (float)$input['lng'] : null;
            $autoStarted = !empty($input['auto_started']);

            // A gated user (TrackingSetupGate) must clock in through the checked path;
            // starting a job must not clock them in around it.
            require_once APP_ROOT . '/Modules/Team/Services/TrackingSetupGate.php';
            if (TrackingSetupGate::appliesTo(getTimeClockSetting(TrackingSetupGate::SETTING, ''), (int)$userId)
                && !getActiveClockEntry($userId)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Clock in first, using the Clock In button.', 'tracking_setup_required' => true]);
                break;
            }

            // Special request gate (inert unless ops_settings.special_requests_enabled): an unread
            // client request answers 409 with the request, so an app build without the gate can't
            // walk past it. A future offline replay sends queued_at (ms) and passes, logged.
            require_once APP_ROOT . '/Modules/Operations/Services/SpecialRequestGate.php';
            SpecialRequestGate::enforce(getDB(), $visitId, $userId, isset($input['queued_at']),
                $input['queued_at'] ?? null, $autoStarted ? 'auto_start' : 'start', 'message');

            // Queued offline? Record when it was tapped, not when it arrived (OfflineTime).
            require_once APP_ROOT . '/Modules/Team/Services/OfflineTime.php';
            $when    = OfflineTime::accept($input['client_time'] ?? null, date('Y-m-d H:i:s'));
            if ($when['reject']) {   // over 7 days old: record nothing (the office enters it by hand)
                if (function_exists('logActivity')) logActivity($userId, null, 'job_start_not_recorded', "Visit {$visitId}: " . $when['note']);
                echo json_encode(['success' => true, 'recorded' => false, 'visit_id' => $visitId, 'message' => $when['note']]);
                break;
            }
            $tapped  = $when['from_phone'] ? $when['at'] : null;
            // A start replayed from the phone's queue may have no location (no fix in a dead zone):
            // record it, noted, instead of refusing it and losing the job's time.
            $queued  = !empty($input['queued']);
            $entryId = startVisitTimer($visitId, $userId, $lat, $lng, $autoStarted, $tapped, $queued);
            if ($queued && ($lat === null || $lng === null)) {
                $when['note'] = trim(($when['note'] ?? '') . ' No location (offline).');
            }
            if ($when['note']) {
                getDB()->prepare("UPDATE job_time_entries SET notes = TRIM(CONCAT(COALESCE(notes, ''), ' ', ?)) WHERE id = ?")
                       ->execute([$when['note'], $entryId]);
            }

            // Auto clock-in globally if not already clocked in.
            if (!isset($db)) $db = getDB();
            $ckChk = $db->prepare(
                "SELECT id FROM time_clock_entries
                 WHERE user_id = ? AND status = 'active' AND clock_out IS NULL
                 LIMIT 1"
            );
            $ckChk->execute([$userId]);
            $autoClockIn = false;
            if (!$ckChk->fetch()) {
                $db->prepare(
                    "INSERT INTO time_clock_entries
                     (user_id, clock_in, clock_in_lat, clock_in_lng, status, created_at)
                     VALUES (?, COALESCE(?, NOW()), ?, ?, 'active', NOW())"
                )->execute([$userId, $tapped, $lat, $lng]);
                $autoClockIn = true;
            }

            $responseStart = json_encode([
                'success'       => true,
                'message'       => 'Job started',
                'entry_id'      => $entryId,
                'visit_id'      => $visitId,
                'start_time'    => $when['at'],
                'time_flag'     => $when['flag'],
                'auto_clock_in' => $autoClockIn,
            ]);
            if ($idempKey) idempotencyStore($db, $idempKey, $userId, 'timer', 'start', $responseStart);
            echo $responseStart;
            break;

        case 'stop':
            $visitId       = (int)($input['visit_id'] ?? 0);
            if (!$visitId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'visit_id is required']);
                exit;
            }

            // Return cached response if this key was already processed.
            if ($idempKey) {
                $db = getDB();
                $cached = idempotencyCheck($db, $idempKey, $userId);
                if ($cached !== null) { echo $cached; exit; }
            }

            // Same ownership rule as start (2026-10-12): crew on the visit, own timer, or admin/manager.
            if (!userMayActOnVisit($visitId, $userId)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'You are not assigned to this visit']);
                exit;
            }

            $lat           = isset($input['lat']) ? (float)$input['lat'] : null;
            $lng           = isset($input['lng']) ? (float)$input['lng'] : null;
            $notes         = $input['notes'] ?? null;
            $completeVisit = $input['complete_visit'] ?? true;
            $extrasMinutes = max(0, (int)($input['extras_minutes'] ?? 0));
            $extrasNote    = trim((string)($input['extras_note'] ?? ''));

            // Queued offline? The stop counts from when it was tapped — never before this
            // person's own timer started (OfflineTime notBefore).
            require_once APP_ROOT . '/Modules/Team/Services/OfflineTime.php';
            $ownStart = getDB()->prepare("SELECT MAX(start_time) FROM job_time_entries
                                          WHERE visit_id = ? AND user_id = ? AND status = 'active' AND end_time IS NULL");
            $ownStart->execute([$visitId, $userId]);
            $startedAt = $ownStart->fetchColumn() ?: null;
            $when = OfflineTime::accept($input['client_time'] ?? null, date('Y-m-d H:i:s'), $startedAt ? (string)$startedAt : null);
            if ($when['reject']) {
                if (function_exists('logActivity')) logActivity($userId, null, 'job_stop_not_recorded', "Visit {$visitId}: " . $when['note']);
                echo json_encode(['success' => true, 'recorded' => false, 'visit_id' => $visitId, 'message' => $when['note']]);
                break;
            }
            if ($when['note']) $notes = trim(((string)$notes) . ' ' . $when['note']);

            $duration = stopVisitTimer($visitId, $userId, $lat, $lng, $notes, (bool)$completeVisit,
                                       $when['from_phone'] ? $when['at'] : null);

            // Persist timed extra work on the visit so it is never lost when the
            // crew complete without invoicing. The invoice create flow reads these
            // back and itemises them. Calc is the single source in the service.
            if ($extrasMinutes > 0 || $extrasNote !== '') {
                try {
                    require_once APP_ROOT . '/Modules/Invoices/Services/InvoiceFromVisitService.php';
                    $extras       = (new InvoiceFromVisitService($db ?? getDB()))->computeExtrasAmount($extrasMinutes);
                    if (!isset($db)) $db = getDB();
                    $db->prepare("UPDATE job_visits SET extras_minutes = ?, extras_amount = ?, extras_note = ? WHERE id = ?")
                       ->execute([$extrasMinutes, $extras['amount'], ($extrasNote !== '' ? $extrasNote : null), $visitId]);
                } catch (Throwable $exEx) {
                    error_log('timer.php extras persist failed: ' . $exEx->getMessage());
                }
            }
            // Special-request extra work goes on top of the sheet's extras (once; never throws).
            if ($completeVisit) {
                require_once APP_ROOT . '/Modules/Operations/Services/SpecialRequestGate.php';
                SpecialRequestGate::afterCompletion($db ?? getDB(), $visitId);
            }

            $responseStop = json_encode([
                'success'          => true,
                'message'          => 'Job completed',
                'visit_id'         => $visitId,
                'duration_minutes' => $duration,
                'end_time'         => $when['at'],
                'time_flag'        => $when['flag'],
                'visit_completed'  => (bool)$completeVisit,
            ]);
            if ($idempKey) {
                if (!isset($db)) $db = getDB();
                idempotencyStore($db, $idempKey, $userId, 'timer', 'stop', $responseStop);
            }
            echo $responseStop;
            break;

        case 'skip':
            $visitId = (int)($input['visit_id'] ?? 0);
            if (!$visitId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'visit_id is required']);
                exit;
            }

            $db = getDB();
            if ($idempKey) {
                $cached = idempotencyCheck($db, $idempKey, $userId);
                if ($cached !== null) { echo $cached; exit; }
            }

            if (!userMayActOnVisit($visitId, $userId)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'You are not assigned to this visit']);
                exit;
            }

            // updateVisitStatus() lives behind plan-functions, which this endpoint
            // does not load up front (see ensurePlanFunctionsLoaded()).
            ensurePlanFunctionsLoaded();
            $result = VisitLifecycleService::skipVisit($visitId, $userId, (string)($input['reason'] ?? ''));

            $responseSkip = json_encode([
                'success'  => $result['success'],
                'message'  => $result['message'],
                'visit_id' => $visitId,
            ]);
            if ($idempKey && $result['success']) {
                idempotencyStore($db, $idempKey, $userId, 'timer', 'skip', $responseSkip);
            }
            echo $responseSkip;
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Unknown action: {$action}"]);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . ApiResponse::message($e, 'timer')]);
}
