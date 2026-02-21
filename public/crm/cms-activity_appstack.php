<?php
/**
 * CMS Activity Log
 *
 * Displays a filterable, paginated log of all CMS actions
 * (page saves, block edits, media updates, etc.)
 *
 * @package Mowology CRM
 * @subpackage CMS
 */

declare(strict_types=1);

require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/cms-functions.php';

requireLogin();
$user = getCurrentUser();

// Admin-only view
if (!in_array($user['role'], ['admin'])) {
    http_response_code(403);
    die('Access denied');
}

$pageTitle = 'CMS Activity Log';
$activePage = 'cms';

$db = getDB();

// -------------------------------------------------------------------------
// Detect activity_log column names (Schema A vs B)
// -------------------------------------------------------------------------
$logCols = [];
try {
    $colCheck = $db->query("SHOW COLUMNS FROM activity_log");
    if ($colCheck) {
        $logCols = array_column($colCheck->fetchAll(PDO::FETCH_ASSOC), 'Field');
    }
} catch (PDOException $e) {
    $logCols = [];
}

$hasSchemaB   = in_array('action_type', $logCols);
$actionCol    = $hasSchemaB ? 'action_type' : 'action';
$textCol      = $hasSchemaB ? 'notes' : (in_array('details', $logCols) ? 'details' : (in_array('description', $logCols) ? 'description' : null));

// -------------------------------------------------------------------------
// Filters
// -------------------------------------------------------------------------
$filterAction = trim($_GET['action'] ?? '');
$filterUser   = (int)($_GET['user_id'] ?? 0);
$page         = max(1, (int)($_GET['p'] ?? 1));
$perPage      = 50;
$offset       = ($page - 1) * $perPage;

// -------------------------------------------------------------------------
// Build query
// -------------------------------------------------------------------------
$where  = ["l.{$actionCol} LIKE 'page_%' OR l.{$actionCol} LIKE 'block_%' OR l.{$actionCol} LIKE 'media_%'"];
$params = [];

if ($filterAction !== '') {
    $where[] = "l.{$actionCol} = ?";
    $params[] = $filterAction;
}
if ($filterUser > 0) {
    $where[] = 'l.user_id = ?';
    $params[] = $filterUser;
}

$whereSQL = 'WHERE (' . implode(') AND (', $where) . ')';

// Total count
$countSQL = "SELECT COUNT(*) FROM activity_log l $whereSQL";
$total = 0;
try {
    $cStmt = $db->prepare($countSQL);
    $cStmt->execute($params);
    $total = (int)$cStmt->fetchColumn();
} catch (PDOException $e) {
    // table may not have CMS rows yet
}

// Fetch rows
$textSelect = $textCol ? "l.{$textCol} AS summary," : "'—' AS summary,";
$rows = [];
try {
    $rowSQL = "
        SELECT
            l.id,
            l.{$actionCol} AS action,
            $textSelect
            l.user_id,
            l.created_at,
            u.full_name AS user_name
        FROM activity_log l
        LEFT JOIN users u ON u.id = l.user_id
        $whereSQL
        ORDER BY l.created_at DESC
        LIMIT $perPage OFFSET $offset
    ";
    $rStmt = $db->prepare($rowSQL);
    $rStmt->execute($params);
    $rows = $rStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // silently skip
}

// Distinct actions for filter dropdown
$distinctActions = [];
try {
    $daStmt = $db->query("
        SELECT DISTINCT {$actionCol} AS a
        FROM activity_log
        WHERE {$actionCol} LIKE 'page_%'
           OR {$actionCol} LIKE 'block_%'
           OR {$actionCol} LIKE 'media_%'
        ORDER BY a
    ");
    $distinctActions = $daStmt ? array_column($daStmt->fetchAll(PDO::FETCH_ASSOC), 'a') : [];
} catch (PDOException $e) {
    $distinctActions = [];
}

// Staff list for filter
$staffList = [];
try {
    $sStmt = $db->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name");
    $staffList = $sStmt ? $sStmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (PDOException $e) {
    $staffList = [];
}

$totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;

// -------------------------------------------------------------------------
// Action badge helper
// -------------------------------------------------------------------------
function cmsActivityBadge(string $action): string
{
    static $map = [
        'page_created'  => ['success', 'Created'],
        'page_updated'  => ['primary', 'Updated'],
        'page_deleted'  => ['danger',  'Deleted'],
        'block_updated' => ['info',    'Block saved'],
        'block_deleted' => ['warning', 'Block deleted'],
        'media_updated' => ['secondary','Media saved'],
        'media_deleted' => ['danger',  'Media deleted'],
        'preset_deleted'=> ['warning', 'Preset deleted'],
    ];
    [$cls, $label] = $map[$action] ?? ['light', $action];
    return '<span class="badge badge-' . $cls . '">' . h($label) . '</span>';
}

?>
<?php include __DIR__ . '/includes/appstack_head.php'; ?>

<div class="container-fluid p-0">

    <!-- Page header -->
    <div class="mw-page-header d-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h3 mb-1">CMS Activity Log</h1>
            <p class="text-muted mb-0">All content edits across pages, blocks, and media.</p>
        </div>
        <a href="/crm/cms-pages_appstack.php" class="btn btn-outline-secondary">
            <i data-feather="arrow-left" class="feather-sm mr-1"></i> Back to Pages
        </a>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="form-inline">
                <label class="mr-2 text-muted" for="filterAction">Action:</label>
                <select name="action" id="filterAction" class="form-control form-control-sm mr-3">
                    <option value="">All actions</option>
                    <?php foreach ($distinctActions as $da): ?>
                        <option value="<?php echo h($da); ?>"
                            <?php echo $filterAction === $da ? 'selected' : ''; ?>>
                            <?php echo h($da); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label class="mr-2 text-muted" for="filterUser">User:</label>
                <select name="user_id" id="filterUser" class="form-control form-control-sm mr-3">
                    <option value="0">All users</option>
                    <?php foreach ($staffList as $s): ?>
                        <option value="<?php echo (int)$s['id']; ?>"
                            <?php echo $filterUser === (int)$s['id'] ? 'selected' : ''; ?>>
                            <?php echo h($s['full_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="btn btn-primary btn-sm mr-2">Filter</button>
                <a href="/crm/cms-activity_appstack.php" class="btn btn-outline-secondary btn-sm">Clear</a>

                <span class="ml-auto text-muted small">
                    <?php echo number_format($total); ?> record<?php echo $total !== 1 ? 's' : ''; ?>
                </span>
            </form>
        </div>
    </div>

    <!-- Log table -->
    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($rows)): ?>
                <div class="text-center py-5 text-muted">
                    <i data-feather="activity" style="width:48px;height:48px;opacity:.3;"></i>
                    <p class="mt-3">No activity records yet.</p>
                    <?php if ($filterAction !== '' || $filterUser > 0): ?>
                        <a href="/crm/cms-activity_appstack.php" class="btn btn-sm btn-outline-secondary">Clear filters</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th style="width:160px;">When</th>
                                <th style="width:150px;">Action</th>
                                <th>Summary</th>
                                <th style="width:150px;">User</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td class="text-nowrap text-muted small">
                                        <?php echo date('M j, Y', strtotime($r['created_at'])); ?><br>
                                        <span class="text-muted"><?php echo date('g:i A', strtotime($r['created_at'])); ?></span>
                                    </td>
                                    <td><?php echo cmsActivityBadge((string)($r['action'] ?? '')); ?></td>
                                    <td class="small"><?php echo h((string)($r['summary'] ?? '—')); ?></td>
                                    <td class="small text-muted"><?php echo h((string)($r['user_name'] ?? 'System')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="d-flex justify-content-center py-3">
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?p=<?php echo $page - 1; ?>&action=<?php echo h(urlencode($filterAction)); ?>&user_id=<?php echo $filterUser; ?>">&laquo;</a>
                                    </li>
                                <?php endif; ?>
                                <?php
                                $start = max(1, $page - 3);
                                $end   = min($totalPages, $page + 3);
                                for ($i = $start; $i <= $end; $i++):
                                ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?p=<?php echo $i; ?>&action=<?php echo h(urlencode($filterAction)); ?>&user_id=<?php echo $filterUser; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <?php if ($page < $totalPages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?p=<?php echo $page + 1; ?>&action=<?php echo h(urlencode($filterAction)); ?>&user_id=<?php echo $filterUser; ?>">&raquo;</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php include __DIR__ . '/includes/appstack_footer.php'; ?>
