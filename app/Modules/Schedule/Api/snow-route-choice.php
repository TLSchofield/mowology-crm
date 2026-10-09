<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/snow-route-choice.php
 *
 * Mobile Schedule API — what was done at a snow & salt route stop.
 *
 * GET  /api/schedule/snow-route-choice?visit_id=42
 *   → { success, is_route, choice, label, choices: [{value, label}] }
 * POST /api/schedule/snow-route-choice   { "visit_id": 42, "choice": "salt" }
 *   choice: salt | arctic | snow | none
 *
 * The run is invoiced at the recorded rate only (SnowContractService). Same rules
 * as the web endpoint /crm/api/snow-route-choice.php; JWT here.
 * Crew may record for visits assigned to them; admin/manager and the truck login for any.
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

require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
require_once APP_ROOT . '/Modules/Contracts/Services/SnowContractService.php';

$jwtUser = requireJwt();
$db      = getDB();
$svc     = new SnowContractService($db);

$isPost  = $_SERVER['REQUEST_METHOD'] === 'POST';
$body    = $isPost ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
$visitId = (int)($isPost ? ($body['visit_id'] ?? 0) : ($_GET['visit_id'] ?? 0));

if ($visitId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'visit_id is required']);
    exit;
}

try {
    $stmt = $db->prepare("SELECT plan_id, assigned_crew_id FROM job_visits WHERE id = ?");
    $stmt->execute([$visitId]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$visit) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Visit not found']);
        exit;
    }

    if ($isPost) {
        $isAdmin = jwtIsAdmin($jwtUser['role']);
        // Managers/admins and the truck login (device_type 'truck') may record any stop.
        $dt = $db->prepare("SELECT device_type FROM users WHERE id = ?");
        $dt->execute([(int)$jwtUser['id']]);
        $isTruck = (string)$dt->fetchColumn() === 'truck';
        if (!$isAdmin && !$isTruck && (int)($visit['assigned_crew_id'] ?? 0) !== (int)$jwtUser['id']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You are not assigned to this visit']);
            exit;
        }
        echo json_encode($svc->recordChoice($visitId, (string)($body['choice'] ?? ''), (int)$jwtUser['id'], $isAdmin ? 'office' : 'crew'));
        exit;
    }

    $rates  = $svc->ratesForPlan((int)$visit['plan_id']);
    $choice = $rates ? $svc->choiceForVisit($visitId) : null;
    $choices = [];
    if ($rates) {
        foreach (SnowContractService::CHOICES as $c) {
            $choices[] = ['value' => $c, 'label' => SnowContractService::choiceLabel($c)];
        }
    }
    echo json_encode([
        'success'  => true,
        'is_route' => (bool)$rates,
        'choice'   => $choice,
        'label'    => $choice ? SnowContractService::choiceLabel($choice) : null,
        'choices'  => $choices,
    ]);
} catch (Throwable $e) {
    error_log('api/schedule/snow-route-choice: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not load this stop.']);
}
