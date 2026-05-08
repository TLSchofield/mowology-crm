<?php
/**
 * Quote Request Reminder Cron
 *
 * Sends an SMS to admin for every quote_request that has been sitting unactioned
 * (status = 'new', no quote raised) for more than 4 hours. Fires once per request
 * by logging a 'quote_request_reminder' entry to activity_log.
 *
 * Cron: run every 30 minutes
 *   * /2 * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Quotes/Cron/quote_request_reminder.php
 *
 * Also accessible via POST to /crm/cron/quote_request_reminder.php (admin-only shim).
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

require_once CRM_INCLUDES . '/functions.php';
require_once PUBLIC_ROOT . '/includes/notifications.php';

$db  = getDB();
$log = [];

function qrLog(string $msg): void {
    global $log;
    $ts = date('Y-m-d H:i:s');
    $log[] = "[$ts] $msg";
    if (PHP_SAPI === 'cli') { echo "[$ts] $msg\n"; }
}

qrLog('=== Quote Request Reminder started ===');

try {
    // Find quote_requests older than 4 hours that:
    // - still have status = 'new' (no quote raised)
    // - have not already been reminded (no activity_log entry for this action)
    $stmt = $db->prepare("
        SELECT qr.id, qr.created_at, qr.urgency,
               c.first_name, c.last_name, c.phone
        FROM quote_requests qr
        LEFT JOIN contacts c ON c.id = qr.contact_id
        WHERE qr.status = 'new'
          AND qr.quote_id IS NULL
          AND qr.created_at < NOW() - INTERVAL 4 HOUR
          AND NOT EXISTS (
              SELECT 1 FROM activity_log al
              WHERE al.quote_request_id = qr.id
                AND al.action = 'quote_request_reminder'
          )
        ORDER BY qr.urgency = 'asap' DESC, qr.created_at ASC
    ");
    $stmt->execute();
    $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

    qrLog('Found ' . count($pending) . ' unactioned request(s)');

    foreach ($pending as $row) {
        $name    = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'Unknown';
        $phone   = $row['phone'] ?? '';
        $hours   = (int)round((time() - strtotime($row['created_at'])) / 3600);
        $urgent  = ($row['urgency'] === 'asap') ? ' URGENT' : '';

        // Build SMS — max 160 chars, no URLs (CASL/carrier rule)
        $sms = "REMINDER{$urgent}: {$name}";
        if ($phone !== '') $sms .= " {$phone}";
        $sms .= " — quote req {$hours}h old, no quote raised. Check CRM.";
        $sms  = substr($sms, 0, 160);

        $sent = sendSMSNotification($sms);
        qrLog(($sent ? 'SMS sent' : 'SMS FAILED') . " for quote_request #{$row['id']} ({$name})");

        // Mark as reminded so it doesn't fire again
        $logStmt = $db->prepare("
            INSERT INTO activity_log (contact_id, quote_request_id, action, details)
            VALUES (?, ?, 'quote_request_reminder', ?)
        ");
        $logStmt->execute([
            null,
            (int)$row['id'],
            "SMS reminder sent to admin. Hours elapsed: {$hours}. SMS result: " . ($sent ? 'OK' : 'FAILED'),
        ]);
    }

} catch (Throwable $e) {
    qrLog('ERROR: ' . $e->getMessage());
    error_log('[quote_request_reminder] ' . $e->getMessage());
}

qrLog('=== Done ===');

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'log' => $log]);
}
