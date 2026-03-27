<?php
/**
 * Migration 1004 — Truck-driver link + home geofence
 *
 * Adds:
 *   users.assigned_truck_user_id  — driver row → their assigned truck user row
 *   users.home_lat / home_lng     — employee home coordinates for forgot-to-clock-out SMS
 *   users.home_radius_meters      — geofence radius around home (default 250m)
 *   ops_settings entry            — forgot_clockout_sms_enabled
 *
 * Run once via: /crm/api/run-migration-1004.php
 * Protected by CRM login + database.manage permission.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

$db  = getDB();
$log = [];

function mig1004_run(PDO $db, string $label, string $sql): string {
    try {
        $db->exec($sql);
        return "✅ $label";
    } catch (\PDOException $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'already exists') !== false || stripos($msg, 'Duplicate column') !== false) {
            return "⏭ $label — already exists, skipped";
        }
        return "❌ $label — ERROR: $msg";
    }
}

// assigned_truck_user_id — on driver's row, points to truck device user
$log[] = mig1004_run($db, 'Add assigned_truck_user_id to users',
    "ALTER TABLE `users`
     ADD COLUMN `assigned_truck_user_id` INT NULL DEFAULT NULL
       COMMENT 'FK to users.id where device_type=truck — auto-clocks out truck when driver clocks out'
     AFTER `device_type`"
);

// home_lat
$log[] = mig1004_run($db, 'Add home_lat to users',
    "ALTER TABLE `users`
     ADD COLUMN `home_lat` DECIMAL(10,6) NULL DEFAULT NULL
       COMMENT 'Home latitude for forgot-to-clock-out geofence detection'
     AFTER `assigned_truck_user_id`"
);

// home_lng
$log[] = mig1004_run($db, 'Add home_lng to users',
    "ALTER TABLE `users`
     ADD COLUMN `home_lng` DECIMAL(10,6) NULL DEFAULT NULL
       COMMENT 'Home longitude for forgot-to-clock-out geofence detection'
     AFTER `home_lat`"
);

// home_radius_meters
$log[] = mig1004_run($db, 'Add home_radius_meters to users',
    "ALTER TABLE `users`
     ADD COLUMN `home_radius_meters` INT NOT NULL DEFAULT 250
       COMMENT 'Geofence radius in meters — employee is considered \"home\" when GPS is within this distance'
     AFTER `home_lng`"
);

// forgot_sms_sent_at on time_clock_entries
$log[] = mig1004_run($db, 'Add forgot_sms_sent_at to time_clock_entries',
    "ALTER TABLE `time_clock_entries`
     ADD COLUMN `forgot_sms_sent_at` DATETIME NULL DEFAULT NULL
       COMMENT 'When the forgot-to-clock-out SMS was sent for this shift (NULL = not yet sent)'
     AFTER `notes`"
);

// ops_settings entry
$log[] = mig1004_run($db, 'Seed forgot_clockout_sms_enabled in ops_settings',
    "INSERT INTO `ops_settings` (`key`, `value`, `description`)
     VALUES ('forgot_clockout_sms_enabled', '1', 'Send SMS reminder when employee GPS is near home but still clocked in')
     ON DUPLICATE KEY UPDATE `description` = VALUES(`description`)"
);

$failed = array_filter($log, fn($l) => str_starts_with($l, '❌'));
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Migration 1004</title>
<style>body{font-family:monospace;background:#f5f5f5;padding:2rem}ul{list-style:none;padding:0}li{padding:.35rem 0;border-bottom:1px solid #e0e0e0}.ok{color:#2D8659;font-weight:bold}.err{color:#c00;font-weight:bold}</style>
</head><body>
<h1>Migration 1004 — Truck-driver link + home geofence</h1>
<ul>
<?php foreach ($log as $line): ?>
  <li class="<?= str_starts_with($line,'❌')?'err':'ok' ?>"><?= htmlspecialchars($line) ?></li>
<?php endforeach; ?>
</ul>
<?php if (empty($failed)): ?>
  <p class="ok" style="margin-top:1rem">✅ Migration complete.</p>
<?php else: ?>
  <p class="err" style="margin-top:1rem">❌ <?= count($failed) ?> step(s) failed.</p>
<?php endif; ?>
<p><a href="/crm/database_appstack.php">← Database Manager</a></p>
</body></html>
