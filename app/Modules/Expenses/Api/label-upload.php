<?php
/**
 * iOS: photo of a product bag or machine nameplate — JWT-authenticated.
 *
 * POST /api/expenses/label-upload  multipart/form-data:
 *   label_photo  image (required) · lat, lng (optional)
 * Auth: Authorization: Bearer <jwt> (no CSRF).
 *
 * Same storage + OCR as a receipt (LabelCaptureService) but it never creates an expense:
 * the label becomes a proposal on Penny's (product) or Otto's (machine) card on the web.
 * Returns {success, capture_id, kind: product|machine|null, title, message, existing}.
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
    require_once APP_ROOT . '/Modules/Products/Services/LabelCaptureService.php';

    $jwtUser = requireJwt();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }
    $lat = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float)$_POST['lat'] : null;
    $lng = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float)$_POST['lng'] : null;
    $r = (new LabelCaptureService(getDB()))->capture((int)$jwtUser['id'], $_FILES['label_photo'] ?? [], $lat, $lng);
    if (!empty($r['http_code'])) {
        http_response_code((int)$r['http_code']);
        echo json_encode(['success' => false, 'error' => $r['error'] ?? 'Upload failed']);
        exit;
    }
    echo json_encode([
        'success'    => true,
        'capture_id' => $r['capture_id'] ?? null,
        'kind'       => $r['kind'] ?? null,
        'title'      => $r['title'] ?? null,
        'message'    => $r['message'] ?? 'Saved.',
        'existing'   => (bool)($r['existing'] ?? false),
    ]);
} catch (Throwable $e) {
    error_log('label-upload: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Label upload failed: ' . $e->getMessage()]);
}
