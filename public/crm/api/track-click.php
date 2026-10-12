<?php
/**
 * Email Click Tracking — 302 Redirect
 *
 * Used in campaign emails: /crm/api/track-click.php?sid=123&url=<base64url>&s=<hmac> (TrackingLinks::clickUrl())
 * Updates campaign_sends.clicked_at, then 302 redirects to the actual URL.
 *
 * Stateless — just UPDATE + redirect.
 */

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

$sid = (int)($_GET['sid'] ?? 0);
$url = class_exists('TrackingLinks') ? TrackingLinks::decodeUrl((string)($_GET['url'] ?? '')) : '';

// Signed (Fable review 2026-10-12): only a link that was in our email may leave mowology.ca, and
// only a signed click counts. Unsigned → no count, and a redirect to mowology.ca hosts only.
$signed = class_exists('TrackingLinks') && TrackingLinks::verify($sid, $url, (string)($_GET['s'] ?? ''));
$url = class_exists('TrackingLinks') ? TrackingLinks::redirectTarget($url, $signed) : 'https://mowology.ca';
if (!$signed) $sid = 0;

if ($sid > 0) {
    try {
        $db = getDB();

        // Only update if not already clicked (first click wins)
        $db->prepare("
            UPDATE campaign_sends
            SET clicked_at = NOW()
            WHERE id = ? AND clicked_at IS NULL AND status = 'sent'
        ")->execute([$sid]);

        // Every click with its time and user agent (migration 1195), for Mia's send-time learning.
        try {
            $db->prepare("
                INSERT INTO campaign_events (send_id, kind, at, user_agent)
                SELECT id, 'click', ?, ? FROM campaign_sends
                WHERE id = ? AND status = 'sent' AND (SELECT COUNT(*) FROM campaign_events WHERE send_id = ? AND kind = 'click') < 20
            ")->execute([date('Y-m-d H:i:s'), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $sid, $sid]);
        } catch (\Throwable $e) { /* before migration 1195 */ }

        // Also increment campaign click_count
        $db->prepare("
            UPDATE marketing_campaigns mc
            SET click_count = (
                SELECT COUNT(*) FROM campaign_sends WHERE campaign_id = mc.id AND clicked_at IS NOT NULL
            )
            WHERE mc.id = (SELECT campaign_id FROM campaign_sends WHERE id = ?)
        ")->execute([$sid]);

    } catch (\Throwable $e) {
        error_log('track-click error: ' . $e->getMessage());
    }
}

// 302 redirect to actual URL
header('Location: ' . $url, true, 302);
exit;
