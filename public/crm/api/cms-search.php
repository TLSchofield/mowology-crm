<?php
/**
 * CMS Full-Text Search API (P2-H)
 * GET /crm/api/cms-search.php?q=search+terms[&limit=10][&status=published]
 * Returns: { success: true, results: [...], total: N, query: "..." }
 */

declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/cms-functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

try {
    requireLogin();
    session_write_close(); // Release session lock — read-only endpoint

    $q      = trim($_GET['q'] ?? '');
    $limit  = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    $status = $_GET['status'] ?? 'all'; // all | published | draft

    if (mb_strlen($q) < 2) {
        echo json_encode(['success' => true, 'results' => [], 'total' => 0, 'query' => $q]);
        exit;
    }

    $db = getDB();

    // Build WHERE clause
    $conditions = ['1=1'];
    $params     = [];

    if ($status === 'published') {
        $conditions[] = "status = 'published'";
    } elseif ($status === 'draft') {
        $conditions[] = "status = 'draft'";
    }

    // Try FULLTEXT search first, fall back to LIKE
    $useFulltext = false;
    try {
        // Test if FULLTEXT index exists by running MATCH … AGAINST on a tiny set
        $test = $db->query("
            SELECT COUNT(*) FROM cms_pages
            WHERE MATCH(title, meta_description) AGAINST('test' IN BOOLEAN MODE)
            LIMIT 1
        ");
        $useFulltext = true;
    } catch (\Throwable $e) {
        $useFulltext = false;
    }

    if ($useFulltext) {
        $conditions[] = "MATCH(title, meta_description, meta_keywords) AGAINST(? IN BOOLEAN MODE)";
        $params[]     = '+' . implode('* +', array_filter(explode(' ', $q))) . '*';
    } else {
        $likeQ        = '%' . $q . '%';
        $conditions[] = "(title LIKE ? OR meta_description LIKE ? OR slug LIKE ?)";
        $params       = array_merge($params, [$likeQ, $likeQ, $likeQ]);
    }

    $where = implode(' AND ', $conditions);
    $sql   = "
        SELECT id, slug, title, meta_title, meta_description, page_type, status,
               view_count, updated_at
        FROM cms_pages
        WHERE {$where}
        ORDER BY
            CASE status WHEN 'published' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END,
            view_count DESC
        LIMIT ?
    ";
    $params[] = $limit;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Add edit/view URLs + excerpt
    $siteUrl = defined('SITE_URL') ? SITE_URL : 'https://mowology.ca';
    $results = array_map(function(array $row) use ($siteUrl, $q): array {
        $desc = $row['meta_description'] ?? '';
        if ($desc && mb_strlen($desc) > 140) {
            // Highlight search query in excerpt
            $desc = mb_substr($desc, 0, 140) . '…';
        }
        return [
            'id'          => (int)$row['id'],
            'slug'        => $row['slug'],
            'title'       => $row['title'],
            'meta_title'  => $row['meta_title'] ?? $row['title'],
            'excerpt'     => $desc,
            'page_type'   => $row['page_type'],
            'status'      => $row['status'],
            'views'       => (int)($row['view_count'] ?? 0),
            'updated_at'  => $row['updated_at'],
            'edit_url'    => '/crm/cms/cms-page-edit.php?id=' . (int)$row['id'],
            'view_url'    => $row['status'] === 'published' ? $siteUrl . '/' . ltrim($row['slug'], '/') : null,
        ];
    }, $rows);

    echo json_encode([
        'success' => true,
        'query'   => $q,
        'total'   => count($results),
        'engine'  => $useFulltext ? 'fulltext' : 'like',
        'results' => $results,
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
