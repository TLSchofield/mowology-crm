<?php
/**
 * /site-admin/api/sections.php
 * Section CRUD AJAX endpoint — JSON responses.
 *
 * Actions (POST):
 *   create  — create a new section
 *   update  — update section data
 *   delete  — soft-delete a section
 *   reorder — reorder sections by ID array
 *
 * Actions (GET):
 *   list    — list sections for a page
 */
declare(strict_types=1);

header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/auth.php';

if (!isSiteLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once dirname(__DIR__, 2) . '/app/Modules/CMS/Services/CmsSectionFunctions.php';

$siteId = (int)CMS_SITE_ID;
$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {

        // ── List sections for a page ──────────────────────────────────────────
        case 'list': {
            $pageId = (int)($_GET['page_id'] ?? 0);
            if (!$pageId) throw new \InvalidArgumentException('page_id required');
            $sections = cms_getPageSections($pageId, $siteId);
            echo json_encode(['ok' => true, 'sections' => $sections]);
            break;
        }

        // ── Create a section ──────────────────────────────────────────────────
        case 'create': {
            $input     = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $pageId    = (int)($input['page_id']    ?? 0);
            $blockType = trim($input['block_type']  ?? '');
            $data      = $input['data']             ?? [];
            $sortOrder = (int)($input['sort_order'] ?? 0);

            if (!$pageId)    throw new \InvalidArgumentException('page_id required');
            if (!$blockType) throw new \InvalidArgumentException('block_type required');

            // Validate block type exists
            $types = cms_getSectionBlockTypes();
            if (!isset($types[$blockType])) {
                throw new \InvalidArgumentException('Unknown block_type: ' . $blockType);
            }

            // Merge with defaults
            $defaults = $types[$blockType]['defaults'] ?? [];
            $merged   = array_merge($defaults, $data);

            $id = cms_createSection($pageId, $siteId, $blockType, $merged, $sortOrder);
            $section = cms_getSectionById($id, $siteId);
            echo json_encode(['ok' => true, 'id' => $id, 'section' => $section]);
            break;
        }

        // ── Update a section ──────────────────────────────────────────────────
        case 'update': {
            $input  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $id     = (int)($input['id']   ?? 0);
            $data   = $input['data']        ?? [];

            if (!$id) throw new \InvalidArgumentException('id required');

            $ok = cms_updateSection($id, $siteId, $data);
            echo json_encode(['ok' => $ok]);
            break;
        }

        // ── Delete a section ──────────────────────────────────────────────────
        case 'delete': {
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $id    = (int)($input['id'] ?? $_POST['id'] ?? 0);
            if (!$id) throw new \InvalidArgumentException('id required');
            $ok = cms_deleteSection($id, $siteId);
            echo json_encode(['ok' => $ok]);
            break;
        }

        // ── Reorder sections ──────────────────────────────────────────────────
        case 'reorder': {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $ids   = $input['ids'] ?? [];
            if (empty($ids)) throw new \InvalidArgumentException('ids array required');
            $ok = cms_reorderSections(array_map('intval', $ids), $siteId);
            echo json_encode(['ok' => $ok]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . $action]);
    }

} catch (\Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
