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
    require_once APP_ROOT . '/Modules/Expenses/Services/ReceiptIntakeService.php';

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }

    $lat   = isset($_POST['lat'])    && $_POST['lat']    !== '' ? (float)$_POST['lat']    : null;
    $lng   = isset($_POST['lng'])    && $_POST['lng']    !== '' ? (float)$_POST['lng']    : null;
    $jobId = isset($_POST['job_id']) && $_POST['job_id'] !== '' ? (int)$_POST['job_id']   : null;

    $intakeService = new ReceiptIntakeService(getDB());
    $result = $intakeService->intake($userId, $_FILES['receipt_photo'] ?? [], $lat, $lng, $jobId);

    if (!empty($result['http_code']) && $result['http_code'] !== 200) {
        http_response_code($result['http_code']);
        echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Upload failed']);
        exit;
    }

    // iOS's Decodable models expect an object ({}) for parsed/suggestions when OCR
    // didn't run, not an empty JSON array ([]) — cast empty arrays to stdClass to
    // preserve receipt-upload.php's historical response shape for the iOS client.
    $parsedOut = $result['parsed'];
    $suggestionsOut = $result['suggestions'];
    if (is_array($parsedOut) && empty($parsedOut)) $parsedOut = new stdClass();
    if (is_array($suggestionsOut) && empty($suggestionsOut)) $suggestionsOut = new stdClass();

    echo json_encode([
        'success'         => true,
        'media_id'        => $result['media_id'],
        'file_path'       => $result['file_path'],
        'ocr_text'        => $result['ocr_text'],
        'ocr_available'   => $result['ocr_available'],
        'ocr_source'      => $result['ocr_source'],
        'parsed'          => $parsedOut,
        'suggestions'     => $suggestionsOut,
        'job_suggestions' => $result['job_suggestions'],
        'duplicate_image' => $result['duplicate_image'],
        // Same trust signals the web review card gets — GST-math check + per-field
        // Vision confidences — so the iOS form can flag a bad total the same way.
        'gst_validation'  => $result['gst_validation'] ?? null,
        'field_confidences' => (is_array($result['field_confidences'] ?? null) && !empty($result['field_confidences']))
            ? $result['field_confidences'] : new stdClass(),
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Receipt upload failed: ' . $e->getMessage()]);
}
