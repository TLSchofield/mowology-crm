<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
    }
    unset($__dir, $__i);
}
require_once APP_ROOT . '/Core/config.php';
if (PHP_SAPI !== 'cli') { http_response_code(403); echo "CLI only\n"; exit; }
require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Core/CronLock.php';
require_once APP_ROOT . '/Modules/QuickBooks/Services/QboSyncService.php';

\App\Core\CronLock::acquire('qbo_sync_' . Tenant::key());
$started = microtime(true);
try {
    $result = QboSyncService::runAll(new QboConnectionService(getDB()));
    recordCronRun('qbo_sync', $result['status'], $result['summary'], (int)round((microtime(true) - $started) * 1000), $result['notes_text'], false);
    echo 'QuickBooks [' . Tenant::key() . ']: ' . $result['summary'] . "\n";
    exit(0);
} catch (Throwable $e) {
    recordCronRun('qbo_sync', 'error', 'QuickBooks sending failed', null, cronFailureDetail($e), false);
    echo 'QuickBooks [' . Tenant::key() . "]: QuickBooks sending failed\n";
    exit(1);
}
