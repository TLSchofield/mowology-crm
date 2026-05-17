<?php
/**
 * Receipt OCR Queue Worker (Phase 3 High).
 *
 * Drains expense_ocr_jobs.status='pending' rows. For each job:
 *   1. Strip EXIF + convert HEIC→JPEG (formerly inline in receipt-upload.php)
 *   2. Run Tesseract pre-screen
 *   3. Run Google Vision OCR if Tesseract score is too low
 *   4. Parse + suggest vendor / category / GPS-matched job
 *   5. Persist parsed payload back to the job row
 *
 * Cron: every minute
 *   * * * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Expenses/Cron/process_ocr_queue.php
 *
 * Concurrency safety:
 *   - flock-based mutex (TODO: swap to App\Core\CronLock once that branch
 *     merges to main — claude/lucid-allen-28bfce, commit cd41afe)
 *   - atomic claim via UPDATE ... WHERE status='pending' + rowCount() check
 *   - stale 'processing' rows (started_at < NOW - 5 min) are recovered to
 *     'pending' on the next tick (handles process crashes between claim
 *     and write-back)
 *
 * Retry policy:
 *   attempts is incremented on each claim. On failure:
 *     - attempts < 3 → status reverts to 'pending', retried next tick
 *     - attempts ≥ 3 → status='failed' permanently, error column set
 *
 * Process up to 20 rows per tick to keep memory bounded and let other
 * crons make progress.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server') {
    // Web invocation requires admin auth (matches the schema_snapshot.php pattern).
    // Until a cron-runner UI exists for receipts, just refuse non-CLI calls.
    http_response_code(403);
    echo "Forbidden: CLI only.\n";
    exit;
}

// ── Bootstrap (resolve APP_ROOT via upward search) ────────────────────
$__dir = __DIR__;
for ($__i = 0; $__i < 6; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

if (!defined('APP_ROOT')) {
    fwrite(STDERR, "process_ocr_queue: failed to locate APP_ROOT\n");
    exit(1);
}

require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Services/Receipts/ReceiptOCR.php';
require_once APP_ROOT . '/Services/Receipts/ReceiptParser.php';
require_once APP_ROOT . '/Services/Receipts/ReceiptSmartMatch.php';
require_once APP_ROOT . '/Services/Receipts/ReceiptLearning.php';
require_once APP_ROOT . '/Services/Receipts/VendorProductMatch.php';
require_once APP_ROOT . '/Services/Receipts/TesseractPreScreen.php';

// ── Mutex: prevent overlapping runs ───────────────────────────────────
// TODO(cron-lock): replace with App\Core\CronLock::acquire('process_ocr_queue')
// once claude/lucid-allen-28bfce merges to main. The inline flock below
// is functionally equivalent (non-blocking exclusive lock, released by
// the kernel on process exit) so the swap will be a one-line change.
$lockPath   = sys_get_temp_dir() . '/mowology_cron_process_ocr_queue.lock';
$lockHandle = fopen($lockPath, 'c');
if ($lockHandle === false) {
    fwrite(STDERR, "process_ocr_queue: failed to open lockfile $lockPath\n");
    exit(1);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    // Another instance is running — exit cleanly.
    fclose($lockHandle);
    exit(0);
}
ftruncate($lockHandle, 0);
fwrite($lockHandle, (string)getmypid());
fflush($lockHandle);
register_shutdown_function(function () use ($lockHandle, $lockPath) {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
    @unlink($lockPath);
});

// ── Logger ────────────────────────────────────────────────────────────
function ocrLog(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if (PHP_SAPI === 'cli') echo $line . "\n";
    error_log('process_ocr_queue: ' . $msg);
}

$startMs = (int) round(microtime(true) * 1000);
$fromWeb = false; // CLI-only (web returns 403 above)
$cronStatus = 'success';
$cronError  = null;

ocrLog('=== OCR queue worker started ===');

$db = getDB();

// ── Recover stale 'processing' rows ───────────────────────────────────
// If a previous run crashed between claim and write-back, the row stays
// 'processing' forever. After 5 minutes, push it back to 'pending' so
// the next tick can retry (attempts is preserved, so terminal failure
// still kicks in after 3 tries).
try {
    $recover = $db->prepare("
        UPDATE expense_ocr_jobs
        SET status = 'pending'
        WHERE status = 'processing'
          AND started_at < (NOW() - INTERVAL 5 MINUTE)
    ");
    $recover->execute();
    if ($recover->rowCount() > 0) {
        ocrLog("Recovered {$recover->rowCount()} stale 'processing' row(s) → 'pending'");
    }
} catch (Throwable $e) {
    ocrLog("Stale-row recovery failed (non-fatal): " . $e->getMessage());
}

// ── Pull a batch of candidate IDs ─────────────────────────────────────
$batchSize = 20;
try {
    $list = $db->prepare("
        SELECT id
        FROM expense_ocr_jobs
        WHERE status = 'pending'
          AND attempts < 3
        ORDER BY created_at ASC
        LIMIT $batchSize
    ");
    $list->execute();
    $candidateIds = $list->fetchAll(PDO::FETCH_COLUMN, 0);
} catch (Throwable $e) {
    ocrLog("Candidate fetch failed: " . $e->getMessage());
    exit(1);
}

if (empty($candidateIds)) {
    ocrLog("No pending jobs.");
    exit(0);
}

ocrLog("Found " . count($candidateIds) . " candidate job(s)");

$processed = 0;
$succeeded = 0;
$failed    = 0;

foreach ($candidateIds as $jobId) {
    $jobId = (int)$jobId;

    // Atomic claim — only one cron instance can transition pending→processing.
    $claim = $db->prepare("
        UPDATE expense_ocr_jobs
        SET status = 'processing',
            started_at = NOW(),
            attempts = attempts + 1
        WHERE id = ? AND status = 'pending'
    ");
    $claim->execute([$jobId]);
    if ($claim->rowCount() === 0) {
        // Another cron instance grabbed it (race) or status changed.
        continue;
    }

    // Read the now-claimed row. attempts already reflects this run.
    $rowStmt = $db->prepare("
        SELECT j.id, j.media_id, j.user_id, j.attempts,
               m.file_path, m.mime_type, m.gps_lat, m.gps_lng
        FROM expense_ocr_jobs j
        LEFT JOIN media_assets m ON m.id = j.media_id
        WHERE j.id = ?
    ");
    $rowStmt->execute([$jobId]);
    $row = $rowStmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['file_path'])) {
        markFailed($db, $jobId, (int)($row['attempts'] ?? 1), 'media asset missing');
        $failed++;
        $processed++;
        continue;
    }

    $processed++;
    try {
        $result = processOcrJob(
            (int)$row['media_id'],
            (int)$row['user_id'],
            (string)$row['file_path'],
            (string)($row['mime_type'] ?? ''),
            isset($row['gps_lat']) ? (float)$row['gps_lat'] : null,
            isset($row['gps_lng']) ? (float)$row['gps_lng'] : null
        );

        $write = $db->prepare("
            UPDATE expense_ocr_jobs
            SET status = 'complete',
                completed_at = NOW(),
                parsed_vendor = ?,
                parsed_total = ?,
                parsed_date = ?,
                parsed_raw_text = ?,
                parsed_json = ?,
                error = NULL
            WHERE id = ?
        ");
        $write->execute([
            $result['vendor_name'] ?? null,
            $result['total']       ?? null,
            $result['date']        ?? null,
            $result['ocr_text']    ?? null,
            json_encode($result['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $jobId,
        ]);
        $succeeded++;
        ocrLog("job #$jobId media #{$row['media_id']} → complete");
    } catch (Throwable $e) {
        $attempts = (int)$row['attempts'];
        markFailed($db, $jobId, $attempts, $e->getMessage());
        $failed++;
        ocrLog("job #$jobId failed (attempt $attempts): " . $e->getMessage());
    }
}

ocrLog("Done. processed=$processed succeeded=$succeeded failed=$failed");

$durationMs = (int) round(microtime(true) * 1000) - $startMs;
recordCronRun(
    'process_ocr_queue',
    $cronStatus,
    "Processed: {$processed}, Succeeded: {$succeeded}, Failed: {$failed}",
    $durationMs,
    $cronError,
    $fromWeb
);
exit(0);

// ──────────────────────────────────────────────────────────────────────

/**
 * Mark a job as failed (or pending for retry, depending on attempts).
 */
function markFailed(PDO $db, int $jobId, int $attempts, string $errorMsg): void
{
    if ($attempts >= 3) {
        $stmt = $db->prepare("
            UPDATE expense_ocr_jobs
            SET status = 'failed', completed_at = NOW(), error = ?
            WHERE id = ?
        ");
    } else {
        // Push back to pending so next tick retries.
        $stmt = $db->prepare("
            UPDATE expense_ocr_jobs
            SET status = 'pending', error = ?
            WHERE id = ?
        ");
    }
    $stmt->execute([substr($errorMsg, 0, 8000), $jobId]);
}

/**
 * Run the full OCR pipeline on one media asset and return parsed fields.
 *
 * @throws RuntimeException on unrecoverable failure
 * @return array{
 *   vendor_name: ?string,
 *   total:       ?string,
 *   date:        ?string,
 *   ocr_text:    string,
 *   payload:     array{
 *     parsed: array, suggestions: array, ocr_text: string,
 *     ocr_available: bool, ocr_source: string,
 *     field_confidences: array, gst_validation: ?array,
 *     job_suggestions: array, file_path: string, mime_type: string
 *   }
 * }
 */
function processOcrJob(int $mediaId, int $userId, string $webPath, string $mimeType, ?float $lat, ?float $lng): array
{
    $db = getDB();

    // Resolve absolute path on disk.
    $filePath = PUBLIC_ROOT . '/' . ltrim($webPath, '/');
    if (!is_file($filePath)) {
        throw new RuntimeException("file not found on disk: $filePath");
    }

    // ── Step 1: HEIC→JPEG conversion (Imagick) ────────────────────────
    if (in_array(strtolower($mimeType), ['image/heic', 'image/heif'], true) && extension_loaded('imagick')) {
        try {
            $im = new Imagick($filePath);
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(90);
            $im->stripImage();
            $newName    = preg_replace('/\.(heic|heif)$/i', '.jpg', basename($filePath));
            $newPath    = dirname($filePath) . '/' . $newName;
            $newWebPath = '/uploads/receipts/' . $newName;
            $im->writeImage($newPath);
            $im->destroy();
            @unlink($filePath);
            $filePath = $newPath;
            $mimeType = 'image/jpeg';
            // Update media_assets to point at the JPEG.
            $db->prepare("UPDATE media_assets SET stored_filename = ?, file_path = ?, mime_type = 'image/jpeg' WHERE id = ?")
               ->execute([$newName, $newWebPath, $mediaId]);
        } catch (Throwable $heicEx) {
            error_log('process_ocr_queue HEIC→JPEG failed: ' . $heicEx->getMessage());
        }
    }

    // ── Step 2: EXIF strip via GD re-encode ───────────────────────────
    if (extension_loaded('gd')) {
        if (in_array($mimeType, ['image/jpeg', 'image/jpg'], true)) {
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

    // ── Step 3: Tesseract pre-screen + Vision fallback ────────────────
    $preScreen      = tesseractPreScreen($filePath);
    $earlyVendorId  = null;
    if (!empty($preScreen['text'])) {
        try {
            $earlyMatch = suggestReceiptMeta($preScreen['text'], null, null, null);
            $earlyVendorId = !empty($earlyMatch['vendor_id']) ? (int)$earlyMatch['vendor_id'] : null;
        } catch (Throwable $e) { /* non-fatal */ }
    }
    $tessThreshold  = getVendorTesseractThreshold($earlyVendorId);
    $preScreenScore = $preScreen['score'];
    if (empty($preScreen['error']) && $preScreenScore > 0) {
        $preScreenDecision = $preScreenScore >= $tessThreshold ? 'use_tesseract'
                           : ($preScreenScore >= 30 ? 'use_vision' : 'skip');
    } else {
        $preScreenDecision = $preScreen['decision'];
    }

    $ocrResult = ['success' => false, 'text' => '', 'raw_response' => null, 'error' => null];
    $ocrSource = 'none';
    if ($preScreenDecision === 'use_tesseract') {
        $ocrResult = ['success' => true, 'text' => $preScreen['text'], 'raw_response' => null, 'error' => null];
        $ocrSource = 'tesseract';
    } elseif ($preScreenDecision === 'use_vision') {
        $ocrResult = extractTextFromImage($filePath);
        $ocrSource = 'vision';
    }

    $ocrAvailable = (bool)$ocrResult['success'];
    $ocrText      = $ocrResult['text'] ?? '';
    $parsed       = [];
    $suggestions  = [];
    $fieldConfidences = [];
    $gstValidation    = null;

    if ($ocrAvailable && $ocrText !== '') {
        $parsed      = parseReceiptText($ocrText, $ocrResult['raw_response'] ?? null);
        $suggestions = suggestReceiptMeta($ocrText, $lat, $lng, null);

        if (!empty($suggestions['vendor_id'])) {
            $parsed = applyLearnedPatterns((int)$suggestions['vendor_id'], $parsed, $ocrText);
            $parsed = matchVendorProducts((int)$suggestions['vendor_id'], $ocrText, $parsed);
        }

        if (!empty($suggestions['vendor_gst_exempt'])) {
            $parsed['gst'] = '0.00';
            $parsed['gst_estimated'] = false;
            $parsed['gst_exempt']    = true;
            if (!empty($parsed['subtotal']) && empty($parsed['pst'])) {
                $parsed['total'] = $parsed['subtotal'];
            }
        }

        // Per-field confidences (Vision word-level), if available
        if (function_exists('extractWordConfidenceMap') && function_exists('calculateFieldConfidences') && !empty($ocrResult['raw_response'])) {
            $wordConf = extractWordConfidenceMap($ocrResult['raw_response']);
            if (!empty($wordConf)) {
                $fieldConfidences = calculateFieldConfidences($parsed, $wordConf);
            }
        }

        if (function_exists('validateGstMath')) {
            $gstValidation = validateGstMath($parsed);
        }

        // Auto-create vendor from OCR hint when no match
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
                    error_log('process_ocr_queue auto-vendor failed: ' . $e->getMessage());
                }
            }
        }
    }

    // GPS-matched job suggestions
    $jobSuggestions = [];
    try {
        $jobSuggestions = suggestJobFromSchedule($userId, $lat, $lng);
    } catch (Throwable $e) {
        error_log('process_ocr_queue job suggestion failed: ' . $e->getMessage());
    }

    $payload = [
        'media_id'          => $mediaId,
        'file_path'         => $webPath,
        'mime_type'         => $mimeType,
        'ocr_text'          => $ocrText,
        'ocr_available'     => $ocrAvailable,
        'ocr_source'        => $ocrSource,
        'pre_screen'        => [
            'score'    => $preScreen['score'] ?? 0,
            'decision' => $preScreenDecision,
            'low_quality' => $preScreen['low_quality'] ?? false,
        ],
        'parsed'            => $parsed,
        'suggestions'       => $suggestions,
        'job_suggestions'   => $jobSuggestions,
        'field_confidences' => $fieldConfidences
            ? (object)array_map(static fn($v) => (int)round($v * 100), $fieldConfidences)
            : new stdClass(),
        'gst_validation'    => $gstValidation,
    ];

    return [
        'vendor_name' => isset($suggestions['vendor_name']) ? (string)$suggestions['vendor_name'] : (isset($parsed['vendor_hint']) ? (string)$parsed['vendor_hint'] : null),
        'total'       => isset($parsed['total']) ? (string)$parsed['total'] : null,
        'date'        => isset($parsed['date']) ? formatParsedDate((string)$parsed['date']) : null,
        'ocr_text'    => $ocrText,
        'payload'     => $payload,
    ];
}

/**
 * Coerce a parsed date (YYYY-MM-DD or MM/DD/YYYY) to MySQL DATE format.
 * Returns null on invalid input rather than throwing — DATE column is nullable.
 */
function formatParsedDate(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') return null;
    $ts = strtotime($raw);
    if ($ts === false) return null;
    return date('Y-m-d', $ts);
}
