<?php
/**
 * API: Service Types CRUD
 * /app/Modules/Settings/Api/service-types.php
 *
 * Actions:
 *   list     (GET)  — all service types ordered by sort_order
 *   save     (POST) — insert (id=0) or update; validates slug uniqueness
 *   delete   (POST) — soft-delete if slug used in job_plans, hard-delete otherwise
 *   reorder  (POST) — update sort_order for multiple records
 *
 * Admin only. CSRF required on writes.
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

if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? null;
$db     = getDB();

// ── Auto-create table if migration hasn't run yet ─────────────────────────────
try {
    $db->query("SELECT 1 FROM service_types LIMIT 1");
} catch (PDOException $e) {
    // Table doesn't exist — create it now (matches migration 506)
    $db->exec("
        CREATE TABLE IF NOT EXISTS `service_types` (
            `id`              int NOT NULL AUTO_INCREMENT,
            `slug`            varchar(100) NOT NULL,
            `label`           varchar(100) NOT NULL,
            `color`           varchar(7) NOT NULL DEFAULT '#455A64',
            `icon`            varchar(50) NULL,
            `is_active`       tinyint(1) NOT NULL DEFAULT 1,
            `show_in_jobflow` tinyint(1) NOT NULL DEFAULT 0,
            `sort_order`      int NOT NULL DEFAULT 0,
            `created_at`      timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`      timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_st_slug` (`slug`),
            KEY `idx_st_active` (`is_active`),
            KEY `idx_st_sort` (`sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // Seed default types
    $db->exec("
        INSERT IGNORE INTO `service_types` (slug, label, color, icon, is_active, show_in_jobflow, sort_order) VALUES
        ('lawn_care',          'Lawn Care',          '#2E7D32', 'scissors',   1, 1, 10),
        ('maintenance',        'Maintenance',         '#2D8659', 'tool',       1, 1, 20),
        ('cleanup',            'Cleanup',             '#455A64', 'trash-2',    1, 1, 30),
        ('snow_removal',       'Snow Removal',        '#1565C0', 'cloud-snow', 1, 1, 40),
        ('hedge_trimming',     'Hedge Trimming',      '#6A1B9A', 'git-branch', 1, 1, 50),
        ('landscaping',        'Landscaping',         '#2D8659', 'map',        1, 0, 60),
        ('garden_maintenance', 'Garden Maintenance',  '#EF6C00', 'feather',    1, 0, 70),
        ('seasonal_cleanup',   'Seasonal Cleanup',    '#455A64', 'wind',       1, 0, 80)
    ");
}

try {

    if ($action === 'list') {
        $rows = $db->query("
            SELECT id, slug, label, color, icon, is_active, show_in_jobflow, sort_order
            FROM service_types
            ORDER BY sort_order ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Cast ints
        foreach ($rows as &$r) {
            $r['id']              = (int)$r['id'];
            $r['is_active']       = (int)$r['is_active'];
            $r['show_in_jobflow'] = (int)$r['show_in_jobflow'];
            $r['sort_order']      = (int)$r['sort_order'];
        }
        unset($r);

        echo json_encode(['success' => true, 'service_types' => $rows]);

    } elseif ($action === 'save') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST required');

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid security token']);
            exit;
        }

        $id            = (int)($input['id'] ?? 0);
        $label         = trim($input['label'] ?? '');
        $slug          = trim(strtolower($input['slug'] ?? ''));
        $color         = trim($input['color'] ?? '#455A64');
        $icon          = trim($input['icon'] ?? '');
        $isActive      = isset($input['is_active']) ? (int)(bool)$input['is_active'] : 1;
        $showInJobflow = isset($input['show_in_jobflow']) ? (int)(bool)$input['show_in_jobflow'] : 0;
        $sortOrder     = isset($input['sort_order']) ? (int)$input['sort_order'] : 0;

        if (!$label) throw new Exception('Label is required');

        // Auto-generate slug from label if not provided
        if (!$slug && $label) {
            $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($label));
            $slug = trim($slug, '_');
        }
        // Sanitize slug
        $slug = preg_replace('/[^a-z0-9_]/', '_', $slug);
        $slug = substr($slug, 0, 100);

        if (!$slug) throw new Exception('Slug is required');

        // Validate color
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#455A64';
        }

        // Check slug uniqueness (exclude self on update)
        $dupCheck = $db->prepare("SELECT id FROM service_types WHERE slug = ? AND id != ?");
        $dupCheck->execute([$slug, $id]);
        if ($dupCheck->fetch()) {
            throw new Exception('A service type with slug "' . htmlspecialchars($slug) . '" already exists');
        }

        if ($id > 0) {
            $stmt = $db->prepare("
                UPDATE service_types
                SET label = ?, slug = ?, color = ?, icon = ?, is_active = ?, show_in_jobflow = ?, sort_order = ?
                WHERE id = ?
            ");
            $stmt->execute([$label, $slug, $color, $icon ?: null, $isActive, $showInJobflow, $sortOrder, $id]);
            echo json_encode(['success' => true, 'message' => 'Service type updated', 'id' => $id]);
        } else {
            // Auto sort_order: append at end
            if (!$sortOrder) {
                $sortOrder = (int)$db->query("SELECT COALESCE(MAX(sort_order), 0) + 10 FROM service_types")->fetchColumn();
            }
            $stmt = $db->prepare("
                INSERT INTO service_types (slug, label, color, icon, is_active, show_in_jobflow, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$slug, $label, $color, $icon ?: null, $isActive, $showInJobflow, $sortOrder]);
            $newId = (int)$db->lastInsertId();
            echo json_encode(['success' => true, 'message' => 'Service type created', 'id' => $newId]);
        }

    } elseif ($action === 'delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST required');

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid security token']);
            exit;
        }

        $id = (int)($input['id'] ?? 0);
        if (!$id) throw new Exception('ID is required');

        // Get slug
        $row = $db->prepare("SELECT slug FROM service_types WHERE id = ?");
        $row->execute([$id]);
        $row = $row->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Service type not found');

        // Check if slug is used in job_plans
        $used = 0;
        try {
            $usedStmt = $db->prepare("SELECT COUNT(*) FROM job_plans WHERE service_type = ?");
            $usedStmt->execute([$row['slug']]);
            $used = (int)$usedStmt->fetchColumn();
        } catch (PDOException $e) {
            // job_plans may not exist in some environments
        }

        if ($used > 0) {
            // Soft-delete — data references it
            $db->prepare("UPDATE service_types SET is_active = 0 WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Service type deactivated (used in ' . $used . ' plan(s))']);
        } else {
            $db->prepare("DELETE FROM service_types WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Service type deleted']);
        }

    } elseif ($action === 'reorder') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST required');

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid security token']);
            exit;
        }

        $items = $input['items'] ?? [];
        if (!is_array($items)) throw new Exception('items array required');

        $stmt = $db->prepare("UPDATE service_types SET sort_order = ? WHERE id = ?");
        foreach ($items as $item) {
            $stmt->execute([(int)($item['sort_order'] ?? 0), (int)($item['id'] ?? 0)]);
        }

        echo json_encode(['success' => true, 'message' => 'Order saved']);

    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action ?? '')]);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
