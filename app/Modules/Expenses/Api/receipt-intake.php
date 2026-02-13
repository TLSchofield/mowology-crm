<?php
/**
 * API: Receipt Intake
 *
 * One-shot endpoint: upload receipt photo → OCR → parse → smart match → return suggestions.
 *
 * POST multipart/form-data:
 *   receipt_photo  — image file (required)
 *   csrf_token     — CSRF token (required)
 *   lat            — GPS latitude (optional)
 *   lng            — GPS longitude (optional)
 *   job_id         — linked job ID (optional)
 *
 * Returns JSON:
 *   { success, media_id, file_path, ocr_text, parsed: {...}, suggestions: {...}, ocr_available }
 *
 * If no Vision API key: uploads photo, returns ocr_available: false, user fills manually.
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

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Services/Receipts/ReceiptOCR.php';
    require_once APP_ROOT . '/Services/Receipts/ReceiptParser.php';
    require_once APP_ROOT . '/Services/Receipts/ReceiptSmartMatch.php';

    requireLogin();
    $user = getCurrentUser();
    requirePermission('expenses.edit');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }

    // CSRF check
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid security token']);
        exit;
    }

    // Validate file
    if (empty($_FILES['receipt_photo']) || $_FILES['receipt_photo']['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES['receipt_photo']['error'] ?? UPLOAD_ERR_NO_FILE;
        $errMsg = [
            UPLOAD_ERR_INI_SIZE   => 'File too large (server limit)',
            UPLOAD_ERR_FORM_SIZE  => 'File too large (form limit)',
            UPLOAD_ERR_PARTIAL    => 'Upload incomplete',
            UPLOAD_ERR_NO_FILE    => 'No file selected',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temp directory missing',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
        ][$errCode] ?? 'Upload error';
        echo json_encode(['success' => false, 'error' => $errMsg]);
        exit;
    }

    $file = $_FILES['receipt_photo'];

    // Validate mime type (images only)
    $mimeType = mime_content_type($file['tmp_name']);
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif'];
    if (!in_array($mimeType, $allowedMimes)) {
        echo json_encode(['success' => false, 'error' => 'Only image files are accepted (JPEG, PNG, GIF, WebP, HEIC)']);
        exit;
    }

    // Max 10MB for receipts
    if ($file['size'] > 10 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'File too large (max 10MB)']);
        exit;
    }

    // Store in /uploads/receipts/ (separate from CMS media)
    $uploadDir = PUBLIC_ROOT . '/uploads/receipts/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif'])) {
        $ext = 'jpg'; // Safe fallback
    }
    $storedName = 'receipt-' . date('Ymd-His') . '-' . uniqid() . '.' . $ext;
    $filePath = $uploadDir . $storedName;
    $webPath = '/uploads/receipts/' . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file']);
        exit;
    }

    // Get GPS from POST
    $lat = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float)$_POST['lat'] : null;
    $lng = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float)$_POST['lng'] : null;
    $jobId = isset($_POST['job_id']) && $_POST['job_id'] !== '' ? (int)$_POST['job_id'] : null;

    // Get image dimensions
    $imgWidth = null;
    $imgHeight = null;
    if (function_exists('getimagesize')) {
        $imgInfo = @getimagesize($filePath);
        if ($imgInfo) {
            $imgWidth = $imgInfo[0];
            $imgHeight = $imgInfo[1];
        }
    }

    // Register in media_assets
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO media_assets
            (original_filename, stored_filename, file_path, file_type, mime_type, file_size,
             image_width, image_height, gps_lat, gps_lng, alt_text, created_by)
        VALUES (?, ?, ?, 'image', ?, ?, ?, ?, ?, ?, 'Receipt photo', ?)
    ");
    $stmt->execute([
        $file['name'],
        $storedName,
        $webPath,
        $mimeType,
        $file['size'],
        $imgWidth,
        $imgHeight,
        $lat,
        $lng,
        $user['id'],
    ]);
    $mediaId = (int)$db->lastInsertId();

    // Run OCR
    $ocrResult = extractTextFromImage($filePath);
    $ocrAvailable = $ocrResult['success'];
    $ocrText = $ocrResult['text'] ?? '';

    // Parse extracted text
    $parsed = [];
    $suggestions = [];
    if ($ocrAvailable && !empty($ocrText)) {
        $parsed = parseReceiptText($ocrText);
        $suggestions = suggestReceiptMeta($ocrText, $lat, $lng, $jobId);
    }

    // Return everything to the client
    echo json_encode([
        'success'       => true,
        'media_id'      => $mediaId,
        'file_path'     => $webPath,
        'ocr_text'      => $ocrText,
        'ocr_available' => $ocrAvailable,
        'ocr_error'     => $ocrResult['error'] ?? null,
        'parsed'        => $parsed,
        'suggestions'   => $suggestions,
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
