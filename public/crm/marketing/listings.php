<?php
/**
 * Listings — where Mowology is listed, and whether each listing says the same thing (NAP).
 *
 * Thin controller: ListingsService does the work (app/Modules/Marketing/Services/ListingsService.php).
 * Most directories have no API, so Tim checks a listing, types what it shows, and the page
 * compares it with the master record from business_settings. Mia reminds him monthly.
 * Also holds the "Google approved API access" switch for Google Business Profile.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('marketing.view');

require_once APP_ROOT . '/Modules/Marketing/Services/ListingsService.php';
require_once APP_ROOT . '/Modules/Social/Services/GbpService.php';

$pageTitle  = 'Listings';
$activePage = 'listings';
$canEdit    = userHasPermission('marketing.edit') || userHasPermission('marketing.approve');
$db         = getDB();
$svc        = new ListingsService($db);
$flash      = '';
$flashBad   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit || !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        $flash = 'Not allowed, or the form expired. Refresh and try again.';
        $flashBad = true;
    } else {
        try {
            $what = (string)($_POST['form'] ?? '');
            if ($what === 'listing') {
                $r = $svc->save((int)($_POST['id'] ?? 0), $_POST, (int)$user['id']);
                $flash = $r['ok'] ? 'Saved.' . ($r['nap']['issues'] ? ' Details that differ: ' . implode('; ', $r['nap']['issues']) . '.' : '') : $r['error'];
                $flashBad = !$r['ok'];
            } elseif ($what === 'categories') {
                $svc->saveCategories((string)($_POST['categories'] ?? ''));
                $flash = 'Categories saved.';
            } elseif ($what === 'gbp_approved') {
                $db->prepare("INSERT INTO ops_settings (setting_key, setting_value, description) VALUES ('gbp_api_approved', ?, 'Mia: 1 once Google has approved Business Profile API access')
                              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([!empty($_POST['approved']) ? '1' : '0']);
                $flash = !empty($_POST['approved']) ? 'Noted: Google approved API access.' : 'Google Business Profile is back to drafts only.';
            }
        } catch (Throwable $e) {
            error_log('Listings page: ' . $e->getMessage());
            $flash = 'That didn\'t save — see the error log.';
            $flashBad = true;
        }
    }
}

$ready    = $svc->ready();
$master   = $svc->master();
$rows     = $ready ? $svc->all() : [];
$gbpMode  = (new GbpService($db))->mode();
$approved = false;
try {
    $approved = (string)$db->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'gbp_api_approved'")->fetchColumn() === '1';
} catch (Throwable $e) {}
$csrf = generateCSRFToken();
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
              <h1 class="h3 mb-0">Listings</h1>
              <p class="text-muted mb-0">Where Mowology is listed, and whether every listing shows the same name, address and phone</p>
            </div>
          </div>

          <?php if ($flash !== ''): ?>
          <div class="alert <?= $flashBad ? 'alert-danger' : 'alert-success' ?>"><?= h($flash) ?></div>
          <?php endif; ?>

          <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h5 class="card-title mb-0">Master record</h5>
              <span class="mw-lst-muted">From Settings → Business. Every listing should match this exactly.</span>
            </div>
            <div class="card-body">
              <div class="mw-lst-nap">
                <div><div class="mw-k">Name</div><div class="mw-v"><?= h($master['name'] ?: '—') ?></div></div>
                <div><div class="mw-k">Address</div><div class="mw-v"><?= h($master['address'] ?: '—') ?></div></div>
                <div><div class="mw-k">Phone</div><div class="mw-v"><?= h($master['phone'] ?: '—') ?></div></div>
                <div><div class="mw-k">Website</div><div class="mw-v"><?= h($master['website'] ?: '—') ?></div></div>
                <div><div class="mw-k">Hours</div><div class="mw-v"><?= h($master['hours'] ?: '—') ?></div></div>
                <div><div class="mw-k">Categories</div><div class="mw-v"><?= h($master['categories'] ?: '—') ?></div></div>
              </div>
              <?php if ($canEdit): ?>
              <form method="post" class="form-inline mt-3">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="form" value="categories">
                <label class="mr-2 mw-lst-muted" for="lstCats">Categories</label>
                <input id="lstCats" class="form-control form-control-sm mr-2" name="categories" size="50" value="<?= h($master['categories']) ?>">
                <button class="btn btn-sm btn-outline-secondary">Save</button>
              </form>
              <?php endif; ?>
            </div>
          </div>

          <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h5 class="card-title mb-0">Google Business Profile API</h5>
              <span class="mw-lst-status <?= $gbpMode['mode'] === 'live' ? 'is-verified' : 'is-not_claimed' ?>"><?= $gbpMode['mode'] === 'live' ? 'Connected' : 'Drafts only' ?></span>
            </div>
            <div class="card-body">
              <p class="mb-2"><?= h($gbpMode['reason']) ?></p>
              <ol class="mb-3">
                <?php foreach ($gbpMode['steps'] as $st): ?>
                <li class="<?= $st['done'] ? 'text-muted' : '' ?>"><?= h($st['text']) ?>
                  <?php if (!empty($st['url'])): ?> <a href="<?= h($st['url']) ?>" target="_blank" rel="noopener">Open</a><?php endif; ?></li>
                <?php endforeach; ?>
              </ol>
              <?php if ($canEdit): ?>
              <form method="post" class="form-inline">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="form" value="gbp_approved">
                <div class="custom-control custom-checkbox mr-3">
                  <input type="checkbox" class="custom-control-input" id="gbpApproved" name="approved" value="1" <?= $approved ? 'checked' : '' ?>>
                  <label class="custom-control-label" for="gbpApproved">Google has approved API access (the quota in Cloud Console shows 300, not 0)</label>
                </div>
                <button class="btn btn-sm btn-outline-secondary">Save</button>
              </form>
              <?php endif; ?>
            </div>
          </div>

          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h5 class="card-title mb-0">Directories</h5>
              <span class="mw-lst-muted">Re-check each one every <?= (int)ListingsService::STALE_DAYS ?> days. Mia reminds you monthly to check Houzz and Yelp.</span>
            </div>
            <?php if (!$ready): ?>
            <div class="card-body"><p class="mb-0">Run migration 1201 (marketing_listings) to start tracking listings.</p></div>
            <?php else: ?>
            <div class="table-responsive">
              <table class="table mb-0 mw-lst-table">
                <thead><tr><th>Site</th><th>Status</th><th>Profile</th><th>Reviews</th><th>Details match?</th><th>Last checked</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): $nap = $r['nap']; ?>
                  <tr>
                    <td><strong><?= h($r['site_name']) ?></strong><?php if ((int)$r['is_optional']): ?><span class="mw-lst-optional">optional</span><?php endif; ?></td>
                    <td><span class="mw-lst-status is-<?= h($r['status']) ?>"><?= h(ListingsService::STATUSES[$r['status']] ?? $r['status']) ?></span></td>
                    <td><?php if ($r['profile_url']): ?><a href="<?= h($r['profile_url']) ?>" target="_blank" rel="noopener">View</a><?php elseif ($r['claim_url']): ?><a href="<?= h($r['claim_url']) ?>" target="_blank" rel="noopener">Claim</a><?php else: ?>—<?php endif; ?></td>
                    <td><?= $r['review_count'] !== null ? (int)$r['review_count'] . ($r['rating'] !== null ? ' · ' . h(number_format((float)$r['rating'], 1)) . '★' : '') : '<span class="mw-lst-muted">—</span>' ?></td>
                    <td>
                      <?php if ($nap['status'] === 'match'): ?><span class="mw-lst-napok">Matches</span>
                      <?php elseif ($nap['status'] === 'mismatch'): ?><span class="mw-lst-napbad" title="<?= h(implode('; ', $nap['issues'])) ?>">Differs: <?= h(implode(', ', array_keys(array_filter($nap['fields'], fn($f) => $f === 'differs')))) ?></span>
                      <?php else: ?><span class="mw-lst-muted">Not entered</span><?php endif; ?>
                    </td>
                    <td><?= $r['last_checked'] ? h(date('M j, Y', strtotime((string)$r['last_checked']))) : '<span class="mw-lst-muted">Never</span>' ?><?= $r['stale'] && $r['last_checked'] ? ' <span class="mw-lst-muted">(due)</span>' : '' ?></td>
                    <td><?php if ($canEdit): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-toggle="collapse" data-target="#lstEdit<?= (int)$r['id'] ?>">Update</button><?php endif; ?></td>
                  </tr>
                  <?php if ($canEdit): ?>
                  <tr class="collapse mw-lst-edit" id="lstEdit<?= (int)$r['id'] ?>">
                    <td colspan="7">
                      <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="form" value="listing">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <div class="form-row">
                          <div class="col-md-2"><label class="mw-lst-muted">Status</label>
                            <select name="status" class="form-control form-control-sm">
                              <?php foreach (ListingsService::STATUSES as $k => $lbl): ?><option value="<?= h($k) ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
                            </select></div>
                          <div class="col-md-6"><label class="mw-lst-muted">Profile link</label><input name="profile_url" class="form-control form-control-sm" value="<?= h((string)$r['profile_url']) ?>" placeholder="https://"></div>
                          <div class="col-md-2"><label class="mw-lst-muted">Reviews</label><input name="review_count" type="number" min="0" class="form-control form-control-sm" value="<?= h((string)$r['review_count']) ?>"></div>
                          <div class="col-md-2"><label class="mw-lst-muted">Rating</label><input name="rating" type="number" min="0" max="5" step="0.1" class="form-control form-control-sm" value="<?= h((string)$r['rating']) ?>"></div>
                        </div>
                        <div class="form-row">
                          <div class="col-md-3"><label class="mw-lst-muted">Name as listed</label><input name="listed_name" class="form-control form-control-sm" value="<?= h((string)$r['listed_name']) ?>"></div>
                          <div class="col-md-4"><label class="mw-lst-muted">Address as listed</label><input name="listed_address" class="form-control form-control-sm" value="<?= h((string)$r['listed_address']) ?>"></div>
                          <div class="col-md-2"><label class="mw-lst-muted">Phone as listed</label><input name="listed_phone" class="form-control form-control-sm" value="<?= h((string)$r['listed_phone']) ?>"></div>
                          <div class="col-md-3"><label class="mw-lst-muted">Website as listed</label><input name="listed_website" class="form-control form-control-sm" value="<?= h((string)$r['listed_website']) ?>"></div>
                        </div>
                        <div class="form-row">
                          <div class="col-md-10"><label class="mw-lst-muted">Notes</label><input name="notes" class="form-control form-control-sm" value="<?= h((string)$r['notes']) ?>"></div>
                          <div class="col-md-2 d-flex align-items-end"><button class="btn btn-sm btn-success btn-block">Save (checked today)</button></div>
                        </div>
                      </form>
                    </td>
                  </tr>
                  <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>
          </div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
