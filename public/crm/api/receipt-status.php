<?php
/**
 * API: Receipt OCR Status (Async Polling)
 *
 * Returns the current OCR processing status for a media asset.
 * Used by the expenses page to poll after upload for async OCR results.
 *
 * GET /crm/api/receipt-status.php?media_id=<id>
 *
 * Returns JSON:
 * {
 *   success: bool,
 *   media_id: int,
 *   ocr_status: 'pending' | 'processing' | 'ready' | 'failed',
 * }
 *
 * Current implementation: OCR is synchronous in receipt-intake.php so this
 * endpoint always returns 'ready'. It exists as a forward-compatibility shim
 * for when OCR is moved to an async background queue. The client-side polling
 * loop in expenses_appstack.php only activates when receipt-intake returns
 * ocr_status='processing'; synchronous responses skip polling entirely.
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

    $mediaId = (int)($_GET['media_id'] ?? 0);
    if (!$mediaId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'media_id required']);
        exit;
    }

    $db = getDB();

    // Check the media asset status column
    $stmt = $db->prepare("SELECT id, status FROM media_assets WHERE id = ? LIMIT 1");
    $stmt->execute([$mediaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Media not found']);
        exit;
    }

    // Map media status to OCR status
    // 'processing' means background OCR is still running (future async mode).
    // All other statuses mean ready (current synchronous mode).
    $mediaStatus = $row['status'] ?? 'ready';
    $ocrStatus = ($mediaStatus === 'processing') ? 'processing' : 'ready';

    echo json_encode([
        'success'    => true,
        'media_id'   => (int)$row['id'],
        'ocr_status' => $ocrStatus,
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
