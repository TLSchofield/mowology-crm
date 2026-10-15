<?php
/**
 * Plants — "What is this?" (PlantIdService, migration 1367). Session (CRM) endpoint.
 *
 * GET  ?mode=list&property_id=N           the property's plants + its drawn beds + attribution
 * POST {mode: 'identify', property_id, media_ids: [whole, closer, close-up], lat?, lng?, accuracy?, heading?, csrf_token}
 * POST {mode: 'confirm', id, scientific_name?, common_name?, measurement_id (0 = no bed), notes?, csrf_token}
 * POST {mode: 'remove', id, csrf_token}
 *
 * Viewing and identifying: clients.view. Confirming and removing: clients.edit (or jobs.edit).
 * ?mode=, not ?action= (the /api/ router appends its own action).
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
    $user = getCurrentUser() ?? [];
    $uid = (int)($user['id'] ?? 0);

    $method = $_SERVER['REQUEST_METHOD'];
    $input  = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
    $mode   = $method === 'POST' ? (string)($input['mode'] ?? '') : (string)($_GET['mode'] ?? 'list');

    if ($method === 'POST' && !verifyCSRFToken((string)($input['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
    if (!userHasPermission('clients.view')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Access denied']);
        exit;
    }
    $canEdit = userHasPermission('clients.edit') || userHasPermission('jobs.edit');

    $db = getDB();
    session_write_close();
    require_once APP_ROOT . '/Modules/Plants/Services/PlantIdService.php';
    $svc = new PlantIdService($db);
    if (!$svc->ready()) {
        echo json_encode(['ok' => false, 'ready' => false, 'error' => 'Plant ID needs migration 1367.']);
        exit;
    }

    switch ($mode) {
        case 'list': {
            $pid = (int)($_GET['property_id'] ?? 0);
            $beds = array_map(fn($b) => ['id' => $b['id'], 'name' => $b['name'], 'type' => $b['type']], $svc->beds($pid));
            echo json_encode(['ok' => true, 'plants' => $svc->forProperty($pid), 'beds' => $beds, 'can_edit' => $canEdit,
                              'attribution' => PlantIdService::ATTRIBUTION, 'left_today' => max(0, PlantIdService::DAILY_CAP - $svc->usedToday())]);
            break;
        }
        case 'identify':
            @set_time_limit(90);
            echo json_encode($svc->identify((int)($input['property_id'] ?? 0), (array)($input['media_ids'] ?? []),
                ['lat' => $input['lat'] ?? null, 'lng' => $input['lng'] ?? null, 'accuracy' => $input['accuracy'] ?? null, 'heading' => $input['heading'] ?? null], $uid));
            break;
        case 'confirm':
            if (!$canEdit) { http_response_code(403); echo json_encode(['ok' => false, 'message' => 'You can look but not change plants.']); break; }
            echo json_encode($svc->confirm((int)($input['id'] ?? 0), $input, $uid));
            break;
        case 'remove':
            if (!$canEdit) { http_response_code(403); echo json_encode(['ok' => false, 'message' => 'You can look but not change plants.']); break; }
            echo json_encode($svc->remove((int)($input['id'] ?? 0), $uid));
            break;
        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('Plants API: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Plant ID is unavailable right now']);
}
