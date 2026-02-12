<?php
/**
 * Page Generation API - Generate landing pages from templates
 *
 * Accepts POST requests with service + neighbourhood + template selection
 * Returns newly created page ID and redirect URL
 *
 * @package Mowology CRM
 * @subpackage CMS Phase 4 API
 */

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

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
require_once CRM_INCLUDES . '/cms-functions.php';
require_once CRM_INCLUDES . '/cms-token-engine.php';
require_once CRM_INCLUDES . '/page-generator.php';

requireLogin();
$user = getCurrentUser();

// Verify CSRF token
$token = $_POST['csrf_token'] ?? '';
if (empty($token) || !verifyCSRFToken($token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token invalid']);
    exit;
}

// Parse request
$generatorKey = trim($_POST['generator_key'] ?? '');
$service = trim($_POST['service'] ?? '');
$neighbourhood = trim($_POST['neighbourhood'] ?? '');
$customTitle = trim($_POST['custom_title'] ?? '');

// Validation
if (empty($generatorKey)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'generator_key is required']);
    exit;
}

if (empty($service) || empty($neighbourhood)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'service and neighbourhood are required']);
    exit;
}

// Load generator configuration
$config = pg_getGeneratorConfig($generatorKey);
if (!$config || !$config['enabled']) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Generator configuration not found or disabled']);
    exit;
}

// Build substitution variables
$variables = [
    'service' => $service,
    'neighbourhood' => $neighbourhood,
];

// Override title template if custom title provided
$configData = $config['config_data'];
if (!empty($customTitle)) {
    $configData['title_template'] = $customTitle;
}

// Generate page
$result = pg_generatePage($configData, $variables, $user['id']);

if (!$result['success']) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $result['errors'][0] ?? 'Generation failed'
    ]);
    exit;
}

// Return success
echo json_encode([
    'success' => true,
    'page_id' => $result['page_id'],
    'page_slug' => $result['page_slug'],
    'edit_url' => '/crm/cms/cms-page-edit.php?id=' . $result['page_id'],
    'message' => $result['message']
]);
