<?php
/**
 * Contract Terms & Conditions — template library.
 *
 * The wording clients see above the signature pad. Editing a body creates a new
 * revision; contracts already signed keep the wording they were signed with, so
 * changing a template here can never rewrite an existing agreement.
 *
 * Admin only: these are the liability terms, not a content field.
 */

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

if (($user['role'] ?? '') !== 'admin') {
    header('Location: ../contracts_appstack.php');
    exit;
}

require_once APP_ROOT . '/Modules/Contracts/Services/ContractTermsService.php';

$db  = getDB();
$svc = new ContractTermsService($db);

$error   = '';
$success = '';

if (!$svc->isAvailable()) {
    $error = 'Contract terms are not set up in this environment yet — migration 1119 has not been applied. '
           . 'Run it from Settings → Database → Migrations, then reload this page.';
}

// ── POST ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    try {
        if (($_POST['action'] ?? '') === 'save') {
            $svc->saveTemplate([
                'id'                   => intval($_POST['id'] ?? 0),
                'name'                 => $_POST['name'] ?? '',
                'scope'                => $_POST['scope'] ?? 'service_type',
                'service_type'         => $_POST['service_type'] ?? '',
                'body'                 => $_POST['body'] ?? '',
                'season_start'         => $_POST['season_start'] ?? '',
                'season_end'           => $_POST['season_end'] ?? '',
                'forces_no_auto_renew' => !empty($_POST['forces_no_auto_renew']),
                'is_active'            => !empty($_POST['is_active']),
                'is_default'           => !empty($_POST['is_default']),
                'sort_order'           => intval($_POST['sort_order'] ?? 0),
            ], (int)$user['id']);
            $success = 'Terms saved. Contracts already signed keep the wording they were signed with.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$csrfToken = generateCSRFToken();
$templates = $svc->listTemplates();
$editId    = intval($_GET['edit'] ?? 0);
$editing   = $editId ? $svc->getTemplate($editId) : null;

$pageTitle  = 'Contract Terms';
$activePage = 'contracts';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <a href="../contracts_appstack.php" class="mw-back-link">&larr; Back to Contracts</a>

          <h1 class="h3 mb-1">Terms &amp; Conditions</h1>
          <p class="text-muted mb-4">
              Shown in full to the client above the signature pad. They must tick to agree before they can sign,
              and the exact wording is stored against the contract at that moment.
          </p>

          <?php if ($error): ?>
              <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
          <?php endif; ?>
          <?php if ($success): ?>
              <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
          <?php endif; ?>

          <div class="row">
              <div class="col-lg-5 mb-4">
                  <div class="card">
                      <div class="card-header"><h5 class="card-title mb-0">Templates</h5></div>
                      <div class="card-body p-0">
                          <?php if (!$templates): ?>
                              <p class="text-muted p-3 mb-0">No terms templates yet.</p>
                          <?php else: ?>
                          <table class="table table-sm mb-0">
                              <tbody>
                              <?php foreach ($templates as $t): ?>
                                  <tr<?php echo $editId === (int)$t['id'] ? ' class="table-active"' : ''; ?>>
                                      <td>
                                          <a href="?edit=<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></a>
                                          <div class="small text-muted">
                                              <?php echo $t['scope'] === 'global'
                                                  ? 'All services'
                                                  : htmlspecialchars((string)($t['service_type'] ?: 'unassigned')); ?>
                                              &middot; rev <?php echo (int)$t['version']; ?>
                                              <?php if (!empty($t['season_start']) && !empty($t['season_end'])): ?>
                                                  &middot; <?php echo htmlspecialchars($t['season_start'] . ' → ' . $t['season_end']); ?>
                                              <?php endif; ?>
                                          </div>
                                      </td>
                                      <td class="text-right" style="white-space:nowrap;">
                                          <?php if (!empty($t['is_default'])): ?>
                                              <span class="badge badge-primary">Default</span>
                                          <?php endif; ?>
                                          <?php if (empty($t['is_active'])): ?>
                                              <span class="badge badge-secondary">Inactive</span>
                                          <?php endif; ?>
                                          <?php if (!empty($t['forces_no_auto_renew'])): ?>
                                              <span class="badge badge-warning">Seasonal</span>
                                          <?php endif; ?>
                                      </td>
                                  </tr>
                              <?php endforeach; ?>
                              </tbody>
                          </table>
                          <?php endif; ?>
                      </div>
                      <div class="card-footer">
                          <a href="?edit=0" class="btn btn-sm btn-outline-primary">New template</a>
                      </div>
                  </div>
              </div>

              <div class="col-lg-7">
                  <div class="card">
                      <div class="card-header">
                          <h5 class="card-title mb-0"><?php echo $editing ? 'Edit terms' : 'New terms'; ?></h5>
                      </div>
                      <div class="card-body">
                          <form method="POST">
                              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                              <input type="hidden" name="action" value="save">
                              <input type="hidden" name="id" value="<?php echo (int)($editing['id'] ?? 0); ?>">

                              <div class="mw-form-row">
                                  <label class="mw-form-label" for="name">Name</label>
                                  <input type="text" id="name" name="name" class="form-control" required
                                         value="<?php echo htmlspecialchars((string)($editing['name'] ?? '')); ?>">
                              </div>

                              <div class="row">
                                  <div class="col-sm-6">
                                      <div class="mw-form-row">
                                          <label class="mw-form-label" for="scope">Applies to</label>
                                          <select id="scope" name="scope" class="form-control">
                                              <option value="service_type"<?php echo ($editing['scope'] ?? '') === 'service_type' ? ' selected' : ''; ?>>A specific service</option>
                                              <option value="global"<?php echo ($editing['scope'] ?? '') === 'global' ? ' selected' : ''; ?>>All services</option>
                                          </select>
                                      </div>
                                  </div>
                                  <div class="col-sm-6">
                                      <div class="mw-form-row">
                                          <label class="mw-form-label" for="service_type">Service type</label>
                                          <input type="text" id="service_type" name="service_type" class="form-control"
                                                 placeholder="e.g. snow_removal"
                                                 value="<?php echo htmlspecialchars((string)($editing['service_type'] ?? '')); ?>">
                                          <small class="text-muted">Matched against the service type on the contract's job plans.</small>
                                      </div>
                                  </div>
                              </div>

                              <div class="mw-form-row">
                                  <label class="mw-form-label" for="body">Terms shown to the client</label>
                                  <textarea id="body" name="body" class="form-control" rows="16" required
                                            style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.85rem;"><?php
                                      echo htmlspecialchars((string)($editing['body'] ?? '')); ?></textarea>
                                  <small class="text-muted">
                                      Plain text. Blank lines separate paragraphs. Editing this creates revision
                                      <?php echo $editing ? ((int)$editing['version'] + 1) : 1; ?> —
                                      contracts already signed are unaffected.
                                  </small>
                              </div>

                              <div class="row">
                                  <div class="col-sm-4">
                                      <div class="mw-form-row">
                                          <label class="mw-form-label" for="season_start">Season starts</label>
                                          <input type="text" id="season_start" name="season_start" class="form-control"
                                                 placeholder="MM-DD" maxlength="5"
                                                 value="<?php echo htmlspecialchars((string)($editing['season_start'] ?? '')); ?>">
                                      </div>
                                  </div>
                                  <div class="col-sm-4">
                                      <div class="mw-form-row">
                                          <label class="mw-form-label" for="season_end">Season ends</label>
                                          <input type="text" id="season_end" name="season_end" class="form-control"
                                                 placeholder="MM-DD" maxlength="5"
                                                 value="<?php echo htmlspecialchars((string)($editing['season_end'] ?? '')); ?>">
                                      </div>
                                  </div>
                                  <div class="col-sm-4">
                                      <div class="mw-form-row">
                                          <label class="mw-form-label" for="sort_order">Priority</label>
                                          <input type="number" id="sort_order" name="sort_order" class="form-control"
                                                 value="<?php echo (int)($editing['sort_order'] ?? 50); ?>">
                                          <small class="text-muted">Lowest wins on a mixed contract.</small>
                                      </div>
                                  </div>
                              </div>

                              <div class="mw-form-row">
                                  <div class="custom-control custom-checkbox">
                                      <input type="checkbox" class="custom-control-input" id="forces_no_auto_renew"
                                             name="forces_no_auto_renew" value="1"
                                             <?php echo !empty($editing['forces_no_auto_renew']) ? 'checked' : ''; ?>>
                                      <label class="custom-control-label" for="forces_no_auto_renew">
                                          Seasonal — never auto-renew, and end the contract at the season end
                                      </label>
                                  </div>
                                  <div class="custom-control custom-checkbox">
                                      <input type="checkbox" class="custom-control-input" id="is_default" name="is_default" value="1"
                                             <?php echo !empty($editing['is_default']) ? 'checked' : ''; ?>>
                                      <label class="custom-control-label" for="is_default">
                                          Use as the fallback when no service-specific terms match
                                      </label>
                                  </div>
                                  <div class="custom-control custom-checkbox">
                                      <input type="checkbox" class="custom-control-input" id="is_active" name="is_active" value="1"
                                             <?php echo (!$editing || !empty($editing['is_active'])) ? 'checked' : ''; ?>>
                                      <label class="custom-control-label" for="is_active">Active</label>
                                  </div>
                              </div>

                              <button type="submit" class="btn btn-primary">Save terms</button>
                          </form>
                      </div>
                  </div>
              </div>
          </div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
