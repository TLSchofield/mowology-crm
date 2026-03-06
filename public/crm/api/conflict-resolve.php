<?php
/**
 * Conflict Resolution API — Actions to resolve route reconciliation conflicts
 *
 * POST actions:
 *   create_time_entry  — Creates a completed time entry for a missed visit
 *   dismiss            — Marks conflict as reviewed, no action needed
 *   enable_auto_clockin — Enables auto clock-in for a plan
 *
 * Auth: admin/manager only
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';

requireLogin();
$user = getCurrentUser();

// Release session lock — POST writes are DB-only
session_write_close();

if (!in_array($user['role'], ['admin', 'manager'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }

    // CSRF check
    if (empty($input['csrf_token']) || !verifyCSRFToken($input['csrf_token'])) {
        throw new Exception('Invalid CSRF token');
    }

    $action = $input['action'] ?? '';
    $db = getDB();
    $tz = new DateTimeZone('America/Vancouver');
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    switch ($action) {

        case 'create_time_entry':
            $visitId   = (int)($input['visit_id'] ?? 0);
            $userId    = (int)($input['user_id'] ?? 0);
            $dateStr   = $input['date'] ?? '';
            $startTime = $input['start_time'] ?? '';
            $endTime   = $input['end_time'] ?? '';
            $notes     = trim($input['notes'] ?? '');

            if (!$visitId || !$userId || !$dateStr || !$startTime || !$endTime) {
                throw new Exception('Missing required fields');
            }

            // Validate visit exists
            $vStmt = $db->prepare("SELECT id, status, plan_id FROM job_visits WHERE id = ?");
            $vStmt->execute([$visitId]);
            $visit = $vStmt->fetch(PDO::FETCH_ASSOC);
            if (!$visit) {
                throw new Exception('Visit not found');
            }

            // Build full datetime strings (date + time)
            $startDt = $dateStr . ' ' . $startTime . ':00';
            $endDt   = $dateStr . ' ' . $endTime . ':00';

            // Calculate duration
            $startEpoch = (new DateTime($startDt, $tz))->getTimestamp();
            $endEpoch   = (new DateTime($endDt, $tz))->getTimestamp();
            if ($endEpoch <= $startEpoch) {
                throw new Exception('End time must be after start time');
            }
            $durationMinutes = (int)round(($endEpoch - $startEpoch) / 60);

            $db->beginTransaction();

            // Insert completed time entry
            $entryStmt = $db->prepare("
                INSERT INTO job_time_entries
                    (visit_id, user_id, start_time, end_time, duration_minutes, auto_started, status, notes)
                VALUES (?, ?, ?, ?, ?, 0, 'completed', ?)
            ");
            $entryStmt->execute([$visitId, $userId, $startDt, $endDt, $durationMinutes, $notes ?: 'Created via conflict resolution']);
            $entryId = (int)$db->lastInsertId();

            // Update visit status to completed if still scheduled
            if ($visit['status'] === 'scheduled' || $visit['status'] === 'in_progress') {
                $db->prepare("
                    UPDATE job_visits SET status = 'completed', completed_at = ? WHERE id = ?
                ")->execute([$endDt, $visitId]);
            }

            // Record resolution
            $db->prepare("
                INSERT INTO conflict_resolutions (visit_id, visit_date, resolution_type, resolved_by, notes)
                VALUES (?, ?, 'time_entry_created', ?, ?)
            ")->execute([$visitId, $dateStr, (int)$user['id'], $notes ?: null]);

            $db->commit();

            // Capture labor costs (non-critical — outside transaction)
            try {
                $vcService = APP_ROOT . '/Modules/Jobs/Services/VisitCompletionService.php';
                if (is_file($vcService)) {
                    require_once $vcService;
                    VisitCompletionService::capture($visitId, $userId);
                }
            } catch (Throwable $e) {
                error_log('[conflict-resolve] VisitCompletionService error: ' . $e->getMessage());
            }

            // Audit log
            try {
                $db->prepare("
                    INSERT INTO visit_audit_log (visit_id, user_id, action, payload_json, ip_address)
                    VALUES (?, ?, 'conflict_resolved', ?, ?)
                ")->execute([
                    $visitId, (int)$user['id'],
                    json_encode(['type' => 'time_entry_created', 'entry_id' => $entryId, 'duration' => $durationMinutes]),
                    $ip ? substr($ip, 0, 45) : null,
                ]);
            } catch (Throwable $e) {
                error_log('[conflict-resolve] Audit log error: ' . $e->getMessage());
            }

            echo json_encode([
                'success'          => true,
                'entry_id'         => $entryId,
                'duration_minutes' => $durationMinutes,
            ]);
            break;

        case 'dismiss':
            $visitId = (int)($input['visit_id'] ?? 0);
            $dateStr = $input['date'] ?? '';
            $notes   = trim($input['notes'] ?? '');

            if (!$visitId || !$dateStr) {
                throw new Exception('Missing required fields');
            }

            $db->prepare("
                INSERT INTO conflict_resolutions (visit_id, visit_date, resolution_type, resolved_by, notes)
                VALUES (?, ?, 'dismissed', ?, ?)
            ")->execute([$visitId, $dateStr, (int)$user['id'], $notes ?: null]);

            // Audit log
            try {
                $db->prepare("
                    INSERT INTO visit_audit_log (visit_id, user_id, action, payload_json, ip_address)
                    VALUES (?, ?, 'conflict_dismissed', ?, ?)
                ")->execute([
                    $visitId, (int)$user['id'],
                    json_encode(['notes' => $notes]),
                    $ip ? substr($ip, 0, 45) : null,
                ]);
            } catch (Throwable $e) {
                error_log('[conflict-resolve] Audit log error: ' . $e->getMessage());
            }

            echo json_encode(['success' => true]);
            break;

        case 'enable_auto_clockin':
            $planId = (int)($input['plan_id'] ?? 0);
            if (!$planId) {
                throw new Exception('Missing plan_id');
            }

            // Check if column exists (defensive)
            try {
                $colCheck = $db->query("SHOW COLUMNS FROM job_plans LIKE 'auto_clock_in_override'");
                if ($colCheck->rowCount() === 0) {
                    throw new Exception('auto_clock_in_override column not available');
                }
            } catch (PDOException $e) {
                throw new Exception('Cannot check plan configuration');
            }

            $db->prepare("UPDATE job_plans SET auto_clock_in_override = 1 WHERE id = ?")->execute([$planId]);

            echo json_encode(['success' => true, 'auto_clock_in' => true]);
            break;

        default:
            throw new Exception('Unknown action: ' . $action);
    }

} catch (Exception $e) {
    if ($db ?? null) {
        try { $db->rollBack(); } catch (Throwable $ignore) {}
    }
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
