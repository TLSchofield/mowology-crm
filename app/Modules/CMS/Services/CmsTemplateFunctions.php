<?php
/**
 * CMS Template Library Functions
 *
 * Manage page templates, block templates, presets, and template-based page creation.
 * Enables rapid page creation from stored, reusable templates with adjustable blocks.
 *
 * @package Mowology
 * @subpackage CMS
 *
 * Migrated from: public/crm/includes/cms-template-functions.php
 */

declare(strict_types=1);

// ============================================================================
// PAGE TEMPLATE OPERATIONS
// ============================================================================

/**
 * Get all active page templates
 *
 * @param string|null $category Filter by category (landing|service|product)
 * @param bool $onlyFeatured Show only featured templates
 * @return array Array of template records
 */
function cms_getPageTemplates(?string $category = null, bool $onlyFeatured = false): array
{
    $db = getDB();

    $sql = "
        SELECT id, template_key, label, description, layout_template, page_type,
               category, use_case, version, preview_image_path, usage_count,
               last_used_at, is_locked
        FROM cms_page_templates
        WHERE is_active = 1
    ";

    $params = [];

    if ($category) {
        $sql .= " AND category = ?";
        $params[] = $category;
    }

    $sql .= " ORDER BY usage_count DESC, label ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Get a page template by key
 *
 * @param string $templateKey Template key
 * @return array|null Template record with blocks_config decoded
 */
function cms_getPageTemplate(string $templateKey): ?array
{
    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, template_key, label, description, layout_template, page_type,
               category, use_case, version, blocks_config_json, page_metadata_defaults_json,
               preview_image_path, usage_count, is_locked
        FROM cms_page_templates
        WHERE template_key = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$templateKey]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($template) {
        $template['blocks_config'] = json_decode($template['blocks_config_json'] ?? '[]', true);
        $template['page_metadata_defaults'] = json_decode($template['page_metadata_defaults_json'] ?? '{}', true);
    }

    return $template ?: null;
}

/**
 * Get a page template by ID
 *
 * @param int $templateId Template ID
 * @return array|null Template record
 */
function cms_getPageTemplateById(int $templateId): ?array
{
    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, template_key, label, description, layout_template, page_type,
               category, use_case, version, blocks_config_json, page_metadata_defaults_json,
               preview_image_path, usage_count, is_locked
        FROM cms_page_templates
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$templateId]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($template) {
        $template['blocks_config'] = json_decode($template['blocks_config_json'] ?? '[]', true);
        $template['page_metadata_defaults'] = json_decode($template['page_metadata_defaults_json'] ?? '{}', true);
    }

    return $template ?: null;
}

/**
 * Create or update a page template
 *
 * @param array $data Template data
 * @param int $userId User ID
 * @param int|null $templateId If provided, update; otherwise create
 * @return int Template ID
 */
function cms_savePageTemplate(array $data, int $userId, ?int $templateId = null): int
{
    $db = getDB();

    $templateKey = $data['template_key'] ?? '';
    if (!$templateKey || !preg_match('/^[a-z0-9_]+$/', $templateKey)) {
        throw new Exception('Invalid template key (lowercase alphanumeric + underscore only)');
    }

    // Validate blocks config
    $blocksConfig = $data['blocks_config'] ?? [];
    if (!is_array($blocksConfig)) {
        throw new Exception('blocks_config must be array');
    }

    // Validate each block
    foreach ($blocksConfig as $block) {
        if (empty($block['type'])) {
            throw new Exception('Block type required');
        }
        if (!isset($block['position']) || $block['position'] < 0) {
            throw new Exception('Block position required (0+)');
        }
    }

    $blocksJson = json_encode($blocksConfig, JSON_UNESCAPED_SLASHES);
    $metadataJson = json_encode($data['page_metadata_defaults'] ?? [], JSON_UNESCAPED_SLASHES);

    if ($templateId) {
        // Update
        // If not locked, create version snapshot first
        $existing = cms_getPageTemplateById($templateId);
        if (!$existing['is_locked']) {
            cms_createPageTemplateVersion($templateId, $existing['version'], $existing['blocks_config_json'], $data['change_summary'] ?? 'Updated');
        }

        $newVersion = ($existing['version'] ?? 1) + 1;

        $stmt = $db->prepare("
            UPDATE cms_page_templates
            SET label = ?, description = ?, layout_template = ?,
                page_type = ?, category = ?, use_case = ?,
                blocks_config_json = ?, page_metadata_defaults_json = ?,
                version = ?, updated_by = ?, updated_at = NOW()
            WHERE id = ? AND is_locked = 0
        ");

        if (!$stmt->execute([
            $data['label'] ?? '',
            $data['description'] ?? null,
            $data['layout_template'] ?? 'default',
            $data['page_type'] ?? 'custom',
            $data['category'] ?? null,
            $data['use_case'] ?? null,
            $blocksJson,
            $metadataJson,
            $newVersion,
            $userId,
            $templateId,
        ])) {
            throw new Exception('Cannot update locked template');
        }

        return $templateId;
    } else {
        // Create
        $stmt = $db->prepare("
            INSERT INTO cms_page_templates
            (template_key, label, description, layout_template, page_type, category, use_case,
             blocks_config_json, page_metadata_defaults_json, version, is_locked, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, NOW())
        ");

        $stmt->execute([
            $templateKey,
            $data['label'] ?? '',
            $data['description'] ?? null,
            $data['layout_template'] ?? 'default',
            $data['page_type'] ?? 'custom',
            $data['category'] ?? null,
            $data['use_case'] ?? null,
            $blocksJson,
            $metadataJson,
            $userId,
        ]);

        return (int)$db->lastInsertId();
    }
}

/**
 * Create a page template version snapshot
 *
 * @param int $templateId Template ID
 * @param int $previousVersion Previous version number
 * @param string $blocksJson JSON snapshot
 * @param string $changeSummary What changed
 * @return int Version ID
 */
function cms_createPageTemplateVersion(int $templateId, int $previousVersion, string $blocksJson, string $changeSummary): int
{
    $db = getDB();

    $stmt = $db->prepare("
        INSERT INTO cms_page_template_versions
        (page_template_id, version, blocks_config_json, change_summary, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");

    $stmt->execute([
        $templateId,
        $previousVersion,
        $blocksJson,
        $changeSummary,
        getCurrentUser()['id'] ?? 1,
    ]);

    return (int)$db->lastInsertId();
}

/**
 * Get page template versions (history)
 *
 * @param int $templateId Template ID
 * @return array Array of version records
 */
function cms_getPageTemplateVersions(int $templateId): array
{
    $db = getDB();
    $stmt = $db->prepare("
        SELECT version, change_summary, created_by, created_at
        FROM cms_page_template_versions
        WHERE page_template_id = ?
        ORDER BY version DESC
    ");
    $stmt->execute([$templateId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// ============================================================================
// BLOCK TEMPLATE OPERATIONS
// ============================================================================

/**
 * Get all block templates
 *
 * @param string|null $blockType Filter by block type (hero, feature_grid, etc.)
 * @param string|null $category Filter by category (hero|proof|cta)
 * @return array Array of block template records
 */
function cms_getBlockTemplates(?string $blockType = null, ?string $category = null): array
{
    $db = getDB();

    $sql = "
        SELECT id, block_template_key, label, description, block_type, category,
               version, preview_image_path, usage_count, is_locked
        FROM cms_block_templates
        WHERE is_active = 1
    ";

    $params = [];

    if ($blockType) {
        $sql .= " AND block_type = ?";
        $params[] = $blockType;
    }

    if ($category) {
        $sql .= " AND category = ?";
        $params[] = $category;
    }

    $sql .= " ORDER BY usage_count DESC, label ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Get a block template by key
 *
 * @param string $templateKey Block template key
 * @return array|null Template record with config decoded
 */
function cms_getBlockTemplate(string $templateKey): ?array
{
    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, block_template_key, label, description, block_type, category,
               default_config_json, editable_fields_json, locked_fields_json,
               version, preview_image_path, usage_count, is_locked,
               performance_data_json
        FROM cms_block_templates
        WHERE block_template_key = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$templateKey]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($template) {
        $template['default_config'] = json_decode($template['default_config_json'] ?? '{}', true);
        $template['editable_fields'] = json_decode($template['editable_fields_json'] ?? '[]', true);
        $template['locked_fields'] = json_decode($template['locked_fields_json'] ?? '[]', true);
        $template['performance_data'] = json_decode($template['performance_data_json'] ?? 'null', true);
    }

    return $template ?: null;
}

/**
 * Get block template by ID
 *
 * @param int $templateId Block template ID
 * @return array|null Template record
 */
function cms_getBlockTemplateById(int $templateId): ?array
{
    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, block_template_key, label, description, block_type, category,
               default_config_json, editable_fields_json, locked_fields_json,
               version, preview_image_path, is_locked
        FROM cms_block_templates
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$templateId]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($template) {
        $template['default_config'] = json_decode($template['default_config_json'] ?? '{}', true);
        $template['editable_fields'] = json_decode($template['editable_fields_json'] ?? '[]', true);
        $template['locked_fields'] = json_decode($template['locked_fields_json'] ?? '[]', true);
    }

    return $template ?: null;
}

/**
 * Create or update a block template
 *
 * @param array $data Block template data
 * @param int $userId User ID
 * @param int|null $templateId If provided, update; otherwise create
 * @return int Block template ID
 */
function cms_saveBlockTemplate(array $data, int $userId, ?int $templateId = null): int
{
    $db = getDB();

    $templateKey = $data['block_template_key'] ?? '';
    if (!$templateKey || !preg_match('/^[a-z0-9_]+$/', $templateKey)) {
        throw new Exception('Invalid block template key');
    }

    // Validate block type exists
    $typeStmt = $db->prepare("SELECT id FROM cms_block_types WHERE block_type = ? LIMIT 1");
    $typeStmt->execute([$data['block_type'] ?? '']);
    if (!$typeStmt->fetch()) {
        throw new Exception('Invalid block type: ' . ($data['block_type'] ?? ''));
    }

    $defaultConfigJson = json_encode($data['default_config'] ?? [], JSON_UNESCAPED_SLASHES);
    $editableFieldsJson = json_encode($data['editable_fields'] ?? [], JSON_UNESCAPED_SLASHES);
    $lockedFieldsJson = json_encode($data['locked_fields'] ?? [], JSON_UNESCAPED_SLASHES);

    if ($templateId) {
        // Update
        $existing = cms_getBlockTemplateById($templateId);
        if ($existing && !$existing['is_locked']) {
            cms_createBlockTemplateVersion($templateId, $existing['version'], $existing['default_config_json'], $data['change_summary'] ?? 'Updated');
        }

        $newVersion = ($existing['version'] ?? 1) + 1;

        $stmt = $db->prepare("
            UPDATE cms_block_templates
            SET label = ?, description = ?, block_type = ?, category = ?,
                default_config_json = ?, editable_fields_json = ?, locked_fields_json = ?,
                version = ?, updated_by = ?, updated_at = NOW()
            WHERE id = ? AND is_locked = 0
        ");

        $stmt->execute([
            $data['label'] ?? '',
            $data['description'] ?? null,
            $data['block_type'],
            $data['category'] ?? null,
            $defaultConfigJson,
            $editableFieldsJson,
            $lockedFieldsJson,
            $newVersion,
            $userId,
            $templateId,
        ]);

        return $templateId;
    } else {
        // Create
        $stmt = $db->prepare("
            INSERT INTO cms_block_templates
            (block_template_key, label, description, block_type, category,
             default_config_json, editable_fields_json, locked_fields_json, version, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())
        ");

        $stmt->execute([
            $templateKey,
            $data['label'] ?? '',
            $data['description'] ?? null,
            $data['block_type'],
            $data['category'] ?? null,
            $defaultConfigJson,
            $editableFieldsJson,
            $lockedFieldsJson,
            $userId,
        ]);

        return (int)$db->lastInsertId();
    }
}

/**
 * Create a block template version snapshot
 *
 * @param int $templateId Block template ID
 * @param int $previousVersion Previous version
 * @param string $configJson JSON snapshot
 * @param string $changeSummary What changed
 * @return int Version ID
 */
function cms_createBlockTemplateVersion(int $templateId, int $previousVersion, string $configJson, string $changeSummary): int
{
    $db = getDB();

    $stmt = $db->prepare("
        INSERT INTO cms_block_template_versions
        (block_template_id, version, default_config_json, change_summary, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");

    $stmt->execute([
        $templateId,
        $previousVersion,
        $configJson,
        $changeSummary,
        getCurrentUser()['id'] ?? 1,
    ]);

    return (int)$db->lastInsertId();
}

// ============================================================================
// PAGE CREATION FROM TEMPLATE
// ============================================================================

/**
 * Create a new page from a template
 *
 * @param string $pageTemplateKey Template key to use
 * @param array $pageOverrides Override values: {slug, title, meta_description, etc.}
 * @param array $blockCustomizations Override block configs: {block_index: {field: value}}
 * @param int $userId User creating page
 * @return int Page ID
 */
function cms_createPageFromTemplate(
    string $pageTemplateKey,
    array $pageOverrides,
    array $blockCustomizations = [],
    int $userId = 0
): int {
    $db = getDB();

    // Get template
    $template = cms_getPageTemplate($pageTemplateKey);
    if (!$template) {
        throw new Exception("Template not found: {$pageTemplateKey}");
    }

    // Merge page metadata with template defaults
    $pageData = array_merge($template['page_metadata_defaults'], $pageOverrides);

    // Required fields
    if (empty($pageData['slug'])) {
        throw new Exception('Page slug required');
    }
    if (empty($pageData['title'])) {
        throw new Exception('Page title required');
    }

    // Set defaults from template
    $pageData['layout_template'] = $template['layout_template'];
    $pageData['page_type'] = $template['page_type'];
    $pageData['status'] = 'draft'; // Always create as draft

    // Create page
    $pageId = cms_savePage($pageData, null, $userId);

    // Create blocks from template
    $blocksConfig = $template['blocks_config'];

    foreach ($blocksConfig as $blockIndex => $blockTemplate) {
        $blockType = $blockTemplate['type'];
        $position = $blockTemplate['position'];

        // Get block template if exists
        $blockTemplate = cms_getBlockTemplate($blockTemplate['template_key'] ?? '') ?: $blockTemplate;

        // Start with default config
        $blockConfig = $blockTemplate['default_config'] ?? $blockTemplate['config'] ?? [];

        // Apply customizations (only editable fields)
        $editableFields = $blockTemplate['editable_fields'] ?? [];
        if (isset($blockCustomizations[$blockIndex])) {
            foreach ($blockCustomizations[$blockIndex] as $field => $value) {
                // Only allow customization of editable fields
                if (empty($editableFields) || in_array($field, $editableFields)) {
                    $blockConfig[$field] = $value;
                }
            }
        }

        // Create block
        cms_saveBlock($pageId, $blockType, $position, $blockConfig);
    }

    // Create audit record
    $blockTemplateIds = array_map(fn($b) => $b['block_template_id'] ?? null, $blocksConfig);
    $auditStmt = $db->prepare("
        INSERT INTO cms_pages_template_audit
        (page_id, page_template_id, block_template_ids_json, template_version_used, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $auditStmt->execute([
        $pageId,
        $template['id'],
        json_encode(array_filter($blockTemplateIds)),
        $template['version'],
        $userId,
    ]);

    // Increment template usage
    $db->prepare("
        UPDATE cms_page_templates
        SET usage_count = usage_count + 1, last_used_at = NOW()
        WHERE id = ?
    ")->execute([$template['id']]);

    return $pageId;
}

// ============================================================================
// TEMPLATE PRESETS (Save & reuse customizations)
// ============================================================================

/**
 * Create a template preset (saved customization)
 *
 * @param array $data Preset data
 * @param int $userId User ID
 * @return int Preset ID
 */
function cms_createTemplatePreset(array $data, int $userId): int
{
    $db = getDB();

    $presetKey = $data['preset_key'] ?? '';
    if (!$presetKey || !preg_match('/^[a-z0-9_]+$/', $presetKey)) {
        throw new Exception('Invalid preset key');
    }

    $customizationJson = json_encode($data['customization_config'] ?? [], JSON_UNESCAPED_SLASHES);

    $stmt = $db->prepare("
        INSERT INTO cms_template_presets
        (preset_key, label, description, base_page_template_id, base_block_template_id,
         customization_config_json, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $stmt->execute([
        $presetKey,
        $data['label'] ?? '',
        $data['description'] ?? null,
        $data['base_page_template_id'] ?? null,
        $data['base_block_template_id'] ?? null,
        $customizationJson,
        $userId,
    ]);

    return (int)$db->lastInsertId();
}

/**
 * Get all presets
 *
 * @param int|null $baseTemplateId Filter by base template
 * @return array Array of preset records
 */
function cms_getTemplatePresets(?int $baseTemplateId = null): array
{
    $db = getDB();

    $sql = "SELECT * FROM cms_template_presets WHERE 1=1";
    $params = [];

    if ($baseTemplateId) {
        $sql .= " AND (base_page_template_id = ? OR base_block_template_id = ?)";
        $params = [$baseTemplateId, $baseTemplateId];
    }

    $sql .= " ORDER BY label ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $presets = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($presets as &$preset) {
        $preset['customization_config'] = json_decode($preset['customization_config_json'], true);
    }

    return $presets;
}

/**
 * Apply a preset to a page
 *
 * @param int $pageId Page ID to apply preset to
 * @param int $presetId Preset ID
 * @param int $userId User applying preset
 * @return bool Success
 */
function cms_applyTemplatePreset(int $pageId, int $presetId, int $userId): bool
{
    $db = getDB();

    // Get preset
    $stmt = $db->prepare("
        SELECT base_page_template_id, base_block_template_id, customization_config_json
        FROM cms_template_presets
        WHERE id = ?
    ");
    $stmt->execute([$presetId]);
    $preset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$preset) {
        throw new Exception('Preset not found');
    }

    $customizations = json_decode($preset['customization_config_json'], true) ?: [];

    // Get blocks on page
    $blocks = cms_getBlocksByPageId($pageId);

    // Apply customizations to matching blocks
    foreach ($blocks as $block) {
        foreach ($customizations as $field => $value) {
            // Update config
            $config = $block['config'] ?? [];
            $config[$field] = $value;

            cms_saveBlock($pageId, $block['block_type'], $block['position'], $config, null, null, $block['id']);
        }
    }

    // Increment preset usage
    $db->prepare("
        UPDATE cms_template_presets
        SET applied_to_count = applied_to_count + 1
        WHERE id = ?
    ")->execute([$presetId]);

    return true;
}

// ============================================================================
// TEMPLATE GROUPS (Organize templates)
// ============================================================================

/**
 * Create a template group
 *
 * @param array $data Group data
 * @param int $userId User ID
 * @return int Group ID
 */
function cms_createTemplateGroup(array $data, int $userId): int
{
    $db = getDB();

    $groupKey = $data['group_key'] ?? '';
    if (!$groupKey || !preg_match('/^[a-z0-9_]+$/', $groupKey)) {
        throw new Exception('Invalid group key');
    }

    $stmt = $db->prepare("
        INSERT INTO cms_template_groups
        (group_key, label, description, group_type, purpose, is_featured, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $stmt->execute([
        $groupKey,
        $data['label'] ?? '',
        $data['description'] ?? null,
        $data['group_type'] ?? 'mixed',
        $data['purpose'] ?? null,
        isset($data['is_featured']) ? (int)$data['is_featured'] : 0,
        $userId,
    ]);

    return (int)$db->lastInsertId();
}

/**
 * Get featured template groups
 *
 * @return array Array of group records with member templates
 */
function cms_getFeaturedTemplateGroups(): array
{
    $db = getDB();

    $stmt = $db->query("
        SELECT id, group_key, label, description, group_type, purpose, preview_image_path
        FROM cms_template_groups
        WHERE is_featured = 1 AND is_active = 1
        ORDER BY label ASC
    ");

    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Load members for each group
    foreach ($groups as &$group) {
        $group['members'] = cms_getTemplateGroupMembers($group['id']);
    }

    return $groups;
}

/**
 * Get members of a template group
 *
 * @param int $groupId Group ID
 * @return array Array of page/block templates in group
 */
function cms_getTemplateGroupMembers(int $groupId): array
{
    $db = getDB();

    $stmt = $db->prepare("
        SELECT page_template_id, block_template_id, position
        FROM cms_template_group_members
        WHERE group_id = ?
        ORDER BY position ASC
    ");
    $stmt->execute([$groupId]);

    $members = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Load full template data
    foreach ($members as &$member) {
        if ($member['page_template_id']) {
            $member['template'] = cms_getPageTemplateById($member['page_template_id']);
            $member['template_type'] = 'page';
        } elseif ($member['block_template_id']) {
            $member['template'] = cms_getBlockTemplateById($member['block_template_id']);
            $member['template_type'] = 'block';
        }
    }

    return $members;
}

/**
 * Add template to group
 *
 * @param int $groupId Group ID
 * @param int|null $pageTemplateId Page template ID (or null if adding block template)
 * @param int|null $blockTemplateId Block template ID (or null if adding page template)
 * @param int $position Position in group
 * @return int Membership record ID
 */
function cms_addTemplateToGroup(int $groupId, ?int $pageTemplateId = null, ?int $blockTemplateId = null, int $position = 0): int
{
    if (!$pageTemplateId && !$blockTemplateId) {
        throw new Exception('Must provide either page_template_id or block_template_id');
    }

    $db = getDB();

    $stmt = $db->prepare("
        INSERT INTO cms_template_group_members
        (group_id, page_template_id, block_template_id, position)
        VALUES (?, ?, ?, ?)
    ");

    $stmt->execute([$groupId, $pageTemplateId, $blockTemplateId, $position]);

    return (int)$db->lastInsertId();
}

// ============================================================================
// TEMPLATE PERFORMANCE TRACKING
// ============================================================================

/**
 * Record template usage and performance
 *
 * @param int $pageTemplateId Page template ID (or null for block)
 * @param int|null $blockTemplateId Block template ID
 * @param array $metrics Performance metrics
 * @return bool Success
 */
function cms_recordTemplatePerformance(?int $pageTemplateId, ?int $blockTemplateId, array $metrics): bool
{
    $db = getDB();

    $stmt = $db->prepare("
        INSERT INTO cms_template_performance
        (page_template_id, block_template_id, date, pages_created, pages_published,
         avg_page_views, avg_conversion_rate, avg_time_on_page, avg_ctr, avg_engagement, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        pages_created = pages_created + ?,
        pages_published = pages_published + ?,
        avg_page_views = ?,
        avg_conversion_rate = ?,
        avg_time_on_page = ?,
        avg_ctr = ?,
        avg_engagement = ?
    ");

    $date = $metrics['date'] ?? date('Y-m-d');

    return $stmt->execute([
        $pageTemplateId,
        $blockTemplateId,
        $date,
        $metrics['pages_created'] ?? 0,
        $metrics['pages_published'] ?? 0,
        $metrics['avg_page_views'] ?? null,
        $metrics['avg_conversion_rate'] ?? null,
        $metrics['avg_time_on_page'] ?? null,
        $metrics['avg_ctr'] ?? null,
        $metrics['avg_engagement'] ?? null,
        $metrics['notes'] ?? null,
        // Duplicates (for UPDATE)
        $metrics['pages_created'] ?? 0,
        $metrics['pages_published'] ?? 0,
        $metrics['avg_page_views'] ?? null,
        $metrics['avg_conversion_rate'] ?? null,
        $metrics['avg_time_on_page'] ?? null,
        $metrics['avg_ctr'] ?? null,
        $metrics['avg_engagement'] ?? null,
    ]);
}

/**
 * Get template performance summary
 *
 * @param int|null $pageTemplateId Page template ID
 * @param int|null $blockTemplateId Block template ID
 * @param string|null $dateFrom Start date (YYYY-MM-DD)
 * @param string|null $dateTo End date (YYYY-MM-DD)
 * @return array Performance metrics
 */
function cms_getTemplatePerformance(?int $pageTemplateId = null, ?int $blockTemplateId = null, ?string $dateFrom = null, ?string $dateTo = null): array
{
    $db = getDB();

    $sql = "
        SELECT
            date,
            pages_created,
            pages_published,
            avg_page_views,
            avg_conversion_rate,
            avg_ctr,
            avg_engagement
        FROM cms_template_performance
        WHERE 1=1
    ";

    $params = [];

    if ($pageTemplateId) {
        $sql .= " AND page_template_id = ?";
        $params[] = $pageTemplateId;
    }

    if ($blockTemplateId) {
        $sql .= " AND block_template_id = ?";
        $params[] = $blockTemplateId;
    }

    if ($dateFrom) {
        $sql .= " AND date >= ?";
        $params[] = $dateFrom;
    }

    if ($dateTo) {
        $sql .= " AND date <= ?";
        $params[] = $dateTo;
    }

    $sql .= " ORDER BY date DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// ============================================================================
// TEMPLATE SEARCH & DISCOVERY
// ============================================================================

/**
 * Search templates
 *
 * @param string $query Search query (label, description)
 * @param array $filters Filters: {category, block_type, usage_min, performance}
 * @return array Matching templates (both page and block)
 */
function cms_searchTemplates(string $query, array $filters = []): array
{
    $db = getDB();

    $results = [];

    // Search page templates
    $sql = "
        SELECT 'page' as type, id, template_key as key, label, description, category, usage_count
        FROM cms_page_templates
        WHERE is_active = 1 AND (label LIKE ? OR description LIKE ?)
    ";

    $params = ["%{$query}%", "%{$query}%"];

    if (!empty($filters['category'])) {
        $sql .= " AND category = ?";
        $params[] = $filters['category'];
    }

    if (!empty($filters['usage_min'])) {
        $sql .= " AND usage_count >= ?";
        $params[] = (int)$filters['usage_min'];
    }

    $sql .= " ORDER BY usage_count DESC LIMIT 10";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $results['page_templates'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Search block templates
    $sql = "
        SELECT 'block' as type, id, block_template_key as key, label, description, block_type, category, usage_count
        FROM cms_block_templates
        WHERE is_active = 1 AND (label LIKE ? OR description LIKE ?)
    ";

    $params = ["%{$query}%", "%{$query}%"];

    if (!empty($filters['block_type'])) {
        $sql .= " AND block_type = ?";
        $params[] = $filters['block_type'];
    }

    $sql .= " ORDER BY usage_count DESC LIMIT 10";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $results['block_templates'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return $results;
}

// ============================================================================
// TEMPLATE EXPORT/IMPORT (For backup & sharing)
// ============================================================================

/**
 * Export page template to JSON
 *
 * @param int $templateId Template ID
 * @return string JSON export
 */
function cms_exportPageTemplate(int $templateId): string
{
    $template = cms_getPageTemplateById($templateId);
    if (!$template) {
        throw new Exception('Template not found');
    }

    $export = [
        'template_key' => $template['template_key'],
        'label' => $template['label'],
        'description' => $template['description'],
        'layout_template' => $template['layout_template'],
        'page_type' => $template['page_type'],
        'blocks_config' => $template['blocks_config'],
        'page_metadata_defaults' => $template['page_metadata_defaults'],
        'exported_at' => date('Y-m-d H:i:s'),
    ];

    return json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/**
 * Import page template from JSON
 *
 * @param string $json JSON export
 * @param int $userId User importing
 * @return int Template ID
 */
function cms_importPageTemplate(string $json, int $userId): int
{
    $data = json_decode($json, true);
    if (!$data) {
        throw new Exception('Invalid JSON');
    }

    return cms_savePageTemplate($data, $userId);
}
