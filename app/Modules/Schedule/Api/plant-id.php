<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/plant-id.php — "What is this plant?" from the iPhone app (JWT).
 * POST /api/schedule/plant-id   JSON body:
 *   {mode: 'identify', visit_id, media_ids: [whole, closer, close-up], lat?, lng?, accuracy?, heading?}
 *        → PlantIdService on the visit's property; answers with the record, the property's beds and the care
 *   {mode: 'confirm', id, scientific_name?, common_name?, measurement_id (0 = no bed)}
 *        → the crew member who identified it (within a day), or an admin / manager / staff
 * The photos are uploaded first through job-photo (photo_type 'other') — the same visit access rule.
 */

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

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    require_once APP_ROOT . '/Core/config.php';
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Modules/Plants/Services/PlantIdService.php';
    require_once APP_ROOT . '/Modules/Plants/Services/PlantCareService.php';

    $jwtUser = requireJwt();
    $userId = (int)$jwtUser['id'];
    $isAdmin = in_array($jwtUser['role'] ?? 'crew', ['admin', 'manager', 'staff'], true);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'POST required']);
        exit;
    }
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $db = getDB();
    $svc = new PlantIdService($db);
    if (!$svc->ready()) { echo json_encode(['ok' => false, 'message' => 'Plant ID is not set up yet.']); exit; }
    $care = new PlantCareService($db);
    $careOf = function (?array $rec) use ($care): ?array {
        if (!$rec || empty($rec['scientific_name']) || !$care->ready()) return null;
        $c = $care->forPlant((string)$rec['scientific_name']);
        if (!$c || $c['status'] !== 'approved') return null;   // only approved care goes to the field
        return ['name' => $c['common_name'], 'general' => $c['match'] === 'genus',
                'tasks' => array_map(fn($t) => ['task' => $t['task'], 'when' => PlantCareService::window((int)$t['from'], (int)$t['to']), 'how' => $t['how']], $c['card']['tasks'] ?? []),
                'toxic_pets' => !empty($c['card']['toxic_pets']), 'toxic_note' => (string)($c['card']['toxic_note'] ?? ''),
                'weed' => !empty($c['card']['weed']) || ($c['plant_group'] ?? '') === 'weed', 'invasive' => !empty($c['card']['invasive_bc']),
                'invasive_note' => (string)($c['card']['invasive_note'] ?? ''), 'safety' => (string)($c['card']['safety'] ?? ''),
                'disposal' => (string)($c['card']['disposal'] ?? '')];
    };
    $slim = fn(?array $r) => $r === null ? null : [
        'id' => (int)$r['id'], 'status' => $r['status'], 'scientific_name' => $r['scientific_name'], 'common_name' => $r['common_name'],
        'score' => $r['score'] !== null ? (float)$r['score'] : null, 'matches' => $r['matches'],
        'bed' => $r['bed'], 'measurement_id' => $r['measurement_id'] !== null ? (int)$r['measurement_id'] : null,
    ];

    switch ((string)($in['mode'] ?? '')) {
        case 'identify': {
            $visitId = (int)($in['visit_id'] ?? 0);
            $vs = $db->prepare('SELECT v.id, v.stop_id, v.assigned_crew_id, v.plan_id, jp.property_id FROM job_visits v JOIN job_plans jp ON jp.id = v.plan_id WHERE v.id = ?');
            $vs->execute([$visitId]);
            $visit = $vs->fetch(PDO::FETCH_ASSOC);
            if (!$visit) { http_response_code(404); echo json_encode(['ok' => false, 'message' => 'Visit not found']); exit; }
            require_once APP_ROOT . '/Modules/Jobs/Services/VisitWorkService.php';
            $stopCrew = (new VisitWorkService($db))->stopCrewIds(isset($visit['stop_id']) ? (int)$visit['stop_id'] : null);
            if (!VisitWorkService::canAccess($visit, $userId, $isAdmin, $stopCrew)) {
                http_response_code(403); echo json_encode(['ok' => false, 'message' => 'Not authorised for this visit']); exit;
            }
            @set_time_limit(90);
            $res = $svc->identify((int)$visit['property_id'], (array)($in['media_ids'] ?? []),
                ['lat' => $in['lat'] ?? null, 'lng' => $in['lng'] ?? null, 'accuracy' => $in['accuracy'] ?? null, 'heading' => $in['heading'] ?? null], $userId);
            echo json_encode(['ok' => (bool)$res['ok'], 'retry' => !empty($res['retry']), 'repeat' => !empty($res['repeat']),
                              'message' => $res['message'] ?? '', 'record' => $slim($res['record'] ?? null),
                              'beds' => array_map(fn($b) => ['id' => $b['id'], 'name' => $b['name']], $svc->beds((int)$visit['property_id'])),
                              'care' => $careOf($res['record'] ?? null)]);
            break;
        }
        case 'confirm': {
            $rec = $svc->one((int)($in['id'] ?? 0));
            $own = $rec && (int)$rec['created_by'] === $userId && strtotime((string)$rec['created_at']) >= time() - 86400;
            if (!$rec || (!$own && !$isAdmin)) { http_response_code(403); echo json_encode(['ok' => false, 'message' => 'Not yours to confirm']); exit; }
            $res = $svc->confirm((int)$rec['id'], $in, $userId);
            echo json_encode(['ok' => (bool)$res['ok'], 'message' => $res['message'] ?? '', 'record' => $slim($res['record'] ?? null), 'care' => $careOf($res['record'] ?? null)]);
            break;
        }
        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('Mobile plant-id: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Plant ID is unavailable right now']);
}
