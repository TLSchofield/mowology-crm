<?php
/**
 * Companies Management - List View
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('clients.view');

$db = getDB();

// Handle AJAX bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] === 'application/json') {
    $jsonData = json_decode(file_get_contents('php://input'), true);
    $requestAction = $_GET['action'] ?? null;

    if ($requestAction === 'bulk_delete') {
        header('Content-Type: application/json');

        if (!verifyCSRFToken($jsonData['csrf_token'] ?? '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }

        $ids = array_filter(array_map('intval', $jsonData['ids'] ?? []), function($id) { return $id > 0; });
        if (empty($ids)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No valid IDs provided']);
            exit;
        }

        if (count($ids) > 100) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Maximum 100 companies can be deleted at once']);
            exit;
        }

        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            // Count related records for the response
            $relatedJobs = 0;
            $relatedInvoices = 0;
            try {
                $stmt = $db->prepare("SELECT COUNT(*) FROM job_plans WHERE company_id IN ({$placeholders})");
                $stmt->execute($ids);
                $relatedJobs = (int)$stmt->fetchColumn();
            } catch (Exception $e) {}
            try {
                $stmt = $db->prepare("SELECT COUNT(*) FROM invoices WHERE company_id IN ({$placeholders})");
                $stmt->execute($ids);
                $relatedInvoices = (int)$stmt->fetchColumn();
            } catch (Exception $e) {}

            $db->beginTransaction();

            // Clean up activity_log (no FK constraint)
            try {
                $db->prepare("DELETE FROM activity_log WHERE company_id IN ({$placeholders})")->execute($ids);
            } catch (Exception $e) {}

            // Delete companies (cascades via FK: company_properties, etc.)
            $stmt = $db->prepare("DELETE FROM companies WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $deleted = $stmt->rowCount();

            $db->commit();

            echo json_encode([
                'success' => true,
                'deleted_count' => $deleted,
                'related_jobs' => $relatedJobs,
                'related_invoices' => $relatedInvoices
            ]);
        } catch (Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Delete failed']);
        }
        exit;
    }
}

// Handle filters
$statusFilter = $_GET['status'] ?? '';
$stageFilter = $_GET['stage'] ?? '';
$searchQuery = trim($_GET['search'] ?? '');

// Build query
$params = [];
$whereConditions = ['1=1'];

if ($statusFilter) {
    $whereConditions[] = 'c.account_status = ?';
    $params[] = $statusFilter;
} else {
    // Default: show active only (hide archived)
    $whereConditions[] = "c.account_status != 'inactive'";
}

if ($stageFilter) {
    $whereConditions[] = 'c.lifecycle_stage = ?';
    $params[] = $stageFilter;
}

if ($searchQuery) {
    $whereConditions[] = '(c.company_name LIKE ? OR c.billing_email LIKE ? OR c.billing_phone LIKE ?)';
    $searchParam = "%{$searchQuery}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$whereClause = implode(' AND ', $whereConditions);

$stmt = $db->prepare("
    SELECT c.*,
           pc.first_name as primary_first_name, pc.last_name as primary_last_name,
           pc.email as primary_email, pc.phone as primary_phone
    FROM companies c
    LEFT JOIN contacts pc ON c.primary_contact_id = pc.id
    WHERE {$whereClause}
    ORDER BY c.company_name ASC
    LIMIT 200
");
$stmt->execute($params);
$companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts for stat cards
$countStmt = $db->query("
    SELECT account_status, COUNT(*) as cnt
    FROM companies
    GROUP BY account_status
");
$statusCounts = [];
while ($row = $countStmt->fetch()) {
    $statusCounts[$row['account_status']] = (int)$row['cnt'];
}
$totalCount = array_sum($statusCounts);
$activeCount = $statusCounts['active'] ?? 0;
$inactiveCount = $statusCounts['inactive'] ?? 0;
$suspendedCount = $statusCounts['suspended'] ?? 0;

// Get lifecycle stages for filter dropdown
$stages = getLifecycleStages('company');

$pageTitle = 'Companies';
$activePage = 'companies';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-1">Companies</h1>
                    <p class="text-muted mb-0">Manage your business accounts</p>
                </div>
                <a href="create.php" class="btn btn-primary">
                    <i data-feather="plus" class="align-middle mr-1" style="width:16px;height:16px;"></i> New Company
                </a>
            </div>

            <!-- Stat Cards -->
            <div class="row mb-4">
                <div class="col-6 col-md-3">
                    <div class="card">
                        <div class="card-body py-3">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h3 class="mb-0"><?= $totalCount ?></h3>
                                    <p class="text-muted mb-0 small">Total</p>
                                </div>
                                <div class="text-muted"><i data-feather="briefcase"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card">
                        <div class="card-body py-3">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h3 class="mb-0"><?= $activeCount ?></h3>
                                    <p class="text-muted mb-0 small">Active</p>
                                </div>
                                <div style="color:var(--mw-green);"><i data-feather="check-circle"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card">
                        <div class="card-body py-3">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h3 class="mb-0"><?= $inactiveCount ?></h3>
                                    <p class="text-muted mb-0 small">Archived</p>
                                </div>
                                <div class="text-muted"><i data-feather="archive"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card">
                        <div class="card-body py-3">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h3 class="mb-0"><?= $suspendedCount ?></h3>
                                    <p class="text-muted mb-0 small">Suspended</p>
                                </div>
                                <div class="text-warning"><i data-feather="alert-circle"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters & Search -->
            <div class="card mb-4">
                <div class="card-body py-3">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <!-- Status Filter Tabs -->
                        <div class="mw-filter-tabs">
                            <a href="?" class="mw-filter-tab <?= (!$statusFilter && !$stageFilter) ? 'active' : '' ?>">All</a>
                            <a href="?status=active" class="mw-filter-tab <?= $statusFilter === 'active' ? 'active' : '' ?>">Active</a>
                            <a href="?status=inactive" class="mw-filter-tab <?= $statusFilter === 'inactive' ? 'active' : '' ?>">Archived</a>
                            <a href="?status=suspended" class="mw-filter-tab <?= $statusFilter === 'suspended' ? 'active' : '' ?>">Suspended</a>
                            <?php if (!empty($stages)): ?>
                                <span class="mx-2 text-muted">|</span>
                                <?php foreach ($stages as $stage): ?>
                                    <a href="?stage=<?= htmlspecialchars($stage['stage_key']) ?>"
                                       class="mw-filter-tab <?= $stageFilter === $stage['stage_key'] ? 'active' : '' ?>"
                                       style="<?= $stageFilter === $stage['stage_key'] ? 'border-color:' . htmlspecialchars($stage['stage_color']) . ';color:' . htmlspecialchars($stage['stage_color']) : '' ?>">
                                        <?= htmlspecialchars($stage['stage_label']) ?>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Search -->
                        <form method="get" class="d-flex" style="max-width:300px;">
                            <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>"><?php endif; ?>
                            <input type="text" name="search" class="form-control form-control-sm"
                                   placeholder="Search companies..."
                                   value="<?= htmlspecialchars($searchQuery) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary ml-1">
                                <i data-feather="search" style="width:14px;height:14px;"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Companies Table -->
            <div class="card">
                <div class="card-body p-0">
                    <?php if (empty($companies)): ?>
                        <div class="text-center py-5">
                            <i data-feather="briefcase" style="width:48px;height:48px;" class="text-muted mb-3"></i>
                            <h5 class="text-muted">No companies found</h5>
                            <p class="text-muted">
                                <?php if ($searchQuery): ?>
                                    Try adjusting your search or filters.
                                <?php else: ?>
                                    Get started by creating your first company.
                                <?php endif; ?>
                            </p>
                            <a href="create.php" class="btn btn-primary mt-2">+ New Company</a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th class="mw-bulk-checkbox-cell">
                                            <input type="checkbox" class="mw-bulk-checkbox" id="mw-companies-select-all" title="Select all">
                                        </th>
                                        <th>Company Name</th>
                                        <th>Type</th>
                                        <th>Primary Contact</th>
                                        <th>Phone</th>
                                        <th>Status</th>
                                        <th>Stage</th>
                                        <th class="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($companies as $co): ?>
                                        <tr>
                                            <td class="mw-bulk-checkbox-cell">
                                                <input type="checkbox" class="mw-bulk-checkbox mw-bulk-row-select" data-id="<?= (int)$co['id'] ?>">
                                            </td>
                                            <td>
                                                <a href="view.php?id=<?= $co['id'] ?>" class="font-weight-bold text-dark">
                                                    <?= htmlspecialchars($co['company_name']) ?>
                                                </a>
                                            </td>
                                            <td>
                                                <span class="mw-company-type-badge <?= htmlspecialchars($co['company_type'] ?? 'individual') ?>">
                                                    <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $co['company_type'] ?? 'individual'))) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($co['primary_first_name']): ?>
                                                    <?= htmlspecialchars(trim($co['primary_first_name'] . ' ' . $co['primary_last_name'])) ?>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($co['billing_phone'] ?: ($co['primary_phone'] ?: '—')) ?>
                                            </td>
                                            <td>
                                                <?php
                                                $statusColors = ['active' => 'success', 'inactive' => 'secondary', 'suspended' => 'warning'];
                                                $statusColor = $statusColors[$co['account_status']] ?? 'secondary';
                                                ?>
                                                <span class="badge badge-<?= $statusColor ?>">
                                                    <?= htmlspecialchars(ucfirst($co['account_status'])) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars(ucfirst($co['lifecycle_stage'] ?? 'prospect')) ?>
                                            </td>
                                            <td class="text-right">
                                                <a href="view.php?id=<?= $co['id'] ?>" class="btn btn-sm btn-outline-primary mr-1" title="View">
                                                    <i data-feather="eye" style="width:14px;height:14px;"></i>
                                                </a>
                                                <a href="edit.php?id=<?= $co['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Edit">
                                                    <i data-feather="edit-2" style="width:14px;height:14px;"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Bulk Action Bar -->
            <div class="mw-bulk-action-bar" id="mw-companies-bulk-bar">
                <div>
                    <span class="mw-bulk-count" id="mw-companies-bulk-count">0</span> companies selected
                    <button class="btn btn-sm mw-bulk-clear-btn ml-3" onclick="mwBulkClearCompanies()">Clear Selection</button>
                </div>
                <button class="btn btn-sm btn-danger" onclick="mwBulkDeleteCompanies()">
                    <i data-feather="trash-2"></i> Delete Selected
                </button>
            </div>

<script>
// ── Bulk Delete ──────────────────────────────────────
var mwCompaniesBulkSelected = new Set();

var companiesSelectAll = document.getElementById('mw-companies-select-all');
if (companiesSelectAll) {
    companiesSelectAll.addEventListener('change', function() {
        var checked = this.checked;
        document.querySelectorAll('.mw-bulk-row-select').forEach(function(cb) {
            cb.checked = checked;
            var id = parseInt(cb.dataset.id);
            if (checked) {
                mwCompaniesBulkSelected.add(id);
            } else {
                mwCompaniesBulkSelected.delete(id);
            }
        });
        mwCompaniesBulkUpdateBar();
    });
}

document.addEventListener('change', function(e) {
    if (e.target.classList.contains('mw-bulk-row-select')) {
        var id = parseInt(e.target.dataset.id);
        if (e.target.checked) {
            mwCompaniesBulkSelected.add(id);
        } else {
            mwCompaniesBulkSelected.delete(id);
            if (companiesSelectAll) companiesSelectAll.checked = false;
        }
        mwCompaniesBulkUpdateBar();
    }
});

function mwCompaniesBulkUpdateBar() {
    var bar = document.getElementById('mw-companies-bulk-bar');
    var count = document.getElementById('mw-companies-bulk-count');
    if (!bar || !count) return;
    count.textContent = mwCompaniesBulkSelected.size;
    if (mwCompaniesBulkSelected.size > 0) {
        bar.classList.add('mw-bulk-visible');
    } else {
        bar.classList.remove('mw-bulk-visible');
    }
}

function mwBulkClearCompanies() {
    mwCompaniesBulkSelected.clear();
    document.querySelectorAll('.mw-bulk-row-select').forEach(function(cb) { cb.checked = false; });
    if (companiesSelectAll) companiesSelectAll.checked = false;
    mwCompaniesBulkUpdateBar();
}

function mwBulkDeleteCompanies() {
    var count = mwCompaniesBulkSelected.size;
    if (count === 0) return;

    var response = prompt(
        'WARNING: Permanently delete ' + count + ' company(ies)?\n\n' +
        'This will CASCADE DELETE all their:\n' +
        '• Jobs\n' +
        '• Invoices\n' +
        '• Property associations\n\n' +
        'This is IRREVERSIBLE. Type DELETE to confirm:'
    );
    if (response !== 'DELETE') return;

    fetch('?action=bulk_delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            ids: Array.from(mwCompaniesBulkSelected),
            csrf_token: '<?php echo generateCSRFToken(); ?>'
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            var msg = data.deleted_count + ' company(ies) deleted.';
            if (data.related_jobs > 0) msg += '\n' + data.related_jobs + ' job(s) cascade-deleted.';
            if (data.related_invoices > 0) msg += '\n' + data.related_invoices + ' invoice(s) cascade-deleted.';
            alert(msg);
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(function(err) { alert('Error: ' + err.message); });
}
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
