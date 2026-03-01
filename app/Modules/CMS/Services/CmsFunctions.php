<?php
/**
 * CMS Functions Library
 *
 * Core helper functions for CMS page rendering, block management, and content operations.
 * Used by CMS admin pages and front-end page renderer.
 *
 * @package Mowology
 * @subpackage CMS
 *
 * Migrated from: public/crm/includes/cms-functions.php
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 3) . '/Core/paths.php';
}

// ============================================================================
// PAGE QUERIES
// ============================================================================

/**
 * Get a page by slug (with caching support)
 *
 * @param string $slug URL slug
 * @param int $cacheTTL Cache duration in seconds (0 = no cache)
 * @return array|null Page record or null if not found
 */
function cms_getPageBySlug(string $slug, int $cacheTTL = 900): ?array
{
    if ($cacheTTL > 0) {
        $cached = cms_getCache("page_slug_{$slug}");
        if ($cached) {
            return $cached;
        }
    }

    $db = getDB();
    $stmt = $db->prepare("
        SELECT *
        FROM cms_pages
        WHERE slug = ?
        LIMIT 1
    ");
    $stmt->execute([$slug]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($page && $cacheTTL > 0) {
        cms_setCache("page_slug_{$slug}", $page, $cacheTTL);
    }

    return $page ?: null;
}

/**
 * Get a page by ID
 *
 * @param int $pageId Page ID
 * @return array|null Page record
 */
function cms_getPageById(int $pageId): ?array
{
    $db = getDB();
    $stmt = $db->prepare("
        SELECT *
        FROM cms_pages
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$pageId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Get all published pages (for sitemap, navigation, etc.)
 * Respects publish_at / unpublish_at scheduling windows.
 *
 * @param string|null $pageType Filter by page_type (optional)
 * @return array Array of page records
 */
function cms_getPublishedPages(?string $pageType = null): array
{
    $db = getDB();
    $sql = "
        SELECT id, slug, title, meta_title, meta_description, page_type, noindex, updated_at
        FROM cms_pages
        WHERE status = 'published'
          AND (publish_at IS NULL OR publish_at <= NOW())
          AND (unpublish_at IS NULL OR unpublish_at > NOW())
    ";

    $params = [];
    if ($pageType) {
        $sql .= " AND page_type = ?";
        $params[] = $pageType;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Determine if a page is live (published + within scheduling window).
 * Use this in the public renderer; admin lookups should bypass it.
 *
 * @param array $page Page record from cms_getPageBySlug()
 * @return bool True if the page should be shown to the public
 */
function cms_isPageLive(array $page): bool
{
    if (($page['status'] ?? '') !== 'published') {
        return false;
    }

    $now = time();

    if (!empty($page['publish_at'])) {
        $publishAt = strtotime($page['publish_at']);
        if ($publishAt !== false && $now < $publishAt) {
            return false;
        }
    }

    if (!empty($page['unpublish_at'])) {
        $unpublishAt = strtotime($page['unpublish_at']);
        if ($unpublishAt !== false && $now >= $unpublishAt) {
            return false;
        }
    }

    return true;
}

/**
 * Get all pages (published, draft, archived) for admin listing
 *
 * Includes all statuses so filtering/stats are accurate in admin UI.
 *
 * @return array Array of all page records
 */
function cms_getAllPages(): array
{
    $db = getDB();
    $stmt = $db->query("
        SELECT id, slug, title, page_type, status, meta_title, meta_description,
               created_at, updated_at, created_by, view_count, noindex
        FROM cms_pages
        ORDER BY created_at DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Create or update a page
 *
 * @param array $data Page data (slug, title, meta_*, page_type, layout_template, status, etc.)
 * @param int|null $pageId If provided, update; otherwise create
 * @param int $userId User ID for created_by / updated_by
 * @return int Page ID (created or updated)
 */
function cms_savePage(array $data, ?int $pageId = null, int $userId = 0): int
{
    $db = getDB();

    // Sanitize slug
    $slug = cms_sanitizeSlug($data['slug'] ?? '');
    if (!$slug) {
        throw new Exception('Invalid or empty slug');
    }

    // Check for slug collision
    $existing = $db->prepare("
        SELECT id FROM cms_pages
        WHERE slug = ? AND id != ?
        LIMIT 1
    ");
    $existing->execute([$slug, $pageId ?? 0]);
    if ($existing->fetch()) {
        throw new Exception("Slug '{$slug}' is already in use");
    }

    $now = date('Y-m-d H:i:s');
    $cannonicalUrl = $data['canonical_url'] ?? null;

    // Auto-generate canonical if not provided
    if (!$cannonicalUrl) {
        $siteUrl = defined('SITE_URL') ? SITE_URL : 'https://mowology.ca';
        $cannonicalUrl = $siteUrl . '/' . trim($slug, '/');
    }

    if ($pageId) {
        // Capture old slug BEFORE updating (for redirect auto-registration)
        $oldSlugRow = $db->prepare("SELECT slug, status FROM cms_pages WHERE id = ? LIMIT 1");
        $oldSlugRow->execute([$pageId]);
        $oldPage = $oldSlugRow->fetch(PDO::FETCH_ASSOC);
        $oldSlug = $oldPage['slug'] ?? null;

        // Update
        $stmt = $db->prepare("
            UPDATE cms_pages
            SET slug = ?,
                title = ?,
                meta_title = ?,
                meta_description = ?,
                meta_keywords = ?,
                canonical_url = ?,
                og_image_path = ?,
                page_type = ?,
                layout_template = ?,
                status = ?,
                publish_at = ?,
                unpublish_at = ?,
                noindex = ?,
                seo_score = ?,
                updated_by = ?,
                updated_at = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $slug,
            $data['title'] ?? '',
            $data['meta_title'] ?? null,
            $data['meta_description'] ?? null,
            $data['meta_keywords'] ?? null,
            $cannonicalUrl,
            $data['og_image_path'] ?? null,
            $data['page_type'] ?? 'custom',
            $data['layout_template'] ?? 'default',
            $data['status'] ?? 'draft',
            $data['publish_at'] ?? null,
            $data['unpublish_at'] ?? null,
            isset($data['noindex']) ? (int)$data['noindex'] : 0,
            $data['seo_score'] ?? null,
            $userId,
            $now,
            $pageId,
        ]);

        // Auto-register 301 redirect if slug changed
        if ($oldSlug && $oldSlug !== $slug) {
            cms_registerRedirect($oldSlug, $slug, 301);
        }

        // Invalidate HTML cache for this page
        cms_invalidatePageHtmlCache($pageId);

        // Activity log
        $newStatus = $data['status'] ?? 'draft';
        $action = ($oldPage && ($oldPage['status'] ?? '') !== $newStatus)
            ? 'page_' . $newStatus
            : 'page_updated';
        cms_logCmsActivity($userId, $action, "Page '{$data['title']}' {$action}", [
            'page_id' => $pageId,
            'slug'    => $slug,
        ]);

        return $pageId;
    } else {
        // Create
        $stmt = $db->prepare("
            INSERT INTO cms_pages
            (slug, title, meta_title, meta_description, meta_keywords, canonical_url, og_image_path,
             page_type, layout_template, status, publish_at, unpublish_at, noindex, seo_score,
             created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $slug,
            $data['title'] ?? '',
            $data['meta_title'] ?? null,
            $data['meta_description'] ?? null,
            $data['meta_keywords'] ?? null,
            $cannonicalUrl,
            $data['og_image_path'] ?? null,
            $data['page_type'] ?? 'custom',
            $data['layout_template'] ?? 'default',
            $data['status'] ?? 'draft',
            $data['publish_at'] ?? null,
            $data['unpublish_at'] ?? null,
            isset($data['noindex']) ? (int)$data['noindex'] : 0,
            $data['seo_score'] ?? null,
            $userId,
            $now,
            $now,
        ]);

        $newPageId = (int)$db->lastInsertId();

        // Activity log
        cms_logCmsActivity($userId, 'page_created', "Page '{$data['title']}' created", [
            'page_id' => $newPageId,
            'slug'    => $slug,
        ]);

        return $newPageId;
    }
}

/**
 * Delete a page (soft delete via archive status)
 *
 * @param int $pageId Page ID
 * @param bool $hardDelete If true, permanently delete; otherwise set to archived
 * @return bool Success
 */
function cms_deletePage(int $pageId, bool $hardDelete = false): bool
{
    $db = getDB();

    // Grab title for activity log before deleting
    $row = $db->prepare("SELECT title, slug FROM cms_pages WHERE id = ? LIMIT 1");
    $row->execute([$pageId]);
    $meta = $row->fetch(PDO::FETCH_ASSOC);

    if ($hardDelete) {
        $stmt = $db->prepare("DELETE FROM cms_pages WHERE id = ?");
    } else {
        $stmt = $db->prepare("UPDATE cms_pages SET status = 'archived' WHERE id = ?");
    }

    $result = $stmt->execute([$pageId]);

    if ($result) {
        cms_invalidatePageHtmlCache($pageId);

        $userId = 0;
        if (function_exists('getCurrentUser')) {
            try { $u = getCurrentUser(); $userId = (int)($u['id'] ?? 0); } catch (\Throwable $e) {}
        }
        $action = $hardDelete ? 'page_deleted' : 'page_archived';
        cms_logCmsActivity($userId, $action, "Page '{$meta['title']}' {$action}", [
            'page_id' => $pageId,
            'slug'    => $meta['slug'] ?? '',
        ]);
    }

    return $result;
}

// ============================================================================
// BLOCK QUERIES & OPERATIONS
// ============================================================================

/**
 * Get all blocks for a page (ordered by position)
 *
 * @param int $pageId Page ID
 * @return array Array of block records with config_json decoded
 */
function cms_getBlocksByPageId(int $pageId, int $cacheTTL = 900): array
{
    $cacheKey = "blocks_page_{$pageId}";
    if ($cacheTTL > 0) {
        $cached = cms_getCache($cacheKey);
        if ($cached) {
            return $cached;
        }
    }

    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, page_id, block_type, position, config_json, content_json, visibility_json,
               created_at, updated_at
        FROM cms_blocks
        WHERE page_id = ?
        ORDER BY position ASC
    ");
    $stmt->execute([$pageId]);
    $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Decode JSON fields
    foreach ($blocks as &$block) {
        $block['config'] = json_decode($block['config_json'] ?? '{}', true);
        $block['content'] = json_decode($block['content_json'] ?? 'null', true);
        $block['visibility'] = json_decode($block['visibility_json'] ?? 'null', true);
    }

    if ($cacheTTL > 0) {
        cms_setCache($cacheKey, $blocks, $cacheTTL);
    }

    return $blocks;
}

/**
 * Get a block by ID
 *
 * @param int $blockId Block ID
 * @return array|null Block record with JSON decoded
 */
function cms_getBlockById(int $blockId): ?array
{
    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, page_id, block_type, position, config_json, content_json, visibility_json,
               created_at, updated_at
        FROM cms_blocks
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$blockId]);
    $block = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($block) {
        $block['config'] = json_decode($block['config_json'] ?? '{}', true);
        $block['content'] = json_decode($block['content_json'] ?? 'null', true);
        $block['visibility'] = json_decode($block['visibility_json'] ?? 'null', true);
    }

    return $block ?: null;
}

/**
 * Create or update a block
 *
 * @param int $pageId Page ID
 * @param string $blockType Block type (must exist in cms_block_types)
 * @param int $position Render position (0 = first)
 * @param array $config Block configuration (varies by type)
 * @param array|null $content Optional dynamic content
 * @param array|null $visibility Optional visibility rules
 * @param int|null $blockId If provided, update; otherwise create
 * @return int Block ID
 */
function cms_saveBlock(
    int $pageId,
    string $blockType,
    int $position,
    array $config,
    ?array $content = null,
    ?array $visibility = null,
    ?int $blockId = null
): int {
    $db = getDB();

    // Verify block type exists
    $typeCheck = $db->prepare("SELECT id FROM cms_block_types WHERE block_type = ? LIMIT 1");
    $typeCheck->execute([$blockType]);
    if (!$typeCheck->fetch()) {
        throw new Exception("Invalid block type: {$blockType}");
    }

    // Verify page exists
    $pageCheck = $db->prepare("SELECT id FROM cms_pages WHERE id = ? LIMIT 1");
    $pageCheck->execute([$pageId]);
    if (!$pageCheck->fetch()) {
        throw new Exception("Page not found: {$pageId}");
    }

    $configJson = json_encode($config, JSON_UNESCAPED_SLASHES);
    $contentJson = json_encode($content, JSON_UNESCAPED_SLASHES);
    $visibilityJson = json_encode($visibility, JSON_UNESCAPED_SLASHES);

    $userId = 0;
    if (function_exists('getCurrentUser')) {
        try { $u = getCurrentUser(); $userId = (int)($u['id'] ?? 0); } catch (\Throwable $e) {}
    }

    if ($blockId) {
        // Update
        $stmt = $db->prepare("
            UPDATE cms_blocks
            SET page_id = ?, block_type = ?, position = ?,
                config_json = ?, content_json = ?, visibility_json = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$pageId, $blockType, $position, $configJson, $contentJson, $visibilityJson, $blockId]);

        // Invalidate request + HTML cache
        cms_invalidateCache("blocks_page_{$pageId}");
        cms_invalidatePageHtmlCache($pageId);

        cms_logCmsActivity($userId, 'block_updated', "Block '{$blockType}' updated on page #{$pageId}", [
            'page_id' => $pageId, 'block_id' => $blockId, 'block_type' => $blockType,
        ]);

        return $blockId;
    } else {
        // Create
        $stmt = $db->prepare("
            INSERT INTO cms_blocks
            (page_id, block_type, position, config_json, content_json, visibility_json, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$pageId, $blockType, $position, $configJson, $contentJson, $visibilityJson]);

        $newBlockId = (int)$db->lastInsertId();

        // Invalidate request + HTML cache
        cms_invalidateCache("blocks_page_{$pageId}");
        cms_invalidatePageHtmlCache($pageId);

        cms_logCmsActivity($userId, 'block_created', "Block '{$blockType}' added to page #{$pageId}", [
            'page_id' => $pageId, 'block_id' => $newBlockId, 'block_type' => $blockType,
        ]);

        return $newBlockId;
    }
}

/**
 * Delete a block
 *
 * @param int $blockId Block ID
 * @return bool Success
 */
function cms_deleteBlock(int $blockId): bool
{
    $db = getDB();
    $stmt = $db->prepare("SELECT page_id, block_type FROM cms_blocks WHERE id = ?");
    $stmt->execute([$blockId]);
    $block = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$block) {
        return false;
    }

    $stmt = $db->prepare("DELETE FROM cms_blocks WHERE id = ?");
    $result = $stmt->execute([$blockId]);

    if ($result) {
        // Invalidate request + HTML cache
        cms_invalidateCache("blocks_page_{$block['page_id']}");
        cms_invalidatePageHtmlCache((int)$block['page_id']);

        $userId = 0;
        if (function_exists('getCurrentUser')) {
            try { $u = getCurrentUser(); $userId = (int)($u['id'] ?? 0); } catch (\Throwable $e) {}
        }
        cms_logCmsActivity($userId, 'block_deleted', "Block '{$block['block_type']}' deleted from page #{$block['page_id']}", [
            'page_id' => $block['page_id'], 'block_id' => $blockId, 'block_type' => $block['block_type'],
        ]);
    }

    return $result;
}

/**
 * Reorder blocks within a page
 *
 * @param int $pageId Page ID
 * @param array $blockOrder Array of block IDs in new order
 * @return bool Success
 */
function cms_reorderBlocks(int $pageId, array $blockOrder): bool
{
    $db = getDB();

    $stmt = $db->prepare("
        UPDATE cms_blocks
        SET position = ?
        WHERE id = ? AND page_id = ?
    ");

    foreach ($blockOrder as $position => $blockId) {
        $stmt->execute([$position, $blockId, $pageId]);
    }

    // Invalidate request + HTML cache
    cms_invalidateCache("blocks_page_{$pageId}");
    cms_invalidatePageHtmlCache($pageId);

    return true;
}

// ============================================================================
// BLOCK TYPE REGISTRY
// ============================================================================

/**
 * Get all available block types
 *
 * @return array Array of block type records
 */
function cms_getBlockTypes(): array
{
    $cached = cms_getCache('block_types_all');
    if ($cached) {
        return $cached;
    }

    $db = getDB();
    $stmt = $db->query("
        SELECT id, block_type, label, description, schema_json, renderer_path, is_active
        FROM cms_block_types
        WHERE is_active = 1
        ORDER BY label ASC
    ");
    $types = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Decode schema_json
    foreach ($types as &$type) {
        $type['schema'] = json_decode($type['schema_json'] ?? '{}', true);
    }

    cms_setCache('block_types_all', $types, 3600);

    return $types;
}

/**
 * Get a block type by code
 *
 * @param string $blockType Block type code
 * @return array|null Block type record
 */
function cms_getBlockType(string $blockType): ?array
{
    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, block_type, label, description, schema_json, renderer_path, is_active
        FROM cms_block_types
        WHERE block_type = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$blockType]);
    $type = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($type) {
        $type['schema'] = json_decode($type['schema_json'] ?? '{}', true);
    }

    return $type ?: null;
}

// ============================================================================
// MENU OPERATIONS
// ============================================================================

/**
 * Get a menu with all items (hierarchical)
 *
 * @param string $menuKey Menu key (e.g., header_nav, footer_nav)
 * @return array|null Menu with nested items
 */
function cms_getMenu(string $menuKey): ?array
{
    $db = getDB();

    // Get menu
    $stmt = $db->prepare("
        SELECT id, menu_key, label, description
        FROM cms_menus
        WHERE menu_key = ?
        LIMIT 1
    ");
    $stmt->execute([$menuKey]);
    $menu = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$menu) {
        return null;
    }

    // Get items (parents first, then children)
    $stmt = $db->prepare("
        SELECT id, parent_id, label, url, title_attr, target, rel_attr, position, is_active
        FROM cms_menu_items
        WHERE menu_id = ? AND is_active = 1
        ORDER BY position ASC
    ");
    $stmt->execute([$menu['id']]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Build hierarchy
    $menu['items'] = cms_buildMenuHierarchy($items);

    return $menu;
}

/**
 * Build hierarchical menu structure from flat list
 *
 * @param array $items Flat array of menu items
 * @param int|null $parentId Current parent ID (null = top-level)
 * @return array Hierarchical menu tree
 */
function cms_buildMenuHierarchy(array $items, ?int $parentId = null): array
{
    $tree = [];

    foreach ($items as $item) {
        if (($item['parent_id'] ?? null) === $parentId) {
            $item['children'] = cms_buildMenuHierarchy($items, $item['id']);
            $tree[] = $item;
        }
    }

    return $tree;
}

/**
 * Save a menu item
 *
 * @param int $menuId Menu ID
 * @param string $label Item label
 * @param string $url Item URL
 * @param int $position Sort position
 * @param int|null $parentId Parent item ID (for nested items)
 * @param int|null $itemId If provided, update; otherwise create
 * @return int Item ID
 */
function cms_saveMenuItem(
    int $menuId,
    string $label,
    string $url,
    int $position,
    ?int $parentId = null,
    ?int $itemId = null
): int {
    $db = getDB();

    // Validate URL (must be root-relative or absolute, no JS)
    if (!cms_isValidMenuUrl($url)) {
        throw new Exception("Invalid menu URL: {$url}");
    }

    if ($itemId) {
        $stmt = $db->prepare("
            UPDATE cms_menu_items
            SET menu_id = ?, parent_id = ?, label = ?, url = ?, position = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$menuId, $parentId, $label, $url, $position, $itemId]);
        return $itemId;
    } else {
        $stmt = $db->prepare("
            INSERT INTO cms_menu_items (menu_id, parent_id, label, url, position, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$menuId, $parentId, $label, $url, $position]);
        return (int)$db->lastInsertId();
    }
}

/**
 * Delete a menu item
 *
 * @param int $itemId Menu item ID
 * @return bool Success
 */
function cms_deleteMenuItem(int $itemId): bool
{
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM cms_menu_items WHERE id = ?");
    return $stmt->execute([$itemId]);
}

// ============================================================================
// MEDIA LIBRARY
// ============================================================================

/**
 * Get all media assets with filtering/search
 *
 * @param array $filters Filters: {type, tags, is_favorite, search}
 * @param int $limit Results per page
 * @param int $offset Pagination offset
 * @return array Array of media records
 */
function cms_getMediaAssets(array $filters = [], int $limit = 50, int $offset = 0): array
{
    $db = getDB();

    $sql = "SELECT * FROM media_assets WHERE 1=1";

    if (!empty($filters['type'])) {
        $sql .= " AND file_type = ?";
    }
    if (!empty($filters['is_favorite'])) {
        $sql .= " AND is_favorite = 1";
    }
    if (!empty($filters['search'])) {
        $sql .= " AND (alt_text LIKE ? OR caption LIKE ? OR description LIKE ?)";
    }

    $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";

    $stmt = $db->prepare($sql);
    $params = [];

    if (!empty($filters['type'])) {
        $params[] = $filters['type'];
    }
    if (!empty($filters['search'])) {
        $search = "%{$filters['search']}%";
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    $params[] = $limit;
    $params[] = $offset;

    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Get a media asset by ID
 *
 * @param int $mediaId Media ID
 * @return array|null Media record
 */
function cms_getMediaAssetById(int $mediaId): ?array
{
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM media_assets WHERE id = ? LIMIT 1");
    $stmt->execute([$mediaId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Update media asset metadata
 *
 * @param int $mediaId Media ID
 * @param array $data Fields to update (alt_text, caption, description, tags_json, is_favorite)
 * @return bool Success
 */
function cms_updateMediaAsset(int $mediaId, array $data): bool
{
    $db = getDB();

    $updates = [];
    $params = [];

    if (isset($data['alt_text'])) {
        $updates[] = "alt_text = ?";
        $params[] = $data['alt_text'];
    }
    if (isset($data['caption'])) {
        $updates[] = "caption = ?";
        $params[] = $data['caption'];
    }
    if (isset($data['description'])) {
        $updates[] = "description = ?";
        $params[] = $data['description'];
    }
    if (isset($data['tags'])) {
        $updates[] = "tags_json = ?";
        $params[] = json_encode($data['tags']);
    }
    if (isset($data['is_favorite'])) {
        $updates[] = "is_favorite = ?";
        $params[] = (int)$data['is_favorite'];
    }

    if (empty($updates)) {
        return true;
    }

    $updates[] = "updated_at = NOW()";
    $params[] = $mediaId;

    $sql = "UPDATE media_assets SET " . implode(", ", $updates) . " WHERE id = ?";
    $stmt = $db->prepare($sql);

    return $stmt->execute($params);
}

// ============================================================================
// PAGE REVISIONS
// ============================================================================

/**
 * Create a page revision snapshot
 *
 * @param int $pageId Page ID
 * @param int $userId User who created revision
 * @param string $type Revision type (draft|published|restore)
 * @param string|null $message Revision message
 * @return int Revision ID
 */
function cms_createPageRevision(int $pageId, int $userId, string $type = 'draft', ?string $message = null): int
{
    $db = getDB();

    $page = cms_getPageById($pageId);
    if (!$page) {
        throw new Exception("Page not found: {$pageId}");
    }

    $blocks = cms_getBlocksByPageId($pageId);

    $stmt = $db->prepare("
        INSERT INTO cms_page_revisions
        (page_id, slug, title, meta_title, meta_description, blocks_snapshot, revision_type, revision_message, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $stmt->execute([
        $pageId,
        $page['slug'],
        $page['title'],
        $page['meta_title'],
        $page['meta_description'],
        json_encode($blocks),
        $type,
        $message,
        $userId,
    ]);

    return (int)$db->lastInsertId();
}

/**
 * Get page revision history
 *
 * @param int $pageId Page ID
 * @param int $limit Number of revisions to return
 * @return array Array of revision records
 */
function cms_getPageRevisions(int $pageId, int $limit = 50): array
{
    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, page_id, slug, title, revision_type, revision_message, created_by, created_at
        FROM cms_page_revisions
        WHERE page_id = ?
        ORDER BY created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$pageId, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Restore a page from a revision
 *
 * @param int $revisionId Revision ID
 * @param int $userId User restoring the revision
 * @return bool Success
 */
function cms_restorePageFromRevision(int $revisionId, int $userId): bool
{
    $db = getDB();

    $stmt = $db->prepare("
        SELECT page_id, blocks_snapshot
        FROM cms_page_revisions
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$revisionId]);
    $revision = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$revision) {
        throw new Exception("Revision not found: {$revisionId}");
    }

    // Clear existing blocks
    $db->prepare("DELETE FROM cms_blocks WHERE page_id = ?")->execute([$revision['page_id']]);

    // Restore blocks from snapshot
    $blocks = json_decode($revision['blocks_snapshot'], true) ?: [];
    foreach ($blocks as $block) {
        cms_saveBlock(
            $revision['page_id'],
            $block['block_type'],
            $block['position'],
            $block['config'] ?? json_decode($block['config_json'] ?? '{}', true),
            $block['content'] ?? json_decode($block['content_json'] ?? 'null', true)
        );
    }

    // Create restore revision record
    cms_createPageRevision($revision['page_id'], $userId, 'restore', 'Restored from revision #' . $revisionId);

    return true;
}

// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

/**
 * Sanitize and validate a page slug
 *
 * @param string $slug Raw slug input
 * @return string Sanitized slug (lowercase, alphanumeric + hyphens only)
 */
function cms_sanitizeSlug(string $slug): string
{
    // Convert to lowercase
    $slug = strtolower($slug);

    // Replace spaces and underscores with hyphens
    $slug = preg_replace('/[\s_]+/', '-', $slug);

    // Remove non-alphanumeric characters except hyphens
    $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);

    // Collapse multiple hyphens
    $slug = preg_replace('/-+/', '-', $slug);

    // Trim hyphens from start/end
    $slug = trim($slug, '-');

    return $slug;
}

/**
 * Validate a menu URL (prevent XSS)
 *
 * @param string $url URL to validate
 * @return bool Whether URL is safe
 */
function cms_isValidMenuUrl(string $url): bool
{
    // Block javascript: and data: protocols
    if (stripos($url, 'javascript:') === 0 || stripos($url, 'data:') === 0) {
        return false;
    }

    // Allow root-relative paths
    if (strpos($url, '/') === 0) {
        return true;
    }

    // Allow absolute URLs
    if (filter_var($url, FILTER_VALIDATE_URL)) {
        return true;
    }

    // Allow # and ? for anchors and queries
    if (preg_match('/^#[\w\-]+$/', $url) || preg_match('/^\?/', $url)) {
        return true;
    }

    return false;
}

/**
 * Simple in-memory caching wrapper (can be upgraded to Redis/APCu)
 *
 * @param string $key Cache key
 * @return mixed Cached value or null
 */
function cms_getCache(string $key)
{
    static $cache = [];
    return $cache[$key] ?? null;
}

/**
 * Set cache value
 *
 * @param string $key Cache key
 * @param mixed $value Value to cache
 * @param int $ttl Time-to-live in seconds (ignored in-memory)
 * @return void
 */
function cms_setCache(string $key, $value, int $ttl = 900): void
{
    static $cache = [];
    $cache[$key] = $value;
}

/**
 * Invalidate cache key
 *
 * @param string $key Cache key (supports * wildcard prefix matching)
 * @return void
 */
function cms_invalidateCache(string $key): void
{
    static $cache = [];
    if (strpos($key, '*') !== false) {
        $pattern = str_replace('*', '.*', $key);
        foreach (array_keys($cache) as $k) {
            if (preg_match("/^{$pattern}$/", $k)) {
                unset($cache[$k]);
            }
        }
    } else {
        unset($cache[$key]);
    }
}

// ============================================================================
// CONSTANTS
// ============================================================================

if (!defined('CMS_BLOCKS_RENDERER_DIR')) {
    define('CMS_BLOCKS_RENDERER_DIR', CRM_INCLUDES . '/blocks');
}

if (!defined('CMS_LAYOUTS_DIR')) {
    define('CMS_LAYOUTS_DIR', PUBLIC_ROOT . '/layouts');
}

// ============================================================================
// COMPLETION SCORE
// ============================================================================

/**
 * Calculate a 0-100 completion score for a page.
 *
 * Checks: title, slug, meta_title, meta_description, 2+ blocks,
 *         hero block present, at least one media reference in any block config.
 *
 * @param array $page Page record
 * @return int Score 0-100
 */
function cms_getPageCompletionScore(array $page): int
{
    $checks = [
        'title'      => !empty(trim($page['title'] ?? '')),
        'slug'       => !empty(trim($page['slug'] ?? '')),
        'meta_title' => !empty(trim($page['meta_title'] ?? '')),
        'meta_desc'  => !empty(trim($page['meta_description'] ?? '')),
        'has_blocks' => false,
        'has_hero'   => false,
        'has_image'  => false,
    ];

    try {
        $blocks = cms_getBlocksByPageId((int)$page['id'], 0);
        $checks['has_blocks'] = count($blocks) >= 2;
        foreach ($blocks as $blk) {
            if ($blk['block_type'] === 'hero') {
                $checks['has_hero'] = true;
            }
            foreach ($blk['config'] ?? [] as $v) {
                if (is_numeric($v) && (int)$v > 0) {
                    $checks['has_image'] = true;
                } elseif (is_string($v) && preg_match('/\.(jpg|jpeg|png|webp|gif)/i', $v)) {
                    $checks['has_image'] = true;
                }
            }
        }
    } catch (Exception $e) {
        // Silently skip — don't break admin listing
    }

    $total  = count($checks);
    $passed = count(array_filter($checks));

    return (int)round(($passed / $total) * 100);
}

// ============================================================================
// ACTIVITY LOG
// ============================================================================

/**
 * Log a CMS activity to the activity_log table.
 *
 * Silently fails so it never breaks the calling operation.
 *
 * @param int    $userId    Acting user ID
 * @param string $action    Machine-readable action key (e.g. 'page_published', 'block_updated')
 * @param string $summary   Human-readable one-liner shown in the log UI
 * @param array  $meta      Optional extra data (block_id, page_id, etc.)
 */
function cms_logCmsActivity(int $userId, string $action, string $summary, array $meta = []): void
{
    try {
        $db = getDB();

        // Gracefully try both common activity_log schemas
        // Schema A: (user_id, action, description, created_at)
        // Schema B: (user_id, action_type, notes, meta_json, created_at)
        $cols = [];
        $colCheck = $db->query("SHOW COLUMNS FROM activity_log");
        if ($colCheck) {
            $cols = array_column($colCheck->fetchAll(PDO::FETCH_ASSOC), 'Field');
        }

        if (in_array('action_type', $cols)) {
            // Schema B: action_type + notes + meta_json
            $db->prepare("
                INSERT INTO activity_log (user_id, action_type, notes, meta_json, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ")->execute([$userId, $action, $summary, json_encode($meta)]);
        } elseif (in_array('action', $cols)) {
            // Schema A: action + details (or description if that column name is used)
            $textCol = in_array('details', $cols) ? 'details' : (in_array('description', $cols) ? 'description' : null);
            if ($textCol) {
                $db->prepare("
                    INSERT INTO activity_log (user_id, action, {$textCol}, created_at)
                    VALUES (?, ?, ?, NOW())
                ")->execute([$userId, $action, $summary]);
            } else {
                $db->prepare("
                    INSERT INTO activity_log (user_id, action, created_at)
                    VALUES (?, ?, NOW())
                ")->execute([$userId, $action]);
            }
        }
        // If table has neither column, silently skip
    } catch (Exception $e) {
        error_log('cms_logCmsActivity failed: ' . $e->getMessage());
    }
}

// ============================================================================
// HTML PAGE CACHE  (P1-B)
// Stores fully-rendered HTML per page in cms_page_cache table.
// Falls back to live render if table missing (migration 921 not yet run).
// ============================================================================

/**
 * Retrieve cached HTML for a page.
 *
 * @param int $pageId
 * @return string|null Cached HTML, or null on miss / expired
 */
function cms_getPageHtmlCache(int $pageId): ?string
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT cache_html, cached_at, ttl_seconds
            FROM cms_page_cache
            WHERE page_id = ?
            LIMIT 1
        ");
        $stmt->execute([$pageId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        // Check TTL
        $age = time() - strtotime($row['cached_at']);
        if ($age > (int)$row['ttl_seconds']) {
            return null; // Expired
        }

        return $row['cache_html'];
    } catch (\Throwable $e) {
        // Table probably not created yet — silently skip
        return null;
    }
}

/**
 * Store rendered HTML for a page.
 *
 * @param int    $pageId
 * @param string $html       Full rendered HTML output
 * @param int    $ttlSeconds Cache duration (default 1 hour)
 */
function cms_setPageHtmlCache(int $pageId, string $html, int $ttlSeconds = 3600): void
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO cms_page_cache (page_id, cache_html, cached_at, ttl_seconds)
            VALUES (?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE cache_html = VALUES(cache_html),
                                    cached_at  = NOW(),
                                    ttl_seconds = VALUES(ttl_seconds)
        ");
        $stmt->execute([$pageId, $html, $ttlSeconds]);
    } catch (\Throwable $e) {
        // Silently skip — live render is the fallback
    }
}

/**
 * Invalidate the HTML cache for a specific page.
 * Called automatically on page/block save/delete.
 *
 * @param int $pageId
 */
function cms_invalidatePageHtmlCache(int $pageId): void
{
    try {
        $db = getDB();
        $db->prepare("DELETE FROM cms_page_cache WHERE page_id = ?")->execute([$pageId]);
    } catch (\Throwable $e) {
        // Silently skip
    }
}

/**
 * Flush the entire HTML page cache (e.g. after a global CSS/token change).
 */
function cms_invalidateAllPageHtmlCache(): void
{
    try {
        $db = getDB();
        $db->exec("TRUNCATE TABLE cms_page_cache");
    } catch (\Throwable $e) {
        // Silently skip
    }
}

// ============================================================================
// REDIRECT MANAGEMENT  (P1-A)
// Auto-populated when cms_savePage() detects a slug change.
// Checked by cms-render.php before issuing a 404.
// ============================================================================

/**
 * Look up a redirect for an old slug.
 *
 * @param string $fromSlug The slug being requested
 * @return array|null  Array with keys: to_slug, status_code — or null if none
 */
function cms_getRedirectForSlug(string $fromSlug): ?array
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT to_slug, status_code
            FROM cms_page_redirects
            WHERE from_slug = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$fromSlug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            // Increment hit counter asynchronously (fire-and-forget)
            try {
                $db->prepare("UPDATE cms_page_redirects SET hit_count = hit_count + 1 WHERE from_slug = ?")
                   ->execute([$fromSlug]);
            } catch (\Throwable $e) {}
        }

        return $row ?: null;
    } catch (\Throwable $e) {
        // Table probably not created yet
        return null;
    }
}

/**
 * Register a redirect rule (upserts on from_slug conflict).
 * Called automatically by cms_savePage() when a slug changes.
 *
 * @param string $fromSlug   Old slug
 * @param string $toSlug     New slug
 * @param int    $statusCode 301 (permanent) or 302 (temporary)
 */
function cms_registerRedirect(string $fromSlug, string $toSlug, int $statusCode = 301): void
{
    if ($fromSlug === $toSlug) {
        return;
    }

    try {
        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO cms_page_redirects (from_slug, to_slug, status_code, is_active, created_at)
            VALUES (?, ?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE to_slug     = VALUES(to_slug),
                                    status_code = VALUES(status_code),
                                    is_active   = 1
        ");
        $stmt->execute([$fromSlug, $toSlug, $statusCode]);

        // If the new slug was itself redirected FROM somewhere, update that chain
        // so we never create redirect loops. (e.g. A→B, then B→C becomes A→C)
        $db->prepare("
            UPDATE cms_page_redirects
            SET to_slug = ?
            WHERE to_slug = ? AND from_slug != ?
        ")->execute([$toSlug, $fromSlug, $toSlug]);
    } catch (\Throwable $e) {
        error_log('cms_registerRedirect failed: ' . $e->getMessage());
    }
}
