<?php
/**
 * Social Accounts — Connect and manage platform integrations.
 *
 * Google Business Profile: Full OAuth 2.0 + location selection.
 * Facebook / Instagram: Meta Graph API with page selection + Instagram Business linkage.
 * LinkedIn: Phase 3 placeholder.
 *
 * Query params:
 *   step=select_location   After GBP OAuth — shows location picker
 *   step=select_page       After Meta OAuth — shows page picker (Phase 2)
 *   error=MSG              OAuth failure message
 *   platform=gbp|facebook
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('marketing.view');

$pageTitle  = 'Social Accounts';
$activePage = 'social';
$canApprove = userHasPermission('marketing.approve');

$step     = htmlspecialchars($_GET['step']     ?? '');
$platform = htmlspecialchars($_GET['platform'] ?? '');
$errorMsg = htmlspecialchars($_GET['error']    ?? '');
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <!-- Header -->
          <div class="d-flex justify-content-between align-items-center mb-4">
              <div>
                  <h1 class="h3 mb-0">Social Accounts</h1>
                  <p class="text-muted mb-0">
                      <a href="/crm/marketing/social.php" class="text-muted">Social</a>
                      <span class="mx-1">›</span> Connected Platforms
                  </p>
              </div>
              <a href="/crm/marketing/social.php" class="btn btn-outline-secondary">Back</a>
          </div>

          <?php if ($errorMsg): ?>
          <div class="alert alert-danger alert-dismissible fade show">
              <strong>Connection failed:</strong> <?php echo $errorMsg; ?>
              <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
          </div>
          <?php endif; ?>

          <!-- OAuth Step: Select Facebook Page -->
          <?php if ($step === 'select_page' && in_array($platform, ['facebook', 'instagram'], true) && $canApprove): ?>
          <div class="card mb-4" style="border-color:#1877f2">
              <div class="card-header" style="background:#1877f2;color:#fff">
                  <h5 class="mb-0">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-2"><path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"/></svg>
                      Facebook Authorized — Select a Page to Connect
                  </h5>
              </div>
              <div class="card-body">
                  <p class="text-muted">Select the Facebook Page you want to publish to. Pages with a linked Instagram Business account show an Instagram badge — you can connect both at once.</p>
                  <div id="pageList">
                      <div class="mw-soc-loading">Loading your Facebook Pages...</div>
                  </div>
              </div>
          </div>
          <?php endif; ?>

          <!-- OAuth Step: Select GBP Location -->
          <?php if ($step === 'select_location' && $platform === 'gbp' && $canApprove): ?>
          <div class="card mb-4 border-success">
              <div class="card-header bg-success text-white">
                  <h5 class="mb-0">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                      Google Authorized — Select Your Business Location
                  </h5>
              </div>
              <div class="card-body">
                  <p class="text-muted">Choose which Google Business Profile location to connect to Mowology.</p>
                  <div id="locationList">
                      <div class="mw-soc-loading">Loading your GBP locations...</div>
                  </div>
              </div>
          </div>
          <?php endif; ?>

          <!-- Platform Connection Cards -->
          <div class="row mb-4">

              <!-- Google Business Profile -->
              <div class="col-md-6 col-xl-4 mb-3">
                  <div class="mw-soc-platform-card mw-soc-platform-gbp">
                      <div class="mw-soc-platform-card-header">
                          <div class="mw-soc-platform-logo mw-soc-pl-gbp">
                              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                          </div>
                          <div>
                              <h5 class="mb-0">Google Business Profile</h5>
                              <p class="text-muted small mb-0">Local posts, photos, reviews</p>
                          </div>
                          <span class="mw-soc-phase-badge">Phase 1</span>
                      </div>
                      <div class="mw-soc-platform-card-body">
                          <ul class="mw-soc-platform-features">
                              <li>✓ Create local posts (text + photos)</li>
                              <li>✓ Call-to-action buttons (Book, Learn More)</li>
                              <li>✓ Highest local SEO visibility ROI</li>
                              <li>✓ Automatic scheduling &amp; retry</li>
                          </ul>
                          <?php if ($canApprove): ?>
                          <a href="/crm/api/social/accounts.php?action=oauth-init&platform=gbp"
                             class="btn btn-success btn-block" id="btnConnectGbp">
                              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                              Connect Google Business
                          </a>
                          <?php else: ?>
                          <p class="text-muted small">An admin must connect this account.</p>
                          <?php endif; ?>
                      </div>
                  </div>
              </div>

              <!-- Facebook -->
              <div class="col-md-6 col-xl-4 mb-3">
                  <div class="mw-soc-platform-card mw-soc-platform-facebook">
                      <div class="mw-soc-platform-card-header">
                          <div class="mw-soc-platform-logo" style="background:#1877f2;color:#fff">
                              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"/></svg>
                          </div>
                          <div>
                              <h5 class="mb-0">Facebook Page</h5>
                              <p class="text-muted small mb-0">Posts, photos, stories</p>
                          </div>
                      </div>
                      <div class="mw-soc-platform-card-body">
                          <ul class="mw-soc-platform-features">
                              <li>✓ Photo &amp; multi-photo posts</li>
                              <li>✓ Page insights &amp; engagement</li>
                              <li>✓ Linked to Instagram Business</li>
                              <li>✓ Automatic scheduling &amp; retry</li>
                          </ul>
                          <?php if ($canApprove): ?>
                          <a href="/crm/api/social/accounts.php?action=oauth-init&amp;platform=facebook"
                             class="btn btn-block" style="background:#1877f2;color:#fff">
                              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="mr-1"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                              Connect Facebook Page
                          </a>
                          <?php else: ?>
                          <p class="text-muted small">An admin must connect this account.</p>
                          <?php endif; ?>
                      </div>
                  </div>
              </div>

              <!-- Instagram -->
              <div class="col-md-6 col-xl-4 mb-3">
                  <div class="mw-soc-platform-card mw-soc-platform-instagram">
                      <div class="mw-soc-platform-card-header">
                          <div class="mw-soc-platform-logo" style="background:linear-gradient(45deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888);color:#fff">
                              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5"/><path d="M16 11.37A4 4 0 1112.63 8 4 4 0 0116 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>
                          </div>
                          <div>
                              <h5 class="mb-0">Instagram Business</h5>
                              <p class="text-muted small mb-0">Reels, posts, carousels</p>
                          </div>
                      </div>
                      <div class="mw-soc-platform-card-body">
                          <ul class="mw-soc-platform-features">
                              <li>✓ Feed posts &amp; carousels (up to 10 images)</li>
                              <li>✓ Hashtag optimization</li>
                              <li>✓ Before/after visuals</li>
                              <li>✓ Connected via Facebook Page OAuth</li>
                          </ul>
                          <?php if ($canApprove): ?>
                          <a href="/crm/api/social/accounts.php?action=oauth-init&amp;platform=facebook"
                             class="btn btn-block" style="background:linear-gradient(45deg,#f09433,#e6683c,#dc2743,#cc2366);color:#fff">
                              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="mr-1"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                              Connect Instagram (via Facebook)
                          </a>
                          <p class="text-muted x-small mt-1 mb-0">Requires Instagram Business account linked to a Facebook Page.</p>
                          <?php else: ?>
                          <p class="text-muted small">An admin must connect this account.</p>
                          <?php endif; ?>
                      </div>
                  </div>
              </div>
          </div>

          <!-- Connected Accounts Table -->
          <div class="card mb-4">
              <div class="card-header">
                  <h5 class="mb-0">Connected Accounts</h5>
              </div>
              <div class="card-body p-0" id="accountsTable">
                  <div class="mw-soc-loading p-3">Loading...</div>
              </div>
          </div>

          <!-- Audit Log -->
          <?php if ($canApprove): ?>
          <div class="card">
              <div class="card-header">
                  <h5 class="mb-0">Audit Log</h5>
              </div>
              <div class="card-body p-0" id="auditLog">
                  <div class="mw-soc-loading p-3">Loading...</div>
              </div>
          </div>
          <?php endif; ?>

          <!-- Location picker modal (GBP) -->
          <?php if ($canApprove): ?>
          <div class="modal fade" id="locationModal" tabindex="-1">
              <div class="modal-dialog">
                  <div class="modal-content">
                      <div class="modal-header">
                          <h5 class="modal-title">Save GBP Connection</h5>
                          <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                      </div>
                      <div class="modal-body">
                          <input type="hidden" id="selLocationName">
                          <input type="hidden" id="selAccountResource">
                          <div class="form-group">
                              <label>Location</label>
                              <input type="text" class="form-control" id="selLocationDisplay" readonly>
                          </div>
                          <div class="form-group">
                              <label>Address</label>
                              <input type="text" class="form-control" id="selLocationAddress" readonly>
                          </div>
                      </div>
                      <div class="modal-footer">
                          <button class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                          <button class="btn btn-success" onclick="confirmConnect()">
                              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                              Connect This Location
                          </button>
                      </div>
                  </div>
              </div>
          </div>
          <?php endif; ?>

          <!-- Facebook Page picker modal -->
          <?php if ($canApprove): ?>
          <div class="modal fade" id="pageModal" tabindex="-1">
              <div class="modal-dialog">
                  <div class="modal-content">
                      <div class="modal-header" style="background:#1877f2;color:#fff">
                          <h5 class="modal-title">Connect Facebook Page</h5>
                          <button type="button" class="close" style="color:#fff" data-dismiss="modal"><span>&times;</span></button>
                      </div>
                      <div class="modal-body">
                          <input type="hidden" id="selPageId">
                          <input type="hidden" id="selPageToken">
                          <input type="hidden" id="selIgUserId">
                          <div class="form-group">
                              <label>Facebook Page</label>
                              <input type="text" class="form-control" id="selPageDisplay" readonly>
                          </div>
                          <div id="selIgInfo" class="form-group" style="display:none">
                              <label>Instagram Account</label>
                              <input type="text" class="form-control" id="selIgDisplay" readonly>
                          </div>
                          <p class="text-muted small mb-0">The page access token is stored encrypted. It does not expire.</p>
                      </div>
                      <div class="modal-footer">
                          <button class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                          <button class="btn btn-block" style="background:#1877f2;color:#fff" onclick="confirmConnectPage('facebook')">
                              Connect Facebook Page
                          </button>
                          <button class="btn btn-block" id="btnConnectInstagram" onclick="confirmConnectPage('instagram')"
                                  style="background:linear-gradient(45deg,#f09433,#cc2366);color:#fff;display:none">
                              + Also Connect Instagram Business
                          </button>
                      </div>
                  </div>
              </div>
          </div>
          <?php endif; ?>

          <script>
          (function() {
              'use strict';

              var csrf       = '<?php echo generateCSRFToken(); ?>';
              var canApprove = <?php echo json_encode($canApprove); ?>;
              var step       = '<?php echo $step; ?>';
              var platform   = '<?php echo $platform; ?>';

              // ── Load existing accounts ─────────────────────────────
              function loadAccounts() {
                  fetch('/crm/api/social/accounts.php?action=list')
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          var container = document.getElementById('accountsTable');
                          if (!data.success || !data.accounts.length) {
                              container.innerHTML = '<div class="mw-soc-empty-state p-3"><p>No accounts connected yet.</p></div>';
                              return;
                          }

                          var platformNames = {gbp:'Google Business Profile', facebook:'Facebook Page', instagram:'Instagram Business', linkedin:'LinkedIn'};
                          var html = '<div class="table-responsive"><table class="table mb-0">'
                              + '<thead><tr><th>Platform</th><th>Location / Page</th><th>Token Status</th><th>Last Sync</th><th>Status</th>';
                          if (canApprove) html += '<th>Actions</th>';
                          html += '</tr></thead><tbody>';

                          data.accounts.forEach(function(a) {
                              var healthLabels = {good:'Active', expiring:'Expiring soon', expired:'Token expired', unknown:'Unknown'};
                              var healthClasses = {good:'success', expiring:'warning', expired:'danger', unknown:'secondary'};
                              var h = a.token_health;

                              html += '<tr>';
                              html += '<td><span class="mw-soc-platform-pill mw-soc-pl-' + esc(a.platform) + '">' + esc(platformNames[a.platform] || a.platform) + '</span></td>';
                              html += '<td>' + esc(a.location_name_display || a.account_name) + '</td>';
                              html += '<td><span class="badge badge-' + (healthClasses[h] || 'secondary') + '">' + esc(healthLabels[h] || h) + '</span></td>';
                              html += '<td class="text-muted small">' + (a.last_sync_at ? formatDt(a.last_sync_at) : '—') + '</td>';
                              html += '<td>' + (a.is_active ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-secondary">Paused</span>') + '</td>';
                              if (canApprove) {
                                  html += '<td class="text-nowrap">';
                                  html += '<button class="btn btn-sm btn-outline-secondary mr-1" onclick="toggleAccount(' + a.id + ')">Toggle</button>';
                                  html += '<button class="btn btn-sm btn-outline-danger" onclick="disconnectAccount(' + a.id + ', \'' + esc(a.account_name) + '\')">Disconnect</button>';
                                  html += '</td>';
                              }
                              html += '</tr>';
                          });
                          html += '</tbody></table></div>';
                          container.innerHTML = html;
                      });
              }

              // ── Load Facebook Pages after OAuth ───────────────────
              function loadPages() {
                  var listDiv = document.getElementById('pageList');
                  if (!listDiv) return;

                  fetch('/crm/api/social/accounts.php?action=pages')
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (!data.success) {
                              listDiv.innerHTML = '<div class="alert alert-danger">' + esc(data.error || 'Unknown error') + '</div>';
                              return;
                          }
                          if (!data.pages || !data.pages.length) {
                              listDiv.innerHTML = '<div class="alert alert-warning">No Facebook Pages found on this account. Make sure you manage at least one Facebook Page.</div>';
                              return;
                          }
                          var html = '<div class="list-group">';
                          data.pages.forEach(function(p) {
                              var igBadge = p.ig_user_id
                                  ? '<span class="badge badge-pill ml-2" style="background:linear-gradient(45deg,#f09433,#cc2366);color:#fff">Instagram linked</span>'
                                  : '';
                              html += '<div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" style="cursor:pointer"'
                                  + ' onclick="selectPage(\'' + esc(p.page_id) + '\',\'' + esc(p.page_name).replace(/'/g, "\\'") + '\',\'' + esc(p.page_token).replace(/'/g, "\\'") + '\',\'' + esc(p.ig_user_id || '') + '\')">'
                                  + '<div><strong>' + esc(p.page_name) + '</strong>' + igBadge + '</div>'
                                  + '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>'
                                  + '</div>';
                          });
                          html += '</div>';
                          listDiv.innerHTML = html;
                      })
                      .catch(function(e) {
                          if (listDiv) listDiv.innerHTML = '<div class="alert alert-danger">Could not load pages: ' + esc(e.message) + '</div>';
                      });
              }

              window.selectPage = function(pageId, pageName, pageToken, igUserId) {
                  document.getElementById('selPageId').value    = pageId;
                  document.getElementById('selPageToken').value = pageToken;
                  document.getElementById('selIgUserId').value  = igUserId || '';
                  document.getElementById('selPageDisplay').value = pageName;

                  var igInfo = document.getElementById('selIgInfo');
                  var btnIg  = document.getElementById('btnConnectInstagram');
                  if (igUserId) {
                      document.getElementById('selIgDisplay').value = 'Instagram Business account linked (ID: ' + igUserId + ')';
                      igInfo.style.display = '';
                      btnIg.style.display  = '';
                  } else {
                      igInfo.style.display = 'none';
                      btnIg.style.display  = 'none';
                  }
                  $('#pageModal').modal('show');
              };

              window.confirmConnectPage = function(platform) {
                  var btn = platform === 'instagram'
                      ? document.getElementById('btnConnectInstagram')
                      : document.querySelector('#pageModal .modal-footer .btn[style*="1877f2"]');
                  if (btn) { btn.disabled = true; btn.textContent = 'Connecting...'; }

                  var body = {
                      csrf_token:   csrf,
                      platform:     platform,
                      account_name: document.getElementById('selPageDisplay').value,
                      page_id:      document.getElementById('selPageId').value,
                      page_token:   document.getElementById('selPageToken').value,
                      ig_user_id:   document.getElementById('selIgUserId').value || null,
                  };

                  fetch('/crm/api/social/accounts.php?action=connect', {
                      method: 'POST',
                      headers: {'Content-Type': 'application/json'},
                      body: JSON.stringify(body)
                  }).then(function(r) { return r.json(); }).then(function(data) {
                      if (data.success) {
                          $('#pageModal').modal('hide');
                          window.location.href = '/crm/marketing/social-accounts.php?msg=' + encodeURIComponent(data.message || 'Connected!');
                      } else {
                          alert('Error: ' + (data.error || 'Unknown error'));
                          if (btn) { btn.disabled = false; btn.textContent = platform === 'instagram' ? '+ Also Connect Instagram Business' : 'Connect Facebook Page'; }
                      }
                  }).catch(function(e) {
                      alert('Connect failed: ' + e.message);
                      if (btn) { btn.disabled = false; }
                  });
              };

              // ── Load GBP locations after OAuth ────────────────────
              function loadLocations() {
                  var listDiv = document.getElementById('locationList');
                  if (!listDiv) return;

                  fetch('/crm/api/social/accounts.php?action=locations')
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (!data.success) {
                              listDiv.innerHTML = '<div class="alert alert-danger">' + esc(data.error) + '</div>';
                              return;
                          }
                          if (!data.locations.length) {
                              listDiv.innerHTML = '<div class="alert alert-warning">No GBP locations found on this account. Make sure you have an active Google Business Profile.</div>';
                              return;
                          }
                          var html = '<div class="mw-soc-location-list">';
                          data.locations.forEach(function(l) {
                              html += '<div class="mw-soc-location-item" onclick="selectLocation(\'' + esc(l.location_name) + '\',\'' + esc(l.title) + '\',\'' + esc(l.address) + '\',\'' + esc(l.account_resource) + '\')">';
                              html += '  <div class="mw-soc-location-name">' + esc(l.title) + '</div>';
                              html += '  <div class="text-muted small">' + esc(l.address) + '</div>';
                              html += '  <div class="text-muted x-small">' + esc(l.location_name) + '</div>';
                              html += '</div>';
                          });
                          html += '</div>';
                          listDiv.innerHTML = html;
                      })
                      .catch(function(e) {
                          if (listDiv) listDiv.innerHTML = '<div class="alert alert-danger">Could not load locations: ' + esc(e.message) + '</div>';
                      });
              }

              window.selectLocation = function(locationName, title, address, accountResource) {
                  document.getElementById('selLocationName').value    = locationName;
                  document.getElementById('selAccountResource').value = accountResource;
                  document.getElementById('selLocationDisplay').value = title;
                  document.getElementById('selLocationAddress').value = address;
                  $('#locationModal').modal('show');
              };

              window.confirmConnect = function() {
                  var body = {
                      csrf_token:       csrf,
                      platform:         'gbp',
                      account_name:     document.getElementById('selLocationDisplay').value,
                      location_name:    document.getElementById('selLocationName').value,
                      location_display: document.getElementById('selLocationDisplay').value,
                  };

                  fetch('/crm/api/social/accounts.php?action=connect', {
                      method: 'POST',
                      headers: {'Content-Type': 'application/json'},
                      body: JSON.stringify(body)
                  }).then(function(r) { return r.json(); }).then(function(data) {
                      if (data.success) {
                          $('#locationModal').modal('hide');
                          window.location.href = '/crm/marketing/social-accounts.php?msg=' + encodeURIComponent(data.message || 'Connected!');
                      } else {
                          alert('Error: ' + (data.error || 'Unknown'));
                      }
                  });
              };

              // ── Account management ─────────────────────────────────
              window.toggleAccount = function(id) {
                  fetch('/crm/api/social/accounts.php?action=toggle', {
                      method: 'POST',
                      headers: {'Content-Type': 'application/json'},
                      body: JSON.stringify({id: id, csrf_token: csrf})
                  }).then(function(r) { return r.json(); }).then(function(data) {
                      if (data.success) loadAccounts();
                  });
              };

              window.disconnectAccount = function(id, name) {
                  if (!confirm('Disconnect "' + name + '"? Existing published posts will remain on the platform, but new posts cannot be published to this account.')) return;
                  fetch('/crm/api/social/accounts.php?action=disconnect', {
                      method: 'POST',
                      headers: {'Content-Type': 'application/json'},
                      body: JSON.stringify({id: id, csrf_token: csrf})
                  }).then(function(r) { return r.json(); }).then(function(data) {
                      if (data.success) loadAccounts();
                  });
              };

              // ── Load audit log ─────────────────────────────────────
              function loadAudit() {
                  var el = document.getElementById('auditLog');
                  if (!el) return;

                  fetch('/crm/api/social/accounts.php?action=audit')
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (!data.success || !data.log.length) {
                              el.innerHTML = '<div class="mw-soc-empty-state p-3"><p>No audit events yet.</p></div>';
                              return;
                          }
                          var html = '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Time</th><th>User</th><th>Action</th><th>Detail</th></tr></thead><tbody>';
                          data.log.forEach(function(entry) {
                              html += '<tr>';
                              html += '<td class="text-muted small">' + esc(formatDt(entry.created_at)) + '</td>';
                              html += '<td>' + esc(entry.user_name || '(system)') + '</td>';
                              html += '<td><code>' + esc(entry.action) + '</code></td>';
                              html += '<td class="small text-muted">' + esc(entry.detail || '') + '</td>';
                              html += '</tr>';
                          });
                          html += '</tbody></table></div>';
                          el.innerHTML = html;
                      });
              }

              // ── Helpers ────────────────────────────────────────────
              function esc(str) {
                  if (!str) return '';
                  var d = document.createElement('div');
                  d.appendChild(document.createTextNode(str));
                  return d.innerHTML;
              }
              function formatDt(dt) {
                  if (!dt) return '';
                  var d = new Date(dt.replace(' ', 'T'));
                  return d.toLocaleDateString('en-CA', {month:'short', day:'numeric', hour:'numeric', minute:'2-digit', hour12:true});
              }

              // ── Init ──────────────────────────────────────────────
              loadAccounts();
              if (canApprove) loadAudit();
              if (step === 'select_location' && platform === 'gbp') {
                  loadLocations();
              }
              if (step === 'select_page' && (platform === 'facebook' || platform === 'instagram')) {
                  loadPages();
              }
          })();
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
