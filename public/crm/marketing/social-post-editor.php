<?php
/**
 * Social Post Editor — Create and Edit social posts.
 *
 * Query params:
 *   id=N           Edit existing post
 *   template=N     Pre-fill from template
 *   scheduled=DT   Pre-set scheduled datetime
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('marketing.edit');

$editId     = (int)($_GET['id']       ?? 0);
$templateId = (int)($_GET['template'] ?? 0);
$preSchedule = htmlspecialchars($_GET['scheduled'] ?? '');

$pageTitle  = $editId ? 'Edit Post' : 'New Post';
$activePage = 'social';
$canApprove = userHasPermission('marketing.approve');
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <!-- Header -->
          <div class="d-flex justify-content-between align-items-center mb-4">
              <div>
                  <h1 class="h3 mb-0"><?php echo $editId ? 'Edit Post' : 'New Post'; ?></h1>
                  <p class="text-muted mb-0">
                      <a href="/crm/marketing/social.php" class="text-muted">Social</a>
                      <span class="mx-1">›</span>
                      <?php echo $editId ? 'Edit #' . $editId : 'Create'; ?>
                  </p>
              </div>
              <a href="/crm/marketing/social-calendar.php" class="btn btn-outline-secondary">
                  Back to Calendar
              </a>
          </div>

          <div class="row">
              <!-- LEFT: Editor -->
              <div class="col-lg-7">

                  <!-- Caption -->
                  <div class="card mb-3">
                      <div class="card-header d-flex justify-content-between align-items-center">
                          <h5 class="mb-0">Caption</h5>
                          <div class="d-flex gap-2">
                              <!-- Template picker -->
                              <select class="form-control form-control-sm" id="templatePicker" style="width:auto;" onchange="loadTemplate(this.value)">
                                  <option value="">— Use a template —</option>
                              </select>
                              <!-- Variable inserter -->
                              <div class="dropdown">
                                  <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-toggle="dropdown">
                                      Insert Variable
                                  </button>
                                  <div class="dropdown-menu">
                                      <a class="dropdown-item" href="#" onclick="insertVar('{neighborhood}');return false;">{neighborhood}</a>
                                      <a class="dropdown-item" href="#" onclick="insertVar('{city}');return false;">{city}</a>
                                      <a class="dropdown-item" href="#" onclick="insertVar('{service}');return false;">{service}</a>
                                      <a class="dropdown-item" href="#" onclick="insertVar('{date}');return false;">{date}</a>
                                      <a class="dropdown-item" href="#" onclick="insertVar('{crew_name}');return false;">{crew_name}</a>
                                  </div>
                              </div>
                          </div>
                      </div>
                      <div class="card-body">
                          <div class="form-group mb-2">
                              <input type="text" class="form-control" id="postTitle" placeholder="Internal title (not published)">
                          </div>
                          <div class="position-relative">
                              <textarea class="form-control" id="postCaption" rows="8"
                                  placeholder="Write your caption here. Use {neighborhood}, {city}, {service} to personalise each post."></textarea>
                              <div class="mw-soc-char-count" id="charCount">0 / 1500 (GBP)</div>
                          </div>
                      </div>
                  </div>

                  <!-- Hashtags -->
                  <div class="card mb-3">
                      <div class="card-header">
                          <h5 class="mb-0">Hashtags</h5>
                      </div>
                      <div class="card-body">
                          <textarea class="form-control" id="postHashtags" rows="2"
                              placeholder="#VancouverLandscaping #Mowology #LawnCare"></textarea>
                          <small class="text-muted">Space-separated. Will be appended to caption when publishing.</small>
                      </div>
                  </div>

                  <!-- Targeting -->
                  <div class="card mb-3">
                      <div class="card-header"><h5 class="mb-0">Context &amp; Targeting</h5></div>
                      <div class="card-body">
                          <div class="row">
                              <div class="col-md-6">
                                  <div class="form-group">
                                      <label>Neighbourhood</label>
                                      <input type="text" class="form-control" id="postNeighborhood" placeholder="e.g., Kits, South Granville">
                                  </div>
                              </div>
                              <div class="col-md-6">
                                  <div class="form-group">
                                      <label>City</label>
                                      <input type="text" class="form-control" id="postCity" value="Vancouver">
                                  </div>
                              </div>
                          </div>
                          <div class="row">
                              <div class="col-md-6">
                                  <div class="form-group">
                                      <label>Service Type</label>
                                      <select class="form-control" id="postServiceType">
                                          <option value="">— Select service —</option>
                                          <option value="lawn_maintenance">Lawn Maintenance</option>
                                          <option value="fertilizer">Fertilization</option>
                                          <option value="aeration">Aeration</option>
                                          <option value="power_rake">Power Raking</option>
                                          <option value="hedge_trimming">Hedge Trimming</option>
                                          <option value="spring_cleanup">Spring Cleanup</option>
                                          <option value="fall_cleanup">Fall Cleanup</option>
                                          <option value="general">General Landscaping</option>
                                      </select>
                                  </div>
                              </div>
                              <div class="col-md-6">
                                  <div class="form-group">
                                      <label>Call-to-Action</label>
                                      <select class="form-control" id="postCta">
                                          <option value="">— None —</option>
                                          <option value="BOOK">Book Now</option>
                                          <option value="LEARN_MORE">Learn More</option>
                                          <option value="CALL">Call Us</option>
                                          <option value="SIGN_UP">Sign Up</option>
                                      </select>
                                  </div>
                              </div>
                          </div>
                          <div class="form-group mb-0">
                              <label>CTA URL</label>
                              <input type="url" class="form-control" id="postCtaUrl"
                                  placeholder="https://mowology.ca/quote?service=lawn&src=gbp">
                              <small class="text-muted">UTM params will be appended automatically when publishing.</small>
                          </div>
                      </div>
                  </div>

                  <!-- Platforms -->
                  <div class="card mb-3">
                      <div class="card-header"><h5 class="mb-0">Publish To</h5></div>
                      <div class="card-body">
                          <div id="platformToggles">
                              <div class="mw-soc-loading">Loading connected accounts...</div>
                          </div>
                      </div>
                  </div>

                  <!-- Schedule -->
                  <div class="card mb-3">
                      <div class="card-header"><h5 class="mb-0">Schedule</h5></div>
                      <div class="card-body">
                          <div class="row align-items-center">
                              <div class="col-md-6">
                                  <div class="form-group mb-0">
                                      <label>Publish Date &amp; Time</label>
                                      <input type="datetime-local" class="form-control" id="postSchedule"
                                          value="<?php echo $preSchedule; ?>">
                                      <small class="text-muted">Leave blank to save as draft without scheduling.</small>
                                  </div>
                              </div>
                              <div class="col-md-6">
                                  <div class="mw-soc-time-presets">
                                      <label class="d-block">Quick presets</label>
                                      <button class="btn btn-sm btn-outline-secondary" onclick="setPreset(9,0)">9am Today</button>
                                      <button class="btn btn-sm btn-outline-secondary" onclick="setPreset(9,1)">9am Tomorrow</button>
                                      <button class="btn btn-sm btn-outline-secondary" onclick="setPreset(12,0)">Noon Today</button>
                                  </div>
                              </div>
                          </div>
                      </div>
                  </div>

              </div>

              <!-- RIGHT: Media + Preview -->
              <div class="col-lg-5">

                  <!-- Media Library Picker -->
                  <div class="card mb-3">
                      <div class="card-header d-flex justify-content-between align-items-center">
                          <h5 class="mb-0">Attach Photos</h5>
                          <div class="d-flex align-items-center gap-2">
                              <span class="text-muted small" id="mediaSelectedCount">0 selected</span>
                              <button class="btn btn-sm btn-outline-primary" onclick="document.getElementById('mediaUploadInput').click()" title="Upload image">
                                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                  Upload
                              </button>
                              <input type="file" id="mediaUploadInput" accept="image/*" multiple style="display:none" onchange="uploadMediaFiles(this)">
                          </div>
                      </div>
                      <div class="card-body">
                          <div id="mediaUploadProgress" style="display:none" class="mb-2">
                              <div class="progress" style="height:4px"><div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width:100%"></div></div>
                              <div class="text-muted small mt-1">Uploading...</div>
                          </div>
                          <input type="text" class="form-control form-control-sm mb-2" id="mediaSearch"
                              placeholder="Search photos..." oninput="searchMedia(this.value)">
                          <div class="mw-soc-media-grid" id="mediaGrid">
                              <div class="mw-soc-loading">Loading photos...</div>
                          </div>
                          <div id="selectedMediaIds" style="display:none;"></div>
                      </div>
                  </div>

                  <!-- Post Preview -->
                  <div class="card mb-3">
                      <div class="card-header d-flex justify-content-between align-items-center">
                          <h5 class="mb-0">Preview</h5>
                          <div class="btn-group btn-group-sm" id="previewTabs">
                              <button class="btn btn-outline-secondary active" data-platform="gbp" onclick="switchPreview('gbp',this)">GBP</button>
                              <button class="btn btn-outline-secondary" data-platform="instagram" onclick="switchPreview('instagram',this)">Instagram</button>
                              <button class="btn btn-outline-secondary" data-platform="facebook" onclick="switchPreview('facebook',this)">Facebook</button>
                          </div>
                      </div>
                      <div class="card-body p-0">
                          <div class="mw-soc-preview" id="postPreview">
                              <div class="mw-soc-preview-placeholder">
                                  <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                  <p>Start typing to see a preview</p>
                              </div>
                          </div>
                      </div>
                  </div>

                  <!-- Status + Action Buttons -->
                  <div class="card mw-soc-action-card">
                      <div class="card-body">
                          <div class="mw-soc-status-row mb-3" id="currentStatusRow"></div>
                          <div class="d-grid gap-2">
                              <?php if ($canApprove): ?>
                              <button class="btn btn-success btn-lg" onclick="schedulePost()" id="btnSchedule">
                                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                  Approve &amp; Schedule
                              </button>
                              <?php endif; ?>
                              <button class="btn btn-outline-success" onclick="savePost('pending_approval')" id="btnSubmit">
                                  Submit for Approval
                              </button>
                              <button class="btn btn-outline-secondary" onclick="savePost('draft')">
                                  Save Draft
                              </button>
                              <?php if ($editId && $canApprove): ?>
                              <button class="btn btn-outline-danger" onclick="cancelPost()" id="btnCancel">
                                  Cancel Post
                              </button>
                              <?php endif; ?>
                          </div>
                      </div>
                  </div>

              </div>
          </div>

          <script>
          (function() {
              'use strict';

              var csrf       = '<?php echo generateCSRFToken(); ?>';
              var editId     = <?php echo $editId; ?>;
              var templateId = <?php echo $templateId; ?>;
              var canApprove = <?php echo json_encode($canApprove); ?>;
              var selectedMedia = [];
              var selectedAccounts = []; // [{platform, account_id}]
              var previewPlatform = 'gbp';
              var charLimits = { gbp: 1500, instagram: 2200, facebook: 63206 };

              // ── Init ──────────────────────────────────────────────
              loadTemplateOptions();
              loadAccounts();
              loadMedia('');

              if (editId) {
                  loadPost(editId);
              } else if (templateId) {
                  loadTemplateById(templateId);
              }

              // ── Load existing post ─────────────────────────────────
              function loadPost(id) {
                  fetch('/crm/api/social/posts.php?action=get&id=' + id)
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (!data.success) { alert('Could not load post: ' + data.error); return; }
                          var p = data.post;
                          document.getElementById('postTitle').value       = p.title || '';
                          document.getElementById('postCaption').value     = p.caption || '';
                          document.getElementById('postHashtags').value    = p.hashtags || '';
                          document.getElementById('postNeighborhood').value = p.neighborhood || '';
                          document.getElementById('postCity').value        = p.city || 'Vancouver';
                          document.getElementById('postServiceType').value = p.service_type || '';
                          document.getElementById('postCta').value         = p.cta_action || '';
                          document.getElementById('postCtaUrl').value      = p.cta_url || '';
                          if (p.scheduled_at) {
                              document.getElementById('postSchedule').value = p.scheduled_at.replace(' ', 'T').substring(0, 16);
                          }

                          // Pre-select media
                          selectedMedia = (p.media || []).map(function(m) { return m.media_id; });
                          updateMediaSelection();

                          // Pre-select platforms
                          selectedAccounts = (p.platforms || []).map(function(pp) {
                              return {platform: pp.platform, account_id: pp.account_id};
                          });

                          // Show current status
                          var statusMap = {
                              draft: 'Draft', pending_approval: 'Awaiting Approval',
                              approved: 'Approved', scheduled: 'Scheduled', published: 'Published', failed: 'Failed'
                          };
                          var statusRow = document.getElementById('currentStatusRow');
                          if (p.status) {
                              statusRow.innerHTML = '<span class="text-muted small">Current status:</span> '
                                  + '<span class="mw-soc-badge mw-soc-badge-' + p.status + '">' + esc(statusMap[p.status] || p.status) + '</span>';
                          }

                          updateCharCount();
                          updatePreview();
                      });
              }

              // ── Load template options ──────────────────────────────
              function loadTemplateOptions() {
                  fetch('/crm/api/social/accounts.php?action=templates')
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (!data.success) return;
                          var sel = document.getElementById('templatePicker');
                          (data.templates || []).forEach(function(t) {
                              var opt = document.createElement('option');
                              opt.value = t.id;
                              opt.textContent = t.name + ' (' + (t.category || '') + ')';
                              opt.dataset.caption  = t.caption_template;
                              opt.dataset.hashtags = t.hashtag_preset || '';
                              opt.dataset.cta      = t.cta_preset || '';
                              sel.appendChild(opt);
                          });
                          // Pre-select if templateId passed
                          if (templateId) {
                              sel.value = templateId;
                              loadTemplateById(templateId, data.templates);
                          }
                      });
              }

              function loadTemplateById(id, templates) {
                  if (templates) {
                      var t = templates.find(function(x) { return x.id == id; });
                      if (t) applyTemplate(t);
                  } else {
                      fetch('/crm/api/social/accounts.php?action=templates')
                          .then(function(r) { return r.json(); })
                          .then(function(data) {
                              var t = (data.templates || []).find(function(x) { return x.id == id; });
                              if (t) applyTemplate(t);
                          });
                  }
              }

              window.loadTemplate = function(id) {
                  if (!id) return;
                  var sel = document.getElementById('templatePicker');
                  var opt = sel.querySelector('option[value="' + id + '"]');
                  if (opt) {
                      document.getElementById('postCaption').value  = opt.dataset.caption || '';
                      document.getElementById('postHashtags').value = opt.dataset.hashtags || '';
                      document.getElementById('postCta').value      = opt.dataset.cta || '';
                  }
                  updateCharCount();
                  updatePreview();
              };

              function applyTemplate(t) {
                  document.getElementById('postCaption').value   = t.caption_template || '';
                  document.getElementById('postHashtags').value  = t.hashtag_preset || '';
                  document.getElementById('postCta').value       = t.cta_preset || '';
                  document.getElementById('postCtaUrl').value    = t.cta_url_preset || '';
                  updateCharCount();
                  updatePreview();
              }

              // ── Load connected accounts ────────────────────────────
              function loadAccounts() {
                  fetch('/crm/api/social/accounts.php?action=list')
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          var container = document.getElementById('platformToggles');
                          if (!data.success || !data.accounts.length) {
                              container.innerHTML = '<div class="alert alert-warning mb-0">'
                                  + 'No platforms connected. <a href="/crm/marketing/social-accounts.php">Connect an account →</a>'
                                  + '</div>';
                              return;
                          }

                          var platformNames = { gbp: 'Google Business Profile', facebook: 'Facebook Page', instagram: 'Instagram Business', linkedin: 'LinkedIn' };
                          var html = '';
                          data.accounts.forEach(function(a) {
                              var checked = selectedAccounts.some(function(x) { return x.account_id == a.id; });
                              html += '<div class="mw-soc-platform-toggle">';
                              html += '  <label class="d-flex align-items-center gap-2 mb-0 cursor-pointer">';
                              html += '    <input type="checkbox" class="mw-soc-platform-chk" data-platform="' + esc(a.platform) + '" data-account="' + a.id + '"'
                                   + (checked ? ' checked' : '') + ' onchange="updateSelectedAccounts()">';
                              html += '    <span class="mw-soc-platform-pill mw-soc-pl-' + esc(a.platform) + '">'
                                   + platformIcons[a.platform] + '</span>';
                              html += '    <strong>' + esc(a.location_name_display || a.account_name) + '</strong>';
                              html += '    <span class="text-muted small">(' + esc(platformNames[a.platform] || a.platform) + ')</span>';
                              html += '  </label>';
                              html += '</div>';
                          });
                          container.innerHTML = html;
                      });
              }

              window.updateSelectedAccounts = function() {
                  selectedAccounts = [];
                  document.querySelectorAll('.mw-soc-platform-chk:checked').forEach(function(chk) {
                      selectedAccounts.push({
                          platform:   chk.dataset.platform,
                          account_id: parseInt(chk.dataset.account),
                      });
                  });
              };

              // ── Load media ─────────────────────────────────────────
              function loadMedia(query) {
                  var url = '/crm/api/social/posts.php?action=media-pick&limit=24';
                  if (query) url += '&q=' + encodeURIComponent(query);

                  fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                      var grid = document.getElementById('mediaGrid');
                      if (!data.success || !data.media.length) {
                          grid.innerHTML = '<div class="text-muted text-center p-3 small">No photos found. Click <strong>Upload</strong> to add images.</div>';
                          return;
                      }
                      var html = '';
                      data.media.forEach(function(m) {
                          var sel = selectedMedia.indexOf(m.id) !== -1;
                          html += '<div class="mw-soc-media-item' + (sel ? ' selected' : '') + '" data-id="' + m.id + '" onclick="toggleMedia(' + m.id + ',\'' + esc(m.url) + '\')">';
                          html += '  <img src="' + esc(m.url) + '" alt="' + esc(m.alt_text || '') + '" loading="lazy">';
                          html += '  <div class="mw-soc-media-check"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg></div>';
                          html += '</div>';
                      });
                      grid.innerHTML = html;
                  });
              }

              window.searchMedia = function(q) {
                  clearTimeout(window._mediaTimer);
                  window._mediaTimer = setTimeout(function() { loadMedia(q); }, 300);
              };

              window.uploadMediaFiles = function(input) {
                  if (!input.files || !input.files.length) return;
                  var progress = document.getElementById('mediaUploadProgress');
                  if (progress) progress.style.display = '';

                  var fd = new FormData();
                  fd.append('csrf_token', csrf);
                  fd.append('context_type', 'marketing_general');
                  fd.append('context_id', '0');
                  fd.append('visibility', 'marketing_eligible');
                  for (var i = 0; i < input.files.length; i++) {
                      fd.append('files[]', input.files[i]);
                  }

                  fetch('/crm/api/media-upload.php', { method: 'POST', body: fd })
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (progress) progress.style.display = 'none';
                          input.value = ''; // reset so same file can be re-uploaded
                          if (!data.success && !data.results) {
                              alert('Upload failed: ' + (data.error || 'Unknown error'));
                              return;
                          }
                          // Auto-select newly uploaded images
                          var results = data.results || [];
                          results.forEach(function(r) {
                              if (r.success && r.media_id && selectedMedia.indexOf(r.media_id) === -1) {
                                  if (selectedMedia.length < 10) selectedMedia.push(r.media_id);
                              }
                          });
                          // Refresh the grid (search cleared so new uploads appear)
                          document.getElementById('mediaSearch').value = '';
                          loadMedia('');
                      })
                      .catch(function(e) {
                          if (progress) progress.style.display = 'none';
                          alert('Upload error: ' + e.message);
                      });
              };

              window.toggleMedia = function(id, url) {
                  var idx = selectedMedia.indexOf(id);
                  if (idx === -1) {
                      if (selectedMedia.length >= 10) {
                          alert('Maximum 10 photos per post');
                          return;
                      }
                      selectedMedia.push(id);
                  } else {
                      selectedMedia.splice(idx, 1);
                  }
                  updateMediaSelection();
                  updatePreview();
              };

              function updateMediaSelection() {
                  document.getElementById('mediaSelectedCount').textContent = selectedMedia.length + ' selected';
                  document.querySelectorAll('.mw-soc-media-item').forEach(function(el) {
                      var id = parseInt(el.dataset.id);
                      el.classList.toggle('selected', selectedMedia.indexOf(id) !== -1);
                  });
              }

              // ── Preview ────────────────────────────────────────────
              var platformIcons = {
                  gbp:       '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>',
                  facebook:  '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"/></svg>',
                  instagram: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5"/><path d="M16 11.37A4 4 0 1112.63 8 4 4 0 0116 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>',
              };

              window.switchPreview = function(platform, btn) {
                  previewPlatform = platform;
                  document.querySelectorAll('#previewTabs .btn').forEach(function(b) { b.classList.remove('active'); });
                  btn.classList.add('active');
                  updatePreview();
              };

              function updatePreview() {
                  var caption  = document.getElementById('postCaption').value;
                  var hashtags = document.getElementById('postHashtags').value;
                  var cta      = document.getElementById('postCta').value;
                  var preview  = document.getElementById('postPreview');

                  if (!caption.trim()) {
                      preview.innerHTML = '<div class="mw-soc-preview-placeholder"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg><p>Start typing to see a preview</p></div>';
                      return;
                  }

                  var fullText = caption + (hashtags ? '\n\n' + hashtags : '');

                  // Build first image preview
                  var imgHtml = '';
                  var firstMedia = document.querySelector('.mw-soc-media-item.selected img');
                  if (firstMedia) {
                      imgHtml = '<div class="mw-soc-preview-image"><img src="' + esc(firstMedia.src) + '" alt=""></div>';
                  }

                  var ctaLabels = {BOOK: 'Book Now', LEARN_MORE: 'Learn More', CALL: 'Call Us', SIGN_UP: 'Sign Up'};
                  var ctaHtml = cta ? '<div class="mw-soc-preview-cta">' + esc(ctaLabels[cta] || cta) + '</div>' : '';

                  if (previewPlatform === 'gbp') {
                      preview.innerHTML = '<div class="mw-soc-prev-gbp">'
                          + '<div class="mw-soc-prev-gbp-header"><span class="mw-soc-prev-biz">Mowology</span><span class="text-muted small">Google Business</span></div>'
                          + imgHtml
                          + '<div class="mw-soc-prev-body">' + nl2br(esc(fullText)) + '</div>'
                          + ctaHtml
                          + '</div>';
                  } else if (previewPlatform === 'instagram') {
                      preview.innerHTML = '<div class="mw-soc-prev-ig">'
                          + '<div class="mw-soc-prev-ig-header">'
                          + platformIcons.instagram
                          + ' <strong>mowology</strong></div>'
                          + imgHtml
                          + '<div class="mw-soc-prev-ig-likes">♥ 0 likes</div>'
                          + '<div class="mw-soc-prev-body"><strong>mowology</strong> ' + nl2br(esc(fullText)) + '</div>'
                          + '</div>';
                  } else {
                      preview.innerHTML = '<div class="mw-soc-prev-fb">'
                          + '<div class="mw-soc-prev-fb-header"><strong>Mowology Landscaping</strong>'
                          + '<div class="text-muted small">Just now · ' + platformIcons.facebook + '</div></div>'
                          + '<div class="mw-soc-prev-body">' + nl2br(esc(fullText)) + '</div>'
                          + imgHtml
                          + ctaHtml
                          + '</div>';
                  }
              }

              // ── Char counter ───────────────────────────────────────
              function updateCharCount() {
                  var len    = (document.getElementById('postCaption').value || '').length;
                  var limit  = charLimits[previewPlatform] || 1500;
                  var el     = document.getElementById('charCount');
                  var label  = { gbp: 'GBP', instagram: 'Instagram', facebook: 'Facebook' }[previewPlatform] || previewPlatform;
                  el.textContent = len + ' / ' + limit + ' (' + label + ')';
                  el.style.color = len > limit ? '#dc3545' : '';
              }

              document.getElementById('postCaption').addEventListener('input', function() {
                  updateCharCount();
                  updatePreview();
              });
              document.getElementById('postHashtags').addEventListener('input', updatePreview);

              // ── Schedule presets ───────────────────────────────────
              window.setPreset = function(hour, daysAhead) {
                  var d = new Date();
                  d.setDate(d.getDate() + daysAhead);
                  d.setHours(hour, 0, 0, 0);
                  var pad = function(n) { return n < 10 ? '0' + n : n; };
                  var val = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
                          + 'T' + pad(hour) + ':00';
                  document.getElementById('postSchedule').value = val;
              };

              // ── Insert variable ────────────────────────────────────
              window.insertVar = function(v) {
                  var ta = document.getElementById('postCaption');
                  var start = ta.selectionStart;
                  ta.value = ta.value.substring(0, start) + v + ta.value.substring(ta.selectionEnd);
                  ta.focus();
                  ta.selectionStart = ta.selectionEnd = start + v.length;
                  updateCharCount();
                  updatePreview();
              };

              // ── Save post ──────────────────────────────────────────
              function buildPayload(status) {
                  return {
                      csrf_token:        csrf,
                      id:                editId,
                      title:             document.getElementById('postTitle').value,
                      caption:           document.getElementById('postCaption').value,
                      hashtags:          document.getElementById('postHashtags').value,
                      neighborhood:      document.getElementById('postNeighborhood').value,
                      city:              document.getElementById('postCity').value,
                      service_type:      document.getElementById('postServiceType').value,
                      cta_action:        document.getElementById('postCta').value,
                      cta_url:           document.getElementById('postCtaUrl').value,
                      scheduled_at:      document.getElementById('postSchedule').value || null,
                      status:            status,
                      media_ids:         selectedMedia,
                      platform_accounts: selectedAccounts,
                  };
              }

              window.savePost = function(status) {
                  var payload = buildPayload(status);
                  if (!payload.caption.trim()) { alert('Caption is required'); return; }
                  if (!selectedAccounts.length) { alert('Select at least one platform to publish to'); return; }

                  fetch('/crm/api/social/posts.php?action=save', {
                      method: 'POST',
                      headers: {'Content-Type': 'application/json'},
                      body: JSON.stringify(payload)
                  }).then(function(r) { return r.json(); }).then(function(data) {
                      if (data.success) {
                          var msg = status === 'pending_approval' ? 'Submitted for approval!' : 'Draft saved!';
                          window.location.href = '/crm/marketing/social.php?msg=' + encodeURIComponent(msg);
                      } else {
                          alert('Error: ' + (data.error || 'Unknown'));
                      }
                  });
              };

              window.schedulePost = function() {
                  var scheduledAt = document.getElementById('postSchedule').value;
                  if (!scheduledAt) { alert('Set a date and time first'); return; }

                  var payload = buildPayload('approved');
                  if (!payload.caption.trim()) { alert('Caption is required'); return; }
                  if (!selectedAccounts.length) { alert('Select at least one platform'); return; }

                  // First save, then schedule
                  fetch('/crm/api/social/posts.php?action=save', {
                      method: 'POST',
                      headers: {'Content-Type': 'application/json'},
                      body: JSON.stringify(payload)
                  }).then(function(r) { return r.json(); }).then(function(data) {
                      if (!data.success) { alert('Save error: ' + data.error); return; }

                      var pid = data.id;
                      return fetch('/crm/api/social/posts.php?action=schedule', {
                          method: 'POST',
                          headers: {'Content-Type': 'application/json'},
                          body: JSON.stringify({id: pid, scheduled_at: scheduledAt, csrf_token: csrf})
                      }).then(function(r) { return r.json(); }).then(function(sData) {
                          if (sData.success) {
                              window.location.href = '/crm/marketing/social.php?msg=' + encodeURIComponent('Post scheduled!');
                          } else {
                              alert('Schedule error: ' + (sData.error || 'Unknown'));
                          }
                      });
                  });
              };

              window.cancelPost = function() {
                  if (!confirm('Cancel this post? It will no longer publish.')) return;
                  fetch('/crm/api/social/posts.php?action=delete', {
                      method: 'POST',
                      headers: {'Content-Type': 'application/json'},
                      body: JSON.stringify({id: editId, csrf_token: csrf})
                  }).then(function(r) { return r.json(); }).then(function(data) {
                      if (data.success) window.location.href = '/crm/marketing/social.php';
                  });
              };

              // ── Helpers ────────────────────────────────────────────
              function esc(str) {
                  if (!str) return '';
                  var d = document.createElement('div');
                  d.appendChild(document.createTextNode(str));
                  return d.innerHTML;
              }
              function nl2br(str) {
                  return str.replace(/\n/g, '<br>');
              }

              // Initial char count
              updateCharCount();
          })();
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
