<?php
/**
 * Central path constants for the Mowology application.
 *
 * All paths are absolute. Include this file first in any /app/ code.
 * This file is safe to require multiple times (define() guards prevent redefinition).
 *
 * Path layout:
 *   Local dev:  /…/mowology-crm/public/  (web root)
 *               /…/mowology-crm/app/     (application logic)
 *   Production: /home/mowology/           (web root = FTP root, no /public/ subdir)
 *               /home/mowology/app/       (application logic)
 *
 * APP_ROOT and PUBLIC_ROOT are siblings in both environments.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));               // /…/app

    // Auto-detect web root:
    // Local dev has /public/ subdir; production is flat (web root = parent of /app/)
    $__publicCandidate = dirname(__DIR__, 2) . '/public';
    if (is_dir($__publicCandidate) && is_dir($__publicCandidate . '/crm')) {
        define('PUBLIC_ROOT', $__publicCandidate);
    } else {
        define('PUBLIC_ROOT', dirname(__DIR__, 2));
    }
    unset($__publicCandidate);

    define('PROJECT_ROOT', dirname(APP_ROOT));          // Parent of /app/
    define('CRM_ROOT', PUBLIC_ROOT . '/crm');
    define('CRM_INCLUDES', CRM_ROOT . '/includes');
    define('STORAGE_ROOT', APP_ROOT . '/Storage');
}
