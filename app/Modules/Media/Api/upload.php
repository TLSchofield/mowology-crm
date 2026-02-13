<?php
/**
 * Unified Media Upload API Endpoint
 *
 * POST /crm/api/media-upload.php
 *
 * Accepts multi-file upload with context metadata, generates responsive
 * variants and optional PoW stamps. Returns JSON per-file results.
 *
 * POST fields:
 *   files[]        - One or more image files
 *   csrf_token     - Required CSRF token
 *   context_type   - Context: cms, job_visit, quote_visit, marketing_general, etc.
 *   context_id     - ID in the context table (0 for general)
 *   category       - before, during, after, issue, other
 *   visibility     - internal, client_visible, marketing_eligible
 *   gps_lat        - Browser geolocation latitude (optional)
 *   gps_lng        - Browser geolocation longitude (optional)
 *   gps_accuracy   - Browser geolocation accuracy in meters (optional)
 *   pow_stamp      - "1" to generate proof-of-work stamp (optional)
 *   alt_text       - Alt text for accessibility (optional)
 *
 * @package Mowology CRM
 * @subpackage Media API
 */

declare(strict_types=1);

// Bootstrap paths
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
require_once APP_ROOT . '/Services/Media/MediaUploadService.php';
require_once APP_ROOT . '/Services/Media/MediaVariantGenerator.php';
require_once APP_ROOT . '/Services/Media/PowStampGenerator.php';

header('Content-Type: application/json');

// --- Auth ---
requireLogin();
$user = getCurrentUser();

if (!in_array($user['role'], ['admin', 'staff'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

// --- CSRF ---
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token invalid']);
    exit;
}

// --- Method check ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

// --- Parse context fields ---
$contextType = $_POST['context_type'] ?? 'marketing_general';
$contextId = (int)($_POST['context_id'] ?? 0);
$category = $_POST['category'] ?? null;
$visibility = $_POST['visibility'] ?? 'internal';
$altText = $_POST['alt_text'] ?? null;
$powStamp = ($_POST['pow_stamp'] ?? '0') === '1';

// Validate context_type against allowed values
$allowedContextTypes = ['cms', 'job_visit', 'quote_visit', 'job_plan', 'property', 'portfolio', 'marketing_general', 'client_general', 'internal_general'];
if (!in_array($contextType, $allowedContextTypes)) {
    $contextType = 'marketing_general';
}

// Validate visibility — staff cannot set marketing_eligible
$allowedVisibility = ['internal', 'client_visible'];
if ($user['role'] === 'admin') {
    $allowedVisibility[] = 'marketing_eligible';
}
if (!in_array($visibility, $allowedVisibility)) {
    $visibility = 'internal';
}

// --- Parse browser GPS ---
$browserGps = null;
if (!empty($_POST['gps_lat']) && !empty($_POST['gps_lng'])) {
    $lat = (float)$_POST['gps_lat'];
    $lng = (float)$_POST['gps_lng'];
    // Basic validation: lat [-90,90], lng [-180,180]
    if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
        $browserGps = [
            'lat' => $lat,
            'lng' => $lng,
            'accuracy' => (float)($_POST['gps_accuracy'] ?? 0),
        ];
    }
}

// --- Handle files ---
$files = normalizeFilesArray($_FILES['files'] ?? []);

if (empty($files)) {
    echo json_encode(['success' => false, 'error' => 'No files uploaded']);
    exit;
}

$results = [];
$allSuccess = true;

foreach ($files as $file) {
    // Upload the file
    $uploadResult = mediaUploadFile($file, (int)$user['id'], $contextType, $browserGps, $altText);

    if (!$uploadResult['success']) {
        $results[] = [
            'success' => false,
            'filename' => basename($file['name'] ?? 'unknown'),
            'errors' => $uploadResult['errors'],
        ];
        $allSuccess = false;
        continue;
    }

    $mediaId = $uploadResult['media_id'];
    $isDuplicate = $uploadResult['is_duplicate'] ?? false;

    // Create context link
    if ($contextId > 0 || $contextType !== 'marketing_general') {
        createMediaLink($mediaId, $contextType, $contextId, $category, $visibility, (int)$user['id']);
    }

    // Generate variants (skip for duplicates — they already have variants)
    $variantResult = null;
    if (!$isDuplicate) {
        $originalAbsPath = PUBLIC_ROOT . $uploadResult['file_path'];
        $variantResult = generateMediaVariants($mediaId, $originalAbsPath);
    }

    // Generate PoW stamp if requested and applicable
    $powResult = null;
    if ($powStamp && in_array($contextType, ['job_visit', 'quote_visit']) && $contextId > 0) {
        $powResult = generatePowStamp($mediaId, $contextType, $contextId);
    }

    // Build per-file result
    $thumbUrl = null;
    if ($variantResult && !empty($variantResult['thumb_url'])) {
        $thumbUrl = $variantResult['thumb_url'];
    } elseif ($isDuplicate) {
        $thumbUrl = getMediaThumbUrl($mediaId);
    }

    $results[] = [
        'success' => true,
        'media_id' => $mediaId,
        'uuid' => $uploadResult['uuid'],
        'filename' => basename($file['name'] ?? 'unknown'),
        'file_path' => $uploadResult['file_path'],
        'thumb_url' => $thumbUrl,
        'is_duplicate' => $isDuplicate,
        'variants_count' => $variantResult['variants_count'] ?? 0,
        'pow_stamp_url' => ($powResult && $powResult['success']) ? $powResult['derivative_path'] : null,
    ];
}

echo json_encode([
    'success' => $allSuccess || count(array_filter($results, fn($r) => $r['success'])) > 0,
    'results' => $results,
    'total_uploaded' => count(array_filter($results, fn($r) => $r['success'])),
    'total_failed' => count(array_filter($results, fn($r) => !$r['success'])),
]);
