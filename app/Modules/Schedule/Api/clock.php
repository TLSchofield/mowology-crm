<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/clock.php
 *
 * Mobile Time-Clock API — JWT authenticated
 *
 * GET  /api/schedule/clock?action=status
 *      Returns current clock-in state + active job timer.
 *
 * POST /api/schedule/clock
 *      Body: {"action": "clock_in",  "lat": ..., "lng": ...}
 *      Body: {"action": "clock_out", "lat": ..., "lng": ...}
 *
 * All responses match the exact field names decoded by TimeClockModels.swift.
 *
 * ┌─ PRODUCTION SAFETY ──────────────────────────────────────────────────────┐
 * │  1. JWT-ONLY. This endpoint uses requireJwt() (Bearer token), NOT        │
 * │     requireLogin() (session cookie). Do NOT add session_start() here.    │
 * │     session_write_close() is intentionally absent — no session used.     │
 * │                                                                           │
 * │  2. USER ISOLATION. Every query is scoped to $jwtUser['id']. Never      │
 * │     accept a user_id from the request body — that would let one crew     │
 * │     member clock in/out on behalf of another.                             │
 * │                                                                           │
 * │  3. FUNCTION GUARDS. startJobTimer() and stopJobTimer() call             │
 * │     updateVisitStatus() and logCrewLocation() via function_exists()      │
 * │     because those helpers live in separate include files not loaded        │
 * │     here. This is intentional — the clock endpoint only needs the        │
 * │     core timeclock functions, not the full CRM function library.          │
 * │                                                                           │
 * │  4. IDEMPOTENCY. The endpoint short-circuits when the user is already   │
 * │     in the requested terminal state: clock_in while clocked in returns   │
 * │     the existing entry, clock_out while not clocked in returns success.  │
 * │     This makes a network-loss retry that lands after the server already  │
 * │     processed the first call resolve cleanly with a 200, instead of      │
 * │     surfacing as a 409. Mutations only happen on the first call.         │
 * └───────────────────────────────────────────────────────────────────────────┘
 */

// ── Bootstrap ────────────────────────────────────────────────────────────────
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

// ── Auth & config ────────────────────────────────────────────────────────────
require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Core/Auth/JwtAuth.php';

$jwtUser = requireJwt(); // exits with 401 if token invalid or missing

// ── Load time-clock helpers ──────────────────────────────────────────────────
require_once APP_ROOT . '/Modules/Team/Services/TimeclockFunctions.php';

$userId = (int)$jwtUser['id'];

// ── Helper: build active_job object matching ActiveJobTimer iOS model ────────
function buildActiveJobPayload(?array $timer): ?array {
    if (!$timer) return null;
    return [
        'id'               => (int)$timer['id'],
        'visit_id'         => (int)$timer['visit_id'],
        'job_title'        => $timer['job_title']        ?? null,
        'property_address' => $timer['property_address'] ?? null,
        'start_time'       => $timer['start_time'],
        'elapsed_seconds'  => (int)$timer['elapsed_seconds'],
    ];
}

// ═══════════════════════════════════════════════════════════════════
// GET — status
// ═══════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'status';
    if ($action !== 'status') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action. Use ?action=status']);
        exit;
    }

    try {
        $entry = getActiveClockEntry($userId);
        $timer = $entry ? getActiveJobTimer($userId) : null;

        echo json_encode([
            'success'         => true,
            'clocked_in'      => (bool)$entry,
            'entry_id'        => $entry ? (int)$entry['id'] : null,
            'clock_in'        => $entry ? $entry['clock_in'] : null,
            'elapsed_seconds' => $entry ? (int)$entry['elapsed_seconds'] : null,
            'active_job'      => buildActiveJobPayload($timer ?: null),
            'message'         => null,
        ]);
    } catch (Throwable $e) {
        error_log('[schedule/clock status] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load clock status.']);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════════
// POST — clock_in / clock_out
// ═══════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';
$lat    = isset($body['lat']) ? (float)$body['lat'] : null;
$lng    = isset($body['lng']) ? (float)$body['lng'] : null;

if (!in_array($action, ['clock_in', 'clock_out'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action. Use clock_in or clock_out']);
    exit;
}

// ── Clock In ─────────────────────────────────────────────────────────────────
if ($action === 'clock_in') {
    try {
        // Idempotent fast-path: if already clocked in, return the existing
        // entry as success rather than throwing. A retry that arrives after
        // a previous clock_in succeeded (but whose response was lost mid-flight)
        // resolves cleanly with the user shown as clocked in.
        $existing = getActiveClockEntry($userId);
        if ($existing) {
            $elapsed = (int)($existing['elapsed_seconds'] ?? max(0, time() - strtotime($existing['clock_in'])));
            echo json_encode([
                'success'         => true,
                'clocked_in'      => true,
                'entry_id'        => (int)$existing['id'],
                'clock_in'        => $existing['clock_in'],
                'clock_out'       => null,
                'elapsed_seconds' => $elapsed,
                'total_minutes'   => null,
                'message'         => 'Already clocked in',
            ]);
            exit;
        }

        $entryId = clockIn($userId, $lat, $lng);
        $entry   = getActiveClockEntry($userId);

        echo json_encode([
            'success'         => true,
            'clocked_in'      => true,
            'entry_id'        => $entryId,
            'clock_in'        => $entry ? $entry['clock_in'] : null,
            'clock_out'       => null,
            'elapsed_seconds' => 0,
            'total_minutes'   => null,
            'message'         => 'Clocked in',
        ]);
    } catch (Throwable $e) {
        error_log('[schedule/clock in] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Clock-in failed. Please try again.']);
    }
    exit;
}

// ── Clock Out ────────────────────────────────────────────────────────────────
if ($action === 'clock_out') {
    try {
        // Idempotent fast-path: if not currently clocked in, return success
        // rather than throwing. Covers both "user wasn't clocked in" and
        // "previous clock_out succeeded but the response was lost mid-flight
        // and the iOS client retried." The crew's terminal state is the
        // requested state, so the request is satisfied.
        if (!getActiveClockEntry($userId)) {
            echo json_encode([
                'success'         => true,
                'clocked_in'      => false,
                'entry_id'        => null,
                'clock_in'        => null,
                'clock_out'       => null,
                'elapsed_seconds' => null,
                'total_minutes'   => null,
                'message'         => 'Already clocked out',
            ]);
            exit;
        }

        $totalMinutes = clockOut($userId, $lat, $lng);

        echo json_encode([
            'success'         => true,
            'clocked_in'      => false,
            'entry_id'        => null,
            'clock_in'        => null,
            'clock_out'       => date('Y-m-d H:i:s'),
            'elapsed_seconds' => null,
            'total_minutes'   => $totalMinutes,
            'message'         => 'Clocked out',
        ]);
    } catch (Throwable $e) {
        error_log('[schedule/clock out] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Clock-out failed. Please try again.']);
    }
    exit;
}
