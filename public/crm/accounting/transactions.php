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
                <input type="date" id="f-date-from" class="form-control form-control-sm"
                       value="<?= date('Y-m-01') ?>" onchange="loadTransactions()">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">To</label>
                <input type="date" id="f-date-to" class="form-control form-control-sm"
                       value="<?= date('Y-m-d') ?>" onchange="loadTransactions()">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Account</label>
                <select id="f-account" class="form-select form-select-sm" onchange="loadTransactions()">
                    <option value="">All Accounts</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1">Status</label>
                <select id="f-status" class="form-select form-select-sm" onchange="loadTransactions()">
                    <option value="">All</option>
                    <option value="cleared">Cleared</option>
                    <option value="pending">Pending</option>
                    <option value="reconciled">Reconciled</option>
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
                        <th style="width:80px">Status</th>
                        <th style="width:60px" class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody id="tx-tbody">
                    <tr><td colspan="8" class="text-center text-muted py-4">Loading…</td></tr>
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
                        <input type="date" id="tx-date" class="form-control" value="<?= date('Y-m-d') ?>">
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
        status:      document.getElementById('f-status').value,
        search:      document.getElementById('f-search').value,
        needs_review:document.getElementById('f-review').checked ? '1' : '',
    });

    document.getElementById('tx-tbody').innerHTML =
        '<tr><td colspan="8" class="text-center text-muted py-3">Loading…</td></tr>';

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
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">No transactions found for this filter.</td></tr>';
        return;
    }

    tbody.innerHTML = rows.map(tx => {
        const isIncome = tx.type === 'income';
        const typeClass = isIncome ? 'mw-acct-badge-income' : 'mw-acct-badge-expense';
        const typeLabel = isIncome ? 'Revenue' : 'Expense';
        const amtClass  = isIncome ? 'mw-acct-color-income' : 'mw-acct-color-expense';
        const flagBtn   = tx.needs_review == 1
            ? `<span class="badge bg-warning text-dark" title="Needs Review" style="font-size:9px">Review</span> ` : '';
        const autoTag   = tx.is_auto_categorized == 1
            ? `<span class="badge bg-light text-secondary" style="font-size:9px;border:1px solid #dee2e6">auto</span> ` : '';
        const label     = tx.vendor_name
            ? `<strong>${esc(tx.vendor_name)}</strong>`
            : esc((tx.description || '').substring(0, 60));

        const statusBadge = {
            cleared:    '<span class="badge bg-success bg-opacity-10 text-success">Cleared</span>',
            pending:    '<span class="badge bg-warning bg-opacity-10 text-warning">Pending</span>',
            reconciled: '<span class="badge bg-primary bg-opacity-10 text-primary">Reconciled</span>',
        }[tx.status] || tx.status;

        return `<tr class="${tx.needs_review == 1 ? 'mw-acct-row-review' : ''}">
            <td class="text-nowrap small">${tx.transaction_date}</td>
            <td><span class="badge ${typeClass}">${typeLabel}</span></td>
            <td>
                <div>${label}</div>
                <div class="text-muted" style="font-size:11px">${flagBtn}${autoTag}${esc(tx.description || '').substring(0, 80)}</div>
            </td>
            <td class="small">${esc(tx.account_code)} <span class="text-muted">${esc(tx.account_name)}</span></td>
            <td class="text-end ${amtClass} fw-bold">${fmtMoney(tx.amount)}</td>
            <td class="text-end text-muted small">${tx.gst_amount > 0 ? fmtMoney(tx.gst_amount) : '—'}</td>
            <td>${statusBadge}</td>
            <td class="text-center">
                <div class="dropdown">
                    <button class="btn btn-xs btn-outline-secondary" data-bs-toggle="dropdown">⋯</button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item small" href="#" onclick="openRecat(${tx.id}, ${tx.account_id})">Change Account</a></li>
                        ${tx.reference_type === 'invoice' ? `<li><a class="dropdown-item small" href="/crm/invoices/view.php?id=${tx.reference_id}">View Invoice</a></li>` : ''}
                        ${tx.reference_type === 'expense' ? `<li><a class="dropdown-item small" href="/crm/expenses_appstack.php?highlight=${tx.reference_id}">View Expense</a></li>` : ''}
                        <li><a class="dropdown-item small" href="#" onclick="toggleReview(${tx.id}, ${tx.needs_review == 1 ? 0 : 1})">${tx.needs_review == 1 ? 'Clear Review Flag' : 'Flag for Review'}</a></li>
                        ${tx.reference_type === 'manual' ? `<li><hr class="dropdown-divider"><li><a class="dropdown-item small text-danger" href="#" onclick="deleteTx(${tx.id})">Delete</a></li>` : ''}
                    </ul>
                </div>
            </td>
        </tr>`;
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
        alert(`Sync complete.\n\nInvoices: ${res.invoices_synced} added, ${res.invoices_updated} updated\nExpenses: ${res.expenses_synced} added, ${res.expenses_updated} updated\nAuto-categorized: ${res.rules_applied}`);
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
    document.getElementById('f-date-to').value   = '<?= date('Y-m-d') ?>';
    document.getElementById('f-account').value   = '';
    document.getElementById('f-status').value    = '';
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
