<?php
/**
 * Photo Refile — photos whose own GPS says they were taken at another property.
 *
 * GET  ?mode=list[&days=N]                 → { groups: [...], recent_moves: [...] }
 * POST JSON { action: move,    from_visit, to_visit, media_ids[], csrf_token }
 *           { action: dismiss, media_ids[], csrf_token }
 *           { action: undo,    log_id, csrf_token }
 * Thin controller — the rules live in PhotoRefileService. Flag only: nothing moves until the office approves a group.
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
    require_once APP_ROOT . '/Modules/Photos/Services/PhotoRefileService.php';

    requireLogin();
    requirePermission('jobs.edit');
    $user = getCurrentUser();
    $db   = getDB();
    $svc  = new PhotoRefileService($db);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        try {
            $days = max(0, min(365, (int)($_GET['days'] ?? 60)));
            echo json_encode(['groups' => $svc->findGroups($days), 'recent_moves' => $svc->recentMoves()]);
        } catch (PDOException $e) {
            error_log('photo-refile.php list: ' . $e->getMessage());
            if (stripos($e->getMessage(), 'photo_refile_log') === false) {
                throw $e;
            }
            echo json_encode(['groups' => [], 'recent_moves' => [], 'needs_migration' => true]);
        }
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    if (empty($input['csrf_token']) || !verifyCSRFToken((string)$input['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid request']);
        exit;
    }
    $ids = is_array($input['media_ids'] ?? null) ? array_slice($input['media_ids'], 0, 200) : [];

    switch ($input['action'] ?? '') {
        case 'move':
            $r = $svc->move((int)($input['from_visit'] ?? 0), (int)($input['to_visit'] ?? 0), $ids, (int)$user['id']);
            echo json_encode(['success' => true] + $r);
            break;
        case 'dismiss':
            echo json_encode(['success' => true, 'dismissed' => $svc->dismiss($ids, (int)$user['id'])]);
            break;
        case 'undo':
            $ok = $svc->undo((int)($input['log_id'] ?? 0), (int)$user['id']);
            echo json_encode(['success' => $ok]);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    error_log('photo-refile.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not complete that']);
}
