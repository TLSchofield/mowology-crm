<?php
/**
 * Time Entry Edit API — Add / Edit / Void clock entries
 * POST JSON: { action, csrf_token, ... }
 *
 * Actions:
 *   add  — Insert a manual time_clock_entries row for a user/date
 *   edit — Update clock_in / clock_out / notes on an existing entry
 *   void — Soft-delete by setting status = 'void'
 *
 * All actions recalculate every affected week's timesheet totals (old and new week on a move).
 * Admin/Manager only; only an admin may change their OWN time. An approved week is refused
 * (reopen the timesheet first); a manual entry needs a clock-out and may not overlap another
 * entry of the same person (TimeEntryGuard, 2026-10-12).
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
    require_once APP_ROOT . '/Modules/Team/Services/TimeEntryGuard.php';

    requireLogin();
    $actor = getCurrentUser();

    if (!in_array($actor['role'], ['admin', 'manager'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
        exit;
    }

    $body       = json_decode(file_get_contents('php://input'), true) ?? [];
    $action     = $body['action']     ?? '';
    $csrfToken  = $body['csrf_token'] ?? '';

    if (!verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid security token']);
        exit;
    }

    if (!in_array($action, ['add', 'edit', 'void'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
    }

    $db = getDB();

    // ── ADD ──────────────────────────────────────────────────────────────────────
    if ($action === 'add') {
        $userId   = (int)($body['user_id']   ?? 0);
        $clockIn  = $body['clock_in']  ?? '';
        $clockOut = $body['clock_out'] ?? '';
        $notes    = trim($body['notes'] ?? '');

        if (!$userId || !$clockIn) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'user_id and clock_in are required']);
            exit;
        }

        // Validate datetime formats
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $clockIn)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid clock_in format']);
            exit;
        }

        if (!TimeclockRules::canEditTimeOf((string)$actor['role'], (int)$actor['id'], $userId)) {
            _refuse(403, 'Only an admin can change their own time.');
        }
        // A manual entry is a finished shift. An open-ended 'active' insert became a second
        // running shift the clock and the auto clock-out cron then fought over.
        if (!$clockOut) _refuse(400, 'A manual entry needs a clock-out time.');

        // Normalize to MySQL datetime
        $clockIn  = date('Y-m-d H:i:s', strtotime($clockIn));
        $clockOut = date('Y-m-d H:i:s', strtotime($clockOut));

        $totalMin = TimeclockRules::minutesBetween($clockIn, $clockOut);   // real minutes (DST-safe)
        if ($totalMin <= 0) _refuse(400, 'clock_out must be after clock_in');

        if ($why = TimeEntryGuard::refusal($db, $userId, [$clockIn])) _refuse(409, $why);
        if ($clash = TimeEntryGuard::overlapping($db, $userId, $clockIn, $clockOut)) {
            _refuse(409, 'That overlaps another shift (' . date('M j g:i a', strtotime($clash['clock_in']))
                . ($clash['clock_out'] ? ' – ' . date('g:i a', strtotime($clash['clock_out'])) : ', still running') . '). Edit that one instead.');
        }

        $status = 'completed';

        $stmt = $db->prepare("
            INSERT INTO time_clock_entries
                (user_id, clock_in, clock_out, total_minutes, notes, status, edited_by, edited_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $clockIn, $clockOut, $totalMin, $notes ?: null, $status, $actor['id']]);

        $weekStart = TimeclockRules::weekStart($clockIn);
        TimeEntryGuard::recalcWeeks($userId, [$clockIn]);

        echo json_encode([
            'success'    => true,
            'week_start' => $weekStart,
            'timesheet'  => _fetchTimesheetTotals($db, $userId, $weekStart),
        ]);
        exit;
    }

    // ── EDIT ─────────────────────────────────────────────────────────────────────
    if ($action === 'edit') {
        $entryId  = (int)($body['entry_id'] ?? 0);
        $clockIn  = $body['clock_in']  ?? '';
        $clockOut = $body['clock_out'] ?? '';
        $notes    = trim($body['notes'] ?? '');

        if (!$entryId || !$clockIn) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'entry_id and clock_in are required']);
            exit;
        }

        // Load existing entry to get user_id / week
        $stmt = $db->prepare("SELECT * FROM time_clock_entries WHERE id = ?");
        $stmt->execute([$entryId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$entry || $entry['status'] === 'void') {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Entry not found or already voided']);
            exit;
        }

        $userId = (int)$entry['user_id'];
        if (!TimeclockRules::canEditTimeOf((string)$actor['role'], (int)$actor['id'], $userId)) {
            _refuse(403, 'Only an admin can change their own time.');
        }
        // Only a shift that is still running may stay open; a finished one keeps a clock-out.
        if (!$clockOut && $entry['status'] !== 'active') _refuse(400, 'A finished shift needs a clock-out time.');

        $clockIn  = date('Y-m-d H:i:s', strtotime($clockIn));
        $clockOut = $clockOut ? date('Y-m-d H:i:s', strtotime($clockOut)) : null;

        $totalMin = null;
        if ($clockOut) {
            $totalMin = TimeclockRules::minutesBetween($clockIn, $clockOut);   // real minutes (DST-safe)
            if ($totalMin < 0) _refuse(400, 'clock_out must be after clock_in');
        }

        // Both the week it was in and the week it moves to must be open.
        if ($why = TimeEntryGuard::refusal($db, $userId, [$entry['clock_in'], $clockIn])) _refuse(409, $why);
        if ($clash = TimeEntryGuard::overlapping($db, $userId, $clockIn, $clockOut, $entryId)) {
            _refuse(409, 'That overlaps another shift (' . date('M j g:i a', strtotime($clash['clock_in']))
                . ($clash['clock_out'] ? ' – ' . date('g:i a', strtotime($clash['clock_out'])) : ', still running') . ').');
        }

        $status = $clockOut ? 'edited' : 'active';

        $stmt = $db->prepare("
            UPDATE time_clock_entries
            SET clock_in = ?, clock_out = ?, total_minutes = ?,
                notes = ?, status = ?, edited_by = ?, edited_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$clockIn, $clockOut, $totalMin, $notes ?: null, $status, $actor['id'], $entryId]);

        // Recalculate the week it left AND the week it landed in (an edit can move it across).
        $weekStart = TimeclockRules::weekStart($clockIn);
        TimeEntryGuard::recalcWeeks($userId, [$entry['clock_in'], $clockIn]);

        echo json_encode([
            'success'    => true,
            'week_start' => $weekStart,
            'timesheet'  => _fetchTimesheetTotals($db, $userId, $weekStart),
        ]);
        exit;
    }

    // ── VOID ─────────────────────────────────────────────────────────────────────
    if ($action === 'void') {
        $entryId = (int)($body['entry_id'] ?? 0);
        if (!$entryId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'entry_id required']);
            exit;
        }

        $stmt = $db->prepare("SELECT * FROM time_clock_entries WHERE id = ?");
        $stmt->execute([$entryId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$entry || $entry['status'] === 'void') {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Entry not found or already voided']);
            exit;
        }

        if (!TimeclockRules::canEditTimeOf((string)$actor['role'], (int)$actor['id'], (int)$entry['user_id'])) {
            _refuse(403, 'Only an admin can change their own time.');
        }
        if ($why = TimeEntryGuard::refusal($db, (int)$entry['user_id'], [$entry['clock_in']])) _refuse(409, $why);

        $stmt = $db->prepare("
            UPDATE time_clock_entries
            SET status = 'void', edited_by = ?, edited_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$actor['id'], $entryId]);

        $userId    = (int)$entry['user_id'];
        $weekStart = date('Y-m-d', strtotime('monday this week', strtotime($entry['clock_in'])));
        recalculateTimesheetTotals($userId, $weekStart);

        echo json_encode([
            'success'    => true,
            'week_start' => $weekStart,
            'timesheet'  => _fetchTimesheetTotals($db, $userId, $weekStart),
        ]);
        exit;
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

// ── Helper ───────────────────────────────────────────────────────────────────────
function _refuse(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function _fetchTimesheetTotals(PDO $db, int $userId, string $weekStart): array
{
    $stmt = $db->prepare("
        SELECT total_shift_minutes, total_job_minutes, total_travel_minutes, status
        FROM timesheets
        WHERE user_id = ? AND week_start = ?
    ");
    $stmt->execute([$userId, $weekStart]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}
