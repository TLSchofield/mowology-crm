<?php
/**
 * Forgot Clock-Out SMS Reminder Cron
 *
 * Detects employees who are still clocked in but whose last GPS ping is within
 * their home geofence radius. Sends an SMS reminder asking them to clock out.
 *
 * Rules:
 *   - Only runs if ops_settings forgot_clockout_sms_enabled = 1
 *   - Employee must have home_lat + home_lng set on their user record
 *   - Last GPS ping must be within home_radius_meters of their home
 *   - Last ping must be at least 15 minutes old (not freshly arrived)
 *   - Only sends once per shift — tracks via time_clock_entries.forgot_sms_sent_at
 *   - SMS has no URLs (carrier gateway compliance)
 *
 * Cron: run every 30 minutes
 *   0,30 * * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Team/Cron/forgot_clockout_sms.php
 *
 * Also callable via POST to /crm/api/forgot-clockout-cron.php (admin-only shim).
 */

declare(strict_types=1);

// Bootstrap
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once CRM_INCLUDES . '/functions.php';
require_once CRM_INCLUDES . '/messaging.php';

$db  = getDB();
$log = [];

function fcoLog(string $msg): void {
    global $log;
    $ts = date('Y-m-d H:i:s');
    $log[] = "[$ts] $msg";
    if (PHP_SAPI === 'cli') { echo "[$ts] $msg\n"; }
}

fcoLog('=== Forgot Clock-Out SMS Cron started ===');

// Check feature flag
$enabledRow = $db->query("SELECT value FROM ops_settings WHERE `key` = 'forgot_clockout_sms_enabled' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$enabledRow || $enabledRow['value'] !== '1') {
    fcoLog('Feature disabled — exiting');
    exit;
}

// Haversine distance in meters between two lat/lng points
function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371000.0; // Earth radius in meters
    $φ1 = deg2rad($lat1);
    $φ2 = deg2rad($lat2);
    $Δφ = deg2rad($lat2 - $lat1);
    $Δλ = deg2rad($lng2 - $lng1);
    $a = sin($Δφ / 2) ** 2 + cos($φ1) * cos($φ2) * sin($Δλ / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

// Ensure the forgot_sms_sent_at column exists — migration 1004 adds it, but be graceful
try {
    $db->query("SELECT forgot_sms_sent_at FROM time_clock_entries LIMIT 0");
    $hasForgotCol = true;
} catch (\PDOException $e) {
    $hasForgotCol = false;
    fcoLog('forgot_sms_sent_at column missing — run migration 1004 first');
}

try {
    // Find all currently clocked-in employees who have home coordinates set
    // and have receive_sms consent and a phone number
    $stmt = $db->query("
        SELECT
            tce.id AS entry_id,
            tce.user_id,
            tce.clock_in,
            " . ($hasForgotCol ? "tce.forgot_sms_sent_at," : "'0' AS forgot_sms_sent_at,") . "
            u.first_name,
            u.full_name,
            u.phone,
            u.home_lat,
            u.home_lng,
            u.home_radius_meters,
            (
                SELECT clh.latitude
                FROM crew_location_history clh
                WHERE clh.crew_id = tce.user_id
                ORDER BY clh.timestamp DESC
                LIMIT 1
            ) AS last_lat,
            (
                SELECT clh.longitude
                FROM crew_location_history clh
                WHERE clh.crew_id = tce.user_id
                ORDER BY clh.timestamp DESC
                LIMIT 1
            ) AS last_lng,
            (
                SELECT clh.timestamp
                FROM crew_location_history clh
                WHERE clh.crew_id = tce.user_id
                ORDER BY clh.timestamp DESC
                LIMIT 1
            ) AS last_ping_at
        FROM time_clock_entries tce
        JOIN users u ON u.id = tce.user_id
        WHERE tce.status = 'active'
          AND tce.clock_out IS NULL
          AND u.home_lat IS NOT NULL
          AND u.home_lng IS NOT NULL
          AND u.phone IS NOT NULL
          AND u.phone != ''
    ");

    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    fcoLog('Clocked-in employees with home set: ' . count($candidates));

    $sent = 0;
    $skipped = 0;

    foreach ($candidates as $row) {
        $name     = $row['first_name'] ?: $row['full_name'] ?: 'there';
        $phone    = $row['phone'];
        $entryId  = (int)$row['entry_id'];
        $userId   = (int)$row['user_id'];

        // Must have a recent GPS ping
        if (empty($row['last_lat']) || empty($row['last_lng'])) {
            fcoLog("  Skip #{$userId} — no GPS history");
            $skipped++;
            continue;
        }

        // Ping must be at least 15 minutes old (they've been home a while, not just passing by)
        $pingAge = time() - strtotime($row['last_ping_at']);
        if ($pingAge < 900) { // 900s = 15 min
            fcoLog("  Skip #{$userId} — last ping only {$pingAge}s ago, too recent");
            $skipped++;
            continue;
        }

        // Already sent SMS for this shift?
        if (!empty($row['forgot_sms_sent_at'])) {
            fcoLog("  Skip #{$userId} — SMS already sent at {$row['forgot_sms_sent_at']}");
            $skipped++;
            continue;
        }

        // Check distance from home
        $radius   = max(50, (int)($row['home_radius_meters'] ?? 250));
        $distance = haversineMeters(
            (float)$row['home_lat'], (float)$row['home_lng'],
            (float)$row['last_lat'], (float)$row['last_lng']
        );

        if ($distance > $radius) {
            fcoLog("  Skip #{$userId} — {$distance}m from home (radius {$radius}m)");
            $skipped++;
            continue;
        }

        fcoLog("  Employee #{$userId} ({$name}) is {$distance}m from home — sending SMS");

        // Build SMS (160 char max, no URLs)
        $smsText = "Hi {$name}, you appear to be home but are still clocked in. Please clock out when you're done for the day. Questions? Call (778) 846-9273";
        if (mb_strlen($smsText) > 160) {
            $smsText = "Hi {$name}, you're still clocked in. Please clock out if you're done for the day. Call (778) 846-9273";
        }

        $smsSent = sendSms($phone, $smsText);

        if ($smsSent) {
            // Mark the clock entry so we don't send again for this shift
            if ($hasForgotCol) {
                $db->prepare("UPDATE time_clock_entries SET forgot_sms_sent_at = NOW() WHERE id = ?")
                   ->execute([$entryId]);
            }
            fcoLog("  ✅ SMS sent to #{$userId}");
            $sent++;
        } else {
            fcoLog("  ❌ SMS delivery failed for #{$userId}");
        }
    }

    fcoLog("=== Done. Sent: {$sent}, Skipped: {$skipped} ===");

} catch (\Throwable $e) {
    fcoLog('FATAL: ' . $e->getMessage());
    exit(1);
}
