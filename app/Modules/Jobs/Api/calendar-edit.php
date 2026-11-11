<?php
/**
 * Calendar edit API — create a job/task on the calendar, resize one visit, undo, title suggestions.
 * Thin controller: the rules live in CalendarCreateService / VisitResizeService. A MOVE does not go
 * through here; the calendar calls the existing reschedule-stop.php.
 *
 * POST JSON { action: create|resize|undo|titles, csrf_token, ... }
 * Off unless ops_settings it_calendar_edit = '1'; office only, same rule as reschedule-stop.php.
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
    require_once APP_ROOT . '/Core/ApiResponse.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once CRM_INCLUDES . '/plan-functions.php';
    require_once CRM_INCLUDES . '/weather-service.php';
    require_once APP_ROOT . '/Modules/Jobs/Services/VisitResizeService.php';
    require_once APP_ROOT . '/Modules/Jobs/Services/CalendarCreateService.php';

    requireLogin();
    $user = getCurrentUser();

    if (!in_array($user['role'] ?? '', ['admin', 'manager'], true) && !userHasPermission('jobs.edit')) {
        http_response_code(403);
        echo json_encode(['error' => 'Only the office can change the calendar.']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed');
    }
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    // Before session_write_close(): verifyCSRFToken() reads $_SESSION.
    if (!verifyCSRFToken($input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        throw new Exception('Invalid CSRF token');
    }
    session_write_close();

    $db = getDB();
    if (!VisitResizeService::enabled($db)) {
        http_response_code(403);
        echo json_encode(['error' => 'Calendar editing is switched off.']);
        exit;
    }

    $resizer = new VisitResizeService($db);
    $action  = (string)($input['action'] ?? '');

    // Fresh Day Summary Card HTML for the affected day, like reschedule-stop.php.
    $dayCards = static function (string $date): array {
        $isToday = ($date === date('Y-m-d'));
        $bcData  = computeDayBattleCard($date);
        $dateStr = $date;
        ob_start();
        include PUBLIC_ROOT . '/crm/partials/day-summary-card.php';
        return [$date => ob_get_clean()];
    };

    switch ($action) {
        case 'crew':
            $rows = $db->query("SELECT id, full_name FROM users WHERE is_active = 1 AND full_name <> '' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'crew' => array_map(static function ($r) { return ['id' => (int)$r['id'], 'name' => $r['full_name']]; }, $rows)]);
            break;

        case 'titles':
            echo json_encode(['success' => true, 'titles' => (new CalendarCreateService($db))->titles((int)($input['property_id'] ?? 0), (string)($input['q'] ?? ''))]);
            break;

        case 'create':
            $res = (new CalendarCreateService($db))->create($input, (int)$user['id']);
            if (empty($res['success'])) {
                http_response_code(422);
                echo json_encode($res);
                break;
            }
            logActivityExtended($user['id'], 'Job added on calendar', "Plan #{$res['plan_id']} on {$res['date']}", null, null, null, null, null, null);
            echo json_encode($res + ['day_cards' => $dayCards($res['date']), 'message' => 'Created visit']);
            break;

        case 'resize':
            try {
                $res = $resizer->resize((int)($input['visit_id'] ?? 0), (string)($input['start'] ?? ''), (string)($input['end'] ?? ''), !empty($input['force']));
            } catch (InvalidArgumentException $e) {
                http_response_code(422);
                echo json_encode(['error' => ApiResponse::message($e, 'calendar-edit')]);
                break;
            } catch (RuntimeException $e) {
                http_response_code(409);
                echo json_encode(['error' => ApiResponse::message($e, 'calendar-edit')]);
                break;
            }
            if (!empty($res['success'])) {
                logActivityExtended($user['id'], 'Visit resized', 'Visit #' . (int)$input['visit_id'] . " set to {$input['start']}-{$input['end']}", null, null, null, null, null, null);
                $res['day_cards'] = $dayCards($res['date']);
                $res['message'] = 'Rescheduled visit';
            }
            echo json_encode($res);
            break;

        case 'undo':
            $p = (array)($input['previous'] ?? []);
            try {
                $resizer->restore((int)($input['visit_id'] ?? 0), $p['start'] ?? null, $p['end'] ?? null, isset($p['override']) ? (int)$p['override'] : null);
            } catch (RuntimeException $e) {
                http_response_code(409);
                echo json_encode(['error' => ApiResponse::message($e, 'calendar-edit')]);
                break;
            }
            $v = $resizer->visit((int)($input['visit_id'] ?? 0));
            logActivityExtended($user['id'], 'Visit resize undone', 'Visit #' . (int)$input['visit_id'], null, null, null, null, null, null);
            echo json_encode(['success' => true, 'day_cards' => $v ? $dayCards((string)$v['scheduled_date']) : []]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    error_log('calendar-edit.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['error' => ApiResponse::message($e, 'calendar-edit')]);
}
