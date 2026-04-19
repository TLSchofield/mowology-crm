<?php
/**
 * Admin Upsells API — manage product_upsells records.
 *
 * Actions (POST):
 *   create — insert new upsell relationship
 *   update — update bundled_price / is_popular / is_active / display_text / type
 *   delete — remove an upsell relationship
 *
 * Admin only.
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

header('Content-Type: application/json; charset=utf-8');

session_write_close();
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin only']);
    exit;
}

$action = $_GET['action'] ?? '';
$db = getDB();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('POST required');
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    switch ($action) {

        case 'create':
            $baseId   = (int)($input['base_product_id'] ?? 0);
            $upsellId = (int)($input['upsell_product_id'] ?? 0);
            $type     = $input['type'] ?? 'addon';
            $text     = trim($input['display_text'] ?? '');
            $bundled  = isset($input['bundled_price']) && $input['bundled_price'] !== null
                ? (float)$input['bundled_price'] : null;
            $popular  = !empty($input['is_popular']) ? 1 : 0;

            if (!$baseId || !$upsellId) throw new Exception('base_product_id and upsell_product_id required');
            if ($baseId === $upsellId) throw new Exception('Base and upsell must be different products');
            if (!in_array($type, ['addon', 'recommended', 'upgrade'])) $type = 'addon';

            // Check uniqueness
            $exists = $db->prepare("SELECT id FROM product_upsells WHERE base_product_id = ? AND upsell_product_id = ?");
            $exists->execute([$baseId, $upsellId]);
            if ($exists->fetch()) throw new Exception('This base→upsell relationship already exists');

            $db->prepare("
                INSERT INTO product_upsells
                    (base_product_id, upsell_product_id, type, display_text, bundled_price, is_popular, is_active, sort_order, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 1, 0, NOW())
            ")->execute([$baseId, $upsellId, $type, $text ?: null, $bundled, $popular]);

            echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);
            break;

        case 'update':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('id required');

            $updates = [];
            $params  = [];

            if (array_key_exists('bundled_price', $input)) {
                $updates[] = 'bundled_price = ?';
                $params[]  = $input['bundled_price'] === null || $input['bundled_price'] === ''
                    ? null : (float)$input['bundled_price'];
            }
            if (array_key_exists('is_popular', $input)) {
                $updates[] = 'is_popular = ?';
                $params[]  = !empty($input['is_popular']) ? 1 : 0;
            }
            if (array_key_exists('is_active', $input)) {
                $updates[] = 'is_active = ?';
                $params[]  = !empty($input['is_active']) ? 1 : 0;
            }
            if (array_key_exists('display_text', $input)) {
                $updates[] = 'display_text = ?';
                $params[]  = trim($input['display_text']) ?: null;
            }
            if (array_key_exists('type', $input)) {
                $type = in_array($input['type'], ['addon', 'recommended', 'upgrade']) ? $input['type'] : 'addon';
                $updates[] = 'type = ?';
                $params[]  = $type;
            }

            if (empty($updates)) throw new Exception('No fields to update');

            $params[] = $id;
            $stmt = $db->prepare("UPDATE product_upsells SET " . implode(', ', $updates) . " WHERE id = ?");
            $stmt->execute($params);

            echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
            break;

        case 'delete':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new Exception('id required');

            $stmt = $db->prepare("DELETE FROM product_upsells WHERE id = ?");
            $stmt->execute([$id]);

            echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
            break;

        default:
            throw new Exception('Unknown action: ' . $action);
    }

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
