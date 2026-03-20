<?php
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('expenses.view');

$canEdit = userHasPermission('expenses.edit');

$pageTitle  = 'Chart of Accounts';
$activePage = 'accounting';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">Chart of Accounts</h1>
        <p class="text-muted mb-0 small">Your financial account structure</p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($canEdit): ?>
        <button class="btn btn-sm btn-primary" onclick="openAddModal()">
            <i data-feather="plus" class="mw-btn-icon"></i> New Account
        </button>
        <?php endif; ?>
        <a href="/crm/accounting_appstack.php" class="btn btn-sm btn-outline-secondary">← Dashboard</a>
    </div>
</div>

<!-- ── Type Filter Tabs ─────────────────────────────────────────────────────── -->
<div class="mw-filter-tabs mb-3">
    <button class="mw-filter-tab active" onclick="filterType('')">All</button>
    <button class="mw-filter-tab" onclick="filterType('revenue')">Revenue</button>
    <button class="mw-filter-tab" onclick="filterType('expense')">Expenses</button>
    <button class="mw-filter-tab" onclick="filterType('asset')">Assets</button>
    <button class="mw-filter-tab" onclick="filterType('liability')">Liabilities</button>
    <button class="mw-filter-tab" onclick="filterType('equity')">Equity</button>
</div>

<!-- ── Account Table ──────────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mw-acct-table mb-0">
                <thead>
                    <tr>
                        <th style="width:90px">Code</th>
                        <th>Account Name</th>
                        <th style="width:80px">Type</th>
                        <th style="width:90px">Sub-Type</th>
                        <th style="width:80px" class="text-end"># Transactions</th>
                        <th style="width:70px">Status</th>
                        <?php if ($canEdit): ?>
                        <th style="width:60px" class="text-center">Edit</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="coa-tbody">
                    <tr><td colspan="7" class="text-center text-muted py-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── Add/Edit Modal ─────────────────────────────────────────────────────────── -->
<?php if ($canEdit): ?>
<div class="modal fade" id="coaModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="coaModalTitle">New Account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="coa-id">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label small">Code <span class="text-danger">*</span></label>
                        <input type="text" id="coa-code" class="form-control" placeholder="e.g. 6150">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label small">Account Name <span class="text-danger">*</span></label>
                        <input type="text" id="coa-name" class="form-control" placeholder="e.g. Uniforms & Safety Gear">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Type <span class="text-danger">*</span></label>
                        <select id="coa-type" class="form-select">
                            <option value="asset">Asset</option>
                            <option value="liability">Liability</option>
                            <option value="equity">Equity</option>
                            <option value="revenue">Revenue</option>
                            <option value="expense" selected>Expense</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Sub-Type</label>
                        <input type="text" id="coa-subtype" class="form-control" placeholder="e.g. vehicle, labour">
                    </div>
                    <div class="col-12">
                        <label class="form-label small">Description</label>
                        <textarea id="coa-desc" class="form-control" rows="2" placeholder="What kinds of transactions go in this account?"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Display Order</label>
                        <input type="number" id="coa-order" class="form-control" value="500">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Category Alias <small class="text-muted">(links to expense system)</small></label>
                        <input type="text" id="coa-alias" class="form-control" placeholder="e.g. fuel, materials">
                    </div>
                    <div class="col-12" id="coa-status-row" style="display:none">
                        <div class="form-check">
                            <input type="checkbox" id="coa-active" class="form-check-input" checked>
                            <label class="form-check-label small" for="coa-active">Account is active</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveCoa()">Save Account</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const API_ACC = '/crm/api/accounting-accounts.php';
let allAccounts = [];
let activeFilter = '';

document.addEventListener('DOMContentLoaded', loadAccounts);

async function loadAccounts() {
    const r = await fetch(API_ACC + '?action=list&active_only=0');
    const d = await r.json();
    if (!d.ok) return;
    allAccounts = d.accounts;
    renderTable(allAccounts);
}

function filterType(type) {
    activeFilter = type;
    document.querySelectorAll('.mw-filter-tab').forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
    renderTable(type ? allAccounts.filter(a => a.type === type) : allAccounts);
}

const TYPE_COLORS = {
    revenue:   'success',
    expense:   'danger',
    asset:     'primary',
    liability: 'warning',
    equity:    'secondary',
};
const SYSTEM_ACCOUNTS = true; // show system accounts

function renderTable(accounts) {
    const tbody = document.getElementById('coa-tbody');
    if (!accounts.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">No accounts found.</td></tr>';
        return;
    }

    tbody.innerHTML = accounts.map(a => {
        const typeColor  = TYPE_COLORS[a.type] || 'secondary';
        const isHeader   = a.sub_type === 'header';
        const sysIcon    = a.is_system == 1 ? '<span class="text-muted ms-1" title="System account" style="font-size:10px">🔒</span>' : '';
        const inactiveTag= a.is_active == 0 ? '<span class="badge bg-light text-secondary ms-1" style="font-size:10px">Inactive</span>' : '';
        const rowClass   = isHeader ? 'mw-acct-row-header' : (a.is_active == 0 ? 'text-muted' : '');

        const editBtn = (<?= $canEdit ? 'true' : 'false' ?> && !isHeader)
            ? `<button class="btn btn-xs btn-outline-secondary" onclick="openEditModal(${a.id})">Edit</button>`
            : '';

        return `<tr class="${rowClass}">
            <td class="fw-mono small">${esc(a.code)}</td>
            <td>
                ${isHeader ? '<strong>' : ''}
                ${a.parent_id ? '<span class="text-muted me-1">└</span>' : ''}
                ${esc(a.name)} ${sysIcon} ${inactiveTag}
                ${isHeader ? '</strong>' : ''}
                ${a.description ? `<div class="text-muted" style="font-size:11px">${esc(a.description)}</div>` : ''}
            </td>
            <td><span class="badge bg-${typeColor} bg-opacity-10 text-${typeColor}">${a.type}</span></td>
            <td class="text-muted small">${esc(a.sub_type || '')}</td>
            <td class="text-end small">${a.transaction_count > 0 ? Number(a.transaction_count).toLocaleString() : '—'}</td>
            <td>${a.is_active == 1 ? '<span class="badge bg-success bg-opacity-10 text-success">Active</span>' : '<span class="badge bg-secondary bg-opacity-10 text-secondary">Inactive</span>'}</td>
            ${<?= $canEdit ? 'true' : 'false' ?> ? `<td class="text-center">${editBtn}</td>` : ''}
        </tr>`;
    }).join('');
}

function openAddModal() {
    document.getElementById('coaModalTitle').textContent = 'New Account';
    document.getElementById('coa-id').value       = '';
    document.getElementById('coa-code').value     = '';
    document.getElementById('coa-name').value     = '';
    document.getElementById('coa-type').value     = 'expense';
    document.getElementById('coa-subtype').value  = '';
    document.getElementById('coa-desc').value     = '';
    document.getElementById('coa-order').value    = '500';
    document.getElementById('coa-alias').value    = '';
    document.getElementById('coa-status-row').style.display = 'none';
    new bootstrap.Modal(document.getElementById('coaModal')).show();
}

function openEditModal(id) {
    const a = allAccounts.find(x => x.id == id);
    if (!a) return;

    document.getElementById('coaModalTitle').textContent = 'Edit Account';
    document.getElementById('coa-id').value       = a.id;
    document.getElementById('coa-code').value     = a.code;
    document.getElementById('coa-name').value     = a.name;
    document.getElementById('coa-type').value     = a.type;
    document.getElementById('coa-subtype').value  = a.sub_type || '';
    document.getElementById('coa-desc').value     = a.description || '';
    document.getElementById('coa-order').value    = a.display_order;
    document.getElementById('coa-alias').value    = a.expense_category_alias || '';
    document.getElementById('coa-active').checked = a.is_active == 1;
    document.getElementById('coa-status-row').style.display = '';
    new bootstrap.Modal(document.getElementById('coaModal')).show();
}

async function saveCoa() {
    const id = document.getElementById('coa-id').value;
    const data = {
        action:                  id ? 'update' : 'create',
        id:                      id || undefined,
        code:                    document.getElementById('coa-code').value,
        name:                    document.getElementById('coa-name').value,
        type:                    document.getElementById('coa-type').value,
        sub_type:                document.getElementById('coa-subtype').value,
        description:             document.getElementById('coa-desc').value,
        display_order:           document.getElementById('coa-order').value,
        expense_category_alias:  document.getElementById('coa-alias').value,
        is_active:               document.getElementById('coa-active')?.checked ? 1 : 0,
    };

    const r = await fetch(API_ACC, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
    });
    const d = await r.json();
    if (!d.ok) { alert('Error: ' + d.error); return; }
    bootstrap.Modal.getInstance(document.getElementById('coaModal'))?.hide();
    await loadAccounts();
}

function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
