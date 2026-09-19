<?php
/**
 * API: Save crew work schedule for an employee.
 * Accepts JSON POST, replaces all schedule rows for the given user.
 *
 * Request body:
 *   { user_id: int, csrf_token: string, days: { "1": { start, end, notes }, ... } }
 *   Keys in days are day_of_week integers (0=Sun … 6=Sat).
 *   Days not present are treated as not working (their rows will be deleted).
 *
 * Response: { success: bool, error?: string }
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 6; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/public/loginAuth/auth.php')) {
        require_once $__dir . '/public/loginAuth/auth.php';
        break;
    }
}

try {
    requireLogin();
    $currentUser = getCurrentUser();

    if (!userHasPermission('timer.override')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
        exit;
    }

    if (empty($data['csrf_token']) || !verifyCSRFToken($data['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'CSRF token invalid']);
        exit;
    }

    $userId = (int)($data['user_id'] ?? 0);
    if (!$userId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing user_id']);
        exit;
    }

    $db = getDB();

    // Verify the employee exists
    $stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    if (!$stmt->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Employee not found']);
        exit;
    }

    $days = is_array($data['days'] ?? null) ? $data['days'] : [];

    // Validate each day entry
    $validDows = [0,1,2,3,4,5,6];
    foreach ($days as $dow => $entry) {
        $dow = (int)$dow;
        if (!in_array($dow, $validDows, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Invalid day_of_week: $dow"]);
            exit;
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $entry['start'] ?? '') ||
            !preg_match('/^\d{2}:\d{2}$/', $entry['end'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Invalid time for day $dow"]);
            exit;
        }
        $startMin = (int)substr($entry['start'], 0, 2) * 60 + (int)substr($entry['start'], 3, 2);
        $endMin   = (int)substr($entry['end'],   0, 2) * 60 + (int)substr($entry['end'],   3, 2);
        if ($endMin <= $startMin) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "End time must be after start time for day $dow"]);
            exit;
        }
    }

    $db->beginTransaction();

    // Delete existing rows for this user
    $stmt = $db->prepare("DELETE FROM crew_work_schedules WHERE user_id = ?");
    $stmt->execute([$userId]);

    // Insert new rows
    if (!empty($days)) {
        $insert = $db->prepare("
            INSERT INTO crew_work_schedules (user_id, day_of_week, start_time, end_time, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($days as $dow => $entry) {
            $insert->execute([
                $userId,
                (int)$dow,
                $entry['start'] . ':00',
                $entry['end']   . ':00',
                isset($entry['notes']) ? substr(trim($entry['notes']), 0, 255) : null,
                $currentUser['id'],
            ]);
        }
    }

    $db->commit();

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
