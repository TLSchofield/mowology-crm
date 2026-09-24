<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/assign-crew.php
 *
 * Mobile Schedule API — Assign/Unassign Crew on a Calendar Stop
 *
 * POST /api/schedule/assign-crew
 * Authorization: Bearer <jwt>
 * Content-Type: application/json
 *
 * Body: { "stop_id": 100, "crew_ids": [11, 10] }
 * crew_ids may be an empty array to unassign the stop.
 *
 * Response 200: { "success": true, "stop_id": ..., "crew_ids": [...], "crew_names": [...], ... }
 *
 * Admin/manager only — same restriction as the CRM web schedule page.
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
require_once APP_ROOT . '/Modules/Jobs/Services/CrewAssignmentService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$jwtUser = requireJwt();

if (!jwtIsAdmin($jwtUser['role'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$stopId = isset($body['stop_id']) ? (int)$body['stop_id'] : 0;

if ($stopId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'stop_id is required']);
    exit;
}

$crewIds = (isset($body['crew_ids']) && is_array($body['crew_ids'])) ? $body['crew_ids'] : [];

try {
    $db     = getDB();
    $result = (new CrewAssignmentService($db))->assignCrew($stopId, $crewIds, 'this_visit', 0, $jwtUser['id']);
    echo json_encode($result);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[schedule/assign-crew] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
