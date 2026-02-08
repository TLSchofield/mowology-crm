<?php
/**
 * Simplified Reschedule API for testing
 */
declare(strict_types=1);
header('Content-Type: application/json');

try {
    require_once dirname(__DIR__) . '/../loginAuth/auth.php';
    require_once dirname(__DIR__) . '/includes/functions.php';

    requireLogin();
    $user = getCurrentUser();

    // Get input
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['job_id']) || !isset($input['scheduled_date'])) {
        throw new Exception('Missing required fields');
    }

    $jobId = (int)$input['job_id'];
    $newDate = $input['scheduled_date'];
    $newTime = $input['scheduled_time_start'] ?? null;

    // Validate formats
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate)) {
        throw new Exception('Invalid date format');
    }

    if ($newTime && !preg_match('/^\d{2}:\d{2}:\d{2}$/', $newTime)) {
        throw new Exception('Invalid time format');
    }

    // Get database connection
    $db = getDB();

    // Verify job exists
    $stmt = $db->prepare("SELECT id FROM jobs WHERE id = ?");
    $stmt->execute([$jobId]);
    if (!$stmt->fetch()) {
        throw new Exception('Job not found');
    }

    // Update job
    if ($newTime) {
        $stmt = $db->prepare("UPDATE jobs SET scheduled_date = ?, scheduled_time_start = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newDate, $newTime, $jobId]);
    } else {
        $stmt = $db->prepare("UPDATE jobs SET scheduled_date = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newDate, $jobId]);
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Job rescheduled successfully',
        'job_id' => $jobId,
        'new_date' => $newDate,
        'new_time' => $newTime
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
