<?php
/**
 * API Endpoint: Restore Page from Revision
 *
 * POST /crm/api/restore-revision.php
 * Body: csrf_token, revision_id
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

$revisionId = (int)($_POST['revision_id'] ?? 0);
if (!$revisionId) {
    echo json_encode(['success' => false, 'error' => 'Revision ID required']);
    exit;
}

try {
    $ok = cms_restorePageFromRevision($revisionId, $user['id']);

    cms_logCmsActivity(
        $user['id'],
        'page_updated',
        "Restored page from revision #{$revisionId}",
        ['revision_id' => $revisionId]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Page restored from revision #' . $revisionId,
    ]);
} catch (Exception $e) {
    error_log('restore-revision.php error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
