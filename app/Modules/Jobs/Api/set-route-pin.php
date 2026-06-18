<?php
/**
 * Set Route Pin — persist a day-view route endpoint pin
 * ──────────────────────────────────────────────────────
 * Marks a calendar_stop as the locked first or last stop of its day's route,
 * or clears it. Enforces one 'first' and one 'last' per stop_date by clearing
 * the same role from sibling stops on that date. Day-scoped (not per-crew) to
 * match the day-view optimiser, which reorders the whole day's stops.
 *
 * POST JSON: { stop_id: int, pin: 'first'|'last'|null, csrf_token: string }
 * Returns:   { success: true, stop_id: int, pin: string|null }
 */
declare(strict_types=1);
header('Content-Type: application/json');

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    requireLogin();
    requirePermission('jobs.edit');

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('Invalid request body');
    }

    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid CSRF token');
    }

    $stopId = isset($input['stop_id']) ? (int)$input['stop_id'] : 0;
    if ($stopId <= 0) {
        throw new Exception('Missing required field: stop_id');
    }

    // Normalise pin: 'first' | 'last' | null (anything else clears it)
    $pin = $input['pin'] ?? null;
    if ($pin !== 'first' && $pin !== 'last') {
        $pin = null;
    }

    $db = getDB();

    // Resolve the stop's date so we can keep the pin day-scoped.
    $stmt = $db->prepare('SELECT stop_date FROM calendar_stops WHERE id = ?');
    $stmt->execute([$stopId]);
    $stopDate = $stmt->fetchColumn();
    if ($stopDate === false) {
        throw new Exception('Stop not found');
    }

    $db->beginTransaction();

    if ($pin === null) {
        // Clear just this stop's pin.
        $db->prepare('UPDATE calendar_stops SET route_pin = NULL WHERE id = ?')
           ->execute([$stopId]);
    } else {
        // Only one stop may hold this role per day — release it from siblings.
        $db->prepare('UPDATE calendar_stops SET route_pin = NULL WHERE stop_date = ? AND route_pin = ? AND id <> ?')
           ->execute([$stopDate, $pin, $stopId]);
        // Assign the role to this stop (replaces its previous role if any).
        $db->prepare('UPDATE calendar_stops SET route_pin = ? WHERE id = ?')
           ->execute([$pin, $stopId]);
    }

    $db->commit();

    echo json_encode(['success' => true, 'stop_id' => $stopId, 'pin' => $pin]);

} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
