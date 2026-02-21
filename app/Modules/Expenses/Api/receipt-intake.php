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
    require_once APP_ROOT . '/Services/Receipts/ReceiptLearning.php';
    require_once APP_ROOT . '/Services/Receipts/VendorProductMatch.php';
    require_once APP_ROOT . '/Services/Receipts/TesseractPreScreen.php';

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

    // --- Rate Limiting: max 20 receipt uploads per user per hour ---
    // Stored as a simple DB row to survive PHP session expiry on mobile.
    try {
        $rlDb = getDB();
        $rlUserId = (int)$user['id'];
        $rlWindow = date('Y-m-d H:00:00'); // Current hour bucket

        // Upsert: increment counter for this user+hour
        $rlDb->prepare("
            INSERT INTO upload_rate_limits (user_id, window_start, upload_count)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE upload_count = upload_count + 1
        ")->execute([$rlUserId, $rlWindow]);

        // Check count
        $rlStmt = $rlDb->prepare("
            SELECT upload_count FROM upload_rate_limits
            WHERE user_id = ? AND window_start = ?
        ");
        $rlStmt->execute([$rlUserId, $rlWindow]);
        $rlCount = (int)($rlStmt->fetchColumn() ?: 0);

        if ($rlCount > 20) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Too many uploads — please wait before uploading more receipts (limit: 20/hour)']);
            exit;
        }
    } catch (Throwable $rlEx) {
        // If rate limit table doesn't exist yet, log and continue — non-blocking
        error_log('Rate limit check error (non-fatal): ' . $rlEx->getMessage());
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

    // --- Strip EXIF metadata by re-encoding through GD ---
    // Prevents GPS coordinates, device model/serial from being stored in the file.
    // EXIF values we need (GPS, date) are read below and stored in the DB instead.
    if (extension_loaded('gd') && in_array($mimeType, ['image/jpeg', 'image/jpg'])) {
        $stripped = @imagecreatefromjpeg($filePath);
        if ($stripped) {
            imagejpeg($stripped, $filePath, 92);
            imagedestroy($stripped);
        }
    } elseif (extension_loaded('gd') && $mimeType === 'image/png') {
        $stripped = @imagecreatefrompng($filePath);
        if ($stripped) {
            imagesavealpha($stripped, true);
            imagepng($stripped, $filePath, 6);
            imagedestroy($stripped);
        }
    } elseif (extension_loaded('gd') && $mimeType === 'image/webp') {
        $stripped = @imagecreatefromwebp($filePath);
        if ($stripped) {
            imagewebp($stripped, $filePath, 90);
            imagedestroy($stripped);
        }
    }

    // Get GPS from POST
    $lat = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float)$_POST['lat'] : null;
    $lng = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float)$_POST['lng'] : null;
    $jobId = isset($_POST['job_id']) && $_POST['job_id'] !== '' ? (int)$_POST['job_id'] : null;

    // ── SHA-256 Image Hash Dedup ──────────────────────────────────────
    $sha256 = hash_file('sha256', $filePath);
    $db = getDB();
    $duplicateImage = null;

    if ($sha256) {
        $checkHash = $db->prepare("
            SELECT id, file_path FROM media_assets
            WHERE sha256 = ? AND context_type = 'expense'
            LIMIT 1
        ");
        $checkHash->execute([$sha256]);
        $existing = $checkHash->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $duplicateImage = [
                'existing_media_id' => (int)$existing['id'],
                'existing_file_path' => $existing['file_path'],
            ];
        }
    }

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

    // Register in media_assets (include sha256 for future dedup)
    $stmt = $db->prepare("
        INSERT INTO media_assets
            (original_filename, stored_filename, file_path, file_type, mime_type, file_size,
             image_width, image_height, gps_lat, gps_lng, sha256, alt_text, context_type, created_by)
        VALUES (?, ?, ?, 'image', ?, ?, ?, ?, ?, ?, ?, 'Receipt photo', 'expense', ?)
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
        $sha256,
        $user['id'],
    ]);
    $mediaId = (int)$db->lastInsertId();

    // ── Tesseract Pre-Screen ──────────────────────────────────────────
    // Try a fast local Tesseract pass first. If it gets good text (score ≥ 70)
    // we skip the Vision API entirely (saves cost). Poor quality → Vision.
    // Not a receipt at all (score < 30) → skip all OCR, let user fill manually.
    $preScreen = tesseractPreScreen($filePath);
    $preScreenDecision = $preScreen['decision']; // use_tesseract | use_vision | skip

    $ocrResult = ['success' => false, 'text' => '', 'raw_response' => null, 'error' => null];
    $ocrSource  = 'none';  // 'tesseract' | 'vision' | 'none'

    if ($preScreenDecision === 'use_tesseract') {
        // Tesseract text is good enough — use it directly, skip Vision
        $ocrResult = [
            'success'      => true,
            'text'         => $preScreen['text'],
            'raw_response' => null,          // No bounding boxes from Tesseract
            'error'        => null,
        ];
        $ocrSource = 'tesseract';
    } elseif ($preScreenDecision === 'use_vision') {
        // Tesseract wasn't confident enough — call Vision for better extraction
        $ocrResult = extractTextFromImage($filePath);
        $ocrSource = 'vision';
    }
    // 'skip' → leave $ocrResult with success:false, user fills manually

    $ocrAvailable = $ocrResult['success'];
    $ocrText = $ocrResult['text'] ?? '';

    // Parse extracted text (pass raw Vision response for position-aware line items)
    $parsed = [];
    $suggestions = [];
    if ($ocrAvailable && !empty($ocrText)) {
        $rawResponse = $ocrResult['raw_response'] ?? null;
        $parsed = parseReceiptText($ocrText, $rawResponse);
        $suggestions = suggestReceiptMeta($ocrText, $lat, $lng, $jobId);

        // Apply learned vendor-specific patterns to enhance parsed results
        if (!empty($suggestions['vendor_id'])) {
            $parsed = applyLearnedPatterns((int)$suggestions['vendor_id'], $parsed, $ocrText);

            // Match OCR text against vendor's known product catalog
            $parsed = matchVendorProducts((int)$suggestions['vendor_id'], $ocrText, $parsed);
        }

        // Apply GST-exempt override: if matched vendor doesn't charge GST, zero it out
        if (!empty($suggestions['vendor_gst_exempt'])) {
            $parsed['gst'] = '0.00';
            $parsed['gst_estimated'] = false;
            $parsed['gst_exempt'] = true;
            // Recalculate: if subtotal exists, total = subtotal (no tax)
            if (!empty($parsed['subtotal']) && empty($parsed['pst'])) {
                $parsed['total'] = $parsed['subtotal'];
            }
        }

        // ── Word-Level Confidence Extraction ──────────────────────────
        $fieldConfidences = [];
        if ($rawResponse) {
            $wordConfidences = extractWordConfidenceMap($rawResponse);
            if (!empty($wordConfidences)) {
                $fieldConfidences = calculateFieldConfidences($parsed, $wordConfidences);
            }
        }

        // ── GST Math Validation ───────────────────────────────────────
        $gstValidation = validateGstMath($parsed);

        // ── Gated Vendor Auto-Creation ────────────────────────────────
        // Instead of eagerly creating vendors from OCR hints, use fuzzy matching
        // to prevent polluting the vendor table with OCR typos.
        if (empty($suggestions['vendor_id']) && !empty($parsed['vendor_hint'])) {
            $vendorName = strtoupper(trim($parsed['vendor_hint']));
            if (strlen($vendorName) >= 3) {
                try {
                    $gateResult = gateVendorAutoCreation($vendorName, $db);

                    if ($gateResult['action'] === 'match' && $gateResult['vendor']) {
                        // Fuzzy matched an existing vendor — use it
                        $suggestions['vendor_id']   = (int)$gateResult['vendor']['id'];
                        $suggestions['vendor_name']  = $gateResult['vendor']['name'];
                        $suggestions['vendor_confidence'] = 65;
                        $suggestions['match_details'][] = $gateResult['reason'];
                    } elseif ($gateResult['action'] === 'review' && $gateResult['vendor']) {
                        // Possible match — suggest but flag for review
                        $suggestions['vendor_id']   = (int)$gateResult['vendor']['id'];
                        $suggestions['vendor_name']  = $gateResult['vendor']['name'];
                        $suggestions['vendor_confidence'] = 40;
                        $suggestions['vendor_needs_review'] = true;
                        $suggestions['match_details'][] = $gateResult['reason'];
                    } elseif ($gateResult['action'] === 'create') {
                        // No close match — safe to auto-create
                        $checkStmt = $db->prepare("SELECT id FROM vendors WHERE UPPER(name) = ? LIMIT 1");
                        $checkStmt->execute([$vendorName]);
                        $existingId = $checkStmt->fetchColumn();

                        if ($existingId) {
                            $suggestions['vendor_id']   = (int)$existingId;
                            $suggestions['vendor_name']  = $vendorName;
                            $suggestions['vendor_confidence'] = 80;
                            $suggestions['match_details'][] = 'Exact match found on retry';
                        } else {
                            $insertStmt = $db->prepare("
                                INSERT INTO vendors (name, aliases, is_active)
                                VALUES (?, ?, 1)
                            ");
                            $insertStmt->execute([$vendorName, strtolower($vendorName)]);
                            $newVendorId = (int)$db->lastInsertId();

                            $suggestions['vendor_id']   = $newVendorId;
                            $suggestions['vendor_name']  = $vendorName;
                            $suggestions['vendor_confidence'] = 70;
                            $suggestions['match_details'][] = 'Auto-created from OCR vendor hint';
                            $suggestions['vendor_auto_created'] = true;
                        }
                    }
                } catch (Throwable $e) {
                    error_log('Auto vendor creation failed: ' . $e->getMessage());
                }
            }
        }
    }

    // Suggest matching job from today's/tomorrow's schedule (GPS + crew + time)
    $jobSuggestions = [];
    try {
        $jobSuggestions = suggestJobFromSchedule((int)$user['id'], $lat, $lng);
    } catch (Throwable $e) {
        error_log('Job suggestion error: ' . $e->getMessage());
    }

    // Return everything to the client
    echo json_encode([
        'success'           => true,
        'media_id'          => $mediaId,
        'file_path'         => $webPath,
        'ocr_text'          => $ocrText,
        'ocr_available'     => $ocrAvailable,
        'ocr_error'         => $ocrResult['error'] ?? null,
        'ocr_source'        => $ocrSource,          // 'tesseract' | 'vision' | 'none'
        'pre_screen'        => [                    // Pre-screen debug info
            'score'    => $preScreen['score'],
            'decision' => $preScreenDecision,
            'low_quality' => $preScreen['low_quality'],
        ],
        'parsed'            => $parsed,
        'suggestions'       => $suggestions,
        'job_suggestions'   => $jobSuggestions,
        'field_confidences' => $fieldConfidences ?? [],
        'gst_validation'    => $gstValidation ?? null,
        'duplicate_image'   => $duplicateImage,
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Receipt upload failed: ' . $e->getMessage()]);
}
