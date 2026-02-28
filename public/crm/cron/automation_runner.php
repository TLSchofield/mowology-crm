<?php
/**
 * Automation Runner Cron — shim
 *
 * Cron command:
 *   * /5 * * * * /usr/local/bin/php /home/mowology/public_html/crm/cron/automation_runner.php
 *
 * Web trigger (POST, admin only):
 *   POST /crm/cron/automation_runner.php
 */
if (PHP_SAPI !== 'cli') {
    // Web trigger: require admin login
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    requireLogin();
    $u = getCurrentUser();
    if (!userHasPermission('admin') && !userHasPermission('marketing.edit')) {
        http_response_code(403);
        exit(json_encode(['error' => 'Forbidden']));
    }
    header('Content-Type: application/json');
    ob_start();
}

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        if (!defined('APP_ROOT')) require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once APP_ROOT . '/Modules/Marketing/Cron/automation_runner.php';

if (PHP_SAPI !== 'cli') {
    $output = ob_get_clean();
    echo json_encode(['success' => true, 'output' => $output]);
}
