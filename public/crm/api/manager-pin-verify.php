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
    require_once APP_ROOT . '/Core/Auth/AttemptThrottle.php';
    require_once APP_ROOT . '/Modules/Settings/Services/ManagerPin.php';

    requireLogin();
    $user = getCurrentUser();

    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    // CSRF must be verified before session_write_close() — closing the session clears $_SESSION
    if (empty($input['csrf_token']) || !verifyCSRFToken($input['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid request']);
        exit;
    }

    session_write_close();

    // Anyone driving this shift may need a manager to waive the pre-trip — not only users
    // with the permanent is_driver flag. The manager's PIN is the control, not the flag.

    $pin    = trim($input['pin'] ?? '');
    $reason = trim($input['bypass_reason'] ?? 'manager_override');
    $reason = substr($reason, 0, 100);

    if (empty($pin)) {
        http_response_code(400);
        echo json_encode(['error' => 'PIN required']);
        exit;
    }

    $db = getDB();
    $userId = (int)$user['id'];
    $ip     = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    // Lockout (#10, Fable audit 2026-10-12): 5 wrong PINs in 10 minutes locks this user out of the
    // bypass. A 4-digit PIN otherwise falls to 10,000 tries.
    if (AttemptThrottle::pinIsLocked($db, $userId)) {
        logActivity($userId, null, 'Manager PIN locked out', 'IP: ' . $ip);
        http_response_code(429);
        echo json_encode(['error' => 'Too many wrong PINs. Try again in 10 minutes or call the office.']);
        exit;
    }

    // Fetch stored PIN — empty means PIN bypasses are disabled
    $pinRow    = $db->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'manager_override_pin'")->fetch(PDO::FETCH_ASSOC);
    $storedPin = $pinRow['setting_value'] ?? '';

    if (empty($storedPin)) {
        http_response_code(403);
        echo json_encode(['error' => 'Manager override is not configured. Contact your administrator.']);
        exit;
    }

    // Stored hashed (settings save path); a legacy plaintext value is accepted once and re-hashed.
    $check = ManagerPin::verify($pin, (string)$storedPin);
    if (!$check['ok']) {
        AttemptThrottle::record($db, AttemptThrottle::pinKey($userId), $ip);
        error_log('[manager-pin-verify] failed PIN attempt for user #' . $userId);
        logActivity($userId, null, 'Manager PIN failed', 'IP: ' . $ip);
        http_response_code(403);
        echo json_encode(['error' => 'Incorrect PIN']);
        exit;
    }
    AttemptThrottle::clear($db, AttemptThrottle::pinKey($userId));
    if ($check['rehash'] !== null) {
        $db->prepare("UPDATE ops_settings SET setting_value = ? WHERE setting_key = 'manager_override_pin' AND setting_value = ?")
           ->execute([$check['rehash'], (string)$storedPin]);
    }

    $today  = date('Y-m-d');

    // Upsert vehicle_trip_reports to mark pre-trip as skipped.
    // Uses try/catch fallback in case migration 1017 hasn't run yet
    // (pre_trip_skipped / skip_reason columns may not exist).
    $existingStmt = $db->prepare("SELECT id FROM vehicle_trip_reports WHERE driver_id = ? AND report_date = ? LIMIT 1");
    $existingStmt->execute([$userId, $today]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

    try {
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
    } catch (Throwable $colErr) {
        // Migration 1017 not yet run — fall back to minimal write without new columns
        error_log('[manager-pin-verify] new columns missing, using fallback: ' . $colErr->getMessage());
        if ($existing) {
            $db->prepare("UPDATE vehicle_trip_reports SET pre_trip_at = NOW() WHERE id = ?")->execute([$existing['id']]);
        } else {
            $db->prepare("INSERT INTO vehicle_trip_reports (driver_id, report_date, pre_trip_at) VALUES (?, ?, NOW())")->execute([$userId, $today]);
        }
    }

    // Audit log
    logActivity($userId, null, 'Pre-trip bypassed via manager PIN', 'Reason: ' . $reason);

    echo json_encode(['success' => true, 'message' => 'Pre-trip bypassed']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    error_log('[manager-pin-verify] ' . $e->getMessage());
}
