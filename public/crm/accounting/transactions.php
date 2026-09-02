<?php
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('expenses.view');

$canEdit = userHasPermission('expenses.edit');

$pageTitle  = 'Transaction Ledger';
$activePage = 'accounting';
$csrfToken  = generateCSRFToken();
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">Transaction Ledger</h1>
        <p class="text-muted mb-0 small">Every dollar in and out — synced from invoices and expenses</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($canEdit): ?>
        <button class="btn btn-sm btn-outline-secondary" onclick="syncLedger()">
            <i data-feather="refresh-cw" class="mw-btn-icon"></i> Sync
        </button>
        <button class="btn btn-sm btn-primary" onclick="openAddModal()">
            <i data-feather="plus" class="mw-btn-icon"></i> Add Entry
        </button>
        <?php endif; ?>
        <a href="/crm/accounting_appstack.php" class="btn btn-sm btn-outline-secondary">← Dashboard</a>
    </div>
</div>

<!-- ── Accounting Sub-Nav ────────────────────────────────────────────────────── -->
<div class="mw-filter-tabs mb-3">
    <a href="/crm/accounting_appstack.php" class="mw-filter-tab">Dashboard</a>
    <a href="/crm/accounting/transactions.php" class="mw-filter-tab active">Transactions</a>
    <a href="/crm/accounting/bank-import.php" class="mw-filter-tab">Bank Import</a>
    <a href="/crm/accounting/reports.php" class="mw-filter-tab">Reports</a>
    <a href="/crm/accounting/chart-of-accounts.php" class="mw-filter-tab">Chart of Accounts</a>
</div>

<!-- ── Filter Bar ──────────────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Type</label>
                <select id="f-type" class="form-select form-select-sm" onchange="loadTransactions()">
                    <option value="">All</option>
                    <option value="income">Income</option>
                    <option value="expense">Expense</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">From</label>
                <button type="button" class="mw-datepicker-trigger" data-mw-dp-commit="input" data-mw-dp-target="#f-date-from"
                        data-mw-dp-range-group="tx-filter-range" data-mw-dp-range-role="start" aria-haspopup="true" aria-expanded="false">
                    <svg class="mw-datepicker-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span class="mw-datepicker-date" data-mw-dp-label></span>
                    <svg class="mw-datepicker-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <input type="date" id="f-date-from" class="form-control form-control-sm" hidden
                       value="<?= date('Y-m-01') ?>" onchange="loadTransactions()">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">To</label>
                <button type="button" class="mw-datepicker-trigger" data-mw-dp-commit="input" data-mw-dp-target="#f-date-to"
                        data-mw-dp-range-group="tx-filter-range" data-mw-dp-range-role="end" aria-haspopup="true" aria-expanded="false">
                    <svg class="mw-datepicker-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span class="mw-datepicker-date" data-mw-dp-label></span>
                    <svg class="mw-datepicker-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <input type="date" id="f-date-to" class="form-control form-control-sm" hidden
                       value="<?= date('Y-m-d') ?>" onchange="loadTransactions()">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Account</label>
                <select id="f-account" class="form-select form-select-sm" onchange="loadTransactions()">
                    <option value="">All Accounts</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Source</label>
                <select id="f-source" class="form-select form-select-sm" onchange="loadTransactions()">
                    <option value="">All Sources</option>
                    <option value="invoice">Invoices</option>
                    <option value="expense">Expenses</option>
                    <option value="bank_import">Bank Import</option>
                    <option value="manual">Manual Entries</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Search</label>
                <input type="text" id="f-search" class="form-control form-control-sm"
                       placeholder="Description…" oninput="debounceSearch()">
            </div>
        </div>
        <div class="mt-2 d-flex gap-2 align-items-center">
            <div class="form-check form-check-inline mb-0">
                <input class="form-check-input" type="checkbox" id="f-review" onchange="loadTransactions()">
                <label class="form-check-label small" for="f-review">Needs Review Only</label>
            </div>
            <button class="btn btn-xs btn-outline-secondary" onclick="clearFilters()">Clear Filters</button>
            <span id="tx-count-label" class="text-muted small ms-auto"></span>
        </div>
    </div>
</div>

<!-- ── Summary Bar ─────────────────────────────────────────────────────────── -->
<div class="row g-2 mb-3" id="mw-tx-summary-row">
    <div class="col-4">
        <div class="mw-acct-mini-stat mw-acct-color-income">
            Revenue: <strong id="sum-revenue">—</strong>
        </div>
    </div>
    <div class="col-4">
        <div class="mw-acct-mini-stat mw-acct-color-expense">
            Expenses: <strong id="sum-expenses">—</strong>
        </div>
    </div>
    <div class="col-4">
        <div class="mw-acct-mini-stat" id="sum-net-wrap">
            Net: <strong id="sum-net">—</strong>
        </div>
    </div>
</div>

<!-- ── Transaction Table ──────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="mw-table mw-acct-table">
                <thead>
                    <tr>
                        <th style="width:100px">Date</th>
                        <th style="width:70px">Type</th>
                        <th>Description / Vendor</th>
                        <th>Account</th>
                        <th style="width:90px" class="text-end">Amount</th>
                        <th style="width:70px" class="text-end">GST</th>
                        <th style="width:60px" class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody id="tx-tbody">
                    <tr><td colspan="7" class="text-center text-muted py-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center py-2">
        <div id="tx-page-info" class="text-muted small"></div>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-secondary" id="btn-prev" onclick="changePage(-1)">← Prev</button>
            <button class="btn btn-sm btn-outline-secondary" id="btn-next" onclick="changePage(+1)">Next →</button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- ADD / EDIT MODAL                                                           -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="txModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="txModalTitle">Add Transaction</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="tx-id">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small">Date <span class="text-danger">*</span></label>
                        <button type="button" class="mw-datepicker-trigger" data-mw-dp-commit="input" data-mw-dp-target="#tx-date" aria-haspopup="true" aria-expanded="false">
                            <svg class="mw-datepicker-cal-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <span class="mw-datepicker-date" data-mw-dp-label></span>
                            <svg class="mw-datepicker-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <input type="date" id="tx-date" class="form-control" hidden value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Type <span class="text-danger">*</span></label>
                        <select id="tx-type" class="form-select" onchange="updateAccountDropdown()">
                            <option value="income">Income</option>
                            <option value="expense" selected>Expense</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label small">Account <span class="text-danger">*</span></label>
                        <select id="tx-account" class="form-select">
                            <option value="">— Select Account —</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Amount (excl. tax) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" id="tx-amount" class="form-control" step="0.01" min="0" placeholder="0.00" oninput="calcTax()">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">GST Amount</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" id="tx-gst" class="form-control" step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">PST Amount</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" id="tx-pst" class="form-control" step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Status</label>
                        <select id="tx-status" class="form-select">
                            <option value="cleared" selected>Cleared</option>
                            <option value="pending">Pending</option>
                            <option value="reconciled">Reconciled</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small">Description</label>
                        <input type="text" id="tx-desc" class="form-control" placeholder="What was this for?">
                    </div>
                    <div class="col-12">
                        <label class="form-label small">Notes (internal)</label>
                        <textarea id="tx-notes" class="form-control" rows="2" placeholder="Optional notes for accountant…"></textarea>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check">
                            <input type="checkbox" id="tx-review" class="form-check-input">
                            <label class="form-check-label small" for="tx-review">Flag for accountant review</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveTx()">Save Transaction</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Recategorize Modal ─────────────────────────────────────────────────────── -->
<div class="modal fade" id="recatModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Change Account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="recat-id">
                <label class="form-label small">Account</label>
                <select id="recat-account" class="form-select"></select>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" onclick="saveRecat()">Update</button>
            </div>
        </div>
    </div>
</div>

<script>
const API_TX  = '/crm/api/accounting-transactions.php';
const API_ACC = '/crm/api/accounting-accounts.php';
const CSRF    = '<?= htmlspecialchars($csrfToken) ?>';

let allAccounts = [];
let currentPage = 1;
let lastTotal   = 0;
const PER_PAGE  = 50;
let searchTimer;

// ── Bootstrap ────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
    await loadAccountList();
    loadTransactions();

    // Auto-open add modal from URL param
    if (new URLSearchParams(location.search).get('mode') === 'add') openAddModal();
    if (new URLSearchParams(location.search).get('needs_review') === '1') {
        document.getElementById('f-review').checked = true;
    }
});

// ── Accounts ─────────────────────────────────────────────────────────────────
async function loadAccountList() {
    const r = await fetch(API_ACC + '?action=list');
    const d = await r.json();
    if (!d.ok) return;
    allAccounts = d.accounts;
    populateAccountFilter(allAccounts);
    populateAccountDropdowns(allAccounts);
}

function populateAccountFilter(accounts) {
    const sel = document.getElementById('f-account');
    accounts.forEach(a => {
        if (a.sub_type === 'header') return;
        const o = new Option(`${a.code} – ${a.name}`, a.id);
        sel.appendChild(o);
    });
}

function populateAccountDropdowns(accounts) {
    const byType = { income: [], expense: [] };
    accounts.forEach(a => {
        if (a.sub_type === 'header') return;
        if (a.type === 'revenue') byType.income.push(a);
        if (a.type === 'expense') byType.expense.push(a);
    });
    window._acctByType = byType;

    [document.getElementById('tx-account'), document.getElementById('recat-account')].forEach(sel => {
        if (!sel) return;
        sel.innerHTML = '<option value="">— Select Account —</option>';
        ['expense', 'income'].forEach(t => {
            const grp = document.createElement('optgroup');
            grp.label = t === 'income' ? 'Revenue Accounts' : 'Expense Accounts';
            (byType[t] || []).forEach(a => {
                grp.appendChild(new Option(`${a.code} – ${a.name}`, a.id));
            });
            sel.appendChild(grp);
        });
    });
}

function updateAccountDropdown() {
    const type = document.getElementById('tx-type').value;
    const sel  = document.getElementById('tx-account');
    sel.innerHTML = '<option value="">— Select Account —</option>';
    const list = type === 'income'
        ? (window._acctByType?.income || [])
        : (window._acctByType?.expense || []);

    list.forEach(a => sel.appendChild(new Option(`${a.code} – ${a.name}`, a.id)));
}

// ── Load Transactions ─────────────────────────────────────────────────────────
async function loadTransactions(page) {
    if (page !== undefined) currentPage = page;

    const params = new URLSearchParams({
        action:      'list',
        page:        currentPage,
        per_page:    PER_PAGE,
        type:        document.getElementById('f-type').value,
        date_from:   document.getElementById('f-date-from').value,
        date_to:     document.getElementById('f-date-to').value,
        account_id:  document.getElementById('f-account').value,
        source:      document.getElementById('f-source').value,
        search:      document.getElementById('f-search').value,
        needs_review:document.getElementById('f-review').checked ? '1' : '',
    });

    document.getElementById('tx-tbody').innerHTML =
        '<tr><td colspan="7" class="text-center text-muted py-3">Loading…</td></tr>';

    const r = await fetch(API_TX + '?' + params);
    const d = await r.json();
    if (!d.ok) { console.error(d.error); return; }

    const res = d.result;
    lastTotal  = res.total;

    renderTable(res.data);
    updateSummary(res.data);
    updatePagination(res);
}

function renderTable(rows) {
    const tbody = document.getElementById('tx-tbody');
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">No transactions found for this filter.</td></tr>';
        return;
    }

    tbody.innerHTML = rows.map(tx => {
        const isIncome  = tx.type === 'income';
        const typeClass = isIncome ? 'mw-acct-badge-income' : 'mw-acct-badge-expense';
        const typeLabel = isIncome ? 'Revenue' : 'Expense';
        const amtClass  = isIncome ? 'mw-acct-color-income' : 'mw-acct-color-expense';

        // Left-side stripe:
        // Bank import rows: green = matched to system entry, red = unmatched
        // Other rows: amber = needs review, green = has vendor/is invoice, blue = auto-cat only
        let stripeClass = '';
        if (tx.needs_review == 1) {
            stripeClass = 'mw-tx-stripe-pending';   // amber — needs attention
        } else if (tx.reference_type === 'bank_import') {
            stripeClass = tx.bank_match_exists == 1 ? 'mw-tx-stripe-cleared' : 'mw-tx-stripe-unmatched';
        } else if (tx.vendor_id || tx.reference_type === 'invoice' || tx.reference_type === 'expense') {
            stripeClass = 'mw-tx-stripe-cleared';   // green — vendor known, invoice, or tracked expense
        } else if (tx.is_auto_categorized == 1) {
            stripeClass = 'mw-tx-stripe-reconciled'; // blue — rules engine guessed
        }
        // else no stripe — uncategorized misc

        // Badges — don't show auto-cat on invoices (it's a system artefact, not meaningful)
        const isInvoice  = tx.reference_type === 'invoice';
        const isBankImport = tx.reference_type === 'bank_import';
        const flagBtn    = tx.needs_review == 1
            ? `<span class="badge bg-warning text-dark" title="Needs Review" style="font-size:9px">Review</span> ` : '';
        const matchBadge = (!isInvoice && !isBankImport && tx.is_auto_categorized == 1)
            ? `<span class="mw-badge-autocat" title="Category set automatically by rules engine">auto-cat</span> `
            : '';
        // For bank import rows: show matched/unmatched badge instead of auto-cat
        let sourceBadge = '';
        if (isBankImport) {
            sourceBadge = tx.bank_match_exists == 1
                ? `<span class="mw-badge-receipt" title="Matched to a system entry — click to expand">✓ matched</span> `
                : `<span class="mw-badge-unmatched" title="No matching expense or invoice found">unmatched</span> `;
        }

        // Primary label:
        //   expenses  → vendor name (row click navigates to expense)
        //   invoices  → client name + address (row click navigates to invoice)
        //   bank/misc → raw description (bank matched rows expand on click)
        let label, subLine;
        if (tx.vendor_name) {
            label   = `<strong>${esc(tx.vendor_name)}</strong>`;
            subLine = tx.reference_id
                ? `<span class="text-muted" style="font-size:10px">click to expand</span>`
                : esc(tx.description || '').substring(0, 80);
        } else if (isInvoice) {
            const client = tx.client_name || '';
            const addr   = tx.property_address || '';
            const inv    = tx.invoice_number   || '';
            label   = client
                ? `<strong>${esc(client)}</strong>${addr ? `<span class="text-muted ms-1" style="font-size:11px">· ${esc(addr)}</span>` : ''}`
                : esc((tx.description || '').substring(0, 60));
            subLine = inv ? `<span class="text-muted" style="font-size:10px">${esc(inv)} · click to expand</span>` : '';
        } else if (isBankImport) {
            label   = esc((tx.description || '').substring(0, 60));
            subLine = tx.bank_match_exists == 1
                ? `<span class="text-muted" style="font-size:10px">click to expand</span>`
                : '';
        } else {
            label   = esc((tx.description || '').substring(0, 60));
            subLine = '';
        }

        // All linked rows expand; clicking the strip navigates to the item
        const isExpense = tx.reference_type === 'expense';
        const isBankMatchExpandable = isBankImport && tx.bank_match_exists == 1;
        // Unmatched bank rows expand too — into a "Find Expense Match" candidates
        // panel, not a navigation target (see fetchAndShowExpenseCandidates()).
        const isBankUnmatchedExpandable = isBankImport && tx.bank_match_exists != 1;
        const isExpandable = ((isInvoice || isExpense) && tx.reference_id) || isBankMatchExpandable || isBankUnmatchedExpandable;

        const rowClass = [
            tx.needs_review == 1 ? 'mw-acct-row-review' : '',
            isExpandable ? 'mw-tx-main-row' : '',
        ].filter(Boolean).join(' ');

        const clickAttr = isExpandable ? `onclick="toggleTxDetail(${tx.id})"` : '';

        const html = [`<tr class="${rowClass}" ${clickAttr}>
            <td class="text-nowrap small ${stripeClass}">${tx.transaction_date}</td>
            <td><span class="badge ${typeClass}">${typeLabel}</span></td>
            <td>
                <div>${label}</div>
                <div class="text-muted" style="font-size:11px">${flagBtn}${sourceBadge || matchBadge}${subLine}</div>
            </td>
            <td class="small">${esc(tx.account_code)} <span class="text-muted">${esc(tx.account_name)}</span></td>
            <td class="text-end ${amtClass} fw-bold">${fmtMoney(tx.amount)}</td>
            <td class="text-end text-muted small">${tx.gst_amount > 0 ? fmtMoney(tx.gst_amount) : '—'}</td>
            <td class="text-center">
                <div class="dropdown">
                    <button class="btn btn-xs btn-outline-secondary" data-bs-toggle="dropdown" onclick="event.stopPropagation()">⋯</button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item small" href="#" onclick="event.stopPropagation();openRecat(${tx.id}, ${tx.account_id})">Change Account</a></li>
                        ${isInvoice ? `<li><a class="dropdown-item small" href="/crm/invoices/view.php?id=${tx.reference_id}" onclick="event.stopPropagation()">View Invoice</a></li>` : ''}
                        ${tx.reference_type === 'expense' ? `<li><a class="dropdown-item small" href="/crm/expenses_appstack.php?edit=${tx.reference_id}" onclick="event.stopPropagation()">Edit Expense</a></li>` : ''}
                        ${isBankUnmatchedExpandable ? `<li><a class="dropdown-item small" href="#" onclick="event.stopPropagation();expandTxDetail(${tx.id})">Find Expense Match</a></li>` : ''}
                        <li><a class="dropdown-item small" href="#" onclick="event.stopPropagation();toggleReview(${tx.id}, ${tx.needs_review == 1 ? 0 : 1})">${tx.needs_review == 1 ? 'Clear Review Flag' : 'Flag for Review'}</a></li>
                        ${tx.reference_type === 'manual' ? `<li><hr class="dropdown-divider"></li><li><a class="dropdown-item small text-danger" href="#" onclick="event.stopPropagation();deleteTx(${tx.id})">Delete</a></li>` : ''}
                    </ul>
                </div>
            </td>
        </tr>`];

        // Expand detail row — clicking the strip navigates to the matched item
        if (isExpandable) {
            if (isBankMatchExpandable) {
                // Bank import: lazy-load via API; fetchAndShowMatch will set onclick after fetch
                html.push(`<tr class="mw-tx-detail-row" id="det-${tx.id}" style="display:none">
                    <td colspan="7">
                        <div id="det-content-${tx.id}" class="mw-tx-detail-strip text-muted" data-mode="match">Loading match…</div>
                    </td>
                </tr>`);
            } else if (isBankUnmatchedExpandable) {
                // Unmatched bank import: lazy-load candidate expenses to attach —
                // manual counterpart to findExpenseMatch()'s one-shot auto-match.
                html.push(`<tr class="mw-tx-detail-row" id="det-${tx.id}" style="display:none">
                    <td colspan="7">
                        <div id="det-content-${tx.id}" class="text-muted small p-2" data-mode="expense-candidates">Searching for matching receipts…</div>
                    </td>
                </tr>`);
            } else if (isInvoice) {
                const client = tx.client_name || tx.contact_name || '—';
                const addr   = tx.property_address || '';
                const inv    = tx.invoice_number   || '—';
                html.push(`<tr class="mw-tx-detail-row mw-tx-detail-nav" id="det-${tx.id}" style="display:none"
                    onclick="window.location='/crm/invoices/view.php?id=${tx.reference_id}'">
                    <td colspan="7">
                        <div class="mw-tx-detail-strip">
                            <span class="mw-tx-detail-docref">${esc(inv)}</span>
                            <span class="mw-tx-detail-client">${esc(client)}</span>
                            ${addr ? `<span class="mw-tx-detail-addr">${esc(addr)}</span>` : ''}
                            <div class="ms-auto d-flex align-items-center" style="gap:16px">
                                <span class="mw-tx-detail-amount">${fmtMoney(tx.amount)}</span>
                                ${tx.gst_amount > 0 ? `<span class="mw-tx-detail-addr">GST ${fmtMoney(tx.gst_amount)}</span>` : ''}
                            </div>
                        </div>
                    </td>
                </tr>`);
            } else {
                // Expense row
                const vendor = tx.vendor_name || tx.description || '—';
                html.push(`<tr class="mw-tx-detail-row mw-tx-detail-nav" id="det-${tx.id}" style="display:none"
                    onclick="window.location='/crm/expenses_appstack.php?edit=${tx.reference_id}'">
                    <td colspan="7">
                        <div class="mw-tx-detail-strip">
                            <span class="mw-tx-detail-docref">Receipt</span>
                            <span class="mw-tx-detail-client">${esc(vendor)}</span>
                            <div class="ms-auto d-flex align-items-center" style="gap:16px">
                                <span class="mw-tx-detail-amount">${fmtMoney(tx.amount)}</span>
                                ${tx.gst_amount > 0 ? `<span class="mw-tx-detail-addr">GST ${fmtMoney(tx.gst_amount)}</span>` : ''}
                            </div>
                        </div>
                    </td>
                </tr>`);
            }
        }

        return html.join('');
    }).join('');
}

function updateSummary(rows) {
    let rev = 0, exp = 0;
    rows.forEach(tx => {
        if (tx.type === 'income')  rev += parseFloat(tx.amount);
        if (tx.type === 'expense') exp += parseFloat(tx.amount);
    });
    document.getElementById('sum-revenue').textContent  = fmtMoney(rev);
    document.getElementById('sum-expenses').textContent = fmtMoney(exp);
    const net    = rev - exp;
    const netEl  = document.getElementById('sum-net');
    const wrap   = document.getElementById('sum-net-wrap');
    netEl.textContent = fmtMoney(Math.abs(net));
    wrap.className    = 'mw-acct-mini-stat ' + (net >= 0 ? 'mw-acct-color-income' : 'mw-acct-color-expense');
    netEl.textContent = (net < 0 ? '−' : '+') + fmtMoney(Math.abs(net));
}

function updatePagination(res) {
    document.getElementById('tx-page-info').textContent =
        `${res.total} transactions · Page ${res.page} of ${res.last_page}`;
    document.getElementById('btn-prev').disabled = res.page <= 1;
    document.getElementById('btn-next').disabled = res.page >= res.last_page;
    document.getElementById('tx-count-label').textContent = `${res.total} result${res.total !== 1 ? 's' : ''}`;
}

function changePage(delta) { loadTransactions(currentPage + delta); }

// ── Add/Edit Modal ────────────────────────────────────────────────────────────
function openAddModal() {
    document.getElementById('txModalTitle').textContent = 'Add Transaction';
    document.getElementById('tx-id').value      = '';
    document.getElementById('tx-date').value    = new Date().toISOString().split('T')[0];
    document.getElementById('tx-date').dispatchEvent(new Event('input', { bubbles: true }));
    document.getElementById('tx-date').dispatchEvent(new Event('change', { bubbles: true }));
    document.getElementById('tx-type').value    = 'expense';
    document.getElementById('tx-amount').value  = '';
    document.getElementById('tx-gst').value     = '';
    document.getElementById('tx-pst').value     = '';
    document.getElementById('tx-desc').value    = '';
    document.getElementById('tx-notes').value   = '';
    document.getElementById('tx-status').value  = 'cleared';
    document.getElementById('tx-review').checked = false;
    updateAccountDropdown();
    new bootstrap.Modal(document.getElementById('txModal')).show();
}

function calcTax() {
    const amt = parseFloat(document.getElementById('tx-amount').value) || 0;
    const gst = Math.round(amt * 0.05 * 100) / 100;
    document.getElementById('tx-gst').value = gst.toFixed(2);
}

async function saveTx() {
    const id = document.getElementById('tx-id').value;
    const data = {
        action:           id ? 'update' : 'create',
        id:               id || undefined,
        transaction_date: document.getElementById('tx-date').value,
        type:             document.getElementById('tx-type').value,
        account_id:       document.getElementById('tx-account').value,
        amount:           document.getElementById('tx-amount').value,
        gst_amount:       document.getElementById('tx-gst').value,
        pst_amount:       document.getElementById('tx-pst').value,
        description:      document.getElementById('tx-desc').value,
        notes:            document.getElementById('tx-notes').value,
        status:           document.getElementById('tx-status').value,
        needs_review:     document.getElementById('tx-review').checked ? 1 : 0,
    };

    const r = await fetch(API_TX, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
    });
    const d = await r.json();
    if (!d.ok) { alert('Error: ' + d.error); return; }

    bootstrap.Modal.getInstance(document.getElementById('txModal'))?.hide();
    loadTransactions(1);
}

// ── Recategorize ──────────────────────────────────────────────────────────────
function openRecat(txId, currentAccountId) {
    document.getElementById('recat-id').value = txId;
    document.getElementById('recat-account').value = currentAccountId;
    new bootstrap.Modal(document.getElementById('recatModal')).show();
}

async function saveRecat() {
    const id        = document.getElementById('recat-id').value;
    const accountId = document.getElementById('recat-account').value;
    const r = await fetch(API_TX, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'recategorize', id, account_id: accountId }),
    });
    const d = await r.json();
    if (!d.ok) { alert('Error: ' + d.error); return; }
    bootstrap.Modal.getInstance(document.getElementById('recatModal'))?.hide();
    loadTransactions();
}

// ── Expand detail row (invoice or bank import match) ──────────────────────────
function toggleTxDetail(id) {
    const row = document.getElementById('det-' + id);
    if (!row) return;
    const isHidden = row.style.display === 'none';
    row.style.display = isHidden ? '' : 'none';
    // Lazy-load on first expand — mode set server-side via data-mode.
    const contentEl = document.getElementById('det-content-' + id);
    if (isHidden && contentEl && contentEl.dataset.loaded !== '1') {
        contentEl.dataset.loaded = '1';
        if (contentEl.dataset.mode === 'expense-candidates') {
            fetchAndShowExpenseCandidates(id, contentEl);
        } else {
            fetchAndShowMatch(id, contentEl);
        }
    }
}

/** Expand (not toggle) a detail row — used by the "Find Expense Match" dropdown
 *  action so it always opens the panel rather than collapsing an open one. */
function expandTxDetail(id) {
    const row = document.getElementById('det-' + id);
    if (row && row.style.display === 'none') toggleTxDetail(id);
}

async function fetchAndShowMatch(txId, contentEl) {
    try {
        const r = await fetch(API_TX + '?action=find_match&id=' + txId);
        const d = await r.json();
        if (!d.ok || !d.match) {
            contentEl.innerHTML = '<span class="text-muted">No match found</span>';
            return;
        }
        const m = d.match;
        const isInv = m.reference_type === 'invoice';
        const label = m.vendor_name || m.client_name || m.contact_name || m.description || '—';
        const docRef = isInv ? (m.invoice_number || 'Invoice') : 'Receipt';
        const viewUrl = isInv
            ? `/crm/invoices/view.php?id=${m.reference_id}`
            : `/crm/expenses_appstack.php?edit=${m.reference_id}`;
        const acctLabel = [m.account_code, m.account_name].filter(Boolean).join(' ');

        // Make the entire detail row navigate to the matched item on click
        const detRow = document.getElementById('det-' + txId);
        if (detRow) {
            detRow.classList.add('mw-tx-detail-nav');
            detRow.onclick = () => { window.location = viewUrl; };
        }

        contentEl.innerHTML = `
            <div class="mw-tx-detail-strip">
                <span class="mw-tx-detail-docref">${esc(docRef)}</span>
                <span class="mw-tx-detail-client">${esc(label)}</span>
                <span class="mw-tx-detail-addr">${esc(m.transaction_date)}${acctLabel ? ' · ' + esc(acctLabel) : ''}</span>
                <div class="ms-auto d-flex align-items-center" style="gap:16px">
                    <span class="mw-tx-detail-amount">${fmtMoney(m.amount)}</span>
                    ${parseFloat(m.gst_amount) > 0 ? `<span class="mw-tx-detail-addr">GST ${fmtMoney(m.gst_amount)}</span>` : ''}
                </div>
            </div>`;
    } catch (e) {
        contentEl.innerHTML = '<span class="text-danger">Error loading match</span>';
    }
}

// ── Manual expense match (unmatched bank rows) ──────────────────────────────────
// findExpenseMatch() only runs once, at import time, and only considers
// approved/forwarded expenses — this is the human-reviewed fallback for whatever
// it missed (receipt still draft at import time, amount/date drift), reachable
// from a row's "Find Expense Match" dropdown action / by expanding the row.
async function fetchAndShowExpenseCandidates(txId, contentEl) {
    try {
        const r = await fetch('/crm/api/accounting-reconciliation.php?action=transaction_expense_candidates&transaction_id=' + txId);
        const d = await r.json();
        if (!d.ok) throw new Error(d.error || 'Search failed');
        if (!d.candidates.length) {
            contentEl.innerHTML = '<span class="text-muted">No matching receipts found.</span>';
            return;
        }
        contentEl.innerHTML = d.candidates.map(c => {
            const confClass = c.confidence >= 70 ? 'high' : (c.confidence >= 45 ? 'med' : 'low');
            const statusTag = (c.status === 'draft' || c.status === 'pending_approval')
                ? `<span class="badge bg-light text-dark border ms-1" style="font-size:9px">${c.status === 'draft' ? 'Draft' : 'Pending'}</span>`
                : '';
            const reasonsHtml = (c.reasons || []).map(rz => `<span class="mw-match-reason">${esc(rz)}</span>`).join('');
            return `<div class="mw-match-item">
                <span class="mw-conf-badge mw-conf-${confClass}">${c.confidence}%</span>
                <div class="mw-match-info">
                    <div class="mw-match-desc">${esc(c.vendor)}${statusTag}</div>
                    <div class="mw-match-meta">${esc(c.date)} &middot; ${fmtMoney(c.amount)}${c.category ? ' &middot; ' + esc(c.category) : ''}${reasonsHtml}</div>
                </div>
                <div class="mw-match-apply">
                    <button type="button" class="mw-action-btn mw-action-btn-paid" onclick="event.stopPropagation();attachTxToExpense(${txId}, ${c.expense_id})">Attach</button>
                </div>
            </div>`;
        }).join('');
    } catch (e) {
        contentEl.innerHTML = '<span class="text-danger">Error: ' + esc(e.message) + '</span>';
    }
}

async function attachTxToExpense(txId, expenseId) {
    try {
        const r = await fetch('/crm/api/accounting-reconciliation.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'attach_expense', csrf_token: CSRF, transaction_id: txId, expense_id: expenseId }),
        });
        const d = await r.json();
        if (!d.ok) throw new Error(d.error || 'Attach failed');
        loadTransactions(currentPage);
    } catch (e) {
        alert('Attach error: ' + e.message);
    }
}

// ── Review Flag ───────────────────────────────────────────────────────────────
async function toggleReview(id, flag) {
    await fetch(API_TX, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'flag_review', id, flag }),
    });
    loadTransactions();
}

// ── Delete ────────────────────────────────────────────────────────────────────
async function deleteTx(id) {
    if (!confirm('Delete this manual transaction? This cannot be undone.')) return;
    const r = await fetch(API_TX, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id }),
    });
    const d = await r.json();
    if (!d.ok) { alert(d.error); return; }
    loadTransactions();
}

// ── Sync ──────────────────────────────────────────────────────────────────────
async function syncLedger() {
    if (!confirm('Sync ledger? This will import any new paid invoices and expenses.')) return;
    const r = await fetch(API_TX + '?action=sync');
    const d = await r.json();
    if (d.ok) {
        const res = d.result;
        const removedNote = res.removed_orphans > 0 ? `\nRemoved stale entries: ${res.removed_orphans}` : '';
        alert(`Sync complete.\n\nInvoices: ${res.invoices_synced} added, ${res.invoices_updated} updated\nExpenses: ${res.expenses_synced} added, ${res.expenses_updated} updated\nAuto-categorized: ${res.rules_applied}${removedNote}`);
        loadTransactions();
    } else {
        alert('Sync failed: ' + d.error);
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmtMoney(v) {
    return '$' + parseFloat(v || 0).toLocaleString('en-CA', {minimumFractionDigits:2, maximumFractionDigits:2});
}
function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function clearFilters() {
    document.getElementById('f-type').value     = '';
    document.getElementById('f-date-from').value = '<?= date('Y-m-01') ?>';
    document.getElementById('f-date-from').dispatchEvent(new Event('input', { bubbles: true }));
    document.getElementById('f-date-from').dispatchEvent(new Event('change', { bubbles: true }));
    document.getElementById('f-date-to').value   = '<?= date('Y-m-d') ?>';
    document.getElementById('f-date-to').dispatchEvent(new Event('input', { bubbles: true }));
    document.getElementById('f-date-to').dispatchEvent(new Event('change', { bubbles: true }));
    document.getElementById('f-account').value   = '';
    document.getElementById('f-source').value    = '';
    document.getElementById('f-search').value    = '';
    document.getElementById('f-review').checked  = false;
    loadTransactions(1);
}
function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadTransactions(1), 400);
}
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
