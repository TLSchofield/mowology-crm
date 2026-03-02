<?php
/**
 * CMS Page Export API — P3-I
 * GET /crm/api/cms-export-page.php?id=X
 * Returns: JSON download of page settings + all blocks for backup/staging transfer.
 */

declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/cms-functions.php';

requireLogin();
requirePermission('admin');
session_write_close();

$pageId = (int)($_GET['id'] ?? 0);
if (!$pageId) {
    http_response_code(400);
    echo json_encode(['error' => 'id required']);
    exit;
}

try {
    $page = cms_getPageById($pageId);
    if (!$page) {
        http_response_code(404);
        echo json_encode(['error' => 'Page not found']);
        exit;
    }

    $blocks   = cms_getBlocksByPageId($pageId, 0);
    $tokens   = cms_getPageTokens($pageId);

    // Strip internal fields from the export (IDs, timestamps that shouldn't be blindly imported)
    $exportBlocks = array_map(function(array $b): array {
        return [
            'block_type' => $b['block_type'],
            'position'   => $b['position'],
            'config'     => $b['config'] ?? [],
        ];
    }, $blocks);

    $export = [
        '_mowology_cms_export' => true,
        '_version'             => '1.0',
        '_exported_at'         => date('c'),
        '_source_id'           => $pageId,
        'page' => [
            'slug'             => $page['slug'],
            'title'            => $page['title'],
            'meta_title'       => $page['meta_title'],
            'meta_description' => $page['meta_description'],
            'meta_keywords'    => $page['meta_keywords'],
            'og_image_path'    => $page['og_image_path'],
            'page_type'        => $page['page_type'],
            'layout_template'  => $page['layout_template'],
            'noindex'          => (int)($page['noindex'] ?? 0),
            'canonical_url'    => $page['canonical_url'] ?? null,
        ],
        'blocks' => $exportBlocks,
        'tokens' => $tokens,
    ];

    $filename = 'cms-page-' . preg_replace('/[^a-z0-9\-]/', '-', strtolower($page['slug'])) . '-' . date('Ymd') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
