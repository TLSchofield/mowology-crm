<?php
/**
 * Sam's pipeline stage sweep — admin only.
 *
 *   GET  /crm/api/pipeline-sweep.php?mode=dry_run[&limit=N]
 *        What the sweep WOULD change: counts by from→to and up to 30 sample record ids per
 *        transition (ids only, no names). Writes nothing.
 *   POST /crm/api/pipeline-sweep.php?mode=run   (csrf_token in the body or X-CSRF-Token header)
 *        Runs the sweep for real and records it on the Cron Jobs tab.
 *
 * ?mode=, never ?action= (see the /api/ router collision note).
 */
declare(strict_types=1);

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
if (!isAdmin()) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Admin only']); exit; }

require_once APP_ROOT . '/Modules/Sales/Services/PipelineStageService.php';

$mode = (string)($_GET['mode'] ?? 'dry_run');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($mode === 'run') {
    if ($method !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'error' => 'POST required']); exit; }
    $token = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!verifyCSRFToken($token)) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']); exit; }
} elseif ($mode !== 'dry_run') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'mode must be dry_run or run']);
    exit;
}

$dryRun = ($mode !== 'run');
$limit = isset($_GET['limit']) && (int)$_GET['limit'] > 0 ? (int)$_GET['limit'] : null;

try {
    @set_time_limit(300);
    $start = (int)(microtime(true) * 1000);
    $result = (new PipelineStageService(getDB()))->sweep($limit, $dryRun);
    $summary = ($dryRun ? '[dry run] ' : '') . PipelineStageService::summarize($result);
    if (!$dryRun && function_exists('recordCronRun')) {
        recordCronRun('pipeline_sweep', 'success', $summary, (int)(microtime(true) * 1000) - $start, null, true);
    }
    echo json_encode(['success' => true, 'mode' => $mode, 'summary' => $summary] + $result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[pipeline-sweep api] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Sweep failed — see the PHP error log']);
}
