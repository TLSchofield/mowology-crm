<?php
/**
 * API: Cost Factors Management
 * Handles CRUD for labor rates, equipment costs, materials, and overhead/margins
 * Returns JSON
 */

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

header('Content-Type: application/json');

$action = $_GET['action'] ?? null;
$db = getDB();

try {
    // Ensure cost_factors table exists
    $db->exec("
        CREATE TABLE IF NOT EXISTS `cost_factors` (
            `id` int NOT NULL AUTO_INCREMENT,
            `factor_name` varchar(100) NOT NULL,
            `factor_type` enum('labor','equipment','material','overhead','fuel','other') NOT NULL,
            `rate` decimal(10,2) NOT NULL,
            `rate_with_burden` decimal(10,2) DEFAULT NULL,
            `unit` varchar(20) DEFAULT 'per hour',
            `equipment_type` varchar(50) DEFAULT NULL,
            `supplier` varchar(100) DEFAULT NULL,
            `description` text,
            `active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `factor_name` (`factor_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // Ensure overhead_settings table exists
    $db->exec("
        CREATE TABLE IF NOT EXISTS `overhead_settings` (
            `id` int NOT NULL AUTO_INCREMENT,
            `setting_key` varchar(50) NOT NULL,
            `setting_value` decimal(10,2) NOT NULL,
            `description` text,
            `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `setting_key` (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // Check if rate_with_burden column exists, add if missing
    $cols = $db->query("SHOW COLUMNS FROM cost_factors LIKE 'rate_with_burden'")->rowCount();
    if ($cols === 0) {
        $db->exec("ALTER TABLE cost_factors ADD COLUMN `rate_with_burden` decimal(10,2) DEFAULT NULL AFTER `rate`");
    }
    $cols = $db->query("SHOW COLUMNS FROM cost_factors LIKE 'equipment_type'")->rowCount();
    if ($cols === 0) {
        $db->exec("ALTER TABLE cost_factors ADD COLUMN `equipment_type` varchar(50) DEFAULT NULL AFTER `unit`");
    }
    $cols = $db->query("SHOW COLUMNS FROM cost_factors LIKE 'supplier'")->rowCount();
    if ($cols === 0) {
        $db->exec("ALTER TABLE cost_factors ADD COLUMN `supplier` varchar(100) DEFAULT NULL AFTER `equipment_type`");
    }

    // ── List all cost factors ──────────────────────────────────
    if ($action === 'list') {
        $type = $_GET['type'] ?? null;
        $includeInactive = isset($_GET['inactive']) && $_GET['inactive'] === '1';

        $sql = "SELECT * FROM cost_factors WHERE 1";
        $params = [];

        if ($type) {
            $sql .= " AND factor_type = ?";
            $params[] = $type;
        }
        if (!$includeInactive) {
            $sql .= " AND active = 1";
        }

        $sql .= " ORDER BY factor_type, factor_name";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        echo json_encode([
            'success' => true,
            'factors' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);

    // ── Get a single cost factor ───────────────────────────────
    } elseif ($action === 'get') {
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if (!$id) throw new Exception('Factor ID is required');

        $stmt = $db->prepare("SELECT * FROM cost_factors WHERE id = ?");
        $stmt->execute([$id]);
        $factor = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$factor) throw new Exception('Cost factor not found');

        echo json_encode(['success' => true, 'factor' => $factor]);

    // ── Save (create or update) ────────────────────────────────
    } elseif ($action === 'save') {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['factor_name']) || !isset($data['rate'])) {
            throw new Exception('Name and rate are required');
        }

        $validTypes = ['labor', 'equipment', 'material', 'overhead', 'fuel', 'other'];
        if (empty($data['factor_type']) || !in_array($data['factor_type'], $validTypes)) {
            throw new Exception('Valid factor type is required');
        }

        if (!empty($data['id'])) {
            // Update
            $stmt = $db->prepare("
                UPDATE cost_factors SET
                    factor_name = ?, factor_type = ?, rate = ?, rate_with_burden = ?,
                    unit = ?, equipment_type = ?, supplier = ?, description = ?, active = ?
                WHERE id = ?
            ");
            $stmt->execute([
                trim($data['factor_name']),
                $data['factor_type'],
                floatval($data['rate']),
                !empty($data['rate_with_burden']) ? floatval($data['rate_with_burden']) : null,
                $data['unit'] ?? 'per hour',
                $data['equipment_type'] ?? null,
                $data['supplier'] ?? null,
                $data['description'] ?? null,
                isset($data['active']) ? intval($data['active']) : 1,
                intval($data['id'])
            ]);
            echo json_encode(['success' => true, 'message' => 'Cost factor updated']);
        } else {
            // Create
            $stmt = $db->prepare("
                INSERT INTO cost_factors
                    (factor_name, factor_type, rate, rate_with_burden, unit, equipment_type, supplier, description, active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                trim($data['factor_name']),
                $data['factor_type'],
                floatval($data['rate']),
                !empty($data['rate_with_burden']) ? floatval($data['rate_with_burden']) : null,
                $data['unit'] ?? 'per hour',
                $data['equipment_type'] ?? null,
                $data['supplier'] ?? null,
                $data['description'] ?? null,
                isset($data['active']) ? intval($data['active']) : 1
            ]);
            echo json_encode([
                'success' => true,
                'id' => $db->lastInsertId(),
                'message' => 'Cost factor created'
            ]);
        }

    // ── Delete ─────────────────────────────────────────────────
    } elseif ($action === 'delete') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id'])) throw new Exception('Factor ID is required');

        $stmt = $db->prepare("DELETE FROM cost_factors WHERE id = ?");
        $stmt->execute([intval($data['id'])]);

        echo json_encode(['success' => true, 'message' => 'Cost factor deleted']);

    // ── Toggle active status ───────────────────────────────────
    } elseif ($action === 'toggle-active') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id'])) throw new Exception('Factor ID is required');

        $stmt = $db->prepare("UPDATE cost_factors SET active = NOT active WHERE id = ?");
        $stmt->execute([intval($data['id'])]);

        echo json_encode(['success' => true, 'message' => 'Status toggled']);

    // ── Get overhead settings ──────────────────────────────────
    } elseif ($action === 'get-overhead') {
        $stmt = $db->query("SELECT * FROM overhead_settings ORDER BY setting_key");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build key-value map
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = floatval($row['setting_value']);
        }

        // Defaults if not set
        if (!isset($settings['overhead_percent'])) $settings['overhead_percent'] = 20;
        if (!isset($settings['profit_margin'])) $settings['profit_margin'] = 35;
        if (!isset($settings['gst_rate'])) $settings['gst_rate'] = 5;

        echo json_encode(['success' => true, 'settings' => $settings]);

    // ── Save overhead settings ─────────────────────────────────
    } elseif ($action === 'save-overhead') {
        $data = json_decode(file_get_contents('php://input'), true);

        $validKeys = ['overhead_percent', 'profit_margin', 'gst_rate'];
        $saved = 0;

        foreach ($validKeys as $key) {
            if (isset($data[$key])) {
                $stmt = $db->prepare("
                    INSERT INTO overhead_settings (setting_key, setting_value, description)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                ");
                $descriptions = [
                    'overhead_percent' => 'General overhead percentage (insurance, office, utilities)',
                    'profit_margin' => 'Target profit margin percentage',
                    'gst_rate' => 'GST tax rate'
                ];
                $stmt->execute([
                    $key,
                    floatval($data[$key]),
                    $descriptions[$key] ?? null
                ]);
                $saved++;
            }
        }

        echo json_encode(['success' => true, 'message' => "$saved setting(s) saved"]);

    // ── Seed default data ──────────────────────────────────────
    } elseif ($action === 'seed-defaults') {
        $count = $db->query("SELECT COUNT(*) FROM cost_factors")->fetchColumn();
        if ($count > 0) {
            echo json_encode(['success' => true, 'message' => 'Defaults already seeded', 'count' => intval($count)]);
            exit;
        }

        $defaults = [
            ['Owner/Manager', 'labor', 45.00, 52.00, 'per hour', null, null, 'Owner or manager hourly rate'],
            ['Foreman', 'labor', 35.00, 40.50, 'per hour', null, null, 'Lead crew member rate'],
            ['Laborer', 'labor', 25.00, 29.00, 'per hour', null, null, 'General laborer rate'],
            ['Riding Mower', 'equipment', 15.00, null, 'per hour', 'Equipment', null, 'Commercial riding mower (fuel + maintenance + depreciation)'],
            ['Walk-Behind Mower', 'equipment', 8.00, null, 'per hour', 'Equipment', null, 'Walk-behind mower (fuel + maintenance)'],
            ['Pickup Truck', 'equipment', 12.00, null, 'per hour', 'Vehicle', null, 'Pickup truck (fuel + insurance + depreciation)'],
            ['Dump Truck', 'equipment', 25.00, null, 'per hour', 'Vehicle', null, 'Dump truck (fuel + insurance + depreciation)'],
            ['String Trimmer', 'equipment', 3.00, null, 'per hour', 'Equipment', null, 'String trimmer / weed eater'],
            ['Leaf Blower', 'equipment', 2.50, null, 'per hour', 'Equipment', null, 'Backpack or handheld blower'],
            ['Cedar Mulch', 'material', 35.00, null, 'per yard', null, 'Landscape Supply Co.', 'Bulk cedar mulch'],
            ['Topsoil', 'material', 25.00, null, 'per yard', null, 'Soil Depot', 'Screened topsoil'],
            ['River Rock', 'material', 55.00, null, 'per yard', null, 'Landscape Supply Co.', 'Decorative river rock'],
            ['Grass Seed', 'material', 4.50, null, 'per kg', null, 'Garden Centre', 'Premium grass seed blend'],
            ['Fertilizer', 'material', 28.00, null, 'per bag', null, 'Garden Centre', 'Slow-release lawn fertilizer (25kg)'],
        ];

        $stmt = $db->prepare("
            INSERT INTO cost_factors (factor_name, factor_type, rate, rate_with_burden, unit, equipment_type, supplier, description, active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");

        foreach ($defaults as $row) {
            $stmt->execute($row);
        }

        // Seed overhead settings
        $overheadDefaults = [
            ['overhead_percent', 20, 'General overhead percentage (insurance, office, utilities)'],
            ['profit_margin', 35, 'Target profit margin percentage'],
            ['gst_rate', 5, 'GST tax rate'],
        ];
        $osStmt = $db->prepare("
            INSERT INTO overhead_settings (setting_key, setting_value, description)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        foreach ($overheadDefaults as $row) {
            $osStmt->execute($row);
        }

        echo json_encode(['success' => true, 'message' => 'Default cost factors seeded', 'count' => count($defaults)]);

    } else {
        throw new Exception('Invalid action: ' . htmlspecialchars($action ?? ''));
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
