<?php
/**
 * Assign Crew to Calendar Stop API
 * Supports multi-crew assignment via the calendar_stop_crew junction table.
 * The first crew_id in the array becomes the "lead" crew on calendar_stops.crew_id.
 *
 * POST JSON: { stop_id, crew_ids: [1, 2, 3] }
 * Also accepts legacy format: { stop_id, crew_id } (single crew)
 * crew_ids can be empty array or [null] to unassign all.
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
    require_once APP_ROOT . '/Modules/Jobs/Services/CrewAssignmentService.php';

    requireLogin();
    $user = getCurrentUser();
    requirePermission('jobs.edit');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed');
    }

    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        throw new Exception('Invalid CSRF token');
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['stop_id'])) {
        throw new Exception('Missing required field: stop_id');
    }

    $stopId = (int)$input['stop_id'];
    $scope  = ($input['scope'] ?? 'this_visit') === 'all_future' ? 'all_future' : 'this_visit';
    $planId = isset($input['plan_id']) ? (int)$input['plan_id'] : 0;

    // Accept crew_ids array (new) or crew_id (legacy)
    $crewIds = [];
    if (isset($input['crew_ids']) && is_array($input['crew_ids'])) {
        $crewIds = $input['crew_ids'];
    } elseif (isset($input['crew_id']) && $input['crew_id'] !== '' && $input['crew_id'] !== null) {
        $crewIds = [$input['crew_id']];
    }

    $db = getDB();
    $result = (new CrewAssignmentService($db))->assignCrew($stopId, $crewIds, $scope, $planId, (int)$user['id']);

    http_response_code(200);
    echo json_encode($result);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
