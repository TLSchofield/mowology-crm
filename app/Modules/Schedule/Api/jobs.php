<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/jobs.php
 *
 * Mobile Jobs API — JWT authenticated
 *
 * GET /api/schedule/jobs
 *   ?status=scheduled|in_progress|completed|all  (default: all)
 *   ?limit=20  (max 100)
 *   ?offset=0
 *
 * Role-scoped:
 *   admin/manager → all visits in the system
 *   user (crew)   → only visits assigned to them
 *
 * Response 200:
 * {
 *   "success": true,
 *   "total": 142,
 *   "limit": 20,
 *   "offset": 0,
 *   "visits": [
 *     {
 *       "visit_id": 1,
 *       "visit_number": "PLN-2026-0001-V001",
 *       "plan_title": "Weekly Lawn Mowing",
 *       "service_type": "lawn_care",
 *       "visit_status": "completed",
 *       "property_address": "123 Oak St",
 *       "property_city": "Vancouver",
 *       "scheduled_date": "2026-05-01",
 *       "scheduled_start": "09:00",
 *       "estimated_duration": 45,
 *       "price_per_visit": 75.00,
 *       "stop_id": 42
 *     }
 *   ]
 * }
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

$jwtUser = requireJwt();

$userId  = (int)$jwtUser['id'];
$isAdmin = jwtIsAdmin($jwtUser['role']);

// ── Input ─────────────────────────────────────────────────────────────────────
$validStatuses = ['scheduled', 'in_progress', 'completed', 'cancelled', 'skipped', 'all'];
$statusFilter  = $_GET['status'] ?? 'all';
if (!in_array($statusFilter, $validStatuses, true)) $statusFilter = 'all';

$limit  = min(100, max(1, (int)($_GET['limit']  ?? 20)));
$offset = max(0,        (int)($_GET['offset'] ?? 0));

try {
    $db = getDB();

    // ── Build WHERE clause ────────────────────────────────────────────────────
    $where  = [];
    $params = [];

    if (!$isAdmin) {
        $where[]  = 'jv.assigned_crew_id = ?';
        $params[] = $userId;
    }

    if ($statusFilter !== 'all') {
        $where[]  = 'jv.status = ?';
        $params[] = $statusFilter;
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // ── Count ─────────────────────────────────────────────────────────────────
    $countStmt = $db->prepare("
        SELECT COUNT(*) FROM job_visits jv {$whereSQL}
    ");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // ── Fetch ─────────────────────────────────────────────────────────────────
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $db->prepare("
        SELECT
            jv.id            AS visit_id,
            jv.visit_number,
            jp.title         AS plan_title,
            jv.service_type,
            jv.status        AS visit_status,
            p.address        AS property_address,
            p.city           AS property_city,
            jv.scheduled_date,
            jv.scheduled_time_start AS scheduled_start,
            jv.stop_id,
            COALESCE(pkg.estimated_duration_minutes, sp.estimated_duration_minutes) AS estimated_duration,
            COALESCE(pkg.price_per_visit, sp.price)                                 AS price_per_visit
        FROM job_visits jv
        JOIN job_plans jp ON jv.plan_id = jp.id
        LEFT JOIN properties p ON jp.property_id = p.id
        LEFT JOIN service_packages sp  ON jp.service_package_id = sp.id
        LEFT JOIN service_packages pkg ON jv.package_id = pkg.id
        {$whereSQL}
        ORDER BY jv.scheduled_date DESC, jv.scheduled_time_start ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $visits = array_map(static function (array $r): array {
        return [
            'visit_id'          => (int)$r['visit_id'],
            'visit_number'      => $r['visit_number'],
            'plan_title'        => $r['plan_title'],
            'service_type'      => $r['service_type'],
            'visit_status'      => $r['visit_status'],
            'property_address'  => $r['property_address'] ?? '',
            'property_city'     => $r['property_city']    ?? '',
            'scheduled_date'    => $r['scheduled_date'],
            'scheduled_start'   => $r['scheduled_start'] ? substr($r['scheduled_start'], 0, 5) : null,
            'estimated_duration'=> $r['estimated_duration'] ? (int)$r['estimated_duration'] : null,
            'price_per_visit'   => $r['price_per_visit']    ? (float)$r['price_per_visit']  : null,
            'stop_id'           => $r['stop_id'] ? (int)$r['stop_id'] : null,
        ];
    }, $rows);

    echo json_encode([
        'success' => true,
        'total'   => $total,
        'limit'   => $limit,
        'offset'  => $offset,
        'visits'  => $visits,
    ]);

} catch (Throwable $e) {
    error_log('[schedule/jobs] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load jobs.']);
}
