<?php
/**
 * Manager PIN Verify — bypass pre-trip inspection with a manager PIN.
 *
 * POST: { pin: string, bypass_reason?: string, csrf_token: string }
 *
 * On success: inserts or updates a vehicle_trip_reports row marking the
 * pre-trip as skipped, logs the bypass to activity_log, and returns {success:true}.
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
    require_once CRM_INCLUDES . '/functions.php';
    require_once CRM_INCLUDES . '/timeclock-functions.php';

    requireLogin();
    $user = getCurrentUser();
    session_write_close();

    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    if (empty($input['csrf_token']) || !verifyCSRFToken($input['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid request']);
        exit;
    }

    // Only drivers use this endpoint
    if (empty($user['is_driver'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Not a driver account']);
        exit;
    }

    $pin    = trim($input['pin'] ?? '');
    $reason = trim($input['bypass_reason'] ?? 'manager_override');
    $reason = substr($reason, 0, 100);

    if (empty($pin)) {
        http_response_code(400);
        echo json_encode(['error' => 'PIN required']);
        exit;
    }

    $db = getDB();

    // Fetch stored PIN — empty means PIN bypasses are disabled
    $pinRow = $db->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'manager_override_pin'")->fetch(PDO::FETCH_ASSOC);
    $storedPin = $pinRow['setting_value'] ?? '';

    if (empty($storedPin)) {
        http_response_code(403);
        echo json_encode(['error' => 'Manager override is not configured. Contact your administrator.']);
        exit;
    }

    if (!hash_equals($storedPin, $pin)) {
        error_log('[manager-pin-verify] failed PIN attempt for user #' . $user['id']);
        http_response_code(403);
        echo json_encode(['error' => 'Incorrect PIN']);
        exit;
    }

    $userId = (int)$user['id'];
    $today  = date('Y-m-d');

    // Upsert vehicle_trip_reports to mark pre-trip as skipped
    $existingStmt = $db->prepare("SELECT id FROM vehicle_trip_reports WHERE driver_id = ? AND report_date = ? LIMIT 1");
    $existingStmt->execute([$userId, $today]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $db->prepare("
            UPDATE vehicle_trip_reports
            SET pre_trip_at      = NOW(),
                pre_trip_skipped = 1,
                skip_reason      = ?
            WHERE id = ?
        ")->execute([$reason, $existing['id']]);
    } else {
        $db->prepare("
            INSERT INTO vehicle_trip_reports (driver_id, report_date, pre_trip_at, pre_trip_skipped, skip_reason)
            VALUES (?, ?, NOW(), 1, ?)
        ")->execute([$userId, $today, $reason]);
    }

    // Audit log
    logActivity($userId, null, 'Pre-trip bypassed via manager PIN', 'Reason: ' . $reason);

    echo json_encode(['success' => true, 'message' => 'Pre-trip bypassed']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    error_log('[manager-pin-verify] ' . $e->getMessage());
}
