<?php
/**
 * API: Ops Settings Management
 * Handles read/write for ops_settings table (key-value operational config).
 * Returns JSON.
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

// Admin only
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? null;
$db = getDB();

try {
    if ($action === 'get') {
        // Get a single setting by key
        $key = $_GET['key'] ?? null;
        if (!$key) {
            throw new Exception('Setting key is required');
        }

        $stmt = $db->prepare("SELECT setting_value, description, updated_at FROM ops_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['success' => true, 'value' => null, 'exists' => false]);
        } else {
            $value = $row['setting_value'];
            // Try to decode JSON
            $decoded = json_decode($value, true);
            echo json_encode([
                'success'     => true,
                'exists'      => true,
                'value'       => $decoded !== null ? $decoded : $value,
                'raw'         => $value,
                'description' => $row['description'],
                'updated_at'  => $row['updated_at'],
            ]);
        }

    } elseif ($action === 'save') {
        // Save/upsert a setting
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception('POST method required');
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $key = $data['key'] ?? null;
        $value = $data['value'] ?? null;
        $description = $data['description'] ?? null;

        if (!$key) {
            throw new Exception('Setting key is required');
        }

        // Encode value as JSON if it's an array/object
        if (is_array($value)) {
            $value = json_encode($value);
        }

        $stmt = $db->prepare("
            INSERT INTO ops_settings (setting_key, setting_value, description, updated_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description), updated_by = VALUES(updated_by)
        ");
        $stmt->execute([$key, $value, $description, $user['id']]);

        echo json_encode(['success' => true, 'message' => 'Setting saved']);

    } elseif ($action === 'get-service-weather-rules') {
        // Get weather rules for all service packages
        $stmt = $db->prepare("
            SELECT id, package_name, slug, category, service_type, is_active,
                   weather_policy, max_precip_chance_pct, max_precip_mm_per_hr,
                   min_temp_c, max_temp_c, max_wind_kph,
                   move_window_hours, move_timeband_start, move_timeband_end,
                   auto_reschedule, require_manual_if_uncertain
            FROM service_packages
            ORDER BY category, package_name
        ");
        $stmt->execute();
        $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'packages' => $packages]);

    } elseif ($action === 'save-service-weather-rules') {
        // Save weather rules for a service package
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception('POST method required');
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);

        if (!$id) {
            throw new Exception('Service package ID is required');
        }

        $stmt = $db->prepare("
            UPDATE service_packages
            SET weather_policy = ?,
                max_precip_chance_pct = ?,
                max_precip_mm_per_hr = ?,
                min_temp_c = ?,
                max_temp_c = ?,
                max_wind_kph = ?,
                move_window_hours = ?,
                move_timeband_start = ?,
                move_timeband_end = ?,
                auto_reschedule = ?,
                require_manual_if_uncertain = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $data['weather_policy'] ?? 'ANY',
            !empty($data['max_precip_chance_pct']) ? (int)$data['max_precip_chance_pct'] : null,
            !empty($data['max_precip_mm_per_hr']) ? (float)$data['max_precip_mm_per_hr'] : null,
            isset($data['min_temp_c']) && $data['min_temp_c'] !== '' ? (float)$data['min_temp_c'] : null,
            isset($data['max_temp_c']) && $data['max_temp_c'] !== '' ? (float)$data['max_temp_c'] : null,
            !empty($data['max_wind_kph']) ? (float)$data['max_wind_kph'] : null,
            !empty($data['move_window_hours']) ? (int)$data['move_window_hours'] : null,
            !empty($data['move_timeband_start']) ? $data['move_timeband_start'] : null,
            !empty($data['move_timeband_end']) ? $data['move_timeband_end'] : null,
            !empty($data['auto_reschedule']) ? 1 : 0,
            isset($data['require_manual_if_uncertain']) ? ((int)$data['require_manual_if_uncertain']) : 1,
            $id,
        ]);

        echo json_encode(['success' => true, 'message' => 'Weather rules saved']);

    } else {
        throw new Exception('Invalid action: ' . htmlspecialchars($action ?? ''));
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
