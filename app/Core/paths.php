<?php
/**
 * Central path constants for the Mowology application.
 *
 * All paths are absolute. Include this file first in any /app/ code.
 * This file is safe to require multiple times (define() guards prevent redefinition).
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));           // /…/mowology-crm/app
    define('PROJECT_ROOT', dirname(__DIR__, 2));    // /…/mowology-crm
    define('PUBLIC_ROOT', PROJECT_ROOT . '/public');
    define('CRM_ROOT', PUBLIC_ROOT . '/crm');
    define('CRM_INCLUDES', CRM_ROOT . '/includes');
    define('STORAGE_ROOT', APP_ROOT . '/Storage');
}
