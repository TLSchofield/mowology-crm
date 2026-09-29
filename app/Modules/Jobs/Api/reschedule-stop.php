<?php
/**
 * Reschedule Calendar Stop API
 * Moves a stop (and all its linked scheduled visits) to a new date/time.
 *
 * POST JSON: { stop_id, new_date, new_route_order?, new_time?, force?, append_to_end? }
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
    require_once CRM_INCLUDES . '/plan-functions.php';
    require_once CRM_INCLUDES . '/weather-service.php';
    require_once dirname(CRM_INCLUDES) . '/modules/weather/weather-rules.php';
    require_once APP_ROOT . '/Modules/Jobs/Services/StopRescheduleService.php';

    requireLogin();
    $user = getCurrentUser();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed');
    }

    // Get input
    $input = json_decode(file_get_contents('php://input'), true);

    // Must run before session_write_close() below — verifyCSRFToken() reads
    // $_SESSION, which session_write_close() clears from memory.
    $csrfToken = $input['csrf_token'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        throw new Exception('Invalid CSRF token');
    }

    session_write_close(); // release session lock — no session writes needed beyond this point

    if (!$input || !isset($input['stop_id']) || !isset($input['new_date'])) {
        throw new Exception('Missing required fields: stop_id, new_date');
    }

    $stopId = (int)$input['stop_id'];
    $newDate = (string)$input['new_date'];
    $newRouteOrder = isset($input['new_route_order']) ? (int)$input['new_route_order'] : null;
    $newTime = $input['new_time'] ?? null;
    $force = !empty($input['force']);
    // Mobile "Move" button sends no route position — land the stop last on the new day.
    $appendToEnd = !empty($input['append_to_end']);

    if (!StopRescheduleService::isValidDate($newDate)) {
        throw new Exception('Invalid date format');
    }

    $db = getDB();
    $rescheduler = new StopRescheduleService($db);

    // ── Capacity warning ─────────────────────────────────────────────────────
    // Soft warning so the client can ask for confirmation (force=1).
    if (!$force) {
        $warning = $rescheduler->capacityWarning($stopId, $newDate);
        if ($warning) {
            http_response_code(200);
            echo json_encode(['warning' => true] + $warning);
            exit;
        }
    }

    $moved = $rescheduler->reschedule($stopId, $newDate, $newRouteOrder, $newTime, $appendToEnd);
    $resultStopId  = $moved['stop_id'];
    $oldDate       = $moved['old_date'];
    $newRouteOrder = $moved['new_route_order'];

    // Log activity
    logActivityExtended(
        $user['id'],
        'Stop rescheduled',
        "Stop moved from {$oldDate} to {$newDate}",
        null, null, null, null, null, null
    );

    // Fresh Day Summary Card HTML for every affected day column, so the
    // client can patch the schedule in place instead of reloading the page.
    $dayCards = [];
    foreach (array_unique([$newDate, $oldDate]) as $renderDate) {
        $isToday = ($renderDate === date('Y-m-d'));
        $bcData  = computeDayBattleCard($renderDate);
        $dateStr = $renderDate; // expected by the partial
        ob_start();
        include PUBLIC_ROOT . '/crm/partials/day-summary-card.php';
        $dayCards[$renderDate] = ob_get_clean();
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Stop rescheduled successfully',
        'stop_id' => $resultStopId,
        'new_date' => $newDate,
        'new_route_order' => $newRouteOrder,
        'day_cards' => $dayCards
    ]);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('reschedule-stop.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
