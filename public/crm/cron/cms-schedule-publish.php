<?php
require_once __DIR__ . '/_guard.php';
/**
 * Web shim → app/Modules/CMS/Cron/cms_schedule_publish.php
 * POST /crm/cron/cms-schedule-publish.php (admin only)
 */
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
require_once APP_ROOT . '/Modules/CMS/Cron/cms_schedule_publish.php';
