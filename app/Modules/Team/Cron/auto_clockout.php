<?php
/**
 * Auto Clock-Out Cron
 *
 * Finds active clock entries older than auto_clock_out_hours (default 10h)
 * and completes them automatically. Also closes any open trip reports for
 * the same driver/date.
 *
 * Sends an SMS notification to the employee (if SMS enabled).
 *
 * Cron: run every 30 minutes
 *   0,30 * * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Team/Cron/auto_clockout.php
 *
 * Also callable via POST to /crm/api/auto-clockout-cron.php (admin-only shim).
 */

declare(strict_types=1);

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
require_once CRM_INCLUDES . '/timeclock-functions.php';
require_once CRM_INCLUDES . '/messaging.php';

$db = getDB();

$autoHours = max(1, (int) getTimeClockSetting('auto_clock_out_hours', 10));

$staleStmt = $db->prepare("
    SELECT tce.id, tce.user_id, tce.clock_in,
           u.full_name, u.phone, u.first_name
    FROM time_clock_entries tce
    JOIN users u ON u.id = tce.user_id
    WHERE tce.status     = 'active'
      AND tce.clock_out  IS NULL
      AND tce.clock_in   < DATE_SUB(NOW(), INTERVAL ? HOUR)
");
$staleStmt->execute([$autoHours]);
$entries = $staleStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($entries)) {
    echo "No stale entries found.\n";
    exit;
}

$completed = 0;
foreach ($entries as $entry) {
    try {
        $clockInDate = date('Y-m-d', strtotime($entry['clock_in']));

        $db->prepare("
            UPDATE time_clock_entries
            SET clock_out     = DATE_ADD(clock_in, INTERVAL ? HOUR),
                total_minutes = ? * 60,
                status        = 'completed',
                notes         = CONCAT(COALESCE(notes, ''), ' [auto-completed by cron after ', ?, 'h]'),
                edited_at     = NOW()
            WHERE id = ?
        ")->execute([$autoHours, $autoHours, $autoHours, $entry['id']]);

        ensureTimesheetExists((int)$entry['user_id'], $clockInDate);

        // Close any open trip reports for that date
        $db->prepare("
            UPDATE vehicle_trip_reports
            SET post_trip_at = DATE_ADD(pre_trip_at, INTERVAL 8 HOUR)
            WHERE driver_id    = ?
              AND report_date  = ?
              AND pre_trip_at  IS NOT NULL
              AND post_trip_at IS NULL
        ")->execute([$entry['user_id'], $clockInDate]);

        // SMS notification — no URLs, 160 chars max
        if (!empty($entry['phone'])) {
            $firstName = $entry['first_name'] ?: explode(' ', $entry['full_name'])[0];
            $dayStr    = date('M j', strtotime($entry['clock_in']));
            $msg       = "Hi $firstName, your {$dayStr} shift was auto-closed after {$autoHours}h. Questions? Call (778) 846-9273";
            if (strlen($msg) <= 160) {
                try {
                    sendSms($entry['phone'], $msg);
                } catch (Throwable $smsErr) {
                    error_log('[auto_clockout] SMS failed for user #' . $entry['user_id'] . ': ' . $smsErr->getMessage());
                }
            }
        }

        $completed++;
        echo "Auto-completed entry #{$entry['id']} for user #{$entry['user_id']} ({$entry['full_name']})\n";

    } catch (Throwable $e) {
        error_log('[auto_clockout] failed for entry #' . $entry['id'] . ': ' . $e->getMessage());
        echo "ERROR for entry #{$entry['id']}: " . $e->getMessage() . "\n";
    }
}

echo "Done. Completed $completed stale entries.\n";
