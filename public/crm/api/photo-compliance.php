<?php
/**
 * Photo Compliance API
 *
 * GET ?action=crew_summary&days=30   — per-crew compliance rates
 * GET ?action=missing_list&days=7    — completed visits missing required photos
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if ($user['role'] !== 'admin') { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }

header('Content-Type: application/json');
session_write_close();

$db     = getDB();
$action = $_GET['action'] ?? 'crew_summary';
$days   = max(1, min(365, (int)($_GET['days'] ?? 30)));
$since  = date('Y-m-d H:i:s', strtotime("-{$days} days"));

try {

    if ($action === 'crew_summary') {
        // Fetch all plans that have photo requirements
        $plans = $db->query("
            SELECT id, photo_types_required, photos_block_completion
            FROM job_plans
            WHERE photos_block_completion = 1
              AND photo_types_required IS NOT NULL
              AND photo_types_required != ''
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($plans)) {
            echo json_encode(['rows' => [], 'note' => 'No plans have photo requirements configured.']);
            exit;
        }

        // Build required-count map: plan_id => count of required types
        $requiredCountMap = [];
        foreach ($plans as $p) {
            $types = json_decode($p['photo_types_required'], true) ?: [];
            $requiredCountMap[(int)$p['id']] = count($types);
        }
        $planIds = array_keys($requiredCountMap);
        $placeholders = implode(',', array_fill(0, count($planIds), '?'));

        // Fetch completed visits for these plans in date range
        $visitStmt = $db->prepare("
            SELECT jv.id AS visit_id, jv.plan_id, jv.assigned_crew_id,
                   u.full_name AS crew_name
            FROM job_visits jv
            LEFT JOIN users u ON u.id = jv.assigned_crew_id
            WHERE jv.plan_id IN ({$placeholders})
              AND jv.status = 'completed'
              AND jv.completed_at >= ?
        ");
        $visitStmt->execute(array_merge($planIds, [$since]));
        $visits = $visitStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($visits)) {
            echo json_encode(['rows' => [], 'note' => "No completed visits in the last {$days} days with photo requirements."]);
            exit;
        }

        // Fetch uploaded types for all those visits in one query
        $visitIds = array_column($visits, 'visit_id');
        $vPlaceholders = implode(',', array_fill(0, count($visitIds), '?'));
        $photoStmt = $db->prepare("
            SELECT visit_id, photo_type FROM visit_photos
            WHERE visit_id IN ({$vPlaceholders}) AND deleted_at IS NULL
        ");
        $photoStmt->execute($visitIds);
        $allPhotos = $photoStmt->fetchAll(PDO::FETCH_ASSOC);

        // Group uploaded types by visit_id
        $uploadedByVisit = [];
        foreach ($allPhotos as $ph) {
            $uploadedByVisit[$ph['visit_id']][] = $ph['photo_type'];
        }

        // Compute per-crew stats
        $crewStats = [];
        foreach ($visits as $v) {
            $crewId   = $v['assigned_crew_id'] ?? 0;
            $crewName = $v['crew_name'] ?? 'Unassigned';
            $required = $requiredCountMap[(int)$v['plan_id']] ?? 0;
            $uploaded = count(array_unique($uploadedByVisit[$v['visit_id']] ?? []));
            $compliant = ($uploaded >= $required) ? 1 : 0;

            if (!isset($crewStats[$crewId])) {
                $crewStats[$crewId] = ['crew_name' => $crewName, 'total' => 0, 'compliant' => 0];
            }
            $crewStats[$crewId]['total']++;
            $crewStats[$crewId]['compliant'] += $compliant;
        }

        $rows = [];
        foreach ($crewStats as $stats) {
            $pct = $stats['total'] > 0 ? round(($stats['compliant'] / $stats['total']) * 100) : 0;
            $rows[] = [
                'crew_name'  => $stats['crew_name'],
                'total'      => $stats['total'],
                'compliant'  => $stats['compliant'],
                'pct'        => $pct,
            ];
        }
        usort($rows, function($a, $b) { return $a['pct'] - $b['pct']; });

        echo json_encode(['rows' => $rows, 'days' => $days]);

    } elseif ($action === 'missing_list') {
        // Find completed visits with required photos missing
        $plans = $db->query("
            SELECT id, photo_types_required
            FROM job_plans
            WHERE photos_block_completion = 1
              AND photo_types_required IS NOT NULL
              AND photo_types_required != ''
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($plans)) {
            echo json_encode(['rows' => []]);
            exit;
        }

        $planIds      = array_column($plans, 'id');
        $placeholders = implode(',', array_fill(0, count($planIds), '?'));

        $visitStmt = $db->prepare("
            SELECT jv.id AS visit_id, jv.plan_id, jv.visit_number,
                   jv.completed_at, jv.assigned_crew_id,
                   u.full_name AS crew_name,
                   pr.address AS property_address
            FROM job_visits jv
            LEFT JOIN users u ON u.id = jv.assigned_crew_id
            LEFT JOIN job_plans jp ON jp.id = jv.plan_id
            LEFT JOIN properties pr ON pr.id = jp.property_id
            WHERE jv.plan_id IN ({$placeholders})
              AND jv.status = 'completed'
              AND jv.completed_at >= ?
            ORDER BY jv.completed_at DESC
            LIMIT 50
        ");
        $visitStmt->execute(array_merge($planIds, [$since]));
        $visits = $visitStmt->fetchAll(PDO::FETCH_ASSOC);

        // Build required map
        $requiredMap = [];
        foreach ($plans as $p) {
            $requiredMap[$p['id']] = json_decode($p['photo_types_required'], true) ?: [];
        }

        // Get uploaded types
        $visitIds = array_column($visits, 'visit_id');
        if (empty($visitIds)) { echo json_encode(['rows' => []]); exit; }

        $vPh = implode(',', array_fill(0, count($visitIds), '?'));
        $photoStmt = $db->prepare("
            SELECT visit_id, photo_type FROM visit_photos
            WHERE visit_id IN ({$vPh}) AND deleted_at IS NULL
        ");
        $photoStmt->execute($visitIds);
        $uploadedByVisit = [];
        foreach ($photoStmt->fetchAll(PDO::FETCH_ASSOC) as $ph) {
            $uploadedByVisit[$ph['visit_id']][] = $ph['photo_type'];
        }

        $rows = [];
        foreach ($visits as $v) {
            $required = $requiredMap[$v['plan_id']] ?? [];
            $uploaded = $uploadedByVisit[$v['visit_id']] ?? [];
            $missing  = array_values(array_diff($required, $uploaded));
            if (!empty($missing)) {
                $rows[] = [
                    'visit_id'    => $v['visit_id'],
                    'visit_number'=> $v['visit_number'],
                    'crew_name'   => $v['crew_name'] ?? 'Unassigned',
                    'property'    => $v['property_address'] ?? '—',
                    'completed_at'=> $v['completed_at'],
                    'missing'     => $missing,
                ];
            }
        }

        echo json_encode(['rows' => $rows, 'days' => $days]);

    } else {
        echo json_encode(['error' => 'Unknown action']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
