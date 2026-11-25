<?php
declare(strict_types=1);

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
require_once APP_ROOT . '/Core/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit;
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Core/CronLock.php';
require_once APP_ROOT . '/Modules/Operations/Services/OttoAutoPlaceService.php';

\App\Core\CronLock::acquire('otto_place_properties_' . Tenant::key());
$started = microtime(true);
try {
    $result = (new OttoAutoPlaceService(getDB()))->run();
    $message = (string)$result['message'];
    recordCronRun('otto_place_properties', 'success', $message, (int)round((microtime(true) - $started) * 1000), null, false);
    echo 'Otto place [' . Tenant::key() . ']: ' . $message . "\n";
    exit(0);
} catch (Throwable $e) {
    recordCronRun('otto_place_properties', 'error', 'Otto place failed', null, cronFailureDetail($e), false);
    echo 'Otto place [' . Tenant::key() . "]: Otto place failed\n";
    exit(1);
}
