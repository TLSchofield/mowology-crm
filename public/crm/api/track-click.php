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
$url = $_GET['url'] ?? '';

// Validate URL — must be http(s)
if (!preg_match('#^https?://#i', $url)) {
    $url = 'https://mowology.ca'; // Fallback
}

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
