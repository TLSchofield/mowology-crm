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

            $pair_id = (int)($_POST['pair_id'] ?? 0);
            if ($pair_id) {
                $stmt = $db->prepare("
                    UPDATE ba_pairs SET before_id=?, after_id=?, label=?, service=?, category=?, published=?, updated_at=NOW()
                    WHERE id=?
                ");
                $stmt->execute([$before_id, $after_id, $label, $service, $category, $published, $pair_id]);
            } else {
                // Get next sort_order
                $maxSort = (int)$db->query("SELECT COALESCE(MAX(sort_order),0) FROM ba_pairs")->fetchColumn();
                $stmt = $db->prepare("
                    INSERT INTO ba_pairs (before_id, after_id, label, service, category, published, sort_order, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([$before_id, $after_id, $label, $service, $category, $published, $maxSort + 1]);
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

          <!-- Toolbar -->
          <div class="card mb-3">
            <div class="card-body py-2 d-flex align-items-center flex-wrap" style="gap:10px">
              <span class="mw-ba-toolbar-label">Filter:</span>
              <select class="form-control form-control-sm mw-ba-filter" id="filterService" style="width:auto">
                <option value="">All Services</option>
                <option value="Lawn Restoration">Lawn</option>
                <option value="Garden Restoration">Garden</option>
                <option value="Seasonal Cleanup">Cleanup</option>
                <option value="Strata Maintenance">Strata</option>
              </select>
              <select class="form-control form-control-sm mw-ba-filter" id="filterStatus" style="width:auto">
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
                <span class="text-muted" style="font-size:0.78rem">Drag to reorder</span>
              </div>
              <div class="mw-ba-pairs-list" id="baPairsList">
                <div class="mw-ba-empty">
                  <i data-feather="image" style="width:40px;height:40px;stroke:#d1d5db;display:block;margin:0 auto 1rem"></i>
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
                    <div class="mw-ba-empty" style="grid-column:1/-1;padding:1rem">
                      Loading media&hellip;
                    </div>
                  </div>
                </div>

                <!-- Selected preview -->
                <div class="mw-ba-selected-preview">
                  <div class="mw-ba-preview-slot mw-ba-preview-slot--before" id="prevBefore">
                    <span class="mw-ba-preview-badge">Before</span>
                    <div class="mw-ba-preview-placeholder">
                      <i data-feather="image" style="width:24px;height:24px;stroke:#d1d5db"></i>
                      <span>Select photo</span>
                    </div>
                  </div>
                  <div class="mw-ba-preview-slot mw-ba-preview-slot--after" id="prevAfter">
                    <span class="mw-ba-preview-badge">After</span>
                    <div class="mw-ba-preview-placeholder">
                      <i data-feather="image" style="width:24px;height:24px;stroke:#d1d5db"></i>
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
              <div class="card-footer d-flex" style="gap:8px">
                <button class="btn btn-light flex-fill" id="btnCancel">Cancel</button>
                <button class="btn btn-success flex-fill" id="btnSave">Save Pair</button>
              </div>
            </div>

          </div><!-- /.mw-ba-body -->

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
                .then(function(r) { return r.json(); });
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
                  '<i data-feather="image" style="width:40px;height:40px;stroke:#d1d5db;display:block;margin:0 auto 1rem"></i>' +
                  'No pairs yet &mdash; click <strong>+ New Pair</strong> to get started</div>';
                if (window.feather) feather.replace();
                return;
              }

              list.innerHTML = filtered.map(function(p) {
                return '<div class="mw-ba-pair-row" data-id="' + p.id + '" draggable="true">' +
                  '<div class="mw-ba-pair-drag"><i data-feather="menu" style="width:14px;height:14px;color:#d1d5db"></i></div>' +
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
                    '<button class="btn btn-sm btn-outline-secondary" data-action="edit" data-id="' + p.id + '" title="Edit"><i data-feather="edit-2" style="width:14px;height:14px"></i></button>' +
                    '<button class="btn btn-sm btn-outline-danger" data-action="delete" data-id="' + p.id + '" title="Delete"><i data-feather="trash-2" style="width:14px;height:14px"></i></button>' +
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
                grid.innerHTML = '<div style="grid-column:1/-1;padding:1rem;text-align:center;color:#9ca3af;font-size:0.8rem">' +
                  'No photos found in media library yet.<br>Upload via the Media Library page.</div>';
                return;
              }

              grid.innerHTML = mediaList.map(function(m) {
                var cls = 'mw-ba-pick-item';
                if (beforeId === m.id) cls += ' is-selected-before';
                if (afterId === m.id)  cls += ' is-selected-after';
                return '<div class="' + cls + '" data-id="' + m.id + '" data-url="' + escAttr(m.url_thumb || m.url) + '"' +
                  ' style="background-image:url(\'' + escAttr(m.url_thumb || m.url) + '\')"></div>';
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
