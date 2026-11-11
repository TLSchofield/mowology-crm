<?php
/**
 * Before & After Pair Manager
 *
 * Admin page for pairing before/after images from the media library,
 * adding labels, and publishing to the public website module.
 */
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

$pageTitle  = 'Before & After';
$activePage = 'before-after';

// ── Handle POST actions (AJAX) ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $db = getDB();

    // Queue decisions are a manager's call (portfolio.edit); staff can view the page.
    if (in_array($action, ['queue_approve', 'queue_reject', 'queue_consent', 'queue_unpublish', 'queue_privacy'], true)) {
        if (!userHasPermission('portfolio.edit')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Only a manager can approve website photos']);
            exit;
        }
        require_once APP_ROOT . '/Modules/Portfolio/Services/BeforeAfterService.php';
        require_once APP_ROOT . '/Services/Media/MediaVariantGenerator.php';
        require_once APP_ROOT . '/Modules/Portfolio/Services/BeforeAfterJobAdapter.php';
        require_once APP_ROOT . '/Modules/Marketing/Services/VoiceProfile.php';
        $ba     = new BeforeAfterService($db);
        $pairId = (int)($_POST['pair_id'] ?? 0);
        $flow   = BeforeAfterJobAdapter::enabled($db);   // Mia's strip: privacy check, then her offered text, then publish
        // The strip's next step for this pair (null when there is none): the card that replaces the one just decided.
        $nextCard = function () use ($ba, $pairId, $flow) {
            if (!$flow || ($_POST['strip'] ?? '') !== '1') return null;
            $row = $ba->pendingPair($pairId);
            return $row ? BeforeAfterJobAdapter::cardFor($row, $ba->offerRecord()) : null;
        };
        try {
            switch ($action) {
                case 'queue_privacy':
                    if (!$flow) { echo json_encode(['success' => false, 'error' => 'The photo check is switched off']); exit; }
                    $ba->recordPrivacyCheck($pairId, (int)$user['id']);
                    if (!$ba->privacyChecked($pairId)) { echo json_encode(['success' => false, 'error' => 'Could not record the check. The page needs its database update first']); exit; }
                    echo json_encode(['success' => true, 'message' => 'Photos checked.', 'next' => $nextCard()]);
                    exit;
                case 'queue_consent':
                    $ba->recordConsent($pairId, (int)$user['id'], (string)($_POST['note'] ?? ''));
                    echo json_encode(['success' => true, 'message' => 'Agreement recorded.', 'next' => $nextCard()]);
                    exit;
                case 'queue_approve':
                    // With Mia's flow line on, nothing publishes before the owner has checked the photos (server-side, so no page can skip it).
                    if ($flow && !$ba->privacyChecked($pairId)) {
                        echo json_encode(['success' => false, 'error' => 'Check the photos for faces, house numbers and licence plates first']);
                        exit;
                    }
                    $fields = array_intersect_key($_POST, array_flip(['label', 'service', 'category', 'area', 'alt_before', 'alt_after']));
                    $before = ($flow && ($_POST['offered'] ?? '') === '1') ? $ba->pendingPair($pairId) : null;
                    $pair   = $ba->approve($pairId, (int)$user['id'], $fields);
                    if ($before) {   // her record: was the text she offered published as it was?
                        $ba->recordOfferState($pairId, BeforeAfterJobAdapter::edited($before, $fields) ? 'edited' : 'unedited');
                    }
                    echo json_encode(['success' => true, 'pair' => $pair, 'message' => 'On the website.']);
                    exit;
                case 'queue_reject':
                    $ba->reject($pairId, (int)$user['id'], (string)($_POST['reason'] ?? ''));
                    echo json_encode(['success' => true, 'message' => 'Set aside. Not for the website.']);
                    exit;
                case 'queue_unpublish':
                    $ba->unpublish($pairId, (int)$user['id'], (string)($_POST['reason'] ?? 'Unpublished'));
                    echo json_encode(['success' => true]);
                    exit;
            }
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    // Making, removing and ordering website pairs is a manager's call too (Fable review 2026-10-12).
    if (in_array($action, ['save_pair', 'delete_pair', 'reorder'], true) && !userHasPermission('portfolio.edit')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Only a manager can change website photos']);
        exit;
    }

    switch ($action) {
        case 'save_pair':
            $before_id = (int)($_POST['before_id'] ?? 0);
            $after_id  = (int)($_POST['after_id']  ?? 0);
            $label     = trim($_POST['label']    ?? '');
            $service   = trim($_POST['service']  ?? '');
            $category  = trim($_POST['category'] ?? 'general');
            $published = (int)($_POST['published'] ?? 1);

            if (!$before_id || !$after_id) {
                echo json_encode(['success' => false, 'error' => 'Both images required']);
                exit;
            }
            require_once APP_ROOT . '/Modules/Portfolio/Services/BeforeAfterService.php';
            $baCheck = new BeforeAfterService($db);
            if (!$baCheck->isImageMedia($before_id) || !$baCheck->isImageMedia($after_id)) {
                echo json_encode(['success' => false, 'error' => 'Both before and after must be photos']);
                exit;
            }

            $pair_id = (int)($_POST['pair_id'] ?? 0);
            if ($pair_id) {
                // A hand-made pair is the manager's own decision: publishing it IS the approval.
                $stmt = $db->prepare("
                    UPDATE ba_pairs SET before_id=?, after_id=?, label=?, service=?, category=?, published=?,
                        status=?, approved_by=?, approved_at=NOW(), updated_at=NOW()
                    WHERE id=?
                ");
                $stmt->execute([$before_id, $after_id, $label, $service, $category, $published,
                    $published ? 'approved' : 'rejected', (int)$user['id'], $pair_id]);
            } else {
                // Get next sort_order
                $maxSort = (int)$db->query("SELECT COALESCE(MAX(sort_order),0) FROM ba_pairs")->fetchColumn();
                $stmt = $db->prepare("
                    INSERT INTO ba_pairs (before_id, after_id, label, service, category, published, status, approved_by, approved_at, sort_order, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW(), NOW())
                ");
                $stmt->execute([$before_id, $after_id, $label, $service, $category, $published,
                    $published ? 'approved' : 'rejected', (int)$user['id'], $maxSort + 1]);
                $pair_id = $db->lastInsertId();
            }
            echo json_encode(['success' => true, 'pair_id' => $pair_id]);
            exit;

        case 'delete_pair':
            $pair_id = (int)($_POST['pair_id'] ?? 0);
            if ($pair_id) {
                $db->prepare("DELETE FROM ba_pairs WHERE id=?")->execute([$pair_id]);
            }
            echo json_encode(['success' => true]);
            exit;

        case 'reorder':
            $ids = json_decode($_POST['ids'] ?? '[]', true);
            if (is_array($ids)) {
                foreach ($ids as $i => $id) {
                    $db->prepare("UPDATE ba_pairs SET sort_order=? WHERE id=?")->execute([$i + 1, (int)$id]);
                }
            }
            echo json_encode(['success' => true]);
            exit;

    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

$csrfToken = generateCSRFToken();

// ── Approval queue (endorsed visits → pending pairs) ───────────────────────
require_once APP_ROOT . '/Modules/Portfolio/Services/BeforeAfterService.php';
$canApprove = function_exists('userHasPermission') ? userHasPermission('portfolio.edit') : (($user['role'] ?? '') === 'admin');
$pendingPairs = [];
$livePairs    = [];
$parkedPairs  = [];
$pairCounts   = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
$flow         = false;   // Mia's flow line (ops_settings ba_flowline): photo check, then her offered text, then publish
try {
    $db = getDB();
    $baService = new BeforeAfterService($db);
    $baService->sweepEndorsed();          // catch endorsements made before the after photo existed
    $pendingPairs = $baService->pending();
    $pairCounts   = $baService->counts();
    $livePairs    = $baService->decided(BeforeAfterService::STATUS_APPROVED);
    $parkedPairs  = $baService->decided(BeforeAfterService::STATUS_REJECTED);
    require_once APP_ROOT . '/Modules/Portfolio/Services/BeforeAfterJobAdapter.php';
    require_once APP_ROOT . '/Modules/Marketing/Services/VoiceProfile.php';
    $flow = BeforeAfterJobAdapter::enabled($db);
} catch (Throwable $e) {
    // ba_pairs predates migration 1118 — the queue is simply empty until it runs
    error_log('before-after queue unavailable: ' . $e->getMessage());
}
?>
<?php include 'includes/appstack_head.php'; ?>

          <div class="d-flex align-items-start justify-content-between flex-wrap mb-4">
            <div>
              <h1 class="h3 mb-1">Before &amp; After Pairs</h1>
              <p class="text-muted mb-0">Pair crew photos from the media library to create transformation showcases on your website</p>
            </div>
            <button class="btn btn-success" id="btnNewPair">
              <i data-feather="plus" class="align-middle mr-1"></i> New Pair
            </button>
          </div>

          <!-- ── Awaiting approval: endorsed visits, decided by a manager ───────── -->
          <div class="card mb-3 mw-baq" id="baQueue" data-flow="<?= $flow ? '1' : '0' ?>">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap">
              <div class="mw-baq-tabs" role="tablist">
                <button type="button" class="mw-baq-tab is-active" data-tab="waiting" role="tab">Waiting <span class="badge badge-warning ml-1" id="baQueueCount"><?= count($pendingPairs) ?></span></button>
                <button type="button" class="mw-baq-tab" data-tab="live" role="tab">On the website <span class="badge badge-light ml-1" id="baLiveCount"><?= (int)$pairCounts['approved'] ?></span></button>
                <button type="button" class="mw-baq-tab" data-tab="parked" role="tab">Not for the website <span class="badge badge-light ml-1" id="baParkedCount"><?= (int)$pairCounts['rejected'] ?></span></button>
              </div>
              <small class="text-muted">
                <?php if (!$canApprove): ?>View only. A manager approves.<?php elseif (!$flow): ?>Check each pair for faces, house numbers and licence plates before approving.<?php endif; ?>
              </small>
            </div>

            <div class="mw-baq-pane" data-pane="waiting">
            <?php if (empty($pendingPairs)): ?>
              <div class="card-body text-center text-muted py-4" id="baQueueEmpty">
                <i data-feather="heart" class="mw-baq-empty-icon"></i>
                Nothing waiting.<br>
                <small>When crew endorse a visit with a before and an after photo, it appears here for approval.</small>
              </div>
            <?php else: ?>
              <?php foreach ($pendingPairs as $q):
                $needsConsent = ($q['consent_state'] === BeforeAfterService::CONSENT_NEEDED);
                $hasConsent   = ($q['consent_state'] === BeforeAfterService::CONSENT_RECORDED);
                $checked      = trim((string)($q['privacy_checked_at'] ?? '')) !== '';
                $when = !empty($q['completed_at']) ? $q['completed_at'] : ($q['scheduled_date'] ?? $q['queued_at']);
                // With the flow line on, the fields start as Mia's offer (same builders, nothing that names the client).
                $v = $flow ? BeforeAfterJobAdapter::offer($q) : ['label' => $q['label'], 'area' => $q['area'], 'alt_before' => $q['alt_before'], 'alt_after' => $q['alt_after']];
                if ($needsConsent)      $state = 'Needs the client\'s agreement first';
                elseif ($flow && !$checked) $state = 'Photos not checked yet';
                elseif ($flow)          $state = 'Photos checked. Ready for Mia\'s text';
                else                    $state = $hasConsent ? 'Client agreed. Ready to approve' : 'Ready to approve';
              ?>
              <div class="mw-baq-row" data-pair-id="<?= (int)$q['id'] ?>" data-consent="<?= $needsConsent ? 'needed' : 'ok' ?>" data-privacy="<?= $checked ? 'ok' : 'todo' ?>">
                <div class="mw-baq-photos">
                  <figure class="mw-baq-photo">
                    <a href="<?= h($q['before_url']) ?>" target="_blank" rel="noopener"><img src="<?= h($q['before_url']) ?>" alt="Before" loading="lazy"></a>
                    <figcaption>Before</figcaption>
                  </figure>
                  <figure class="mw-baq-photo">
                    <a href="<?= h($q['after_url']) ?>" target="_blank" rel="noopener"><img src="<?= h($q['after_url']) ?>" alt="After" loading="lazy"></a>
                    <figcaption>After</figcaption>
                  </figure>
                </div>
                <div class="mw-baq-main">
                  <div class="mw-baq-title"><?= h($v['label'] ?: ($q['service'] ?: 'Untitled pair')) ?></div>
                  <div class="mw-baq-state q-state"><?= h($state) ?></div>

                  <?php if ($needsConsent): ?>
                  <div class="mw-baq-consent">
                    <span>Strata or managed property: the client must agree before this goes on the website.</span>
                    <?php if ($canApprove): ?>
                    <div class="mw-baq-consent-row mt-2">
                      <input type="text" class="form-control form-control-sm q-consent-note" placeholder="How they agreed, e.g. Email from Ron 21 Sep">
                      <button type="button" class="btn btn-sm btn-outline-primary q-consent-btn">Record agreement</button>
                    </div>
                    <?php endif; ?>
                  </div>
                  <?php elseif ($hasConsent): ?>
                  <div class="mw-baq-consent is-recorded"><i data-feather="check-circle"></i> Client agreed: <?= h($q['consent_note']) ?></div>
                  <?php endif; ?>

                  <?php if ($canApprove): ?>
                  <div class="mw-baq-actions">
                    <?php if ($flow && !$checked): ?>
                    <button type="button" class="btn btn-success q-privacy-btn"><i data-feather="eye" class="mr-1"></i> Photos checked</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-success q-approve-btn"<?= ($flow && !$checked) ? ' hidden' : '' ?><?= $needsConsent ? ' disabled title="Record the client\'s agreement first"' : '' ?>>
                      <i data-feather="globe" class="mr-1"></i> <span class="q-approve-label">Approve &amp; publish</span>
                    </button>
                    <button type="button" class="btn btn-outline-secondary q-reject-btn">Not for the website</button>
                    <button type="button" class="btn btn-link q-edit-btn" aria-expanded="false">Edit</button>
                    <span class="q-status text-muted small ml-2"></span>
                  </div>
                  <?php else: ?>
                  <div class="mw-baq-actions"><button type="button" class="btn btn-link q-edit-btn" aria-expanded="false">Details</button></div>
                  <?php endif; ?>

                  <div class="mw-baq-detail" hidden>
                    <div class="mw-baq-private">
                      <strong><?= h($q['property_name'] ?: $q['address'] ?: 'Property') ?></strong>
                      <?php if (!empty($q['address']) && $q['property_name']): ?><span class="text-muted"> · <?= h($q['address']) ?></span><?php endif; ?>
                      <span class="text-muted"> · <?= h($q['visit_number'] ?: ('Visit #' . (int)$q['visit_id'])) ?> · <?= $when ? date('M j, Y', strtotime($when)) : '' ?></span>
                      <?php if (!empty($q['queued_by_name'])): ?><span class="text-muted"> · endorsed by <?= h($q['queued_by_name']) ?></span><?php endif; ?>
                      <span class="badge badge-light ml-1" title="Office only, never shown on the website">private</span>
                    </div>
                    <div class="form-row">
                      <div class="col-md-6 mb-2">
                        <label class="mw-baq-label">Public label</label>
                        <input type="text" class="form-control form-control-sm q-label" value="<?= h($v['label']) ?>" maxlength="120">
                      </div>
                      <div class="col-md-3 mb-2">
                        <label class="mw-baq-label">Service</label>
                        <input type="text" class="form-control form-control-sm q-service" value="<?= h($q['service']) ?>" maxlength="100">
                      </div>
                      <div class="col-md-3 mb-2">
                        <label class="mw-baq-label">Neighbourhood</label>
                        <input type="text" class="form-control form-control-sm q-area" value="<?= h($v['area']) ?>" maxlength="80" placeholder="e.g. Kitsilano">
                      </div>
                    </div>
                    <div class="form-row">
                      <div class="col-md-3 mb-2">
                        <label class="mw-baq-label">Website filter</label>
                        <select class="form-control form-control-sm q-category">
                          <?php foreach (BeforeAfterService::CATEGORIES as $cat): ?>
                          <option value="<?= $cat ?>"<?= $q['category'] === $cat ? ' selected' : '' ?>><?= ucfirst($cat) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-md-9 mb-2">
                        <label class="mw-baq-label">Image descriptions (alt text, read by Google)</label>
                        <div class="mw-baq-alts">
                          <input type="text" class="form-control form-control-sm q-alt-before" value="<?= h($v['alt_before']) ?>" maxlength="160">
                          <input type="text" class="form-control form-control-sm q-alt-after" value="<?= h($v['alt_after']) ?>" maxlength="160">
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
            </div>

            <?php foreach ([['live', $livePairs, 'Nothing on the website from here yet.'], ['parked', $parkedPairs, 'Nothing set aside.']] as [$paneKey, $paneRows, $paneEmpty]): ?>
            <div class="mw-baq-pane" data-pane="<?= $paneKey ?>" hidden>
              <?php if (!$paneRows): ?>
              <div class="card-body text-center text-muted py-4"><?= h($paneEmpty) ?></div>
              <?php else: foreach ($paneRows as $r): ?>
              <div class="mw-baq-row mw-baq-row-compact">
                <div class="mw-baq-photos">
                  <figure class="mw-baq-photo"><img src="<?= h($r['before_url']) ?>" alt="Before" loading="lazy"></figure>
                  <figure class="mw-baq-photo"><img src="<?= h($r['after_url']) ?>" alt="After" loading="lazy"></figure>
                </div>
                <div class="mw-baq-main">
                  <div class="mw-baq-title"><?= h($r['label'] ?: 'Untitled pair') ?></div>
                  <div class="mw-baq-state"><?= $paneKey === 'live'
                      ? 'On the website' . (!empty($r['approved_at']) ? ' since ' . date('M j, Y', strtotime($r['approved_at'])) : '')
                      : 'Not for the website' . (!empty($r['rejected_reason']) ? ': ' . h($r['rejected_reason']) : '') ?></div>
                </div>
              </div>
              <?php endforeach; endif; ?>
            </div>
            <?php endforeach; ?>
          </div>

          <!-- Toolbar -->
          <div class="card mb-3">
            <div class="card-body py-2 d-flex align-items-center flex-wrap mw-ba-toolbar">
              <span class="mw-ba-toolbar-label">Filter:</span>
              <select class="form-control form-control-sm mw-ba-filter" id="filterService">
                <option value="">All Services</option>
                <option value="Lawn Restoration">Lawn</option>
                <option value="Garden Restoration">Garden</option>
                <option value="Seasonal Cleanup">Cleanup</option>
                <option value="Strata Maintenance">Strata</option>
              </select>
              <select class="form-control form-control-sm mw-ba-filter" id="filterStatus">
                <option value="">All Statuses</option>
                <option value="1">Published</option>
                <option value="0">Draft</option>
              </select>
              <span class="flex-fill"></span>
              <small id="pairsCount" class="text-muted"></small>
            </div>
          </div>

          <!-- Two-column layout -->
          <div class="mw-ba-body">

            <!-- Left: pairs list -->
            <div class="card">
              <div class="card-header d-flex align-items-center justify-content-between">
                <h5 class="card-title mb-0">Transformation Pairs</h5>
                <span class="text-muted mw-ba-hint">Drag to reorder</span>
              </div>
              <div class="mw-ba-pairs-list" id="baPairsList">
                <div class="mw-ba-empty">
                  <i data-feather="image" class="mw-ba-empty-ico"></i>
                  Loading pairs&hellip;
                </div>
              </div>
            </div>

            <!-- Right: edit panel -->
            <div class="card mw-ba-panel">
              <div class="card-header">
                <h5 class="card-title mb-0" id="panelTitle">Select a pair to edit</h5>
              </div>
              <div class="card-body mw-ba-panel-body" id="panelBody">

                <!-- Image picker tabs -->
                <div>
                  <label class="mw-ba-pick-label">Pick Images</label>
                  <div class="mw-ba-tabs">
                    <button class="mw-ba-tab is-active" data-pick="before">Before Photo</button>
                    <button class="mw-ba-tab" data-pick="after">After Photo</button>
                  </div>
                  <div class="mw-ba-pick-grid" id="pickGrid">
                    <div class="mw-ba-empty mw-ba-empty-wide">
                      Loading media&hellip;
                    </div>
                  </div>
                </div>

                <!-- Selected preview -->
                <div class="mw-ba-selected-preview">
                  <div class="mw-ba-preview-slot mw-ba-preview-slot--before" id="prevBefore">
                    <span class="mw-ba-preview-badge">Before</span>
                    <div class="mw-ba-preview-placeholder">
                      <i data-feather="image" class="mw-ba-ph-ico"></i>
                      <span>Select photo</span>
                    </div>
                  </div>
                  <div class="mw-ba-preview-slot mw-ba-preview-slot--after" id="prevAfter">
                    <span class="mw-ba-preview-badge">After</span>
                    <div class="mw-ba-preview-placeholder">
                      <i data-feather="image" class="mw-ba-ph-ico"></i>
                      <span>Select photo</span>
                    </div>
                  </div>
                </div>

                <!-- Form fields -->
                <input type="hidden" id="fPairId" value="">
                <input type="hidden" id="fBeforeId" value="">
                <input type="hidden" id="fAfterId" value="">
                <div class="form-group">
                  <label for="fLabel">Label <small class="text-muted">(e.g. Spring Cleanup &mdash; Kerrisdale)</small></label>
                  <input type="text" class="form-control" id="fLabel" placeholder="Describe the transformation...">
                </div>
                <div class="form-group">
                  <label for="fService">Service Type</label>
                  <select class="form-control" id="fService">
                    <option value="Lawn Restoration">Lawn Restoration</option>
                    <option value="Garden Restoration">Garden Restoration</option>
                    <option value="Seasonal Cleanup">Seasonal Cleanup</option>
                    <option value="Strata Maintenance">Strata Maintenance</option>
                    <option value="Hedge & Pruning">Hedge &amp; Pruning</option>
                    <option value="Garden Care">Garden Care</option>
                  </select>
                </div>
                <div class="form-group">
                  <label for="fCategory">Filter Category</label>
                  <select class="form-control" id="fCategory">
                    <option value="lawn">Lawn</option>
                    <option value="garden">Garden</option>
                    <option value="cleanup">Cleanup</option>
                    <option value="strata">Strata</option>
                  </select>
                </div>
                <div class="form-group">
                  <label for="fPublished">Status</label>
                  <select class="form-control" id="fPublished">
                    <option value="1">Published &mdash; visible on website</option>
                    <option value="0">Draft &mdash; hidden from website</option>
                  </select>
                </div>
              </div>
              <div class="card-footer d-flex mw-ba-footer">
                <button class="btn btn-light flex-fill" id="btnCancel">Cancel</button>
                <button class="btn btn-success flex-fill" id="btnSave">Save Pair</button>
              </div>
            </div>

          </div><!-- /.mw-ba-body -->


          <script>
          (function() {
            // ── Approval queue: tabs, photo check, consent → (Mia's text) → approve / reject ──
            var CSRF  = <?= json_encode($csrfToken) ?>;
            var queue = document.getElementById('baQueue');
            if (!queue) return;
            var FLOW = queue.dataset.flow === '1';   // Mia's flow line: nothing publishes before the photos are checked and her text is read

            function post(fields) {
              var fd = new FormData();
              Object.keys(fields).forEach(function(k) { fd.append(k, fields[k]); });
              fd.append('csrf_token', CSRF);
              return fetch(window.location.pathname, { method: 'POST', body: fd }).then(function(r) { return r.json(); });
            }
            function rowOf(el) { return el.closest('.mw-baq-row'); }
            function status(row, text, ok) {
              var s = row.querySelector('.q-status');
              if (!s) return;
              s.textContent = text;
              s.classList.toggle('is-ok', !!ok);
              s.classList.toggle('is-bad', !ok);
            }
            function state(row, text) { var n = row.querySelector('.q-state'); if (n) n.textContent = text; }
            function bump(id, by) {
              var badge = document.getElementById(id);
              if (badge) badge.textContent = Math.max(0, parseInt(badge.textContent, 10) + by);
            }
            function settle(row, text, countId) {
              status(row, text, true);
              row.classList.add('is-done');
              bump('baQueueCount', -1);
              if (countId) bump(countId, 1);
              setTimeout(function() { row.remove(); }, 900);
              if (typeof fetchPairs === 'function') fetchPairs();
            }
            function openDetail(row, open) {
              var d = row.querySelector('.mw-baq-detail'), b = row.querySelector('.q-edit-btn');
              if (d) d.hidden = !open;
              if (b) b.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
            function consentRecorded(row, note) {
              var box = row.querySelector('.mw-baq-consent');
              if (box) { box.classList.add('is-recorded'); box.innerHTML = '<i data-feather="check-circle"></i> Client agreed: ' + escHtml(note); }
              row.dataset.consent = 'ok';
              var ok = row.querySelector('.q-approve-btn');
              if (ok) { ok.disabled = false; ok.removeAttribute('title'); }
              state(row, FLOW && row.dataset.privacy !== 'ok' ? 'Photos not checked yet' : (FLOW ? 'Photos checked. Ready for Mia\'s text' : 'Client agreed. Ready to approve'));
              if (typeof feather !== 'undefined') feather.replace();
            }
            function photosChecked(row) {
              row.dataset.privacy = 'ok';
              var p = row.querySelector('.q-privacy-btn'); if (p) p.remove();
              var ok = row.querySelector('.q-approve-btn'); if (ok) ok.hidden = false;
              state(row, row.dataset.consent === 'needed' ? 'Needs the client\'s agreement first' : 'Photos checked. Ready for Mia\'s text');
            }

            // Tabs: Waiting / On the website / Not for the website
            queue.querySelectorAll('.mw-baq-tab').forEach(function(tab) {
              tab.addEventListener('click', function() {
                queue.querySelectorAll('.mw-baq-tab').forEach(function(t) { t.classList.toggle('is-active', t === tab); });
                queue.querySelectorAll('.mw-baq-pane').forEach(function(p) { p.hidden = p.dataset.pane !== tab.dataset.tab; });
              });
            });

            queue.addEventListener('click', function(e) {
              var btn = e.target.closest('button');
              if (!btn) return;
              var row = rowOf(btn);
              if (!row || !row.dataset.pairId) return;
              var pairId = row.dataset.pairId;

              if (btn.classList.contains('q-edit-btn')) {
                openDetail(row, row.querySelector('.mw-baq-detail').hidden);
                return;
              }

              if (btn.classList.contains('q-privacy-btn')) {
                btn.disabled = true;
                post({ action: 'queue_privacy', pair_id: pairId }).then(function(d) {
                  if (!d.success) { btn.disabled = false; status(row, d.error || 'Could not record', false); return; }
                  photosChecked(row);
                }).catch(function() { btn.disabled = false; status(row, 'Network error', false); });
                return;
              }

              if (btn.classList.contains('q-consent-btn')) {
                var note = row.querySelector('.q-consent-note').value.trim();
                if (!note) { row.querySelector('.q-consent-note').focus(); return; }
                btn.disabled = true;
                post({ action: 'queue_consent', pair_id: pairId, note: note }).then(function(d) {
                  if (!d.success) { btn.disabled = false; alert(d.error || 'Could not record'); return; }
                  consentRecorded(row, note);
                });
                return;
              }

              if (btn.classList.contains('q-approve-btn')) {
                // Flow line on: the first press offers Mia's text (the fields are open, ready to change); the second publishes it.
                if (FLOW && !row.dataset.offered) {
                  row.dataset.offered = '1';
                  openDetail(row, true);
                  btn.querySelector('.q-approve-label').textContent = 'Publish';
                  state(row, 'Mia\'s text is below. Change anything you like, then press Publish.');
                  return;
                }
                btn.disabled = true;
                status(row, 'Publishing…', true);
                var body = {
                  action: 'queue_approve', pair_id: pairId,
                  label: row.querySelector('.q-label').value, service: row.querySelector('.q-service').value,
                  area: row.querySelector('.q-area').value, category: row.querySelector('.q-category').value,
                  alt_before: row.querySelector('.q-alt-before').value, alt_after: row.querySelector('.q-alt-after').value
                };
                if (FLOW) body.offered = '1';
                post(body).then(function(d) {
                  if (!d.success) { btn.disabled = false; status(row, d.error || 'Error', false); return; }
                  settle(row, '✓ On the website', 'baLiveCount');
                }).catch(function() { btn.disabled = false; status(row, 'Network error', false); });
                return;
              }

              if (btn.classList.contains('q-reject-btn')) {
                var reason = prompt('Why not? (optional — kept for the record)') ;
                if (reason === null) return;
                btn.disabled = true;
                post({ action: 'queue_reject', pair_id: pairId, reason: reason }).then(function(d) {
                  if (!d.success) { btn.disabled = false; status(row, d.error || 'Error', false); return; }
                  settle(row, 'Parked', 'baParkedCount');
                });
              }
            });

            // Mia's strip decided a step for a pair: the card below follows (the strip sent the page's own request).
            window.addEventListener('mw:job-done', function(e) {
              var d = e.detail || {}, req = d.request || {}, row = queue.querySelector('.mw-baq-row[data-pair-id="' + req.pair_id + '"]');
              if (!row || !d.response || !d.response.success) return;
              if (req.action === 'queue_privacy') photosChecked(row);
              else if (req.action === 'queue_consent') consentRecorded(row, req.note || 'recorded');
              else if (req.action === 'queue_approve') settle(row, '✓ On the website', 'baLiveCount');
              else if (req.action === 'queue_reject') settle(row, 'Parked', 'baParkedCount');
            });

            function escHtml(str) {
              var d = document.createElement('div');
              d.appendChild(document.createTextNode(str || ''));
              return d.innerHTML;
            }
          })();
          </script>

          <script>
          (function () {
            'use strict';

            var pairs     = [];
            var mediaList = [];
            var activePick = 'before';
            var editingId  = null;
            var beforeId   = null;
            var afterId    = null;
            var CSRF       = <?= json_encode($csrfToken) ?>;

            /* ── API helpers ────────────────────────────────────────── */
            function apiPost(body) {
              var fd = new FormData();
              Object.keys(body).forEach(function(k) { fd.append(k, body[k]); });
              fd.append('csrf_token', CSRF);
              return fetch(window.location.pathname, { method: 'POST', body: fd })
                .then(function(r) {
                  var ct = r.headers.get('content-type') || '';
                  if (!ct.includes('application/json')) {
                    throw new Error('Session expired. Please refresh the page and try again.');
                  }
                  return r.json();
                })
                .then(function(data) {
                  if (data && !data.success) throw new Error(data.error || 'Save failed');
                  return data;
                });
            }

            function fetchMedia() {
              fetch('/crm/api/cms_media_list.php?per_page=50&type=image', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
              })
              .then(function(r) { return r.json(); })
              .then(function(data) {
                mediaList = (data.data || []).map(function(m) {
                  return { id: m.id, url: m.file_path, url_thumb: m.thumb_path || m.file_path, filename: m.filename };
                });
                renderPickGrid();
              })
              .catch(function() { mediaList = []; renderPickGrid(); });
            }

            function fetchPairs() {
              fetch('/crm/api/before-after-pairs.php?admin=1&limit=100', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
              })
              .then(function(r) { return r.json(); })
              .then(function(data) { pairs = data.pairs || []; renderPairsList(); })
              .catch(function() { pairs = []; renderPairsList(); });
            }

            /* ── Render pairs list ─────────────────────────────────── */
            function renderPairsList() {
              var list  = document.getElementById('baPairsList');
              var count = document.getElementById('pairsCount');
              var svc   = document.getElementById('filterService').value;
              var stat  = document.getElementById('filterStatus').value;

              var filtered = pairs;
              if (svc)  filtered = filtered.filter(function(p) { return p.service === svc; });
              if (stat !== '') filtered = filtered.filter(function(p) { return String(+p.published) === stat; });

              count.textContent = filtered.length + ' pair' + (filtered.length !== 1 ? 's' : '');

              if (!filtered.length) {
                list.innerHTML = '<div class="mw-ba-empty">' +
                  '<i data-feather="image" class="mw-ba-empty-ico"></i>' +
                  'No pairs yet &mdash; click <strong>+ New Pair</strong> to get started</div>';
                if (window.feather) feather.replace();
                return;
              }

              list.innerHTML = filtered.map(function(p) {
                return '<div class="mw-ba-pair-row" data-id="' + p.id + '" draggable="true">' +
                  '<div class="mw-ba-pair-drag"><i data-feather="menu" class="mw-ba-grip-ico"></i></div>' +
                  '<div class="mw-ba-pair-imgs">' +
                    '<div class="mw-ba-pair-thumb mw-ba-pair-thumb--before" style="background-image:url(\'' + escAttr(p.before_thumb || '') + '\')">' +
                      '<span class="mw-ba-thumb-badge mw-ba-thumb-badge--before">B</span></div>' +
                    '<div class="mw-ba-pair-thumb mw-ba-pair-thumb--after" style="background-image:url(\'' + escAttr(p.after_thumb || '') + '\')">' +
                      '<span class="mw-ba-thumb-badge mw-ba-thumb-badge--after">A</span></div>' +
                  '</div>' +
                  '<div class="mw-ba-pair-info">' +
                    '<div class="mw-ba-pair-label">' + escHtml(p.label || 'Untitled') + '</div>' +
                    '<div class="mw-ba-pair-service">' + escHtml(p.service || '') + ' &middot; ' + escHtml(p.date || '') + '</div>' +
                  '</div>' +
                  '<div class="mw-ba-pair-status">' +
                    '<span class="badge badge-' + (p.published ? 'success' : 'secondary') + '">' + (p.published ? 'Live' : 'Draft') + '</span>' +
                  '</div>' +
                  '<div class="mw-ba-pair-actions">' +
                    '<button class="btn btn-sm btn-outline-secondary" data-action="edit" data-id="' + p.id + '" title="Edit"><i data-feather="edit-2" class="mw-ba-btn-ico"></i></button>' +
                    '<button class="btn btn-sm btn-outline-danger" data-action="delete" data-id="' + p.id + '" title="Delete"><i data-feather="trash-2" class="mw-ba-btn-ico"></i></button>' +
                  '</div>' +
                '</div>';
              }).join('');

              if (window.feather) feather.replace();

              // Click handlers
              list.querySelectorAll('[data-action="edit"]').forEach(function(btn) {
                btn.addEventListener('click', function(e) { e.stopPropagation(); editPair(parseInt(btn.dataset.id)); });
              });
              list.querySelectorAll('[data-action="delete"]').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                  e.stopPropagation();
                  if (!confirm('Delete this pair?')) return;
                  apiPost({ action: 'delete_pair', pair_id: btn.dataset.id }).then(function() { fetchPairs(); });
                });
              });

              initDragSort(list);
            }

            /* ── Render image picker ────────────────────────────────── */
            function renderPickGrid() {
              var grid = document.getElementById('pickGrid');
              if (!mediaList.length) {
                grid.innerHTML = '<div class="mw-ba-empty mw-ba-empty-wide">' +
                  'No photos found in media library yet.<br>Upload via the Media Library page.</div>';
                return;
              }

              grid.innerHTML = mediaList.map(function(m) {
                var cls = 'mw-ba-pick-item';
                if (beforeId === m.id) cls += ' is-selected-before';
                if (afterId === m.id)  cls += ' is-selected-after';
                var name = (m.filename || '').replace(/\.[^.]+$/, '');
                if (name.length > 20) name = name.substring(0, 20) + '...';
                return '<div class="' + cls + '" data-id="' + m.id + '" data-url="' + escAttr(m.url_thumb || m.url) + '"' +
                  ' style="background-image:url(\'' + escAttr(m.url_thumb || m.url) + '\')" title="' + escAttr(m.filename || '') + '">' +
                  '<span class="mw-ba-pick-name">' + escHtml(name) + '</span></div>';
              }).join('');

              grid.querySelectorAll('.mw-ba-pick-item').forEach(function(item) {
                item.addEventListener('click', function() { selectImage(parseInt(item.dataset.id), item.dataset.url); });
              });
            }

            function selectImage(id, url) {
              if (activePick === 'before') {
                beforeId = id;
                document.getElementById('fBeforeId').value = id;
                var slot = document.getElementById('prevBefore');
                slot.style.backgroundImage = "url('" + url + "')";
                var ph = slot.querySelector('.mw-ba-preview-placeholder');
                if (ph) ph.style.display = 'none';
              } else {
                afterId = id;
                document.getElementById('fAfterId').value = id;
                var slot = document.getElementById('prevAfter');
                slot.style.backgroundImage = "url('" + url + "')";
                var ph = slot.querySelector('.mw-ba-preview-placeholder');
                if (ph) ph.style.display = 'none';
              }
              renderPickGrid();
            }

            /* ── Tabs ───────────────────────────────────────────────── */
            document.querySelectorAll('.mw-ba-tab').forEach(function(tab) {
              tab.addEventListener('click', function() {
                document.querySelectorAll('.mw-ba-tab').forEach(function(t) { t.classList.remove('is-active'); });
                tab.classList.add('is-active');
                activePick = tab.dataset.pick;
                renderPickGrid();
              });
            });

            /* ── Edit / New ─────────────────────────────────────────── */
            function editPair(id) {
              var pair = pairs.find(function(p) { return p.id === id; });
              if (!pair) return;
              editingId = id;
              beforeId = pair.before_id;
              afterId  = pair.after_id;
              document.getElementById('panelTitle').textContent = 'Edit Pair';
              document.getElementById('fPairId').value   = pair.id;
              document.getElementById('fBeforeId').value = pair.before_id;
              document.getElementById('fAfterId').value  = pair.after_id;
              document.getElementById('fLabel').value    = pair.label || '';
              document.getElementById('fService').value  = pair.service || '';
              document.getElementById('fCategory').value = pair.category || 'lawn';
              document.getElementById('fPublished').value = pair.published ? '1' : '0';

              var prevB = document.getElementById('prevBefore');
              var prevA = document.getElementById('prevAfter');
              if (pair.before_thumb) {
                prevB.style.backgroundImage = "url('" + pair.before_thumb + "')";
                var ph = prevB.querySelector('.mw-ba-preview-placeholder');
                if (ph) ph.style.display = 'none';
              }
              if (pair.after_thumb) {
                prevA.style.backgroundImage = "url('" + pair.after_thumb + "')";
                var ph = prevA.querySelector('.mw-ba-preview-placeholder');
                if (ph) ph.style.display = 'none';
              }
              renderPickGrid();
            }

            function resetPanel() {
              editingId = null; beforeId = null; afterId = null;
              document.getElementById('panelTitle').textContent = 'New Pair';
              document.getElementById('fPairId').value = '';
              document.getElementById('fBeforeId').value = '';
              document.getElementById('fAfterId').value = '';
              document.getElementById('fLabel').value = '';
              document.getElementById('fService').selectedIndex = 0;
              document.getElementById('fCategory').selectedIndex = 0;
              document.getElementById('fPublished').selectedIndex = 0;
              var prevB = document.getElementById('prevBefore');
              var prevA = document.getElementById('prevAfter');
              prevB.style.backgroundImage = '';
              prevA.style.backgroundImage = '';
              var phB = prevB.querySelector('.mw-ba-preview-placeholder');
              var phA = prevA.querySelector('.mw-ba-preview-placeholder');
              if (phB) phB.style.display = '';
              if (phA) phA.style.display = '';
              renderPickGrid();
            }

            document.getElementById('btnNewPair').addEventListener('click', resetPanel);
            document.getElementById('btnCancel').addEventListener('click', resetPanel);

            document.getElementById('btnSave').addEventListener('click', function() {
              var label = document.getElementById('fLabel').value.trim();
              var bId   = document.getElementById('fBeforeId').value;
              var aId   = document.getElementById('fAfterId').value;
              if (!bId || !aId) { alert('Please select both a before and after photo.'); return; }
              if (!label)       { alert('Please add a label for this pair.'); return; }

              var btn = document.getElementById('btnSave');
              btn.textContent = 'Saving...'; btn.disabled = true;

              apiPost({
                action:    'save_pair',
                pair_id:   document.getElementById('fPairId').value || '0',
                before_id: bId,
                after_id:  aId,
                label:     label,
                service:   document.getElementById('fService').value,
                category:  document.getElementById('fCategory').value,
                published: document.getElementById('fPublished').value,
              }).then(function() {
                btn.textContent = 'Save Pair'; btn.disabled = false;
                resetPanel();
                fetchPairs();
              }).catch(function(err) {
                btn.textContent = 'Save Pair'; btn.disabled = false;
                alert(err.message || 'Save failed. Please refresh and try again.');
              });
            });

            /* ── Drag-to-sort ───────────────────────────────────────── */
            function initDragSort(list) {
              var dragEl = null;
              list.querySelectorAll('.mw-ba-pair-row').forEach(function(row) {
                row.addEventListener('dragstart', function() { dragEl = row; row.classList.add('is-dragging'); });
                row.addEventListener('dragend',   function() { row.classList.remove('is-dragging'); saveOrder(); });
                row.addEventListener('dragover',  function(e) {
                  e.preventDefault();
                  var after = getDragAfterEl(list, e.clientY);
                  if (after) list.insertBefore(dragEl, after);
                  else list.appendChild(dragEl);
                });
              });
            }

            function getDragAfterEl(container, y) {
              var els = Array.from(container.querySelectorAll('.mw-ba-pair-row:not(.is-dragging)'));
              var result = { offset: Number.NEGATIVE_INFINITY, element: null };
              els.forEach(function(child) {
                var box = child.getBoundingClientRect();
                var offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > result.offset) {
                  result = { offset: offset, element: child };
                }
              });
              return result.element;
            }

            function saveOrder() {
              var ids = Array.from(document.querySelectorAll('.mw-ba-pair-row')).map(function(r) { return r.dataset.id; });
              apiPost({ action: 'reorder', ids: JSON.stringify(ids) });
            }

            /* ── Filters ────────────────────────────────────────────── */
            document.getElementById('filterService').addEventListener('change', renderPairsList);
            document.getElementById('filterStatus').addEventListener('change', renderPairsList);

            /* ── Utils ──────────────────────────────────────────────── */
            function escHtml(str) {
              var d = document.createElement('div');
              d.appendChild(document.createTextNode(str || ''));
              return d.innerHTML;
            }
            function escAttr(str) { return (str || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

            /* ── Boot ───────────────────────────────────────────────── */
            fetchMedia();
            fetchPairs();
          })();
          </script>

<?php include 'includes/appstack_footer.php'; ?>
