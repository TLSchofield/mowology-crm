<?php
/**
 * CMS Content Snippets Manager
 *
 * Two-panel layout: rendered block preview (left) + snippet list/editor (right).
 * Prev/next cycling through snippets within the same block type.
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

                $decoded = json_decode($data['content_json'], true);
                if ($data['content_json'] !== '' && $decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Invalid JSON in content field: ' . json_last_error_msg());
                }

                cms_saveSnippet($data, $snippetId > 0 ? $snippetId : null);
                $message = $snippetId > 0 ? 'Snippet updated.' : 'Snippet created.';
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

// Build a flat ordered list for prev/next cycling
$snippetIds = array_column($allSnippets, 'id');

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

// Determine which snippet to preview (selected row or first in list)
$previewId = (int)($_GET['preview'] ?? 0);
if ($previewId <= 0 && $editSnippet && !empty($editSnippet['id'])) {
    $previewId = (int)$editSnippet['id'];
}
if ($previewId <= 0 && !empty($allSnippets)) {
    $previewId = (int)$allSnippets[0]['id'];
}

// Find prev/next within the same block type for cycling
$prevId = 0;
$nextId = 0;
$previewSnippet = null;
if ($previewId > 0) {
    foreach ($allSnippets as $s) {
        if ((int)$s['id'] === $previewId) {
            $previewSnippet = $s;
            break;
        }
    }
    // Find siblings (same block_type)
    if ($previewSnippet) {
        $bt = $previewSnippet['block_type'];
        $siblings = $grouped[$bt] ?? [];
        $siblingIds = array_column($siblings, 'id');
        $pos = array_search($previewId, array_map('intval', $siblingIds));
        if ($pos !== false) {
            if ($pos > 0) $prevId = (int)$siblingIds[$pos - 1];
            if ($pos < count($siblingIds) - 1) $nextId = (int)$siblingIds[$pos + 1];
        }
    }
}

$csrfToken = generateCSRFToken();

// JSON for JS cycling (all snippet IDs grouped by block type)
$jsGroups = [];
foreach ($grouped as $bt => $snippets) {
    $jsGroups[$bt] = array_map(function ($s) {
        return [
            'id'   => (int)$s['id'],
            'key'  => $s['snippet_key'],
            'variant' => $s['variant'],
            'active'  => (bool)$s['is_active'],
        ];
    }, $snippets);
}
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <!-- CMS Sub-nav -->
          <ul class="nav nav-pills mb-3">
              <li class="nav-item"><a class="nav-link" href="/crm/cms-pages_appstack.php"><i data-feather="file-text" class="mr-1" style="width:14px;height:14px;"></i> Pages</a></li>
              <li class="nav-item"><a class="nav-link active" href="/crm/cms/cms-snippets.php"><i data-feather="copy" class="mr-1" style="width:14px;height:14px;"></i> Snippets</a></li>
              <li class="nav-item"><a class="nav-link" href="/crm/cms/cms-tokens.php"><i data-feather="hash" class="mr-1" style="width:14px;height:14px;"></i> Tokens</a></li>
              <li class="nav-item"><a class="nav-link" href="/crm/cms/cms-generator-manager.php"><i data-feather="layers" class="mr-1" style="width:14px;height:14px;"></i> Templates</a></li>
          </ul>

          <!-- Header -->
          <div class="d-flex justify-content-between align-items-center mb-3">
              <h1 class="h3 mb-0">Content Snippets</h1>
              <a href="?action=new" class="btn btn-success btn-sm">
                  <i data-feather="plus" class="mr-1" style="width:14px;height:14px;"></i> New Snippet
              </a>
          </div>

          <?php if ($message): ?>
              <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
                  <?php echo h($message); ?>
                  <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
              </div>
          <?php endif; ?>
          <?php if ($error): ?>
              <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
                  <?php echo h($error); ?>
                  <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
              </div>
          <?php endif; ?>

          <!-- ================================================================ -->
          <!-- TWO-PANEL LAYOUT: Preview (left) + List/Editor (right)          -->
          <!-- ================================================================ -->
          <div class="row">

              <!-- LEFT: Block Preview -->
              <div class="col-lg-6 mb-4">
                  <div class="card mw-snippet-preview-card" style="position: sticky; top: 80px;">
                      <div class="card-header d-flex justify-content-between align-items-center py-2">
                          <div class="d-flex align-items-center">
                              <i data-feather="eye" class="mr-2 text-muted" style="width:16px;height:16px;"></i>
                              <span class="font-weight-bold small" id="previewLabel">
                                  <?php if ($previewSnippet): ?>
                                      <?php echo h($previewSnippet['snippet_key']); ?>
                                  <?php else: ?>
                                      Block Preview
                                  <?php endif; ?>
                              </span>
                              <?php if ($previewSnippet): ?>
                                  <span class="badge badge-info ml-2" id="previewVariant"><?php echo h($previewSnippet['variant']); ?></span>
                                  <span class="badge badge-secondary ml-1" id="previewType"><?php echo h($previewSnippet['block_type']); ?></span>
                              <?php endif; ?>
                          </div>
                          <!-- Cycle controls -->
                          <div class="d-flex align-items-center" id="cycleControls">
                              <button class="btn btn-sm btn-outline-secondary mr-1 mw-cycle-btn" id="prevBtn" data-dir="prev"
                                      <?php echo $prevId <= 0 ? 'disabled' : ''; ?>
                                      title="Previous snippet">
                                  <i data-feather="chevron-left" style="width:14px;height:14px;"></i>
                              </button>
                              <span class="text-muted small mx-1" id="cycleCounter">
                                  <?php
                                  if ($previewSnippet) {
                                      $bt = $previewSnippet['block_type'];
                                      $siblings = $grouped[$bt] ?? [];
                                      $pos = 0;
                                      foreach ($siblings as $idx => $s) {
                                          if ((int)$s['id'] === $previewId) { $pos = $idx; break; }
                                      }
                                      echo ($pos + 1) . '/' . count($siblings);
                                  }
                                  ?>
                              </span>
                              <button class="btn btn-sm btn-outline-secondary ml-1 mw-cycle-btn" id="nextBtn" data-dir="next"
                                      <?php echo $nextId <= 0 ? 'disabled' : ''; ?>
                                      title="Next snippet">
                                  <i data-feather="chevron-right" style="width:14px;height:14px;"></i>
                              </button>
                          </div>
                      </div>
                      <div class="card-body p-0" id="previewContainer" style="max-height: 600px; overflow-y: auto; background: #fff;">
                          <?php if ($previewId > 0): ?>
                              <div id="previewContent">
                                  <?php
                                  // Server-side initial render
                                  if ($previewSnippet) {
                                      $pConfig = json_decode($previewSnippet['content_json'], true) ?: [];
                                      $tokens = cms_getTokenDefaults();
                                      $config = cms_processBlockConfig($pConfig, $tokens, false);
                                      $rendererPath = dirname(__DIR__) . '/includes/blocks/' . $previewSnippet['block_type'] . '.php';
                                      if (file_exists($rendererPath)) {
                                          include $rendererPath;
                                      } else {
                                          echo '<div class="p-4 text-muted text-center">No renderer for: <strong>' . h($previewSnippet['block_type']) . '</strong></div>';
                                      }
                                  }
                                  ?>
                              </div>
                          <?php else: ?>
                              <div class="p-5 text-center text-muted" id="previewContent">
                                  <i data-feather="image" style="width:48px;height:48px;" class="mb-3 d-block mx-auto text-muted"></i>
                                  <p>Select a snippet to preview its rendered block.</p>
                              </div>
                          <?php endif; ?>
                      </div>
                  </div>
              </div>

              <!-- RIGHT: Snippet List + Editor -->
              <div class="col-lg-6">

                  <?php if ($editSnippet !== null): ?>
                  <!-- EDIT / CREATE FORM -->
                  <div class="card mb-3">
                      <div class="card-header py-2">
                          <h5 class="mb-0 small font-weight-bold"><?php echo $editSnippet['id'] ? 'Edit Snippet' : 'New Snippet'; ?></h5>
                      </div>
                      <div class="card-body py-3">
                          <form method="post" action="?">
                              <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                              <input type="hidden" name="post_action" value="save">
                              <input type="hidden" name="snippet_id" value="<?php echo (int)$editSnippet['id']; ?>">

                              <div class="row">
                                  <div class="col-md-6">
                                      <div class="form-group mb-2">
                                          <label class="small mb-1" for="snippet_key">Snippet Key <span class="text-danger">*</span></label>
                                          <input type="text" class="form-control form-control-sm" id="snippet_key" name="snippet_key"
                                                 value="<?php echo h($editSnippet['snippet_key']); ?>" required
                                                 pattern="[a-z0-9_\.]+"
                                                 placeholder="e.g. hero.residential.v1">
                                      </div>
                                  </div>
                                  <div class="col-md-3">
                                      <div class="form-group mb-2">
                                          <label class="small mb-1" for="variant">Variant</label>
                                          <select class="form-control form-control-sm" id="variant" name="variant">
                                              <?php foreach ($variants as $v): ?>
                                                  <option value="<?php echo $v; ?>" <?php echo ($editSnippet['variant'] ?? 'default') === $v ? 'selected' : ''; ?>>
                                                      <?php echo ucfirst($v); ?>
                                                  </option>
                                              <?php endforeach; ?>
                                          </select>
                                      </div>
                                  </div>
                                  <div class="col-md-3">
                                      <div class="form-group mb-2">
                                          <label class="small mb-1" for="block_type">Block Type</label>
                                          <select class="form-control form-control-sm" id="block_type" name="block_type">
                                              <option value="">--</option>
                                              <?php foreach ($blockTypes as $bt): ?>
                                                  <option value="<?php echo $bt; ?>" <?php echo ($editSnippet['block_type'] ?? '') === $bt ? 'selected' : ''; ?>>
                                                      <?php echo ucwords(str_replace('_', ' ', $bt)); ?>
                                                  </option>
                                              <?php endforeach; ?>
                                          </select>
                                      </div>
                                  </div>
                              </div>

                              <div class="form-group mb-2">
                                  <label class="small mb-1" for="content_json">Content JSON</label>
                                  <textarea class="form-control form-control-sm" id="content_json" name="content_json" rows="10"
                                            style="font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; font-size: 0.8rem;"
                                            placeholder='{"headline": "...", "items": [...]}'><?php echo h($editSnippet['content_json'] ?? '{}'); ?></textarea>
                              </div>

                              <div class="form-group mb-2">
                                  <label class="small mb-1" for="notes">Notes</label>
                                  <textarea class="form-control form-control-sm" id="notes" name="notes" rows="1"
                                            placeholder="Internal notes..."><?php echo h($editSnippet['notes'] ?? ''); ?></textarea>
                              </div>

                              <div class="d-flex align-items-center">
                                  <div class="form-check mr-3">
                                      <input type="checkbox" class="form-check-input" id="is_active" name="is_active" value="1"
                                             <?php echo !empty($editSnippet['is_active']) ? 'checked' : ''; ?>>
                                      <label class="form-check-label small" for="is_active">Active</label>
                                  </div>
                                  <button type="submit" class="btn btn-success btn-sm mr-2">
                                      <i data-feather="save" class="mr-1" style="width:12px;height:12px;"></i>
                                      <?php echo $editSnippet['id'] ? 'Update' : 'Create'; ?>
                                  </button>
                                  <a href="?" class="btn btn-outline-secondary btn-sm">Cancel</a>
                              </div>
                          </form>
                      </div>
                  </div>
                  <?php endif; ?>

                  <!-- SNIPPET LIST (grouped by block_type) -->
                  <?php if (empty($allSnippets)): ?>
                      <div class="card">
                          <div class="card-body text-center py-5">
                              <p class="text-muted mb-1"><strong>No content snippets yet.</strong></p>
                              <a href="?action=new" class="btn btn-success btn-sm mt-2">
                                  <i data-feather="plus" class="mr-1" style="width:14px;height:14px;"></i> New Snippet
                              </a>
                          </div>
                      </div>
                  <?php else: ?>
                      <p class="text-muted small mb-2"><?php echo count($allSnippets); ?> snippet<?php echo count($allSnippets) !== 1 ? 's' : ''; ?> in <?php echo count($grouped); ?> block type<?php echo count($grouped) !== 1 ? 's' : ''; ?></p>

                      <?php foreach ($grouped as $blockType => $snippets): ?>
                          <?php $groupSlug = preg_replace('/[^a-z0-9]/', '-', strtolower($blockType)); ?>
                          <div class="card mb-2 mw-snippet-group">
                              <div class="card-header d-flex justify-content-between align-items-center py-2" role="button"
                                   data-toggle="collapse" data-target="#group-<?php echo h($groupSlug); ?>"
                                   aria-expanded="true">
                                  <h6 class="mb-0">
                                      <?php echo h(ucwords(str_replace('_', ' ', $blockType))); ?>
                                      <span class="badge badge-secondary ml-1"><?php echo count($snippets); ?></span>
                                  </h6>
                                  <i data-feather="chevron-down" style="width:16px;height:16px;"></i>
                              </div>
                              <div class="collapse show" id="group-<?php echo h($groupSlug); ?>">
                                  <div class="list-group list-group-flush">
                                      <?php foreach ($snippets as $snippet):
                                          $sid = (int)$snippet['id'];
                                          $isSelected = ($sid === $previewId);
                                      ?>
                                          <div class="list-group-item list-group-item-action py-2 px-3 d-flex align-items-center mw-snippet-row <?php echo $isSelected ? 'mw-snippet-active' : ''; ?> <?php echo !$snippet['is_active'] ? 'mw-snippet-inactive' : ''; ?>"
                                               data-snippet-id="<?php echo $sid; ?>"
                                               data-block-type="<?php echo h($snippet['block_type']); ?>">

                                              <!-- Preview button -->
                                              <button class="btn btn-sm mr-2 mw-preview-btn <?php echo $isSelected ? 'btn-success' : 'btn-outline-secondary'; ?>"
                                                      data-id="<?php echo $sid; ?>"
                                                      title="Preview this snippet"
                                                      style="min-width: 28px; padding: 2px 5px;">
                                                  <i data-feather="eye" style="width:12px;height:12px;"></i>
                                              </button>

                                              <!-- Snippet info -->
                                              <div class="flex-grow-1 min-width-0">
                                                  <div class="d-flex align-items-center">
                                                      <code class="small mr-2" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 200px;">
                                                          <?php echo h($snippet['snippet_key']); ?>
                                                      </code>
                                                      <span class="badge badge-info" style="font-size: 0.65rem;"><?php echo h($snippet['variant']); ?></span>
                                                      <?php if (!$snippet['is_active']): ?>
                                                          <span class="badge badge-warning ml-1" style="font-size: 0.65rem;">Off</span>
                                                      <?php endif; ?>
                                                  </div>
                                                  <?php if ($snippet['notes']): ?>
                                                      <div class="text-muted" style="font-size: 0.7rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                          <?php echo h(mb_substr($snippet['notes'], 0, 60)); ?>
                                                      </div>
                                                  <?php endif; ?>
                                              </div>

                                              <!-- Actions -->
                                              <div class="ml-2 text-nowrap">
                                                  <a href="?action=edit&id=<?php echo $sid; ?>&preview=<?php echo $sid; ?>" class="btn btn-sm btn-outline-primary py-0 px-1" title="Edit">
                                                      <i data-feather="edit-2" style="width:12px;height:12px;"></i>
                                                  </a>
                                                  <form method="post" action="?preview=<?php echo $previewId; ?>" class="d-inline">
                                                      <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                                                      <input type="hidden" name="post_action" value="toggle">
                                                      <input type="hidden" name="snippet_id" value="<?php echo $sid; ?>">
                                                      <button type="submit" class="btn btn-sm btn-outline-secondary py-0 px-1" title="<?php echo $snippet['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                                          <i data-feather="<?php echo $snippet['is_active'] ? 'eye-off' : 'eye'; ?>" style="width:12px;height:12px;"></i>
                                                      </button>
                                                  </form>
                                                  <form method="post" action="?preview=<?php echo $previewId; ?>" class="d-inline" onsubmit="return confirm('Delete this snippet?');">
                                                      <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                                                      <input type="hidden" name="post_action" value="delete">
                                                      <input type="hidden" name="snippet_id" value="<?php echo $sid; ?>">
                                                      <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1" title="Delete">
                                                          <i data-feather="trash-2" style="width:12px;height:12px;"></i>
                                                      </button>
                                                  </form>
                                              </div>
                                          </div>
                                      <?php endforeach; ?>
                                  </div>
                              </div>
                          </div>
                      <?php endforeach; ?>
                  <?php endif; ?>

              </div><!-- /col-lg-6 right -->
          </div><!-- /row -->

          <!-- ================================================================ -->
          <!-- JavaScript: AJAX preview loading + keyboard cycling             -->
          <!-- ================================================================ -->
          <script>
          (function() {
              var groups = <?php echo json_encode($jsGroups, JSON_UNESCAPED_UNICODE); ?>;
              var currentId = <?php echo $previewId; ?>;
              var currentType = <?php echo json_encode($previewSnippet ? $previewSnippet['block_type'] : ''); ?>;

              // Find position in group
              function findPos(type, id) {
                  var g = groups[type] || [];
                  for (var i = 0; i < g.length; i++) {
                      if (g[i].id === id) return i;
                  }
                  return -1;
              }

              // Load preview via AJAX
              function loadPreview(id) {
                  var container = document.getElementById('previewContent');
                  if (!container) return;
                  container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-success" role="status"></div></div>';

                  fetch('/crm/cms/snippet-preview.php?id=' + id)
                      .then(function(r) { return r.text(); })
                      .then(function(html) {
                          container.innerHTML = html;
                          // Re-init feather icons inside the preview
                          if (window.feather) feather.replace();
                      })
                      .catch(function(err) {
                          container.innerHTML = '<div class="p-4 text-danger text-center">Preview failed: ' + err.message + '</div>';
                      });
              }

              // Update header info
              function updateHeader(snippet, type) {
                  var label = document.getElementById('previewLabel');
                  var variant = document.getElementById('previewVariant');
                  var typeEl = document.getElementById('previewType');
                  if (label) label.textContent = snippet.key;
                  if (variant) { variant.textContent = snippet.variant; variant.style.display = ''; }
                  if (typeEl) { typeEl.textContent = type; typeEl.style.display = ''; }
              }

              // Update cycle counter + buttons
              function updateCycle(type, id) {
                  var g = groups[type] || [];
                  var pos = findPos(type, id);
                  var counter = document.getElementById('cycleCounter');
                  var prevBtn = document.getElementById('prevBtn');
                  var nextBtn = document.getElementById('nextBtn');
                  if (counter) counter.textContent = (pos + 1) + '/' + g.length;
                  if (prevBtn) prevBtn.disabled = (pos <= 0);
                  if (nextBtn) nextBtn.disabled = (pos < 0 || pos >= g.length - 1);
              }

              // Highlight active row
              function highlightRow(id) {
                  document.querySelectorAll('.mw-snippet-row').forEach(function(row) {
                      row.classList.remove('mw-snippet-active');
                      var btn = row.querySelector('.mw-preview-btn');
                      if (btn) { btn.classList.remove('btn-success'); btn.classList.add('btn-outline-secondary'); }
                  });
                  var active = document.querySelector('.mw-snippet-row[data-snippet-id="' + id + '"]');
                  if (active) {
                      active.classList.add('mw-snippet-active');
                      var btn = active.querySelector('.mw-preview-btn');
                      if (btn) { btn.classList.remove('btn-outline-secondary'); btn.classList.add('btn-success'); }
                      // Scroll into view if needed
                      active.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                  }
              }

              // Navigate to snippet
              function goTo(id) {
                  // Find snippet info
                  var snippet = null;
                  var type = '';
                  for (var t in groups) {
                      for (var i = 0; i < groups[t].length; i++) {
                          if (groups[t][i].id === id) { snippet = groups[t][i]; type = t; break; }
                      }
                      if (snippet) break;
                  }
                  if (!snippet) return;

                  currentId = id;
                  currentType = type;
                  loadPreview(id);
                  updateHeader(snippet, type);
                  updateCycle(type, id);
                  highlightRow(id);
              }

              // Cycle prev/next
              function cycle(dir) {
                  var g = groups[currentType] || [];
                  var pos = findPos(currentType, currentId);
                  if (pos < 0) return;
                  var newPos = dir === 'prev' ? pos - 1 : pos + 1;
                  if (newPos < 0 || newPos >= g.length) return;
                  goTo(g[newPos].id);
              }

              // Event: prev/next buttons
              document.getElementById('prevBtn').addEventListener('click', function(e) { e.preventDefault(); cycle('prev'); });
              document.getElementById('nextBtn').addEventListener('click', function(e) { e.preventDefault(); cycle('next'); });

              // Event: preview buttons in list
              document.querySelectorAll('.mw-preview-btn').forEach(function(btn) {
                  btn.addEventListener('click', function(e) {
                      e.preventDefault();
                      e.stopPropagation();
                      goTo(parseInt(this.dataset.id, 10));
                  });
              });

              // Event: click entire row to preview
              document.querySelectorAll('.mw-snippet-row').forEach(function(row) {
                  row.addEventListener('click', function(e) {
                      // Don't trigger on action buttons
                      if (e.target.closest('a') || e.target.closest('form') || e.target.closest('.mw-preview-btn')) return;
                      goTo(parseInt(this.dataset.snippetId, 10));
                  });
              });

              // Keyboard shortcuts: left/right arrows for cycling
              document.addEventListener('keydown', function(e) {
                  // Don't intercept if user is typing in a form field
                  var tag = (e.target.tagName || '').toLowerCase();
                  if (tag === 'input' || tag === 'textarea' || tag === 'select') return;

                  if (e.key === 'ArrowLeft') { e.preventDefault(); cycle('prev'); }
                  if (e.key === 'ArrowRight') { e.preventDefault(); cycle('next'); }
              });
          })();
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
