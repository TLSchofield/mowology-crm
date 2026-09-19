<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/quotes.php
 *
 * Mobile Quotes API — JWT authenticated, admin/manager only.
 *
 * GET /api/schedule/quotes
 *   ?status=pending_review|sent|approved|rejected|all  (default: all)
 *   ?limit=20  (max 100)
 *   ?offset=0
 *
 * Crew-only users receive an empty list (quotes are admin-managed).
 *
 * Response 200:
 * {
 *   "success": true,
 *   "total": 12,
 *   "quotes": [
 *     {
 *       "quote_id": 1,
 *       "quote_number": "QUO-2026-0001",
 *       "client_name": "Bob Jones",
 *       "status": "sent",
 *       "total_amount": 350.00,
 *       "valid_until": "2026-06-01",
 *       "created_at": "2026-05-01"
 *     }
 *   ]
 * }
 */

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Core/Auth/JwtAuth.php';

$jwtUser = requireJwt();
$isAdmin = jwtIsAdmin($jwtUser['role']);

// Non-admin crew members don't manage quotes
if (!$isAdmin) {
    echo json_encode(['success' => true, 'total' => 0, 'quotes' => []]);
    exit;
}

$validStatuses = ['pending_review', 'draft', 'sent', 'approved', 'rejected', 'expired', 'all'];
$statusFilter  = $_GET['status'] ?? 'all';
if (!in_array($statusFilter, $validStatuses, true)) $statusFilter = 'all';

$limit  = min(100, max(1, (int)($_GET['limit']  ?? 20)));
$offset = max(0,        (int)($_GET['offset'] ?? 0));

try {
    $db = getDB();

    $where  = [];
    $params = [];

    if ($statusFilter !== 'all') {
        $where[]  = 'q.status = ?';
        $params[] = $statusFilter;
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $db->prepare("SELECT COUNT(*) FROM quotes q {$whereSQL}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $params[] = $limit;
    $params[] = $offset;

    $stmt = $db->prepare("
        SELECT
            q.id           AS quote_id,
            q.quote_number,
            q.status,
            q.total_amount,
            q.valid_until,
            DATE(q.created_at) AS created_at,
            COALESCE(co.full_name, co.first_name, 'Unknown') AS client_name
        FROM quotes q
        LEFT JOIN contacts co ON q.contact_id = co.id
        {$whereSQL}
        ORDER BY q.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $quotes = array_map(static function (array $r): array {
        return [
            'quote_id'     => (int)$r['quote_id'],
            'quote_number' => $r['quote_number'],
            'client_name'  => $r['client_name'],
            'status'       => $r['status'],
            'total_amount' => $r['total_amount'] ? (float)$r['total_amount'] : null,
            'valid_until'  => $r['valid_until'],
            'created_at'   => $r['created_at'],
        ];
    }, $rows);

    echo json_encode(['success' => true, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'quotes' => $quotes]);

} catch (Throwable $e) {
    error_log('[schedule/quotes] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load quotes.']);
}
