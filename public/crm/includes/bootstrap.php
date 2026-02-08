<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

/**
 * CRM bootstrap:
 * - Loads session + config from /app_config
 * - Loads auth helpers from /loginAuth
 * - Enforces login
 * - Provides $db and $user for all CRM pages
 *
 * Folder assumptions:
 *   /public_html/crm/includes/bootstrap.php
 *   /public_html/app_config/...
 *   /public_html/loginAuth/auth.php
 */

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';

// Enforce login for all CRM pages
requireLogin();

// Provide globals used by layout and pages
$user = getCurrentUser() ?? [];
$db   = Database::pdo();

// If you want CRM to be staff/admin only, uncomment:
// require_role(['staff', 'admin']);

