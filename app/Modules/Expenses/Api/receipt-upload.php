<?php
/**
 * iOS Receipt Upload API  — JWT-authenticated
 *
 * POST multipart/form-data:
 *   receipt_photo  — image file (required)
 *   lat            — GPS latitude (optional)
 *   lng            — GPS longitude (optional)
 *   job_id         — linked job ID (optional)
 *
 * Auth: Authorization: Bearer <jwt>  (no CSRF token needed)
 *
 * Returns same JSON shape as receipt-intake.php so iOS and web share models.
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
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Services/Receipts/ReceiptOCR.php';
    require_once APP_ROOT . '/Services/Receipts/ReceiptParser.php';
    require_once APP_ROOT . '/Services/Receipts/ReceiptSmartMatch.php';
    require_once APP_ROOT . '/Services/Receipts/ReceiptLearning.php';
    require_once APP_ROOT . '/Services/Receipts/VendorProductMatch.php';
    require_once APP_ROOT . '/Services/Receipts/TesseractPreScreen.php';

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }

    // Rate limiting: 20 uploads/user/hour (shared counter with web app)
    try {
        $rlDb     = getDB();
        $rlWindow = date('Y-m-d H:00:00');
        $rlStmt   = $rlDb->prepare("SELECT upload_count FROM upload_rate_limits WHERE user_id = ? AND window_start = ?");
        $rlStmt->execute([$userId, $rlWindow]);
        $rlCount = (int)($rlStmt->fetchColumn() ?: 0);
        if ($rlCount >= 20) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Too many uploads — limit: 20/hour']);
            exit;
        }
        $rlDb->prepare("INSERT INTO upload_rate_limits (user_id, window_start, upload_count) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE upload_count = upload_count + 1")->execute([$userId, $rlWindow]);
    } catch (Throwable $rlEx) {
        error_log('Rate limit check error (non-fatal): ' . $rlEx->getMessage());
    }

    // Validate file
    if (empty($_FILES['receipt_photo']) || $_FILES['receipt_photo']['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES['receipt_photo']['error'] ?? UPLOAD_ERR_NO_FILE;
        $errMsg  = [
            UPLOAD_ERR_INI_SIZE   => 'File too large (server limit)',
            UPLOAD_ERR_FORM_SIZE  => 'File too large',
            UPLOAD_ERR_PARTIAL    => 'Upload incomplete',
            UPLOAD_ERR_NO_FILE    => 'No file selected',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temp directory missing',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
        ][$errCode] ?? 'Upload error';
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $errMsg]);
        exit;
    }

    $file     = $_FILES['receipt_photo'];
    $mimeType = mime_content_type($file['tmp_name']);
    $allowed  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif'];
    if (!in_array($mimeType, $allowed)) {
        http_response_code(415);
        echo json_encode(['success' => false, 'error' => 'Only image files accepted (JPEG, PNG, GIF, WebP, HEIC)']);
        exit;
    }
    if ($file['size'] > 10 * 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['success' => false, 'error' => 'File too large (max 10MB)']);
        exit;
    }

    $uploadDir = PUBLIC_ROOT . '/uploads/receipts/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','gif','webp','heic','heif'])) $ext = 'jpg';
    $storedName = 'receipt-' . date('Ymd-His') . '-' . uniqid() . '.' . $ext;
    $filePath   = $uploadDir . $storedName;
    $webPath    = '/uploads/receipts/' . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file']);
        exit;
    }

    // Strip EXIF metadata by re-encoding through GD
    if (extension_loaded('gd')) {
        if (in_array($mimeType, ['image/jpeg', 'image/jpg'])) {
            $img = @imagecreatefromjpeg($filePath);
            if ($img) { imagejpeg($img, $filePath, 92); imagedestroy($img); }
        } elseif ($mimeType === 'image/png') {
            $img = @imagecreatefrompng($filePath);
            if ($img) { imagesavealpha($img, true); imagepng($img, $filePath, 6); imagedestroy($img); }
        } elseif ($mimeType === 'image/webp') {
            $img = @imagecreatefromwebp($filePath);
            if ($img) { imagewebp($img, $filePath, 90); imagedestroy($img); }
        }
    }

    // HEIC → JPEG conversion via Imagick (also strips EXIF)
    if (in_array($mimeType, ['image/heic', 'image/heif']) && extension_loaded('imagick')) {
        try {
            $im = new Imagick($filePath);
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(90);
            $im->stripImage();
            $newName    = preg_replace('/\.(heic|heif)$/i', '.jpg', $storedName);
            $newPath    = $uploadDir . $newName;
            $newWebPath = '/uploads/receipts/' . $newName;
            $im->writeImage($newPath);
            $im->destroy();
            @unlink($filePath);
            $filePath   = $newPath;
            $webPath    = $newWebPath;
            $storedName = $newName;
            $mimeType   = 'image/jpeg';
        } catch (Throwable $heicEx) {
            error_log('HEIC→JPEG failed: ' . $heicEx->getMessage());
        }
    }

    $lat   = isset($_POST['lat'])    && $_POST['lat']    !== '' ? (float)$_POST['lat']    : null;
    $lng   = isset($_POST['lng'])    && $_POST['lng']    !== '' ? (float)$_POST['lng']    : null;
    $jobId = isset($_POST['job_id']) && $_POST['job_id'] !== '' ? (int)$_POST['job_id']   : null;

    // SHA-256 dedup
    $sha256        = hash_file('sha256', $filePath);
    $db            = getDB();
    $duplicateImage = null;
    if ($sha256) {
        $chk = $db->prepare("SELECT id, file_path FROM media_assets WHERE sha256 = ? AND context_type = 'expense' LIMIT 1");
        $chk->execute([$sha256]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);
        if ($existing) $duplicateImage = ['existing_media_id' => (int)$existing['id'], 'existing_file_path' => $existing['file_path']];
    }

    // Image dimensions
    $imgWidth = $imgHeight = null;
    if (function_exists('getimagesize')) {
        $info = @getimagesize($filePath);
        if ($info) { $imgWidth = $info[0]; $imgHeight = $info[1]; }
    }

    // Register media asset
    $stmt = $db->prepare("INSERT INTO media_assets (original_filename, stored_filename, file_path, file_type, mime_type, file_size, image_width, image_height, gps_lat, gps_lng, sha256, alt_text, context_type, created_by) VALUES (?, ?, ?, 'image', ?, ?, ?, ?, ?, ?, ?, 'Receipt photo', 'expense', ?)");
    $stmt->execute([$file['name'], $storedName, $webPath, $mimeType, $file['size'], $imgWidth, $imgHeight, $lat, $lng, $sha256, $userId]);
    $mediaId = (int)$db->lastInsertId();

    // OCR pipeline — identical to receipt-intake.php
    $preScreen        = tesseractPreScreen($filePath);
    $earlyVendorId    = null;
    if (!empty($preScreen['text'])) {
        try {
            $earlyMatch    = suggestReceiptMeta($preScreen['text'], null, null, null);
            $earlyVendorId = !empty($earlyMatch['vendor_id']) ? (int)$earlyMatch['vendor_id'] : null;
        } catch (Throwable $e) { /* non-fatal */ }
    }
    $tessThreshold = getVendorTesseractThreshold($earlyVendorId);
    $preScreenScore = $preScreen['score'];
    if (empty($preScreen['error']) && $preScreenScore > 0) {
        $preScreenDecision = $preScreenScore >= $tessThreshold ? 'use_tesseract' : ($preScreenScore >= 30 ? 'use_vision' : 'skip');
    } else {
        $preScreenDecision = $preScreen['decision'];
    }

    $ocrResult = ['success' => false, 'text' => '', 'raw_response' => null, 'error' => null];
    $ocrSource  = 'none';
    if ($preScreenDecision === 'use_tesseract') {
        $ocrResult = ['success' => true, 'text' => $preScreen['text'], 'raw_response' => null, 'error' => null];
        $ocrSource = 'tesseract';
    } elseif ($preScreenDecision === 'use_vision') {
        $ocrResult = extractTextFromImage($filePath);
        $ocrSource = 'vision';
    }

    $ocrAvailable = $ocrResult['success'];
    $ocrText      = $ocrResult['text'] ?? '';
    $parsed       = new stdClass();
    $suggestions  = new stdClass();

    if ($ocrAvailable && !empty($ocrText)) {
        $parsed      = parseReceiptText($ocrText, $ocrResult['raw_response'] ?? null);
        $suggestions = suggestReceiptMeta($ocrText, $lat, $lng, $jobId, $parsed);

        if (!empty($suggestions['vendor_id'])) {
            $parsed = applyLearnedPatterns((int)$suggestions['vendor_id'], $parsed, $ocrText);
            $parsed = matchVendorProducts((int)$suggestions['vendor_id'], $ocrText, $parsed);
        }

        if (!empty($suggestions['vendor_gst_exempt'])) {
            $parsed['gst'] = '0.00';
            $parsed['gst_estimated'] = false;
            $parsed['gst_exempt'] = true;
            if (!empty($parsed['subtotal']) && empty($parsed['pst'])) $parsed['total'] = $parsed['subtotal'];
        }

        // Auto-create vendor from OCR hint if no match found
        if (empty($suggestions['vendor_id']) && !empty($parsed['vendor_hint'])) {
            $vendorName = strtoupper(trim($parsed['vendor_hint']));
            if (strlen($vendorName) >= 3) {
                try {
                    $gateResult = gateVendorAutoCreation($vendorName, $db);
                    if ($gateResult['action'] === 'match' && $gateResult['vendor']) {
                        $suggestions['vendor_id']         = (int)$gateResult['vendor']['id'];
                        $suggestions['vendor_name']       = $gateResult['vendor']['name'];
                        $suggestions['vendor_confidence'] = 65;
                    } elseif ($gateResult['action'] === 'review' && $gateResult['vendor']) {
                        $suggestions['vendor_id']              = (int)$gateResult['vendor']['id'];
                        $suggestions['vendor_name']            = $gateResult['vendor']['name'];
                        $suggestions['vendor_confidence']      = 40;
                        $suggestions['vendor_needs_review']    = true;
                    } elseif ($gateResult['action'] === 'create') {
                        $chk2 = $db->prepare("SELECT id FROM vendors WHERE UPPER(name) = ? LIMIT 1");
                        $chk2->execute([$vendorName]);
                        $existingId = $chk2->fetchColumn();
                        if ($existingId) {
                            $suggestions['vendor_id']         = (int)$existingId;
                            $suggestions['vendor_name']       = $vendorName;
                            $suggestions['vendor_confidence'] = 80;
                        } else {
                            $ins = $db->prepare("INSERT INTO vendors (name, aliases, is_active) VALUES (?, ?, 1)");
                            $ins->execute([$vendorName, strtolower($vendorName)]);
                            $suggestions['vendor_id']           = (int)$db->lastInsertId();
                            $suggestions['vendor_name']         = $vendorName;
                            $suggestions['vendor_confidence']   = 70;
                            $suggestions['vendor_auto_created'] = true;
                        }
                    }
                } catch (Throwable $e) {
                    error_log('Auto vendor creation failed: ' . $e->getMessage());
                }
            }
        }
    }

    // Suggest nearby job from schedule
    $jobSuggestions = null;
    try {
        $jobSuggestions = suggestJobFromSchedule($userId, $lat, $lng);
    } catch (Throwable $e) {
        error_log('Job suggestion error: ' . $e->getMessage());
    }

    echo json_encode([
        'success'         => true,
        'media_id'        => $mediaId,
        'file_path'       => $webPath,
        'ocr_text'        => $ocrText,
        'ocr_available'   => $ocrAvailable,
        'ocr_source'      => $ocrSource,
        'parsed'          => $parsed,
        'suggestions'     => $suggestions,
        'job_suggestions' => $jobSuggestions,
        'duplicate_image' => $duplicateImage,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Receipt upload failed: ' . $e->getMessage()]);
}
