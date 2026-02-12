<?php
/**
 * LEGACY SHIM — weather_schedule_guard.php
 * Real logic lives at /app/Modules/Jobs/Cron/weather_schedule_guard.php
 * DO NOT add new code here. Edit the target file instead.
 */
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Modules/Jobs/Cron/weather_schedule_guard.php')) {
        require $__dir . '/app/Modules/Jobs/Cron/weather_schedule_guard.php';
        exit;
    }
}
http_response_code(500);
echo json_encode(['error' => 'Module file not found']);
