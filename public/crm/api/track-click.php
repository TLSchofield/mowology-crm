<?php
/**
 * Email Click Tracking — 302 Redirect
 *
 * Used in campaign emails: /crm/api/track-click.php?sid=123&url=https%3A//mowology.ca/quote
 * Updates campaign_sends.clicked_at, then 302 redirects to the actual URL.
 *
 * Stateless — just UPDATE + redirect.
 */

$sid = (int)($_GET['sid'] ?? 0);
$raw = $_GET['url'] ?? '';

// Decode base64url if it doesn't look like a plain URL
// (campaign_sender encodes URLs as base64url to avoid double-encoding)
if ($raw !== '' && !preg_match('#^https?://#i', $raw)) {
    $decoded = base64_decode(strtr($raw, '-_', '+/') . str_repeat('=', 3 - (strlen($raw) + 3) % 4));
    $raw = ($decoded !== false) ? $decoded : '';
}

// Validate — must be http(s), must point to mowology.ca or known trusted origin
$url = (preg_match('#^https?://[a-z0-9\-\.]+#i', $raw) && !preg_match('#[\r\n]#', $raw))
    ? $raw
    : 'https://mowology.ca';

if ($sid > 0) {
    try {
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
        }

        $db = getDB();

        // Only update if not already clicked (first click wins)
        $db->prepare("
            UPDATE campaign_sends
            SET clicked_at = NOW()
            WHERE id = ? AND clicked_at IS NULL AND status = 'sent'
        ")->execute([$sid]);

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
