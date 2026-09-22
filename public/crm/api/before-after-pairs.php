<?php
/**
 * API: Before & After Pairs
 *
 * GET  ?limit=6          — fetch published pairs for public module
 * GET  ?limit=100&admin=1 — fetch all pairs for admin panel (requires login)
 *
 * Returns JSON with pairs[] array, each item containing:
 *   id, before_id, after_id, before_url, after_url,
 *   before_thumb, after_thumb, label, service, category,
 *   date, published, sort_order, crew
 */

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

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$isAdmin = isset($_GET['admin']) && $_GET['admin'] === '1' && isLoggedIn();
$limit   = min((int)($_GET['limit'] ?? 6), 100);

try {
    $db = getDB();

    if (!$isAdmin) {
        // Public feed: approved pairs, public fields only — see BeforeAfterService::publicShape().
        require_once APP_ROOT . '/Modules/Portfolio/Services/BeforeAfterService.php';
        $pairs = (new BeforeAfterService($db))->published($limit);
        echo json_encode([
            'success' => true,
            'pairs'   => $pairs,
            'total'   => count($pairs),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    // Admin panel: every pair (pending ones included) — the manager page's list shows status.
    $stmt  = $db->prepare("
        SELECT
            p.id,
            p.before_id,
            p.after_id,
            p.label,
            p.service,
            p.category,
            p.area,
            p.published,
            p.status,
            p.consent_state,
            p.sort_order,
            p.crew,
            p.created_at,
            mb.file_path AS before_url,
            ma.file_path AS after_url,
            DATE_FORMAT(COALESCE(p.approved_at, p.updated_at), '%M %Y') AS date
        FROM  ba_pairs p
        JOIN  media_assets mb ON mb.id = p.before_id
        JOIN  media_assets ma ON ma.id = p.after_id
        WHERE p.status <> 'pending'
        ORDER BY p.sort_order ASC, p.id DESC
        LIMIT " . (int)$limit . "
    ");
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pairs = array_map(function ($row) {
        return [
            'id'           => (int)$row['id'],
            'before_id'    => (int)$row['before_id'],
            'after_id'     => (int)$row['after_id'],
            'before_url'   => $row['before_url'],
            'after_url'    => $row['after_url'],
            'before_thumb' => $row['before_url'],
            'after_thumb'  => $row['after_url'],
            'label'        => $row['label'],
            'service'      => $row['service'],
            'category'     => $row['category'],
            'area'         => $row['area'] ?? '',
            'published'    => (bool)$row['published'],
            'status'       => $row['status'],
            'consent'      => $row['consent_state'],
            'sort_order'   => (int)$row['sort_order'],
            'crew'         => $row['crew'] ?? '',
            'date'         => $row['date'],
        ];
    }, $rows);

    echo json_encode([
        'success' => true,
        'pairs'   => $pairs,
        'total'   => count($pairs),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Server error',
        'pairs'   => [],
    ]);
}
