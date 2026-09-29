<?php
/**
 * Visit Photo History API (session) — the Android / web schedule card.
 *
 * GET ?visit_id=42 → { success, history:[ { visit_id, date, service, status, photos:[…] } ] }
 *
 * Photos from earlier visits at the same property, newest visit first. Same
 * VisitPhotoService as the iOS endpoint (/api/schedule/visit-photos?mode=history).
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
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Modules/Jobs/Services/VisitPhotoService.php';

    requireLogin();
    requirePermission('schedule.view');
    session_write_close(); // read-only

    $visitId = isset($_GET['visit_id']) ? (int)$_GET['visit_id'] : 0;
    if ($visitId < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'visit_id is required']);
        exit;
    }

    echo json_encode([
        'success'  => true,
        'visit_id' => $visitId,
        'history'  => (new VisitPhotoService(getDB()))->historyForVisit($visitId),
    ]);
} catch (Throwable $e) {
    error_log('visit-photo-history.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
