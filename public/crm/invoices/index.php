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

// Build query
$db     = getDB();
$params = [];
$whereConditions = ['1=1'];

if ($statusFilter) {
    $whereConditions[] = 'i.status = ?';
    $params[] = $statusFilter;
}

if ($searchQuery) {
    $whereConditions[] = '(i.invoice_number LIKE ? OR c.company_name LIKE ? OR CONCAT(ct.first_name," ",ct.last_name) LIKE ?)';
    $searchParam = "%{$searchQuery}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$whereClause = implode(' AND ', $whereConditions);

$stmt = $db->prepare("
    SELECT
        i.*,
        COALESCE(c.company_name, CONCAT(ct.first_name,' ',ct.last_name)) as display_client,
        c.company_name,
        ct.first_name as contact_first,
        ct.last_name  as contact_last,
        jp.plan_number,
        jp.title as plan_title
    FROM invoices i
    LEFT JOIN companies  c  ON i.company_id = c.id
    LEFT JOIN contacts   ct ON i.contact_id = ct.id
    LEFT JOIN job_plans  jp ON i.plan_id    = jp.id
    LEFT JOIN job_visits jv ON i.visit_id   = jv.id
    WHERE {$whereClause}
    ORDER BY i.created_at DESC
    LIMIT 200
");
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts
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

$csrfToken = generateCSRFToken();

$pageTitle  = 'Invoices';
$activePage = 'invoices';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-1">Invoices</h1>
                    <p class="text-muted mb-0">Track payments and manage billing</p>
                </div>
                <a href="create.php" class="btn btn-primary">
                    <i data-feather="plus" style="width:16px;height:16px;margin-right:4px;vertical-align:middle;"></i>
                    Create Invoice
                </a>
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
                        Overdue <span class="count"><?php echo $statusCounts['overdue'] ?? 0; ?></span>
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
                                <th>Invoice #</th>
                                <th>Client</th>
                                <th>Plan</th>
                                <th class="text-right">Amount</th>
                                <th class="text-right">Balance</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoices as $invoice): ?>
                                <?php
                                $isPayable = in_array($invoice['status'], ['sent', 'viewed', 'partial', 'overdue']);
                                $balance   = floatval($invoice['balance_due']);
                                $client    = htmlspecialchars($invoice['display_client'] ?? $invoice['company_name'] ?? 'N/A');
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
                                        <a href="view.php?id=<?php echo $invoice['id']; ?>" class="invoice-number">
                                            <?php echo htmlspecialchars($invoice['invoice_number']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo $client; ?></td>
                                    <td>
                                        <?php if (!empty($invoice['plan_number'])): ?>
                                            <a href="../jobs/view.php?id=<?php echo $invoice['plan_id']; ?>">
                                                <?php echo htmlspecialchars($invoice['plan_number']); ?>
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
                                    <td><?php echo getStatusBadge($invoice['status'], 'invoice'); ?></td>
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
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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

}());
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
