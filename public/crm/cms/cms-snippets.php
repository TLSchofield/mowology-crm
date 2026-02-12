<?php
/**
 * CMS Content Snippets Manager
 *
 * Admin page for listing, creating, editing, and deleting content snippets.
 * Snippets are reusable content blocks stored as JSON in the content_snippets table.
 *
 * @package Mowology CRM
 * @subpackage CMS
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/cms-functions.php';
require_once dirname(__DIR__) . '/includes/cms-token-engine.php';

requireLogin();
$user = getCurrentUser();

$pageTitle = 'Content Snippets';
$activePage = 'cms';

// ============================================================================
// HANDLE POST ACTIONS
// ============================================================================

$message = '';
$error = '';
$editSnippet = null;

// Determine current action
$action = $_GET['action'] ?? '';
$editId = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $postAction = $_POST['post_action'] ?? '';
        $db = getDB();

        try {
            if ($postAction === 'save') {
                $snippetId = (int)($_POST['snippet_id'] ?? 0);
                $data = [
                    'snippet_key'  => trim($_POST['snippet_key'] ?? ''),
                    'variant'      => $_POST['variant'] ?? 'default',
                    'block_type'   => $_POST['block_type'] ?? '',
                    'content_json' => trim($_POST['content_json'] ?? '{}'),
                    'notes'        => trim($_POST['notes'] ?? ''),
                    'is_active'    => isset($_POST['is_active']) ? 1 : 0,
                ];

                // Validate JSON
                $decoded = json_decode($data['content_json'], true);
                if ($data['content_json'] !== '' && $decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Invalid JSON in content field: ' . json_last_error_msg());
                }

                cms_saveSnippet($data, $snippetId > 0 ? $snippetId : null);
                $message = $snippetId > 0 ? 'Snippet updated.' : 'Snippet created.';

                // Clear edit mode after save
                $action = '';
                $editId = 0;

            } elseif ($postAction === 'delete') {
                $deleteId = (int)($_POST['snippet_id'] ?? 0);
                if ($deleteId > 0) {
                    cms_deleteSnippet($deleteId);
                    $message = 'Snippet deleted.';
                }

            } elseif ($postAction === 'toggle') {
                $toggleId = (int)($_POST['snippet_id'] ?? 0);
                if ($toggleId > 0) {
                    $stmt = $db->prepare("UPDATE content_snippets SET is_active = NOT is_active, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$toggleId]);
                    $message = 'Snippet status toggled.';
                }
            }

        } catch (Exception $e) {
            $error = $e->getMessage();
            // Preserve edit mode on error
            if ($postAction === 'save') {
                $action = (int)($_POST['snippet_id'] ?? 0) > 0 ? 'edit' : 'new';
                $editId = (int)($_POST['snippet_id'] ?? 0);
            }
        }
    }
}

// ============================================================================
// LOAD DATA
// ============================================================================

$db = getDB();

// Load ALL snippets (including inactive) for admin listing
$stmt = $db->query("
    SELECT id, snippet_key, variant, block_type, content_json, notes, is_active, created_at, updated_at
    FROM content_snippets
    ORDER BY block_type ASC, variant ASC, snippet_key ASC
");
$allSnippets = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Group by block_type
$grouped = [];
foreach ($allSnippets as $snippet) {
    $bt = $snippet['block_type'] ?: '(no type)';
    $grouped[$bt][] = $snippet;
}
ksort($grouped);

// Load snippet for editing
if ($action === 'edit' && $editId > 0) {
    $stmt = $db->prepare("SELECT id, snippet_key, variant, block_type, content_json, notes, is_active FROM content_snippets WHERE id = ?");
    $stmt->execute([$editId]);
    $editSnippet = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editSnippet) {
        $error = 'Snippet not found.';
        $action = '';
    }
}

// For "new" action, build an empty snippet scaffold
if ($action === 'new') {
    $editSnippet = [
        'id'           => 0,
        'snippet_key'  => '',
        'variant'      => 'default',
        'block_type'   => '',
        'content_json' => '{}',
        'notes'        => '',
        'is_active'    => 1,
    ];
}

// If POST failed with validation error, repopulate from POST data
if ($error && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['post_action'] ?? '') === 'save') {
    $editSnippet = [
        'id'           => (int)($_POST['snippet_id'] ?? 0),
        'snippet_key'  => $_POST['snippet_key'] ?? '',
        'variant'      => $_POST['variant'] ?? 'default',
        'block_type'   => $_POST['block_type'] ?? '',
        'content_json' => $_POST['content_json'] ?? '{}',
        'notes'        => $_POST['notes'] ?? '',
        'is_active'    => isset($_POST['is_active']) ? 1 : 0,
    ];
}

$variants = ['default', 'residential', 'strata', 'commercial'];
$blockTypes = ['hero', 'feature_grid', 'testimonials', 'cta', 'faq', 'gallery', 'service_cards', 'rich_text'];

$csrfToken = generateCSRFToken();
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <!-- Header -->
          <div class="d-flex justify-content-between align-items-center mb-4">
              <div>
                  <a href="/crm/cms-pages_appstack.php" class="text-muted small">&larr; Back to CMS</a>
                  <h1 class="h3 mb-0 mt-1">Content Snippets</h1>
              </div>
              <div>
                  <a href="?action=new" class="btn btn-success">
                      <i data-feather="plus" class="mr-1" style="width:16px;height:16px;"></i> New Snippet
                  </a>
              </div>
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

          <?php if ($editSnippet !== null): ?>
          <!-- ================================================================ -->
          <!-- EDIT / CREATE FORM                                               -->
          <!-- ================================================================ -->
          <div class="card mb-4">
              <div class="card-header">
                  <h5 class="mb-0"><?php echo $editSnippet['id'] ? 'Edit Snippet' : 'New Snippet'; ?></h5>
              </div>
              <div class="card-body">
                  <form method="post" action="?">
                      <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                      <input type="hidden" name="post_action" value="save">
                      <input type="hidden" name="snippet_id" value="<?php echo (int)$editSnippet['id']; ?>">

                      <div class="row">
                          <div class="col-md-6">
                              <div class="form-group">
                                  <label for="snippet_key">Snippet Key <span class="text-danger">*</span></label>
                                  <input type="text" class="form-control" id="snippet_key" name="snippet_key"
                                         value="<?php echo h($editSnippet['snippet_key']); ?>" required
                                         pattern="[a-z0-9_\.]+"
                                         placeholder="e.g. hero.residential.v1">
                                  <small class="form-text text-muted">Lowercase letters, numbers, underscores, dots only.</small>
                              </div>
                          </div>
                          <div class="col-md-3">
                              <div class="form-group">
                                  <label for="variant">Variant</label>
                                  <select class="form-control" id="variant" name="variant">
                                      <?php foreach ($variants as $v): ?>
                                          <option value="<?php echo $v; ?>" <?php echo ($editSnippet['variant'] ?? 'default') === $v ? 'selected' : ''; ?>>
                                              <?php echo ucfirst($v); ?>
                                          </option>
                                      <?php endforeach; ?>
                                  </select>
                              </div>
                          </div>
                          <div class="col-md-3">
                              <div class="form-group">
                                  <label for="block_type">Block Type</label>
                                  <select class="form-control" id="block_type" name="block_type">
                                      <option value="">-- Select --</option>
                                      <?php foreach ($blockTypes as $bt): ?>
                                          <option value="<?php echo $bt; ?>" <?php echo ($editSnippet['block_type'] ?? '') === $bt ? 'selected' : ''; ?>>
                                              <?php echo ucwords(str_replace('_', ' ', $bt)); ?>
                                          </option>
                                      <?php endforeach; ?>
                                  </select>
                              </div>
                          </div>
                      </div>

                      <div class="form-group">
                          <label for="content_json">Content JSON</label>
                          <textarea class="form-control" id="content_json" name="content_json" rows="12"
                                    style="font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; font-size: 0.85rem;"
                                    placeholder='{"headline": "...", "items": [...]}'><?php echo h($editSnippet['content_json'] ?? '{}'); ?></textarea>
                          <small class="form-text text-muted">Raw JSON content for this snippet block.</small>
                      </div>

                      <div class="form-group">
                          <label for="notes">Notes</label>
                          <textarea class="form-control" id="notes" name="notes" rows="2"
                                    placeholder="Internal notes about this snippet..."><?php echo h($editSnippet['notes'] ?? ''); ?></textarea>
                      </div>

                      <div class="form-check mb-3">
                          <input type="checkbox" class="form-check-input" id="is_active" name="is_active" value="1"
                                 <?php echo !empty($editSnippet['is_active']) ? 'checked' : ''; ?>>
                          <label class="form-check-label" for="is_active">Active</label>
                      </div>

                      <div class="d-flex">
                          <button type="submit" class="btn btn-success mr-2">
                              <i data-feather="save" class="mr-1" style="width:14px;height:14px;"></i>
                              <?php echo $editSnippet['id'] ? 'Update Snippet' : 'Create Snippet'; ?>
                          </button>
                          <a href="?" class="btn btn-outline-secondary">Cancel</a>
                      </div>
                  </form>
              </div>
          </div>
          <?php endif; ?>

          <!-- ================================================================ -->
          <!-- SNIPPET LIST (grouped by block_type)                             -->
          <!-- ================================================================ -->

          <?php if (empty($allSnippets)): ?>
              <div class="card">
                  <div class="card-body text-center py-5">
                      <p class="text-muted mb-1"><strong>No content snippets yet.</strong></p>
                      <p class="text-muted">Create your first snippet to start building reusable content blocks.</p>
                      <a href="?action=new" class="btn btn-success mt-2">
                          <i data-feather="plus" class="mr-1" style="width:16px;height:16px;"></i> New Snippet
                      </a>
                  </div>
              </div>
          <?php else: ?>
              <p class="text-muted small mb-3"><?php echo count($allSnippets); ?> snippet<?php echo count($allSnippets) !== 1 ? 's' : ''; ?> in <?php echo count($grouped); ?> block type<?php echo count($grouped) !== 1 ? 's' : ''; ?></p>

              <?php foreach ($grouped as $blockType => $snippets): ?>
                  <div class="card mb-3">
                      <div class="card-header d-flex justify-content-between align-items-center" role="button"
                           data-toggle="collapse" data-target="#group-<?php echo h(preg_replace('/[^a-z0-9]/', '-', strtolower($blockType))); ?>"
                           aria-expanded="true">
                          <h5 class="mb-0">
                              <?php echo h(ucwords(str_replace('_', ' ', $blockType))); ?>
                              <span class="badge badge-secondary ml-2"><?php echo count($snippets); ?></span>
                          </h5>
                          <i data-feather="chevron-down" style="width:18px;height:18px;"></i>
                      </div>
                      <div class="collapse show" id="group-<?php echo h(preg_replace('/[^a-z0-9]/', '-', strtolower($blockType))); ?>">
                          <div class="table-responsive">
                              <table class="table table-hover mb-0">
                                  <thead>
                                      <tr>
                                          <th>Snippet Key</th>
                                          <th>Variant</th>
                                          <th>Block Type</th>
                                          <th>Notes</th>
                                          <th>Status</th>
                                          <th class="text-right">Actions</th>
                                      </tr>
                                  </thead>
                                  <tbody>
                                      <?php foreach ($snippets as $snippet): ?>
                                          <tr class="<?php echo !$snippet['is_active'] ? 'table-light' : ''; ?>">
                                              <td>
                                                  <code><?php echo h($snippet['snippet_key']); ?></code>
                                              </td>
                                              <td>
                                                  <span class="badge badge-info"><?php echo h($snippet['variant']); ?></span>
                                              </td>
                                              <td>
                                                  <span class="badge badge-secondary"><?php echo h($snippet['block_type']); ?></span>
                                              </td>
                                              <td class="text-muted small">
                                                  <?php
                                                  $notes = $snippet['notes'] ?? '';
                                                  echo h(mb_strlen($notes) > 60 ? mb_substr($notes, 0, 60) . '...' : $notes);
                                                  ?>
                                              </td>
                                              <td>
                                                  <?php if ($snippet['is_active']): ?>
                                                      <span class="badge badge-success">Active</span>
                                                  <?php else: ?>
                                                      <span class="badge badge-warning">Inactive</span>
                                                  <?php endif; ?>
                                              </td>
                                              <td class="text-right text-nowrap">
                                                  <a href="?action=edit&id=<?php echo (int)$snippet['id']; ?>" class="btn btn-sm btn-outline-primary mr-1" title="Edit">
                                                      <i data-feather="edit-2" style="width:14px;height:14px;"></i> Edit
                                                  </a>

                                                  <form method="post" action="?" class="d-inline">
                                                      <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                                                      <input type="hidden" name="post_action" value="toggle">
                                                      <input type="hidden" name="snippet_id" value="<?php echo (int)$snippet['id']; ?>">
                                                      <button type="submit" class="btn btn-sm btn-outline-secondary mr-1" title="<?php echo $snippet['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                                          <i data-feather="<?php echo $snippet['is_active'] ? 'eye-off' : 'eye'; ?>" style="width:14px;height:14px;"></i>
                                                      </button>
                                                  </form>

                                                  <form method="post" action="?" class="d-inline" onsubmit="return confirm('Delete this snippet? This cannot be undone.');">
                                                      <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                                                      <input type="hidden" name="post_action" value="delete">
                                                      <input type="hidden" name="snippet_id" value="<?php echo (int)$snippet['id']; ?>">
                                                      <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                                          <i data-feather="trash-2" style="width:14px;height:14px;"></i>
                                                      </button>
                                                  </form>
                                              </td>
                                          </tr>
                                      <?php endforeach; ?>
                                  </tbody>
                              </table>
                          </div>
                      </div>
                  </div>
              <?php endforeach; ?>
          <?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
