<?php
/**
 * Job Timer Entry Edit API — Void or correct job_time_entries rows
 * POST JSON: { action, csrf_token, entry_id, ... }
 *
 * Actions:
 *   void         — Soft-delete: set status='void', recalculate timesheet
 *   set_duration — Override duration_minutes (admin correction of runaway timers)
 *
 * Admin/Manager only. Recalculates weekly timesheet totals after every change.
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

    requireLogin();
    $actor = getCurrentUser();

    if (!in_array($actor['role'], ['admin', 'manager'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
        exit;
    }

    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $action    = $body['action']     ?? '';
    $csrfToken = $body['csrf_token'] ?? '';

    if (!verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid security token']);
        exit;
    }

    if (!in_array($action, ['void', 'set_duration'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
    }

    $entryId = (int)($body['entry_id'] ?? 0);
    if (!$entryId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'entry_id is required']);
        exit;
    }

    $db = getDB();

    // Load entry — need user_id and start_time to recalculate the right week
    $stmt = $db->prepare("SELECT jte.*, jv.visit_number FROM job_time_entries jte JOIN job_visits jv ON jte.visit_id = jv.id WHERE jte.id = ?");
    $stmt->execute([$entryId]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$entry || $entry['status'] === 'void') {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Entry not found or already voided']);
        exit;
    }

    $userId    = (int)$entry['user_id'];
    $weekStart = date('Y-m-d', strtotime('monday this week', strtotime($entry['start_time'])));
    if (strtotime($weekStart) > strtotime($entry['start_time'])) {
        $weekStart = date('Y-m-d', strtotime('monday last week', strtotime($entry['start_time'])));
    }

    // ── VOID ─────────────────────────────────────────────────────────────────────
    if ($action === 'void') {
        $db->prepare("
            UPDATE job_time_entries
            SET status     = 'void',
                notes      = CONCAT(COALESCE(notes, ''), ' [voided by admin: ' , ? , ']')
            WHERE id = ?
        ")->execute([$actor['full_name'] ?? $actor['email'], $entryId]);

        // Also reset actual_duration_minutes on the visit to reflect remaining entries
        $db->prepare("
            UPDATE job_visits
            SET actual_duration_minutes = (
                SELECT COALESCE(SUM(duration_minutes), 0)
                FROM job_time_entries
                WHERE visit_id = ? AND status IN ('completed', 'edited')
            )
            WHERE id = ?
        ")->execute([$entry['visit_id'], $entry['visit_id']]);

        // Log the void
        try {
            $db->prepare("
                INSERT INTO visit_audit_log (visit_id, user_id, action, payload_json, ip_address)
                VALUES (?, ?, 'job_time_entry_voided', ?, ?)
            ")->execute([
                $entry['visit_id'],
                $actor['id'],
                json_encode(['entry_id' => $entryId, 'duration_minutes' => $entry['duration_minutes']]),
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);
        } catch (Throwable $le) {}

        recalculateTimesheetTotals($userId, $weekStart);

        echo json_encode(['success' => true, 'action' => 'void', 'entry_id' => $entryId]);
        exit;
    }

    // ── SET_DURATION ──────────────────────────────────────────────────────────────
    if ($action === 'set_duration') {
        $newMinutes = (int)($body['duration_minutes'] ?? -1);
        if ($newMinutes < 0 || $newMinutes > 720) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'duration_minutes must be 0–720']);
            exit;
        }

        $db->prepare("
            UPDATE job_time_entries
            SET duration_minutes = ?,
                status           = 'edited',
                flagged_anomaly  = 0,
                notes            = CONCAT(COALESCE(notes, ''), ' [duration corrected by admin: ' , ? , ']')
            WHERE id = ?
        ")->execute([$newMinutes, $actor['full_name'] ?? $actor['email'], $entryId]);

        $db->prepare("
            UPDATE job_visits
            SET actual_duration_minutes = (
                SELECT COALESCE(SUM(duration_minutes), 0)
                FROM job_time_entries
                WHERE visit_id = ? AND status IN ('completed', 'edited')
            )
            WHERE id = ?
        ")->execute([$entry['visit_id'], $entry['visit_id']]);

        try {
            $db->prepare("
                INSERT INTO visit_audit_log (visit_id, user_id, action, payload_json, ip_address)
                VALUES (?, ?, 'job_time_entry_corrected', ?, ?)
            ")->execute([
                $entry['visit_id'],
                $actor['id'],
                json_encode(['entry_id' => $entryId, 'old_minutes' => $entry['duration_minutes'], 'new_minutes' => $newMinutes]),
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);
        } catch (Throwable $le) {}

        recalculateTimesheetTotals($userId, $weekStart);

        echo json_encode(['success' => true, 'action' => 'set_duration', 'entry_id' => $entryId, 'duration_minutes' => $newMinutes]);
        exit;
    }

} catch (Throwable $e) {
    http_response_code(500);
    error_log('[job-time-entry-edit] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
