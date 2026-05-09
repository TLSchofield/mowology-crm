<?php
/**
 * API: Receipt OCR Status (legacy media-keyed polling).
 *
 * GET /crm/api/receipt-status.php?media_id=<id>
 *
 * Returns:
 *   {
 *     success: bool,
 *     media_id: int,
 *     ocr_status: 'pending' | 'processing' | 'ready' | 'failed',
 *     // when ocr_status = 'ready', the parsed payload is included so the
 *     // existing pollOcrStatus() in expenses_appstack.php can rebuild
 *     // the review modal without a second round trip:
 *     ocr_text?: string, parsed?: {...}, suggestions?: {...},
 *     field_confidences?: {...}, gst_validation?: {...},
 *     job_suggestions?: [...], ocr_source?: string
 *   }
 *
 * Resolution order:
 *   1. Look up the most recent expense_ocr_jobs row for this media_id.
 *      Map status: pending|processing → 'processing', complete → 'ready',
 *      failed → 'failed'.
 *   2. Fall back to media_assets.status — preserves behaviour for receipts
 *      that pre-date the async queue.
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

    requireLogin();
    requirePermission('expenses.view');
    if (function_exists('session_write_close') && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $mediaId = (int)($_GET['media_id'] ?? 0);
    if (!$mediaId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'media_id required']);
        exit;
    }

    $db = getDB();

    // ── Async queue first ──
    try {
        $jobStmt = $db->prepare("
            SELECT id, status, parsed_json, parsed_raw_text, error
            FROM expense_ocr_jobs
            WHERE media_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $jobStmt->execute([$mediaId]);
        $job = $jobStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Table may not exist yet pre-migration — fall through to legacy lookup.
        $job = null;
    }

    if ($job) {
        $jobStatus = (string)$job['status'];
        $resp = [
            'success'    => true,
            'media_id'   => $mediaId,
            'ocr_status' => $jobStatus === 'complete' ? 'ready'
                          : ($jobStatus === 'failed' ? 'failed' : 'processing'),
        ];
        if ($jobStatus === 'complete' && !empty($job['parsed_json'])) {
            $decoded = json_decode((string)$job['parsed_json'], true);
            if (is_array($decoded)) {
                $resp['ocr_text']          = $decoded['ocr_text']          ?? ($job['parsed_raw_text'] ?? '');
                $resp['ocr_available']     = $decoded['ocr_available']     ?? false;
                $resp['ocr_source']        = $decoded['ocr_source']        ?? 'none';
                $resp['parsed']            = $decoded['parsed']            ?? new stdClass();
                $resp['suggestions']       = $decoded['suggestions']       ?? new stdClass();
                $resp['field_confidences'] = $decoded['field_confidences'] ?? new stdClass();
                $resp['gst_validation']    = $decoded['gst_validation']    ?? null;
                $resp['job_suggestions']   = $decoded['job_suggestions']   ?? [];
            }
        } elseif ($jobStatus === 'failed') {
            $resp['error'] = $job['error'] ?? 'OCR processing failed';
        }
        echo json_encode($resp);
        exit;
    }

    // ── Legacy fallback: media_assets.status ──
    $stmt = $db->prepare("SELECT id, status FROM media_assets WHERE id = ? LIMIT 1");
    $stmt->execute([$mediaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Media not found']);
        exit;
    }

    $mediaStatus = $row['status'] ?? 'ready';
    $ocrStatus   = ($mediaStatus === 'processing') ? 'processing' : 'ready';

    echo json_encode([
        'success'    => true,
        'media_id'   => (int)$row['id'],
        'ocr_status' => $ocrStatus,
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
