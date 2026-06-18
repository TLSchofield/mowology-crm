<?php
/**
 * Migration 1065 — add calendar_stops.route_pin (persisted route endpoint pins).
 *
 * Idempotent + admin-only. Adds a nullable VARCHAR(5) holding 'first' | 'last'
 * so the day-view pin survives reloads and feeds the route optimiser.
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }

$db = getDB();

try {
    $exists = $db->query("SHOW COLUMNS FROM calendar_stops LIKE 'route_pin'")->rowCount() > 0;
    if ($exists) {
        echo json_encode(['success' => true, 'status' => 'already_present', 'column' => 'route_pin']);
        exit;
    }

    $db->exec("ALTER TABLE calendar_stops ADD COLUMN route_pin VARCHAR(5) NULL DEFAULT NULL COMMENT 'first|last route endpoint pin, NULL = unpinned'");

    echo json_encode(['success' => true, 'status' => 'added', 'column' => 'route_pin']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
