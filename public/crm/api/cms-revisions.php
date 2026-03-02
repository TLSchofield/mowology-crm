<?php
/**
 * CMS Revisions API (P3-A)
 * GET  /crm/api/cms-revisions.php?page_id=X[&limit=20]
 * Returns: { success: true, revisions: [...] }
 */

declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/cms-functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

try {
    requireLogin();
    session_write_close(); // Read-only endpoint — release session lock

    $pageId = (int)($_GET['page_id'] ?? 0);
    $limit  = max(1, min(50, (int)($_GET['limit'] ?? 20)));

    if (!$pageId) {
        echo json_encode(['success' => false, 'error' => 'page_id required']);
        exit;
    }

    $revisions = cms_getPageRevisions($pageId, $limit);

    // Enrich with user display name where available
    $db = getDB();
    $userIds = array_filter(array_unique(array_column($revisions, 'created_by')));
    $userNames = [];
    if (!empty($userIds)) {
        $phs  = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $db->prepare("SELECT id, full_name FROM users WHERE id IN ($phs)");
        $stmt->execute(array_values($userIds));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $userNames[(int)$u['id']] = $u['full_name'];
        }
    }

    $result = array_map(function(array $rev) use ($userNames): array {
        $ts = strtotime($rev['created_at']);
        return [
            'id'               => (int)$rev['id'],
            'page_id'          => (int)$rev['page_id'],
            'title'            => $rev['title'] ?? '',
            'revision_type'    => $rev['revision_type'] ?? 'draft',
            'revision_message' => $rev['revision_message'] ?? '',
            'created_by'       => (int)$rev['created_by'],
            'created_by_name'  => $userNames[(int)$rev['created_by']] ?? 'Unknown',
            'created_at'       => $rev['created_at'],
            'created_at_human' => $ts ? date('M j, Y g:i A', $ts) : '',
            'created_at_ago'   => $ts ? cms_timeAgo($ts) : '',
        ];
    }, $revisions);

    echo json_encode([
        'success'   => true,
        'page_id'   => $pageId,
        'total'     => count($result),
        'revisions' => $result,
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Simple human-readable time-ago helper (local to this file).
 */
function cms_timeAgo(int $timestamp): string
{
    $diff = time() - $timestamp;
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return (int)($diff / 60) . 'm ago';
    if ($diff < 86400) return (int)($diff / 3600) . 'h ago';
    if ($diff < 604800) return (int)($diff / 86400) . 'd ago';
    return date('M j', $timestamp);
}
