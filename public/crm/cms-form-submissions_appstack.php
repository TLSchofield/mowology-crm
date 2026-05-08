<?php
/**
 * CMS Form Submissions — P3-D
 * View and manage form capture submissions from embedded website forms.
 */

declare(strict_types=1);

require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

$pageTitle  = 'Form Submissions';
$activePage = 'cms';

$db = getDB();

// Filters
$filterFormId = trim($_GET['form_id'] ?? '');
$filterStatus = $_GET['status'] ?? 'all'; // all | new | read

// Handle mark-as-read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    $subId  = (int)($_POST['id'] ?? 0);
    if ($action === 'mark_read' && $subId) {
        $db->prepare("UPDATE cms_form_submissions SET status = 'read', read_at = NOW() WHERE id = ?")->execute([$subId]);
    } elseif ($action === 'delete' && $subId && isAdmin()) {
        $db->prepare("DELETE FROM cms_form_submissions WHERE id = ?")->execute([$subId]);
    }
    header('Location: /crm/cms-form-submissions_appstack.php?' . http_build_query(array_filter(['form_id' => $filterFormId, 'status' => $filterStatus])));
    exit;
}

// Stats
$stats = $db->query("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'new') AS unread,
        COUNT(DISTINCT form_id) AS forms
    FROM cms_form_submissions
")->fetch(PDO::FETCH_ASSOC) ?: [];

// Distinct form IDs for filter dropdown
$formIds = $db->query("SELECT DISTINCT form_id FROM cms_form_submissions ORDER BY form_id")->fetchAll(PDO::FETCH_COLUMN) ?: [];

// Build query
$where  = ['1=1'];
$params = [];
if ($filterFormId) {
    $where[]  = 'form_id = ?';
    $params[] = $filterFormId;
}
if ($filterStatus === 'new') {
    $where[] = "status = 'new'";
} elseif ($filterStatus === 'read') {
    $where[] = "status = 'read'";
}

$whereClause = implode(' AND ', $where);
$submissions = $db->prepare("
    SELECT * FROM cms_form_submissions
    WHERE {$whereClause}
    ORDER BY created_at DESC
    LIMIT 200
");
$submissions->execute($params);
$rows = $submissions->fetchAll(PDO::FETCH_ASSOC) ?: [];

$csrfToken = generateCSRFToken();
?>
<?php include __DIR__ . '/includes/appstack_head.php'; ?>

<!-- CMS Sub-nav -->
<div class="mb-3">
    <ul class="nav nav-pills nav-sm">
        <li class="nav-item"><a class="nav-link" href="/crm/cms-dashboard_appstack.php">Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="/crm/cms-pages_appstack.php">Pages</a></li>
        <li class="nav-item"><a class="nav-link" href="/crm/cms-redirects_appstack.php">Redirects</a></li>
        <li class="nav-item"><a class="nav-link active" href="/crm/cms-form-submissions_appstack.php">Form Submissions</a></li>
    </ul>
</div>

<div class="mw-page-header">
    <div class="mw-page-header-left">
        <h1 class="mw-page-title">Form Submissions</h1>
    </div>
    <?php if (!empty($stats['unread'])): ?>
    <div class="mw-page-actions">
        <span class="badge badge-danger" style="font-size:1rem;"><?php echo (int)$stats['unread']; ?> unread</span>
    </div>
    <?php endif; ?>
</div>

<!-- Stats Strip -->
<div class="row g-3 mb-4">
    <div class="col-4">
        <div class="card text-center py-2">
            <div class="h4 mb-0"><?php echo (int)($stats['total'] ?? 0); ?></div>
            <div class="small text-muted">Total</div>
        </div>
    </div>
    <div class="col-4">
        <div class="card text-center py-2" style="border-top:3px solid #dc3545;">
            <div class="h4 mb-0 text-danger"><?php echo (int)($stats['unread'] ?? 0); ?></div>
            <div class="small text-muted">Unread</div>
        </div>
    </div>
    <div class="col-4">
        <div class="card text-center py-2">
            <div class="h4 mb-0"><?php echo (int)($stats['forms'] ?? 0); ?></div>
            <div class="small text-muted">Forms</div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body py-2">
        <form method="get" class="form-inline">
            <select name="form_id" class="form-control form-control-sm mr-2">
                <option value="">All Forms</option>
                <?php foreach ($formIds as $fid): ?>
                    <option value="<?php echo h($fid); ?>" <?php echo $filterFormId === $fid ? 'selected' : ''; ?>>
                        <?php echo h($fid); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="form-control form-control-sm mr-2">
                <option value="all"  <?php echo $filterStatus === 'all'  ? 'selected' : ''; ?>>All Status</option>
                <option value="new"  <?php echo $filterStatus === 'new'  ? 'selected' : ''; ?>>Unread</option>
                <option value="read" <?php echo $filterStatus === 'read' ? 'selected' : ''; ?>>Read</option>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-secondary">Filter</button>
            <a href="/crm/cms-form-submissions_appstack.php" class="btn btn-sm btn-link ml-2">Clear</a>
        </form>
    </div>
</div>

<!-- Submissions Table -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($rows)): ?>
            <div class="text-center text-muted py-5">
                <i data-feather="inbox" style="width:32px;height:32px;opacity:.3;" class="d-block mx-auto mb-2"></i>
                No submissions found.
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th style="width:140px;">Date</th>
                        <th style="width:90px;">Form</th>
                        <th>Name / Email</th>
                        <th>Message</th>
                        <th style="width:100px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $sub): ?>
                    <tr class="<?php echo ($sub['status'] ?? 'new') === 'new' ? 'table-warning' : ''; ?>">
                        <td class="small text-muted align-middle">
                            <?php echo date('M j, Y', strtotime($sub['created_at'])); ?><br>
                            <?php echo date('g:i A', strtotime($sub['created_at'])); ?>
                        </td>
                        <td class="align-middle">
                            <code class="small"><?php echo h($sub['form_id'] ?? ''); ?></code>
                        </td>
                        <td class="align-middle">
                            <strong><?php echo h($sub['name'] ?? ''); ?></strong><br>
                            <a href="mailto:<?php echo h($sub['email'] ?? ''); ?>" class="small text-muted">
                                <?php echo h($sub['email'] ?? ''); ?>
                            </a>
                            <?php if (!empty($sub['phone'])): ?>
                                <br><span class="small text-muted"><?php echo h($sub['phone']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="align-middle small text-muted" style="max-width:320px;">
                            <?php echo h(mb_substr($sub['message'] ?? '', 0, 120)); ?>
                            <?php if (mb_strlen($sub['message'] ?? '') > 120): ?>&hellip;<?php endif; ?>
                        </td>
                        <td class="align-middle text-right">
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                                <input type="hidden" name="id" value="<?php echo (int)$sub['id']; ?>">
                                <?php if (($sub['status'] ?? 'new') === 'new'): ?>
                                    <button type="submit" name="action" value="mark_read"
                                            class="btn btn-xs btn-outline-success" title="Mark as read">
                                        <i data-feather="check" style="width:12px;height:12px;"></i>
                                    </button>
                                <?php endif; ?>
                                <?php if (isAdmin()): ?>
                                    <button type="submit" name="action" value="delete"
                                            class="btn btn-xs btn-outline-danger ml-1"
                                            onclick="return confirm('Delete this submission?')" title="Delete">
                                        <i data-feather="trash-2" style="width:12px;height:12px;"></i>
                                    </button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/appstack_footer.php'; ?>
