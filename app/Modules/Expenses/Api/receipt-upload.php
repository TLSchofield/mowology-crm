<?php
/**
 * iOS Receipt Upload API — JWT-authenticated multipart endpoint
 *
 * POST multipart/form-data to /api/expenses/receipt-upload
 *   receipt_photo  — image file (required, JPEG/PNG/WebP/HEIC, max 10 MB)
 *   vision_text    — raw text from on-device iOS Vision OCR (optional, skips server Tesseract)
 *   lat            — GPS latitude  (optional, used for job suggestion + metadata)
 *   lng            — GPS longitude (optional)
 *   job_id         — pre-linked job plan ID (optional)
 *
 * Auth: Authorization: Bearer <jwt>   — no CSRF token needed (stateless Bearer auth)
 *
 * Response shape (used by iOS ReceiptIntakeResponse.swift — do NOT rename keys):
 *   success, media_id, file_path, ocr_text, ocr_available, ocr_source,
 *   parsed, suggestions, job_suggestions, duplicate_image
 *
 * ⚠ PRODUCTION SAFETY — read before editing:
 *   This file touches file storage, the OCR pipeline, the vendor DB, and the
 *   media_assets table in a single request. Each section notes what breaks if
 *   it is removed or modified carelessly.
 */
declare(strict_types=1);

// ── Bootstrap ────────────────────────────────────────────────────────────────
// Upward search for paths.php so this file works regardless of server document
// root. Defines APP_ROOT, PUBLIC_ROOT, CRM_INCLUDES.
// ⚠ Do NOT replace with a fixed dirname() depth — it breaks across environments.
// ⚠ Do NOT define APP_ROOT as PUBLIC_ROOT . '/app' — that doubles the 'app' segment.
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

    // ── OCR service stack ─────────────────────────────────────────────────────
    // All five files are required. If any is missing the endpoint throws at boot,
    // returning a 500 before touching the filesystem or DB.
    // ReceiptOCR.php     — extractTextFromImage() (Google Vision API fallback)
    // ReceiptParser.php  — parseReceiptText()     (amounts, dates, vendor hints)
    // ReceiptSmartMatch.php — suggestReceiptMeta(), gateVendorAutoCreation()
    // ReceiptLearning.php   — applyLearnedPatterns()
    // VendorProductMatch.php — matchVendorProducts() (line-item suggestions)
    // TesseractPreScreen.php — tesseractPreScreen(), getVendorTesseractThreshold()
    require_once APP_ROOT . '/Services/Receipts/ReceiptOCR.php';
    require_once APP_ROOT . '/Services/Receipts/ReceiptParser.php';
    require_once APP_ROOT . '/Services/Receipts/ReceiptSmartMatch.php';
    require_once APP_ROOT . '/Services/Receipts/ReceiptLearning.php';
    require_once APP_ROOT . '/Services/Receipts/VendorProductMatch.php';
    require_once APP_ROOT . '/Services/Receipts/TesseractPreScreen.php';

    // ── Authentication ────────────────────────────────────────────────────────
    // requireJwt() validates the Authorization: Bearer header and returns the
    // decoded payload (id, email, role, exp).
    // ⚠ Removing this makes the endpoint open to the public internet.
    //   Any unauthenticated request can upload files, run Tesseract OCR (CPU spike),
    //   and insert rows into media_assets and the vendors table.
    // ⚠ Do NOT switch to requireLogin() — iOS uses JWT, not session cookies.
    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }

    // ── Rate limiting ─────────────────────────────────────────────────────────
    // 20 uploads per user per clock-hour, counter shared with the web intake form.
    // Uses upload_rate_limits table: (user_id, window_start, upload_count).
    // Wrapped in try/catch so a missing table or DB hiccup does NOT block uploads —
    // rate limiting is protective, not a hard dependency.
    // ⚠ Raising the limit (20) or removing this block disables the only abuse guard.
    //   A single rogue device could flood disk/OCR in minutes.
    // ⚠ The ON DUPLICATE KEY UPDATE is intentional — avoids a race-condition gap
    //   between SELECT and INSERT when two uploads arrive simultaneously.
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

    // ── File presence + PHP upload error check ────────────────────────────────
    // PHP upload errors (UPLOAD_ERR_*) must be checked before any file operations.
    // ⚠ Never skip this block — $_FILES['receipt_photo']['tmp_name'] can exist but
    //   be empty/unreadable if an error code is set.
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

    $file = $_FILES['receipt_photo'];

    // ── MIME type validation ──────────────────────────────────────────────────
    // Uses mime_content_type() on the actual tmp file — NOT the client-supplied
    // Content-Type header (which is trivially spoofed).
    // ⚠ Do NOT use $file['type'] — it comes from the browser and cannot be trusted.
    // ⚠ Removing the MIME check allows PDFs, executables, and PHP files disguised
    //   as images to be stored on the server.
    $mimeType = mime_content_type($file['tmp_name']);
    $allowed  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif'];
    if (!in_array($mimeType, $allowed)) {
        http_response_code(415);
        echo json_encode(['success' => false, 'error' => 'Only image files accepted (JPEG, PNG, GIF, WebP, HEIC)']);
        exit;
    }

    // ── File size cap (10 MB) ─────────────────────────────────────────────────
    // iOS compresses to ~200-600 KB before upload (see ReceiptsView.resizeAndCompress).
    // The 10 MB cap is a server-side safety net for library-picker originals.
    // ⚠ Raising this limit with no other guards can exhaust /tmp and GD memory
    //   when processing very large originals (GD can use 4× file size in RAM).
    if ($file['size'] > 10 * 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['success' => false, 'error' => 'File too large (max 10MB)']);
        exit;
    }

    // ── Destination path ──────────────────────────────────────────────────────
    // Files land in /uploads/receipts/ which has Deny from all in .htaccess.
    // Direct URL access returns 403; images are served through receipt-image.php
    // (which validates the JWT and file ownership before streaming bytes).
    // ⚠ Changing the upload directory without updating receipt-image.php breaks
    //   the iOS image display — allowedPrefixes in that file must match.
    // ⚠ Do NOT store uploads anywhere inside /app/ — it's blocked by .htaccess too
    //   but that's a config file, not a path-safety guarantee.
    $uploadDir = PUBLIC_ROOT . '/uploads/receipts/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    // Filename: receipt-YYYYMMDD-HHmmss-<uniqid>.<ext>
    // uniqid() adds microsecond entropy; collision probability is negligible.
    // ⚠ Do NOT use $file['name'] directly — client-controlled filenames can
    //   contain path traversal sequences (../../etc/passwd).
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','gif','webp','heic','heif'])) $ext = 'jpg';
    $storedName = 'receipt-' . date('Ymd-His') . '-' . uniqid() . '.' . $ext;
    $filePath   = $uploadDir . $storedName;
    $webPath    = '/uploads/receipts/' . $storedName;

    // ⚠ move_uploaded_file() is the only safe way to move PHP upload temp files.
    //   copy() or rename() bypass the PHP upload validation layer.
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file']);
        exit;
    }

    // ── EXIF stripping (GD re-encode) ─────────────────────────────────────────
    // Receipts can contain GPS coordinates from the camera — re-encoding through
    // GD drops all EXIF metadata before the file is stored permanently.
    // ⚠ Removing this leaks the crew member's GPS location for every photo.
    // ⚠ Quality 92 for JPEG is intentional — Tesseract needs sharp text edges;
    //   dropping below ~80 causes character recognition errors.
    // ⚠ This block modifies $filePath in-place; the original tmp file is already gone.
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

    // ── HEIC → JPEG conversion (Imagick) ─────────────────────────────────────
    // iOS saves Live Photos and some camera shots as HEIC. Tesseract and most
    // other tools cannot read HEIC natively — conversion is mandatory.
    // ⚠ If this block is removed, HEIC receipts land in storage but OCR silently
    //   returns empty text; the iOS review sheet opens with no pre-fill at all.
    // ⚠ After conversion $filePath, $webPath, $storedName, and $mimeType are all
    //   updated. Any code below that uses these variables sees the JPEG path.
    //   Do NOT cache $filePath before this block.
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
            @unlink($filePath);   // remove the original HEIC
            $filePath   = $newPath;
            $webPath    = $newWebPath;
            $storedName = $newName;
            $mimeType   = 'image/jpeg';
        } catch (Throwable $heicEx) {
            error_log('HEIC→JPEG failed: ' . $heicEx->getMessage());
            // Non-fatal — original HEIC remains; OCR may still attempt processing
        }
    }

    $lat   = isset($_POST['lat'])    && $_POST['lat']    !== '' ? (float)$_POST['lat']    : null;
    $lng   = isset($_POST['lng'])    && $_POST['lng']    !== '' ? (float)$_POST['lng']    : null;
    $jobId = isset($_POST['job_id']) && $_POST['job_id'] !== '' ? (int)$_POST['job_id']   : null;

    // ── SHA-256 duplicate detection ───────────────────────────────────────────
    // Hashes the stored file (post-EXIF-strip, post-HEIC-convert) so identical
    // receipts from the same image are detected even across different uploads.
    // Result is returned to iOS as duplicate_image.existing_media_id so the app
    // can warn the user ("this receipt may already be submitted").
    // ⚠ This does NOT block the upload — the file is still stored and a new
    //   media_assets row is still inserted. The dedup is advisory only.
    // ⚠ Changing context_type = 'expense' filter affects which receipts are
    //   checked — widening it to ALL assets could return false positives for
    //   product photos, portfolio images, etc.
    $sha256         = hash_file('sha256', $filePath);
    $db             = getDB();
    $duplicateImage = null;
    if ($sha256) {
        $chk = $db->prepare("SELECT id, file_path FROM media_assets WHERE sha256 = ? AND context_type = 'expense' LIMIT 1");
        $chk->execute([$sha256]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);
        if ($existing) $duplicateImage = ['existing_media_id' => (int)$existing['id'], 'existing_file_path' => $existing['file_path']];
    }

    // Image dimensions — stored for UI display (thumbnail sizing, aspect ratio)
    $imgWidth = $imgHeight = null;
    if (function_exists('getimagesize')) {
        $info = @getimagesize($filePath);
        if ($info) { $imgWidth = $info[0]; $imgHeight = $info[1]; }
    }

    // ── media_assets INSERT ───────────────────────────────────────────────────
    // This row is the permanent link between the receipt image on disk and
    // every system that references it: expense records, receipt-image.php,
    // the iOS review sheet, the CRM expense list thumbnails.
    // ⚠ $mediaId (lastInsertId) is returned to iOS as media_id and stored in
    //   expense_records.receipt_media_id. If this INSERT is skipped or fails,
    //   the iOS app receives media_id = 0 and cannot load the receipt image.
    // ⚠ $webPath stored here must match the path served by receipt-image.php.
    //   If they diverge, the image 404s for every expense saved from this upload.
    // ⚠ context_type = 'expense' is used by the dedup check above and by
    //   serve-receipt.php allowedPrefixes logic. Do NOT change it.
    $stmt = $db->prepare("INSERT INTO media_assets (original_filename, stored_filename, file_path, file_type, mime_type, file_size, image_width, image_height, gps_lat, gps_lng, sha256, alt_text, context_type, created_by) VALUES (?, ?, ?, 'image', ?, ?, ?, ?, ?, ?, ?, 'Receipt photo', 'expense', ?)");
    $stmt->execute([$file['name'], $storedName, $webPath, $mimeType, $file['size'], $imgWidth, $imgHeight, $lat, $lng, $sha256, $userId]);
    $mediaId = (int)$db->lastInsertId();

    // ── OCR pipeline ──────────────────────────────────────────────────────────
    // Three possible text sources, evaluated in priority order:
    //
    //   1. ios_vision  — on-device Neural Engine text (sent by iOS app, ~200ms, best quality)
    //   2. tesseract   — server Tesseract (pre-screened; fast, good on clean images)
    //   3. vision      — Google Vision API (slower, costs money, best on poor-quality images)
    //   4. none        — OCR skipped (blurry image, confidence too low to attempt)
    //
    // The pre-screen step (tesseractPreScreen) always runs to:
    //   a) Provide early vendor identification for threshold tuning
    //   b) Decide whether the image quality justifies Tesseract or needs Vision API
    //
    // ⚠ Even when ios_vision is used, the pre-screen still runs (it's fast).
    //   This keeps per-vendor Tesseract thresholds up to date for web uploads.
    // ⚠ The pre-screen result ($preScreen['text']) is used for $earlyVendorId
    //   but NOT as the final OCR text — only the selected source's text flows
    //   into parseReceiptText(). Do not mix them up.
    $preScreen     = tesseractPreScreen($filePath);
    $earlyVendorId = null;
    if (!empty($preScreen['text'])) {
        try {
            $earlyMatch    = suggestReceiptMeta($preScreen['text'], null, null, null);
            $earlyVendorId = !empty($earlyMatch['vendor_id']) ? (int)$earlyMatch['vendor_id'] : null;
        } catch (Throwable $e) { /* non-fatal — threshold falls back to global default */ }
    }

    // Per-vendor Tesseract confidence threshold (vendors with known clean layouts
    // can use a lower threshold; premium vendors use a higher one to trigger Vision API).
    // ⚠ Changing the score boundaries (>= $tessThreshold vs >= 30) alters which
    //   receipts go through Vision API — too aggressive raises costs, too lenient
    //   produces empty OCR on blurry photos.
    $tessThreshold  = getVendorTesseractThreshold($earlyVendorId);
    $preScreenScore = $preScreen['score'];
    if (empty($preScreen['error']) && $preScreenScore > 0) {
        $preScreenDecision = $preScreenScore >= $tessThreshold ? 'use_tesseract' : ($preScreenScore >= 30 ? 'use_vision' : 'skip');
    } else {
        $preScreenDecision = $preScreen['decision'];
    }

    // ── Two-layer OCR pipeline ────────────────────────────────────────────────
    //
    // Layer 1 (fast, on-device):
    //   iOS app runs VisionOCRService.scan() on the Neural Engine (~200 ms) and
    //   sends the extracted text as vision_text in the multipart body.
    //   When present (>=10 chars), used directly — skips Tesseract.
    //   Falls back to Tesseract when iOS Vision text is absent.
    //
    // Layer 2 (authoritative, cloud):
    //   Google Cloud Vision DOCUMENT_TEXT_DETECTION runs on every upload when
    //   credentials are configured. Its parse result is the primary OCR text
    //   used by parseReceiptText() because it handles dense/angled receipts
    //   and Canadian bilingual tax labels better than on-device Vision or Tesseract.
    //   Layer 1 text fills in only when Google Vision is unavailable.
    //
    // ⚠ vision_text is client-supplied advisory data — never used for security
    //   decisions; only flows into text parsing via prepared-statement queries.
    // ⚠ Google Vision incurs a per-request API cost. Disable by leaving
    //   GOOGLE_VISION_CREDENTIALS undefined in secrets.php when not needed.

    $iosVisionText = !empty($_POST['vision_text']) ? trim($_POST['vision_text']) : null;

    // — Layer 1: resolve fast/local text —
    $phase1Text   = null;
    $phase1Source = 'none';
    if ($iosVisionText !== null && strlen($iosVisionText) >= 10) {
        $phase1Text   = $iosVisionText;
        $phase1Source = 'ios_vision';
    } elseif ($preScreenDecision === 'use_tesseract') {
        $phase1Text   = $preScreen['text'];
        $phase1Source = 'tesseract';
    }

    // — Layer 2: Google Cloud Vision (always runs when credentials are available) —
    $googleResult = null;
    if (defined('GOOGLE_VISION_CREDENTIALS') && GOOGLE_VISION_CREDENTIALS) {
        $googleResult = extractTextFromImage($filePath);
    }

    // — Merge: Google Vision wins when it succeeds; Layer 1 fills the gap —
    $ocrResult = ['success' => false, 'text' => '', 'raw_response' => null, 'error' => null];
    $ocrSource  = 'none';
    if (!empty($googleResult['success'])) {
        $ocrResult = $googleResult;
        $ocrSource = $phase1Text ? 'ios_vision+google' : 'google';
    } elseif ($phase1Text !== null) {
        $ocrResult = ['success' => true, 'text' => $phase1Text, 'raw_response' => null, 'error' => null];
        $ocrSource = $phase1Source;
    } elseif ($preScreenDecision === 'use_vision') {
        // Tesseract pre-screen flagged the image as Vision-worthy but Layer 2 was
        // unavailable (no credentials) — should not normally be reached.
        $ocrResult = ['success' => false, 'text' => '', 'raw_response' => null, 'error' => 'google_vision_unavailable'];
    }

    $ocrAvailable = $ocrResult['success'];
    $ocrText      = $ocrResult['text'] ?? '';
    $parsed       = new stdClass();
    $suggestions  = new stdClass();

    // ── Receipt parsing + vendor matching ────────────────────────────────────
    // Only runs when OCR produced substantive text.
    // parseReceiptText()    — extracts total, gst, subtotal, date, vendor_hint,
    //                          payment_method, line_items from raw OCR text.
    //                          Returns an array (not an object) — keys are snake_case.
    // suggestReceiptMeta()  — looks up vendor_id, accounting_category, confidence
    //                          scores by fuzzy-matching vendor_hint against the
    //                          vendors table. Also uses lat/lng proximity.
    // applyLearnedPatterns()— overrides parse results with corrections the crew
    //                          has historically made for this vendor (e.g., a
    //                          vendor always mis-classified gets auto-corrected).
    // matchVendorProducts() — matches line-item text against vendor product DB
    //                          to suggest product associations.
    //
    // ⚠ The order matters: applyLearnedPatterns() must run AFTER suggestReceiptMeta()
    //   because it needs vendor_id to look up the correction history.
    // ⚠ $parsed and $suggestions are arrays returned from these functions but
    //   initialised as stdClass objects above. PHP casts them implicitly when
    //   json_encode() serialises the response — do NOT change the initialisers
    //   to [] arrays or the iOS decoder (expecting an object) will receive an
    //   empty JSON array ([]) instead of an object ({}).
    if ($ocrAvailable && !empty($ocrText)) {
        $parsed      = parseReceiptText($ocrText, $ocrResult['raw_response'] ?? null);
        $suggestions = suggestReceiptMeta($ocrText, $lat, $lng, $jobId);

        if (!empty($suggestions['vendor_id'])) {
            $parsed = applyLearnedPatterns((int)$suggestions['vendor_id'], $parsed, $ocrText);
            $parsed = matchVendorProducts((int)$suggestions['vendor_id'], $ocrText, $parsed);
        }

        // ── GST-exempt vendor override ────────────────────────────────────────
        // Some vendors (municipalities, certain government fees) are GST-exempt.
        // When the vendor DB flags vendor_gst_exempt, zero out any parsed GST
        // and set the total equal to the subtotal.
        // ⚠ This fires AFTER applyLearnedPatterns() deliberately — learned
        //   corrections cannot override GST-exempt status.
        // ⚠ Only clears PST if $parsed['pst'] is absent — PST may still apply
        //   even when a vendor is GST-exempt.
        if (!empty($suggestions['vendor_gst_exempt'])) {
            $parsed['gst']           = '0.00';
            $parsed['gst_estimated'] = false;
            $parsed['gst_exempt']    = true;
            if (!empty($parsed['subtotal']) && empty($parsed['pst'])) $parsed['total'] = $parsed['subtotal'];
        }

        // ── Vendor auto-creation ──────────────────────────────────────────────
        // When OCR found a vendor hint but suggestReceiptMeta() found no DB match,
        // gateVendorAutoCreation() decides whether to:
        //   'match'  — fuzzy-matched an existing vendor (updates suggestions)
        //   'review' — low-confidence fuzzy match (flags vendor_needs_review)
        //   'create' — genuinely new vendor; inserts into vendors table
        //
        // ⚠ This can INSERT a new row into the vendors table on every unrecognised
        //   receipt. The gating logic in gateVendorAutoCreation() prevents duplicates
        //   and spam, but the gate thresholds matter — if loosened, every typo in
        //   a vendor name creates a new vendor row.
        // ⚠ The fallback SELECT (chk2) before INSERT prevents race-condition
        //   duplicates when two receipts from the same new vendor arrive concurrently.
        //   Do NOT remove the pre-check.
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
                        $suggestions['vendor_id']           = (int)$gateResult['vendor']['id'];
                        $suggestions['vendor_name']         = $gateResult['vendor']['name'];
                        $suggestions['vendor_confidence']   = 40;
                        $suggestions['vendor_needs_review'] = true;
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

    // ── Word-Level Confidence Extraction ─────────────────────────────────────
    // Mirrors receipt-intake.php — maps Vision bounding-box word confidences onto
    // parsed fields so iOS clients receive per-field accuracy signals (green/yellow/red).
    // Only populated when Google Cloud Vision raw_response is available (raw_response is
    // null on the ios_vision-only and tesseract-only paths).
    $fieldConfidences = [];
    $rawResponse = $ocrResult['raw_response'] ?? null;
    if ($rawResponse) {
        try {
            $wordConfidences = extractWordConfidenceMap($rawResponse);
            if (!empty($wordConfidences)) {
                $fieldConfidences = calculateFieldConfidences($parsed, $wordConfidences);
            }
        } catch (Throwable $e) {
            error_log('Confidence extraction failed: ' . $e->getMessage());
        }
    }

    // ── GST Math Validation ───────────────────────────────────────────────────
    // Verifies subtotal + GST + PST = total; flags mismatches for the review UI.
    // Previously only ran on the web path (receipt-intake.php); now runs on the
    // iOS path too so math errors are caught regardless of client.
    $gstValidation = null;
    if ($ocrAvailable) {
        try {
            $gstValidation = validateGstMath($parsed);
        } catch (Throwable $e) {
            error_log('GST math validation failed: ' . $e->getMessage());
        }
    }

    // ── Job suggestion ────────────────────────────────────────────────────────
    // Looks at the user's schedule for today and returns plans near $lat/$lng.
    // Displayed in the iOS review sheet as a pre-link dropdown.
    // ⚠ Entirely non-fatal — if this throws, the upload still succeeds and the
    //   expense is saved without a job link. The try/catch is intentional.
    $jobSuggestions = null;
    try {
        $jobSuggestions = suggestJobFromSchedule($userId, $lat, $lng);
    } catch (Throwable $e) {
        error_log('Job suggestion error: ' . $e->getMessage());
    }

    // ── Normalise line item numeric fields to strings ─────────────────────────
    // iOS ReceiptLineItem decodes quantity/amount/unit_price as String?.
    // ReceiptParser returns quantity as PHP int (e.g. 1) which json_encode
    // serialises as a JSON number, causing Swift typeMismatch on decodeIfPresent.
    if (is_array($parsed) && !empty($parsed['line_items'])) {
        $parsed['line_items'] = array_map(static function ($item) {
            if (isset($item['quantity']))   $item['quantity']   = (string)$item['quantity'];
            if (isset($item['amount']))     $item['amount']     = (string)$item['amount'];
            if (isset($item['unit_price'])) $item['unit_price'] = (string)$item['unit_price'];
            return $item;
        }, $parsed['line_items']);
    }

    // ── Response ──────────────────────────────────────────────────────────────
    // Shape is decoded by iOS ReceiptIntakeResponse.swift (CodingKeys are snake_case).
    // ⚠ Do NOT rename, remove, or reorder these keys without updating the Swift model.
    //   A missing key decodes as nil; a renamed key silently drops the field.
    // ⚠ ocr_text is included for the CRM web receipt-intake page; iOS ignores it
    //   but it is stored in expense_records for learning/audit.
    // ⚠ parsed and suggestions must serialise as JSON objects ({}) not arrays ([]).
    //   They are initialised as stdClass above precisely to guarantee this when empty.
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
        'field_confidences' => $fieldConfidences
            ? (object)array_map(static fn($v) => (int)round($v * 100), $fieldConfidences)
            : new stdClass(),
        'gst_validation'    => $gstValidation    ?? null,
        'duplicate_image' => $duplicateImage,
    ], JSON_INVALID_UTF8_SUBSTITUTE);

} catch (Throwable $e) {
    // ── Outer catch ───────────────────────────────────────────────────────────
    // Catches any unhandled exception from the entire pipeline above.
    // Returns 500 with the error message so iOS DevErrorBus can surface it.
    // ⚠ In production, $e->getMessage() may leak internal paths or query fragments.
    //   If the error message is sensitive, replace with a generic string here and
    //   use error_log() to capture the real message server-side.
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Receipt upload failed: ' . $e->getMessage()]);
}
