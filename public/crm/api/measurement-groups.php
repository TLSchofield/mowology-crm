<?php
/**
 * API: Measurement Groups CRUD
 *
 * Actions:
 *   list          (GET)  — returns all measurement groups
 *   update-label  (POST) — rename a group's label
 *   add           (POST) — add a new measurement group
 *   delete        (POST) — soft-delete (deactivate) a group
 *   reorder       (POST) — update sort order
 *   add-type      (POST) — add a measurement type to a group
 *   remove-type   (POST) — remove a measurement type from a group
 *
 * Returns JSON. Requires CRM login with products.edit permission.
 */

// Bootstrap
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';

requireLogin();
$user = getCurrentUser();
session_write_close();

header('Content-Type: application/json');

$action = $_GET['action'] ?? ($_POST['action'] ?? null);
$db = getDB();

try {

    if ($action === 'list') {
        // GET: Return all measurement groups with their types
        $rows = $db->query("
            SELECT id, group_key, group_label, measurement_types, unit, sort_order, is_active
            FROM measurement_groups
            ORDER BY sort_order ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Parse measurement_types into arrays
        foreach ($rows as &$row) {
            $row['types_array'] = array_filter(array_map('trim', explode(',', $row['measurement_types'])));
        }
        unset($row);

        echo json_encode(['success' => true, 'groups' => $rows]);

    } elseif ($action === 'update-label') {
        // POST: Rename a group's label
        $groupId = intval($_POST['group_id'] ?? 0);
        $newLabel = trim($_POST['group_label'] ?? '');

        if (!$groupId || !$newLabel) throw new Exception('Group ID and label are required');
        if (strlen($newLabel) > 100) throw new Exception('Label must be 100 characters or less');

        $stmt = $db->prepare("UPDATE measurement_groups SET group_label = ? WHERE id = ?");
        $stmt->execute([$newLabel, $groupId]);

        echo json_encode(['success' => true, 'message' => 'Group label updated']);

    } elseif ($action === 'add') {
        // POST: Add a new measurement group
        $groupKey   = trim($_POST['group_key'] ?? '');
        $groupLabel = trim($_POST['group_label'] ?? '');
        $unit       = trim($_POST['unit'] ?? 'sqft');
        $types      = trim($_POST['measurement_types'] ?? '');

        if (!$groupKey || !$groupLabel) throw new Exception('Group key and label are required');
        if (!in_array($unit, ['sqft', 'linear_ft'])) throw new Exception('Unit must be sqft or linear_ft');

        // Sanitize group_key: lowercase, underscores only
        $groupKey = preg_replace('/[^a-z0-9_]/', '_', strtolower($groupKey));
        if (strlen($groupKey) > 50) $groupKey = substr($groupKey, 0, 50);

        // Check for duplicate key
        $existing = $db->prepare("SELECT id FROM measurement_groups WHERE group_key = ?");
        $existing->execute([$groupKey]);
        if ($existing->fetch()) throw new Exception('A group with key "' . $groupKey . '" already exists');

        // Get next sort order
        $maxSort = $db->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM measurement_groups")->fetchColumn();

        $stmt = $db->prepare("
            INSERT INTO measurement_groups (group_key, group_label, measurement_types, unit, sort_order, is_active)
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([$groupKey, $groupLabel, $types, $unit, $maxSort]);

        // If measurement types were provided, ensure the ENUM accepts them
        if ($types) {
            $newTypes = array_filter(array_map('trim', explode(',', $types)));
            foreach ($newTypes as $newType) {
                ensureMeasurementTypeExists($db, $newType);
            }
        }

        echo json_encode([
            'success'  => true,
            'message'  => 'Group created',
            'group_id' => (int)$db->lastInsertId(),
        ]);

    } elseif ($action === 'delete') {
        // POST: Soft-delete (deactivate) a group
        $groupId = intval($_POST['group_id'] ?? 0);
        if (!$groupId) throw new Exception('Group ID is required');

        // Check if group has measurements
        $group = $db->prepare("SELECT group_key FROM measurement_groups WHERE id = ?");
        $group->execute([$groupId]);
        $group = $group->fetch(PDO::FETCH_ASSOC);
        if (!$group) throw new Exception('Group not found');

        $count = $db->prepare("SELECT COUNT(*) FROM property_measurements WHERE measurement_group_key = ?");
        $count->execute([$group['group_key']]);
        $measCount = (int)$count->fetchColumn();

        if ($measCount > 0) {
            // Soft-delete only — data still references this group
            $db->prepare("UPDATE measurement_groups SET is_active = 0 WHERE id = ?")->execute([$groupId]);
            echo json_encode(['success' => true, 'message' => 'Group deactivated (has ' . $measCount . ' existing measurements)']);
        } else {
            // Safe to hard-delete — no data references it
            $db->prepare("DELETE FROM measurement_groups WHERE id = ?")->execute([$groupId]);
            echo json_encode(['success' => true, 'message' => 'Group deleted']);
        }

    } elseif ($action === 'add-type') {
        // POST: Add a measurement type to a group
        $groupId = intval($_POST['group_id'] ?? 0);
        $newType = trim(strtolower($_POST['measurement_type'] ?? ''));

        if (!$groupId || !$newType) throw new Exception('Group ID and measurement type are required');
        $newType = preg_replace('/[^a-z0-9_]/', '_', $newType);

        // Get current types
        $stmt = $db->prepare("SELECT measurement_types FROM measurement_groups WHERE id = ?");
        $stmt->execute([$groupId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Group not found');

        $currentTypes = array_filter(array_map('trim', explode(',', $row['measurement_types'])));
        if (in_array($newType, $currentTypes)) throw new Exception('Type "' . $newType . '" already exists in this group');

        // Check that no other group claims this type
        $allGroups = $db->query("SELECT group_key, measurement_types FROM measurement_groups")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allGroups as $g) {
            $gTypes = array_map('trim', explode(',', $g['measurement_types']));
            if (in_array($newType, $gTypes)) {
                throw new Exception('Type "' . $newType . '" is already assigned to group "' . $g['group_key'] . '"');
            }
        }

        $currentTypes[] = $newType;
        $db->prepare("UPDATE measurement_groups SET measurement_types = ? WHERE id = ?")
            ->execute([implode(',', $currentTypes), $groupId]);

        // Ensure ENUM accepts this type
        ensureMeasurementTypeExists($db, $newType);

        echo json_encode(['success' => true, 'message' => 'Type added to group']);

    } elseif ($action === 'remove-type') {
        // POST: Remove a measurement type from a group
        $groupId = intval($_POST['group_id'] ?? 0);
        $removeType = trim(strtolower($_POST['measurement_type'] ?? ''));

        if (!$groupId || !$removeType) throw new Exception('Group ID and measurement type are required');

        $stmt = $db->prepare("SELECT measurement_types FROM measurement_groups WHERE id = ?");
        $stmt->execute([$groupId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Group not found');

        $currentTypes = array_filter(array_map('trim', explode(',', $row['measurement_types'])));
        $currentTypes = array_values(array_filter($currentTypes, function($t) use ($removeType) { return $t !== $removeType; }));

        $db->prepare("UPDATE measurement_groups SET measurement_types = ? WHERE id = ?")
            ->execute([implode(',', $currentTypes), $groupId]);

        echo json_encode(['success' => true, 'message' => 'Type removed from group']);

    } else {
        throw new Exception('Invalid action: ' . htmlspecialchars($action ?? ''));
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}


/**
 * Ensure a measurement type value exists in the property_measurements.measurement_type column.
 * If it's an ENUM, alter it. If it's already VARCHAR, no-op.
 */
function ensureMeasurementTypeExists(PDO $db, string $type): void {
    try {
        // Check column type
        $col = $db->query("SHOW COLUMNS FROM property_measurements LIKE 'measurement_type'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) return;

        $colType = $col['Type'];

        // If it's already VARCHAR, nothing to do
        if (stripos($colType, 'varchar') !== false) return;

        // If it's an ENUM, check if value exists
        if (stripos($colType, 'enum') !== false) {
            // Parse existing values from enum('val1','val2',...)
            preg_match_all("/'([^']+)'/", $colType, $matches);
            $existing = $matches[1] ?? [];

            if (!in_array($type, $existing)) {
                // Add the new value to the ENUM
                $existing[] = $type;
                $enumStr = "'" . implode("','", $existing) . "'";
                $db->exec("ALTER TABLE property_measurements MODIFY COLUMN measurement_type ENUM({$enumStr}) NOT NULL DEFAULT 'other'");
            }
        }
    } catch (Exception $e) {
        error_log("ensureMeasurementTypeExists error: " . $e->getMessage());
    }
}
