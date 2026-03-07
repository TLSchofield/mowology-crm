<?php
/**
 * Quote Email Open Tracking — 1x1 Transparent PNG
 *
 * Embedded in quote emails as: <img src="/crm/api/track-quote-open.php?t=ACCESS_TOKEN">
 * Updates quotes.email_opened_at on first load.
 *
 * Stateless — just UPDATE + serve transparent pixel.
 */

$token = $_GET['t'] ?? '';

if ($token !== '') {
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

        // Only update first open (email_opened_at IS NULL)
        $db->prepare("
            UPDATE quotes
            SET email_opened_at = NOW()
            WHERE access_token = ? AND email_opened_at IS NULL AND status = 'sent'
        ")->execute([$token]);

    } catch (\Throwable $e) {
        // Silent failure — don't break the image
        error_log('track-quote-open error: ' . $e->getMessage());
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
