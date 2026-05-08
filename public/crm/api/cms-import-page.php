<?php
/**
 * CMS Page Import API — P3-I
 * POST /crm/api/cms-import-page.php
 * Multipart: csrf_token, json_file (uploaded), overwrite_id (optional — existing page ID to overwrite)
 * Returns: { success: true, page_id: N, redirect: "/crm/cms/cms-page-edit.php?id=N" }
 */

declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/cms-functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

requireLogin();
requirePermission('admin');
$user = getCurrentUser();
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token invalid']);
    exit;
}
session_write_close();

try {
    // Read uploaded JSON
    if (empty($_FILES['json_file']) || $_FILES['json_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No valid file uploaded');
    }

    $raw = file_get_contents($_FILES['json_file']['tmp_name']);
    $data = json_decode($raw, true);

    if (!is_array($data) || empty($data['_mowology_cms_export'])) {
        throw new Exception('Invalid export file format — not a Mowology CMS export');
    }

    $pageData = $data['page'] ?? [];
    $blocks   = $data['blocks'] ?? [];
    $tokens   = $data['tokens'] ?? [];

    if (empty($pageData['slug']) || empty($pageData['title'])) {
        throw new Exception('Export file is missing required page fields (slug, title)');
    }

    $overwriteId = (int)($_POST['overwrite_id'] ?? 0);
    $db = getDB();

    if ($overwriteId > 0) {
        // Overwrite existing page — just update settings and replace blocks
        $existing = cms_getPageById($overwriteId);
        if (!$existing) throw new Exception('Target page ID ' . $overwriteId . ' not found');

        // Preserve slug of the overwrite target
        $pageData['slug']   = $existing['slug'];
        $pageData['status'] = $existing['status']; // keep existing status
        $pageId = cms_savePage($pageData, $overwriteId, $user['id']);

        // Delete all existing blocks
        $db->prepare("DELETE FROM cms_blocks WHERE page_id = ?")->execute([$pageId]);
    } else {
        // Create new page — handle slug collision by appending suffix
        $baseSlug = $pageData['slug'];
        $suffix = 1;
        while (true) {
            try {
                $pageData['status'] = 'draft'; // always import as draft
                $pageId = cms_savePage($pageData, null, $user['id']);
                break;
            } catch (Exception $e) {
                if (str_contains($e->getMessage(), 'already in use') && $suffix <= 10) {
                    $pageData['slug'] = $baseSlug . '-' . $suffix++;
                } else {
                    throw $e;
                }
            }
        }
    }

    // Insert blocks
    $stmtBlock = $db->prepare("
        INSERT INTO cms_blocks (page_id, block_type, position, config_json, created_at, updated_at)
        VALUES (?, ?, ?, ?, NOW(), NOW())
    ");
    foreach ($blocks as $pos => $block) {
        $stmtBlock->execute([
            $pageId,
            $block['block_type'],
            (int)($block['position'] ?? $pos),
            json_encode($block['config'] ?? [], JSON_UNESCAPED_SLASHES),
        ]);
    }

    // Save tokens
    if (!empty($tokens)) {
        cms_savePageTokens($pageId, $tokens);
    }

    // Invalidate cache
    cms_invalidatePageHtmlCache($pageId);

    echo json_encode([
        'success'  => true,
        'page_id'  => $pageId,
        'slug'     => $pageData['slug'],
        'redirect' => '/crm/cms/cms-page-edit.php?id=' . $pageId . '&saved=' . urlencode('Page imported successfully.'),
    ]);

} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
