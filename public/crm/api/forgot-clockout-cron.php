<?php
/**
 * Shim — Forgot Clock-Out SMS Cron (web-triggered)
 * POST only, requires admin login + database.manage permission.
 */
declare(strict_types=1);
header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

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

ob_start();
require APP_ROOT . '/Modules/Team/Cron/forgot_clockout_sms.php';
$output = ob_get_clean();

echo json_encode(['success' => true, 'log' => $output]);
