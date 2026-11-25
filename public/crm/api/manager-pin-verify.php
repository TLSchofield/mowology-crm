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

    require_once APP_ROOT . '/Modules/Driver/Services/PreTripBypassService.php';

    $result = PreTripBypassService::attempt(
        getDB(),
        (int)$user['id'],
        (string)($input['pin'] ?? ''),
        (string)($input['bypass_reason'] ?? ''),
        null,
        (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        date('Y-m-d')
    );
    if (!$result['ok']) {
        http_response_code($result['status']);
        echo json_encode(['error' => $result['error']]);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Pre-trip bypassed']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    error_log('[manager-pin-verify] ' . $e->getMessage());
}
