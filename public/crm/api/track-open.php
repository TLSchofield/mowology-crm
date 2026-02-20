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

if ($sid > 0) {
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
        }

        $db = getDB();

        // Only update if not already opened (first open wins)
        $db->prepare("
            UPDATE campaign_sends
            SET opened_at = NOW()
            WHERE id = ? AND opened_at IS NULL AND status = 'sent'
        ")->execute([$sid]);

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
        error_log('track-open error: ' . $e->getMessage());
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
