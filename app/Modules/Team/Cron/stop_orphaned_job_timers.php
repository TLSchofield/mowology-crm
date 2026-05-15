<?php
/**
 * Orphaned Job Timer Auto-Stop — Hourly Cron
 *
 * Finds job_time_entries that have been active for longer than the configured
 * auto-stop threshold (default 12 hours) and closes them, capping the duration
 * at the crew member's shift time for that day so payroll is never inflated.
 *
 * CLI:  /usr/local/bin/php /home/mowology/public_html/app/Modules/Team/Cron/stop_orphaned_job_timers.php
 * Web:  POST /crm/timeclock/stop-orphaned-timers (admin only)
 *
 * CPanel recommended schedule: every hour (0 * * * *)
 */

declare(strict_types=1);

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

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST method required']);
        exit;
    }

    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    requireLogin();
    $user = getCurrentUser();

    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        exit;
    }

    header('Content-Type: application/json');
} else {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
}

require_once APP_ROOT . '/Modules/Team/Services/TimeclockFunctions.php';

$db = getDB();

// Threshold: timers running longer than this many hours are considered orphaned.
$thresholdHours = 12;

// Find all active job timer entries older than the threshold.
$stmt = $db->prepare("
    SELECT jte.id, jte.visit_id, jte.user_id, jte.start_time, jte.clock_entry_id,
           jv.visit_number,
           COALESCE(tce.total_minutes,
               CASE
                   WHEN tce.status = 'active' THEN TIMESTAMPDIFF(MINUTE, tce.clock_in, NOW())
                   ELSE NULL
               END
           ) AS shift_max_minutes,
           TIMESTAMPDIFF(MINUTE, jte.start_time, NOW()) AS raw_minutes
    FROM job_time_entries jte
    JOIN job_visits jv ON jte.visit_id = jv.id
    LEFT JOIN time_clock_entries tce ON tce.id = jte.clock_entry_id
    WHERE jte.status = 'active'
      AND jte.end_time IS NULL
      AND jte.start_time < NOW() - INTERVAL ? HOUR
    ORDER BY jte.start_time ASC
");
$stmt->execute([$thresholdHours]);
$orphans = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stopped  = 0;
$errors   = 0;
$log      = [];

foreach ($orphans as $entry) {
    try {
        $rawMinutes   = (int)$entry['raw_minutes'];
        $shiftMax     = $entry['shift_max_minutes'] !== null ? (int)$entry['shift_max_minutes'] : null;

        // Cap to shift if we have shift data; otherwise cap to threshold hours.
        if ($shiftMax !== null && $shiftMax > 0) {
            $cappedMinutes = min($rawMinutes, $shiftMax);
        } else {
            $cappedMinutes = min($rawMinutes, $thresholdHours * 60);
        }

        $note = '[auto-stopped: orphaned timer exceeded ' . $thresholdHours . 'h threshold]';
        if ($cappedMinutes < $rawMinutes) {
            $note .= ' [auto-capped: exceeded shift duration]';
        }

        $db->prepare("
            UPDATE job_time_entries
            SET end_time        = NOW(),
                duration_minutes = ?,
                notes            = CONCAT(COALESCE(notes, ''), ?),
                status           = 'completed',
                auto_stopped     = 1,
                flagged_anomaly  = 1
            WHERE id = ?
        ")->execute([$cappedMinutes, ' ' . $note, $entry['id']]);

        // Log to visit_audit_log if table exists.
        try {
            $db->prepare("
                INSERT INTO visit_audit_log (visit_id, user_id, action, payload_json, ip_address)
                VALUES (?, ?, 'orphaned_timer_auto_stopped', ?, 'cron')
            ")->execute([
                $entry['visit_id'],
                $entry['user_id'],
                json_encode([
                    'entry_id'       => $entry['id'],
                    'raw_minutes'    => $rawMinutes,
                    'capped_minutes' => $cappedMinutes,
                    'shift_max'      => $shiftMax,
                ]),
            ]);
        } catch (Throwable $logEx) {
            // audit_log is non-critical — continue
        }

        // Recalculate the timesheet for the week this entry falls in.
        $weekStart = date('Y-m-d', strtotime('monday this week', strtotime($entry['start_time'])));
        if (strtotime($weekStart) > strtotime($entry['start_time'])) {
            $weekStart = date('Y-m-d', strtotime('monday last week', strtotime($entry['start_time'])));
        }
        ensureTimesheetExists($entry['user_id'], date('Y-m-d', strtotime($entry['start_time'])));

        $log[] = [
            'entry_id'      => $entry['id'],
            'visit_number'  => $entry['visit_number'],
            'user_id'       => $entry['user_id'],
            'raw_minutes'   => $rawMinutes,
            'capped_minutes' => $cappedMinutes,
        ];

        $stopped++;
    } catch (Throwable $e) {
        $errors++;
        $log[] = ['entry_id' => $entry['id'], 'error' => $e->getMessage()];
        error_log('[stop_orphaned_job_timers] Entry ' . $entry['id'] . ': ' . $e->getMessage());
    }
}

$result = [
    'success'    => true,
    'stopped'    => $stopped,
    'errors'     => $errors,
    'threshold_h' => $thresholdHours,
    'log'        => $log,
];

if ($isCli) {
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    echo json_encode($result);
}
