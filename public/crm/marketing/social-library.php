<?php
/**
 * Social Marketing — Content Library
 *
 * Grid of reusable caption templates organized by category.
 * Create, edit, and delete templates. "Use" button opens post editor.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('marketing.view');

$pageTitle  = 'Content Library';
$activePage = 'social';
$canEdit    = userHasPermission('marketing.edit');
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <!-- Header -->
          <div class="d-flex justify-content-between align-items-center mb-4">
              <div>
                  <h1 class="h3 mb-0">Content Library</h1>
                  <p class="text-muted mb-0">
                      <a href="/crm/marketing/social.php" class="text-muted">Social</a>
                      <span class="mx-1">›</span> Templates
                  </p>
              </div>
              <?php if ($canEdit): ?>
              <button class="btn btn-success" onclick="openTemplateModal()">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  New Template
              </button>
              <?php endif; ?>
          </div>

          <!-- Filter tabs -->
          <div class="mw-camp-tabs mb-4" id="libTabs">
              <button class="mw-camp-tab active" data-cat="">All</button>
              <button class="mw-camp-tab" data-cat="proof_of_work">Proof of Work</button>
              <button class="mw-camp-tab" data-cat="upsell">Upsell</button>
              <button class="mw-camp-tab" data-cat="seasonal">Seasonal</button>
              <button class="mw-camp-tab" data-cat="announcement">Announcements</button>
              <button class="mw-camp-tab" data-cat="review_request">Review Requests</button>
          </div>

          <!-- Template grid -->
          <div id="libGrid">
              <div class="mw-soc-loading">Loading templates...</div>
          </div>

          <!-- ── Template Modal ─────────────────────────────────────────── -->
          <?php if ($canEdit): ?>
          <div class="modal fade" id="templateModal" tabindex="-1">
              <div class="modal-dialog modal-lg">
                  <div class="modal-content">
                      <div class="modal-header">
                          <h5 class="modal-title" id="tmplModalTitle">New Template</h5>
                          <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                      </div>
                      <div class="modal-body">
                          <input type="hidden" id="tmplId" value="0">
                          <div class="row">
                              <div class="col-md-8">
                                  <div class="form-group">
                                      <label>Template Name <span class="text-danger">*</span></label>
                                      <input type="text" class="form-control" id="tmplName" placeholder="e.g., Spring Lawn Care Upsell">
                                  </div>
                              </div>
                              <div class="col-md-4">
                                  <div class="form-group">
                                      <label>Category</label>
                                      <select class="form-control" id="tmplCategory">
                                          <option value="proof_of_work">Proof of Work</option>
                                          <option value="upsell">Upsell</option>
                                          <option value="seasonal">Seasonal</option>
                                          <option value="announcement">Announcement</option>
                                          <option value="review_request">Review Request</option>
                                      </select>
                                  </div>
                              </div>
                          </div>
                          <div class="form-group">
                              <label>
                                  Caption Template <span class="text-danger">*</span>
                                  <small class="text-muted ml-1">Variables: {neighborhood} {city} {service} {date} {crew_name}</small>
                              </label>
                              <div class="position-relative">
                                  <textarea class="form-control" id="tmplCaption" rows="8"
                                      placeholder="Write your caption here. Use {neighborhood}, {city}, {service} as placeholders."></textarea>
                                  <div class="mw-soc-char-count" id="tmplCharCount">0 / 1500</div>
                              </div>
                          </div>
                          <div class="form-group">
                              <label>Hashtag Preset</label>
                              <input type="text" class="form-control" id="tmplHashtags"
                                  placeholder="#VancouverLandscaping #Mowology #LawnCare">
                          </div>
                          <div class="row">
                              <div class="col-md-6">
                                  <div class="form-group">
                                      <label>Default Call-to-Action</label>
                                      <select class="form-control" id="tmplCta">
                                          <option value="">— None —</option>
                                          <option value="BOOK">Book Now</option>
                                          <option value="LEARN_MORE">Learn More</option>
                                          <option value="CALL">Call Us</option>
                                          <option value="SIGN_UP">Sign Up</option>
                                          <option value="ORDER">Order Online</option>
                                      </select>
                                  </div>
                              </div>
                              <div class="col-md-6">
                                  <div class="form-group">
                                      <label>Target Platforms</label>
                                      <input type="text" class="form-control" id="tmplPlatforms" value="gbp,facebook,instagram"
                                          placeholder="gbp,facebook,instagram">
                                  </div>
                              </div>
                          </div>

                          <!-- AI Caption Stub -->
                          <div class="mw-soc-ai-stub">
                              <div class="mw-soc-ai-icon">
                                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M5.34 18.66l-1.41 1.41M22 12h-2M4 12H2M19.07 19.07l-1.41-1.41M5.34 5.34L3.93 3.93"/></svg>
                              </div>
                              <div>
                                  <strong>Generate with AI</strong>
                                  <span class="text-muted small ml-2">— Coming soon. Claude will draft captions based on service type and season.</span>
                              </div>
                              <button class="btn btn-sm btn-outline-secondary ml-auto" onclick="alert('AI caption generation is coming in the next release!')">Try Beta</button>
                          </div>
                      </div>
                      <div class="modal-footer">
                          <button class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                          <button class="btn btn-success" onclick="saveTemplate()">Save Template</button>
                      </div>
                  </div>
              </div>
          </div>
          <?php endif; ?>

          <script>
          (function() {
              'use strict';

              var csrf       = '<?php echo generateCSRFToken(); ?>';
              var canEdit    = <?php echo json_encode($canEdit); ?>;
              var currentCat = '';

              var categoryLabels = {
                  proof_of_work:  'Proof of Work',
                  upsell:         'Upsell',
                  seasonal:       'Seasonal',
                  announcement:   'Announcement',
                  review_request: 'Review Request',
              };

              var categoryColors = {
                  proof_of_work:  '#2D8659',
                  upsell:         '#e85d04',
                  seasonal:       '#0077b6',
                  announcement:   '#7c3aed',
                  review_request: '#b45309',
              };

              var platformIcons = {
                  gbp:       '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>',
                  facebook:  '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"/></svg>',
                  instagram: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5"/><path d="M16 11.37A4 4 0 1112.63 8 4 4 0 0116 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>',
              };

              // ── Filter tabs ────────────────────────────────────────
              document.querySelectorAll('.mw-camp-tab').forEach(function(tab) {
                  tab.addEventListener('click', function() {
                      document.querySelectorAll('.mw-camp-tab').forEach(function(t) { t.classList.remove('active'); });
                      this.classList.add('active');
                      currentCat = this.dataset.cat;
                      loadTemplates();
                  });
              });

              // ── Load templates ─────────────────────────────────────
              function loadTemplates() {
                  var url = '/crm/api/social/accounts.php?action=templates';
                  if (currentCat) url += '&category=' + encodeURIComponent(currentCat);

                  fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                      if (!data.success) {
                          document.getElementById('libGrid').innerHTML = '<div class="alert alert-danger">' + esc(data.error) + '</div>';
                          return;
                      }
                      renderGrid(data.templates || []);
                  });
              }

              function renderGrid(templates) {
                  var container = document.getElementById('libGrid');
                  if (!templates.length) {
                      container.innerHTML = '<div class="mw-camp-empty">'
                          + '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>'
                          + '<p>No templates in this category.</p>'
                          + (canEdit ? '<button class="btn btn-success mt-2" onclick="openTemplateModal()">Create First Template</button>' : '')
                          + '</div>';
                      return;
                  }

                  var html = '<div class="mw-soc-tmpl-grid">';
                  templates.forEach(function(t) {
                      var color = categoryColors[t.category] || '#6c757d';
                      var catLabel = categoryLabels[t.category] || t.category;
                      var platforms = (t.platform_targets || '').split(',').map(function(p) {
                          return '<span class="mw-soc-platform-pill mw-soc-pl-' + esc(p.trim()) + '">' + (platformIcons[p.trim()] || '') + '</span>';
                      }).join('');

                      html += '<div class="mw-soc-tmpl-card">';
                      html += '  <div class="mw-soc-tmpl-stripe" style="background:' + color + '"></div>';
                      html += '  <div class="mw-soc-tmpl-body">';
                      html += '    <div class="mw-soc-tmpl-header">';
                      html += '      <span class="mw-soc-tmpl-cat" style="color:' + color + '">' + esc(catLabel) + '</span>';
                      html += '      <span class="mw-soc-tmpl-uses">' + (t.usage_count || 0) + ' uses</span>';
                      html += '    </div>';
                      html += '    <h6 class="mw-soc-tmpl-name">' + esc(t.name) + '</h6>';
                      html += '    <p class="mw-soc-tmpl-preview">' + esc(truncate(t.caption_template, 120)) + '</p>';
                      if (t.hashtag_preset) {
                          html += '    <div class="mw-soc-tmpl-hashtags">' + esc(truncate(t.hashtag_preset, 60)) + '</div>';
                      }
                      html += '    <div class="mw-soc-tmpl-footer">';
                      html += '      <div class="mw-soc-tmpl-platforms">' + platforms + '</div>';
                      html += '      <div class="mw-soc-tmpl-actions">';
                      html += '        <a href="/crm/marketing/social-post-editor.php?template=' + t.id + '" class="btn btn-sm btn-success">Use</a>';
                      if (canEdit) {
                          html += '        <button class="btn btn-sm btn-outline-secondary" onclick="editTemplate(' + t.id + ')">Edit</button>';
                          html += '        <button class="btn btn-sm btn-outline-danger" onclick="deleteTemplate(' + t.id + ')">Delete</button>';
                      }
                      html += '      </div>';
                      html += '    </div>';
                      html += '  </div>';
                      html += '</div>';
                  });
                  html += '</div>';
                  container.innerHTML = html;
              }

              // ── Template CRUD ──────────────────────────────────────
              window.openTemplateModal = function(data) {
                  document.getElementById('tmplId').value        = data ? data.id : 0;
                  document.getElementById('tmplName').value      = data ? data.name : '';
                  document.getElementById('tmplCategory').value  = data ? data.category : 'proof_of_work';
                  document.getElementById('tmplCaption').value   = data ? data.caption_template : '';
                  document.getElementById('tmplHashtags').value  = data ? (data.hashtag_preset || '') : '';
                  document.getElementById('tmplCta').value       = data ? (data.cta_preset || '') : '';
                  document.getElementById('tmplPlatforms').value = data ? (data.platform_targets || 'gbp,facebook,instagram') : 'gbp,facebook,instagram';
                  document.getElementById('tmplModalTitle').textContent = data ? 'Edit Template' : 'New Template';
                  updateCharCount();
                  $('#templateModal').modal('show');
              };

              window.editTemplate = function(id) {
                  fetch('/crm/api/social/accounts.php?action=templates')
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (!data.success) return;
                          var t = (data.templates || []).find(function(x) { return x.id == id; });
                          if (t) openTemplateModal(t);
                      });
              };

              window.saveTemplate = function() {
                  var body = {
                      csrf_token:       csrf,
                      id:               parseInt(document.getElementById('tmplId').value) || 0,
                      name:             document.getElementById('tmplName').value,
                      category:         document.getElementById('tmplCategory').value,
                      caption_template: document.getElementById('tmplCaption').value,
                      hashtag_preset:   document.getElementById('tmplHashtags').value,
                      cta_preset:       document.getElementById('tmplCta').value,
                      platform_targets: document.getElementById('tmplPlatforms').value,
                  };

                  fetch('/crm/api/social/accounts.php?action=save-template', {
                      method: 'POST',
                      headers: {'Content-Type': 'application/json'},
                      body: JSON.stringify(body)
                  }).then(function(r) { return r.json(); }).then(function(data) {
                      if (data.success) {
                          $('#templateModal').modal('hide');
                          loadTemplates();
                      } else {
                          alert('Error: ' + (data.error || 'Unknown'));
                      }
                  });
              };

              window.deleteTemplate = function(id) {
                  if (!confirm('Delete this template? It will no longer appear in the library.')) return;
                  fetch('/crm/api/social/accounts.php?action=delete-template', {
                      method: 'POST',
                      headers: {'Content-Type': 'application/json'},
                      body: JSON.stringify({id: id, csrf_token: csrf})
                  }).then(function(r) { return r.json(); }).then(function(data) {
                      if (data.success) loadTemplates();
                      else alert('Error: ' + (data.error || 'Unknown'));
                  });
              };

              // ── Char counter ───────────────────────────────────────
              function updateCharCount() {
                  var el = document.getElementById('tmplCaption');
                  if (!el) return;
                  var len = (el.value || '').length;
                  var counter = document.getElementById('tmplCharCount');
                  if (counter) {
                      counter.textContent = len + ' / 1500';
                      counter.style.color = len > 1500 ? '#dc3545' : '';
                  }
              }
              var capt = document.getElementById('tmplCaption');
              if (capt) capt.addEventListener('input', updateCharCount);

              // ── Helpers ────────────────────────────────────────────
              function esc(str) {
                  if (!str) return '';
                  var d = document.createElement('div');
                  d.appendChild(document.createTextNode(str));
                  return d.innerHTML;
              }
              function truncate(s, n) { return s && s.length > n ? s.substring(0, n) + '…' : (s || ''); }

              loadTemplates();
          })();
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
