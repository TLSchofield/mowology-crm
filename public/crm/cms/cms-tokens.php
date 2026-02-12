<?php
/**
 * CMS Token Dictionary — Manage global tokens for {{TOKEN}} replacement
 *
 * CRUD admin page for the token_dictionary table. Tokens are replaced
 * in CMS block content at render time. Per-page overrides take priority
 * over defaults set here.
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

$pageTitle = 'Token Dictionary';
$activePage = 'cms';

$message = '';
$error = '';
$editToken = null;

// ---------------------------------------------------------------------------
// Handle POST actions
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        // --- Save (create or update) ---
        if ($action === 'save') {
            try {
                $tokenId = (int)($_POST['token_id'] ?? 0);
                $data = [
                    'token'         => strtoupper(trim($_POST['token'] ?? '')),
                    'default_value' => trim($_POST['default_value'] ?? ''),
                    'description'   => trim($_POST['description'] ?? ''),
                    'category'      => $_POST['category'] ?? 'custom',
                    'example_value' => trim($_POST['example_value'] ?? ''),
                ];

                if ($data['token'] === '') {
                    throw new Exception('Token name is required.');
                }

                cms_saveToken($data, $tokenId ?: null);
                $message = $tokenId ? 'Token updated.' : 'Token created.';
            } catch (Exception $e) {
                $error = 'Error saving token: ' . $e->getMessage();
            }
        }

        // --- Delete ---
        if ($action === 'delete') {
            try {
                $tokenId = (int)($_POST['token_id'] ?? 0);
                if ($tokenId > 0) {
                    cms_deleteToken($tokenId);
                    $message = 'Token deleted.';
                }
            } catch (Exception $e) {
                $error = 'Error deleting token: ' . $e->getMessage();
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Check if we're editing an existing token (via ?edit=ID)
// ---------------------------------------------------------------------------
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    if ($editId > 0) {
        $allForEdit = cms_getAllTokens();
        foreach ($allForEdit as $t) {
            if ((int)$t['id'] === $editId) {
                $editToken = $t;
                break;
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Load all tokens grouped by category
// ---------------------------------------------------------------------------
$allTokens = cms_getAllTokens();
$grouped = [];
$categoryOrder = ['brand', 'location', 'service', 'custom'];
foreach ($allTokens as $t) {
    $cat = $t['category'] ?? 'custom';
    $grouped[$cat][] = $t;
}

$categoryLabels = [
    'brand'    => 'Brand',
    'location' => 'Location',
    'service'  => 'Service',
    'custom'   => 'Custom',
];

$categoryBadges = [
    'brand'    => 'badge-primary',
    'location' => 'badge-info',
    'service'  => 'badge-success',
    'custom'   => 'badge-secondary',
];

$csrfToken = generateCSRFToken();
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <!-- Page Header -->
          <div class="d-flex justify-content-between align-items-center mb-4">
              <div>
                  <a href="/crm/cms-pages_appstack.php" class="text-muted small">&larr; Back to CMS</a>
                  <h1 class="h3 mb-0 mt-1">Token Dictionary</h1>
              </div>
              <a href="#tokenForm" class="btn btn-success">
                  <i data-feather="plus" class="mr-1" style="width:16px;height:16px;"></i> New Token
              </a>
          </div>

          <!-- Flash Messages -->
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

          <!-- Token List by Category -->
          <?php if (empty($allTokens)): ?>
              <div class="card mb-4">
                  <div class="card-body text-center py-5">
                      <i data-feather="book-open" class="mb-3 text-muted" style="width:48px;height:48px;"></i>
                      <p class="mb-1"><strong>No tokens defined yet.</strong></p>
                      <p class="text-muted">Create your first token below to start using <code>{{TOKEN}}</code> placeholders in CMS content.</p>
                  </div>
              </div>
          <?php else: ?>
              <?php foreach ($categoryOrder as $cat): ?>
                  <?php if (empty($grouped[$cat])) continue; ?>
                  <div class="card mb-4">
                      <div class="card-header d-flex justify-content-between align-items-center">
                          <h5 class="mb-0">
                              <span class="badge <?php echo $categoryBadges[$cat]; ?> mr-2"><?php echo h($categoryLabels[$cat]); ?></span>
                              Tokens
                          </h5>
                          <span class="text-muted small"><?php echo count($grouped[$cat]); ?> token<?php echo count($grouped[$cat]) !== 1 ? 's' : ''; ?></span>
                      </div>
                      <div class="card-body p-0">
                          <div class="table-responsive">
                              <table class="table table-hover mb-0">
                                  <thead class="thead-light">
                                      <tr>
                                          <th>Token</th>
                                          <th>Default Value</th>
                                          <th>Description</th>
                                          <th>Example</th>
                                          <th class="text-right" style="width:120px;">Actions</th>
                                      </tr>
                                  </thead>
                                  <tbody>
                                      <?php foreach ($grouped[$cat] as $tok): ?>
                                          <tr>
                                              <td>
                                                  <code class="font-weight-bold">{{<?php echo h($tok['token']); ?>}}</code>
                                              </td>
                                              <td><?php echo h($tok['default_value']); ?></td>
                                              <td class="text-muted small"><?php echo h($tok['description'] ?? ''); ?></td>
                                              <td class="text-muted small"><?php echo h($tok['example_value'] ?? ''); ?></td>
                                              <td class="text-right text-nowrap">
                                                  <a href="?edit=<?php echo (int)$tok['id']; ?>#tokenForm"
                                                     class="btn btn-sm btn-outline-primary mr-1"
                                                     title="Edit">
                                                      <i data-feather="edit-2" style="width:14px;height:14px;"></i>
                                                  </a>
                                                  <form method="post" class="d-inline" onsubmit="return confirm('Delete token {{<?php echo h($tok['token']); ?>}}? This cannot be undone.');">
                                                      <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                                                      <input type="hidden" name="action" value="delete">
                                                      <input type="hidden" name="token_id" value="<?php echo (int)$tok['id']; ?>">
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

          <!-- Create / Edit Form -->
          <div class="card mb-4" id="tokenForm">
              <div class="card-header">
                  <h5 class="mb-0">
                      <?php echo $editToken ? 'Edit Token' : 'New Token'; ?>
                  </h5>
              </div>
              <div class="card-body">
                  <form method="post">
                      <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                      <input type="hidden" name="action" value="save">
                      <input type="hidden" name="token_id" value="<?php echo $editToken ? (int)$editToken['id'] : 0; ?>">

                      <div class="row">
                          <div class="col-md-6">
                              <div class="form-group">
                                  <label for="tokenName">Token Name <span class="text-danger">*</span></label>
                                  <input type="text" class="form-control" id="tokenName" name="token"
                                         value="<?php echo h($editToken['token'] ?? ''); ?>"
                                         placeholder="e.g. BRAND_TAGLINE"
                                         required
                                         pattern="[A-Za-z0-9_]+"
                                         title="Uppercase letters, numbers, and underscores only"
                                         style="text-transform: uppercase;">
                                  <small class="form-text text-muted">Uppercase letters, numbers, underscores. Will be used as <code>{{TOKEN_NAME}}</code>.</small>
                              </div>
                          </div>
                          <div class="col-md-6">
                              <div class="form-group">
                                  <label for="tokenCategory">Category</label>
                                  <select class="form-control" id="tokenCategory" name="category">
                                      <?php foreach ($categoryLabels as $catKey => $catLabel): ?>
                                          <option value="<?php echo $catKey; ?>"
                                              <?php echo ($editToken['category'] ?? 'custom') === $catKey ? 'selected' : ''; ?>>
                                              <?php echo h($catLabel); ?>
                                          </option>
                                      <?php endforeach; ?>
                                  </select>
                              </div>
                          </div>
                      </div>

                      <div class="form-group">
                          <label for="tokenDefaultValue">Default Value</label>
                          <input type="text" class="form-control" id="tokenDefaultValue" name="default_value"
                                 value="<?php echo h($editToken['default_value'] ?? ''); ?>"
                                 placeholder="e.g. Mowology Landscaping">
                          <small class="form-text text-muted">The value used when no per-page override is set.</small>
                      </div>

                      <div class="form-group">
                          <label for="tokenDescription">Description</label>
                          <input type="text" class="form-control" id="tokenDescription" name="description"
                                 value="<?php echo h($editToken['description'] ?? ''); ?>"
                                 placeholder="e.g. Company brand name used in headings">
                      </div>

                      <div class="form-group">
                          <label for="tokenExampleValue">Example Value</label>
                          <input type="text" class="form-control" id="tokenExampleValue" name="example_value"
                                 value="<?php echo h($editToken['example_value'] ?? ''); ?>"
                                 placeholder="e.g. Your Trusted Lawn Care Experts">
                          <small class="form-text text-muted">Shown as a hint when editing pages. Not used in rendering.</small>
                      </div>

                      <div class="d-flex align-items-center">
                          <button type="submit" class="btn btn-success mr-2">
                              <i data-feather="save" class="mr-1" style="width:14px;height:14px;"></i>
                              <?php echo $editToken ? 'Update Token' : 'Create Token'; ?>
                          </button>
                          <?php if ($editToken): ?>
                              <a href="/crm/cms/cms-tokens.php" class="btn btn-outline-secondary">Cancel</a>
                          <?php endif; ?>
                      </div>
                  </form>
              </div>
          </div>

          <!-- Reference Card -->
          <div class="card mb-4">
              <div class="card-header">
                  <h5 class="mb-0"><i data-feather="info" class="mr-2" style="width:16px;height:16px;"></i> How to Use Tokens</h5>
              </div>
              <div class="card-body">
                  <p>
                      Tokens let you define reusable values that are automatically inserted into CMS page content at render time.
                      Place a token placeholder like <code>{{TOKEN_NAME}}</code> anywhere in a block's text fields, and the CMS
                      engine will replace it with the token's value.
                  </p>

                  <h6 class="font-weight-bold mt-3">Resolution Priority</h6>
                  <ol class="mb-3">
                      <li><strong>Per-page override</strong> &mdash; If a page defines its own value for a token (via the Page Tokens section in the page editor), that value is used first.</li>
                      <li><strong>Token Dictionary default</strong> &mdash; The default value defined on this page is used when no per-page override exists.</li>
                      <li><strong>Site constants</strong> &mdash; Built-in tokens like <code>{{BRAND}}</code>, <code>{{PHONE}}</code>, <code>{{EMAIL}}</code>, and <code>{{YEAR}}</code> are always available from site configuration, even if not listed in the dictionary.</li>
                  </ol>

                  <h6 class="font-weight-bold mt-3">Example Usage</h6>
                  <p>In a CMS block's headline field:</p>
                  <pre class="bg-light p-3 rounded mb-3"><code>Professional {{SERVICE}} in {{CITY}} | {{BRAND}}</code></pre>
                  <p>If <code>SERVICE</code> = "Lawn Care", <code>CITY</code> = "Vancouver", and <code>BRAND</code> = "Mowology", this renders as:</p>
                  <pre class="bg-light p-3 rounded mb-0"><code>Professional Lawn Care in Vancouver | Mowology</code></pre>
              </div>
          </div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
