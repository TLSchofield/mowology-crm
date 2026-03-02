<?php
/**
 * CMS Redirect Manager — View, create, edit, delete 301/302 rules (P2-C)
 *
 * @package Mowology CRM
 * @subpackage CMS
 */

declare(strict_types=1);

require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/cms-functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('marketing.edit');

$pageTitle  = 'CMS Redirects';
$activePage = 'cms';

$message = '';
$error   = '';

$csrfToken = generateCSRFToken();

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $db     = getDB();

        try {
            switch ($action) {

                case 'create':
                    $from = trim($_POST['from_slug'] ?? '');
                    $to   = trim($_POST['to_slug'] ?? '');
                    $code = (int)($_POST['status_code'] ?? 301);
                    if (!$from || !$to) throw new \RuntimeException('Both From and To slugs are required.');
                    if (!in_array($code, [301, 302], true)) $code = 301;
                    $from = ltrim($from, '/');
                    $to   = ltrim($to, '/');
                    $db->prepare("
                        INSERT INTO cms_page_redirects (from_slug, to_slug, status_code, is_active, created_at)
                        VALUES (?, ?, ?, 1, NOW())
                        ON DUPLICATE KEY UPDATE to_slug = ?, status_code = ?, is_active = 1
                    ")->execute([$from, $to, $code, $to, $code]);
                    $message = "Redirect /{$from} → /{$to} saved.";
                    break;

                case 'toggle':
                    $id = (int)($_POST['redirect_id'] ?? 0);
                    $db->prepare("
                        UPDATE cms_page_redirects SET is_active = NOT is_active WHERE id = ?
                    ")->execute([$id]);
                    $message = 'Redirect toggled.';
                    break;

                case 'delete':
                    $id = (int)($_POST['redirect_id'] ?? 0);
                    if (!isAdmin()) throw new \RuntimeException('Only admins can delete redirects.');
                    $db->prepare("DELETE FROM cms_page_redirects WHERE id = ?")->execute([$id]);
                    $message = 'Redirect deleted.';
                    break;

                case 'update':
                    $id   = (int)($_POST['redirect_id'] ?? 0);
                    $from = ltrim(trim($_POST['from_slug'] ?? ''), '/');
                    $to   = ltrim(trim($_POST['to_slug'] ?? ''), '/');
                    $code = (int)($_POST['status_code'] ?? 301);
                    if (!$from || !$to) throw new \RuntimeException('Both slugs are required.');
                    $db->prepare("
                        UPDATE cms_page_redirects
                        SET from_slug = ?, to_slug = ?, status_code = ?
                        WHERE id = ?
                    ")->execute([$from, $to, $code, $id]);
                    $message = 'Redirect updated.';
                    break;
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

// ── Load redirects ────────────────────────────────────────────────────────────
$redirects = [];
try {
    $db        = getDB();
    $redirects = $db->query("
        SELECT id, from_slug, to_slug, status_code, is_active, hit_count, created_at
        FROM cms_page_redirects
        ORDER BY created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $error = 'Could not load redirects: ' . $e->getMessage();
}

$totalHits   = array_sum(array_column($redirects, 'hit_count'));
$activeCount = count(array_filter($redirects, fn($r) => $r['is_active']));
?>
<?php include 'includes/appstack_head.php'; ?>

          <!-- CMS Sub-Navigation -->
          <ul class="nav nav-pills mb-3">
              <li class="nav-item"><a class="nav-link" href="/crm/cms-pages_appstack.php"><i data-feather="file-text" class="mr-1" style="width:14px;height:14px;"></i> Pages</a></li>
              <li class="nav-item"><a class="nav-link" href="/crm/cms/cms-snippets.php"><i data-feather="copy" class="mr-1" style="width:14px;height:14px;"></i> Snippets</a></li>
              <li class="nav-item"><a class="nav-link" href="/crm/cms/cms-tokens.php"><i data-feather="hash" class="mr-1" style="width:14px;height:14px;"></i> Tokens</a></li>
              <li class="nav-item"><a class="nav-link" href="/crm/cms/cms-generator-manager.php"><i data-feather="layers" class="mr-1" style="width:14px;height:14px;"></i> Templates</a></li>
              <li class="nav-item"><a class="nav-link active" href="/crm/cms-redirects_appstack.php"><i data-feather="corner-right-down" class="mr-1" style="width:14px;height:14px;"></i> Redirects</a></li>
              <?php if (isAdmin()): ?>
              <li class="nav-item"><a class="nav-link" href="/crm/cms-activity_appstack.php"><i data-feather="activity" class="mr-1" style="width:14px;height:14px;"></i> Activity Log</a></li>
              <?php endif; ?>
          </ul>

          <div class="d-flex justify-content-between align-items-center mb-4">
              <h1 class="h3 mb-0">Redirect Rules
                  <small class="text-muted" style="font-size:0.75rem; font-weight:400; display:block; margin-top:2px;">
                      301/302 redirects — auto-registered when page slugs change
                  </small>
              </h1>
              <button class="btn btn-primary" data-toggle="modal" data-target="#newRedirectModal">
                  <i data-feather="plus" class="mr-1" style="width:14px;height:14px;"></i> Add Redirect
              </button>
          </div>

          <?php if ($message): ?>
              <div class="alert alert-success alert-dismissible fade show" role="alert">
                  <?php echo h($message); ?>
                  <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
              </div>
          <?php endif; ?>
          <?php if ($error): ?>
              <div class="alert alert-danger alert-dismissible fade show" role="alert">
                  <?php echo h($error); ?>
                  <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
              </div>
          <?php endif; ?>

          <!-- Stats strip -->
          <div class="row mb-4">
              <div class="col-md-3">
                  <div class="card text-center py-3">
                      <div class="h2 mb-0 text-primary"><?php echo count($redirects); ?></div>
                      <div class="small text-muted">Total Rules</div>
                  </div>
              </div>
              <div class="col-md-3">
                  <div class="card text-center py-3">
                      <div class="h2 mb-0 text-success"><?php echo $activeCount; ?></div>
                      <div class="small text-muted">Active</div>
                  </div>
              </div>
              <div class="col-md-3">
                  <div class="card text-center py-3">
                      <div class="h2 mb-0 text-warning"><?php echo count($redirects) - $activeCount; ?></div>
                      <div class="small text-muted">Inactive</div>
                  </div>
              </div>
              <div class="col-md-3">
                  <div class="card text-center py-3">
                      <div class="h2 mb-0"><?php echo number_format($totalHits); ?></div>
                      <div class="small text-muted">Total Hits</div>
                  </div>
              </div>
          </div>

          <!-- Redirect Rules Table -->
          <div class="card">
              <div class="card-body p-0">
                  <?php if (empty($redirects)): ?>
                      <div class="text-center py-5 text-muted">
                          <i data-feather="corner-right-down" style="width:36px;height:36px;" class="mb-3 d-block mx-auto"></i>
                          <p class="mb-1"><strong>No redirects yet</strong></p>
                          <p class="small">Redirects are auto-created when you rename a page slug.</p>
                          <button class="btn btn-sm btn-primary mt-2" data-toggle="modal" data-target="#newRedirectModal">Add Manual Redirect</button>
                      </div>
                  <?php else: ?>
                      <div class="table-responsive">
                          <table class="table table-hover mb-0" id="redirectsTable">
                              <thead class="thead-light">
                                  <tr>
                                      <th>From Slug</th>
                                      <th></th>
                                      <th>To Slug</th>
                                      <th style="width:80px;">Code</th>
                                      <th style="width:70px;">Status</th>
                                      <th style="width:70px;">Hits</th>
                                      <th style="width:100px;">Created</th>
                                      <th class="text-right" style="width:130px;">Actions</th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php foreach ($redirects as $r): ?>
                                  <tr class="<?php echo $r['is_active'] ? '' : 'text-muted'; ?>">
                                      <td>
                                          <code class="small">/<?php echo h($r['from_slug']); ?></code>
                                      </td>
                                      <td class="text-center text-muted">
                                          <i data-feather="arrow-right" style="width:14px;height:14px;"></i>
                                      </td>
                                      <td>
                                          <code class="small">/<?php echo h($r['to_slug']); ?></code>
                                          <a href="/<?php echo h($r['to_slug']); ?>" target="_blank" class="ml-1" title="Open destination">
                                              <i data-feather="external-link" style="width:12px;height:12px;"></i>
                                          </a>
                                      </td>
                                      <td>
                                          <span class="badge badge-<?php echo $r['status_code'] == 301 ? 'secondary' : 'info'; ?>">
                                              <?php echo (int)$r['status_code']; ?>
                                          </span>
                                      </td>
                                      <td>
                                          <?php if ($r['is_active']): ?>
                                              <span class="badge badge-success">Active</span>
                                          <?php else: ?>
                                              <span class="badge badge-light">Off</span>
                                          <?php endif; ?>
                                      </td>
                                      <td class="small"><?php echo number_format((int)$r['hit_count']); ?></td>
                                      <td class="small text-muted"><?php echo date('M j, Y', strtotime($r['created_at'])); ?></td>
                                      <td class="text-right">
                                          <div class="btn-group btn-group-sm">
                                              <!-- Edit -->
                                              <button type="button" class="btn btn-outline-primary mw-edit-redirect"
                                                      data-id="<?php echo (int)$r['id']; ?>"
                                                      data-from="<?php echo h($r['from_slug']); ?>"
                                                      data-to="<?php echo h($r['to_slug']); ?>"
                                                      data-code="<?php echo (int)$r['status_code']; ?>"
                                                      title="Edit">
                                                  <i data-feather="edit-2" style="width:12px;height:12px;"></i>
                                              </button>
                                              <!-- Toggle -->
                                              <form method="post" class="d-inline">
                                                  <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                                                  <input type="hidden" name="action" value="toggle">
                                                  <input type="hidden" name="redirect_id" value="<?php echo (int)$r['id']; ?>">
                                                  <button type="submit" class="btn btn-outline-<?php echo $r['is_active'] ? 'warning' : 'success'; ?>"
                                                          title="<?php echo $r['is_active'] ? 'Disable' : 'Enable'; ?>">
                                                      <i data-feather="<?php echo $r['is_active'] ? 'eye-off' : 'eye'; ?>" style="width:12px;height:12px;"></i>
                                                  </button>
                                              </form>
                                              <!-- Delete (admin only) -->
                                              <?php if (isAdmin()): ?>
                                              <form method="post" class="d-inline" onsubmit="return confirm('Delete this redirect?')">
                                                  <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                                                  <input type="hidden" name="action" value="delete">
                                                  <input type="hidden" name="redirect_id" value="<?php echo (int)$r['id']; ?>">
                                                  <button type="submit" class="btn btn-outline-danger" title="Delete">
                                                      <i data-feather="trash-2" style="width:12px;height:12px;"></i>
                                                  </button>
                                              </form>
                                              <?php endif; ?>
                                          </div>
                                      </td>
                                  </tr>
                                  <?php endforeach; ?>
                              </tbody>
                          </table>
                      </div>
                  <?php endif; ?>
              </div>
          </div>

<!-- Create Redirect Modal -->
<div class="modal fade" id="newRedirectModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title"><i data-feather="corner-right-down" class="mr-1" style="width:16px;height:16px;"></i> Add Redirect</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>From Slug <small class="text-muted">(old URL, without leading /)</small></label>
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text">/</span></div>
                            <input type="text" class="form-control" name="from_slug" placeholder="services/old-lawn-care" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>To Slug <small class="text-muted">(destination, without leading /)</small></label>
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text">/</span></div>
                            <input type="text" class="form-control" name="to_slug" placeholder="services/residential-lawn-care" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Redirect Type</label>
                        <select class="form-control" name="status_code">
                            <option value="301">301 — Permanent (SEO-safe, passes link equity)</option>
                            <option value="302">302 — Temporary</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Redirect</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Redirect Modal -->
<div class="modal fade" id="editRedirectModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="redirect_id" id="editRedirectId">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Redirect</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>From Slug</label>
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text">/</span></div>
                            <input type="text" class="form-control" name="from_slug" id="editFromSlug" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>To Slug</label>
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text">/</span></div>
                            <input type="text" class="form-control" name="to_slug" id="editToSlug" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Redirect Type</label>
                        <select class="form-control" name="status_code" id="editStatusCode">
                            <option value="301">301 — Permanent</option>
                            <option value="302">302 — Temporary</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Redirect</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Wire up edit buttons to populate + open edit modal
document.querySelectorAll('.mw-edit-redirect').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('editRedirectId').value  = this.dataset.id;
        document.getElementById('editFromSlug').value    = this.dataset.from;
        document.getElementById('editToSlug').value      = this.dataset.to;
        document.getElementById('editStatusCode').value  = this.dataset.code;
        $('#editRedirectModal').modal('show');
    });
});

// Live filter table
(function() {
    var inp = document.createElement('input');
    inp.type = 'text';
    inp.className = 'form-control form-control-sm mb-3';
    inp.placeholder = 'Filter redirects…';
    inp.style.maxWidth = '320px';
    var card = document.querySelector('.card .card-body, .card');
    if (card) card.parentNode.insertBefore(inp, card);

    inp.addEventListener('input', function() {
        var q = this.value.toLowerCase();
        document.querySelectorAll('#redirectsTable tbody tr').forEach(function(row) {
            row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    });
})();
</script>

<?php include 'includes/appstack_footer.php'; ?>
