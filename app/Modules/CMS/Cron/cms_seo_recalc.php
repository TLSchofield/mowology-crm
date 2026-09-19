<?php
/**
 * CMS SEO Score Auto-Recalculation Cron (P2-D)
 *
 * Iterates all pages, recalculates seo_score via cms_getPageCompletionScore(),
 * and updates the column. Useful after bulk edits or imports.
 *
 * CLI:  php cms_seo_recalc.php
 * Web:  POST /crm/cron/cms-seo-recalc.php  (admin only)
 *
 * Recommended cron: 0 3 * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/CMS/Cron/cms_seo_recalc.php
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
    'updated'  => 0,
    'skipped'  => 0,
    'errors'   => [],
    'ran_at'   => date('Y-m-d H:i:s'),
];

try {
    $db    = getDB();
    $pages = $db->query("SELECT id, title FROM cms_pages ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    $updStmt = $db->prepare("UPDATE cms_pages SET seo_score = ?, updated_at = updated_at WHERE id = ?");

    foreach ($pages as $pg) {
        try {
            if (!function_exists('cms_getPageCompletionScore')) {
                $result['skipped']++;
                continue;
            }
            $score = cms_getPageCompletionScore((int)$pg['id']);
            $updStmt->execute([(int)$score, (int)$pg['id']]);
            $result['updated']++;
        } catch (\Throwable $e) {
            $result['errors'][] = "Page #{$pg['id']} '{$pg['title']}': " . $e->getMessage();
        }
    }

    $result['success'] = true;
    $result['summary'] = "Updated: {$result['updated']}, Skipped: {$result['skipped']}, Errors: " . count($result['errors']);

} catch (\Throwable $e) {
    $result['success'] = false;
    $result['error']   = $e->getMessage();
    $cronStatus = 'error';
    $cronError  = $e->getMessage();
    error_log('[cms_seo_recalc] Fatal: ' . $e->getMessage());
}

$durationMs = (int) round(microtime(true) * 1000) - $startMs;
recordCronRun(
    'cms_seo_recalc',
    $cronStatus,
    "Updated: {$result['updated']}, Skipped: {$result['skipped']}, Errors: " . count($result['errors'] ?? []),
    $durationMs,
    $cronError,
    $fromWeb
);

if ($isCli) {
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
} else {
    echo json_encode($result);
}
