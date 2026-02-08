<?php
/**
 * /crm/api/seo/generate.php
 * AJAX Endpoint: Manually trigger recommendation generation
 *
 * POST /crm/api/seo/generate.php
 * Requires: admin auth + CSRF token
 *
 * Response: {success: bool, message: string, stats: {...}}
 */

declare(strict_types=1);
header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
require_once dirname(__DIR__, 2) . '/includes/seo-functions.php';

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
$response = include dirname(__DIR__, 2) . '/cron/seo_recommendations.php';
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
