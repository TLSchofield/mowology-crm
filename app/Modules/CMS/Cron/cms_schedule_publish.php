<?php
/**
 * CMS Content Scheduling Cron (P2-G)
 *
 * Auto-publishes pages where publish_at <= NOW() and status='draft'
 * Auto-archives pages where unpublish_at <= NOW() and status='published'
 * Invalidates HTML cache for every affected page.
 *
 * Supports CLI + Web POST (admin-only).
 *
 * CLI:  php cms_schedule_publish.php
 * Web:  POST /crm/cron/cms-schedule-publish.php
 *
 * Recommended cron: * * * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/CMS/Cron/cms_schedule_publish.php
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

set_error_handler(function(int $severity, string $message, string $file, int $line): bool {
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

$isCli   = (php_sapi_name() === 'cli');
$fromWeb = !$isCli;
$startMs = (int) round(microtime(true) * 1000);
$cronStatus = 'success';
$cronError  = null;

if (!$isCli) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    requireLogin();
    $user = getCurrentUser();
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        exit;
    }
    header('Content-Type: application/json');
} else {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
}

require_once CRM_INCLUDES . '/cms-functions.php';

$result = [
    'published' => [],
    'archived'  => [],
    'errors'    => [],
    'ran_at'    => date('Y-m-d H:i:s'),
];

try {
    $db = getDB();

    // ── Auto-publish: draft pages whose publish_at has arrived ────────────────
    $toPublish = $db->query("
        SELECT id, slug, title
        FROM cms_pages
        WHERE status = 'draft'
          AND publish_at IS NOT NULL
          AND publish_at <= NOW()
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($toPublish as $pg) {
        try {
            $db->prepare("
                UPDATE cms_pages
                SET status = 'published', updated_at = NOW()
                WHERE id = ? AND status = 'draft'
            ")->execute([(int)$pg['id']]);

            cms_invalidatePageHtmlCache((int)$pg['id']);

            cms_logCmsActivity(
                0,
                'page_published',
                "Scheduled publish: '{$pg['title']}' (/{$pg['slug']})",
                ['page_id' => (int)$pg['id'], 'source' => 'cron']
            );

            $result['published'][] = ['id' => (int)$pg['id'], 'slug' => $pg['slug'], 'title' => $pg['title']];
        } catch (\Throwable $e) {
            $result['errors'][] = "Publish page #{$pg['id']}: " . $e->getMessage();
        }
    }

    // ── Auto-archive: published pages whose unpublish_at has passed ───────────
    $toArchive = $db->query("
        SELECT id, slug, title
        FROM cms_pages
        WHERE status = 'published'
          AND unpublish_at IS NOT NULL
          AND unpublish_at <= NOW()
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($toArchive as $pg) {
        try {
            $db->prepare("
                UPDATE cms_pages
                SET status = 'archived', updated_at = NOW()
                WHERE id = ? AND status = 'published'
            ")->execute([(int)$pg['id']]);

            cms_invalidatePageHtmlCache((int)$pg['id']);

            cms_logCmsActivity(
                0,
                'page_archived',
                "Scheduled unpublish: '{$pg['title']}' (/{$pg['slug']})",
                ['page_id' => (int)$pg['id'], 'source' => 'cron']
            );

            $result['archived'][] = ['id' => (int)$pg['id'], 'slug' => $pg['slug'], 'title' => $pg['title']];
        } catch (\Throwable $e) {
            $result['errors'][] = "Archive page #{$pg['id']}: " . $e->getMessage();
        }
    }

    $result['success'] = true;
    $result['summary'] = sprintf(
        'Published: %d, Archived: %d, Errors: %d',
        count($result['published']),
        count($result['archived']),
        count($result['errors'])
    );

} catch (\Throwable $e) {
    $result['success'] = false;
    $result['error']   = $e->getMessage();
    $cronStatus = 'error';
    $cronError  = $e->getMessage();
    error_log('[cms_schedule_publish] Fatal: ' . $e->getMessage());
}

$durationMs = (int) round(microtime(true) * 1000) - $startMs;
$published  = count($result['published'] ?? []);
$archived   = count($result['archived']  ?? []);
recordCronRun(
    'cms_schedule_publish',
    $cronStatus,
    "Published: {$published}, Archived: {$archived}, Errors: " . count($result['errors'] ?? []),
    $durationMs,
    $cronError,
    $fromWeb
);

if ($isCli) {
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
} else {
    echo json_encode($result);
}
