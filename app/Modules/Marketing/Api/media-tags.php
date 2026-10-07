<?php
/**
 * Mia's media library tags.
 *
 * GET  ?mode=library&service=&season=&stage=&hero=1   Tagged photos (marketing.view).
 * POST {mode: 'hero', id, on: bool, csrf_token}       Tim's star → use/hero (marketing.edit).
 * POST {mode: 'optout', contact_id, optout: bool, csrf_token}
 *      A client's photo opt-out → consent/no on every photo of their properties (clients.edit).
 *
 * ?mode=, not ?action= (the /api/ router rewrite appends its own `action`).
 */
declare(strict_types=1);
header('Content-Type: application/json');

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
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
    requireLogin();
    $method = $_SERVER['REQUEST_METHOD'];
    $input  = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
    $mode   = $method === 'POST' ? (string)($input['mode'] ?? '') : (string)($_GET['mode'] ?? 'library');
    if ($method === 'POST' && !verifyCSRFToken($input['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
    $db = getDB();
    session_write_close();
    require_once APP_ROOT . '/Modules/Marketing/Services/MediaTagService.php';
    $svc = new MediaTagService($db);
    if (!$svc->ready()) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Migration 1202 has not been run yet.']);
        exit;
    }

    switch ($mode) {
        case 'library':
            requirePermission('marketing.view');
            echo json_encode(['ok' => true, 'items' => $svc->library($_GET), 'facets' => $svc->facets()]);
            break;
        case 'hero':
            requirePermission('marketing.edit');
            echo json_encode($svc->setHero((int)($input['id'] ?? 0), !empty($input['on'])));
            break;
        case 'optout':
            requirePermission('clients.edit');
            echo json_encode($svc->setOptout((int)($input['contact_id'] ?? 0), !empty($input['optout'])));
            break;
        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('Media tags API: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Something went wrong — see the error log.']);
}
