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
                              <div class="col-md-2">
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
                              <div class="col-md-2">
                                  <div class="form-group">
                                      <label>Service Type</label>
                                      <select class="form-control" id="tmplServiceType">
                                          <option value="">— Any —</option>
                                          <option value="Lawn Care">Lawn Care</option>
                                          <option value="Hedge Trimming">Hedge Trimming</option>
                                          <option value="General Landscaping">General Landscaping</option>
                                          <option value="Fall Cleanup">Fall Cleanup</option>
                                          <option value="Spring Cleanup">Spring Cleanup</option>
                                          <option value="Snow Removal">Snow Removal</option>
                                          <option value="Fertilizing">Fertilizing</option>
                                          <option value="Aeration">Aeration</option>
                                          <option value="Pressure Washing">Pressure Washing</option>
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

          <!-- ── Template Campaign Modal ──────────────────────────────── -->
          <div class="modal fade" id="tmplCampaignModal" tabindex="-1">
              <div class="modal-dialog modal-lg">
                  <div class="modal-content">
                      <div class="modal-header" style="background:linear-gradient(135deg,#f0f9f4,#e8f3ee);border-bottom:1px solid #b8dfc8;">
                          <div style="min-width:0;flex:1;">
                              <h5 class="modal-title mb-0">Create Campaign</h5>
                              <div class="text-muted small" id="tmplCampTemplateName" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>
                          </div>
                          <button type="button" class="close ml-3" data-dismiss="modal"><span>&times;</span></button>
                      </div>
                      <div class="modal-body">

                          <!-- Caption preview -->
                          <div id="tmplCampCaption" style="background:#f8f9fa;border-left:3px solid var(--mw-green);padding:10px 14px;border-radius:4px;font-size:.85rem;color:#495057;margin-bottom:1rem;max-height:72px;overflow:hidden;position:relative;"></div>

                          <!-- Platform/account selector -->
                          <div class="form-group">
                              <label class="font-weight-bold">Publish to</label>
                              <div id="tmplCampAccounts" class="mw-soc-account-checkboxes">
                                  <div class="text-muted small">Loading accounts…</div>
                              </div>
                          </div>

                          <!-- Cadence -->
                          <div class="form-group mb-2">
                              <label class="font-weight-bold mb-1">Cadence</label>
                              <div class="mw-soc-cadence-pills" id="tmplCadencePills">
                                  <button class="mw-soc-cadence-pill active" onclick="setTmplCadencePill('seasonal',0,this)">🌿 Seasonal smart</button>
                                  <button class="mw-soc-cadence-pill" onclick="setTmplCadencePill('fixed',14,this)">Every 2 weeks</button>
                                  <button class="mw-soc-cadence-pill" onclick="setTmplCadencePill('fixed',7,this)">Weekly</button>
                              </div>
                              <div class="mw-soc-cadence-desc mt-1" id="tmplCadenceDesc">Posts more often during peak landscaping season (Apr–Jul), less in winter.</div>
                          </div>

                          <!-- Look-ahead months -->
                          <div class="form-group mb-3">
                              <label class="font-weight-bold mb-1">Schedule ahead</label>
                              <div class="mw-soc-cadence-pills" id="tmplMonthPills">
                                  <button class="mw-soc-cadence-pill" onclick="setTmplMonthPill(3,this)">3 months</button>
                                  <button class="mw-soc-cadence-pill active" onclick="setTmplMonthPill(6,this)">6 months</button>
                                  <button class="mw-soc-cadence-pill" onclick="setTmplMonthPill(12,this)">12 months</button>
                              </div>
                          </div>

                          <!-- Slot preview -->
                          <div class="mw-soc-campaign-preview" id="tmplCampPreview">
                              <div class="text-muted small text-center py-2">Select accounts then click Preview slots</div>
                          </div>
                      </div>
                      <div class="modal-footer">
                          <button class="btn btn-sm btn-outline-secondary" onclick="loadTmplCampaignPreview()">
                              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-1"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>
                              Preview slots
                          </button>
                          <button class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                          <button class="btn btn-success" onclick="confirmTemplateCampaign()" id="btnTmplCampConfirm" disabled>
                              Create Campaign
                          </button>
                      </div>
                  </div>
              </div>
          </div>

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
                      html += '        <button class="btn btn-sm btn-outline-primary" onclick="openTemplateCampaign(' + t.id + ')" title="Create a recurring campaign from this template">'
                           +  '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="mr-1"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>'
                           +  'Schedule</button>';
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
                  document.getElementById('tmplId').value          = data ? data.id : 0;
                  document.getElementById('tmplName').value        = data ? data.name : '';
                  document.getElementById('tmplCategory').value    = data ? data.category : 'proof_of_work';
                  document.getElementById('tmplServiceType').value = data ? (data.service_type || '') : '';
                  document.getElementById('tmplCaption').value     = data ? data.caption_template : '';
                  document.getElementById('tmplHashtags').value    = data ? (data.hashtag_preset || '') : '';
                  document.getElementById('tmplCta').value         = data ? (data.cta_preset || '') : '';
                  document.getElementById('tmplPlatforms').value   = data ? (data.platform_targets || 'gbp,facebook,instagram') : 'gbp,facebook,instagram';
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
                      service_type:     document.getElementById('tmplServiceType').value,
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

              // ── Template campaign modal ────────────────────────────────
              var tmplCampaignId   = 0;
              var tmplCampaignData = null;
              var tmplCadence      = 'seasonal';
              var tmplCadenceDays  = 0;
              var tmplMonths       = 6;
              var tmplSlots        = [];
              var tmplAllAccounts  = [];

              var cadenceDescs = {
                  'seasonal': 'Posts more often during peak landscaping season (Apr–Jul), less in winter.',
                  'fixed-14': 'One post every 2 weeks, evenly spaced.',
                  'fixed-7':  'One post per week, evenly spaced.',
              };

              var dowNames3   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
              var monthNms    = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

              window.openTemplateCampaign = function(id) {
                  tmplCampaignId   = id;
                  tmplCampaignData = null;
                  tmplSlots        = [];
                  tmplCadence      = 'seasonal';
                  tmplCadenceDays  = 0;
                  tmplMonths       = 6;

                  // Reset pill states
                  document.querySelectorAll('#tmplCadencePills .mw-soc-cadence-pill').forEach(function(p, i) {
                      p.classList.toggle('active', i === 0);
                  });
                  document.querySelectorAll('#tmplMonthPills .mw-soc-cadence-pill').forEach(function(p, i) {
                      p.classList.toggle('active', i === 1);
                  });
                  document.getElementById('tmplCadenceDesc').textContent = cadenceDescs['seasonal'];
                  document.getElementById('tmplCampPreview').innerHTML   = '<div class="text-muted small text-center py-2">Select accounts then click Preview slots</div>';
                  document.getElementById('btnTmplCampConfirm').disabled = true;
                  document.getElementById('tmplCampTemplateName').textContent = '';
                  document.getElementById('tmplCampCaption').textContent = '';

                  // Load template info for name + caption preview
                  fetch('/crm/api/social/accounts.php?action=templates')
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (!data.success) return;
                          var t = (data.templates || []).find(function(x) { return x.id == id; });
                          if (!t) return;
                          tmplCampaignData = t;
                          document.getElementById('tmplCampTemplateName').textContent =
                              t.name + (t.service_type ? ' — ' + t.service_type : '');
                          document.getElementById('tmplCampCaption').textContent = t.caption_template || '';
                      });

                  // Load connected accounts
                  document.getElementById('tmplCampAccounts').innerHTML = '<div class="text-muted small">Loading accounts…</div>';
                  fetch('/crm/api/social/accounts.php?action=list')
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          tmplAllAccounts = (data.accounts || []).filter(function(a) { return a.is_active == 1; });
                          renderTmplAccountCheckboxes(tmplAllAccounts);
                      });

                  $('#tmplCampaignModal').modal('show');
              };

              function renderTmplAccountCheckboxes(accounts) {
                  var el = document.getElementById('tmplCampAccounts');
                  if (!accounts.length) {
                      el.innerHTML = '<div class="alert alert-warning small mb-0">No connected accounts. <a href="/crm/marketing/social-accounts.php">Connect one first.</a></div>';
                      return;
                  }
                  var html = '';
                  accounts.forEach(function(a) {
                      var locInfo = a.location_name_display ? ' (' + esc(a.location_name_display) + ')' : '';
                      var icon    = platformIcons[a.platform] || '';
                      html += '<label class="mw-soc-account-check">'
                           +  '<input type="checkbox" class="tmpl-acct-cb" data-id="' + a.id + '" data-platform="' + esc(a.platform) + '" style="margin:0;" checked>'
                           +  '<span class="mw-soc-platform-pill mw-soc-pl-' + esc(a.platform) + '" style="margin:0;">' + icon + '</span>'
                           +  '<span class="small">' + esc(a.account_name) + '<span class="text-muted">' + locInfo + '</span></span>'
                           + '</label>';
                  });
                  el.innerHTML = html;
              }

              window.setTmplCadencePill = function(cadence, days, el) {
                  tmplCadence     = cadence;
                  tmplCadenceDays = days;
                  document.querySelectorAll('#tmplCadencePills .mw-soc-cadence-pill').forEach(function(p) { p.classList.remove('active'); });
                  el.classList.add('active');
                  var key = cadence === 'seasonal' ? 'seasonal' : ('fixed-' + days);
                  document.getElementById('tmplCadenceDesc').textContent = cadenceDescs[key] || '';
                  tmplSlots = [];
                  document.getElementById('btnTmplCampConfirm').disabled = true;
                  document.getElementById('tmplCampPreview').innerHTML = '<div class="text-muted small text-center py-2">Click Preview slots to update</div>';
              };

              window.setTmplMonthPill = function(months, el) {
                  tmplMonths = months;
                  document.querySelectorAll('#tmplMonthPills .mw-soc-cadence-pill').forEach(function(p) { p.classList.remove('active'); });
                  el.classList.add('active');
                  tmplSlots = [];
                  document.getElementById('btnTmplCampConfirm').disabled = true;
                  document.getElementById('tmplCampPreview').innerHTML = '<div class="text-muted small text-center py-2">Click Preview slots to update</div>';
              };

              window.loadTmplCampaignPreview = function() {
                  var url = '/crm/api/social/schedules.php?action=preview'
                          + '&template_id=' + tmplCampaignId
                          + '&cadence=' + encodeURIComponent(tmplCadence)
                          + '&cadence_days=' + tmplCadenceDays
                          + '&months=' + tmplMonths;

                  var prev = document.getElementById('tmplCampPreview');
                  prev.innerHTML = '<div class="mw-soc-loading">Calculating slots…</div>';

                  fetch(url)
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (!data.success) {
                              prev.innerHTML = '<div class="alert alert-danger small mb-0">' + esc(data.error || 'Error') + '</div>';
                              return;
                          }
                          tmplSlots = data.slots || [];
                          renderTmplCampaignPreview(tmplSlots, data.total);
                          document.getElementById('btnTmplCampConfirm').disabled = (tmplSlots.length === 0);
                      })
                      .catch(function() {
                          prev.innerHTML = '<div class="alert alert-danger small mb-0">Preview failed. Please try again.</div>';
                      });
              };

              function renderTmplCampaignPreview(slots, total) {
                  var prev = document.getElementById('tmplCampPreview');
                  if (!slots.length) {
                      prev.innerHTML = '<div class="text-muted small text-center py-2">No available slots found for this period.</div>';
                      return;
                  }
                  // Group slots by YYYY-MM
                  var byMonth  = {};
                  var moOrder  = [];
                  slots.forEach(function(s) {
                      var ym = s.scheduled_at.substring(0, 7);
                      if (!byMonth[ym]) { byMonth[ym] = []; moOrder.push(ym); }
                      byMonth[ym].push(s);
                  });

                  var html = '<div class="mb-2 d-flex justify-content-between align-items-center">'
                           + '<strong class="small">📅 ' + total + ' posts to be scheduled</strong>'
                           + '<span class="badge badge-success">Ready to create</span>'
                           + '</div>';

                  moOrder.forEach(function(ym) {
                      var yr     = parseInt(ym.substring(0, 4), 10);
                      var mo     = parseInt(ym.substring(5, 7), 10) - 1;
                      var mLabel = monthNms[mo] + ' ' + yr;
                      html += '<div class="mw-soc-camp-month-hd">' + esc(mLabel) + ' — ' + byMonth[ym].length + ' post' + (byMonth[ym].length !== 1 ? 's' : '') + '</div>';
                      html += '<div class="mw-soc-camp-slots">';
                      byMonth[ym].forEach(function(s) {
                          var dt   = new Date(s.scheduled_at.replace(' ', 'T'));
                          var dow  = dowNames3[dt.getDay()];
                          var day  = dt.getDate();
                          var mon  = monthNms[dt.getMonth()];
                          var hr   = dt.getHours();
                          var ampm = hr >= 12 ? 'pm' : 'am';
                          var h12  = hr % 12 === 0 ? 12 : hr % 12;
                          html += '<div class="mw-soc-camp-slot">'
                               +  '<span class="mw-soc-camp-dow">' + esc(dow) + '</span>'
                               +  '<span class="mw-soc-camp-date">' + esc(mon) + ' ' + day + '</span>'
                               +  '<span class="mw-soc-camp-time">' + h12 + ampm + '</span>'
                               + '</div>';
                      });
                      html += '</div>';
                  });

                  prev.innerHTML = html;
              }

              window.confirmTemplateCampaign = function() {
                  if (!tmplCampaignId || !tmplSlots.length) return;

                  // Collect checked accounts
                  var selectedAccounts = [];
                  document.querySelectorAll('.tmpl-acct-cb:checked').forEach(function(cb) {
                      selectedAccounts.push({
                          account_id: parseInt(cb.dataset.id, 10),
                          platform:   cb.dataset.platform
                      });
                  });

                  if (!selectedAccounts.length) {
                      alert('Please select at least one account to publish to.');
                      return;
                  }

                  var btn = document.getElementById('btnTmplCampConfirm');
                  btn.disabled    = true;
                  btn.textContent = 'Creating…';

                  var body = {
                      csrf_token:   csrf,
                      template_id:  tmplCampaignId,
                      account_ids:  selectedAccounts,
                      cadence:      tmplCadence,
                      cadence_days: tmplCadenceDays,
                      months:       tmplMonths,
                      name:         tmplCampaignData ? tmplCampaignData.name : '',
                  };

                  fetch('/crm/api/social/schedules.php?action=create-from-template', {
                      method:  'POST',
                      headers: {'Content-Type': 'application/json'},
                      body:    JSON.stringify(body),
                  })
                  .then(function(r) { return r.json(); })
                  .then(function(data) {
                      if (data.success) {
                          $('#tmplCampaignModal').modal('hide');
                          alert('✅ Campaign created! ' + data.posts_created + ' posts scheduled as "' + data.name + '".');
                      } else {
                          alert('Error: ' + (data.error || 'Unknown error'));
                          btn.disabled    = false;
                          btn.textContent = 'Create Campaign';
                      }
                  })
                  .catch(function() {
                      alert('Network error. Please try again.');
                      btn.disabled    = false;
                      btn.textContent = 'Create Campaign';
                  });
              };

              loadTemplates();
          })();
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
