<?php
/**
 * API: Property Measurements CRUD
 * /public/crm/api/measurements.php
 *
 * Actions:
 *   list    (GET)  — all property_measurements, optionally filtered by property_id
 *   update  (POST) — update name, measurement_type, notes for a measurement
 *   delete  (POST) — hard-delete a measurement row + recalc property totals
 *
 * Requires CRM login. Writes require CSRF token.
 * Returns JSON.
 */

declare(strict_types=1);

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

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';

requireLogin();
$user = getCurrentUser();
session_write_close();

header('Content-Type: application/json');

$action = $_GET['action'] ?? null;
$db     = getDB();

try {

    if ($action === 'list') {
        $propertyId = isset($_GET['property_id']) ? (int)$_GET['property_id'] : null;
        $typeFilter = isset($_GET['type']) ? trim($_GET['type']) : null;
        $groupFilter = isset($_GET['group_key']) ? trim($_GET['group_key']) : null;
        $search = isset($_GET['search']) ? trim($_GET['search']) : null;

        $sql = "
            SELECT
                pm.id,
                pm.property_id,
                pm.measurement_name,
                pm.measurement_type,
                pm.measurement_group_key,
                pm.area_sqft,
                pm.area_sqm,
                pm.perimeter_ft,
                pm.linear_ft,
                pm.measurement_shape,
                pm.notes,
                pm.created_at,
                pm.updated_at,
                p.address      AS property_address,
                p.city         AS property_city,
                u.full_name    AS measured_by_name
            FROM property_measurements pm
            LEFT JOIN properties p ON p.id = pm.property_id
            LEFT JOIN users u ON u.id = pm.measured_by
            WHERE 1=1
        ";
        $params = [];

        if ($propertyId) {
            $sql .= " AND pm.property_id = ?";
            $params[] = $propertyId;
        }
        if ($typeFilter) {
            $sql .= " AND pm.measurement_type = ?";
            $params[] = $typeFilter;
        }
        if ($groupFilter) {
            $sql .= " AND pm.measurement_group_key = ?";
            $params[] = $groupFilter;
        }
        if ($search) {
            $sql .= " AND (pm.measurement_name LIKE ? OR p.address LIKE ?)";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        $sql .= " ORDER BY p.address ASC, pm.created_at DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cast numeric fields
        foreach ($rows as &$r) {
            $r['id']          = (int)$r['id'];
            $r['property_id'] = (int)$r['property_id'];
            $r['area_sqft']   = $r['area_sqft'] !== null ? (float)$r['area_sqft'] : null;
            $r['area_sqm']    = $r['area_sqm'] !== null ? (float)$r['area_sqm'] : null;
            $r['perimeter_ft']= $r['perimeter_ft'] !== null ? (float)$r['perimeter_ft'] : null;
            $r['linear_ft']   = $r['linear_ft'] !== null ? (float)$r['linear_ft'] : null;
        }
        unset($r);

        // Also return available types + groups for filter dropdowns
        $types = [];
        try {
            $types = $db->query("SELECT DISTINCT measurement_type FROM property_measurements ORDER BY measurement_type")->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {}

        $groups = [];
        try {
            $groups = $db->query("SELECT group_key, group_label FROM measurement_groups WHERE is_active = 1 ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}

        echo json_encode([
            'success'      => true,
            'measurements' => $rows,
            'count'        => count($rows),
            'filter_types' => $types,
            'filter_groups'=> $groups,
        ]);

    } elseif ($action === 'save') {
        // Replace-all save of a property's measurements (shared MapDrawTool engine).
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST required');

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid security token']);
            exit;
        }

        $propId       = (int)($input['property_id'] ?? 0);
        $measurements = $input['measurements'] ?? [];
        if (!$propId) throw new Exception('Property ID required');
        if (!is_array($measurements) || empty($measurements)) {
            throw new Exception('No measurements to save');
        }

        require_once APP_ROOT . '/Services/MeasurementService.php';
        if (!function_exists('saveMeasurementsForProperty')) {
            throw new Exception('MeasurementService not available');
        }

        $result = saveMeasurementsForProperty($propId, $measurements, (int)$user['id']);
        echo json_encode($result);

    } elseif ($action === 'update') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST required');

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid security token']);
            exit;
        }

        $id   = (int)($input['id'] ?? 0);
        if (!$id) throw new Exception('Measurement ID required');

        $name      = trim($input['measurement_name'] ?? '');
        $type      = trim($input['measurement_type'] ?? '');
        $groupKey  = trim($input['measurement_group_key'] ?? '');
        $notes     = trim($input['notes'] ?? '');

        if (!$name) throw new Exception('Measurement name is required');

        // Build update dynamically based on what's provided
        $sets   = ['measurement_name = ?'];
        $params = [$name];

        if ($type !== '') {
            $sets[]   = 'measurement_type = ?';
            $params[] = $type;
        }
        if ($groupKey !== '') {
            $sets[]   = 'measurement_group_key = ?';
            $params[] = $groupKey;
        }
        $sets[]   = 'notes = ?';
        $params[] = $notes ?: null;

        $params[] = $id;

        $db->prepare("UPDATE property_measurements SET " . implode(', ', $sets) . " WHERE id = ?")
           ->execute($params);

        echo json_encode(['success' => true, 'message' => 'Measurement updated']);

    } elseif ($action === 'delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST required');

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid security token']);
            exit;
        }

        $id = (int)($input['id'] ?? 0);
        if (!$id) throw new Exception('Measurement ID required');

        // Get property_id before deleting so we can recalc totals
        $row = $db->prepare("SELECT property_id FROM property_measurements WHERE id = ?");
        $row->execute([$id]);
        $row = $row->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Measurement not found');

        $propertyId = (int)$row['property_id'];

        $db->prepare("DELETE FROM property_measurements WHERE id = ?")->execute([$id]);

        // Recalculate property measurement totals
        recalcPropertyMeasurementTotals($db, $propertyId);

        echo json_encode(['success' => true, 'message' => 'Measurement deleted']);

    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action ?? '')]);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}


/**
 * Recalculate the cached totals on the properties row after a measurement change.
 */
function recalcPropertyMeasurementTotals(PDO $db, int $propertyId): void
{
    try {
        // lawn + garden sqft
        $lawn = $db->prepare("
            SELECT COALESCE(SUM(area_sqft), 0)
            FROM property_measurements
            WHERE property_id = ? AND measurement_type IN ('lawn', 'garden')
        ");
        $lawn->execute([$propertyId]);
        $lawnTotal = (float)$lawn->fetchColumn();

        // driveway + walkway + patio + parking sqft
        $hard = $db->prepare("
            SELECT COALESCE(SUM(area_sqft), 0)
            FROM property_measurements
            WHERE property_id = ? AND measurement_type IN ('driveway', 'walkway', 'patio', 'parking')
        ");
        $hard->execute([$propertyId]);
        $hardTotal = (float)$hard->fetchColumn();

        // hedge linear ft
        $hedge = $db->prepare("
            SELECT COALESCE(SUM(linear_ft), 0)
            FROM property_measurements
            WHERE property_id = ? AND measurement_type = 'hedge'
        ");
        $hedge->execute([$propertyId]);
        $hedgeTotal = (float)$hedge->fetchColumn();

        $db->prepare("
            UPDATE properties
            SET total_lawn_sqft          = ?,
                total_hard_surface_sqft  = ?,
                total_hedge_linear_ft    = ?,
                measurements_updated_at  = NOW()
            WHERE id = ?
        ")->execute([$lawnTotal, $hardTotal, $hedgeTotal, $propertyId]);

    } catch (PDOException $e) {
        // Columns may not exist yet — non-fatal
        error_log('recalcPropertyMeasurementTotals: ' . $e->getMessage());
    }
}
