<?php
/**
 * Job Timer API — Start / Stop / Pause / Active status
 * POST: {action: 'start', job_id, lat?, lng?, auto_started?}
 * POST: {action: 'stop', job_id, lat?, lng?, notes?, complete_job?}
 * POST: {action: 'pause', job_id, lat?, lng?}
 * GET:  ?action=active
 */
declare(strict_types=1);
header('Content-Type: application/json');

try {
    require_once dirname(__DIR__) . '/../loginAuth/auth.php';
    require_once dirname(__DIR__) . '/includes/functions.php';
    require_once dirname(__DIR__) . '/includes/timeclock-functions.php';

    // Load location functions if available
    $locFuncsPath = dirname(__DIR__) . '/includes/location-functions.php';
    if (file_exists($locFuncsPath)) {
        require_once $locFuncsPath;
    }

    requireLogin();
    $user = getCurrentUser();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? '';
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
    }

    switch ($action) {
        case 'active':
            $activeTimer = getActiveJobTimer($user['id']);
            echo json_encode([
                'success' => true,
                'active_timer' => $activeTimer ? [
                    'id' => (int)$activeTimer['id'],
                    'job_id' => (int)$activeTimer['job_id'],
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
            $jobId = (int)($input['job_id'] ?? 0);
            if (!$jobId) {
                throw new Exception('job_id is required');
            }

            $lat = isset($input['lat']) ? (float)$input['lat'] : null;
            $lng = isset($input['lng']) ? (float)$input['lng'] : null;
            $autoStarted = !empty($input['auto_started']);

            $entryId = startJobTimer($jobId, $user['id'], $lat, $lng, $autoStarted);

            echo json_encode([
                'success' => true,
                'message' => 'Job timer started',
                'entry_id' => $entryId,
                'job_id' => $jobId,
                'start_time' => date('Y-m-d H:i:s'),
                'auto_started' => $autoStarted,
            ]);
            break;

        case 'stop':
            $jobId = (int)($input['job_id'] ?? 0);
            if (!$jobId) {
                throw new Exception('job_id is required');
            }

            $lat = isset($input['lat']) ? (float)$input['lat'] : null;
            $lng = isset($input['lng']) ? (float)$input['lng'] : null;
            $notes = $input['notes'] ?? null;
            $completeJob = $input['complete_job'] ?? true;

            $duration = stopJobTimer($jobId, $user['id'], $lat, $lng, $notes, (bool)$completeJob);

            echo json_encode([
                'success' => true,
                'message' => 'Job timer stopped',
                'job_id' => $jobId,
                'duration_minutes' => $duration,
                'duration_formatted' => formatMinutesAsHours($duration),
                'job_completed' => (bool)$completeJob,
            ]);
            break;

        case 'pause':
            $jobId = (int)($input['job_id'] ?? 0);
            if (!$jobId) {
                throw new Exception('job_id is required');
            }

            $lat = isset($input['lat']) ? (float)$input['lat'] : null;
            $lng = isset($input['lng']) ? (float)$input['lng'] : null;

            $duration = pauseJobTimer($jobId, $user['id'], $lat, $lng);

            echo json_encode([
                'success' => true,
                'message' => 'Job timer paused',
                'job_id' => $jobId,
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
