<?php
/**
 * CMS Page Editor — Edit page settings, manage blocks, set tokens
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

$pageTitle = 'Edit Page';
$activePage = 'cms';

$pageId = (int)($_GET['id'] ?? 0);
$isNew = ($pageId === 0);
$page = null;
$blocks = [];
$pageTokens = [];
$message = '';
$error = '';

// Load existing page if editing
if (!$isNew) {
    $page = cms_getPageById($pageId);
    if (!$page) {
        header('Location: /crm/cms-pages_appstack.php');
        exit;
    }
    $blocks = cms_getBlocksByPageId($pageId, 0); // No cache
    $pageTokens = cms_getPageTokens($pageId);
    $pageTitle = 'Edit: ' . $page['title'];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $submitAction = $_POST['submit_action'] ?? 'save';

        try {
            $db = getDB();

            // Determine status based on action
            $status = 'draft';
            if ($submitAction === 'publish') {
                $status = 'published';
            } elseif (!$isNew && $page) {
                $status = $page['status'];
            }

            // Save page settings
            $pageData = [
                'title'            => trim($_POST['title'] ?? ''),
                'slug'             => trim($_POST['slug'] ?? ''),
                'meta_title'       => trim($_POST['meta_title'] ?? ''),
                'meta_description' => trim($_POST['meta_description'] ?? ''),
                'meta_keywords'    => trim($_POST['meta_keywords'] ?? ''),
                'page_type'        => $_POST['page_type'] ?? 'custom',
                'layout_template'  => $_POST['layout_template'] ?? 'default',
                'status'           => $status,
                'og_image_path'    => trim($_POST['og_image_path'] ?? ''),
                'noindex'          => isset($_POST['noindex']) ? 1 : 0,
            ];

            $pageId = cms_savePage($pageData, $isNew ? null : $pageId, $user['id']);

            // Save blocks
            $submittedBlocks = $_POST['blocks'] ?? [];
            $existingBlockIds = array_column($blocks, 'id');
            $submittedBlockIds = [];

            foreach ($submittedBlocks as $position => $blockData) {
                $blockId = (int)($blockData['id'] ?? 0);
                $blockType = $blockData['block_type'] ?? '';
                $config = $blockData['config'] ?? [];

                // Clean empty strings from config arrays
                array_walk_recursive($config, function (&$val) {
                    $val = is_string($val) ? trim($val) : $val;
                });

                $configJson = json_encode($config, JSON_UNESCAPED_SLASHES);

                if ($blockId > 0) {
                    // Update existing block
                    $db->prepare("
                        UPDATE cms_blocks
                        SET block_type = ?, position = ?, config_json = ?, updated_at = NOW()
                        WHERE id = ? AND page_id = ?
                    ")->execute([$blockType, $position, $configJson, $blockId, $pageId]);
                    $submittedBlockIds[] = $blockId;
                } else {
                    // Create new block
                    $db->prepare("
                        INSERT INTO cms_blocks (page_id, block_type, position, config_json, created_at, updated_at)
                        VALUES (?, ?, ?, ?, NOW(), NOW())
                    ")->execute([$pageId, $blockType, $position, $configJson]);
                    $submittedBlockIds[] = (int)$db->lastInsertId();
                }
            }

            // Delete removed blocks
            $removedIds = array_diff($existingBlockIds, $submittedBlockIds);
            if ($removedIds) {
                $placeholders = implode(',', array_fill(0, count($removedIds), '?'));
                $db->prepare("DELETE FROM cms_blocks WHERE id IN ($placeholders) AND page_id = ?")
                   ->execute(array_merge(array_values($removedIds), [$pageId]));
            }

            // Save page tokens
            $tokenData = [];
            $tokenKeys = $_POST['token_keys'] ?? [];
            $tokenValues = $_POST['token_values'] ?? [];
            foreach ($tokenKeys as $i => $key) {
                $key = trim($key);
                $val = trim($tokenValues[$i] ?? '');
                if ($key && $val) {
                    $tokenData[$key] = $val;
                }
            }
            cms_savePageTokens($pageId, $tokenData);

            // Redirect to edit page with success message
            $msg = ($submitAction === 'publish') ? 'Page published.' : 'Page saved.';
            header("Location: /crm/cms/cms-page-edit.php?id={$pageId}&saved=" . urlencode($msg));
            exit;

        } catch (Exception $e) {
            $error = 'Error saving page: ' . $e->getMessage();
        }
    }
}

// Reload after save (fresh data)
if (!$isNew && $pageId > 0) {
    $page = cms_getPageById($pageId);
    $blocks = cms_getBlocksByPageId($pageId, 0);
    $pageTokens = cms_getPageTokens($pageId);
}

if (!empty($_GET['saved'])) {
    $message = $_GET['saved'];
}

// Load available block types
$blockTypes = cms_getBlockTypes();

// Available page types and layouts
$pageTypes = ['home', 'about', 'services', 'service_landing', 'portfolio', 'contact', 'custom', 'landing'];
$layouts = ['default', 'homepage', 'service_landing', 'contact', 'portfolio'];

$csrfToken = generateCSRFToken();
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <style>
              .block-card { border: 1px solid #ddd; border-radius: 4px; margin-bottom: 1rem; background: #fff; }
              .block-card-header { padding: 0.75rem 1rem; background: #f8f9fa; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; cursor: grab; border-radius: 4px 4px 0 0; }
              .block-card-header .badge { font-size: 0.75rem; }
              .block-card-body { padding: 1rem; display: none; }
              .block-card.open .block-card-body { display: block; }
              .block-actions button { background: none; border: none; cursor: pointer; padding: 0.25rem; color: #666; }
              .block-actions button:hover { color: #333; }
              .token-row { display: flex; gap: 0.5rem; margin-bottom: 0.5rem; align-items: center; }
              .token-row input { flex: 1; }
              .repeater-row { border: 1px solid #eee; padding: 0.75rem; margin-bottom: 0.5rem; border-radius: 4px; background: #fafafa; position: relative; }
              .repeater-remove { position: absolute; top: 0.5rem; right: 0.5rem; background: none; border: none; color: #dc3545; cursor: pointer; font-size: 1.2rem; line-height: 1; }
              .slug-preview { font-size: 0.85rem; color: #666; }
              .char-count { font-size: 0.8rem; color: #999; }
          </style>

          <!-- Header -->
          <div class="d-flex justify-content-between align-items-center mb-4">
              <div>
                  <a href="/crm/cms-pages_appstack.php" class="text-muted small">&larr; Back to Pages</a>
                  <h1 class="h3 mb-0 mt-1"><?php echo $isNew ? 'New Page' : 'Edit: ' . h($page['title']); ?></h1>
              </div>
              <?php if (!$isNew && $page): ?>
                  <div>
                      <?php if ($page['status'] === 'published'): ?>
                          <a href="/<?php echo h($page['slug']); ?>" target="_blank" class="btn btn-outline-secondary btn-sm mr-1">
                              <i data-feather="external-link" class="mr-1" style="width:14px;height:14px;"></i> View Live
                          </a>
                      <?php endif; ?>
                      <a href="/cms-render.php?page=<?php echo h($page['slug']); ?>&preview=1" target="_blank" class="btn btn-outline-primary btn-sm">
                          <i data-feather="eye" class="mr-1" style="width:14px;height:14px;"></i> Preview
                      </a>
                  </div>
              <?php endif; ?>
          </div>

          <?php if ($message): ?>
              <div class="alert alert-success alert-dismissible fade show" role="alert">
                  <?php echo h($message); ?>
                  <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
              </div>
          <?php endif; ?>
          <?php if ($error): ?>
              <div class="alert alert-danger"><?php echo h($error); ?></div>
          <?php endif; ?>

          <form method="post" id="pageForm">
              <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
              <input type="hidden" name="submit_action" id="submitAction" value="save">

              <div class="row">
                  <!-- Main Content -->
                  <div class="col-lg-8">

                      <!-- Page Settings Card -->
                      <div class="card mb-4">
                          <div class="card-header"><h5 class="mb-0">Page Settings</h5></div>
                          <div class="card-body">
                              <div class="form-group">
                                  <label for="title">Page Title *</label>
                                  <input type="text" class="form-control" id="title" name="title"
                                         value="<?php echo h($page['title'] ?? ''); ?>" required
                                         placeholder="e.g. Residential Lawn Care in Kitsilano">
                              </div>
                              <div class="form-group">
                                  <label for="slug">URL Slug *</label>
                                  <input type="text" class="form-control" id="slug" name="slug"
                                         value="<?php echo h($page['slug'] ?? ''); ?>" required
                                         placeholder="e.g. services/residential-lawn-care-kitsilano">
                                  <small class="slug-preview">URL: https://mowology.ca/<span id="slugPreview"><?php echo h($page['slug'] ?? ''); ?></span></small>
                              </div>
                              <div class="row">
                                  <div class="col-md-6">
                                      <div class="form-group">
                                          <label for="page_type">Page Type</label>
                                          <select class="form-control" id="page_type" name="page_type">
                                              <?php foreach ($pageTypes as $pt): ?>
                                                  <option value="<?php echo $pt; ?>" <?php echo ($page['page_type'] ?? 'custom') === $pt ? 'selected' : ''; ?>>
                                                      <?php echo ucwords(str_replace('_', ' ', $pt)); ?>
                                                  </option>
                                              <?php endforeach; ?>
                                          </select>
                                      </div>
                                  </div>
                                  <div class="col-md-6">
                                      <div class="form-group">
                                          <label for="layout_template">Layout Template</label>
                                          <select class="form-control" id="layout_template" name="layout_template">
                                              <?php foreach ($layouts as $lt): ?>
                                                  <option value="<?php echo $lt; ?>" <?php echo ($page['layout_template'] ?? 'default') === $lt ? 'selected' : ''; ?>>
                                                      <?php echo ucwords(str_replace('_', ' ', $lt)); ?>
                                                  </option>
                                              <?php endforeach; ?>
                                          </select>
                                      </div>
                                  </div>
                              </div>
                          </div>
                      </div>

                      <!-- SEO Card -->
                      <div class="card mb-4">
                          <div class="card-header"><h5 class="mb-0">SEO</h5></div>
                          <div class="card-body">
                              <div class="form-group">
                                  <label for="meta_title">Meta Title <small class="char-count">(<span id="metaTitleCount">0</span>/60)</small></label>
                                  <input type="text" class="form-control" id="meta_title" name="meta_title" maxlength="70"
                                         value="<?php echo h($page['meta_title'] ?? ''); ?>"
                                         placeholder="SEO title — aim for 60 chars. Supports {{TOKENS}}">
                              </div>
                              <div class="form-group">
                                  <label for="meta_description">Meta Description <small class="char-count">(<span id="metaDescCount">0</span>/160)</small></label>
                                  <textarea class="form-control" id="meta_description" name="meta_description" rows="2" maxlength="200"
                                            placeholder="SEO description — aim for 160 chars. Supports {{TOKENS}}"><?php echo h($page['meta_description'] ?? ''); ?></textarea>
                              </div>
                              <div class="form-group">
                                  <label for="meta_keywords">Meta Keywords</label>
                                  <input type="text" class="form-control" id="meta_keywords" name="meta_keywords"
                                         value="<?php echo h($page['meta_keywords'] ?? ''); ?>"
                                         placeholder="lawn care, landscaping, vancouver (optional)">
                              </div>
                              <div class="form-group">
                                  <label for="og_image_path">OG Image Path</label>
                                  <input type="text" class="form-control" id="og_image_path" name="og_image_path"
                                         value="<?php echo h($page['og_image_path'] ?? ''); ?>"
                                         placeholder="/assets/img/og-default.jpg">
                              </div>
                              <div class="form-check">
                                  <input type="checkbox" class="form-check-input" id="noindex" name="noindex" value="1"
                                         <?php echo !empty($page['noindex']) ? 'checked' : ''; ?>>
                                  <label class="form-check-label" for="noindex">Add noindex (hide from search engines)</label>
                              </div>
                          </div>
                      </div>

                      <!-- Blocks Manager -->
                      <div class="card mb-4">
                          <div class="card-header d-flex justify-content-between align-items-center">
                              <h5 class="mb-0">Content Blocks</h5>
                              <div class="dropdown">
                                  <button class="btn btn-sm btn-primary dropdown-toggle" type="button" data-toggle="dropdown">
                                      <i data-feather="plus" style="width:14px;height:14px;" class="mr-1"></i> Add Block
                                  </button>
                                  <div class="dropdown-menu dropdown-menu-right">
                                      <?php foreach ($blockTypes as $bt): ?>
                                          <a class="dropdown-item add-block-btn" href="#" data-type="<?php echo h($bt['block_type']); ?>">
                                              <?php echo h($bt['label']); ?>
                                          </a>
                                      <?php endforeach; ?>
                                  </div>
                              </div>
                          </div>
                          <div class="card-body" id="blocksContainer">
                              <?php if (empty($blocks)): ?>
                                  <p class="text-muted text-center py-3" id="noBlocksMsg">No blocks yet. Click "Add Block" to start building your page.</p>
                              <?php endif; ?>

                              <?php foreach ($blocks as $index => $block): ?>
                                  <div class="block-card" data-index="<?php echo $index; ?>">
                                      <div class="block-card-header" onclick="toggleBlock(this)">
                                          <div>
                                              <span class="badge badge-info mr-2"><?php echo h($block['block_type']); ?></span>
                                              <strong class="block-label"><?php echo h($block['config']['headline'] ?? $block['config']['title'] ?? 'Block #' . ($index + 1)); ?></strong>
                                          </div>
                                          <div class="block-actions">
                                              <button type="button" onclick="event.stopPropagation(); moveBlock(this, -1)" title="Move up">&uarr;</button>
                                              <button type="button" onclick="event.stopPropagation(); moveBlock(this, 1)" title="Move down">&darr;</button>
                                              <button type="button" onclick="event.stopPropagation(); removeBlock(this)" title="Remove" style="color: #dc3545;">&times;</button>
                                          </div>
                                      </div>
                                      <div class="block-card-body">
                                          <input type="hidden" name="blocks[<?php echo $index; ?>][id]" value="<?php echo (int)$block['id']; ?>">
                                          <input type="hidden" name="blocks[<?php echo $index; ?>][block_type]" value="<?php echo h($block['block_type']); ?>">
                                          <?php
                                          $blockIndex = $index;
                                          $config = $block['config'] ?? [];
                                          $formFile = __DIR__ . '/block-forms/' . $block['block_type'] . '-form.php';
                                          if (file_exists($formFile)) {
                                              include $formFile;
                                          } else {
                                              echo '<p class="text-muted">No form available for block type: ' . h($block['block_type']) . '</p>';
                                              echo '<div class="form-group"><label>Raw Config JSON</label>';
                                              echo '<textarea class="form-control" name="blocks[' . $index . '][config][_raw]" rows="4">' . h(json_encode($config, JSON_PRETTY_PRINT)) . '</textarea></div>';
                                          }
                                          ?>
                                      </div>
                                  </div>
                              <?php endforeach; ?>
                          </div>
                      </div>

                      <!-- Page Tokens -->
                      <div class="card mb-4">
                          <div class="card-header d-flex justify-content-between align-items-center">
                              <h5 class="mb-0">Page Tokens <small class="text-muted">(overrides for {{TOKEN}} placeholders)</small></h5>
                              <button type="button" class="btn btn-sm btn-outline-primary" onclick="addTokenRow()">
                                  <i data-feather="plus" style="width:14px;height:14px;" class="mr-1"></i> Add Token
                              </button>
                          </div>
                          <div class="card-body" id="tokensContainer">
                              <?php if (empty($pageTokens)): ?>
                                  <p class="text-muted small mb-2" id="noTokensMsg">No token overrides. Page will use global defaults.</p>
                              <?php endif; ?>
                              <?php foreach ($pageTokens as $token => $value): ?>
                                  <div class="token-row">
                                      <input type="text" class="form-control form-control-sm" name="token_keys[]"
                                             value="<?php echo h($token); ?>" placeholder="TOKEN_NAME" style="max-width: 200px;">
                                      <input type="text" class="form-control form-control-sm" name="token_values[]"
                                             value="<?php echo h($value); ?>" placeholder="Value">
                                      <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.token-row').remove()">&times;</button>
                                  </div>
                              <?php endforeach; ?>
                          </div>
                      </div>

                  </div>

                  <!-- Sidebar -->
                  <div class="col-lg-4">
                      <div class="card mb-4">
                          <div class="card-header"><h5 class="mb-0">Publish</h5></div>
                          <div class="card-body">
                              <?php if (!$isNew && $page): ?>
                                  <p class="small text-muted mb-2">
                                      Status: <span class="badge badge-<?php echo $page['status'] === 'published' ? 'success' : 'warning'; ?>"><?php echo ucfirst(h($page['status'])); ?></span><br>
                                      Created: <?php echo date('M j, Y', strtotime($page['created_at'])); ?><br>
                                      <?php if ($page['updated_at']): ?>Updated: <?php echo date('M j, Y g:i A', strtotime($page['updated_at'])); ?><?php endif; ?>
                                  </p>
                              <?php endif; ?>
                              <button type="submit" class="btn btn-block btn-outline-secondary mb-2" onclick="document.getElementById('submitAction').value='save'">
                                  <i data-feather="save" style="width:14px;height:14px;" class="mr-1"></i> Save Draft
                              </button>
                              <button type="submit" class="btn btn-block btn-success" onclick="document.getElementById('submitAction').value='publish'">
                                  <i data-feather="check-circle" style="width:14px;height:14px;" class="mr-1"></i> Publish
                              </button>
                          </div>
                      </div>

                      <!-- Quick Token Reference -->
                      <div class="card">
                          <div class="card-header"><h5 class="mb-0">Available Tokens</h5></div>
                          <div class="card-body small">
                              <p class="text-muted mb-2">Use in any text field:</p>
                              <code>{{BRAND}}</code> <code>{{CITY}}</code> <code>{{AREA}}</code><br>
                              <code>{{SERVICE}}</code> <code>{{PHONE}}</code> <code>{{EMAIL}}</code><br>
                              <code>{{CTA_URL}}</code> <code>{{CTA_TEXT}}</code> <code>{{YEAR}}</code><br>
                              <code>{{PRIMARY_KEYWORD}}</code> <code>{{NEIGHBOURHOOD}}</code><br>
                              <code>{{TAGLINE}}</code> <code>{{TRUST_STATEMENT}}</code>
                          </div>
                      </div>
                  </div>
              </div>
          </form>

          <!-- Block Templates (hidden, cloned by JS) -->
          <?php foreach ($blockTypes as $bt): ?>
              <template id="blockTemplate_<?php echo h($bt['block_type']); ?>">
                  <div class="block-card open" data-index="__INDEX__">
                      <div class="block-card-header" onclick="toggleBlock(this)">
                          <div>
                              <span class="badge badge-info mr-2"><?php echo h($bt['block_type']); ?></span>
                              <strong class="block-label"><?php echo h($bt['label']); ?></strong>
                          </div>
                          <div class="block-actions">
                              <button type="button" onclick="event.stopPropagation(); moveBlock(this, -1)" title="Move up">&uarr;</button>
                              <button type="button" onclick="event.stopPropagation(); moveBlock(this, 1)" title="Move down">&darr;</button>
                              <button type="button" onclick="event.stopPropagation(); removeBlock(this)" title="Remove" style="color: #dc3545;">&times;</button>
                          </div>
                      </div>
                      <div class="block-card-body">
                          <input type="hidden" name="blocks[__INDEX__][id]" value="0">
                          <input type="hidden" name="blocks[__INDEX__][block_type]" value="<?php echo h($bt['block_type']); ?>">
                          <?php
                          $blockIndex = '__INDEX__';
                          $config = [];
                          $formFile = __DIR__ . '/block-forms/' . $bt['block_type'] . '-form.php';
                          if (file_exists($formFile)) {
                              include $formFile;
                          } else {
                              echo '<p class="text-muted">No form for: ' . h($bt['block_type']) . '</p>';
                          }
                          ?>
                      </div>
                  </div>
              </template>
          <?php endforeach; ?>

<script>
let blockCounter = <?php echo count($blocks); ?>;

// Toggle block expand/collapse
function toggleBlock(header) {
    header.closest('.block-card').classList.toggle('open');
}

// Move block up/down
function moveBlock(btn, direction) {
    const card = btn.closest('.block-card');
    const container = card.parentElement;
    const sibling = direction === -1 ? card.previousElementSibling : card.nextElementSibling;

    if (sibling && sibling.classList.contains('block-card')) {
        if (direction === -1) {
            container.insertBefore(card, sibling);
        } else {
            container.insertBefore(sibling, card);
        }
        reindexBlocks();
    }
}

// Remove block
function removeBlock(btn) {
    if (!confirm('Remove this block?')) return;
    btn.closest('.block-card').remove();
    reindexBlocks();
}

// Reindex block field names after reorder/remove
function reindexBlocks() {
    document.querySelectorAll('#blocksContainer .block-card').forEach((card, idx) => {
        card.dataset.index = idx;
        // Update all input/select/textarea names
        card.querySelectorAll('[name]').forEach(el => {
            el.name = el.name.replace(/blocks\[\d+\]/, `blocks[${idx}]`);
        });
    });
}

// Add new block
document.querySelectorAll('.add-block-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        const type = this.dataset.type;
        const template = document.getElementById('blockTemplate_' + type);
        if (!template) {
            alert('No template for block type: ' + type);
            return;
        }

        const html = template.innerHTML.replace(/__INDEX__/g, blockCounter);
        const container = document.getElementById('blocksContainer');

        // Remove "no blocks" message
        const msg = document.getElementById('noBlocksMsg');
        if (msg) msg.remove();

        container.insertAdjacentHTML('beforeend', html);
        blockCounter++;

        // Re-initialize feather icons
        if (typeof feather !== 'undefined') feather.replace();
    });
});

// Auto-generate slug from title
document.getElementById('title').addEventListener('input', function() {
    const slugField = document.getElementById('slug');
    if (!slugField.value || slugField.dataset.autoSlug === 'true') {
        const slug = this.value.toLowerCase()
            .replace(/[^a-z0-9\s\-\/]/g, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '');
        slugField.value = slug;
        slugField.dataset.autoSlug = 'true';
        document.getElementById('slugPreview').textContent = slug;
    }
});

document.getElementById('slug').addEventListener('input', function() {
    this.dataset.autoSlug = 'false';
    document.getElementById('slugPreview').textContent = this.value;
});

// Character counters
function updateCharCount(inputId, countId) {
    const el = document.getElementById(inputId);
    const counter = document.getElementById(countId);
    if (el && counter) {
        counter.textContent = el.value.length;
        el.addEventListener('input', () => counter.textContent = el.value.length);
    }
}
updateCharCount('meta_title', 'metaTitleCount');
updateCharCount('meta_description', 'metaDescCount');

// Add token row
function addTokenRow() {
    const msg = document.getElementById('noTokensMsg');
    if (msg) msg.remove();

    const container = document.getElementById('tokensContainer');
    const row = document.createElement('div');
    row.className = 'token-row';
    row.innerHTML = `
        <input type="text" class="form-control form-control-sm" name="token_keys[]"
               placeholder="TOKEN_NAME" style="max-width: 200px;">
        <input type="text" class="form-control form-control-sm" name="token_values[]"
               placeholder="Value">
        <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.token-row').remove()">&times;</button>
    `;
    container.appendChild(row);
}
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
