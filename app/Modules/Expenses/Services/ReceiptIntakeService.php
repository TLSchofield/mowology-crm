<?php
/**
 * ReceiptIntakeService — shared capture→OCR→parse→match pipeline for receipt uploads.
 *
 * Extracted so the web (session/CSRF, receipt-intake.php) and iOS (JWT, receipt-upload.php)
 * upload endpoints stop maintaining two near-identical ~250-line copies of this logic. Both
 * callers get the same file validation, EXIF/HEIC handling, SHA-256 dedup, Tesseract/Vision
 * two-tier OCR, and vendor/job matching — differences are only in auth (handled by the caller
 * before intake() is called) and in which response fields each platform's client expects.
 *
 * Web-only extras (the rescan-mode branch in receipt-intake.php, which re-runs OCR on an
 * already-stored image with no new upload) are NOT part of this service — iOS has no
 * equivalent feature, so that stays inline in receipt-intake.php rather than being forced
 * into a shared method neither caller fully needs.
 *
 * intake() never throws for expected failure modes (bad file, rate limit, etc.) — it returns
 * ['success' => false, 'error' => ..., 'http_code' => ...] so each thin controller can apply
 * its own historical HTTP status code without the two callers drifting on error-handling
 * logic. Unexpected errors (DB, OCR service down) still bubble up as exceptions for the
 * caller's top-level catch block to turn into a 500.
 *
 * The upload-validation rules (mime allowlist, extension fallback, rate-limit threshold)
 * are exposed as pure static methods so they're unit-testable without a DB, APP_ROOT, or
 * real files — the same DB-free-helper pattern used by ReceiptArchiveService.
 */
class ReceiptIntakeService
{
    public const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif'];
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif'];
    public const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024;
    public const RATE_LIMIT_PER_HOUR = 20;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public static function isAllowedMimeType(string $mimeType): bool
    {
        return in_array($mimeType, self::ALLOWED_MIME_TYPES, true);
    }

    /** Safe stored-file extension for an uploaded filename — falls back to 'jpg' for anything unrecognized. */
    public static function resolveStoredExtension(string $originalFilename): string
    {
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        return in_array($ext, self::ALLOWED_EXTENSIONS, true) ? $ext : 'jpg';
    }

    public static function isRateLimited(int $currentHourCount): bool
    {
        return $currentHourCount >= self::RATE_LIMIT_PER_HOUR;
    }

    /**
     * @param int        $userId
     * @param array      $uploadedFile  $_FILES['receipt_photo'] shape (name, tmp_name, size, error)
     * @param float|null $lat
     * @param float|null $lng
     * @param int|null   $jobId
     * @return array Result shape always includes 'success'; on failure also 'error' and
     *               'http_code'. On success, includes media_id/file_path/ocr fields/parsed/
     *               suggestions/job_suggestions/field_confidences/gst_validation/duplicate_image.
     */
    public function intake(int $userId, array $uploadedFile, ?float $lat, ?float $lng, ?int $jobId): array
    {
        require_once APP_ROOT . '/Services/Receipts/ReceiptOCR.php';
        require_once APP_ROOT . '/Services/Receipts/ReceiptParser.php';
        require_once APP_ROOT . '/Services/Receipts/ReceiptSmartMatch.php';
        require_once APP_ROOT . '/Services/Receipts/ReceiptLearning.php';
        require_once APP_ROOT . '/Services/Receipts/VendorProductMatch.php';
        require_once APP_ROOT . '/Services/Receipts/TesseractPreScreen.php';

        // ── Rate limiting: max 20 receipt uploads per user per hour ──────
        // Read-before-write so a rejected upload never permanently consumes a slot.
        try {
            $rlWindow = date('Y-m-d H:00:00');
            $rlStmt = $this->db->prepare("
                SELECT upload_count FROM upload_rate_limits
                WHERE user_id = ? AND window_start = ?
            ");
            $rlStmt->execute([$userId, $rlWindow]);
            $rlCount = (int)($rlStmt->fetchColumn() ?: 0);

            if (self::isRateLimited($rlCount)) {
                return [
                    'success'   => false,
                    'error'     => 'Too many uploads — please wait before uploading more receipts (limit: 20/hour)',
                    'http_code' => 429,
                ];
            }

            $this->db->prepare("
                INSERT INTO upload_rate_limits (user_id, window_start, upload_count)
                VALUES (?, ?, 1)
                ON DUPLICATE KEY UPDATE upload_count = upload_count + 1
            ")->execute([$userId, $rlWindow]);
        } catch (Throwable $rlEx) {
            // If the rate limit table doesn't exist yet, log and continue — non-blocking
            error_log('Rate limit check error (non-fatal): ' . $rlEx->getMessage());
        }

        // ── Validate file ─────────────────────────────────────────────
        if (empty($uploadedFile) || ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errCode = $uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE;
            $errMsg = [
                UPLOAD_ERR_INI_SIZE   => 'File too large (server limit)',
                UPLOAD_ERR_FORM_SIZE  => 'File too large (form limit)',
                UPLOAD_ERR_PARTIAL    => 'Upload incomplete',
                UPLOAD_ERR_NO_FILE    => 'No file selected',
                UPLOAD_ERR_NO_TMP_DIR => 'Server temp directory missing',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
            ][$errCode] ?? 'Upload error';
            return ['success' => false, 'error' => $errMsg, 'http_code' => 400];
        }

        $mimeType = mime_content_type($uploadedFile['tmp_name']);
        if (!self::isAllowedMimeType($mimeType)) {
            return [
                'success'   => false,
                'error'     => 'Only image files are accepted (JPEG, PNG, GIF, WebP, HEIC)',
                'http_code' => 415,
            ];
        }

        if ($uploadedFile['size'] > self::MAX_FILE_SIZE_BYTES) {
            return ['success' => false, 'error' => 'File too large (max 10MB)', 'http_code' => 413];
        }

        // ── Store in /uploads/receipts/ (separate from CMS media) ────────
        $uploadDir = PUBLIC_ROOT . '/uploads/receipts/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext = self::resolveStoredExtension($uploadedFile['name']);
        $storedName = 'receipt-' . date('Ymd-His') . '-' . uniqid() . '.' . $ext;
        $filePath = $uploadDir . $storedName;
        $webPath = '/uploads/receipts/' . $storedName;

        if (!move_uploaded_file($uploadedFile['tmp_name'], $filePath)) {
            return ['success' => false, 'error' => 'Failed to save uploaded file', 'http_code' => 500];
        }

        // ── Strip EXIF metadata by re-encoding through GD ─────────────────
        // Prevents GPS coordinates, device model/serial from being stored in the file.
        // GPS/date are captured separately (POST lat/lng, EXIF read pre-strip elsewhere) and
        // stored in the DB instead.
        if (extension_loaded('gd') && in_array($mimeType, ['image/jpeg', 'image/jpg'], true)) {
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

        // HEIC/HEIF bypasses GD stripping (GD cannot read HEIC) — convert to JPEG via
        // Imagick (strips all EXIF/GPS metadata in the process). If Imagick is unavailable,
        // the file is stored as-is and GPS coordinates from POST are used instead of raw EXIF.
        if (in_array($mimeType, ['image/heic', 'image/heif'], true)) {
            if (extension_loaded('imagick')) {
                try {
                    $im = new Imagick($filePath);
                    $im->setImageFormat('jpeg');
                    $im->setImageCompressionQuality(90);
                    $im->stripImage();
                    $newStoredName = preg_replace('/\.(heic|heif)$/i', '.jpg', $storedName);
                    $newFilePath = $uploadDir . $newStoredName;
                    $newWebPath = '/uploads/receipts/' . $newStoredName;
                    $im->writeImage($newFilePath);
                    $im->destroy();
                    @unlink($filePath);
                    $filePath = $newFilePath;
                    $webPath = $newWebPath;
                    $storedName = $newStoredName;
                    $mimeType = 'image/jpeg';
                } catch (Throwable $heicEx) {
                    error_log('HEIC→JPEG conversion failed for ' . $storedName . ': ' . $heicEx->getMessage());
                }
            } else {
                error_log('HEIC EXIF warning: Imagick unavailable, raw HEIC stored: ' . $storedName);
            }
        }

        // ── SHA-256 Image Hash Dedup ───────────────────────────────────
        $sha256 = hash_file('sha256', $filePath);
        $duplicateImage = null;

        if ($sha256) {
            $checkHash = $this->db->prepare("
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

        $imgWidth = null;
        $imgHeight = null;
        if (function_exists('getimagesize')) {
            $imgInfo = @getimagesize($filePath);
            if ($imgInfo) {
                $imgWidth = $imgInfo[0];
                $imgHeight = $imgInfo[1];
            }
        }

        $stmt = $this->db->prepare("
            INSERT INTO media_assets
                (original_filename, stored_filename, file_path, file_type, mime_type, file_size,
                 image_width, image_height, gps_lat, gps_lng, sha256, alt_text, context_type, created_by)
            VALUES (?, ?, ?, 'image', ?, ?, ?, ?, ?, ?, ?, 'Receipt photo', 'expense', ?)
        ");
        $stmt->execute([
            $uploadedFile['name'],
            $storedName,
            $webPath,
            $mimeType,
            $uploadedFile['size'],
            $imgWidth,
            $imgHeight,
            $lat,
            $lng,
            $sha256,
            $userId,
        ]);
        $mediaId = (int)$this->db->lastInsertId();

        // ── Tesseract Pre-Screen ─────────────────────────────────────────
        // Fast local pass first; score >= vendor threshold skips the paid Vision API.
        // Score < 30 means "not a receipt" — skip all OCR, user fills manually.
        $preScreen = tesseractPreScreen($filePath);

        $earlyVendorId = null;
        if (!empty($preScreen['text'])) {
            try {
                $earlyMatch = suggestReceiptMeta($preScreen['text'], null, null, null);
                $earlyVendorId = !empty($earlyMatch['vendor_id']) ? (int)$earlyMatch['vendor_id'] : null;
            } catch (Throwable $e) { /* non-fatal */ }
        }
        $tessThreshold = getVendorTesseractThreshold($earlyVendorId);
        $preScreenScore = $preScreen['score'];
        if (empty($preScreen['error']) && $preScreenScore > 0) {
            $preScreenDecision = $preScreenScore >= $tessThreshold ? 'use_tesseract'
                : ($preScreenScore >= 30 ? 'use_vision' : 'skip');
        } else {
            $preScreenDecision = $preScreen['decision']; // use_vision (Tesseract failed/missing)
        }

        $ocrResult = ['success' => false, 'text' => '', 'raw_response' => null, 'error' => null];
        $ocrSource = 'none'; // 'tesseract' | 'vision' | 'none'

        if ($preScreenDecision === 'use_tesseract') {
            $ocrResult = [
                'success'      => true,
                'text'         => $preScreen['text'],
                'raw_response' => null, // No bounding boxes from Tesseract
                'error'        => null,
            ];
            $ocrSource = 'tesseract';
        } elseif ($preScreenDecision === 'use_vision') {
            $ocrResult = extractTextFromImage($filePath);
            $ocrSource = 'vision';
        }
        // 'skip' → leave $ocrResult with success:false, user fills manually

        $ocrAvailable = $ocrResult['success'];
        $ocrText = $ocrResult['text'] ?? '';

        $parsed = [];
        $suggestions = [];
        $fieldConfidences = [];
        $gstValidation = null;

        if ($ocrAvailable && !empty($ocrText)) {
            $rawResponse = $ocrResult['raw_response'] ?? null;

            // Vendor-aware parse: the vendor's learned line-item profile (noise lines,
            // known item names, barcode length, discount prefix) shapes how items are
            // split, not just how they're labelled afterwards.
            require_once APP_ROOT . '/Services/Receipts/ReceiptLineItemIntelligence.php';
            $profileVendorId = $earlyVendorId;
            if (!$profileVendorId) {
                try {
                    $pre = suggestReceiptMeta($ocrText, null, null, null);
                    $profileVendorId = !empty($pre['vendor_id']) ? (int)$pre['vendor_id'] : null;
                } catch (Throwable $e) { /* non-fatal */ }
            }
            $liProfile = getVendorLineItemProfile($profileVendorId);
            $parsed = parseReceiptText($ocrText, $rawResponse, $liProfile);

            // Items don't add up? Escalate Tesseract→Vision, then the LLM tier.
            $enh = enhanceLineItemExtraction([
                'file_path'      => $filePath,
                'ocr_source'     => $ocrSource,
                'ocr_text'       => $ocrText,
                'raw_response'   => $rawResponse,
                'parsed'         => $parsed,
                'vendor_id'      => $profileVendorId,
                'vendor_profile' => $liProfile,
            ]);
            $parsed      = $enh['parsed'];
            $ocrText     = $enh['ocr_text'];
            $rawResponse = $enh['raw_response'];
            $ocrSource   = $enh['ocr_source'];
            $ocrResult['text']         = $ocrText;
            $ocrResult['raw_response'] = $rawResponse;

            $suggestions = suggestReceiptMeta($ocrText, $lat, $lng, $jobId, $parsed);

            if (!empty($suggestions['vendor_id'])) {
                $parsed = applyLearnedPatterns((int)$suggestions['vendor_id'], $parsed, $ocrText);
                $parsed = matchVendorProducts((int)$suggestions['vendor_id'], $ocrText, $parsed);

                if (!empty($parsed['product_matches']) && empty($suggestions['category_from_line_items'])) {
                    $enrichedSuggestions = suggestReceiptMeta($ocrText, null, null, null, $parsed);
                    if (!empty($enrichedSuggestions['category_from_line_items'])) {
                        $suggestions['accounting_category'] = $enrichedSuggestions['accounting_category'];
                        $suggestions['category_confidence'] = $enrichedSuggestions['category_confidence'];
                        $suggestions['category_from_line_items'] = true;
                    }
                }

                // Back-fill vendor location/phone/website if missing
                try {
                    $locData = extractVendorLocationFromOcr($ocrText);
                    if ($locData['phone'] || $locData['website']) {
                        $vRow = $this->db->prepare("SELECT phone, website FROM vendors WHERE id = ?");
                        $vRow->execute([(int)$suggestions['vendor_id']]);
                        $vCurrent = $vRow->fetch(PDO::FETCH_ASSOC);
                        if ($vCurrent) {
                            if ($locData['phone'] && empty($vCurrent['phone'])) {
                                $this->db->prepare("UPDATE vendors SET phone = ? WHERE id = ?")
                                    ->execute([$locData['phone'], (int)$suggestions['vendor_id']]);
                            }
                            if ($locData['website'] && empty($vCurrent['website'])) {
                                $this->db->prepare("UPDATE vendors SET website = ? WHERE id = ?")
                                    ->execute([$locData['website'], (int)$suggestions['vendor_id']]);
                            }
                        }
                    }
                    $locCount = $this->db->prepare("SELECT COUNT(*) FROM vendor_locations WHERE vendor_id = ?");
                    $locCount->execute([(int)$suggestions['vendor_id']]);
                    if ((int)$locCount->fetchColumn() === 0 && ($locData['address'] || $locData['city'] || $locData['phone'])) {
                        $locIns = $this->db->prepare("INSERT INTO vendor_locations (vendor_id, label, address, city, phone, is_preferred) VALUES (?, 'Main', ?, ?, ?, 1)");
                        $locIns->execute([(int)$suggestions['vendor_id'], $locData['address'], $locData['city'], $locData['phone']]);
                    }
                } catch (Throwable $le) {
                    error_log('Vendor location back-fill failed: ' . $le->getMessage());
                }
            }

            // Apply GST-exempt override: if matched vendor doesn't charge GST, zero it out
            if (!empty($suggestions['vendor_gst_exempt'])) {
                $parsed['gst'] = '0.00';
                $parsed['gst_estimated'] = false;
                $parsed['gst_exempt'] = true;
                if (!empty($parsed['subtotal']) && empty($parsed['pst'])) {
                    $parsed['total'] = $parsed['subtotal'];
                }
            }

            // Word-level confidence extraction
            if ($rawResponse) {
                $wordConfidences = extractWordConfidenceMap($rawResponse);
                if (!empty($wordConfidences)) {
                    $fieldConfidences = calculateFieldConfidences($parsed, $wordConfidences);
                }
            }

            $gstValidation = validateGstMath($parsed);

            // Gated vendor auto-creation — fuzzy match first to avoid OCR-typo pollution
            if (empty($suggestions['vendor_id']) && !empty($parsed['vendor_hint'])) {
                $vendorName = strtoupper(trim($parsed['vendor_hint']));
                if (strlen($vendorName) >= 3) {
                    try {
                        $gateResult = gateVendorAutoCreation($vendorName, $this->db);

                        if ($gateResult['action'] === 'match' && $gateResult['vendor']) {
                            $suggestions['vendor_id'] = (int)$gateResult['vendor']['id'];
                            $suggestions['vendor_name'] = $gateResult['vendor']['name'];
                            $suggestions['vendor_confidence'] = 65;
                            $suggestions['match_details'][] = $gateResult['reason'];
                        } elseif ($gateResult['action'] === 'review' && $gateResult['vendor']) {
                            $suggestions['vendor_id'] = (int)$gateResult['vendor']['id'];
                            $suggestions['vendor_name'] = $gateResult['vendor']['name'];
                            $suggestions['vendor_confidence'] = 40;
                            $suggestions['vendor_needs_review'] = true;
                            $suggestions['match_details'][] = $gateResult['reason'];
                        } elseif ($gateResult['action'] === 'create') {
                            $checkStmt = $this->db->prepare("SELECT id FROM vendors WHERE UPPER(name) = ? LIMIT 1");
                            $checkStmt->execute([$vendorName]);
                            $existingId = $checkStmt->fetchColumn();

                            if ($existingId) {
                                $suggestions['vendor_id'] = (int)$existingId;
                                $suggestions['vendor_name'] = $vendorName;
                                $suggestions['vendor_confidence'] = 80;
                                $suggestions['match_details'][] = 'Exact match found on retry';
                            } else {
                                $insertStmt = $this->db->prepare("
                                    INSERT INTO vendors (name, aliases, is_active)
                                    VALUES (?, ?, 1)
                                ");
                                $insertStmt->execute([$vendorName, strtolower($vendorName)]);
                                $newVendorId = (int)$this->db->lastInsertId();

                                $suggestions['vendor_id'] = $newVendorId;
                                $suggestions['vendor_name'] = $vendorName;
                                $suggestions['vendor_confidence'] = 70;
                                $suggestions['match_details'][] = 'Auto-created from OCR vendor hint';
                                $suggestions['vendor_auto_created'] = true;

                                $locData = extractVendorLocationFromOcr($ocrText ?? '');
                                if ($locData['address'] || $locData['city'] || $locData['phone']) {
                                    try {
                                        $locIns = $this->db->prepare("INSERT INTO vendor_locations (vendor_id, label, address, city, phone, is_preferred) VALUES (?, 'Main', ?, ?, ?, 1)");
                                        $locIns->execute([$newVendorId, $locData['address'], $locData['city'], $locData['phone']]);
                                    } catch (Throwable $le) {
                                        error_log('Vendor location from receipt failed: ' . $le->getMessage());
                                    }
                                }
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
            $jobSuggestions = suggestJobFromSchedule($userId, $lat, $lng);
        } catch (Throwable $e) {
            error_log('Job suggestion error: ' . $e->getMessage());
        }

        return [
            'success'           => true,
            'media_id'          => $mediaId,
            'file_path'         => $webPath,
            'ocr_text'          => $ocrText,
            'ocr_available'     => $ocrAvailable,
            'ocr_error'         => $ocrResult['error'] ?? null,
            'ocr_source'        => $ocrSource,
            'pre_screen'        => [
                'score'       => $preScreen['score'],
                'decision'    => $preScreenDecision,
                'low_quality' => $preScreen['low_quality'],
            ],
            'parsed'            => $parsed,
            'suggestions'       => $suggestions,
            'job_suggestions'   => $jobSuggestions,
            'field_confidences' => $fieldConfidences,
            'gst_validation'    => $gstValidation,
            'duplicate_image'   => $duplicateImage,
        ];
    }
}
