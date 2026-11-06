<?php
/**
 * Photo Position — save corrected map pins for a visit's photos.
 *
 * POST JSON: { visit_id: int, positions: [{ media_id, lat, lng }, ...], csrf_token }
 * Returns   : { success: true, saved: n, rejected: [media ids] }
 *
 * Called from the portal visit report in admin preview mode after a manager
 * drags a photo pin to the right spot (or places an unlocated photo).
 * Thin controller — the rules live in PhotoPositionService.
 */
declare(strict_types=1);
header('Content-Type: application/json');

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
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once APP_ROOT . '/Modules/Photos/Services/PhotoPositionService.php';

    requireLogin();
    requirePermission('jobs.edit');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST only']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    if (empty($input['csrf_token']) || !verifyCSRFToken((string)$input['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid request']);
        exit;
    }

    $visitId   = (int)($input['visit_id'] ?? 0);
    $positions = is_array($input['positions'] ?? null) ? $input['positions'] : [];

    if ($visitId < 1 || !$positions) {
        http_response_code(400);
        echo json_encode(['error' => 'visit_id and positions are required']);
        exit;
    }
    if (count($positions) > 200) {
        http_response_code(400);
        echo json_encode(['error' => 'Too many positions']);
        exit;
    }

    $svc    = new PhotoPositionService(getDB());
    $result = $svc->setPositions($visitId, $positions);

    echo json_encode([
        'success'  => true,
        'saved'    => $result['saved'],
        'rejected' => $result['rejected'],
    ]);
} catch (Throwable $e) {
    error_log('photo-position.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not save pin positions']);
}
