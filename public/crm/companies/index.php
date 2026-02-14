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

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
