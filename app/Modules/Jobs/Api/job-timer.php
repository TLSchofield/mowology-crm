<?php
/**
 * Visit Timer API — Start / Stop / Pause / Active status
 * POST: {action: 'start', visit_id, lat?, lng?, auto_started?}
 * POST: {action: 'stop', visit_id, lat?, lng?, notes?, complete_visit?}
 * POST: {action: 'pause', visit_id, lat?, lng?}
 * GET:  ?action=active
 */
declare(strict_types=1);
header('Content-Type: application/json');

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
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
    require_once CRM_INCLUDES . '/timeclock-functions.php';
    require_once CRM_INCLUDES . '/plan-functions.php';

    // Load location functions if available
    $locFuncsPath = CRM_INCLUDES . '/location-functions.php';
    if (file_exists($locFuncsPath)) {
        require_once $locFuncsPath;
    }

    requireLogin();
    $user = getCurrentUser();
    requirePermission('timer.start');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? '';
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
    }

    switch ($action) {
        case 'active':
            $activeTimer = getActiveVisitTimer($user['id']);
            echo json_encode([
                'success' => true,
                'active_timer' => $activeTimer ? [
                    'id' => (int)$activeTimer['id'],
                    'visit_id' => (int)$activeTimer['visit_id'],
                    'job_title' => $activeTimer['job_title'],
                    'job_number' => $activeTimer['job_number'],
                    'property_address' => $activeTimer['property_address'],
                    'company_name' => $activeTimer['company_name'],
                    'start_time' => $activeTimer['start_time'],
                    'elapsed_seconds' => time() - strtotime($activeTimer['start_time']),
                ] : null,
            ]);
            break;

        case 'start':
            $visitId = (int)($input['visit_id'] ?? 0);
            if (!$visitId) {
                throw new Exception('visit_id is required');
            }

            $lat = isset($input['lat']) ? (float)$input['lat'] : null;
            $lng = isset($input['lng']) ? (float)$input['lng'] : null;
            $autoStarted = !empty($input['auto_started']);

            $entryId = startVisitTimer($visitId, $user['id'], $lat, $lng, $autoStarted);

            // Resolve tracking requirements for this visit (product defaults + plan overrides)
            $trackingReqs = [];
            if (function_exists('resolveTrackingRequirements')) {
                $trackingReqs = resolveTrackingRequirements($visitId);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Visit timer started',
                'entry_id' => $entryId,
                'visit_id' => $visitId,
                'start_time' => date('Y-m-d H:i:s'),
                'auto_started' => $autoStarted,
                'tracking_level' => $trackingReqs['tracking_level'] ?? 'standard',
                'require_photos' => (bool)($trackingReqs['require_photos'] ?? false),
                'require_gps' => (bool)($trackingReqs['require_gps'] ?? false),
                'require_clock_in' => (bool)($trackingReqs['require_clock_in'] ?? false),
            ]);
            break;

        case 'stop':
            $visitId = (int)($input['visit_id'] ?? 0);
            if (!$visitId) {
                throw new Exception('visit_id is required');
            }

            $lat = isset($input['lat']) ? (float)$input['lat'] : null;
            $lng = isset($input['lng']) ? (float)$input['lng'] : null;
            $notes = $input['notes'] ?? null;
            $completeVisit = $input['complete_visit'] ?? true;

            $duration = stopVisitTimer($visitId, $user['id'], $lat, $lng, $notes, (bool)$completeVisit);

            echo json_encode([
                'success' => true,
                'message' => 'Visit timer stopped',
                'visit_id' => $visitId,
                'duration_minutes' => $duration,
                'duration_formatted' => formatMinutesAsHours($duration),
                'visit_completed' => (bool)$completeVisit,
            ]);
            break;

        case 'pause':
            $visitId = (int)($input['visit_id'] ?? 0);
            if (!$visitId) {
                throw new Exception('visit_id is required');
            }

            $lat = isset($input['lat']) ? (float)$input['lat'] : null;
            $lng = isset($input['lng']) ? (float)$input['lng'] : null;

            $duration = pauseVisitTimer($visitId, $user['id'], $lat, $lng);

            echo json_encode([
                'success' => true,
                'message' => 'Visit timer paused',
                'visit_id' => $visitId,
                'duration_minutes' => $duration,
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use: active, start, stop, pause']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
