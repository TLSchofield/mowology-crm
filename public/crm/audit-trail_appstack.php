<?php
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

if (!function_exists('userHasPermission') || !userHasPermission('settings.edit')) {
    $_SESSION['alert'] = ['type' => 'danger', 'message' => 'You do not have permission to view the audit trail.'];
    header('Location: /crm/dashboard_appstack.php');
    exit;
}

$pageTitle  = 'Audit Trail';
$activePage = 'audit-trail';

$db = getDB();

// ── Filters ─────────────────────────────────────────────────────────────────
$filterEntity = $_GET['entity_type'] ?? '';
$filterUser   = (int)($_GET['user_id'] ?? 0);
$filterField  = $_GET['field'] ?? '';
$filterFrom   = $_GET['date_from'] ?? '';
$filterTo     = $_GET['date_to'] ?? '';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 50;
$offset       = ($page - 1) * $perPage;

// Sanitise dates
if ($filterFrom && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) $filterFrom = '';
if ($filterTo   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo))   $filterTo   = '';

// Build query
$where  = [];
$params = [];

if ($filterEntity) {
    $where[]  = 'fc.entity_type = ?';
    $params[] = $filterEntity;
}
if ($filterUser) {
    $where[]  = 'fc.changed_by = ?';
    $params[] = $filterUser;
}
if ($filterField) {
    $where[]  = 'fc.field_name LIKE ?';
    $params[] = '%' . $filterField . '%';
}
if ($filterFrom) {
    $where[]  = 'fc.changed_at >= ?';
    $params[] = $filterFrom . ' 00:00:00';
}
if ($filterTo) {
    $where[]  = 'fc.changed_at <= ?';
    $params[] = $filterTo . ' 23:59:59';
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count
$countStmt = $db->prepare("SELECT COUNT(*) FROM field_changes fc {$whereClause}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Fetch
$dataParams = array_merge($params, [$perPage, $offset]);
$stmt = $db->prepare("
    SELECT fc.*, u.full_name AS changed_by_name
    FROM field_changes fc
    LEFT JOIN users u ON fc.changed_by = u.id
    {$whereClause}
    ORDER BY fc.changed_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute($dataParams);
$changes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Users for filter dropdown
$usersStmt = $db->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name");
$allUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

// Entity types for filter
$entityTypes = ['contact', 'quote', 'job_plan', 'invoice', 'task'];
?>
<?php include 'includes/appstack_head.php'; ?>

          <div class="mw-page-header">
            <div class="mw-page-header-left">
              <h1 class="mw-page-title">Audit Trail</h1>
            </div>
            <span class="text-muted"><?= number_format($totalRows) ?> changes</span>
          </div>

          <!-- Filters -->
          <div class="card mb-3">
              <div class="card-body py-2">
                  <form method="GET" class="d-flex flex-wrap align-items-end" style="gap: 12px;">
                      <div>
                          <label class="small text-muted mb-0">Entity</label>
                          <select name="entity_type" class="form-control form-control-sm">
                              <option value="">All</option>
                              <?php foreach ($entityTypes as $et): ?>
                              <option value="<?= $et ?>" <?= $filterEntity === $et ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $et)) ?></option>
                              <?php endforeach; ?>
                          </select>
                      </div>
                      <div>
                          <label class="small text-muted mb-0">User</label>
                          <select name="user_id" class="form-control form-control-sm">
                              <option value="">All</option>
                              <?php foreach ($allUsers as $u): ?>
                              <option value="<?= $u['id'] ?>" <?= $filterUser === (int)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['full_name']) ?></option>
                              <?php endforeach; ?>
                          </select>
                      </div>
                      <div>
                          <label class="small text-muted mb-0">Field</label>
                          <input type="text" name="field" class="form-control form-control-sm" value="<?= htmlspecialchars($filterField) ?>" placeholder="e.g. status">
                      </div>
                      <div>
                          <label class="small text-muted mb-0">From</label>
                          <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($filterFrom) ?>">
                      </div>
                      <div>
                          <label class="small text-muted mb-0">To</label>
                          <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($filterTo) ?>">
                      </div>
                      <div>
                          <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                          <a href="/crm/audit-trail_appstack.php" class="btn btn-sm btn-outline-secondary">Clear</a>
                      </div>
                  </form>
              </div>
          </div>

          <!-- Changes Table -->
          <div class="card">
              <div class="table-responsive">
                  <table class="mw-table">
                      <thead>
                          <tr>
                              <th>When</th>
                              <th>User</th>
                              <th>Entity</th>
                              <th>Field</th>
                              <th>Old Value</th>
                              <th>New Value</th>
                          </tr>
                      </thead>
                      <tbody>
                          <?php if (empty($changes)): ?>
                          <tr><td colspan="6" class="text-center text-muted py-4">No changes found.</td></tr>
                          <?php else: foreach ($changes as $c): ?>
                          <tr>
                              <td class="text-nowrap small"><?= date('M j, g:ia', strtotime($c['changed_at'])) ?></td>
                              <td class="small"><?= htmlspecialchars($c['changed_by_name'] ?? 'System') ?></td>
                              <td>
                                  <span class="badge badge-light"><?= htmlspecialchars($c['entity_type']) ?></span>
                                  <span class="text-muted small">#<?= (int)$c['entity_id'] ?></span>
                              </td>
                              <td class="small font-weight-bold"><?= htmlspecialchars($c['field_name']) ?></td>
                              <td class="small"><span class="mw-field-change-old"><?= htmlspecialchars($c['old_value'] ?? '(empty)') ?></span></td>
                              <td class="small"><span class="mw-field-change-new"><?= htmlspecialchars($c['new_value'] ?? '(empty)') ?></span></td>
                          </tr>
                          <?php endforeach; endif; ?>
                      </tbody>
                  </table>
              </div>

              <?php if ($totalPages > 1): ?>
              <div class="card-footer d-flex justify-content-between align-items-center">
                  <span class="text-muted small">Page <?= $page ?> of <?= $totalPages ?></span>
                  <div>
                      <?php if ($page > 1): ?>
                      <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="btn btn-sm btn-outline-secondary">Prev</a>
                      <?php endif; ?>
                      <?php if ($page < $totalPages): ?>
                      <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="btn btn-sm btn-outline-secondary">Next</a>
                      <?php endif; ?>
                  </div>
              </div>
              <?php endif; ?>
          </div>

<?php include 'includes/appstack_footer.php'; ?>
