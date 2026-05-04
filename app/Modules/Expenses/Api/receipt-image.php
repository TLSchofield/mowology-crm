<?php
/**
 * API: Serve Receipt Image (JWT-authenticated)
 *
 * GET /api/expenses/receipt-image?id=<media_id>
 * Authorization: Bearer <jwt>
 *
 * Streams the receipt image file after validating the bearer token.
 * Bypasses the Deny-from-all .htaccess on /uploads/receipts/ so the
 * iOS app can display receipt thumbnails without cookie-based auth.
 *
 * Returns the raw image bytes with the correct Content-Type header.
 * On error: JSON response with success=false and an error message.
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

try {
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';

    // --- Auth ---
    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'GET required']);
        exit;
    }

    // --- Validate media ID ---
    $mediaId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($mediaId <= 0) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Missing or invalid media ID']);
        exit;
    }

    // --- Look up the asset ---
    $db   = getDB();
    $stmt = $db->prepare('
        SELECT ma.file_path, ma.mime_type, ma.uploaded_by
        FROM   media_assets ma
        WHERE  ma.id = ?
        LIMIT  1
    ');
    $stmt->execute([$mediaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Receipt not found']);
        exit;
    }

    // --- Ownership check: owner or admin/manager may view ---
    $isAdmin = in_array($jwtUser['role'] ?? '', ['admin', 'manager'], true);
    if (!$isAdmin && (int)$row['uploaded_by'] !== $userId) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }

    // --- Build + validate absolute path ---
    $absolutePath = PUBLIC_ROOT . $row['file_path'];

    $allowedPrefixes = [
        realpath(PUBLIC_ROOT . '/uploads/receipts'),
        realpath(PUBLIC_ROOT . '/_media'),
    ];

    $realPath = realpath($absolutePath);
    $allowed  = false;
    if ($realPath) {
        foreach ($allowedPrefixes as $prefix) {
            if ($prefix && strpos($realPath, $prefix) === 0) {
                $allowed = true;
                break;
            }
        }
    }

    if (!$allowed || !$realPath || !is_file($realPath)) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'File not found on disk']);
        exit;
    }

    // --- Stream the image ---
    $mimeType = $row['mime_type'] ?: 'image/jpeg';
    $fileSize = filesize($realPath);

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');

    readfile($realPath);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Server error']);
    exit;
}
