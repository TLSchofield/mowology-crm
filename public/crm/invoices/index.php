<?php
/**
 * Invoices Management - List View
 * Supports single-row "Mark Paid" and multi-row bulk payment via checkbox selection.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('billing.view');

// Handle filters
$statusFilter = $_GET['status'] ?? '';
$searchQuery  = trim($_GET['search'] ?? '');

// ── Pagination + Sorting params ──────────────────────────────────────────
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = max(10, min(100, (int)($_GET['per_page'] ?? 25)));
$sortCol  = $_GET['sort'] ?? 'created_at';
$sortDir  = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

$allowedSorts = [
    'invoice_number' => 'i.invoice_number',
    'client'         => 'display_client',
    'amount'         => 'i.total',
    'balance'        => 'i.balance_due',
    'due_date'       => 'i.due_date',
    'status'         => 'i.status',
    'created_at'     => 'i.created_at',
];
$orderBy = $allowedSorts[$sortCol] ?? 'i.created_at';

// Build query
$db     = getDB();
$params = [];
$whereConditions = ['1=1'];

if ($statusFilter === 'overdue') {
    // Live overdue: unpaid with a past due date (matches the summary card / "Due" column),
    // not the stale stored status which lags behind the daily cron.
    $whereConditions[] = "i.status IN ('sent','viewed','partial','overdue')
                          AND i.balance_due > 0.005
                          AND i.due_date IS NOT NULL AND i.due_date < CURDATE()";
} elseif ($statusFilter) {
    $whereConditions[] = 'i.status = ?';
    $params[] = $statusFilter;
}

if ($searchQuery) {
    $whereConditions[] = '(i.invoice_number LIKE ? OR p.property_name LIKE ? OR c.company_name LIKE ? OR CONCAT(ct.first_name," ",ct.last_name) LIKE ?)';
    $searchParam = "%{$searchQuery}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$whereClause = implode(' AND ', $whereConditions);

// Count total matching rows
$countParams = $params;
$cntStmt = $db->prepare("
    SELECT COUNT(*) FROM invoices i
    LEFT JOIN companies  c  ON i.company_id = c.id
    LEFT JOIN contacts   ct ON i.contact_id = ct.id
    LEFT JOIN properties p  ON i.property_id = p.id
    WHERE {$whereClause}
");
$cntStmt->execute($countParams);
$filteredTotal = (int)$cntStmt->fetchColumn();
$totalPages = max(1, (int)ceil($filteredTotal / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare("
    SELECT
        i.*,
        COALESCE(
            NULLIF(p.property_name, ''),
            NULLIF(c.company_name, ''),
            NULLIF(CONCAT(ct.first_name,' ',ct.last_name), ' ')
        ) as display_client,
        p.property_name,
        c.company_name,
        ct.first_name as contact_first,
        ct.last_name  as contact_last,
        jp.plan_number,
        jp.title as plan_title,
        ctr.contract_number,
        ctr.title as contract_title
    FROM invoices i
    LEFT JOIN companies  c   ON i.company_id = c.id
    LEFT JOIN contacts   ct  ON i.contact_id = ct.id
    LEFT JOIN properties p   ON i.property_id = p.id
    LEFT JOIN job_plans  jp  ON i.plan_id    = jp.id
    LEFT JOIN job_visits jv  ON i.visit_id   = jv.id
    LEFT JOIN contracts  ctr ON i.contract_id = ctr.id
    WHERE {$whereClause}
    ORDER BY {$orderBy} {$sortDir}
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Candidate bank-deposit matches for unpaid invoices (inline reconciliation) ──
// Surfaces imported Vancity deposits that likely pay an unpaid invoice so the admin
// can attach them. Computed once for the whole page (no per-row queries).
$invoiceMatches = [];
try {
    if (!defined('APP_ROOT')) {
        $__d = __DIR__;
        for ($__k = 0; $__k < 6; $__k++) {
            $__d = dirname($__d);
            if (is_file($__d . '/app/Core/paths.php')) { require_once $__d . '/app/Core/paths.php'; break; }
        }
        unset($__d, $__k);
    }
    $__svcFile = defined('APP_ROOT') ? APP_ROOT . '/Modules/Accounting/Services/InvoiceReconciliationService.php' : '';
    $__payableIds = [];
    foreach ($invoices as $__inv) {
        if (in_array($__inv['status'], ['sent', 'viewed', 'partial', 'overdue'], true)
            && (float)$__inv['balance_due'] > 0.005) {
            $__payableIds[] = (int)$__inv['id'];
        }
    }
    if ($__payableIds && $__svcFile && is_file($__svcFile)) {
        require_once $__svcFile;
        $__reconSvc = new InvoiceReconciliationService($db);
        $invoiceMatches = $__reconSvc->topCandidatesForInvoiceIds($__payableIds);
    }
} catch (Throwable $__e) {
    error_log('[invoices/index] candidate match error: ' . $__e->getMessage());
    $invoiceMatches = [];
}

// Get counts (per-status, used by the filter chips below).
$countStmt = $db->query("
    SELECT status, COUNT(*) as count, SUM(balance_due) as total_due
    FROM invoices
    GROUP BY status
");
$statusCounts   = [];
$totalOutstanding = 0;
while ($row = $countStmt->fetch()) {
    $statusCounts[$row['status']] = $row['count'];
    if (in_array($row['status'], ['sent', 'viewed', 'partial', 'overdue'])) {
        $totalOutstanding += floatval($row['total_due']);
    }
}
$totalCount = array_sum($statusCounts);

// Live aging split for the summary cards. The stored `status` column only flips to
// 'overdue' when the daily invoice_overdue cron runs, so it lags reality. Derive the
// overdue count straight from due_date (matching the per-row "Due" column) so the cards
// are correct regardless of whether the cron has run.
//   - overdueLive  = unpaid (balance > 0) AND due_date has passed
//   - currentUnpaid = unpaid AND not yet overdue  (shown on the "Sent" card)
$agingStmt = $db->query("
    SELECT
        SUM(CASE WHEN balance_due > 0.005 AND due_date IS NOT NULL AND due_date < CURDATE()
                 THEN 1 ELSE 0 END) AS overdue_live,
        SUM(CASE WHEN balance_due > 0.005 AND (due_date IS NULL OR due_date >= CURDATE())
                 THEN 1 ELSE 0 END) AS current_unpaid
    FROM invoices
    WHERE status IN ('sent', 'viewed', 'partial', 'overdue')
");
$agingRow      = $agingStmt->fetch() ?: [];
$overdueCount  = (int)($agingRow['overdue_live'] ?? 0);
$currentUnpaid = (int)($agingRow['current_unpaid'] ?? 0);

// Helper functions for sort/pagination URLs
function invSortUrl($col, $curSort, $curDir) {
    $p = $_GET; $p['sort'] = $col;
    $p['dir'] = ($curSort === $col && $curDir === 'ASC') ? 'DESC' : 'ASC';
    unset($p['page']); return '?' . http_build_query($p);
}
function invSortClass($col, $curSort, $curDir) {
    if ($curSort !== $col) return 'mw-sortable';
    return 'mw-sortable mw-sort-' . strtolower($curDir);
}
function invPageUrl($pageNum) {
    $p = $_GET; $p['page'] = $pageNum; return '?' . http_build_query($p);
}

$csrfToken = generateCSRFToken();

// Pending Interac e-Transfer notifications (filled by the inbox poller cron).
$pendingEtransfers = [];
if (defined('APP_ROOT')) {
    $__etSvc = APP_ROOT . '/Modules/Accounting/Services/EtransferInboxService.php';
    if (is_file($__etSvc)) {
        require_once $__etSvc;
        try {
            $pendingEtransfers = (new EtransferInboxService(getDB()))->listPending();
        } catch (\Throwable $__e) {
            error_log('[invoices] e-Transfer panel load failed: ' . $__e->getMessage());
        }
    }
}

$pageTitle  = 'Invoices';
$activePage = 'invoices';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

            <div class="mw-page-header">
                <div class="mw-page-header-left">
                    <h1 class="mw-page-title">Invoices</h1>
                    <p class="mw-page-subtitle">Track payments and manage billing</p>
                </div>
                <div class="mw-page-actions">
                    <a href="/crm/api/export-invoices.php" class="btn btn-outline-secondary btn-sm"><i data-feather="download"></i> Export CSV</a>
                    <a href="create.php" class="btn btn-primary"><i data-feather="plus"></i> Create Invoice</a>
                </div>
            </div>

            <!-- Stats -->
            <div class="mw-stats-row">
                <div class="mw-stat-card outstanding">
                    <h4>Outstanding</h4>
                    <div class="value currency"><?php echo formatCurrency($totalOutstanding); ?></div>
                </div>
                <div class="mw-stat-card sent">
                    <h4>Sent</h4>
                    <div class="value"><?php echo $currentUnpaid; ?></div>
                </div>
                <div class="mw-stat-card paid">
                    <h4>Paid</h4>
                    <div class="value"><?php echo $statusCounts['paid'] ?? 0; ?></div>
                </div>
                <div class="mw-stat-card overdue">
                    <h4>Overdue</h4>
                    <div class="value"><?php echo $overdueCount; ?></div>
                </div>
            </div>

            <?php if (!empty($pendingEtransfers)): ?>
            <!-- Pending Interac e-Transfers (from the inbox poller) -->
            <div class="mw-etransfer-panel" id="etransfers">
                <div class="mw-etransfer-head">
                    <span><i data-feather="inbox"></i> Pending e-Transfers <span class="mw-et-count"><?php echo count($pendingEtransfers); ?></span></span>
                    <span class="mw-et-sub">Match each transfer to an invoice, then record it.</span>
                </div>
                <?php foreach ($pendingEtransfers as $et): ?>
                    <?php
                    $etAmount   = $et['amount'] !== null ? (float)$et['amount'] : 0;
                    $matchedNo  = $et['matched_invoice_number'] ?? '';
                    $prefillNo  = $matchedNo ?: ($et['invoice_hint'] ?? '');
                    $conf       = $et['match_confidence'] ?? 'none';
                    $isClaim    = ($et['transfer_type'] ?? '') === 'claim';
                    ?>
                    <div class="mw-et-row" data-id="<?php echo (int)$et['id']; ?>">
                        <div class="mw-et-main">
                            <div class="mw-et-line1">
                                <strong><?php echo htmlspecialchars($et['sender_name'] ?: 'Unknown sender'); ?></strong>
                                <span class="mw-et-amount"><?php echo formatCurrency($etAmount); ?></span>
                                <?php if ($isClaim): ?><span class="mw-et-badge claim">needs claiming in online banking</span><?php endif; ?>
                                <?php if ($conf === 'high'): ?><span class="mw-et-badge high">match: <?php echo htmlspecialchars($matchedNo); ?></span>
                                <?php elseif ($conf === 'medium'): ?><span class="mw-et-badge med">likely: <?php echo htmlspecialchars($matchedNo); ?></span>
                                <?php else: ?><span class="mw-et-badge none">no match — enter invoice #</span><?php endif; ?>
                            </div>
                            <?php if (!empty($et['memo'])): ?>
                                <div class="mw-et-memo">“<?php echo htmlspecialchars($et['memo']); ?>”</div>
                            <?php endif; ?>
                        </div>
                        <div class="mw-et-actions">
                            <input type="text" class="form-control form-control-sm mw-et-inv" placeholder="INV-…"
                                   value="<?php echo htmlspecialchars($prefillNo); ?>" aria-label="Invoice number">
                            <input type="number" step="0.01" min="0" class="form-control form-control-sm mw-et-amt"
                                   value="<?php echo $etAmount > 0 ? number_format($etAmount, 2, '.', '') : ''; ?>" aria-label="Amount">
                            <button class="btn btn-primary btn-sm mw-et-record">Record</button>
                            <button class="btn btn-outline-secondary btn-sm mw-et-dismiss" title="Dismiss">&times;</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Filters + search -->
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
                        Overdue <span class="count"><?php echo $overdueCount; ?></span>
                    </a>
                </div>

                <form class="mw-search-box" method="GET">
                    <?php if ($statusFilter): ?>
                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                    <?php endif; ?>
                    <input type="text" name="search" class="mw-search-input"
                           placeholder="Search invoices…"
                           value="<?php echo htmlspecialchars($searchQuery); ?>">
                </form>
            </div>

            <!-- Bulk action bar (hidden until ≥1 checkbox ticked) -->
            <div id="mw-bulk-bar" class="mw-bulk-bar" style="display:none;" aria-live="polite">
                <div class="mw-bulk-bar-left">
                    <span id="mw-bulk-count" class="mw-bulk-count">0 selected</span>
                    <span class="mw-bulk-total-label">Total: <strong id="mw-bulk-total">$0.00</strong></span>
                </div>
                <div class="mw-bulk-bar-right">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="mwClearSelection()">
                        Clear
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="mwBulkResend()">
                        <i data-feather="send" style="width:14px;height:14px;margin-right:4px;vertical-align:middle;"></i>
                        Resend Selected
                    </button>
                    <button type="button" class="btn btn-sm btn-success" onclick="mwOpenBulkPayment()">
                        <i data-feather="check-circle" style="width:14px;height:14px;margin-right:4px;vertical-align:middle;"></i>
                        Record Payment
                    </button>
                </div>
            </div>

            <!-- Invoice table -->
            <div class="mw-table-card">
                <?php if (empty($invoices)): ?>
                    <div class="mw-empty-state">
                        <span class="mw-empty-state-icon">📄</span>
                        <p>No invoices found. Complete a job to create your first invoice!</p>
                    </div>
                <?php else: ?>
                    <table class="mw-table" id="mw-invoice-table">
                        <thead>
                            <tr>
                                <th class="mw-col-check">
                                    <input type="checkbox" id="mw-check-all" class="mw-checkbox"
                                           title="Select all payable invoices" onchange="mwToggleAll(this)">
                                </th>
                                <th class="<?php echo invSortClass('invoice_number', $sortCol, $sortDir); ?>"><a href="<?php echo invSortUrl('invoice_number', $sortCol, $sortDir); ?>">Invoice #</a></th>
                                <th class="<?php echo invSortClass('client', $sortCol, $sortDir); ?>"><a href="<?php echo invSortUrl('client', $sortCol, $sortDir); ?>">Client</a></th>
                                <th>Plan / Contract</th>
                                <th class="text-right <?php echo invSortClass('amount', $sortCol, $sortDir); ?>"><a href="<?php echo invSortUrl('amount', $sortCol, $sortDir); ?>">Amount</a></th>
                                <th class="text-right <?php echo invSortClass('balance', $sortCol, $sortDir); ?>"><a href="<?php echo invSortUrl('balance', $sortCol, $sortDir); ?>">Balance</a></th>
                                <th class="<?php echo invSortClass('due_date', $sortCol, $sortDir); ?>"><a href="<?php echo invSortUrl('due_date', $sortCol, $sortDir); ?>">Due Date</a></th>
                                <th>Due</th>
                                <th class="<?php echo invSortClass('status', $sortCol, $sortDir); ?>"><a href="<?php echo invSortUrl('status', $sortCol, $sortDir); ?>">Status</a></th>
                                <th>Tracking</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoices as $invoice): ?>
                                <?php
                                $isPayable = in_array($invoice['status'], ['sent', 'viewed', 'partial', 'overdue']);
                                $balance   = floatval($invoice['balance_due']);
                                // Aging / due column
                                $agingLabel = ''; $agingClass = '';
                                $isPaidStatus = in_array($invoice['status'], ['paid', 'draft', 'cancelled']);
                                if (!empty($invoice['due_date']) && !$isPaidStatus) {
                                    $dueDate  = new DateTime($invoice['due_date']);
                                    $todayDt  = new DateTime('today');
                                    $diff     = $todayDt->diff($dueDate);
                                    $days     = (int)$diff->days;
                                    if ($dueDate < $todayDt) {
                                        $agingLabel = $days === 1 ? '1 day overdue' : "{$days} days overdue";
                                        $agingClass = 'mw-due-overdue';
                                    } elseif ($days === 0) {
                                        $agingLabel = 'Due today';
                                        $agingClass = 'mw-due-today';
                                    } elseif ($days <= 7) {
                                        $agingLabel = "Due in {$days}d";
                                        $agingClass = 'mw-due-soon';
                                    } else {
                                        $agingLabel = "Due in {$days}d";
                                        $agingClass = 'mw-due-ok';
                                    }
                                }
                                $client    = htmlspecialchars($invoice['display_client'] ?? $invoice['company_name'] ?? 'N/A');
                                // When we're showing a property name as the primary label, include the
                                // paying company as a muted secondary line so the accountant still knows
                                // who owes the money (e.g. "Oakridge Gardens" + "Vancouver Management Ltd").
                                $clientSubtitle = '';
                                if (!empty($invoice['property_name']) && !empty($invoice['company_name'])
                                    && $invoice['property_name'] !== $invoice['company_name']) {
                                    $clientSubtitle = htmlspecialchars($invoice['company_name']);
                                }
                                ?>
                                <tr class="<?php echo $isPayable ? 'mw-payable-row' : ''; ?>"
                                    data-href="view.php?id=<?php echo (int)$invoice['id']; ?>"
                                    data-invoice-id="<?php echo $invoice['id']; ?>"
                                    data-balance="<?php echo number_format($balance, 2, '.', ''); ?>"
                                    data-invoice-number="<?php echo htmlspecialchars($invoice['invoice_number']); ?>"
                                    data-client="<?php echo $client; ?>">
                                    <td class="mw-col-check">
                                        <?php if ($isPayable): ?>
                                            <input type="checkbox" class="mw-checkbox mw-row-check"
                                                   value="<?php echo $invoice['id']; ?>"
                                                   data-balance="<?php echo number_format($balance, 2, '.', ''); ?>"
                                                   onchange="mwUpdateBulkBar()">
                                        <?php else: ?>
                                            <span class="mw-check-placeholder"></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="view.php?id=<?php echo $invoice['id']; ?>" class="mw-cell-primary invoice-number">
                                            <?php echo htmlspecialchars($invoice['invoice_number']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="mw-cell-primary"><?php echo $client; ?></span>
                                        <?php if ($clientSubtitle !== ''): ?>
                                            <span class="mw-cell-secondary"><?php echo $clientSubtitle; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($invoice['plan_number'])): ?>
                                            <a href="../jobs/view.php?id=<?php echo (int)$invoice['plan_id']; ?>">
                                                <?php echo htmlspecialchars($invoice['plan_number']); ?>
                                            </a>
                                        <?php elseif (!empty($invoice['contract_number'])): ?>
                                            <a href="../contracts/view.php?id=<?php echo (int)$invoice['contract_id']; ?>" title="<?php echo htmlspecialchars($invoice['contract_title'] ?? ''); ?>">
                                                <?php echo htmlspecialchars($invoice['contract_number']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right mw-amount"><?php echo formatCurrency($invoice['total']); ?></td>
                                    <td class="text-right mw-amount <?php echo $isPayable && $balance > 0 ? 'mw-balance-due' : ''; ?>">
                                        <?php echo formatCurrency($balance); ?>
                                    </td>
                                    <td><?php echo formatDate($invoice['due_date']); ?></td>
                                    <td>
                                        <?php if ($agingLabel !== ''): ?>
                                            <span class="mw-due-badge <?php echo $agingClass; ?>"><?php echo htmlspecialchars($agingLabel); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size:12px;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo getStatusBadge($invoice['status'], 'invoice'); ?></td>
                                    <td class="mw-tracking-cell">
                                        <?php $matches = $invoiceMatches[(int)$invoice['id']] ?? []; ?>
                                        <?php if (!empty($matches)): ?>
                                            <button type="button" class="mw-match-pill"
                                                    onclick="event.stopPropagation(); mwToggleMatch(<?php echo (int)$invoice['id']; ?>)"
                                                    title="A bank deposit likely matches this invoice">
                                                <i data-feather="link-2"></i>
                                                <?php echo count($matches); ?> likely match<?php echo count($matches) > 1 ? 'es' : ''; ?>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($invoice['status'] !== 'draft'): ?>
                                            <?php
                                            $viewCount   = (int)($invoice['view_count'] ?? 0);
                                            $emailOpened = !empty($invoice['email_opened_at']);
                                            $hasViewed   = !empty($invoice['viewed_at']);
                                            ?>
                                            <?php if ($emailOpened): ?>
                                                <span class="mw-tracking-badge mw-tracking-opened" title="Email opened <?php echo formatDateTime($invoice['email_opened_at'], 'M j, g:i A'); ?>">Opened</span>
                                            <?php endif; ?>
                                            <?php if ($viewCount > 0): ?>
                                                <span class="mw-tracking-badge mw-tracking-viewed" title="<?php echo $viewCount; ?> portal view(s), last: <?php echo formatDateTime($invoice['last_viewed_at'] ?? $invoice['viewed_at'], 'M j, g:i A'); ?>"><?php echo $viewCount; ?> view<?php echo $viewCount !== 1 ? 's' : ''; ?></span>
                                            <?php endif; ?>
                                            <?php if (!$emailOpened && !$hasViewed && !empty($invoice['sent_at'])): ?>
                                                <span class="text-muted" style="font-size:12px;">No engagement</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="actions">
                                        <a href="view.php?id=<?php echo $invoice['id']; ?>" class="mw-action-btn mw-action-btn-view">View</a>
                                        <?php if ($isPayable): ?>
                                            <button type="button" class="mw-action-btn mw-action-btn-paid"
                                                    onclick="mwOpenSinglePayment(<?php echo $invoice['id']; ?>, '<?php echo htmlspecialchars($invoice['invoice_number']); ?>', <?php echo number_format($balance, 2, '.', ''); ?>, <?php echo json_encode($client); ?>)">
                                                Pay
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php if (!empty($matches)): ?>
                                <tr class="mw-match-row" id="mw-match-row-<?php echo (int)$invoice['id']; ?>" style="display:none;">
                                    <td colspan="11" class="mw-match-cell">
                                        <div class="mw-match-panel">
                                            <div class="mw-match-panel-head">
                                                <i data-feather="zap"></i>
                                                <span>Possible bank deposit<?php echo count($matches) > 1 ? 's' : ''; ?> for <strong><?php echo htmlspecialchars($invoice['invoice_number']); ?></strong></span>
                                                <span class="mw-match-balance">Balance due <?php echo formatCurrency($balance); ?></span>
                                            </div>
                                            <?php foreach ($matches as $m): ?>
                                            <div class="mw-match-item">
                                                <span class="mw-conf-badge mw-conf-<?php echo $m['confidence'] >= 85 ? 'high' : ($m['confidence'] >= 65 ? 'med' : 'low'); ?>" title="Match confidence"><?php echo (int)$m['confidence']; ?>%</span>
                                                <div class="mw-match-info">
                                                    <div class="mw-match-desc"><?php echo htmlspecialchars($m['description'] ?: 'Bank deposit'); ?></div>
                                                    <div class="mw-match-meta">
                                                        <?php echo formatDate($m['date']); ?> · <?php echo formatCurrency($m['amount']); ?> available
                                                        <?php foreach ($m['reasons'] as $reason): ?>
                                                            <span class="mw-match-reason"><?php echo htmlspecialchars($reason); ?></span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <?php if (!empty($m['covers_more'])): ?>
                                                        <div class="mw-match-note">Larger than this balance — applying <?php echo formatCurrency($m['suggested_amount']); ?> here leaves <?php echo formatCurrency($m['amount'] - $m['suggested_amount']); ?> to apply to other invoices.</div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="mw-match-apply">
                                                    <div class="mw-match-amount-field">
                                                        <span>$</span>
                                                        <input type="number" step="0.01" min="0.01" class="mw-match-amount"
                                                               value="<?php echo number_format($m['suggested_amount'], 2, '.', ''); ?>"
                                                               data-balance="<?php echo number_format($balance, 2, '.', ''); ?>"
                                                               data-remaining="<?php echo number_format($m['amount'], 2, '.', ''); ?>">
                                                    </div>
                                                    <button type="button" class="mw-action-btn mw-action-btn-paid"
                                                            onclick="mwAttachDeposit(this, <?php echo (int)$invoice['id']; ?>, <?php echo (int)$m['tx_id']; ?>, <?php echo htmlspecialchars(json_encode($invoice['invoice_number']), ENT_QUOTES); ?>)">
                                                        Attach
                                                    </button>
                                                    <button type="button" class="mw-match-dismiss" onclick="mwToggleMatch(<?php echo (int)$invoice['id']; ?>)">Close</button>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($totalPages > 1): ?>
                    <div class="mw-pagination">
                        <div class="mw-pagination-info">
                            <span>Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $perPage, $filteredTotal); ?> of <?php echo $filteredTotal; ?> invoices</span>
                            <div class="mw-pagination-per-page">
                                <span>Show</span>
                                <select onchange="window.location.href=this.value;">
                                    <?php foreach ([10, 25, 50, 100] as $pp): ?>
                                    <option value="<?php $p = $_GET; $p['per_page'] = $pp; $p['page'] = 1; echo '?' . http_build_query($p); ?>" <?php echo $perPage === $pp ? 'selected' : ''; ?>><?php echo $pp; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span>per page</span>
                            </div>
                        </div>
                        <div class="mw-pagination-pages">
                            <?php if ($page > 1): ?>
                                <a href="<?php echo invPageUrl($page - 1); ?>">&laquo;</a>
                            <?php else: ?>
                                <span class="disabled">&laquo;</span>
                            <?php endif; ?>
                            <?php
                            $startP = max(1, $page - 2);
                            $endP = min($totalPages, $page + 2);
                            if ($startP > 1) echo '<a href="' . invPageUrl(1) . '">1</a>';
                            if ($startP > 2) echo '<span class="disabled">&hellip;</span>';
                            for ($i = $startP; $i <= $endP; $i++):
                            ?>
                                <?php if ($i === $page): ?>
                                    <span class="active"><?php echo $i; ?></span>
                                <?php else: ?>
                                    <a href="<?php echo invPageUrl($i); ?>"><?php echo $i; ?></a>
                                <?php endif; ?>
                            <?php endfor;
                            if ($endP < $totalPages - 1) echo '<span class="disabled">&hellip;</span>';
                            if ($endP < $totalPages) echo '<a href="' . invPageUrl($totalPages) . '">' . $totalPages . '</a>';
                            ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="<?php echo invPageUrl($page + 1); ?>">&raquo;</a>
                            <?php else: ?>
                                <span class="disabled">&raquo;</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- Bulk / Single Payment Modal                                              -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<div id="mw-payment-modal" class="mw-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="mw-payment-modal-title">
    <div class="mw-modal mw-payment-modal-content">
        <div class="mw-modal-header">
            <h5 class="mb-0" id="mw-payment-modal-title">
                <i data-feather="check-circle" style="width:18px;height:18px;vertical-align:middle;margin-right:6px;"></i>
                <span id="mw-modal-title-text">Record Payment</span>
            </h5>
            <button type="button" class="mw-modal-close" onclick="mwClosePaymentModal()" aria-label="Close">&times;</button>
        </div>

        <div class="mw-modal-body">

            <!-- Invoice summary list (populated by JS) -->
            <div id="mw-payment-invoice-list" class="mw-payment-invoice-list"></div>

            <!-- Grand total row -->
            <div class="mw-payment-total-row">
                <span>Total Payment</span>
                <strong id="mw-payment-grand-total">$0.00</strong>
            </div>

            <!-- Payment details -->
            <div class="mw-form-row" style="margin-top:16px;">
                <div class="mw-form-group">
                    <label class="form-label" for="mw-pay-method">Payment Method</label>
                    <select id="mw-pay-method" class="form-control" onchange="mwToggleRefLabel()">
                        <option value="e_transfer">e-Transfer</option>
                        <option value="cash">Cash</option>
                        <option value="cheque">Cheque</option>
                        <option value="credit_card">Credit Card</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="mw-form-group">
                    <label class="form-label" for="mw-pay-date">Payment Date</label>
                    <input type="date" id="mw-pay-date" class="form-control"
                           value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>

            <div class="mw-form-group">
                <label class="form-label" id="mw-ref-label" for="mw-pay-ref">Confirmation / Reference #</label>
                <input type="text" id="mw-pay-ref" class="form-control"
                       placeholder="e.g., e-Transfer confirmation number">
            </div>

            <div class="mw-form-group">
                <label class="form-label" for="mw-pay-notes">Internal Notes <span class="text-muted">(optional)</span></label>
                <input type="text" id="mw-pay-notes" class="form-control"
                       placeholder="e.g., paid at front door">
            </div>

        </div>

        <div class="mw-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="mwClosePaymentModal()">Cancel</button>
            <button type="button" class="btn btn-success" id="mw-pay-submit" onclick="mwSubmitPayment()">
                <span id="mw-pay-submit-label">Record Payment</span>
                <span id="mw-pay-submit-spinner" style="display:none;">
                    <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                    Saving…
                </span>
            </button>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- Toast notification                                                        -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- Bulk Resend Results Modal -->
<div id="mw-resend-modal" class="mw-modal-overlay" style="display:none;" role="dialog" aria-modal="true">
    <div class="mw-modal" style="max-width:540px;">
        <div class="mw-modal-header">
            <h5 class="mb-0" id="mw-resend-modal-title">Resend Invoices</h5>
            <button type="button" class="mw-modal-close" onclick="mwCloseResendModal()" aria-label="Close">&times;</button>
        </div>
        <div class="mw-modal-body" id="mw-resend-body" style="max-height:380px;overflow-y:auto;">
            <div style="text-align:center;padding:30px 0;">
                <span class="spinner-border text-primary" role="status"></span>
                <p class="mt-2 text-muted">Sending&hellip;</p>
            </div>
        </div>
        <div class="mw-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="mwCloseResendModal()">Close</button>
        </div>
    </div>
</div>

<div id="mw-toast" class="mw-toast" role="alert" aria-live="assertive" style="display:none;"></div>

<script>
(function () {
    'use strict';

    var CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;

    // Map of selected invoice IDs → {balance, number, client}
    var selected = {};

    // ── Checkbox helpers ───────────────────────────────────────────────────────
    window.mwToggleAll = function (masterCb) {
        var checks = document.querySelectorAll('.mw-row-check');
        checks.forEach(function (cb) {
            cb.checked = masterCb.checked;
        });
        mwUpdateBulkBar();
    };

    window.mwUpdateBulkBar = function () {
        selected = {};
        document.querySelectorAll('.mw-row-check:checked').forEach(function (cb) {
            var id  = parseInt(cb.value, 10);
            var row = cb.closest('tr');
            selected[id] = {
                balance : parseFloat(cb.dataset.balance),
                number  : row.dataset.invoiceNumber,
                client  : row.dataset.client
            };
        });

        var count = Object.keys(selected).length;
        var total = Object.values(selected).reduce(function (s, v) { return s + v.balance; }, 0);

        var bar   = document.getElementById('mw-bulk-bar');
        var countEl = document.getElementById('mw-bulk-count');
        var totalEl = document.getElementById('mw-bulk-total');

        bar.style.display   = count > 0 ? 'flex' : 'none';
        countEl.textContent = count + (count === 1 ? ' invoice selected' : ' invoices selected');
        totalEl.textContent = formatMoney(total);
    };

    window.mwClearSelection = function () {
        document.querySelectorAll('.mw-row-check').forEach(function (cb) { cb.checked = false; });
        var master = document.getElementById('mw-check-all');
        if (master) master.checked = false;
        mwUpdateBulkBar();
    };

    // ── Bulk Resend ────────────────────────────────────────────────────────────
    window.mwBulkResend = function () {
        var ids = Object.keys(selected).map(Number);
        if (ids.length === 0) return;

        var modal = document.getElementById('mw-resend-modal');
        var body  = document.getElementById('mw-resend-body');
        document.getElementById('mw-resend-modal-title').textContent = 'Resending ' + ids.length + ' invoice' + (ids.length === 1 ? '' : 's') + '…';
        body.innerHTML = '<div style="text-align:center;padding:30px 0;"><span class="spinner-border text-primary" role="status"></span><p class="mt-2 text-muted">Generating PDFs and sending emails&hellip;</p></div>';
        modal.style.display = 'flex';

        fetch('bulk-resend.php', {
            method : 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body   : JSON.stringify({ csrf_token: CSRF_TOKEN, invoice_ids: ids }),
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (!data.success) {
                body.innerHTML = '<div class="alert alert-danger">' + escHtml(data.error || 'An error occurred.') + '</div>';
                return;
            }
            var ok   = data.results.filter(function (r) { return r.success; });
            var fail = data.results.filter(function (r) { return !r.success; });

            document.getElementById('mw-resend-modal-title').textContent = 'Resend Complete';

            var html = '';
            if (ok.length) {
                html += '<p class="text-success mb-2"><strong>' + ok.length + ' sent successfully</strong></p>';
                html += '<ul class="list-unstyled mb-3">';
                ok.forEach(function (r) {
                    html += '<li style="padding:4px 0;border-bottom:1px solid #f0f0f0;font-size:13px;">'
                         + '<span class="badge badge-success mr-2">✓</span>'
                         + '<strong>' + escHtml(r.invoice_number) + '</strong> → ' + escHtml(r.sent_to) + '</li>';
                });
                html += '</ul>';
            }
            if (fail.length) {
                html += '<p class="text-danger mb-2"><strong>' + fail.length + ' failed</strong></p>';
                html += '<ul class="list-unstyled">';
                fail.forEach(function (r) {
                    html += '<li style="padding:4px 0;font-size:13px;">'
                         + '<span class="badge badge-danger mr-2">✗</span>'
                         + '<strong>' + escHtml(r.invoice_number || '#' + r.id) + '</strong>: ' + escHtml(r.error || 'Failed') + '</li>';
                });
                html += '</ul>';
            }
            body.innerHTML = html;
            if (ok.length) {
                mwClearSelection();
                setTimeout(function () { window.location.reload(); }, 2500);
            }
        })
        .catch(function (err) {
            console.error('[mwBulkResend]', err);
            body.innerHTML = '<div class="alert alert-danger">Network error — please try again.</div>';
        });
    };

    window.mwCloseResendModal = function () {
        document.getElementById('mw-resend-modal').style.display = 'none';
    };
    document.getElementById('mw-resend-modal').addEventListener('click', function (e) {
        if (e.target === this) mwCloseResendModal();
    });

    // ── Open modal (bulk) ──────────────────────────────────────────────────────
    window.mwOpenBulkPayment = function () {
        if (Object.keys(selected).length === 0) return;
        mwRenderModal(selected);
        document.getElementById('mw-payment-modal').style.display = 'flex';
        mwToggleRefLabel();
    };

    // ── Open modal (single, from row Pay button) ───────────────────────────────
    window.mwOpenSinglePayment = function (id, number, balance, client) {
        var single = {};
        single[id] = { balance: balance, number: number, client: client };
        mwRenderModal(single);
        document.getElementById('mw-payment-modal').style.display = 'flex';
        mwToggleRefLabel();
    };

    function mwRenderModal(invoiceMap) {
        var ids    = Object.keys(invoiceMap);
        var isSingle = ids.length === 1;
        var total  = 0;

        // Title
        document.getElementById('mw-modal-title-text').textContent =
            isSingle ? 'Record Payment' : 'Record Payment (' + ids.length + ' Invoices)';

        // Invoice list
        var listEl = document.getElementById('mw-payment-invoice-list');
        listEl.innerHTML = '';

        ids.forEach(function (id) {
            var inv = invoiceMap[id];
            total += inv.balance;

            var row = document.createElement('div');
            row.className = 'mw-payment-inv-row';
            row.innerHTML =
                '<div class="mw-payment-inv-info">' +
                    '<span class="mw-payment-inv-number">' + escHtml(inv.number) + '</span>' +
                    '<span class="mw-payment-inv-client">' + escHtml(inv.client) + '</span>' +
                '</div>' +
                '<div class="mw-payment-inv-amount">' + formatMoney(inv.balance) + '</div>';

            listEl.appendChild(row);
        });

        // Store IDs for submission
        listEl.dataset.invoiceIds = JSON.stringify(Object.keys(invoiceMap).map(Number));

        // Grand total
        document.getElementById('mw-payment-grand-total').textContent = formatMoney(total);

        // Reset form fields
        document.getElementById('mw-pay-method').value = 'e_transfer';
        document.getElementById('mw-pay-date').value   = new Date().toISOString().slice(0, 10);
        document.getElementById('mw-pay-ref').value    = '';
        document.getElementById('mw-pay-notes').value  = '';
        clearPayError();
    }

    // ── Toggle ref label based on method ──────────────────────────────────────
    window.mwToggleRefLabel = function () {
        var method = document.getElementById('mw-pay-method').value;
        var label  = document.getElementById('mw-ref-label');
        var input  = document.getElementById('mw-pay-ref');
        var labels = {
            e_transfer  : 'e-Transfer Confirmation #',
            cheque      : 'Cheque Number',
            cash        : 'Receipt / Notes',
            credit_card : 'Authorization Code',
            other       : 'Reference / Notes',
        };
        var placeholders = {
            e_transfer  : 'e.g., 123456789',
            cheque      : 'e.g., #1042',
            cash        : 'e.g., cash received',
            credit_card : 'e.g., auth code',
            other       : '',
        };
        label.textContent       = labels[method] || 'Reference';
        input.placeholder       = placeholders[method] || '';
    };

    // ── Close modal ────────────────────────────────────────────────────────────
    window.mwClosePaymentModal = function () {
        document.getElementById('mw-payment-modal').style.display = 'none';
        clearPayError();
    };

    // Close on overlay click
    document.getElementById('mw-payment-modal').addEventListener('click', function (e) {
        if (e.target === this) window.mwClosePaymentModal();
    });

    // ── Submit ─────────────────────────────────────────────────────────────────
    window.mwSubmitPayment = function () {
        var listEl     = document.getElementById('mw-payment-invoice-list');
        var invoiceIds = JSON.parse(listEl.dataset.invoiceIds || '[]');
        if (!invoiceIds.length) return;

        var method = document.getElementById('mw-pay-method').value;
        var date   = document.getElementById('mw-pay-date').value;
        var ref    = document.getElementById('mw-pay-ref').value.trim();
        var notes  = document.getElementById('mw-pay-notes').value.trim();

        clearPayError();
        setSubmitLoading(true);

        // Build form data (supports multiple invoice_ids[])
        var body = new URLSearchParams();
        body.append('csrf_token', CSRF_TOKEN);
        invoiceIds.forEach(function (id) { body.append('invoice_ids[]', id); });
        body.append('payment_method', method);
        body.append('payment_date', date);
        body.append('transaction_ref', ref);
        body.append('notes', notes);

        fetch('record-payment.php', {
            method  : 'POST',
            headers : { 'X-Requested-With': 'XMLHttpRequest' },
            body    : body,
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            setSubmitLoading(false);
            if (data.success) {
                mwClosePaymentModal();
                mwClearSelection();
                showToast(data.message, 'success');
                // Reload after a short delay so the user sees the toast
                setTimeout(function () { window.location.reload(); }, 1400);
            } else {
                showPayError(data.message || 'An error occurred. Please try again.');
            }
        })
        .catch(function (err) {
            setSubmitLoading(false);
            console.error('[mwPayment] fetch error:', err);
            showPayError('Network error — please try again.');
        });
    };

    // ── Helpers ────────────────────────────────────────────────────────────────
    function formatMoney(n) {
        return '$' + parseFloat(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }
    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function showPayError(msg) {
        var el = document.getElementById('mw-pay-error');
        if (!el) {
            el = document.createElement('div');
            el.id = 'mw-pay-error';
            el.className = 'alert alert-danger mt-3';
            document.querySelector('.mw-modal-body').appendChild(el);
        }
        el.textContent   = msg;
        el.style.display = 'block';
    }
    function clearPayError() {
        var el = document.getElementById('mw-pay-error');
        if (el) { el.style.display = 'none'; el.textContent = ''; }
    }
    function setSubmitLoading(loading) {
        var btn     = document.getElementById('mw-pay-submit');
        var label   = document.getElementById('mw-pay-submit-label');
        var spinner = document.getElementById('mw-pay-submit-spinner');
        btn.disabled          = loading;
        label.style.display   = loading ? 'none' : 'inline';
        spinner.style.display = loading ? 'inline' : 'none';
    }
    function showToast(msg, type) {
        var el = document.getElementById('mw-toast');
        el.textContent = msg;
        el.className   = 'mw-toast mw-toast-' + (type || 'success');
        el.style.display = 'flex';
        setTimeout(function () { el.style.display = 'none'; }, 3000);
    }

    // ── Inline bank-deposit matching ───────────────────────────────────────────
    window.mwToggleMatch = function (invoiceId) {
        var row = document.getElementById('mw-match-row-' + invoiceId);
        if (!row) return;
        var open = row.style.display !== 'none' && row.style.display !== '';
        row.style.display = open ? 'none' : 'table-row';
    };

    window.mwAttachDeposit = function (btn, invoiceId, txId, invNum) {
        var item      = btn.closest('.mw-match-item');
        var input     = item.querySelector('.mw-match-amount');
        var amount    = parseFloat(input.value);
        var balance   = parseFloat(input.dataset.balance);
        var remaining = parseFloat(input.dataset.remaining);

        if (!(amount > 0)) { showToast('Enter a valid amount.', 'error'); return; }
        if (amount > remaining + 0.005) {
            showToast('Amount exceeds the deposit (' + formatMoney(remaining) + ' available).', 'error');
            return;
        }
        if (amount > balance + 0.005) {
            if (!confirm('That is more than the invoice balance (' + formatMoney(balance)
                + '). It will be capped to the balance. Continue?')) { return; }
        }

        var origLabel = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Attaching…';

        fetch('/crm/api/accounting-reconciliation.php', {
            method : 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body   : JSON.stringify({
                action        : 'attach',
                csrf_token    : CSRF_TOKEN,
                transaction_id: txId,
                allocations   : [{ invoice_id: invoiceId, amount: amount }]
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                var rec = (data.result && data.result.recorded && data.result.recorded[0]) || {};
                showToast(invNum + ' — ' + (rec.fully_paid ? 'paid in full' : 'partial payment recorded') + '.', 'success');
                setTimeout(function () { window.location.reload(); }, 1200);
            } else {
                btn.disabled = false;
                btn.textContent = origLabel;
                showToast(data.error || 'Could not attach deposit.', 'error');
            }
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.textContent = origLabel;
            console.error('[mwAttachDeposit]', err);
            showToast('Network error — please try again.', 'error');
        });
    };

    // ── Pending e-Transfers panel ──────────────────────────────────────────
    var etPanel = document.getElementById('etransfers');
    if (etPanel) {
        etPanel.addEventListener('click', function (e) {
            var recordBtn  = e.target.closest('.mw-et-record');
            var dismissBtn = e.target.closest('.mw-et-dismiss');
            if (!recordBtn && !dismissBtn) return;

            var row = e.target.closest('.mw-et-row');
            if (!row) return;
            var id = row.getAttribute('data-id');

            var body = new FormData();
            body.append('csrf_token', CSRF_TOKEN);
            body.append('notification_id', id);

            if (recordBtn) {
                var inv = (row.querySelector('.mw-et-inv') || {}).value || '';
                var amt = (row.querySelector('.mw-et-amt') || {}).value || '';
                if (!inv.trim()) { showToast('Enter an invoice number first.', 'error'); return; }
                body.append('action', 'record');
                body.append('invoice_number', inv.trim());
                body.append('amount', amt);
                recordBtn.disabled = true;
                recordBtn.textContent = 'Recording…';
            } else {
                if (!confirm('Dismiss this e-Transfer? It will no longer show here.')) return;
                body.append('action', 'dismiss');
                dismissBtn.disabled = true;
            }

            fetch('/crm/api/etransfer-confirm.php', { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.ok) {
                        showToast(data.message || 'Done.', 'success');
                        row.parentNode.removeChild(row);
                        var remaining = etPanel.querySelectorAll('.mw-et-row').length;
                        var cnt = etPanel.querySelector('.mw-et-count');
                        if (cnt) cnt.textContent = remaining;
                        if (remaining === 0) etPanel.parentNode.removeChild(etPanel);
                    } else {
                        showToast(data.message || 'Could not record.', 'error');
                        if (recordBtn) { recordBtn.disabled = false; recordBtn.textContent = 'Record'; }
                        if (dismissBtn) dismissBtn.disabled = false;
                    }
                })
                .catch(function (err) {
                    console.error('[etransfer]', err);
                    showToast('Network error — please try again.', 'error');
                    if (recordBtn) { recordBtn.disabled = false; recordBtn.textContent = 'Record'; }
                    if (dismissBtn) dismissBtn.disabled = false;
                });
        });
    }

}());
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
