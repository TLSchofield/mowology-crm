<?php
/**
 * API Facade Dispatcher
 *
 * Provides clean URLs:  /api/{module}/{action}
 * Maps to:              /app/Modules/{Module}/Api/{action}.php
 *
 * Each endpoint file handles its own auth, headers, and response format.
 * This dispatcher only resolves the file path and requires it.
 *
 * Legacy URLs (/crm/api/*.php, /crm/jobs/api-jobs.php, etc.) still work
 * via shim files created in Phase 3a.
 */

// Bootstrap path constants
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

if (!defined('APP_ROOT')) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Server configuration error']);
    exit;
}

// ── Parse request ──────────────────────────────────────────────
$module = $_GET['module'] ?? '';
$action = $_GET['action'] ?? '';

if ($module === '' || $action === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing module or action', 'usage' => '/api/{module}/{action}']);
    exit;
}

// Sanitize: only lowercase letters, digits, hyphens, underscores
if (!preg_match('/^[a-z][a-z0-9\-_]*$/', $module) || !preg_match('/^[a-z][a-z0-9\-_]*$/', $action)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid module or action name']);
    exit;
}

// ── Module directory mapping ───────────────────────────────────
// Maps URL module names to /app/Modules/<Directory>/Api/
$moduleMap = [
    'jobs'      => 'Jobs',
    'quotes'    => 'Quotes',
    'invoices'  => 'Invoices',
    'products'  => 'Products',
    'team'      => 'Team',
    'cms'       => 'CMS',
    'marketing' => 'Marketing',
    'seo'       => 'Marketing',   // alias: /api/seo/* → Marketing module
    'database'  => 'Database',
    'settings'  => 'Settings',
    'schedule'  => 'Schedule',    // Mobile iOS schedule API (JWT-authenticated)
    'expenses'  => 'Expenses',    // Mobile iOS expense/receipt API (JWT-authenticated)
    'reports'   => 'Reports',     // Mobile iOS reports API (JWT-authenticated, admin only)
];

if (!isset($moduleMap[$module])) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => "Unknown module: {$module}"]);
    exit;
}

$moduleName = $moduleMap[$module];

// ── Action-to-file mapping ─────────────────────────────────────
// Some actions have filenames that don't match the URL action name.
// This map handles those cases. If an action is not listed here,
// the dispatcher tries {action}.php directly.
$actionAliases = [
    'jobs' => [
        'create'             => 'job-creation',
        'timer'              => 'job-timer',
        'reschedule'         => 'reschedule-job',
        'reschedule-simple'  => 'reschedule-job-simple',
        'reschedule-stop'    => 'reschedule-stop',
        'weather'            => 'weather-actions',
        'list'               => 'api-jobs',
    ],
    'quotes' => [
        'list'               => 'api-quotes',
    ],
    'invoices' => [
        'properties'         => 'api-get-properties',
        'recipients'         => 'api-get-recipients',
    ],
    'products' => [
        'list'               => 'api-products',
        'media-browse'       => 'api-media-browse',
        'notes'              => 'handle-note-save',
        'quote-request'      => 'process-quote-request',
    ],
    'team' => [
        'clock'              => 'time-clock',
        'time-clock'         => 'time-clock',
        'timesheets'         => 'timesheets',
        'employees'          => 'employees',
        'crew-location'      => 'crew-location',
    ],
    'cms' => [
        'upload-media'       => 'upload-media',
        'save-media'         => 'save-media',
        'delete-media'       => 'delete-media',
        'media-usage'        => 'get-media-usage',
        'media-list'         => 'cms_media_list',
        'save-page'          => 'save-page',
        'delete-page'        => 'delete-page',
        'generate-page'      => 'generate-page',
        'save-block'         => 'save-block',
        'add-block'          => 'add-block',
    ],
    'marketing' => [
        'generate'           => 'generate',
        'apply'              => 'apply',
        'apply-preview'      => 'apply-preview',
        'status'             => 'status',
        'targets'            => 'targets',
        'seasons'            => 'seasons',
    ],
    'seo' => [
        'generate'           => 'generate',
        'apply'              => 'apply',
        'apply-preview'      => 'apply-preview',
        'status'             => 'status',
        'targets'            => 'targets',
        'seasons'            => 'seasons',
    ],
    'database' => [
        'snapshot'           => 'schema-snapshot',
        'schema-snapshot'    => 'schema-snapshot',
        'migrations'         => 'migrations-manager',
        'apply-migrations'   => 'apply-migrations',
        'fix-properties'     => 'fix-properties-columns',
    ],
    'settings' => [
        'business'           => 'business-settings',
        'ops'                => 'ops-settings',
    ],
    'schedule' => [
        'day'                => 'day',
        'week'               => 'week',
        'clock'              => 'clock',       // Mobile time-clock (JWT) — GET status / POST clock_in|clock_out
        'timer'              => 'timer',       // Mobile job timer (JWT) — POST start|stop
        'location'           => 'location',   // Mobile GPS ping (JWT) — POST lat/lng
        'quiz'               => 'quiz',        // Mobile pre-shift quiz gate (JWT)
        'jobs'               => 'jobs',        // Mobile jobs history (JWT) — GET paginated visit list
        'quotes'             => 'quotes',      // Mobile quotes (JWT, admin only)
        'invoices'           => 'invoices',    // Mobile invoices (JWT, admin only)
        'job-photo'          => 'job-photo',   // Mobile before/after photo upload (JWT)
        'crew-trails'        => 'crew-trails', // Mobile crew GPS trail playback (JWT)
    ],
    'reports' => [
        'data'               => 'data',        // Mobile reports API (JWT, admin only)
    ],
];

// Resolve the filename
$filename = $action;
if (isset($actionAliases[$module][$action])) {
    $filename = $actionAliases[$module][$action];
}

// Build the full path
$apiFile = APP_ROOT . '/Modules/' . $moduleName . '/Api/' . $filename . '.php';

if (!is_file($apiFile)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => "Endpoint not found: {$module}/{$action}"]);
    exit;
}

// ── Dispatch ───────────────────────────────────────────────────
require $apiFile;
