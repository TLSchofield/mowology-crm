<?php
/**
 * Email Open Tracking — 1x1 Transparent PNG
 *
 * Embedded in campaign emails as: <img src="/crm/api/track-open.php?sid=123">
 * Updates campaign_sends.opened_at on first load.
 *
 * Stateless — just UPDATE + serve transparent pixel.
 */

$sid = (int)($_GET['sid'] ?? 0);
$mid = (int)($_GET['m'] ?? 0);

if ($sid > 0 || $mid > 0) {
    try {
        // Minimal bootstrap — just need DB
        $__dir = __DIR__;
        for ($__i = 0; $__i < 5; $__i++) {
            $__dir = dirname($__dir);
            if (is_file($__dir . '/app/Core/paths.php')) {
                require_once $__dir . '/app/Core/paths.php';
                break;
            }
        }
        unset($__dir, $__i);

        if (defined('APP_ROOT')) {
            require_once APP_ROOT . '/Core/config.php';
            require_once APP_ROOT . '/Modules/Marketing/Services/TrackingLinks.php';
        }

        $scope = $mid > 0 ? 'mia-open' : 'open';
        $id = $mid > 0 ? $mid : $sid;
        // Only a signed, scope-specific pixel counts.
        if (!class_exists('TrackingLinks') || !TrackingLinks::verify($id, $scope, (string)($_GET['s'] ?? ''))) {
            throw new RuntimeException('unsigned open pixel ignored');
        }

        $db = getDB();

        if ($mid > 0) {
            try {
                $db->prepare("INSERT INTO contact_email_events (contact_id,source,ref_id,kind,at,user_agent,sent_at)
                    SELECT contact_id,'mia',id,'open',?,?,sent_at FROM mia_suggestions
                    WHERE id=? AND sent_at IS NOT NULL AND (SELECT COUNT(*) FROM contact_email_events WHERE source='mia' AND ref_id=? AND kind='open') < 20")
                    ->execute([date('Y-m-d H:i:s'), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $mid, $mid]);
            } catch (Throwable $e) { /* migration not present */ }
            throw new RuntimeException('mia open recorded');
        }

        // Only update if not already opened (first open wins)
        $db->prepare("
            UPDATE campaign_sends
            SET opened_at = NOW()
            WHERE id = ? AND opened_at IS NULL AND status = 'sent'
        ")->execute([$sid]);

        // Every open with its time and user agent (migration 1195), for Mia's send-time learning.
        // Apple Mail's privacy proxy opens mail by itself; SendTimeService filters those out.
        try {
            $db->prepare("
                INSERT INTO campaign_events (send_id, kind, at, user_agent)
                SELECT id, 'open', ?, ? FROM campaign_sends
                WHERE id = ? AND status = 'sent' AND (SELECT COUNT(*) FROM campaign_events WHERE send_id = ? AND kind = 'open') < 20
            ")->execute([date('Y-m-d H:i:s'), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $sid, $sid]);
        } catch (\Throwable $e) { /* before migration 1195 */ }

        // Also increment campaign open_count
        $db->prepare("
            UPDATE marketing_campaigns mc
            SET open_count = (
                SELECT COUNT(*) FROM campaign_sends WHERE campaign_id = mc.id AND opened_at IS NOT NULL
            )
            WHERE mc.id = (SELECT campaign_id FROM campaign_sends WHERE id = ?)
        ")->execute([$sid]);

    } catch (\Throwable $e) {
        // Silent failure — don't break the image
        if (!in_array($e->getMessage(), ['unsigned open pixel ignored', 'mia open recorded'], true)) error_log('track-open error: ' . $e->getMessage());
    }
}

// Serve 1x1 transparent PNG
header('Content-Type: image/png');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Minimal 1x1 transparent PNG (67 bytes)
echo base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg=='
);
