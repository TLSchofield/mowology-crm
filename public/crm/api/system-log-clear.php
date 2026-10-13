<?php
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

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}
// CSRF (Fable review 2026-10-12): a cross-site POST could wipe the log.
require_once APP_ROOT . '/Core/AdminActionGuard.php';
adminActionGuard('clear the system log');

$db = getDB();
$db->exec("TRUNCATE TABLE system_log");
// Who cleared it — kept outside system_log (which was just emptied).
$uid = (int)($user['id'] ?? 0);
logActivity($uid, null, 'system_log_cleared', 'System log cleared by ' . ($user['full_name'] ?? $user['email'] ?? ('user ' . $uid)));
error_log('[system-log-clear] system_log truncated by user ' . $uid . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
echo json_encode(['ok' => true]);
