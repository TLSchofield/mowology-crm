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
          </ul>

          <div class="d-flex justify-content-between align-items-center mb-4">
              <h1 class="h3 mb-0">CMS Pages</h1>
              <div>
                  <a href="/crm/cms/cms-page-edit.php" class="btn btn-primary">
                      <i data-feather="plus" class="mr-1"></i> New Page
                  </a>
                  <a href="/crm/cms/cms-page-generator-wizard.php" class="btn btn-outline-secondary ml-2">
                      <i data-feather="zap" class="mr-1"></i> Generate
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
                      <div class="table-responsive">
                          <table class="table table-hover mb-0">
                              <thead>
                                  <tr>
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

<?php include 'includes/appstack_footer.php'; ?>
