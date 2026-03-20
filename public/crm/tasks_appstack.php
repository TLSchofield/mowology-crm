<?php
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

$pageTitle  = 'Tasks';
$activePage = 'tasks';

$db = getDB();

// ── Filters ─────────────────────────────────────────────────────────────────
$filterTab      = $_GET['tab'] ?? 'my';
$filterPriority = $_GET['priority'] ?? '';
$filterAssigned = $_GET['assigned_to'] ?? '';
$search         = trim($_GET['q'] ?? '');

$where  = [];
$params = [];

// Tab filters
switch ($filterTab) {
    case 'my':
        $where[]  = 't.assigned_to = ?';
        $params[] = $user['id'];
        $where[]  = "t.status != 'completed'";
        $where[]  = "(t.task_type = 'general' OR t.task_type IS NULL)";
        break;
    case 'all':
        $where[] = "t.status != 'completed'";
        $where[] = "(t.task_type = 'general' OR t.task_type IS NULL)";
        break;
    case 'overdue':
        $where[] = "t.status != 'completed' AND t.due_date IS NOT NULL AND t.due_date < CURDATE()";
        break;
    case 'completed':
        $where[] = "t.status = 'completed'";
        break;
    case 'purchases':
        $where[] = "t.task_type = 'purchase'";
        $where[] = "(t.purchase_status IS NULL OR t.purchase_status NOT IN ('verified'))";
        break;
    case 'verified':
        $where[] = "t.task_type = 'purchase' AND t.purchase_status = 'verified'";
        break;
}

if ($filterPriority && in_array($filterPriority, ['high', 'normal', 'low'])) {
    $where[]  = 't.priority = ?';
    $params[] = $filterPriority;
}
if ($filterAssigned) {
    $where[]  = 't.assigned_to = ?';
    $params[] = (int)$filterAssigned;
}
if ($search) {
    $where[]  = '(t.title LIKE ? OR t.description LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Counts for tabs
$cStmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status != 'completed' AND (task_type = 'general' OR task_type IS NULL)");
$cStmt->execute([$user['id']]);
$tabCounts['my'] = (int)$cStmt->fetchColumn();

$cStmt = $db->query("SELECT COUNT(*) FROM tasks WHERE status != 'completed' AND (task_type = 'general' OR task_type IS NULL)");
$tabCounts['all'] = (int)$cStmt->fetchColumn();

$cStmt = $db->query("SELECT COUNT(*) FROM tasks WHERE status != 'completed' AND due_date IS NOT NULL AND due_date < CURDATE()");
$tabCounts['overdue'] = (int)$cStmt->fetchColumn();

$cStmt = $db->query("SELECT COUNT(*) FROM tasks WHERE status = 'completed'");
$tabCounts['completed'] = (int)$cStmt->fetchColumn();

$cStmt = $db->query("SELECT COUNT(*) FROM tasks WHERE task_type = 'purchase' AND (purchase_status IS NULL OR purchase_status NOT IN ('verified'))");
$tabCounts['purchases'] = (int)$cStmt->fetchColumn();

$cStmt = $db->query("SELECT COUNT(*) FROM tasks WHERE task_type = 'purchase' AND purchase_status = 'verified'");
$tabCounts['verified'] = (int)$cStmt->fetchColumn();

// Fetch tasks
$stmt = $db->prepare("
    SELECT t.*,
           u_assigned.full_name AS assigned_to_name,
           u_created.full_name AS created_by_name,
           c.first_name AS contact_first, c.last_name AS contact_last,
           q.quote_number,
           jp.plan_number,
           inv.invoice_number,
           v.name AS vendor_name,
           vl.label AS vendor_location_label,
           vl.address AS vendor_location_address,
           (SELECT COUNT(*) FROM task_items ti WHERE ti.task_id = t.id) AS items_count
    FROM tasks t
    LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
    LEFT JOIN users u_created ON t.created_by = u_created.id
    LEFT JOIN contacts c ON t.contact_id = c.id
    LEFT JOIN quotes q ON t.quote_id = q.id
    LEFT JOIN job_plans jp ON t.plan_id = jp.id
    LEFT JOIN invoices inv ON t.invoice_id = inv.id
    LEFT JOIN vendors v ON t.vendor_id = v.id
    LEFT JOIN vendor_locations vl ON t.vendor_location_id = vl.id
    {$whereClause}
    ORDER BY
        CASE t.status WHEN 'in_progress' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END,
        CASE t.priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END,
        COALESCE(t.due_date, '2099-12-31') ASC,
        t.created_at DESC
    LIMIT 200
");
$stmt->execute($params);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Users for assignment dropdown
$usersStmt = $db->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name");
$allUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = generateCSRFToken();

// Purchase status labels & colors
$purchaseStatusMap = [
    'requested'  => ['label' => 'Requested',  'class' => 'badge-secondary'],
    'assigned'   => ['label' => 'Assigned',   'class' => 'badge-info'],
    'en_route'   => ['label' => 'En Route',   'class' => 'mw-badge-orange'],
    'at_vendor'  => ['label' => 'At Vendor',  'class' => 'badge-warning'],
    'purchased'  => ['label' => 'Purchased',  'class' => 'badge-success'],
    'delivered'  => ['label' => 'Delivered',  'class' => 'mw-badge-teal'],
    'verified'   => ['label' => 'Verified',   'class' => 'mw-badge-dark-green'],
];

$modeLabels = [
    'on_route'  => 'On Route',
    'asap'      => 'ASAP',
    'truck_kit' => 'Truck Kit',
];
?>
<?php include 'includes/appstack_head.php'; ?>

          <div class="d-flex justify-content-between align-items-center mb-3">
              <h1 class="h3 mb-0">Tasks</h1>
              <div>
                  <button class="btn btn-outline-primary mr-1" onclick="mwPurchaseModal()">
                      <i data-feather="shopping-bag" style="width:16px;height:16px;"></i> New Purchase
                  </button>
                  <button class="btn btn-primary" onclick="mwTaskModal()">
                      <i data-feather="plus" style="width:16px;height:16px;"></i> New Task
                  </button>
              </div>
          </div>

          <!-- Filter Tabs -->
          <div class="mw-filter-tabs mb-3">
              <?php
              $tabs = [
                  'my'        => 'My Tasks',
                  'all'       => 'All Tasks',
                  'overdue'   => 'Overdue',
                  'completed' => 'Completed',
                  'purchases' => 'Purchase Queue',
                  'verified'  => 'Verified',
              ];
              foreach ($tabs as $key => $label): ?>
              <a href="?tab=<?= $key ?>" class="mw-filter-tab <?= $filterTab === $key ? 'active' : '' ?>">
                  <?= $label ?>
                  <span class="mw-filter-count"><?= $tabCounts[$key] ?></span>
              </a>
              <?php endforeach; ?>
          </div>

          <!-- Secondary Filters -->
          <div class="d-flex flex-wrap mb-3" style="gap: 8px;">
              <select id="mwTaskPriority" class="form-control form-control-sm" style="width:auto;" onchange="mwTaskFilter()">
                  <option value="">All Priorities</option>
                  <option value="high" <?= $filterPriority === 'high' ? 'selected' : '' ?>>High</option>
                  <option value="normal" <?= $filterPriority === 'normal' ? 'selected' : '' ?>>Normal</option>
                  <option value="low" <?= $filterPriority === 'low' ? 'selected' : '' ?>>Low</option>
              </select>
              <input type="text" id="mwTaskSearch" class="form-control form-control-sm" style="width:200px;" placeholder="Search tasks..." value="<?= htmlspecialchars($search) ?>" onkeydown="if(event.key==='Enter')mwTaskFilter()">
          </div>

          <!-- Task List -->
          <div class="card">
              <div class="card-body p-0">
                  <?php if (empty($tasks)): ?>
                  <div class="text-center text-muted py-5">
                      <i data-feather="check-square" style="width:48px;height:48px;opacity:.3;"></i>
                      <p class="mt-2 mb-0">No tasks found.</p>
                  </div>
                  <?php else: ?>
                  <div class="mw-task-list">
                      <?php foreach ($tasks as $t):
                          $isOverdue = $t['due_date'] && $t['due_date'] < date('Y-m-d') && $t['status'] !== 'completed';
                          $isCompleted = $t['status'] === 'completed';
                          $isPurchase = ($t['task_type'] ?? '') === 'purchase';
                      ?>

                      <?php if ($isPurchase): ?>
                      <!-- ═══ Purchase Task Card ═══ -->
                      <div class="mw-task-item mw-task-purchase" data-task-id="<?= $t['id'] ?>">
                          <div class="mw-task-check">
                              <i data-feather="shopping-bag" style="width:18px;height:18px;color:var(--mw-orange);"></i>
                          </div>
                          <div class="mw-task-body">
                              <div class="d-flex align-items-center flex-wrap" style="gap:6px;">
                                  <span class="mw-task-title <?= $isCompleted ? 'completed' : '' ?>"><?= htmlspecialchars($t['title']) ?></span>
                                  <?php if ($t['task_number']): ?>
                                  <span class="badge badge-light" style="font-size:10px;"><?= htmlspecialchars($t['task_number']) ?></span>
                                  <?php endif; ?>
                                  <?php if ($t['procurement_mode'] && isset($modeLabels[$t['procurement_mode']])): ?>
                                  <span class="badge mw-task-mode-badge mw-mode-<?= $t['procurement_mode'] ?>"><?= $modeLabels[$t['procurement_mode']] ?></span>
                                  <?php endif; ?>
                              </div>
                              <?php if ($t['vendor_name']): ?>
                              <div class="mw-task-vendor-info">
                                  <i data-feather="map-pin" style="width:11px;height:11px;"></i>
                                  <?= htmlspecialchars($t['vendor_name']) ?>
                                  <?php if ($t['vendor_location_label']): ?>
                                  <span class="text-muted">- <?= htmlspecialchars($t['vendor_location_label']) ?></span>
                                  <?php endif; ?>
                              </div>
                              <?php endif; ?>
                              <div class="mw-task-meta mt-1">
                                  <?php if ($t['purchase_status'] && isset($purchaseStatusMap[$t['purchase_status']])): ?>
                                  <span class="badge <?= $purchaseStatusMap[$t['purchase_status']]['class'] ?>" style="font-size:10px;">
                                      <?= $purchaseStatusMap[$t['purchase_status']]['label'] ?>
                                  </span>
                                  <?php endif; ?>
                                  <?php if ($t['items_count']): ?>
                                  <span><i data-feather="package" style="width:12px;height:12px;"></i> <?= $t['items_count'] ?> item<?= $t['items_count'] > 1 ? 's' : '' ?></span>
                                  <?php endif; ?>
                                  <?php if ($t['estimated_total']): ?>
                                  <span class="mw-task-cost-summary">Est: $<?= number_format((float)$t['estimated_total'], 2) ?></span>
                                  <?php endif; ?>
                                  <?php if ($t['actual_total']): ?>
                                  <span class="mw-task-cost-summary font-weight-bold">Actual: $<?= number_format((float)$t['actual_total'], 2) ?></span>
                                  <?php endif; ?>
                                  <?php if ($t['scheduled_date']): ?>
                                  <span class="<?= ($t['scheduled_date'] < date('Y-m-d') && !$isCompleted) ? 'mw-task-overdue' : '' ?>">
                                      <i data-feather="calendar" style="width:12px;height:12px;"></i>
                                      <?= date('M j', strtotime($t['scheduled_date'])) ?>
                                  </span>
                                  <?php endif; ?>
                                  <?php if ($t['assigned_to_name']): ?>
                                  <span><i data-feather="user" style="width:12px;height:12px;"></i> <?= htmlspecialchars($t['assigned_to_name']) ?></span>
                                  <?php endif; ?>
                                  <?php if ($t['plan_number']): ?>
                                  <span class="mw-task-entity-links"><a href="/crm/jobs/view.php?id=<?= $t['plan_id'] ?>"><?= htmlspecialchars($t['plan_number']) ?></a></span>
                                  <?php endif; ?>
                                  <?php if ($t['total_time_minutes']): ?>
                                  <span><i data-feather="clock" style="width:12px;height:12px;"></i> <?= $t['total_time_minutes'] ?>m</span>
                                  <?php endif; ?>
                              </div>
                          </div>
                          <div class="mw-task-actions">
                              <button class="btn btn-sm btn-link text-muted p-0" onclick="mwPurchaseModal(<?= $t['id'] ?>)" title="Edit">
                                  <i data-feather="edit-2" style="width:14px;height:14px;"></i>
                              </button>
                          </div>
                      </div>

                      <?php else: ?>
                      <!-- ═══ General Task Card ═══ -->
                      <div class="mw-task-item mw-task-priority-<?= $t['priority'] ?> <?= $isCompleted ? 'mw-task-done' : '' ?>" data-task-id="<?= $t['id'] ?>">
                          <div class="mw-task-check">
                              <input type="checkbox" <?= $isCompleted ? 'checked' : '' ?>
                                     onchange="mwToggleTask(<?= $t['id'] ?>, this.checked)"
                                     title="<?= $isCompleted ? 'Reopen' : 'Complete' ?>">
                          </div>
                          <div class="mw-task-body">
                              <div class="mw-task-title <?= $isCompleted ? 'completed' : '' ?>"><?= htmlspecialchars($t['title']) ?></div>
                              <?php if ($t['description']): ?>
                              <div class="mw-task-desc text-muted small"><?= htmlspecialchars(mb_strimwidth($t['description'], 0, 120, '...')) ?></div>
                              <?php endif; ?>
                              <div class="mw-task-meta mt-1">
                                  <?php if ($t['due_date']): ?>
                                  <span class="<?= $isOverdue ? 'mw-task-overdue' : '' ?>">
                                      <i data-feather="calendar" style="width:12px;height:12px;"></i>
                                      <?= date('M j', strtotime($t['due_date'])) ?>
                                      <?php if ($t['due_time']): ?> <?= date('g:ia', strtotime($t['due_time'])) ?><?php endif; ?>
                                  </span>
                                  <?php endif; ?>
                                  <?php if ($t['assigned_to_name']): ?>
                                  <span><i data-feather="user" style="width:12px;height:12px;"></i> <?= htmlspecialchars($t['assigned_to_name']) ?></span>
                                  <?php endif; ?>
                                  <?php if ($t['priority'] === 'high'): ?>
                                  <span class="badge badge-danger" style="font-size:10px;">High</span>
                                  <?php endif; ?>
                                  <?php
                                  $links = [];
                                  if ($t['contact_first']) $links[] = '<a href="/crm/clients_appstack.php?action=view_contact&id=' . $t['contact_id'] . '">' . htmlspecialchars($t['contact_first'] . ' ' . $t['contact_last']) . '</a>';
                                  if ($t['quote_number']) $links[] = '<a href="/crm/quotes/view.php?id=' . $t['quote_id'] . '">' . htmlspecialchars($t['quote_number']) . '</a>';
                                  if ($t['plan_number']) $links[] = '<a href="/crm/jobs/view.php?id=' . $t['plan_id'] . '">' . htmlspecialchars($t['plan_number']) . '</a>';
                                  if ($t['invoice_number']) $links[] = '<a href="/crm/invoices/view.php?id=' . $t['invoice_id'] . '">' . htmlspecialchars($t['invoice_number']) . '</a>';
                                  if ($links): ?>
                                  <span class="mw-task-entity-links"><?= implode(' &middot; ', $links) ?></span>
                                  <?php endif; ?>
                              </div>
                          </div>
                          <div class="mw-task-actions">
                              <button class="btn btn-sm btn-link text-muted p-0" onclick="mwTaskModal(<?= $t['id'] ?>)" title="Edit">
                                  <i data-feather="edit-2" style="width:14px;height:14px;"></i>
                              </button>
                          </div>
                      </div>
                      <?php endif; ?>

                      <?php endforeach; ?>
                  </div>
                  <?php endif; ?>
              </div>
          </div>

          <!-- ═══ General Task Modal ═══ -->
          <div class="modal fade" id="mwTaskModalEl" tabindex="-1">
              <div class="modal-dialog">
                  <div class="modal-content">
                      <div class="modal-header">
                          <h5 class="modal-title" id="mwTaskModalTitle">New Task</h5>
                          <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                      </div>
                      <div class="modal-body">
                          <input type="hidden" id="mwTaskId" value="">
                          <div class="form-group">
                              <label>Title <span class="text-danger">*</span></label>
                              <input type="text" id="mwTaskTitle" class="form-control" placeholder="e.g. Follow up on quote">
                          </div>
                          <div class="form-group">
                              <label>Description</label>
                              <textarea id="mwTaskDesc" class="form-control" rows="2"></textarea>
                          </div>
                          <div class="row">
                              <div class="col-md-6 form-group">
                                  <label>Due Date</label>
                                  <input type="date" id="mwTaskDueDate" class="form-control">
                              </div>
                              <div class="col-md-6 form-group">
                                  <label>Due Time</label>
                                  <input type="time" id="mwTaskDueTime" class="form-control">
                              </div>
                          </div>
                          <div class="row">
                              <div class="col-md-6 form-group">
                                  <label>Priority</label>
                                  <select id="mwTaskPriorityInput" class="form-control">
                                      <option value="normal">Normal</option>
                                      <option value="high">High</option>
                                      <option value="low">Low</option>
                                  </select>
                              </div>
                              <div class="col-md-6 form-group">
                                  <label>Assign To</label>
                                  <select id="mwTaskAssignTo" class="form-control">
                                      <?php foreach ($allUsers as $u): ?>
                                      <option value="<?= $u['id'] ?>" <?= (int)$u['id'] === (int)$user['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['full_name']) ?></option>
                                      <?php endforeach; ?>
                                  </select>
                              </div>
                          </div>
                          <div id="mwTaskEntityFields" style="display:none;">
                              <hr>
                              <small class="text-muted">Linked to:</small>
                              <div id="mwTaskEntityInfo" class="small font-weight-bold"></div>
                          </div>
                      </div>
                      <div class="modal-footer">
                          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                          <button type="button" class="btn btn-primary" onclick="mwSaveTask()">Save Task</button>
                      </div>
                  </div>
              </div>
          </div>

          <!-- ═══ Purchase Task Modal ═══ -->
          <div class="modal fade" id="mwPurchaseModalEl" tabindex="-1">
              <div class="modal-dialog modal-lg">
                  <div class="modal-content">
                      <div class="modal-header" style="border-bottom: 3px solid var(--mw-orange);">
                          <h5 class="modal-title" id="mwPurchaseModalTitle">
                              <i data-feather="shopping-bag" style="width:18px;height:18px;color:var(--mw-orange);"></i>
                              New Purchase Task
                          </h5>
                          <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                      </div>
                      <div class="modal-body">
                          <input type="hidden" id="mwPurchaseId" value="">

                          <!-- Title -->
                          <div class="form-group">
                              <label>Title <span class="text-danger">*</span></label>
                              <input type="text" id="mwPurchaseTitle" class="form-control" placeholder="e.g. Lattice fence sheets for Smith job">
                          </div>

                          <!-- Vendor + Location Row -->
                          <div class="row">
                              <div class="col-md-6 form-group">
                                  <label>Vendor <span class="text-danger">*</span></label>
                                  <input type="text" id="mwPurchaseVendorSearch" class="form-control" placeholder="Search vendors..."
                                         autocomplete="off" oninput="mwSearchVendors(this.value)">
                                  <input type="hidden" id="mwPurchaseVendorId" value="">
                                  <div id="mwVendorDropdown" class="mw-vendor-dropdown" style="display:none;"></div>
                              </div>
                              <div class="col-md-6 form-group">
                                  <label>Location</label>
                                  <select id="mwPurchaseLocationId" class="form-control" disabled>
                                      <option value="">Select vendor first</option>
                                  </select>
                              </div>
                          </div>

                          <!-- Mode + Job Row -->
                          <div class="row">
                              <div class="col-md-4 form-group">
                                  <label>Procurement Mode</label>
                                  <select id="mwPurchaseMode" class="form-control">
                                      <option value="on_route">On Route</option>
                                      <option value="asap">ASAP</option>
                                      <option value="truck_kit">Truck Kit</option>
                                  </select>
                              </div>
                              <div class="col-md-4 form-group">
                                  <label>Link to Job Plan</label>
                                  <select id="mwPurchasePlanId" class="form-control">
                                      <option value="">None</option>
                                  </select>
                              </div>
                              <div class="col-md-4 form-group">
                                  <label>Priority</label>
                                  <select id="mwPurchasePriority" class="form-control">
                                      <option value="normal">Normal</option>
                                      <option value="high">High</option>
                                      <option value="low">Low</option>
                                  </select>
                              </div>
                          </div>

                          <!-- Dates + Assign Row -->
                          <div class="row">
                              <div class="col-md-4 form-group">
                                  <label>Needed By</label>
                                  <input type="date" id="mwPurchaseRequestedDate" class="form-control">
                              </div>
                              <div class="col-md-4 form-group">
                                  <label>Scheduled Date</label>
                                  <input type="date" id="mwPurchaseScheduledDate" class="form-control">
                              </div>
                              <div class="col-md-4 form-group">
                                  <label>Assign To</label>
                                  <select id="mwPurchaseAssignTo" class="form-control">
                                      <option value="">Unassigned</option>
                                      <?php foreach ($allUsers as $u): ?>
                                      <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?></option>
                                      <?php endforeach; ?>
                                  </select>
                              </div>
                          </div>

                          <!-- Items Section -->
                          <div class="mt-2 mb-2">
                              <div class="d-flex justify-content-between align-items-center mb-2">
                                  <label class="mb-0 font-weight-bold">Items to Purchase</label>
                                  <div>
                                      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="mwAddPurchaseItemFromCatalog()">
                                          <i data-feather="book-open" style="width:14px;height:14px;"></i> From Catalog
                                      </button>
                                      <button type="button" class="btn btn-sm btn-outline-primary" onclick="mwAddPurchaseItem()">
                                          <i data-feather="plus" style="width:14px;height:14px;"></i> Custom Item
                                      </button>
                                  </div>
                              </div>
                              <table class="table table-sm mw-task-item-list" id="mwPurchaseItemsTable">
                                  <thead>
                                      <tr>
                                          <th>Item</th>
                                          <th style="width:80px;">Qty</th>
                                          <th style="width:80px;">Unit</th>
                                          <th style="width:100px;">Est. Price</th>
                                          <th style="width:40px;"></th>
                                      </tr>
                                  </thead>
                                  <tbody id="mwPurchaseItemsBody">
                                      <tr id="mwPurchaseItemsEmpty">
                                          <td colspan="5" class="text-center text-muted py-3">No items added yet</td>
                                      </tr>
                                  </tbody>
                                  <tfoot>
                                      <tr>
                                          <td colspan="3" class="text-right font-weight-bold">Estimated Total:</td>
                                          <td id="mwPurchaseEstTotal" class="font-weight-bold">$0.00</td>
                                          <td></td>
                                      </tr>
                                  </tfoot>
                              </table>
                          </div>

                          <!-- Notes -->
                          <div class="form-group">
                              <label>Notes / Instructions</label>
                              <textarea id="mwPurchaseNotes" class="form-control" rows="2" placeholder="Substitution preferences, special instructions..."></textarea>
                          </div>
                      </div>
                      <div class="modal-footer">
                          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                          <button type="button" class="btn btn-primary" onclick="mwSavePurchase()" style="background:var(--mw-orange);border-color:var(--mw-orange);">
                              <i data-feather="shopping-bag" style="width:16px;height:16px;"></i> Save Purchase Task
                          </button>
                      </div>
                  </div>
              </div>
          </div>

          <!-- ═══ Vendor Product Catalog Modal ═══ -->
          <div class="modal fade" id="mwCatalogModalEl" tabindex="-1">
              <div class="modal-dialog">
                  <div class="modal-content">
                      <div class="modal-header">
                          <h5 class="modal-title">Vendor Product Catalog</h5>
                          <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                      </div>
                      <div class="modal-body" id="mwCatalogBody" style="max-height:400px;overflow-y:auto;">
                          <p class="text-muted">Select a vendor first.</p>
                      </div>
                  </div>
              </div>
          </div>

          <script>
          var _mwCsrf = <?= json_encode($csrfToken) ?>;
          var _mwCurrentTab = <?= json_encode($filterTab) ?>;
          var _mwPurchaseItems = [];
          var _mwVendorSearchTimeout = null;

          // ── General Task Functions ─────────────────────────────────────────

          function mwTaskFilter() {
              var priority = document.getElementById('mwTaskPriority').value;
              var search   = document.getElementById('mwTaskSearch').value;
              var url = '?tab=' + _mwCurrentTab;
              if (priority) url += '&priority=' + priority;
              if (search)   url += '&q=' + encodeURIComponent(search);
              location.href = url;
          }

          var _mwTaskEntity = {};

          function mwTaskModal(taskId) {
              document.getElementById('mwTaskId').value = taskId || '';
              document.getElementById('mwTaskModalTitle').textContent = taskId ? 'Edit Task' : 'New Task';

              if (taskId) {
                  fetch('/crm/api/tasks.php?assigned_to=&status=&limit=200')
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          var task = (data.data || []).find(function(t) { return t.id == taskId; });
                          if (task) {
                              document.getElementById('mwTaskTitle').value = task.title;
                              document.getElementById('mwTaskDesc').value = task.description || '';
                              document.getElementById('mwTaskDueDate').value = task.due_date || '';
                              document.getElementById('mwTaskDueTime').value = task.due_time || '';
                              document.getElementById('mwTaskPriorityInput').value = task.priority;
                              document.getElementById('mwTaskAssignTo').value = task.assigned_to || '';
                          }
                      });
              } else {
                  document.getElementById('mwTaskTitle').value = '';
                  document.getElementById('mwTaskDesc').value = '';
                  document.getElementById('mwTaskDueDate').value = '';
                  document.getElementById('mwTaskDueTime').value = '';
                  document.getElementById('mwTaskPriorityInput').value = 'normal';
                  document.getElementById('mwTaskAssignTo').value = <?= json_encode($user['id']) ?>;
              }

              $('#mwTaskModalEl').modal('show');
          }

          function mwSaveTask() {
              var taskId = document.getElementById('mwTaskId').value;
              var body = Object.assign({
                  csrf_token: _mwCsrf,
                  task_type: 'general',
                  title: document.getElementById('mwTaskTitle').value,
                  description: document.getElementById('mwTaskDesc').value,
                  due_date: document.getElementById('mwTaskDueDate').value,
                  due_time: document.getElementById('mwTaskDueTime').value,
                  priority: document.getElementById('mwTaskPriorityInput').value,
                  assigned_to: document.getElementById('mwTaskAssignTo').value,
              }, _mwTaskEntity);

              var action = taskId ? 'update' : 'create';
              if (taskId) body.task_id = taskId;

              fetch('/crm/api/tasks.php?action=' + action, {
                  method: 'POST',
                  headers: {'Content-Type': 'application/json'},
                  body: JSON.stringify(body)
              })
              .then(function(r) { return r.json(); })
              .then(function(data) {
                  if (data.success) {
                      location.reload();
                  } else {
                      alert(data.error || 'Failed to save task');
                  }
              });
          }

          function mwToggleTask(taskId, completed) {
              var action = completed ? 'complete' : 'reopen';
              fetch('/crm/api/tasks.php?action=' + action, {
                  method: 'POST',
                  headers: {'Content-Type': 'application/json'},
                  body: JSON.stringify({csrf_token: _mwCsrf, task_id: taskId})
              })
              .then(function(r) { return r.json(); })
              .then(function(data) {
                  if (data.success) location.reload();
              });
          }

          // ── Purchase Task Functions ────────────────────────────────────────

          function mwPurchaseModal(taskId) {
              document.getElementById('mwPurchaseId').value = taskId || '';
              document.getElementById('mwPurchaseModalTitle').innerHTML =
                  '<i data-feather="shopping-bag" style="width:18px;height:18px;color:var(--mw-orange);"></i> ' +
                  (taskId ? 'Edit Purchase Task' : 'New Purchase Task');

              // Reset form
              if (!taskId) {
                  document.getElementById('mwPurchaseTitle').value = '';
                  document.getElementById('mwPurchaseVendorSearch').value = '';
                  document.getElementById('mwPurchaseVendorId').value = '';
                  document.getElementById('mwPurchaseLocationId').innerHTML = '<option value="">Select vendor first</option>';
                  document.getElementById('mwPurchaseLocationId').disabled = true;
                  document.getElementById('mwPurchaseMode').value = 'on_route';
                  document.getElementById('mwPurchasePlanId').value = '';
                  document.getElementById('mwPurchasePriority').value = 'normal';
                  document.getElementById('mwPurchaseRequestedDate').value = '';
                  document.getElementById('mwPurchaseScheduledDate').value = '';
                  document.getElementById('mwPurchaseAssignTo').value = '';
                  document.getElementById('mwPurchaseNotes').value = '';
                  _mwPurchaseItems = [];
                  mwRenderPurchaseItems();
              } else {
                  // Load existing purchase task
                  fetch('/crm/api/tasks.php?task_type=purchase&limit=200')
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          var task = (data.data || []).find(function(t) { return t.id == taskId; });
                          if (task) {
                              document.getElementById('mwPurchaseTitle').value = task.title || '';
                              document.getElementById('mwPurchaseVendorSearch').value = task.vendor_name || '';
                              document.getElementById('mwPurchaseVendorId').value = task.vendor_id || '';
                              document.getElementById('mwPurchaseMode').value = task.procurement_mode || 'on_route';
                              document.getElementById('mwPurchasePlanId').value = task.plan_id || '';
                              document.getElementById('mwPurchasePriority').value = task.priority || 'normal';
                              document.getElementById('mwPurchaseRequestedDate').value = task.requested_date || '';
                              document.getElementById('mwPurchaseScheduledDate').value = task.scheduled_date || '';
                              document.getElementById('mwPurchaseAssignTo').value = task.assigned_to || '';
                              document.getElementById('mwPurchaseNotes').value = task.description || '';

                              // Load vendor locations
                              if (task.vendor_id) {
                                  mwLoadVendorLocations(task.vendor_id, task.vendor_location_id);
                              }

                              // Load items
                              fetch('/crm/api/tasks.php?action=get_items&task_id=' + taskId)
                                  .then(function(r) { return r.json(); })
                                  .then(function(itemData) {
                                      _mwPurchaseItems = (itemData.data || []).map(function(item) {
                                          return {
                                              vendor_product_id: item.vendor_product_id,
                                              name: item.name,
                                              quantity: parseFloat(item.quantity) || 1,
                                              unit: item.unit || '',
                                              estimated_unit_price: parseFloat(item.estimated_unit_price) || 0,
                                          };
                                      });
                                      mwRenderPurchaseItems();
                                  });
                          }
                      });
              }

              // Load active plans for the plan dropdown
              mwLoadActivePlans();

              $('#mwPurchaseModalEl').modal('show');
              if (feather) feather.replace();
          }

          function mwSearchVendors(q) {
              clearTimeout(_mwVendorSearchTimeout);
              if (q.length < 2) {
                  document.getElementById('mwVendorDropdown').style.display = 'none';
                  return;
              }
              _mwVendorSearchTimeout = setTimeout(function() {
                  fetch('/crm/api/vendors.php?action=search&q=' + encodeURIComponent(q))
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          var dd = document.getElementById('mwVendorDropdown');
                          if (!data.vendors || !data.vendors.length) {
                              dd.style.display = 'none';
                              return;
                          }
                          dd.innerHTML = data.vendors.map(function(v) {
                              return '<div class="mw-vendor-option" onclick="mwSelectVendor(' + v.id + ',\'' +
                                  v.name.replace(/'/g, "\\'") + '\')">' +
                                  '<strong>' + v.name + '</strong>' +
                                  (v.default_accounting_category ? ' <small class="text-muted">' + v.default_accounting_category + '</small>' : '') +
                                  '</div>';
                          }).join('');
                          dd.style.display = 'block';
                      });
              }, 250);
          }

          function mwSelectVendor(vendorId, vendorName) {
              document.getElementById('mwPurchaseVendorSearch').value = vendorName;
              document.getElementById('mwPurchaseVendorId').value = vendorId;
              document.getElementById('mwVendorDropdown').style.display = 'none';
              mwLoadVendorLocations(vendorId);
          }

          function mwLoadVendorLocations(vendorId, selectedId) {
              fetch('/crm/api/vendors.php?action=get&id=' + vendorId)
                  .then(function(r) { return r.json(); })
                  .then(function(data) {
                      var sel = document.getElementById('mwPurchaseLocationId');
                      var locations = (data.vendor && data.vendor.locations) || [];
                      sel.innerHTML = '<option value="">-- Select Location --</option>';
                      locations.forEach(function(loc) {
                          var label = (loc.label || loc.address || 'Location #' + loc.id);
                          if (loc.city) label += ' (' + loc.city + ')';
                          var opt = document.createElement('option');
                          opt.value = loc.id;
                          opt.textContent = label;
                          if (selectedId && loc.id == selectedId) opt.selected = true;
                          if (loc.is_preferred == 1 && !selectedId) opt.selected = true;
                          sel.appendChild(opt);
                      });
                      sel.disabled = false;
                  });
          }

          function mwLoadActivePlans() {
              var sel = document.getElementById('mwPurchasePlanId');
              if (sel.options.length > 1) return; // Already loaded
              fetch('/crm/api/tasks.php?limit=1') // Cheap call just to warm connection
                  .then(function() {
                      // Load plans from jobs API or inline from PHP
                  });
              // For now, load from PHP-rendered data (simpler, no extra API call)
          }

          function mwAddPurchaseItem(name, qty, unit, price, vpId) {
              _mwPurchaseItems.push({
                  vendor_product_id: vpId || null,
                  name: name || '',
                  quantity: qty || 1,
                  unit: unit || '',
                  estimated_unit_price: price || 0,
              });
              mwRenderPurchaseItems();
          }

          function mwRemovePurchaseItem(idx) {
              _mwPurchaseItems.splice(idx, 1);
              mwRenderPurchaseItems();
          }

          function mwRenderPurchaseItems() {
              var tbody = document.getElementById('mwPurchaseItemsBody');
              var empty = document.getElementById('mwPurchaseItemsEmpty');

              if (!_mwPurchaseItems.length) {
                  tbody.innerHTML = '<tr id="mwPurchaseItemsEmpty"><td colspan="5" class="text-center text-muted py-3">No items added yet</td></tr>';
                  document.getElementById('mwPurchaseEstTotal').textContent = '$0.00';
                  return;
              }

              var total = 0;
              tbody.innerHTML = _mwPurchaseItems.map(function(item, i) {
                  var lineTotal = (item.quantity || 0) * (item.estimated_unit_price || 0);
                  total += lineTotal;
                  return '<tr>' +
                      '<td><input type="text" class="form-control form-control-sm" value="' + (item.name || '').replace(/"/g, '&quot;') + '" onchange="_mwPurchaseItems[' + i + '].name=this.value"></td>' +
                      '<td><input type="number" class="form-control form-control-sm" value="' + (item.quantity || 1) + '" min="0" step="0.5" onchange="_mwPurchaseItems[' + i + '].quantity=parseFloat(this.value)||1;mwRenderPurchaseItems()"></td>' +
                      '<td><input type="text" class="form-control form-control-sm" value="' + (item.unit || '') + '" placeholder="each" onchange="_mwPurchaseItems[' + i + '].unit=this.value"></td>' +
                      '<td><input type="number" class="form-control form-control-sm" value="' + (item.estimated_unit_price || '') + '" min="0" step="0.01" onchange="_mwPurchaseItems[' + i + '].estimated_unit_price=parseFloat(this.value)||0;mwRenderPurchaseItems()"></td>' +
                      '<td><button class="btn btn-sm btn-link text-danger p-0" onclick="mwRemovePurchaseItem(' + i + ')" title="Remove"><i data-feather="x" style="width:14px;height:14px;"></i></button></td>' +
                      '</tr>';
              }).join('');

              document.getElementById('mwPurchaseEstTotal').textContent = '$' + total.toFixed(2);
              if (feather) feather.replace();
          }

          function mwAddPurchaseItemFromCatalog() {
              var vendorId = document.getElementById('mwPurchaseVendorId').value;
              if (!vendorId) {
                  alert('Select a vendor first');
                  return;
              }
              // Load catalog
              fetch('/crm/api/vendors.php?action=products&vendor_id=' + vendorId)
                  .then(function(r) { return r.json(); })
                  .then(function(data) {
                      var body = document.getElementById('mwCatalogBody');
                      var grouped = data.grouped || {};
                      var cats = Object.keys(grouped);
                      if (!cats.length) {
                          body.innerHTML = '<p class="text-muted">No products in catalog for this vendor.</p>';
                      } else {
                          body.innerHTML = cats.map(function(cat) {
                              return '<h6 class="mt-2">' + cat + '</h6>' +
                                  grouped[cat].map(function(p) {
                                      var priceStr = p.price_per_unit ? ' — $' + parseFloat(p.price_per_unit).toFixed(2) + '/' + (p.unit || 'unit') : '';
                                      return '<div class="d-flex justify-content-between align-items-center py-1 border-bottom">' +
                                          '<span>' + p.name + '<small class="text-muted">' + priceStr + '</small></span>' +
                                          '<button class="btn btn-sm btn-outline-primary" onclick="mwAddFromCatalog(' + p.id + ',\'' +
                                          p.name.replace(/'/g, "\\'") + '\',' + (parseFloat(p.price_per_unit) || 0) + ',\'' + (p.unit || 'each') + '\')">Add</button>' +
                                          '</div>';
                                  }).join('');
                          }).join('');
                      }
                      $('#mwCatalogModalEl').modal('show');
                  });
          }

          function mwAddFromCatalog(vpId, name, price, unit) {
              mwAddPurchaseItem(name, 1, unit, price, vpId);
              $('#mwCatalogModalEl').modal('hide');
          }

          function mwSavePurchase() {
              var taskId = document.getElementById('mwPurchaseId').value;
              var vendorId = document.getElementById('mwPurchaseVendorId').value;

              if (!vendorId) {
                  alert('Please select a vendor');
                  return;
              }

              var title = document.getElementById('mwPurchaseTitle').value;
              if (!title) {
                  alert('Please enter a title');
                  return;
              }

              var body = {
                  csrf_token: _mwCsrf,
                  task_type: 'purchase',
                  title: title,
                  description: document.getElementById('mwPurchaseNotes').value,
                  vendor_id: vendorId,
                  vendor_location_id: document.getElementById('mwPurchaseLocationId').value || null,
                  procurement_mode: document.getElementById('mwPurchaseMode').value,
                  plan_id: document.getElementById('mwPurchasePlanId').value || null,
                  priority: document.getElementById('mwPurchasePriority').value,
                  requested_date: document.getElementById('mwPurchaseRequestedDate').value || null,
                  scheduled_date: document.getElementById('mwPurchaseScheduledDate').value || null,
                  assigned_to: document.getElementById('mwPurchaseAssignTo').value || null,
                  items: _mwPurchaseItems,
              };

              var action = taskId ? 'update' : 'create';
              if (taskId) body.task_id = taskId;

              fetch('/crm/api/tasks.php?action=' + action, {
                  method: 'POST',
                  headers: {'Content-Type': 'application/json'},
                  body: JSON.stringify(body)
              })
              .then(function(r) { return r.json(); })
              .then(function(data) {
                  if (data.success) {
                      // If editing and there are items, also save items separately
                      if (taskId && _mwPurchaseItems.length) {
                          fetch('/crm/api/tasks.php?action=save_items', {
                              method: 'POST',
                              headers: {'Content-Type': 'application/json'},
                              body: JSON.stringify({csrf_token: _mwCsrf, task_id: taskId, items: _mwPurchaseItems})
                          }).then(function() { location.href = '?tab=purchases'; });
                      } else {
                          location.href = '?tab=purchases';
                      }
                  } else {
                      alert(data.error || 'Failed to save purchase task');
                  }
              });
          }

          // Close vendor dropdown on outside click
          document.addEventListener('click', function(e) {
              if (!e.target.closest('#mwPurchaseVendorSearch') && !e.target.closest('#mwVendorDropdown')) {
                  document.getElementById('mwVendorDropdown').style.display = 'none';
              }
          });
          </script>

<?php
// Load active plans for the purchase modal dropdown (server-side render)
$plansStmt = $db->query("
    SELECT jp.id, jp.plan_number, jp.title, p.address AS property_address
    FROM job_plans jp
    LEFT JOIN properties p ON jp.property_id = p.id
    WHERE jp.status = 'active'
    ORDER BY jp.plan_number DESC
    LIMIT 100
");
$activePlans = $plansStmt->fetchAll(PDO::FETCH_ASSOC);
?>
          <script>
          // Populate plan dropdown from server data
          (function() {
              var sel = document.getElementById('mwPurchasePlanId');
              var plans = <?= json_encode($activePlans) ?>;
              plans.forEach(function(p) {
                  var opt = document.createElement('option');
                  opt.value = p.id;
                  opt.textContent = p.plan_number + ' — ' + (p.title || p.property_address || 'Plan');
                  sel.appendChild(opt);
              });
          })();
          </script>

<?php include 'includes/appstack_footer.php'; ?>
