<?php
/**
 * Unified Tag API
 *
 * GET  ?action=list&entity_type=property&entity_id=X  → tags on an entity
 * GET  ?action=available&groups=property_access,property_warning → available tags by group
 * GET  ?action=admin_list                              → all tags with usage counts (admin)
 * POST action=apply   → apply tag to entity (+ optional value)
 * POST action=remove  → remove tag from entity
 * POST action=create  → create new tag (admin)
 * POST action=update  → update tag (admin)
 * POST action=delete  → soft-delete tag (admin)
 *
 * @package Mowology CRM
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();

header('Content-Type: application/json');

$db = getDB();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// ═══════════════════════════════════════════════════════
//  GET — List tags on an entity
// ═══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
    $entityType = $_GET['entity_type'] ?? '';
    $entityId = (int)($_GET['entity_id'] ?? 0);

    if (!$entityType || !$entityId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'entity_type and entity_id required']);
        exit;
    }

    $stmt = $db->prepare("
        SELECT et.id AS entity_tag_id, et.tag_value,
               t.id AS tag_id, t.tag_key, t.tag_label, t.tag_group,
               t.tag_color, t.icon, t.show_on_card, t.has_value
        FROM entity_tags et
        JOIN tags t ON t.id = et.tag_id
        WHERE et.entity_type = ? AND et.entity_id = ? AND t.is_active = 1
        ORDER BY t.sort_order ASC, t.tag_label ASC
    ");
    $stmt->execute([$entityType, $entityId]);
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'tags' => $tags]);
    exit;
}

// ═══════════════════════════════════════════════════════
//  GET — List available tags by group(s)
// ═══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'available') {
    $groupsStr = $_GET['groups'] ?? '';
    $groups = array_filter(array_map('trim', explode(',', $groupsStr)));

    if (empty($groups)) {
        // Return all active tags
        $stmt = $db->query("
            SELECT id AS tag_id, tag_key, tag_label, tag_group,
                   tag_color, icon, show_on_card, has_value, sort_order
            FROM tags WHERE is_active = 1
            ORDER BY tag_group, sort_order ASC, tag_label ASC
        ");
    } else {
        $placeholders = implode(',', array_fill(0, count($groups), '?'));
        $stmt = $db->prepare("
            SELECT id AS tag_id, tag_key, tag_label, tag_group,
                   tag_color, icon, show_on_card, has_value, sort_order
            FROM tags WHERE is_active = 1 AND tag_group IN ({$placeholders})
            ORDER BY tag_group, sort_order ASC, tag_label ASC
        ");
        $stmt->execute($groups);
    }

    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'tags' => $tags]);
    exit;
}

// ═══════════════════════════════════════════════════════
//  GET — Admin list (all tags + usage counts)
// ═══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'admin_list') {
    if (function_exists('requirePermission')) {
        requirePermission('settings.edit');
    }

    $stmt = $db->query("
        SELECT t.id, t.tag_key, t.tag_label, t.tag_group,
               t.tag_color, t.icon, t.show_on_card, t.has_value,
               t.sort_order, t.is_active, t.created_at,
               COUNT(et.id) AS usage_count
        FROM tags t
        LEFT JOIN entity_tags et ON et.tag_id = t.id
        GROUP BY t.id
        ORDER BY t.tag_group ASC, t.sort_order ASC, t.tag_label ASC
    ");
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by tag_group
    $grouped = [];
    foreach ($tags as $tag) {
        $grouped[$tag['tag_group']][] = $tag;
    }

    echo json_encode(['success' => true, 'tags' => $tags, 'grouped' => $grouped]);
    exit;
}

// ═══════════════════════════════════════════════════════
//  POST — Apply tag to entity
// ═══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = json_decode(file_get_contents('php://input'), true);
    if (!$json) {
        $json = $_POST; // Fallback to form data
    }

    $csrfToken = $json['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $postAction = $json['action'] ?? '';

    // ── Apply tag ─────────────────────────────────────
    if ($postAction === 'apply') {
        $entityType = $json['entity_type'] ?? '';
        $entityId = (int)($json['entity_id'] ?? 0);
        $tagId = (int)($json['tag_id'] ?? 0);
        $tagValue = isset($json['tag_value']) ? trim($json['tag_value']) : null;

        if (!$entityType || !$entityId || !$tagId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'entity_type, entity_id, and tag_id required']);
            exit;
        }

        // Verify tag exists and is active
        $tagStmt = $db->prepare("SELECT id, has_value FROM tags WHERE id = ? AND is_active = 1");
        $tagStmt->execute([$tagId]);
        $tag = $tagStmt->fetch(PDO::FETCH_ASSOC);
        if (!$tag) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Tag not found or inactive']);
            exit;
        }

        // Clear value if tag doesn't support it
        if (!$tag['has_value']) {
            $tagValue = null;
        }

        // Insert or update (UNIQUE constraint on entity_type+entity_id+tag_id)
        $stmt = $db->prepare("
            INSERT INTO entity_tags (entity_type, entity_id, tag_id, tag_value, tagged_by)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE tag_value = VALUES(tag_value), tagged_by = VALUES(tagged_by)
        ");
        $stmt->execute([$entityType, $entityId, $tagId, $tagValue, (int)$user['id']]);

        $entityTagId = $db->lastInsertId();

        echo json_encode(['success' => true, 'entity_tag_id' => (int)$entityTagId]);
        exit;
    }

    // ── Remove tag ────────────────────────────────────
    if ($postAction === 'remove') {
        $entityTagId = (int)($json['entity_tag_id'] ?? 0);

        // Also support removal by entity+tag combo
        $entityType = $json['entity_type'] ?? '';
        $entityId = (int)($json['entity_id'] ?? 0);
        $tagId = (int)($json['tag_id'] ?? 0);

        if ($entityTagId) {
            $stmt = $db->prepare("DELETE FROM entity_tags WHERE id = ?");
            $stmt->execute([$entityTagId]);
        } elseif ($entityType && $entityId && $tagId) {
            $stmt = $db->prepare("DELETE FROM entity_tags WHERE entity_type = ? AND entity_id = ? AND tag_id = ?");
            $stmt->execute([$entityType, $entityId, $tagId]);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'entity_tag_id or (entity_type + entity_id + tag_id) required']);
            exit;
        }

        echo json_encode(['success' => true, 'removed' => $stmt->rowCount()]);
        exit;
    }

    // ── Create tag (admin) ─────────────────────────────
    if ($postAction === 'create') {
        if (function_exists('requirePermission')) {
            requirePermission('settings.edit');
        }

        $tagKey   = trim($json['tag_key'] ?? '');
        $tagLabel = trim($json['tag_label'] ?? '');
        $tagGroup = trim($json['tag_group'] ?? '');
        $tagColor = trim($json['tag_color'] ?? '#6B7280');
        $icon     = trim($json['icon'] ?? '') ?: null;
        $showOnCard = (int)($json['show_on_card'] ?? 0);
        $hasValue   = (int)($json['has_value'] ?? 0);

        if (!$tagKey || !$tagLabel || !$tagGroup) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'tag_key, tag_label, and tag_group are required']);
            exit;
        }

        // Validate tag_key format (lowercase, underscores, no spaces)
        if (!preg_match('/^[a-z0-9_]+$/', $tagKey)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'tag_key must be lowercase letters, numbers, and underscores only']);
            exit;
        }

        // Check for duplicate key
        $dup = $db->prepare("SELECT id FROM tags WHERE tag_key = ?");
        $dup->execute([$tagKey]);
        if ($dup->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'A tag with key "' . $tagKey . '" already exists']);
            exit;
        }

        // Get next sort_order for this group
        $orderStmt = $db->prepare("SELECT MAX(sort_order) AS max_order FROM tags WHERE tag_group = ?");
        $orderStmt->execute([$tagGroup]);
        $maxOrder = (int)($orderStmt->fetch(PDO::FETCH_ASSOC)['max_order'] ?? 0);

        $stmt = $db->prepare("
            INSERT INTO tags (tag_key, tag_label, tag_group, tag_color, icon, show_on_card, has_value, sort_order, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([$tagKey, $tagLabel, $tagGroup, $tagColor, $icon, $showOnCard, $hasValue, $maxOrder + 1]);

        echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);
        exit;
    }

    // ── Update tag (admin) ──────────────────────────────
    if ($postAction === 'update') {
        if (function_exists('requirePermission')) {
            requirePermission('settings.edit');
        }

        $tagId    = (int)($json['id'] ?? 0);
        $tagLabel = trim($json['tag_label'] ?? '');
        $tagGroup = trim($json['tag_group'] ?? '');
        $tagColor = trim($json['tag_color'] ?? '#6B7280');
        $icon     = trim($json['icon'] ?? '') ?: null;
        $showOnCard = (int)($json['show_on_card'] ?? 0);
        $hasValue   = (int)($json['has_value'] ?? 0);

        if (!$tagId || !$tagLabel || !$tagGroup) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'id, tag_label, and tag_group are required']);
            exit;
        }

        $stmt = $db->prepare("
            UPDATE tags SET tag_label = ?, tag_group = ?, tag_color = ?, icon = ?,
                            show_on_card = ?, has_value = ?
            WHERE id = ?
        ");
        $stmt->execute([$tagLabel, $tagGroup, $tagColor, $icon, $showOnCard, $hasValue, $tagId]);

        echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
        exit;
    }

    // ── Delete tag (soft-delete, admin) ─────────────────
    if ($postAction === 'delete') {
        if (function_exists('requirePermission')) {
            requirePermission('settings.edit');
        }

        $tagId = (int)($json['id'] ?? 0);
        if (!$tagId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'id is required']);
            exit;
        }

        // Get usage count for response
        $countStmt = $db->prepare("SELECT COUNT(*) AS cnt FROM entity_tags WHERE tag_id = ?");
        $countStmt->execute([$tagId]);
        $usageCount = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['cnt'];

        $stmt = $db->prepare("UPDATE tags SET is_active = 0 WHERE id = ?");
        $stmt->execute([$tagId]);

        echo json_encode(['success' => true, 'deactivated' => $stmt->rowCount(), 'usage_count' => $usageCount]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $postAction]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
