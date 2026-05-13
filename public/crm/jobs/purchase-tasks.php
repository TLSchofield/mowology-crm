<?php
/**
 * Purchase Tasks — Procurement Management
 * /crm/jobs/purchase-tasks.php
 *
 * Lists all purchase tasks (vendor runs, supply pickups).
 * Create / edit via modal. Inline status transitions.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('schedule.view');

$db    = getDB();
$csrf  = generateCSRFToken();
$staff = getStaffMembers();

// ── Filters ────────────────────────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? '';
$filterCrew   = isset($_GET['crew']) && $_GET['crew'] !== '' ? (int)$_GET['crew'] : null;
$filterFrom   = $_GET['from'] ?? date('Y-m-d', strtotime('-14 days'));
$filterTo     = $_GET['to']   ?? date('Y-m-d', strtotime('+14 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) $filterFrom = date('Y-m-d', strtotime('-14 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo))   $filterTo   = date('Y-m-d', strtotime('+14 days'));

// ── Load tasks ─────────────────────────────────────────────────────────────────
$where  = ['pt.task_date BETWEEN ? AND ?'];
$params = [$filterFrom, $filterTo];

if ($filterStatus !== '') {
    $where[]  = 'pt.purchase_status = ?';
    $params[] = $filterStatus;
}
if ($filterCrew !== null) {
    $where[]  = 'pt.assigned_to_id = ?';
    $params[] = $filterCrew;
}

$whereClause = implode(' AND ', $where);

$stmt = $db->prepare("
    SELECT pt.*,
           u.full_name AS assigned_to_name,
           cb.full_name AS created_by_name,
           (SELECT COUNT(*) FROM purchase_task_items pti WHERE pti.task_id = pt.id) AS item_count,
           (SELECT COUNT(*) FROM purchase_task_items pti WHERE pti.task_id = pt.id AND pti.is_purchased = 1) AS items_done
    FROM purchase_tasks pt
    LEFT JOIN users u  ON pt.assigned_to_id = u.id
    LEFT JOIN users cb ON pt.created_by_id  = cb.id
    WHERE {$whereClause}
    ORDER BY pt.task_date DESC, pt.priority = 'urgent' DESC, pt.id DESC
");
$stmt->execute($params);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Google Maps API key ────────────────────────────────────────────────────────
$apiKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';

// ── Page setup ─────────────────────────────────────────────────────────────────
$pageTitle  = 'Purchase Tasks';
$activePage = 'purchase-tasks';
if ($apiKey) {
    $extraHead = '<script src="https://maps.googleapis.com/maps/api/js?key='
        . htmlspecialchars($apiKey, ENT_QUOTES, 'UTF-8')
        . '&libraries=places" defer></script>';
}
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

            <!-- Page header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-1">Purchase Tasks</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0 bg-transparent p-0">
                            <li class="breadcrumb-item"><a href="schedule.php">Schedule</a></li>
                            <li class="breadcrumb-item active">Purchase Tasks</li>
                        </ol>
                    </nav>
                </div>
                <?php if (in_array($user['role'] ?? '', ['admin', 'manager'])): ?>
                <button class="btn btn-primary mw-btn-primary" id="btnNewTask">
                    <i data-feather="plus" class="mr-1"></i> New Task
                </button>
                <?php endif; ?>
            </div>

            <!-- Filter bar -->
            <div class="card mb-4">
                <div class="card-body py-3">
                    <form method="get" class="form-inline flex-wrap" style="gap:10px">
                        <div class="form-group mb-0">
                            <label class="sr-only">From</label>
                            <input type="date" name="from" class="form-control form-control-sm"
                                   value="<?= htmlspecialchars($filterFrom) ?>">
                        </div>
                        <span class="text-muted">to</span>
                        <div class="form-group mb-0">
                            <label class="sr-only">To</label>
                            <input type="date" name="to" class="form-control form-control-sm"
                                   value="<?= htmlspecialchars($filterTo) ?>">
                        </div>
                        <div class="form-group mb-0">
                            <select name="status" class="form-control form-control-sm">
                                <option value="">All statuses</option>
                                <option value="pending"    <?= $filterStatus === 'pending'    ? 'selected' : '' ?>>Pending</option>
                                <option value="in_transit" <?= $filterStatus === 'in_transit' ? 'selected' : '' ?>>En Route</option>
                                <option value="purchased"  <?= $filterStatus === 'purchased'  ? 'selected' : '' ?>>Done</option>
                                <option value="cancelled"  <?= $filterStatus === 'cancelled'  ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="form-group mb-0">
                            <select name="crew" class="form-control form-control-sm">
                                <option value="">All crew</option>
                                <?php foreach ($staff as $s): ?>
                                <option value="<?= (int)$s['id'] ?>" <?= $filterCrew === (int)$s['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['full_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
                        <a href="purchase-tasks.php" class="btn btn-sm btn-link text-muted">Reset</a>
                    </form>
                </div>
            </div>

            <!-- Task list -->
            <?php if (empty($tasks)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i data-feather="shopping-cart" style="width:40px;height:40px;color:#ced4da;margin-bottom:12px;"></i>
                    <h5 class="text-muted">No purchase tasks found</h5>
                    <p class="text-muted mb-3">Vendor runs and supply pickups will appear here.</p>
                    <?php if (in_array($user['role'] ?? '', ['admin', 'manager'])): ?>
                    <button class="btn btn-primary mw-btn-primary" id="btnNewTaskEmpty">
                        <i data-feather="plus" class="mr-1"></i> New Task
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="ptTable">
                        <thead class="thead-light">
                            <tr>
                                <th style="width:100px">Date</th>
                                <th style="width:110px">Task #</th>
                                <th>Title / Vendor</th>
                                <th>Assigned</th>
                                <th style="width:100px">Items</th>
                                <th style="width:95px">Status</th>
                                <th style="width:90px">Est. Total</th>
                                <th style="width:90px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tasks as $task):
                                $statusBadge = [
                                    'pending'    => ['label' => 'Pending',   'cls' => 'warning'],
                                    'in_transit' => ['label' => 'En Route',  'cls' => 'info'],
                                    'purchased'  => ['label' => 'Done',      'cls' => 'success'],
                                    'cancelled'  => ['label' => 'Cancelled', 'cls' => 'secondary'],
                                ][$task['purchase_status']] ?? ['label' => ucfirst($task['purchase_status']), 'cls' => 'secondary'];
                                $isUrgent = $task['priority'] === 'urgent';
                            ?>
                            <tr class="<?= $isUrgent ? 'table-danger' : '' ?>"
                                data-task-id="<?= (int)$task['id'] ?>">
                                <td class="text-nowrap">
                                    <?= date('M j', strtotime($task['task_date'])) ?>
                                    <small class="text-muted d-block"><?= date('D', strtotime($task['task_date'])) ?></small>
                                </td>
                                <td>
                                    <code><?= htmlspecialchars($task['task_number'] ?: '—') ?></code>
                                    <?php if ($isUrgent): ?>
                                    <span class="badge badge-danger ml-1" style="font-size:0.6rem">URGENT</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="font-weight-600"><?= htmlspecialchars($task['title']) ?></div>
                                    <?php if ($task['vendor_name']): ?>
                                    <small class="text-muted">
                                        <i data-feather="map-pin" style="width:11px;height:11px;"></i>
                                        <?= htmlspecialchars($task['vendor_name']) ?>
                                    </small>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($task['assigned_to_name'] ?? '—') ?></td>
                                <td>
                                    <?php if ($task['item_count'] > 0): ?>
                                    <div class="d-flex align-items-center" style="gap:6px">
                                        <span><?= (int)$task['items_done'] ?>/<?= (int)$task['item_count'] ?></span>
                                        <div class="progress flex-fill" style="height:5px;min-width:40px">
                                            <div class="progress-bar bg-success" style="width:<?= $task['item_count'] > 0 ? round(100 * $task['items_done'] / $task['item_count']) : 0 ?>%"></div>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $statusBadge['cls'] ?>">
                                        <?= $statusBadge['label'] ?>
                                    </span>
                                </td>
                                <td class="text-nowrap">
                                    <?= $task['estimated_total'] !== null ? '$' . number_format((float)$task['estimated_total'], 2) : '—' ?>
                                </td>
                                <td class="text-right text-nowrap">
                                    <?php if (in_array($user['role'] ?? '', ['admin', 'manager'])): ?>
                                    <button class="btn btn-sm btn-outline-secondary mw-pt-edit-btn"
                                            data-task='<?= htmlspecialchars(json_encode($task), ENT_QUOTES) ?>'>
                                        <i data-feather="edit-2" style="width:13px;height:13px;"></i>
                                    </button>
                                    <?php endif; ?>
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="schedule.php?view=day&date=<?= htmlspecialchars($task['task_date']) ?>">
                                        <i data-feather="calendar" style="width:13px;height:13px;"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>


<!-- ══════════════════════════════════════════════════════
     Create / Edit Modal
     ══════════════════════════════════════════════════════ -->
<div class="modal fade" id="ptModal" tabindex="-1" role="dialog" aria-labelledby="ptModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="ptModalLabel">New Purchase Task</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="ptForm">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="task_id" id="ptTaskId" value="">

                <div class="modal-body">
                    <div id="ptFormAlert" class="alert alert-danger d-none"></div>

                    <div class="row">
                        <!-- Left column -->
                        <div class="col-md-7">
                            <div class="form-group">
                                <label>Title <span class="text-danger">*</span></label>
                                <input type="text" name="title" id="ptTitle" class="form-control"
                                       placeholder="e.g. Pick up mulch + fertilizer" required>
                            </div>

                            <div class="row">
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label>Date <span class="text-danger">*</span></label>
                                        <input type="date" name="task_date" id="ptDate" class="form-control"
                                               value="<?= date('Y-m-d') ?>" required>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label>Assigned To</label>
                                        <select name="assigned_to_id" id="ptAssignedTo" class="form-control">
                                            <option value="">Unassigned</option>
                                            <?php foreach ($staff as $s): ?>
                                            <option value="<?= (int)$s['id'] ?>">
                                                <?= htmlspecialchars($s['full_name']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Vendor / Store Name</label>
                                <input type="text" name="vendor_name" id="ptVendor" class="form-control"
                                       placeholder="e.g. Home Depot, Otter Co-op">
                            </div>

                            <div class="form-group">
                                <label>Location</label>
                                <input type="text" name="location_label" id="ptLocationLabel" class="form-control mb-1"
                                       placeholder="Display name (e.g. Home Depot — Burnaby)">
                                <input type="text" name="location_address" id="ptLocationAddress" class="form-control"
                                       placeholder="Street address (autocomplete)">
                            </div>
                        </div>

                        <!-- Right column -->
                        <div class="col-md-5">
                            <div class="row">
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label>Mode</label>
                                        <select name="procurement_mode" id="ptMode" class="form-control">
                                            <option value="vendor_run">Vendor Run</option>
                                            <option value="supplier_pickup">Supplier Pickup</option>
                                            <option value="online_order">Online Order</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label>Priority</label>
                                        <select name="priority" id="ptPriority" class="form-control">
                                            <option value="normal">Normal</option>
                                            <option value="urgent">Urgent</option>
                                            <option value="low">Low</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Estimated Total ($)</label>
                                <input type="number" name="estimated_total" id="ptTotal" class="form-control"
                                       step="0.01" min="0" placeholder="0.00">
                            </div>

                            <div class="form-group">
                                <label>Notes</label>
                                <textarea name="notes" id="ptNotes" class="form-control" rows="3"
                                          placeholder="Special instructions, brand preferences…"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Items list -->
                    <hr>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="mb-0 font-weight-600">Supply List</label>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="ptAddItem">
                            <i data-feather="plus" style="width:13px;height:13px;"></i> Add Item
                        </button>
                    </div>
                    <div id="ptItemsContainer">
                        <!-- rows injected by JS -->
                    </div>
                    <p class="text-muted small mt-1 mb-0" id="ptItemsEmpty">No items yet — add specific supplies to track.</p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary mw-btn-primary" id="ptSaveBtn">
                        <i data-feather="save" class="mr-1" style="width:14px;height:14px;"></i> Save Task
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var CSRF = <?php echo json_encode($csrf); ?>;
    var modal = document.getElementById('ptModal');
    var form  = document.getElementById('ptForm');
    var itemsContainer = document.getElementById('ptItemsContainer');
    var itemsEmpty     = document.getElementById('ptItemsEmpty');
    var itemIdx = 0;

    // ── Address autocomplete ──────────────────────────────────────────────────
    <?php if ($apiKey): ?>
    window.addEventListener('load', function () {
        var addrInput = document.getElementById('ptLocationAddress');
        if (addrInput && window.google && window.google.maps && window.google.maps.places) {
            var ac = new google.maps.places.Autocomplete(addrInput, {
                componentRestrictions: { country: ['ca'] },
                fields: ['formatted_address', 'geometry', 'name']
            });
            ac.addListener('place_changed', function () {
                var place = ac.getPlace();
                if (place.formatted_address) addrInput.value = place.formatted_address;
            });
        }
    });
    <?php endif; ?>

    // ── Item row ─────────────────────────────────────────────────────────────
    function addItemRow(data) {
        data = data || {};
        var i = itemIdx++;
        var row = document.createElement('div');
        row.className = 'mw-pt-item-row d-flex align-items-center mb-2';
        row.dataset.idx = i;
        row.innerHTML =
            '<input type="text" class="form-control form-control-sm mr-2" ' +
                'name="items[' + i + '][description]" placeholder="Item description" ' +
                'value="' + esc(data.description || '') + '" style="flex:2" required>' +
            '<input type="number" class="form-control form-control-sm mr-2" ' +
                'name="items[' + i + '][quantity]" placeholder="Qty" min="0.01" step="any" ' +
                'value="' + esc(data.quantity || '1') + '" style="width:70px">' +
            '<input type="text" class="form-control form-control-sm mr-2" ' +
                'name="items[' + i + '][unit]" placeholder="unit" ' +
                'value="' + esc(data.unit || '') + '" style="width:70px">' +
            '<input type="number" class="form-control form-control-sm mr-2" ' +
                'name="items[' + i + '][unit_price]" placeholder="$/unit" step="0.01" min="0" ' +
                'value="' + esc(data.unit_price || '') + '" style="width:85px">' +
            '<button type="button" class="btn btn-sm btn-link text-danger p-1 mw-pt-remove-item">' +
                '<i data-feather="x" style="width:14px;height:14px;"></i>' +
            '</button>';
        row.querySelector('.mw-pt-remove-item').addEventListener('click', function () {
            row.remove();
            updateItemsEmpty();
            if (window.feather) feather.replace();
        });
        itemsContainer.appendChild(row);
        updateItemsEmpty();
        if (window.feather) feather.replace();
    }

    function updateItemsEmpty() {
        var hasRows = itemsContainer.querySelectorAll('.mw-pt-item-row').length > 0;
        itemsEmpty.style.display = hasRows ? 'none' : '';
    }

    document.getElementById('ptAddItem').addEventListener('click', function () { addItemRow(); });

    // ── Open modal (new) ──────────────────────────────────────────────────────
    function openNew(prefillDate) {
        form.reset();
        document.getElementById('ptTaskId').value = '';
        document.getElementById('ptModalLabel').textContent = 'New Purchase Task';
        document.getElementById('ptForm').action.value = 'create';
        document.getElementById('ptDate').value = prefillDate || '<?= date('Y-m-d') ?>';
        document.getElementById('ptSaveBtn').textContent = 'Create Task';
        itemsContainer.innerHTML = '';
        updateItemsEmpty();
        document.getElementById('ptFormAlert').classList.add('d-none');
        if (window.feather) feather.replace();
        $(modal).modal('show');
    }

    // ── Open modal (edit) ─────────────────────────────────────────────────────
    function openEdit(task) {
        form.reset();
        document.getElementById('ptTaskId').value = task.id;
        document.getElementById('ptModalLabel').textContent = 'Edit — ' + task.task_number;
        document.getElementById('ptForm').querySelector('[name="action"]').value = 'update';
        document.getElementById('ptTitle').value = task.title || '';
        document.getElementById('ptDate').value  = task.task_date || '';
        document.getElementById('ptVendor').value = task.vendor_name || '';
        document.getElementById('ptLocationLabel').value  = task.location_label || '';
        document.getElementById('ptLocationAddress').value = task.location_address || '';
        document.getElementById('ptMode').value     = task.procurement_mode || 'vendor_run';
        document.getElementById('ptPriority').value = task.priority || 'normal';
        document.getElementById('ptTotal').value    = task.estimated_total || '';
        document.getElementById('ptNotes').value    = task.notes || '';
        document.getElementById('ptAssignedTo').value = task.assigned_to_id || '';
        document.getElementById('ptSaveBtn').textContent = 'Save Changes';
        itemsContainer.innerHTML = '';
        // Load items via API
        fetch('/crm/api/purchase-tasks.php?task_id=' + task.id)
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.success && d.task && d.task.items) {
                    d.task.items.forEach(function(it) { addItemRow(it); });
                }
            });
        document.getElementById('ptFormAlert').classList.add('d-none');
        if (window.feather) feather.replace();
        $(modal).modal('show');
    }

    // ── Wire up open buttons ──────────────────────────────────────────────────
    var btnNew = document.getElementById('btnNewTask');
    if (btnNew) btnNew.addEventListener('click', function () { openNew(); });
    var btnNewEmpty = document.getElementById('btnNewTaskEmpty');
    if (btnNewEmpty) btnNewEmpty.addEventListener('click', function () { openNew(); });

    document.querySelectorAll('.mw-pt-edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openEdit(JSON.parse(btn.dataset.task));
        });
    });

    // ── Form submit ───────────────────────────────────────────────────────────
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var saveBtn = document.getElementById('ptSaveBtn');
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving…';

        var fd = new FormData(form);
        var data = { csrf_token: CSRF };
        for (var pair of fd.entries()) {
            var key = pair[0], val = pair[1];
            if (key.startsWith('items[')) {
                var m = key.match(/^items\[(\d+)\]\[(\w+)\]$/);
                if (m) {
                    var idx = parseInt(m[1], 10), field = m[2];
                    if (!data.items) data.items = {};
                    if (!data.items[idx]) data.items[idx] = {};
                    data.items[idx][field] = val;
                }
            } else {
                data[key] = val;
            }
        }
        // Convert items object to array
        if (data.items) {
            data.items = Object.values(data.items).filter(function(i) { return i.description && i.description.trim(); });
        }

        fetch('/crm/api/purchase-tasks.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) {
                $(modal).modal('hide');
                window.location.reload();
            } else {
                var alert = document.getElementById('ptFormAlert');
                alert.textContent = d.error || 'Save failed.';
                alert.classList.remove('d-none');
                saveBtn.disabled = false;
                saveBtn.textContent = data.action === 'create' ? 'Create Task' : 'Save Changes';
            }
        })
        .catch(function(err) {
            document.getElementById('ptFormAlert').textContent = 'Network error.';
            document.getElementById('ptFormAlert').classList.remove('d-none');
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save Task';
        });
    });

    // ── Inline status change from table row ───────────────────────────────────
    document.querySelectorAll('.badge[data-task-id]').forEach(function(badge) {
        badge.style.cursor = 'pointer';
    });

    // ── Escape helper ─────────────────────────────────────────────────────────
    function esc(str) {
        return String(str || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    // ── URL param: open modal for a specific date ─────────────────────────────
    var params = new URLSearchParams(window.location.search);
    if (params.get('new') === '1') {
        openNew(params.get('date') || '');
    }
})();
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
