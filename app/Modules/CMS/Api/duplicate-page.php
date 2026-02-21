<?php
/**
 * API Endpoint: Duplicate CMS Page
 *
 * POST /crm/api/duplicate-page.php
 * Copies a page and all its blocks as drafts.
 * The new page gets slug "{original-slug}-copy" (auto-incremented if taken).
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
require_once CRM_INCLUDES . '/cms-functions.php';

header('Content-Type: application/json');

requireLogin();
$user = getCurrentUser();

if (!in_array($user['role'], ['admin', 'staff'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token invalid']);
    exit;
}

$sourcePageId = (int)($_POST['page_id'] ?? 0);
if (!$sourcePageId) {
    echo json_encode(['success' => false, 'error' => 'Page ID required']);
    exit;
}

try {
    $db = getDB();

    // Load source page
    $stmt = $db->prepare('SELECT * FROM cms_pages WHERE id = ? LIMIT 1');
    $stmt->execute([$sourcePageId]);
    $sourcePage = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sourcePage) {
        echo json_encode(['success' => false, 'error' => 'Page not found']);
        exit;
    }

    // Generate unique slug: "{slug}-copy", "{slug}-copy-2", etc.
    $baseSlug = rtrim($sourcePage['slug'], '/') . '-copy';
    $newSlug  = $baseSlug;
    $counter  = 2;
    while (true) {
        $chk = $db->prepare('SELECT id FROM cms_pages WHERE slug = ? LIMIT 1');
        $chk->execute([$newSlug]);
        if (!$chk->fetch()) {
            break;
        }
        $newSlug = $baseSlug . '-' . $counter++;
    }

    // Insert new page as draft
    $ins = $db->prepare('
        INSERT INTO cms_pages
            (slug, title, meta_title, meta_description, meta_keywords, page_type,
             layout_template, status, noindex, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ');
    $ins->execute([
        $newSlug,
        'Copy of ' . $sourcePage['title'],
        $sourcePage['meta_title']       ? 'Copy of ' . $sourcePage['meta_title'] : '',
        $sourcePage['meta_description'] ?? '',
        $sourcePage['meta_keywords']    ?? '',
        $sourcePage['page_type']        ?? 'custom',
        $sourcePage['layout_template']  ?? 'default',
        'draft',  // always start as draft
        (int)($sourcePage['noindex'] ?? 0),
        $user['id'],
    ]);
    $newPageId = (int)$db->lastInsertId();

    // Copy all blocks
    $blocks = $db->prepare('
        SELECT block_type, label, config, sort_order, is_visible
        FROM cms_blocks
        WHERE page_id = ?
        ORDER BY sort_order ASC
    ');
    $blocks->execute([$sourcePageId]);
    $sourceBlocks = $blocks->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($sourceBlocks)) {
        $insBlock = $db->prepare('
            INSERT INTO cms_blocks
                (page_id, block_type, label, config, sort_order, is_visible, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
        ');
        foreach ($sourceBlocks as $blk) {
            $insBlock->execute([
                $newPageId,
                $blk['block_type'],
                $blk['label'],
                $blk['config'],
                $blk['sort_order'],
                $blk['is_visible'],
            ]);
        }
    }

    // Audit log
    cms_logCmsActivity(
        $user['id'],
        'page_created',
        "Duplicated page #{$sourcePageId} → new page #{$newPageId} \"{$newSlug}\"",
        ['source_page_id' => $sourcePageId, 'new_page_id' => $newPageId]
    );

    echo json_encode([
        'success'   => true,
        'page_id'   => $newPageId,
        'slug'      => $newSlug,
        'edit_url'  => '/crm/cms-page-editor.php?id=' . $newPageId,
        'message'   => 'Page duplicated as draft: ' . $newSlug,
    ]);

} catch (PDOException $e) {
    error_log('duplicate-page.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}
