<?php
/**
 * POST /crm/api/reschedule-visit.php
 *
 * Changes the scheduled_date of a job visit.
 * Admin / jobs.edit permission required.
 * Visit must not be locked or completed.
 *
 * Body (JSON): { visit_id: int, new_date: "YYYY-MM-DD", csrf_token: string }
 * Response:    { success: bool, message?: string, new_date?: string }
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/../../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
requirePermission('jobs.edit');

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$visitId = (int)($input['visit_id'] ?? 0);
$newDate  = trim($input['new_date'] ?? '');

if (!$visitId || $newDate === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing visit ID or date.']);
    exit;
}

// Validate YYYY-MM-DD and calendar validity
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid date format.']);
    exit;
}
[$y, $m, $d] = array_map('intval', explode('-', $newDate));
if (!checkdate($m, $d, $y)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid calendar date.']);
    exit;
}

$db = getDB();

$stmt = $db->prepare("SELECT id, scheduled_date, status, locked_at FROM job_visits WHERE id = ?");
$stmt->execute([$visitId]);
$visit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$visit) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Visit not found.']);
    exit;
}

if ($visit['locked_at'] !== null) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Visit is locked and cannot be rescheduled.']);
    exit;
}

if ($visit['status'] === 'completed') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Completed visits cannot be rescheduled.']);
    exit;
}

$oldDate = $visit['scheduled_date'];

if ($newDate === $oldDate) {
    echo json_encode(['success' => true, 'new_date' => $newDate]);
    exit;
}

$db->prepare("UPDATE job_visits SET scheduled_date = ? WHERE id = ?")->execute([$newDate, $visitId]);

$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
$db->prepare("
    INSERT INTO visit_audit_log (visit_id, user_id, action, payload_json, ip_address)
    VALUES (?, ?, ?, ?, ?)
")->execute([
    $visitId,
    (int)$user['id'],
    'rescheduled',
    json_encode(['from' => $oldDate, 'to' => $newDate]),
    $ip ? substr($ip, 0, 45) : null,
]);

echo json_encode(['success' => true, 'new_date' => $newDate]);
