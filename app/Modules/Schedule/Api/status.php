<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/status.php
 *
 * GET /api/schedule/status  (resolved via ?action=status from scheduleClockStatus iOS endpoint)
 * Authorization: Bearer <jwt>
 *
 * Returns the current clock-in state for the authenticated crew member.
 *
 * Response 200:
 * {
 *   "success": true,
 *   "clocked_in": false,
 *   "entry_id": null,
 *   "clock_in": null,
 *   "elapsed_seconds": null,
 *   "active_job": null
 * }
 *
 * When clocked in:
 * {
 *   "success": true,
 *   "clocked_in": true,
 *   "entry_id": 42,
 *   "clock_in": "2026-05-03 08:00:00",
 *   "elapsed_seconds": 3600,
 *   "active_job": {
 *     "id": 7, "visit_id": 7, "job_title": "Lawn Maintenance",
 *     "property_address": "123 Main St", "start_time": "2026-05-03 08:05:00",
 *     "elapsed_seconds": 3300
 *   }
 * }
 *
 * ROUTING NOTE
 * ────────────
 * The iOS scheduleClockStatus endpoint sends:
 *   GET /api/schedule/clock?action=status
 *
 * The .htaccess rewrite turns this into:
 *   index.php?module=schedule&action=clock&action=status
 *
 * PHP picks up the LAST value for duplicate query params, so $action = 'status'.
 * This file is correctly named status.php to match that resolved action.
 * Do NOT rename it to clock.php — that would conflict with clock.php (POST).
 *
 * PRODUCTION SAFETY
 * ─────────────────
 * This is a read-only endpoint — it writes nothing. Safe to retry.
 * elapsed_seconds is calculated server-side via TIMESTAMPDIFF — the client
 * clock is not trusted. The iOS UI starts a local tick timer from this value.
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────
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

require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
require_once APP_ROOT . '/Modules/Team/Services/TimeclockFunctions.php';

$jwtUser = requireJwt();
$userId  = (int)$jwtUser['id'];

try {
    $entry     = getActiveClockEntry($userId);
    $activeJob = getActiveJobTimer($userId);

    if ($entry) {
        http_response_code(200);
        echo json_encode([
            'success'         => true,
            'clocked_in'      => true,
            'entry_id'        => (int)$entry['id'],
            'clock_in'        => $entry['clock_in'],
            'elapsed_seconds' => max(0, (int)$entry['elapsed_seconds']),
            'active_job'      => $activeJob ? [
                'id'               => (int)$activeJob['id'],
                'visit_id'         => (int)$activeJob['visit_id'],
                'job_title'        => $activeJob['job_title'],
                'property_address' => $activeJob['property_address'],
                'start_time'       => $activeJob['start_time'],
                'elapsed_seconds'  => max(0, (int)$activeJob['elapsed_seconds']),
            ] : null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        http_response_code(200);
        echo json_encode([
            'success'         => true,
            'clocked_in'      => false,
            'entry_id'        => null,
            'clock_in'        => null,
            'elapsed_seconds' => null,
            'active_job'      => null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

} catch (Throwable $e) {
    error_log('[schedule/status] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to load clock status.']);
}
