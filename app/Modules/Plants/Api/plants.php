<?php
/**
 * Plants — "What is this?" (PlantIdService, migration 1367). Session (CRM) endpoint.
 *
 * GET  ?mode=list&property_id=N           the property's plants + its drawn beds + attribution
 * POST {mode: 'identify', property_id, media_ids: [whole, closer, close-up], lat?, lng?, accuracy?, heading?, csrf_token}
 * POST {mode: 'confirm', id, scientific_name?, common_name?, measurement_id (0 = no bed), notes?, csrf_token}
 * POST {mode: 'remove', id, csrf_token}
 * The care library (PlantCareService, migration 1368):
 * GET  ?mode=library                      every card by group (finished batches are collected first)
 * POST {mode: 'care_now', scientific_name, common_name}    a plant not in the library: draft its card now
 * POST {mode: 'card_decide', id, what: approve|reject|redo, common_name?, card?}
 * POST {mode: 'group_approve', group}     approve every draft in a group as it stands
 * POST {mode: 'library_build'}            queue the starter list (first time) + failed ones, send one batch
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
    // The care library is shared knowledge and spends money on lookups: catalogue editors only (admins, managers).
    $canLibrary = userHasPermission('products.edit');

    $db = getDB();
    session_write_close();
    require_once APP_ROOT . '/Modules/Plants/Services/PlantIdService.php';
    require_once APP_ROOT . '/Modules/Plants/Services/PlantCareService.php';
    $svc = new PlantIdService($db);
    $care = new PlantCareService($db);
    // A plant's care as the Plants card shows it: approved cards in full, a draft only as "waiting".
    $careOf = function (array $p) use ($care): ?array {
        $sci = (string)($p['scientific_name'] ?? '');
        if ($sci === '') return null;
        $c = $care->forPlant($sci);
        if (!$c) return ['status' => 'none'];
        $tasks = array_map(fn($t) => ['task' => $t['task'], 'when' => PlantCareService::window((int)$t['from'], (int)$t['to']), 'how' => $t['how']], $c['card']['tasks'] ?? []);
        return ['id' => (int)$c['id'], 'status' => $c['status'], 'match' => $c['match'], 'name' => $c['common_name'], 'level' => $c['level'],
                'tasks' => $c['status'] === 'approved' ? $tasks : [], 'toxic_pets' => $c['status'] === 'approved' && !empty($c['card']['toxic_pets']),
                'toxic_note' => $c['status'] === 'approved' ? ($c['card']['toxic_note'] ?? '') : '', 'sun' => $c['status'] === 'approved' ? ($c['card']['sun'] ?? '') : ''];
    };
    if (!$svc->ready()) {
        echo json_encode(['ok' => false, 'ready' => false, 'error' => 'Plant ID needs migration 1367.']);
        exit;
    }

    switch ($mode) {
        case 'list': {
            $pid = (int)($_GET['property_id'] ?? 0);
            $beds = array_map(fn($b) => ['id' => $b['id'], 'name' => $b['name'], 'type' => $b['type']], $svc->beds($pid));
            $plants = $svc->forProperty($pid);
            foreach ($plants as &$p) $p['care'] = $care->ready() ? $careOf($p) : null;
            unset($p);
            echo json_encode(['ok' => true, 'plants' => $plants, 'beds' => $beds, 'can_edit' => $canEdit, 'can_library' => $canLibrary,
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
        case 'library': {
            if (!$care->ready()) { echo json_encode(['ok' => false, 'error' => 'The plant library needs migration 1368.']); break; }
            $got = $care->collect();
            echo json_encode(['ok' => true, 'can_edit' => $canLibrary, 'collected' => $got, 'groups_named' => PlantCareService::GROUPS, 'months' => PlantCareService::MONTHS] + $care->library());
            break;
        }
        case 'care_now':
            if (!$canLibrary) { http_response_code(403); echo json_encode(['ok' => false, 'message' => 'Ask an admin or manager (product catalogue permission).']); break; }
            @set_time_limit(200);
            echo json_encode($care->draftNow((string)($input['scientific_name'] ?? ''), (string)($input['common_name'] ?? ''), $uid));
            break;
        case 'card_decide':
            if (!$canLibrary) { http_response_code(403); echo json_encode(['ok' => false, 'message' => 'Ask an admin or manager (product catalogue permission).']); break; }
            echo json_encode($care->decide((int)($input['id'] ?? 0), $input, $uid));
            break;
        case 'group_approve':
            if (!$canLibrary) { http_response_code(403); echo json_encode(['ok' => false, 'message' => 'Ask an admin or manager (product catalogue permission).']); break; }
            $n = $care->approveGroup((string)($input['group'] ?? ''), $uid);
            echo json_encode(['ok' => true, 'message' => $n . ' card' . ($n === 1 ? '' : 's') . ' approved.']);
            break;
        case 'library_build': {
            if (!$canLibrary) { http_response_code(403); echo json_encode(['ok' => false, 'message' => 'Ask an admin or manager (product catalogue permission).']); break; }
            @set_time_limit(200);
            $seeded = $care->seed();          // idempotent: plants already in the library are skipped
            $care->retryFailed();
            $sent = $care->submitBatch();
            echo json_encode($sent + ['seeded' => $seeded]);
            break;
        }
        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('Plants API: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Plant ID is unavailable right now']);
}
