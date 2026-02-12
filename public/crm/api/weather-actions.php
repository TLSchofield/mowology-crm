<?php
/**
 * API: Weather Actions
 * Handles the weather action list — approve moves, keep visits, dismiss alerts.
 * Returns JSON.
 */

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/plan-functions.php';
require_once dirname(__DIR__) . '/modules/scheduling/rescheduler.php';
require_once dirname(__DIR__) . '/modules/snapshots/snapshot-manager.php';

requireLogin();
$user = getCurrentUser();

header('Content-Type: application/json');

$action = $_GET['action'] ?? null;
$db = getDB();

try {
    if ($action === 'list') {
        // Get visits with weather flags (NOT_OK or BORDERLINE)
        $dateFrom = $_GET['from'] ?? date('Y-m-d');
        $dateTo   = $_GET['to'] ?? date('Y-m-d', strtotime('+7 days'));

        $stmt = $db->prepare("
            SELECT v.id AS visit_id, v.visit_number, v.scheduled_date,
                   v.scheduled_time_start, v.scheduled_time_end,
                   v.assigned_crew_id, v.status,
                   v.weather_ok, v.weather_status, v.weather_reason,
                   v.weather_decision_at, v.weather_snapshot_raw, v.weather_card_path,
                   p.service_type, p.title AS plan_title, p.service_package_id,
                   prop.address, prop.city,
                   sp.package_name AS service_name, sp.weather_policy,
                   u.first_name AS crew_first_name, u.last_name AS crew_last_name
            FROM job_visits v
            JOIN job_plans p ON v.plan_id = p.id
            JOIN properties prop ON p.property_id = prop.id
            LEFT JOIN service_packages sp ON p.service_package_id = sp.id
            LEFT JOIN users u ON v.assigned_crew_id = u.id
            WHERE v.scheduled_date BETWEEN ? AND ?
              AND v.weather_status IN ('NOT_OK', 'BORDERLINE')
              AND v.status = 'scheduled'
            ORDER BY v.scheduled_date, v.scheduled_time_start
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Decode snapshot for worst_hour info
        foreach ($visits as &$v) {
            $v['snapshot'] = null;
            $v['worst_hour'] = null;
            if (!empty($v['weather_snapshot_raw'])) {
                $snap = json_decode($v['weather_snapshot_raw'], true);
                if ($snap) {
                    $v['worst_hour'] = $snap['worst_hour'] ?? null;
                    $v['snapshot_summary'] = $snap['summary'] ?? '';
                    $v['checks'] = $snap['checks'] ?? [];
                }
                unset($v['weather_snapshot_raw']); // Don't send raw blob
            }
        }

        // Get recent weather action log entries
        $logStmt = $db->prepare("
            SELECT action_type, entity_type, entity_id, details, created_at
            FROM weather_action_log
            WHERE action_date >= ? AND entity_type = 'visit'
            ORDER BY created_at DESC
            LIMIT 100
        ");
        $logStmt->execute([$dateFrom]);
        $logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'visits'  => $visits,
            'logs'    => $logs,
            'count'   => count($visits),
        ]);

    } elseif ($action === 'approve_move') {
        // Approve a suggested reschedule
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception('POST method required');
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $visitId    = (int)($data['visit_id'] ?? 0);
        $newDate    = $data['new_date'] ?? null;
        $newTime    = $data['new_time'] ?? null;

        if (!$visitId || !$newDate) {
            throw new Exception('Visit ID and new date are required');
        }

        $result = executeReschedule($visitId, $newDate, $newTime, $user['id'], 'Manual approve: weather');
        echo json_encode($result);

    } elseif ($action === 'keep') {
        // Keep the visit as-is despite weather (manual override)
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception('POST method required');
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $visitId = (int)($data['visit_id'] ?? 0);
        $reason  = $data['reason'] ?? 'Manual override — keeping visit';

        if (!$visitId) {
            throw new Exception('Visit ID is required');
        }

        clearWeatherEvaluation($visitId, $reason);
        logWeatherAction('MANUAL_KEEP', 'visit', $visitId, json_encode([
            'reason' => $reason,
            'user'   => $user['id'],
        ]), $user['id']);

        echo json_encode(['success' => true, 'message' => 'Visit kept, weather override applied']);

    } elseif ($action === 'dismiss') {
        // Dismiss from action list without changing visit
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception('POST method required');
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $visitId = (int)($data['visit_id'] ?? 0);

        if (!$visitId) {
            throw new Exception('Visit ID is required');
        }

        logWeatherAction('DISMISSED', 'visit', $visitId, json_encode([
            'user' => $user['id'],
        ]), $user['id']);

        echo json_encode(['success' => true, 'message' => 'Action item dismissed']);

    } elseif ($action === 'run-guard') {
        // Manually trigger the weather schedule guard
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception('POST method required');
        }

        if (($user['role'] ?? '') !== 'admin') {
            throw new Exception('Admin access required');
        }

        // Execute the guard cron inline
        ob_start();
        $_SERVER['REQUEST_METHOD'] = 'POST'; // Ensure cron thinks it's POST
        include dirname(__DIR__) . '/cron/weather_schedule_guard.php';
        $output = ob_get_clean();

        $result = json_decode($output, true);
        echo json_encode($result ?: ['success' => false, 'error' => 'Guard returned no output']);

    } else {
        throw new Exception('Invalid action: ' . htmlspecialchars($action ?? ''));
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
