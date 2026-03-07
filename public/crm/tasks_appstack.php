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
        break;
    case 'all':
        $where[] = "t.status != 'completed'";
        break;
    case 'overdue':
        $where[] = "t.status != 'completed' AND t.due_date IS NOT NULL AND t.due_date < CURDATE()";
        break;
    case 'completed':
        $where[] = "t.status = 'completed'";
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
$tabCounts = [];
$tabCounts['my']       = (int)$db->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status != 'completed'")->execute([$user['id']]) ? $db->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status != 'completed'")->execute([$user['id']]) : 0;
// Re-do counts properly
$cStmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status != 'completed'");
$cStmt->execute([$user['id']]);
$tabCounts['my'] = (int)$cStmt->fetchColumn();

$cStmt = $db->query("SELECT COUNT(*) FROM tasks WHERE status != 'completed'");
$tabCounts['all'] = (int)$cStmt->fetchColumn();

$cStmt = $db->query("SELECT COUNT(*) FROM tasks WHERE status != 'completed' AND due_date IS NOT NULL AND due_date < CURDATE()");
$tabCounts['overdue'] = (int)$cStmt->fetchColumn();

$cStmt = $db->query("SELECT COUNT(*) FROM tasks WHERE status = 'completed'");
$tabCounts['completed'] = (int)$cStmt->fetchColumn();

// Fetch tasks
$stmt = $db->prepare("
    SELECT t.*,
           u_assigned.full_name AS assigned_to_name,
           u_created.full_name AS created_by_name,
           c.first_name AS contact_first, c.last_name AS contact_last,
           q.quote_number,
           jp.plan_number,
           inv.invoice_number
    FROM tasks t
    LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
    LEFT JOIN users u_created ON t.created_by = u_created.id
    LEFT JOIN contacts c ON t.contact_id = c.id
    LEFT JOIN quotes q ON t.quote_id = q.id
    LEFT JOIN job_plans jp ON t.plan_id = jp.id
    LEFT JOIN invoices inv ON t.invoice_id = inv.id
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
?>
<?php include 'includes/appstack_head.php'; ?>

          <div class="d-flex justify-content-between align-items-center mb-3">
              <h1 class="h3 mb-0">Tasks</h1>
              <button class="btn btn-primary" onclick="mwTaskModal()">
                  <i data-feather="plus" style="width:16px;height:16px;"></i> New Task
              </button>
          </div>

          <!-- Filter Tabs -->
          <div class="mw-filter-tabs mb-3">
              <?php
              $tabs = [
                  'my'        => 'My Tasks',
                  'all'       => 'All Tasks',
                  'overdue'   => 'Overdue',
                  'completed' => 'Completed',
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
                      ?>
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
                                  // Entity links
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
                      <?php endforeach; ?>
                  </div>
                  <?php endif; ?>
              </div>
          </div>

          <!-- Create/Edit Task Modal -->
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

          <script>
          var _mwCsrf = <?= json_encode($csrfToken) ?>;
          var _mwCurrentTab = <?= json_encode($filterTab) ?>;

          function mwTaskFilter() {
              var priority = document.getElementById('mwTaskPriority').value;
              var search   = document.getElementById('mwTaskSearch').value;
              var url = '?tab=' + _mwCurrentTab;
              if (priority) url += '&priority=' + priority;
              if (search)   url += '&q=' + encodeURIComponent(search);
              location.href = url;
          }

          // Store entity context for quick-add from other pages
          var _mwTaskEntity = {};

          function mwTaskModal(taskId) {
              document.getElementById('mwTaskId').value = taskId || '';
              document.getElementById('mwTaskModalTitle').textContent = taskId ? 'Edit Task' : 'New Task';

              if (taskId) {
                  // Load existing task data
                  fetch('/crm/api/tasks.php?assigned_to=&status=&limit=200')
                      .then(r => r.json())
                      .then(data => {
                          var task = (data.data || []).find(t => t.id == taskId);
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
              .then(r => r.json())
              .then(data => {
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
              .then(r => r.json())
              .then(data => {
                  if (data.success) location.reload();
              });
          }
          </script>

<?php include 'includes/appstack_footer.php'; ?>
