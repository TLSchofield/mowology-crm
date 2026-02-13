<?php
/**
 * Invoices Management - List View
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('billing.view');

// Handle filters
$statusFilter = $_GET['status'] ?? '';
$searchQuery = trim($_GET['search'] ?? '');

// Build query
$db = getDB();
$params = [];
$whereConditions = ['1=1'];

if ($statusFilter) {
    $whereConditions[] = 'i.status = ?';
    $params[] = $statusFilter;
}

if ($searchQuery) {
    $whereConditions[] = '(i.invoice_number LIKE ? OR c.company_name LIKE ?)';
    $searchParam = "%{$searchQuery}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$whereClause = implode(' AND ', $whereConditions);

$stmt = $db->prepare("
    SELECT
        i.*,
        c.company_name,
        jp.plan_number,
        jp.title as plan_title
    FROM invoices i
    LEFT JOIN companies c ON i.company_id = c.id
    LEFT JOIN job_plans jp ON i.plan_id = jp.id
    LEFT JOIN job_visits jv ON i.visit_id = jv.id
    WHERE {$whereClause}
    ORDER BY i.created_at DESC
    LIMIT 100
");
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts
$countStmt = $db->query("
    SELECT status, COUNT(*) as count, SUM(balance_due) as total_due
    FROM invoices
    GROUP BY status
");
$statusCounts = [];
$totalOutstanding = 0;
while ($row = $countStmt->fetch()) {
    $statusCounts[$row['status']] = $row['count'];
    if (in_array($row['status'], ['sent', 'viewed', 'partial', 'overdue'])) {
        $totalOutstanding += floatval($row['total_due']);
    }
}
$totalCount = array_sum($statusCounts);

$pageTitle = 'Invoices';
$activePage = 'invoices';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-3">Invoices</h1>
                    <p class="text-muted">Track payments and manage billing</p>
                </div>
                <a href="create.php" class="btn btn-primary">+ Create Invoice</a>
            </div>

            <!-- Stats -->
            <div class="mw-stats-row">
                <div class="mw-stat-card outstanding">
                    <h4>Outstanding</h4>
                    <div class="value currency"><?php echo formatCurrency($totalOutstanding); ?></div>
                </div>
                <div class="mw-stat-card sent">
                    <h4>Sent</h4>
                    <div class="value"><?php echo ($statusCounts['sent'] ?? 0) + ($statusCounts['viewed'] ?? 0); ?></div>
                </div>
                <div class="mw-stat-card paid">
                    <h4>Paid</h4>
                    <div class="value"><?php echo $statusCounts['paid'] ?? 0; ?></div>
                </div>
                <div class="mw-stat-card overdue">
                    <h4>Overdue</h4>
                    <div class="value"><?php echo $statusCounts['overdue'] ?? 0; ?></div>
                </div>
            </div>

            <div class="d-flex flex-wrap align-items-center mb-3" style="gap: 16px;">
                <div class="mw-filter-tabs">
                    <a href="?status=" class="mw-filter-tab <?php echo !$statusFilter ? 'active' : ''; ?>">
                        All <span class="count"><?php echo $totalCount; ?></span>
                    </a>
                    <a href="?status=draft" class="mw-filter-tab <?php echo $statusFilter === 'draft' ? 'active' : ''; ?>">
                        Draft <span class="count"><?php echo $statusCounts['draft'] ?? 0; ?></span>
                    </a>
                    <a href="?status=sent" class="mw-filter-tab <?php echo $statusFilter === 'sent' ? 'active' : ''; ?>">
                        Sent <span class="count"><?php echo $statusCounts['sent'] ?? 0; ?></span>
                    </a>
                    <a href="?status=paid" class="mw-filter-tab <?php echo $statusFilter === 'paid' ? 'active' : ''; ?>">
                        Paid <span class="count"><?php echo $statusCounts['paid'] ?? 0; ?></span>
                    </a>
                    <a href="?status=overdue" class="mw-filter-tab <?php echo $statusFilter === 'overdue' ? 'active' : ''; ?>">
                        Overdue <span class="count"><?php echo $statusCounts['overdue'] ?? 0; ?></span>
                    </a>
                </div>

                <form class="mw-search-box" method="GET">
                    <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>"><?php endif; ?>
                    <input type="text" name="search" class="mw-search-input"
                           placeholder="Search invoices..."
                           value="<?php echo htmlspecialchars($searchQuery); ?>">
                </form>
            </div>

            <div class="mw-table-card">
                <?php if (empty($invoices)): ?>
                    <div class="mw-empty-state">
                        <span class="mw-empty-state-icon">📄</span>
                        <p>No invoices found. Complete a job to create your first invoice!</p>
                    </div>
                <?php else: ?>
                    <table class="mw-table">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Client</th>
                                <th>Plan</th>
                                <th>Amount</th>
                                <th>Balance</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoices as $invoice): ?>
                                <tr>
                                    <td><span class="invoice-number"><?php echo htmlspecialchars($invoice['invoice_number']); ?></span></td>
                                    <td><?php echo htmlspecialchars($invoice['company_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php if (!empty($invoice['plan_number'])): ?>
                                            <a href="../jobs/view.php?id=<?php echo $invoice['plan_id']; ?>"><?php echo htmlspecialchars($invoice['plan_number']); ?></a>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td class="amount"><?php echo formatCurrency($invoice['total']); ?></td>
                                    <td class="amount"><?php echo formatCurrency($invoice['balance_due']); ?></td>
                                    <td><?php echo formatDate($invoice['due_date']); ?></td>
                                    <td><?php echo getStatusBadge($invoice['status'], 'invoice'); ?></td>
                                    <td class="actions">
                                        <a href="view.php?id=<?php echo $invoice['id']; ?>" class="mw-action-btn mw-action-btn-view">View</a>
                                        <?php if (in_array($invoice['status'], ['sent', 'viewed', 'partial', 'overdue'])): ?>
                                            <a href="view.php?id=<?php echo $invoice['id']; ?>&action=pay" class="mw-action-btn mw-action-btn-paid">Mark Paid</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
