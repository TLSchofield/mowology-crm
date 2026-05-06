<?php
/**
 * CMS HTML Cache Flush API (P1-B / P1-G)
 * POST /crm/api/cms-flush-cache.php
 * Returns: { success: true, count: N }
 */

declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/cms-functions.php';

header('Content-Type: application/json');

try {
    requireLogin();
    $user = getCurrentUser();

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        throw new \RuntimeException('Invalid CSRF token');
    }
    session_write_close();

    // Count before truncate
    $db = getDB();
    $count = (int)$db->query("SELECT COUNT(*) FROM cms_page_cache")->fetchColumn();

    cms_invalidateAllPageHtmlCache();

    cms_logCmsActivity(
        (int)($user['id'] ?? 0),
        'cache_flushed',
        "HTML page cache flushed ({$count} pages) by {$user['full_name']}",
        ['count' => $count]
    );

    echo json_encode(['success' => true, 'count' => $count]);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
