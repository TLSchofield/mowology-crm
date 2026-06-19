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
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once APP_ROOT . '/Core/IdempotencyHelper.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once CRM_INCLUDES . '/timeclock-functions.php';

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];

    // Idempotency key — supplied by iOS/Capacitor clients as a UUID per action attempt.
    $idempKey = trim($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = trim($_GET['action'] ?? '');
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

            // Return cached response if this key was already processed.
            if ($idempKey) {
                $db = getDB();
                $cached = idempotencyCheck($db, $idempKey, $userId);
                if ($cached !== null) { echo $cached; exit; }
            }

            $lat         = isset($input['lat']) ? (float)$input['lat'] : null;
            $lng         = isset($input['lng']) ? (float)$input['lng'] : null;
            $autoStarted = !empty($input['auto_started']);

            $entryId = startVisitTimer($visitId, $userId, $lat, $lng, $autoStarted);

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
                     (user_id, clock_in, clock_in_lat, clock_in_lng, status, created_at, updated_at)
                     VALUES (?, NOW(), ?, ?, 'active', NOW(), NOW())"
                )->execute([$userId, $lat, $lng]);
                $autoClockIn = true;
            }

            $responseStart = json_encode([
                'success'       => true,
                'message'       => 'Job started',
                'entry_id'      => $entryId,
                'visit_id'      => $visitId,
                'start_time'    => date('Y-m-d H:i:s'),
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

            $lat           = isset($input['lat']) ? (float)$input['lat'] : null;
            $lng           = isset($input['lng']) ? (float)$input['lng'] : null;
            $notes         = $input['notes'] ?? null;
            $completeVisit = $input['complete_visit'] ?? true;
            $extrasMinutes = max(0, (int)($input['extras_minutes'] ?? 0));
            $extrasNote    = trim((string)($input['extras_note'] ?? ''));

            $duration = stopVisitTimer($visitId, $userId, $lat, $lng, $notes, (bool)$completeVisit);

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

            $responseStop = json_encode([
                'success'          => true,
                'message'          => 'Job completed',
                'visit_id'         => $visitId,
                'duration_minutes' => $duration,
                'visit_completed'  => (bool)$completeVisit,
            ]);
            if ($idempKey) {
                if (!isset($db)) $db = getDB();
                idempotencyStore($db, $idempKey, $userId, 'timer', 'stop', $responseStop);
            }
            echo $responseStop;
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Unknown action: {$action}"]);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
