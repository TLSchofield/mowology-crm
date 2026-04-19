<?php
/**
 * CMS Pages Manager — List, create, publish, archive pages
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

$pageTitle = 'CMS Pages';
$activePage = 'cms';

// Handle status filter
$statusFilter = $_GET['status'] ?? 'all';
$validStatuses = ['all', 'published', 'draft', 'archived'];
if (!in_array($statusFilter, $validStatuses)) {
    $statusFilter = 'all';
}

// Handle quick actions (publish/unpublish/archive/delete)
$actionMessage = '';
$actionError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    $targetPageId = (int)($_POST['page_id'] ?? 0);

    if ($targetPageId > 0) {
        try {
            $db = getDB();
            switch ($action) {
                case 'publish':
                    $db->prepare("UPDATE cms_pages SET status = 'published', published_by = ?, published_at = NOW() WHERE id = ?")
                       ->execute([$user['id'], $targetPageId]);
                    $actionMessage = 'Page published successfully.';
                    break;

                case 'unpublish':
                    $db->prepare("UPDATE cms_pages SET status = 'draft' WHERE id = ?")
                       ->execute([$targetPageId]);
                    $actionMessage = 'Page set to draft.';
                    break;

                case 'archive':
                    cms_deletePage($targetPageId, false);
                    $actionMessage = 'Page archived.';
                    break;

                case 'delete':
                    if (isAdmin()) {
                        cms_deletePage($targetPageId, true);
                        $actionMessage = 'Page permanently deleted.';
                    } else {
                        $actionError = 'Only admins can permanently delete pages.';
                    }
                    break;
            }
        } catch (Exception $e) {
            $actionError = 'Error: ' . $e->getMessage();
        }
    }
}

// Load all pages
$allPages = cms_getAllPages();

// Filter by status
if ($statusFilter !== 'all') {
    $allPages = array_filter($allPages, fn($p) => $p['status'] === $statusFilter);
}

// Count by status for tabs
$counts = ['all' => 0, 'published' => 0, 'draft' => 0, 'archived' => 0];
foreach (cms_getAllPages() as $p) {
    $counts['all']++;
    $counts[$p['status']] = ($counts[$p['status']] ?? 0) + 1;
}

$csrfToken = generateCSRFToken();
?>
<?php include 'includes/appstack_head.php'; ?>

          <!-- CMS Sub-Navigation -->
          <ul class="nav nav-pills mb-3">
              <li class="nav-item"><a class="nav-link active" href="/crm/cms-pages_appstack.php"><i data-feather="file-text" class="mr-1" style="width:14px;height:14px;"></i> Pages</a></li>
              <li class="nav-item"><a class="nav-link" href="/crm/cms/cms-snippets.php"><i data-feather="copy" class="mr-1" style="width:14px;height:14px;"></i> Snippets</a></li>
              <li class="nav-item"><a class="nav-link" href="/crm/cms/cms-tokens.php"><i data-feather="hash" class="mr-1" style="width:14px;height:14px;"></i> Tokens</a></li>
              <li class="nav-item"><a class="nav-link" href="/crm/cms/cms-generator-manager.php"><i data-feather="layers" class="mr-1" style="width:14px;height:14px;"></i> Templates</a></li>
              <li class="nav-item"><a class="nav-link" href="/crm/cms-redirects_appstack.php"><i data-feather="corner-right-down" class="mr-1" style="width:14px;height:14px;"></i> Redirects</a></li>
              <li class="nav-item"><a class="nav-link" href="/crm/cms-form-submissions_appstack.php"><i data-feather="inbox" class="mr-1" style="width:14px;height:14px;"></i> Form Submissions</a></li>
              <?php if (isAdmin()): ?>
              <li class="nav-item"><a class="nav-link" href="/crm/cms-activity_appstack.php"><i data-feather="activity" class="mr-1" style="width:14px;height:14px;"></i> Activity Log</a></li>
              <?php endif; ?>
          </ul>

          <div class="mw-page-header">
              <div class="mw-page-header-left">
                  <h1 class="mw-page-title">CMS Pages</h1>
              </div>
              <div class="mw-page-actions">
                  <a href="/crm/cms/cms-page-edit.php" class="btn btn-primary">
                      <i data-feather="plus"></i> New Page
                  </a>
                  <a href="/crm/cms/cms-page-generator-wizard.php" class="btn btn-outline-secondary">
                      <i data-feather="zap"></i> Generate
                  </a>
              </div>
          </div>

          <?php if ($actionMessage): ?>
              <div class="alert alert-success alert-dismissible fade show" role="alert">
                  <?php echo h($actionMessage); ?>
                  <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
              </div>
          <?php endif; ?>

          <?php if ($actionError): ?>
              <div class="alert alert-danger alert-dismissible fade show" role="alert">
                  <?php echo h($actionError); ?>
                  <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
              </div>
          <?php endif; ?>

          <!-- Status Filter Tabs -->
          <ul class="nav nav-tabs mb-4">
              <?php foreach ($counts as $status => $count): ?>
                  <li class="nav-item">
                      <a class="nav-link <?php echo $statusFilter === $status ? 'active' : ''; ?>"
                         href="?status=<?php echo $status; ?>">
                          <?php echo ucfirst($status); ?>
                          <span class="badge badge-<?php echo $status === 'published' ? 'success' : ($status === 'draft' ? 'warning' : 'secondary'); ?> ml-1"><?php echo $count; ?></span>
                      </a>
                  </li>
              <?php endforeach; ?>
          </ul>

          <!-- Live Search (P2-H) -->
          <div class="d-flex align-items-center mb-3">
              <div class="input-group" style="max-width:420px;">
                  <div class="input-group-prepend">
                      <span class="input-group-text bg-white"><i data-feather="search" style="width:14px;height:14px;color:#999;"></i></span>
                  </div>
                  <input type="text" id="cms-page-search" class="form-control border-left-0"
                         placeholder="Search pages by title, slug, or type…"
                         autocomplete="off" spellcheck="false">
              </div>
              <span id="cms-search-count" class="ml-3 small text-muted" style="display:none;"></span>
          </div>

          <!-- Pages Table -->
          <div class="card">
              <div class="card-body">
                  <?php if (empty($allPages)): ?>
                      <div class="text-center py-5 text-muted">
                          <p class="mb-1"><strong>No pages found</strong></p>
                          <p>Create your first CMS page to get started.</p>
                          <a href="/crm/cms/cms-page-edit.php" class="btn btn-sm btn-primary mt-2">Create Page</a>
                      </div>
                  <?php else: ?>
                      <!-- Bulk action toolbar (hidden until rows are checked) -->
                      <div id="bulk-toolbar" class="d-none mb-3 p-2 bg-light border rounded d-flex align-items-center">
                          <span id="bulk-count" class="mr-3 text-muted small font-weight-bold"></span>
                          <button type="button" class="btn btn-sm btn-success mr-2" data-bulk-action="publish">Publish</button>
                          <button type="button" class="btn btn-sm btn-warning mr-2" data-bulk-action="unpublish">Unpublish</button>
                          <button type="button" class="btn btn-sm btn-secondary mr-2" data-bulk-action="archive">Archive</button>
                          <button type="button" class="btn btn-sm btn-link text-muted ml-auto" id="bulk-clear">Clear selection</button>
                      </div>

                      <div class="table-responsive">
                          <table class="table table-hover mb-0" id="pages-table">
                              <thead>
                                  <tr>
                                      <th style="width:36px;">
                                          <input type="checkbox" id="select-all" title="Select all">
                                      </th>
                                      <th>Title</th>
                                      <th>Slug</th>
                                      <th>Type</th>
                                      <th>Status</th>
                                      <th>Views</th>
                                      <th>Updated</th>
                                      <th class="text-right">Actions</th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php foreach ($allPages as $pg): ?>
                                      <tr>
                                          <td>
                                              <input type="checkbox" class="page-checkbox" value="<?php echo (int)$pg['id']; ?>">
                                          </td>
                                          <td>
                                              <a href="/crm/cms/cms-page-edit.php?id=<?php echo (int)$pg['id']; ?>" class="font-weight-bold text-dark">
                                                  <?php echo h($pg['title']); ?>
                                              </a>
                                          </td>
                                          <td>
                                              <code class="small">/<?php echo h($pg['slug']); ?></code>
                                              <?php if ($pg['status'] === 'published'): ?>
                                                  <a href="/<?php echo h($pg['slug']); ?>" target="_blank" class="ml-1" title="View live page">
                                                      <i data-feather="external-link" style="width:14px;height:14px;"></i>
                                                  </a>
                                              <?php endif; ?>
                                          </td>
                                          <td><span class="badge badge-light"><?php echo h($pg['page_type'] ?? 'custom'); ?></span></td>
                                          <td>
                                              <?php
                                              $badgeClass = match($pg['status']) {
                                                  'published' => 'success',
                                                  'draft' => 'warning',
                                                  'archived' => 'secondary',
                                                  default => 'light',
                                              };
                                              ?>
                                              <span class="badge badge-<?php echo $badgeClass; ?>"><?php echo ucfirst(h($pg['status'])); ?></span>
                                          </td>
                                          <td><?php echo number_format((int)($pg['view_count'] ?? 0)); ?></td>
                                          <td class="small text-muted"><?php echo $pg['updated_at'] ? date('M j, Y', strtotime($pg['updated_at'])) : '—'; ?></td>
                                          <td class="text-right">
                                              <div class="btn-group btn-group-sm">
                                                  <a href="/crm/cms/cms-page-edit.php?id=<?php echo (int)$pg['id']; ?>" class="btn btn-outline-primary" title="Edit">
                                                      <i data-feather="edit-2" style="width:14px;height:14px;"></i>
                                                  </a>

                                                  <?php if ($pg['status'] === 'draft'): ?>
                                                      <form method="post" class="d-inline">
                                                          <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                                                          <input type="hidden" name="page_id" value="<?php echo (int)$pg['id']; ?>">
                                                          <input type="hidden" name="action" value="publish">
                                                          <button type="submit" class="btn btn-outline-success" title="Publish">
                                                              <i data-feather="check-circle" style="width:14px;height:14px;"></i>
                                                          </button>
                                                      </form>
                                                  <?php elseif ($pg['status'] === 'published'): ?>
                                                      <form method="post" class="d-inline">
                                                          <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                                                          <input type="hidden" name="page_id" value="<?php echo (int)$pg['id']; ?>">
                                                          <input type="hidden" name="action" value="unpublish">
                                                          <button type="submit" class="btn btn-outline-warning" title="Unpublish">
                                                              <i data-feather="eye-off" style="width:14px;height:14px;"></i>
                                                          </button>
                                                      </form>
                                                  <?php endif; ?>

                                                  <?php if ($pg['status'] !== 'archived'): ?>
                                                      <form method="post" class="d-inline" onsubmit="return confirm('Archive this page?')">
                                                          <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                                                          <input type="hidden" name="page_id" value="<?php echo (int)$pg['id']; ?>">
                                                          <input type="hidden" name="action" value="archive">
                                                          <button type="submit" class="btn btn-outline-danger" title="Archive">
                                                              <i data-feather="archive" style="width:14px;height:14px;"></i>
                                                          </button>
                                                      </form>
                                                  <?php endif; ?>

                                                  <button type="button"
                                                          class="btn btn-outline-secondary mw-duplicate-page"
                                                          data-page-id="<?php echo (int)$pg['id']; ?>"
                                                          data-csrf="<?php echo h($csrfToken); ?>"
                                                          title="Duplicate as draft">
                                                      <i data-feather="copy" style="width:14px;height:14px;"></i>
                                                  </button>
                                                  <a href="/crm/api/cms-export-page.php?id=<?php echo (int)$pg['id']; ?>"
                                                     class="btn btn-outline-secondary" title="Export as JSON">
                                                      <i data-feather="download" style="width:14px;height:14px;"></i>
                                                  </a>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    var csrfToken = '<?php echo h($csrfToken); ?>';

    // ---- Bulk selection ----
    var selectAll   = document.getElementById('select-all');
    var bulkToolbar = document.getElementById('bulk-toolbar');
    var bulkCount   = document.getElementById('bulk-count');
    var bulkClear   = document.getElementById('bulk-clear');

    function getCheckedIds() {
        return Array.from(document.querySelectorAll('.page-checkbox:checked')).map(function(cb) { return cb.value; });
    }

    function updateBulkToolbar() {
        var ids = getCheckedIds();
        bulkToolbar.classList.toggle('d-none', ids.length === 0);
        if (ids.length > 0) {
            bulkCount.textContent = ids.length + ' page' + (ids.length !== 1 ? 's' : '') + ' selected';
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.page-checkbox').forEach(function(cb) { cb.checked = selectAll.checked; });
            updateBulkToolbar();
        });
    }

    document.querySelectorAll('.page-checkbox').forEach(function(cb) {
        cb.addEventListener('change', function() {
            var all     = document.querySelectorAll('.page-checkbox').length;
            var checked = document.querySelectorAll('.page-checkbox:checked').length;
            if (selectAll) {
                selectAll.checked       = checked === all;
                selectAll.indeterminate = checked > 0 && checked < all;
            }
            updateBulkToolbar();
        });
    });

    if (bulkClear) {
        bulkClear.addEventListener('click', function() {
            document.querySelectorAll('.page-checkbox').forEach(function(cb) { cb.checked = false; });
            if (selectAll) { selectAll.checked = false; selectAll.indeterminate = false; }
            updateBulkToolbar();
        });
    }

    document.querySelectorAll('[data-bulk-action]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var action = this.dataset.bulkAction;
            var ids    = getCheckedIds();
            if (!ids.length) return;
            if (!confirm('Set ' + ids.length + ' page(s) to "' + action + '"?')) return;

            var fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('action', action);
            ids.forEach(function(id) { fd.append('page_ids[]', id); });

            fetch('/crm/api/bulk-status.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) { location.reload(); }
                    else { alert('Error: ' + (data.error || 'Bulk action failed')); }
                })
                .catch(function(err) { alert('Error: ' + err.message); });
        });
    });

    // ---- Live Search (P2-H) ----
    var searchInput = document.getElementById('cms-page-search');
    var searchCount = document.getElementById('cms-search-count');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            var q = this.value.toLowerCase().trim();
            var rows = document.querySelectorAll('#pages-table tbody tr');
            var visible = 0;
            rows.forEach(function(row) {
                var text = row.textContent.toLowerCase();
                var show = !q || text.includes(q);
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            if (q) {
                searchCount.style.display = '';
                searchCount.textContent = visible + ' result' + (visible !== 1 ? 's' : '');
            } else {
                searchCount.style.display = 'none';
            }
        });
    }

    // ---- Duplicate page buttons ----
    document.querySelectorAll('.mw-duplicate-page').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (!confirm('Duplicate this page as a draft?')) return;
            var pageId = this.dataset.pageId;
            var csrf   = this.dataset.csrf;
            var self   = this;
            self.disabled = true;

            var fd = new FormData();
            fd.append('page_id', pageId);
            fd.append('csrf_token', csrf);

            fetch('/crm/api/duplicate-page.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        window.location.href = data.edit_url;
                    } else {
                        alert('Error: ' + (data.error || 'Duplicate failed'));
                        self.disabled = false;
                    }
                })
                .catch(function(err) {
                    alert('Error: ' + err.message);
                    self.disabled = false;
                });
        });
    });
});
</script>
<?php include 'includes/appstack_footer.php'; ?>
