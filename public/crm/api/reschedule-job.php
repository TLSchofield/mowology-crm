<?php
/**
 * Schedule Job via Drag-and-Drop
 *
 * Receives a POST request with job ID and new date/time
 * Updates the database and returns success/failure status
 *
 * POST /crm/api/reschedule-job.php
 * {
 *   "job_id": 123,
 *   "scheduled_date": "2026-02-10",
 *   "scheduled_time_start": "09:00:00",
 *   "scheduled_time_end": "11:00:00"
 * }
 */

declare(strict_types=1);
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get JSON payload
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$jobId = isset($input['job_id']) ? (int)$input['job_id'] : null;
$newDate = isset($input['scheduled_date']) ? $input['scheduled_date'] : null;
$newTimeStart = isset($input['scheduled_time_start']) ? $input['scheduled_time_start'] : null;
$newTimeEnd = isset($input['scheduled_time_end']) ? $input['scheduled_time_end'] : null;

// Validate input
if (!$jobId || !$newDate) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: job_id, scheduled_date']);
    exit;
}

// Validate date format (YYYY-MM-DD)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD']);
    exit;
}

// Validate time format if provided (HH:MM:SS)
if ($newTimeStart && !preg_match('/^\d{2}:\d{2}:\d{2}$/', $newTimeStart)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid time format. Use HH:MM:SS']);
    exit;
}

try {
    $db = getDB();

    // Verify job exists and user has permission to modify
    $stmt = $db->prepare("SELECT id, company_id, assigned_to FROM jobs WHERE id = ?");
    $stmt->execute([$jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        http_response_code(404);
        echo json_encode(['error' => 'Job not found']);
        exit;
    }

    // Basic permission check: user must be admin or assigned to job
    if ($user['role'] !== 'admin' && $job['assigned_to'] != $user['id']) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have permission to reschedule this job']);
        exit;
    }

    // Update job with new date/time
    $updateFields = ['scheduled_date = ?'];
    $params = [$newDate];

    if ($newTimeStart) {
        $updateFields[] = 'scheduled_time_start = ?';
        $params[] = $newTimeStart;
    }

    if ($newTimeEnd) {
        $updateFields[] = 'scheduled_time_end = ?';
        $params[] = $newTimeEnd;
    }

    // Add timestamp for audit trail
    $updateFields[] = 'updated_at = NOW()';

    // Add job ID as final parameter
    $params[] = $jobId;

    $updateQuery = "UPDATE jobs SET " . implode(', ', $updateFields) . " WHERE id = ?";
    $stmt = $db->prepare($updateQuery);

    if (!$stmt->execute($params)) {
        throw new Exception('Database update failed');
    }

    // Log the reschedule action
    logActivity($db, $user['id'], 'job_rescheduled', "Job {$jobId} rescheduled to {$newDate}" . ($newTimeStart ? " at {$newTimeStart}" : ""), $jobId);

    // Return updated job data
    $stmt = $db->prepare("
        SELECT
            id,
            job_number,
            title,
            scheduled_date,
            scheduled_time_start,
            scheduled_time_end,
            status
        FROM jobs
        WHERE id = ?
    ");
    $stmt->execute([$jobId]);
    $updatedJob = $stmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Job rescheduled successfully',
        'job' => $updatedJob
    ]);

} catch (PDOException $e) {
    error_log("Database error during job reschedule: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("Error during job reschedule: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Log activity to activity_log table
 */
function logActivity($db, $userId, $actionType, $description, $jobId = null) {
    try {
        $stmt = $db->prepare("
            INSERT INTO activity_log (user_id, action_type, description, job_id, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $actionType, $description, $jobId]);
    } catch (Exception $e) {
        // Silently fail logging to not interrupt main operation
        error_log("Failed to log activity: " . $e->getMessage());
    }
}
