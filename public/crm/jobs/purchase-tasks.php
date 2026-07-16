<?php
/**
 * Purchase Tasks — Procurement Management
 * /crm/jobs/purchase-tasks.php
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
           u.full_name  AS assigned_to_name,
           cb.full_name AS created_by_name,
           TRIM(CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,''))) AS contact_name,
           p.address    AS property_address,
           p.city       AS property_city,
           (SELECT COUNT(*) FROM purchase_task_items pti WHERE pti.task_id = pt.id) AS item_count,
           (SELECT COUNT(*) FROM purchase_task_items pti WHERE pti.task_id = pt.id AND pti.is_purchased = 1) AS items_done
    FROM purchase_tasks pt
    LEFT JOIN users      u  ON pt.assigned_to_id = u.id
    LEFT JOIN users      cb ON pt.created_by_id  = cb.id
    LEFT JOIN contacts   c  ON pt.contact_id     = c.id
    LEFT JOIN properties p  ON pt.property_id    = p.id
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
        . '&libraries=places&loading=async" defer></script>';
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
                                <th>Client</th>
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
                                <td>
                                    <?php if ($task['contact_name']): ?>
                                    <div class="font-weight-500" style="font-size:.85rem"><?= htmlspecialchars(trim($task['contact_name'])) ?></div>
                                    <?php if ($task['property_address']): ?>
                                    <small class="text-muted"><?= htmlspecialchars($task['property_address'] . ', ' . $task['property_city']) ?></small>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <span class="text-muted">—</span>
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
                <input type="hidden" name="assigned_to_id"    id="ptAssignedToId"    value="">
                <input type="hidden" name="contact_id"        id="ptContactId"       value="">
                <input type="hidden" name="property_id"       id="ptPropertyId"      value="">
                <input type="hidden" name="vendor_id"         id="ptVendorId"        value="">
                <input type="hidden" name="vendor_location_id" id="ptVendorLocationId" value="">

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
                                        <div class="mw-crew-wrapper" id="ptCrewWrapper">
                                            <div class="mw-crew-chips" id="ptCrewChips">
                                                <button type="button" class="mw-crew-add-btn" id="ptCrewAddBtn">
                                                    + Assign
                                                </button>
                                            </div>
                                            <div class="mw-crew-dropdown" id="ptCrewDropdown">
                                                <div class="mw-crew-dropdown-item" data-id="" data-name="Unassigned">
                                                    <span>Unassigned</span>
                                                    <small class="text-muted">clear</small>
                                                </div>
                                                <?php foreach ($staff as $s):
                                                    $initials = strtoupper(substr($s['first_name'] ?? $s['full_name'], 0, 1) . substr($s['last_name'] ?? '', 0, 1));
                                                ?>
                                                <div class="mw-crew-dropdown-item"
                                                     data-id="<?= (int)$s['id'] ?>"
                                                     data-name="<?= htmlspecialchars($s['full_name'], ENT_QUOTES) ?>"
                                                     data-initials="<?= htmlspecialchars($initials, ENT_QUOTES) ?>">
                                                    <span><?= htmlspecialchars($s['full_name']) ?></span>
                                                    <small class="text-muted" style="text-transform:capitalize"><?= htmlspecialchars($s['role'] ?? '') ?></small>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Vendor dropdown + add-new -->
                            <div class="form-group">
                                <label>Vendor / Store Name</label>
                                <div class="d-flex" style="gap:6px">
                                    <select id="ptVendorSelect" class="form-control" style="flex:1">
                                        <option value="">— select vendor —</option>
                                    </select>
                                    <?php if (in_array($user['role'] ?? '', ['admin', 'manager'])): ?>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="ptVendorNewBtn"
                                            title="Add new vendor" style="white-space:nowrap">
                                        + New
                                    </button>
                                    <?php endif; ?>
                                </div>
                                <!-- Inline add-new-vendor panel -->
                                <div id="ptVendorNewPanel" class="mw-pt-new-vendor-panel" style="display:none">
                                    <div class="mw-pt-new-vendor-inner">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <strong style="font-size:.82rem">New Vendor</strong>
                                            <button type="button" id="ptVendorNewCancel"
                                                    class="btn btn-link btn-sm text-muted p-0">&times; cancel</button>
                                        </div>
                                        <input type="text" id="ptVendorNewName" class="form-control form-control-sm mb-2"
                                               placeholder="Vendor name (e.g. Home Depot)">
                                        <input type="text" id="ptVendorNewLocLabel" class="form-control form-control-sm mb-1"
                                               placeholder="Location label (e.g. Home Depot — Burnaby)">
                                        <input type="text" id="ptVendorNewLocAddress" class="form-control form-control-sm mb-2"
                                               placeholder="Street address (optional)">
                                        <button type="button" id="ptVendorNewSave"
                                                class="btn btn-sm btn-success">Save Vendor</button>
                                        <div id="ptVendorNewError" class="text-danger small mt-1" style="display:none"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Location dropdown — populated when a vendor is selected -->
                            <div class="form-group" id="ptLocationWrap" style="display:none">
                                <label>Location</label>
                                <div class="d-flex" style="gap:6px;align-items:center">
                                    <select id="ptLocationSelect" class="form-control" style="flex:1">
                                        <option value="">— select location —</option>
                                    </select>
                                </div>
                                <div id="ptLocationNone" class="text-muted small mt-1" style="display:none">
                                    No locations on file — address will be blank.
                                </div>
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

                    <!-- Client / Property link -->
                    <hr class="mt-1 mb-3">
                    <div class="mw-pt-section-label mb-2">
                        <i data-feather="user" style="width:13px;height:13px;vertical-align:-1px" class="mr-1"></i>
                        Client &amp; Property Link
                        <small class="text-muted ml-1">— who is this for?</small>
                    </div>
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="form-group" style="position:relative">
                                <label class="small mb-1">Client</label>
                                <input type="text" id="ptClientSearch" class="form-control"
                                       placeholder="Search by name…" autocomplete="off">
                                <div id="ptClientDropdown" class="mw-pt-vendor-suggestions" style="display:none"></div>
                                <div id="ptClientChip" class="mt-1" style="display:none">
                                    <span class="mw-crew-chip" id="ptClientChipLabel"></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="form-group" id="ptPropertyWrap" style="display:none">
                                <label class="small mb-1">Property</label>
                                <select id="ptPropertySelect" class="form-control form-control-sm">
                                    <option value="">— select property —</option>
                                </select>
                                <small class="text-muted" id="ptPropertyHint"></small>
                            </div>
                            <div id="ptPropertyEmpty" class="form-group" style="display:none">
                                <label class="small mb-1">Property</label>
                                <p class="small text-muted mb-0">No properties on file for this client.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Create Plan CTA (shown after client is linked, edit mode) -->
                    <div id="ptCreatePlanCta" class="alert alert-light border py-2 px-3 mt-0 mb-0" style="display:none;font-size:.85rem">
                        <i data-feather="calendar" style="width:13px;height:13px;vertical-align:-1px" class="mr-1 text-success"></i>
                        No plan linked yet.
                        <a href="#" id="ptCreatePlanLink" class="ml-2 font-weight-600 text-success">
                            Create a plan for this client →
                        </a>
                    </div>

                    <!-- Items list -->
                    <hr class="mt-3">
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
                        <i data-feather="save" class="mr-1" style="width:14px;height:14px;"></i>
                        <span id="ptSaveBtnLabel">Create Task</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
/* Purchase task modal extras */
.mw-pt-vendor-suggestions {
    position:absolute;
    left:0; right:0;
    background:#fff;
    border:1px solid var(--mw-ink-300);
    border-radius:6px;
    box-shadow:0 4px 12px rgb(0 0 0/.1);
    z-index:200;
    max-height:220px;
    overflow-y:auto;
    margin-top:2px;
}
.mw-pt-suggestion-item {
    padding:8px 12px;
    cursor:pointer;
    font-size:.85rem;
}
.mw-pt-suggestion-item:hover {
    background:var(--mw-light);
}
.mw-pt-suggestion-item .mw-pt-suggestion-sub {
    font-size:.75rem;
    color:var(--mw-ink-500);
}
.mw-pt-section-label {
    font-size:.8rem;
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:.04em;
    color:var(--mw-ink-500);
}
/* Make chip removable in single-assignee context */
#ptCrewChips .mw-crew-chip {
    cursor:default;
}
/* Inline new-vendor panel */
.mw-pt-new-vendor-panel {
    margin-top:6px;
    border:1px solid var(--mw-light);
    border-radius:6px;
    background:#f9fbfa;
}
.mw-pt-new-vendor-inner {
    padding:10px 12px;
}
</style>

<script>
(function () {
    'use strict';

    var CSRF   = <?php echo json_encode($csrf); ?>;
    var STAFF  = <?php echo json_encode(array_values($staff)); ?>;
    var modal  = document.getElementById('ptModal');
    var form   = document.getElementById('ptForm');
    var itemsContainer = document.getElementById('ptItemsContainer');
    var itemsEmpty     = document.getElementById('ptItemsEmpty');
    var itemIdx = 0;

    // ── Address autocomplete (new-vendor panel) ───────────────────────────────
    <?php if ($apiKey): ?>
    window.addEventListener('load', function () {
        var addrInput = document.getElementById('ptVendorNewLocAddress');
        if (addrInput && window.google && window.google.maps && window.google.maps.places) {
            var ac = new google.maps.places.Autocomplete(addrInput, {
                componentRestrictions: { country: ['ca'] },
                fields: ['formatted_address']
            });
            ac.addListener('place_changed', function () {
                var place = ac.getPlace();
                if (place.formatted_address) addrInput.value = place.formatted_address;
            });
        }
    });
    <?php endif; ?>

    // ── Crew chip picker (single-assignee) ────────────────────────────────────
    var crewChips    = document.getElementById('ptCrewChips');
    var crewDropdown = document.getElementById('ptCrewDropdown');
    var crewAddBtn   = document.getElementById('ptCrewAddBtn');
    var assignedId   = document.getElementById('ptAssignedToId');

    function toggleCrewDropdown() {
        crewDropdown.classList.toggle('show');
    }

    function selectCrew(id, name) {
        assignedId.value = id || '';
        crewDropdown.classList.remove('show');

        // Rebuild chips area
        crewChips.innerHTML = '';
        if (id) {
            var chip = document.createElement('span');
            chip.className = 'mw-crew-chip';
            chip.innerHTML = esc(name) +
                '<button type="button" class="mw-crew-chip-remove" title="Remove">&times;</button>';
            chip.querySelector('.mw-crew-chip-remove').addEventListener('click', function () {
                selectCrew('', '');
            });
            crewChips.appendChild(chip);
        }
        var addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'mw-crew-add-btn';
        addBtn.textContent = id ? '↻ Change' : '+ Assign';
        addBtn.addEventListener('click', toggleCrewDropdown);
        crewChips.appendChild(addBtn);

        // Mark selected in dropdown
        crewDropdown.querySelectorAll('.mw-crew-dropdown-item').forEach(function (el) {
            el.style.fontWeight = el.dataset.id == String(id) ? '600' : '';
        });

        if (window.feather) feather.replace();
    }

    crewAddBtn.addEventListener('click', toggleCrewDropdown);
    crewDropdown.querySelectorAll('.mw-crew-dropdown-item').forEach(function (el) {
        el.addEventListener('click', function () {
            selectCrew(el.dataset.id, el.dataset.name);
        });
    });

    // Close crew dropdown on outside click
    document.addEventListener('click', function (e) {
        if (!e.target.closest('#ptCrewWrapper')) {
            crewDropdown.classList.remove('show');
        }
    });

    // ── Vendor dropdown ───────────────────────────────────────────────────────
    var vendorSelect      = document.getElementById('ptVendorSelect');
    var vendorIdInput     = document.getElementById('ptVendorId');
    var locationWrap      = document.getElementById('ptLocationWrap');
    var locationSelect    = document.getElementById('ptLocationSelect');
    var locationIdInput   = document.getElementById('ptVendorLocationId');
    var locationNoneHint  = document.getElementById('ptLocationNone');
    var vendorNewBtn      = document.getElementById('ptVendorNewBtn');
    var vendorNewPanel    = document.getElementById('ptVendorNewPanel');
    var vendorNewCancel   = document.getElementById('ptVendorNewCancel');
    var vendorNewSave     = document.getElementById('ptVendorNewSave');
    var vendorNewName     = document.getElementById('ptVendorNewName');
    var vendorNewLocLabel = document.getElementById('ptVendorNewLocLabel');
    var vendorNewLocAddr  = document.getElementById('ptVendorNewLocAddress');
    var vendorNewError    = document.getElementById('ptVendorNewError');

    // Populate vendor dropdown from API on first open
    var vendorsLoaded = false;
    function loadVendors(cb) {
        if (vendorsLoaded) { if (cb) cb(); return; }
        fetch('/crm/api/purchase-tasks.php?action=vendor_list')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) return;
                vendorsLoaded = true;
                d.vendors.forEach(function (v) {
                    var opt = document.createElement('option');
                    opt.value = v.id;
                    opt.textContent = v.name;
                    opt.dataset.locationCount = v.location_count;
                    vendorSelect.appendChild(opt);
                });
                if (cb) cb();
            })
            .catch(function () {});
    }

    function loadVendorLocations(vendorId, selectLocationId) {
        locationSelect.innerHTML = '<option value="">— select location —</option>';
        locationIdInput.value = '';
        locationNoneHint.style.display = 'none';

        if (!vendorId) { locationWrap.style.display = 'none'; return; }

        fetch('/crm/api/purchase-tasks.php?action=vendor_locations&vendor_id=' + encodeURIComponent(vendorId))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) return;
                locationWrap.style.display = 'block';
                if (!d.locations.length) {
                    locationNoneHint.style.display = 'block';
                    return;
                }
                d.locations.forEach(function (loc) {
                    var opt = document.createElement('option');
                    opt.value = loc.id;
                    var label = loc.label || loc.address || 'Location #' + loc.id;
                    if (loc.address && loc.label && loc.label !== loc.address) {
                        label += ' — ' + loc.address;
                    }
                    opt.textContent = label;
                    if (String(loc.id) === String(selectLocationId)) opt.selected = true;
                    locationSelect.appendChild(opt);
                });
                // Auto-select if only one location
                if (d.locations.length === 1 && !selectLocationId) {
                    locationSelect.value = d.locations[0].id;
                    locationIdInput.value = d.locations[0].id;
                } else if (selectLocationId) {
                    locationIdInput.value = selectLocationId;
                }
            })
            .catch(function () { locationWrap.style.display = 'none'; });
    }

    vendorSelect.addEventListener('change', function () {
        var vid = vendorSelect.value;
        vendorIdInput.value = vid;
        loadVendorLocations(vid, '');
    });

    locationSelect.addEventListener('change', function () {
        locationIdInput.value = locationSelect.value;
    });

    // ── Add new vendor panel ──────────────────────────────────────────────────
    if (vendorNewBtn) {
        vendorNewBtn.addEventListener('click', function () {
            vendorNewPanel.style.display = 'block';
            vendorNewName.focus();
            vendorNewError.style.display = 'none';
        });
    }
    if (vendorNewCancel) {
        vendorNewCancel.addEventListener('click', function () {
            vendorNewPanel.style.display = 'none';
        });
    }
    if (vendorNewSave) {
        vendorNewSave.addEventListener('click', function () {
            var name = vendorNewName.value.trim();
            if (!name) { vendorNewError.textContent = 'Vendor name is required.'; vendorNewError.style.display = 'block'; return; }
            vendorNewSave.disabled = true;
            vendorNewSave.textContent = 'Saving…';

            fetch('/crm/api/purchase-tasks.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token:       CSRF,
                    action:           'create_vendor_quick',
                    name:             name,
                    location_label:   vendorNewLocLabel.value.trim(),
                    location_address: vendorNewLocAddr.value.trim(),
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) throw new Error(d.error || 'Save failed');

                // Add to dropdown (or select existing if duplicate)
                var exists = vendorSelect.querySelector('option[value="' + d.vendor_id + '"]');
                if (!exists) {
                    var opt = document.createElement('option');
                    opt.value = d.vendor_id;
                    opt.textContent = d.name;
                    vendorSelect.appendChild(opt);
                }
                vendorSelect.value = d.vendor_id;
                vendorIdInput.value = d.vendor_id;

                vendorNewPanel.style.display = 'none';
                vendorNewName.value = '';
                vendorNewLocLabel.value = '';
                vendorNewLocAddr.value = '';

                // Re-populate locations
                vendorsLoaded = false; // force reload so new vendor appears fresh
                loadVendorLocations(d.vendor_id, d.location_id || '');
            })
            .catch(function (err) {
                vendorNewError.textContent = err.message || 'Error saving vendor.';
                vendorNewError.style.display = 'block';
            })
            .finally(function () {
                vendorNewSave.disabled = false;
                vendorNewSave.textContent = 'Save Vendor';
            });
        });
    }

    // ── Client search typeahead ───────────────────────────────────────────────
    var clientSearch   = document.getElementById('ptClientSearch');
    var clientDropdown = document.getElementById('ptClientDropdown');
    var clientChipDiv  = document.getElementById('ptClientChip');
    var clientChipLbl  = document.getElementById('ptClientChipLabel');
    var clientDebounce = null;

    function selectClient(id, name) {
        document.getElementById('ptContactId').value = id || '';
        clientChipLbl.textContent = name;
        clientChipDiv.style.display = id ? 'block' : 'none';
        clientSearch.style.display  = id ? 'none'  : '';
        clientDropdown.style.display = 'none';

        // Add remove button to chip
        clientChipLbl.innerHTML = esc(name) +
            ' <button type="button" class="mw-crew-chip-remove" style="margin-left:4px">&times;</button>';
        clientChipLbl.querySelector('.mw-crew-chip-remove').addEventListener('click', function () {
            clearClient();
        });

        if (id) loadProperties(id);
        else     clearProperties();

        updateCreatePlanCta(id);
    }

    function clearClient() {
        document.getElementById('ptContactId').value = '';
        clientSearch.value = '';
        clientSearch.style.display = '';
        clientChipDiv.style.display = 'none';
        clearProperties();
        updateCreatePlanCta('');
    }

    function loadProperties(contactId) {
        fetch('/crm/api/client-search.php?action=properties&contact_id=' + encodeURIComponent(contactId))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var wrap  = document.getElementById('ptPropertyWrap');
                var empty = document.getElementById('ptPropertyEmpty');
                var sel   = document.getElementById('ptPropertySelect');
                var hint  = document.getElementById('ptPropertyHint');
                var currentVal = document.getElementById('ptPropertyId').value;

                if (d.success && d.properties && d.properties.length) {
                    sel.innerHTML = '<option value="">— select property —</option>';
                    d.properties.forEach(function (p) {
                        var opt = document.createElement('option');
                        opt.value = p.id;
                        opt.textContent = p.address + (p.city ? ', ' + p.city : '');
                        if (String(p.id) === String(currentVal)) opt.selected = true;
                        sel.appendChild(opt);
                    });
                    hint.textContent = d.properties.length + ' propert' + (d.properties.length === 1 ? 'y' : 'ies');
                    wrap.style.display  = 'block';
                    empty.style.display = 'none';
                    if (d.properties.length === 1 && !currentVal) {
                        sel.value = d.properties[0].id;
                        document.getElementById('ptPropertyId').value = d.properties[0].id;
                    }
                } else {
                    wrap.style.display  = 'none';
                    empty.style.display = 'block';
                }
            })
            .catch(function () { clearProperties(); });
    }

    function clearProperties() {
        document.getElementById('ptPropertyId').value = '';
        document.getElementById('ptPropertyWrap').style.display  = 'none';
        document.getElementById('ptPropertyEmpty').style.display = 'none';
        var sel = document.getElementById('ptPropertySelect');
        sel.innerHTML = '<option value="">— select property —</option>';
    }

    document.getElementById('ptPropertySelect').addEventListener('change', function () {
        document.getElementById('ptPropertyId').value = this.value;
    });

    clientSearch.addEventListener('input', function () {
        clearTimeout(clientDebounce);
        var q = clientSearch.value.trim();
        if (q.length < 1) { clientDropdown.style.display = 'none'; return; }
        clientDebounce = setTimeout(function () {
            fetch('/crm/api/client-search.php?action=search&q=' + encodeURIComponent(q) + '&type=contact')
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.success || !d.results.length) { clientDropdown.style.display = 'none'; return; }
                    clientDropdown.innerHTML = '';
                    d.results.forEach(function (r) {
                        var item = document.createElement('div');
                        item.className = 'mw-pt-suggestion-item';
                        item.innerHTML = '<strong>' + esc(r.label) + '</strong>' +
                            (r.sublabel ? '<div class="mw-pt-suggestion-sub">' + esc(r.sublabel) + '</div>' : '');
                        item.addEventListener('mousedown', function (e) {
                            e.preventDefault();
                            selectClient(r.id, r.label);
                        });
                        clientDropdown.appendChild(item);
                    });
                    clientDropdown.style.display = 'block';
                })
                .catch(function () { clientDropdown.style.display = 'none'; });
        }, 200);
    });

    clientSearch.addEventListener('blur', function () {
        setTimeout(function () { clientDropdown.style.display = 'none'; }, 150);
    });

    // ── Create Plan CTA ───────────────────────────────────────────────────────
    function updateCreatePlanCta(contactId) {
        var cta     = document.getElementById('ptCreatePlanCta');
        var ctaLink = document.getElementById('ptCreatePlanLink');
        var taskId  = document.getElementById('ptTaskId').value;
        // Only show in edit mode with a linked contact
        if (contactId && taskId) {
            ctaLink.href = 'create-from-quote.php?contact_id=' + encodeURIComponent(contactId);
            cta.style.display = 'block';
            if (window.feather) feather.replace();
        } else {
            cta.style.display = 'none';
        }
    }

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

    // ── Reset form state ──────────────────────────────────────────────────────
    function resetModal() {
        form.reset();
        document.getElementById('ptTaskId').value = '';
        document.getElementById('ptFormAlert').classList.add('d-none');
        itemsContainer.innerHTML = '';
        updateItemsEmpty();
        selectCrew('', '');
        clearClient();
        updateCreatePlanCta('');
        // Reset vendor
        vendorIdInput.value = '';
        locationIdInput.value = '';
        locationWrap.style.display = 'none';
        locationNoneHint.style.display = 'none';
        if (vendorNewPanel) vendorNewPanel.style.display = 'none';
        // Ensure vendor dropdown is populated
        loadVendors(function () {
            vendorSelect.value = '';
        });
        if (window.feather) feather.replace();
    }

    // ── Open modal (new) ──────────────────────────────────────────────────────
    function openNew(prefillDate) {
        resetModal();
        document.getElementById('ptModalLabel').textContent = 'New Purchase Task';
        document.querySelector('[name="action"]').value = 'create';
        document.getElementById('ptDate').value = prefillDate || '<?= date('Y-m-d') ?>';
        document.getElementById('ptSaveBtnLabel').textContent = 'Create Task';
        $(modal).modal('show');
    }

    // ── Open modal (edit) ─────────────────────────────────────────────────────
    function openEdit(task) {
        resetModal();
        document.getElementById('ptTaskId').value = task.id;
        document.getElementById('ptModalLabel').textContent = 'Edit — ' + task.task_number;
        document.querySelector('[name="action"]').value = 'update';
        document.getElementById('ptTitle').value  = task.title || '';
        document.getElementById('ptDate').value   = task.task_date || '';
        // Vendor — restore FK selection; fall back to vendor_name match if no vendor_id
        if (task.vendor_id) {
            loadVendors(function () {
                vendorSelect.value = task.vendor_id;
                vendorIdInput.value = task.vendor_id;
                loadVendorLocations(task.vendor_id, task.vendor_location_id || '');
            });
        } else if (task.vendor_name) {
            // Legacy free-text: try to match in dropdown by name
            loadVendors(function () {
                var matched = false;
                for (var i = 0; i < vendorSelect.options.length; i++) {
                    if (vendorSelect.options[i].text === task.vendor_name) {
                        vendorSelect.selectedIndex = i;
                        vendorIdInput.value = vendorSelect.options[i].value;
                        loadVendorLocations(vendorSelect.options[i].value, '');
                        matched = true;
                        break;
                    }
                }
                // No match: leave blank — legacy data shows in read-only table but isn't selectable
            });
        }
        document.getElementById('ptMode').value     = task.procurement_mode || 'vendor_run';
        document.getElementById('ptPriority').value = task.priority || 'normal';
        document.getElementById('ptTotal').value    = task.estimated_total || '';
        document.getElementById('ptNotes').value    = task.notes || '';
        document.getElementById('ptSaveBtnLabel').textContent = 'Save Changes';

        // Crew
        if (task.assigned_to_id) {
            selectCrew(String(task.assigned_to_id), task.assigned_to_name || '');
        }

        // Client + property
        if (task.contact_id) {
            document.getElementById('ptContactId').value = task.contact_id;
            var clientName = (task.contact_name || '').trim() || 'Client #' + task.contact_id;
            clientSearch.style.display = 'none';
            clientChipLbl.innerHTML = esc(clientName) +
                ' <button type="button" class="mw-crew-chip-remove" style="margin-left:4px">&times;</button>';
            clientChipLbl.querySelector('.mw-crew-chip-remove').addEventListener('click', clearClient);
            clientChipDiv.style.display = 'block';
            document.getElementById('ptPropertyId').value = task.property_id || '';
            loadProperties(task.contact_id);
            updateCreatePlanCta(task.contact_id);
        }

        // Load items via API
        fetch('/crm/api/purchase-tasks.php?task_id=' + task.id)
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.success && d.task && d.task.items) {
                    d.task.items.forEach(function (it) { addItemRow(it); });
                }
            });

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
        document.getElementById('ptSaveBtnLabel').textContent = 'Saving…';

        var fd   = new FormData(form);
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
        if (data.items) {
            data.items = Object.values(data.items).filter(function (i) {
                return i.description && i.description.trim();
            });
        }

        fetch('/crm/api/purchase-tasks.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.success) {
                $(modal).modal('hide');
                // If a client was linked and this was a new task, offer plan creation
                var wasCreate = data.action === 'create';
                if (wasCreate && d.contact_id) {
                    sessionStorage.setItem('pt_new_task_contact', d.contact_id);
                    sessionStorage.setItem('pt_new_task_id', d.task_id);
                }
                window.location.reload();
            } else {
                var alertEl = document.getElementById('ptFormAlert');
                alertEl.textContent = d.error || 'Save failed.';
                alertEl.classList.remove('d-none');
                saveBtn.disabled = false;
                document.getElementById('ptSaveBtnLabel').textContent =
                    data.action === 'create' ? 'Create Task' : 'Save Changes';
            }
        })
        .catch(function () {
            document.getElementById('ptFormAlert').textContent = 'Network error.';
            document.getElementById('ptFormAlert').classList.remove('d-none');
            saveBtn.disabled = false;
            document.getElementById('ptSaveBtnLabel').textContent = 'Save Task';
        });
    });

    // ── Post-create toast: offer plan creation ────────────────────────────────
    (function checkPostCreate() {
        var contactId = sessionStorage.getItem('pt_new_task_contact');
        var taskId    = sessionStorage.getItem('pt_new_task_id');
        if (!contactId) return;
        sessionStorage.removeItem('pt_new_task_contact');
        sessionStorage.removeItem('pt_new_task_id');

        var toast = document.createElement('div');
        toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#1A5F4A;color:#fff;' +
            'border-radius:10px;padding:14px 18px;z-index:9999;max-width:340px;box-shadow:0 4px 16px rgb(0 0 0/.2)';
        toast.innerHTML =
            '<div style="font-weight:600;margin-bottom:4px">Task created</div>' +
            '<div style="font-size:.85rem;opacity:.85">Ready to schedule the installation?</div>' +
            '<div style="margin-top:10px;display:flex;gap:8px">' +
            '  <a href="create-from-quote.php?contact_id=' + encodeURIComponent(contactId) + '" ' +
            '     style="background:#7FD858;color:#0D3B2E;padding:5px 12px;border-radius:6px;font-size:.82rem;font-weight:600;text-decoration:none">' +
            '    Create Plan →' +
            '  </a>' +
            '  <button onclick="this.closest(\'div[style]\').remove()" ' +
            '     style="background:rgba(255,255,255,.15);border:none;color:#fff;padding:5px 10px;border-radius:6px;font-size:.82rem;cursor:pointer">' +
            '    Dismiss' +
            '  </button>' +
            '</div>';
        document.body.appendChild(toast);
        setTimeout(function () { if (toast.parentNode) toast.remove(); }, 12000);
    })();

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
