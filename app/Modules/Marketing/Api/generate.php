<?php
/**
 * /app/Modules/Marketing/Api/generate.php
 * AJAX Endpoint: Manually trigger recommendation generation
 *
 * POST /crm/api/seo/generate.php
 * Requires: admin auth + CSRF token
 *
 * Response: {success: bool, message: string, stats: {...}}
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

header('Content-Type: application/json');

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/seo-functions.php';

requireLogin();
$user = getCurrentUser();

if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Admin access required']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'POST required']));
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'CSRF token invalid']));
}

// Call the cron script's logic via include
ob_start();
$cronPath = CRM_ROOT . '/cron/seo_recommendations.php';
if (!file_exists($cronPath)) {
    ob_end_clean();
    http_response_code(500);
    die(json_encode([
        'success' => false,
        'message' => 'Cron script not found at: ' . $cronPath
    ]));
}

$response = include $cronPath;
$output = ob_get_clean();

// If the cron script has already output JSON, just return it
// Otherwise, compile a success response
if (json_last_error() === JSON_ERROR_NONE) {
    echo $output; // Cron already output JSON
} else {
    die(json_encode([
        'success' => true,
        'message' => 'Recommendations generated successfully',
        'output' => $output
    ]));
}
