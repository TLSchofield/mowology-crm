<?php
/**
 * Marketing Campaigns — List & Management
 *
 * Campaign dashboard with create/edit, segment targeting, template selection,
 * preview, and send controls.
 *
 * @package Mowology CRM
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('marketing.view');

$pageTitle = 'Email Campaigns';
$activePage = 'marketing';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                  <h1 class="h3 mb-0">Email Campaigns</h1>
                  <p class="text-muted mb-0">Create and manage targeted email campaigns</p>
              </div>
              <?php if (userHasPermission('marketing.edit')): ?>
              <button class="btn btn-success" onclick="openCampaignModal()">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  New Campaign
              </button>
              <?php endif; ?>
          </div>

          <!-- Stats Bar -->
          <div class="mw-camp-stats" id="campStats">
              <div class="mw-camp-stat-card">
                  <span class="mw-camp-stat-number" id="statCampaigns">-</span>
                  <span class="mw-camp-stat-label">Campaigns</span>
              </div>
              <div class="mw-camp-stat-card sent">
                  <span class="mw-camp-stat-number" id="statSentMonth">-</span>
                  <span class="mw-camp-stat-label">Sent This Month</span>
              </div>
              <div class="mw-camp-stat-card">
                  <span class="mw-camp-stat-number" id="statOpened">-</span>
                  <span class="mw-camp-stat-label">Total Opened</span>
              </div>
              <div class="mw-camp-stat-card">
                  <span class="mw-camp-stat-number" id="statOpenRate">-</span>
                  <span class="mw-camp-stat-label">Open Rate</span>
              </div>
          </div>

          <!-- Filter Tabs -->
          <div class="mw-camp-tabs" id="campTabs">
              <button class="mw-camp-tab active" data-status="">All</button>
              <button class="mw-camp-tab" data-status="draft">Draft</button>
              <button class="mw-camp-tab" data-status="scheduled">Scheduled</button>
              <button class="mw-camp-tab" data-status="queued">Queued</button>
              <button class="mw-camp-tab" data-status="sending">Sending</button>
              <button class="mw-camp-tab" data-status="completed">Completed</button>
          </div>

          <!-- Campaign List -->
          <div id="campListContainer">
              <div class="mw-camp-empty">
                  <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                      <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                      <polyline points="22,6 12,13 2,6"/>
                  </svg>
                  <p>Loading campaigns...</p>
              </div>
          </div>

          <!-- Pagination -->
          <div id="campPagination" class="d-flex justify-content-center mt-3" style="display:none !important;"></div>

          <!-- ── Campaign Create/Edit Modal ─────────────────────────────── -->
          <div class="modal fade" id="campaignModal" tabindex="-1">
              <div class="modal-dialog modal-lg">
                  <div class="modal-content">
                      <div class="modal-header">
                          <h5 class="modal-title" id="campaignModalTitle">New Campaign</h5>
                          <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                      </div>
                      <div class="modal-body">
                          <input type="hidden" id="campId" value="0">

                          <div class="form-group">
                              <label>Campaign Name <span class="text-danger">*</span></label>
                              <input type="text" class="form-control" id="campName" placeholder="e.g., Spring Lawn Treatment Upsell">
                          </div>

                          <div class="row">
                              <div class="col-md-6">
                                  <div class="form-group">
                                      <label>Email Template</label>
                                      <select class="form-control" id="campTemplate" onchange="loadTemplatePreview()">
                                          <option value="">— Select template —</option>
                                      </select>
                                  </div>
                              </div>
                              <div class="col-md-6">
                                  <div class="form-group">
                                      <label>Target Product</label>
                                      <select class="form-control" id="campProduct" onchange="updateRecipientCount()">
                                          <option value="">— No product filter —</option>
                                      </select>
                                  </div>
                              </div>
                          </div>

                          <div class="row">
                              <div class="col-md-6">
                                  <div class="form-group">
                                      <label>Audience Segment</label>
                                      <select class="form-control" id="campSegment" onchange="updateRecipientCount()">
                                          <option value="all_marketing">All Marketing Contacts</option>
                                          <option value="never_purchased_product">Never Purchased Product</option>
                                          <option value="inactive_3mo">Inactive 3+ Months</option>
                                          <option value="inactive_6mo">Inactive 6+ Months</option>
                                      </select>
                                      <small class="text-muted" id="recipientCount">Select segment to see count</small>
                                  </div>
                              </div>
                              <div class="col-md-6">
                                  <div class="form-group">
                                      <label>Schedule</label>
                                      <input type="datetime-local" class="form-control" id="campSchedule">
                                      <small class="text-muted">Leave blank to send manually</small>
                                  </div>
                              </div>
                          </div>

                          <div class="form-group">
                              <label>Subject Line <small class="text-muted">(overrides template subject)</small></label>
                              <input type="text" class="form-control" id="campSubject" placeholder="Leave blank to use template subject">
                              <small class="text-muted">Merge fields: {{first_name}}, {{product_name}}, {{property_address}}</small>
                          </div>

                          <div class="form-group">
                              <label>
                                  Email Body <small class="text-muted">(overrides template body)</small>
                                  <button class="btn btn-sm btn-outline-secondary ml-2" onclick="insertMergeField()">Insert Field</button>
                                  <button class="btn btn-sm btn-outline-info ml-1" onclick="previewCampaign()">Preview</button>
                              </label>
                              <textarea class="form-control" id="campBody" rows="10" placeholder="Leave blank to use template body. HTML supported with {{merge_fields}}."></textarea>
                          </div>
                      </div>
                      <div class="modal-footer">
                          <button class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                          <button class="btn btn-success" onclick="saveCampaign()">Save Draft</button>
                      </div>
                  </div>
              </div>
          </div>

          <!-- ── Preview Modal ──────────────────────────────────────────── -->
          <div class="modal fade" id="previewModal" tabindex="-1">
              <div class="modal-dialog modal-lg">
                  <div class="modal-content">
                      <div class="modal-header">
                          <h5 class="modal-title">Email Preview</h5>
                          <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                      </div>
                      <div class="modal-body p-0">
                          <div class="p-3 bg-light border-bottom">
                              <strong>To:</strong> <span id="previewTo">—</span><br>
                              <strong>Subject:</strong> <span id="previewSubject">—</span>
                          </div>
                          <iframe id="previewFrame" style="width:100%;height:500px;border:none;"></iframe>
                      </div>
                  </div>
              </div>
          </div>

          <script>
          (function() {
              'use strict';

              var currentStatus = '';
              var currentPage = 1;
              var csrf = '<?php echo generateCSRFToken(); ?>';
              var canEdit = <?php echo json_encode(userHasPermission('marketing.edit')); ?>;
              var templates = [];
              var products = [];

              // ── Init ──────────────────────────────────────────
              loadCampaigns();
              loadStats();
              loadTemplates();
              loadProducts();

              // Tab click handlers
              document.querySelectorAll('.mw-camp-tab').forEach(function(tab) {
                  tab.addEventListener('click', function() {
                      document.querySelectorAll('.mw-camp-tab').forEach(function(t) { t.classList.remove('active'); });
                      this.classList.add('active');
                      currentStatus = this.dataset.status;
                      currentPage = 1;
                      loadCampaigns();
                  });
              });

              // ── Load campaigns ─────────────────────────────────
              function loadCampaigns() {
                  var url = '/crm/api/campaigns.php?action=list&page=' + currentPage;
                  if (currentStatus) url += '&status=' + encodeURIComponent(currentStatus);

                  fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                      if (!data.success) { showError(data.error || 'Failed to load'); return; }
                      renderList(data.campaigns || []);
                      renderPagination(data.page, data.pages);
                  }).catch(function() { showError('Network error'); });
              }

              function loadStats() {
                  fetch('/crm/api/campaigns.php?action=stats')
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (!data.success) return;
                          document.getElementById('statCampaigns').textContent = data.campaigns || 0;
                          document.getElementById('statSentMonth').textContent = data.sent_this_month || 0;
                          document.getElementById('statOpened').textContent = data.total_opened || 0;
                          document.getElementById('statOpenRate').textContent = (data.open_rate || 0) + '%';
                      });
              }

              function loadTemplates() {
                  fetch('/crm/api/campaigns.php?action=templates')
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (!data.success) return;
                          templates = data.templates || [];
                          var sel = document.getElementById('campTemplate');
                          sel.innerHTML = '<option value="">— Select template —</option>';
                          templates.forEach(function(t) {
                              sel.innerHTML += '<option value="' + t.id + '">' + esc(t.name) + ' (' + esc(t.category) + ')</option>';
                          });
                      });
              }

              function loadProducts() {
                  fetch('/crm/api/api-products.php?action=list')
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          var list = data.products || data.data || [];
                          products = list;
                          var sel = document.getElementById('campProduct');
                          sel.innerHTML = '<option value="">— No product filter —</option>';
                          list.forEach(function(p) {
                              sel.innerHTML += '<option value="' + p.id + '">' + esc(p.name) + '</option>';
                          });
                      }).catch(function() {});
              }

              // ── Render campaign list ───────────────────────────
              function renderList(campaigns) {
                  var container = document.getElementById('campListContainer');
                  if (!campaigns.length) {
                      container.innerHTML =
                          '<div class="mw-camp-empty">' +
                          '  <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">' +
                          '    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>' +
                          '    <polyline points="22,6 12,13 2,6"/>' +
                          '  </svg>' +
                          '  <p>No campaigns found.</p>' +
                          '  <p class="text-muted small">Click "New Campaign" to create your first email campaign.</p>' +
                          '</div>';
                      return;
                  }

                  var html = '<div class="mw-camp-grid">';
                  campaigns.forEach(function(c) {
                      var statusClass = 'mw-camp-badge-' + (c.status || 'draft');
                      var date = c.created_at ? new Date(c.created_at).toLocaleDateString('en-CA', {
                          year: 'numeric', month: 'short', day: 'numeric'
                      }) : '';

                      html += '<div class="mw-camp-card" data-id="' + c.id + '">';
                      html += '  <div class="mw-camp-card-header">';
                      html += '    <h4>' + esc(c.name) + '</h4>';
                      html += '    <span class="mw-camp-badge ' + statusClass + '">' + esc(c.status) + '</span>';
                      html += '  </div>';
                      html += '  <div class="mw-camp-card-meta">';
                      if (c.template_name) html += '<span>Template: ' + esc(c.template_name) + '</span>';
                      if (c.product_name) html += '<span>Product: ' + esc(c.product_name) + '</span>';
                      html += '    <span>Segment: ' + esc(formatSegment(c.segment_type)) + '</span>';
                      html += '    <span>Created: ' + esc(date) + '</span>';
                      html += '  </div>';
                      html += '  <div class="mw-camp-card-stats">';
                      html += '    <div class="mw-camp-card-stat"><span class="num">' + (c.recipient_count || 0) + '</span><span class="label">Recipients</span></div>';
                      html += '    <div class="mw-camp-card-stat"><span class="num">' + (c.sent_count || 0) + '</span><span class="label">Sent</span></div>';
                      html += '    <div class="mw-camp-card-stat"><span class="num">' + (c.open_count || 0) + '</span><span class="label">Opened</span></div>';
                      html += '    <div class="mw-camp-card-stat"><span class="num">' + (c.click_count || 0) + '</span><span class="label">Clicked</span></div>';
                      html += '  </div>';

                      if (canEdit) {
                          html += '  <div class="mw-camp-card-actions">';
                          if (c.status === 'draft' || c.status === 'scheduled') {
                              html += '<button class="btn btn-sm btn-outline-primary" onclick="editCampaign(' + c.id + ')">Edit</button>';
                              html += '<button class="btn btn-sm btn-success" onclick="sendCampaign(' + c.id + ')">Send Now</button>';
                              html += '<button class="btn btn-sm btn-outline-danger" onclick="deleteCampaign(' + c.id + ')">Cancel</button>';
                          }
                          if (c.status === 'queued') {
                              html += '<button class="btn btn-sm btn-success" onclick="sendCampaign(' + c.id + ')">Start Sending</button>';
                          }
                          if (c.status === 'sending') {
                              html += '<button class="btn btn-sm btn-warning" onclick="pauseCampaign(' + c.id + ')">Pause</button>';
                          }
                          if (c.status === 'paused') {
                              html += '<button class="btn btn-sm btn-success" onclick="resumeCampaign(' + c.id + ')">Resume</button>';
                          }
                          html += '    <button class="btn btn-sm btn-outline-info" onclick="previewCampaignById(' + c.id + ')">Preview</button>';
                          html += '  </div>';
                      }
                      html += '</div>';
                  });
                  html += '</div>';
                  container.innerHTML = html;
              }

              function renderPagination(page, totalPages) {
                  var container = document.getElementById('campPagination');
                  if (totalPages <= 1) { container.style.display = 'none'; return; }
                  container.style.display = 'flex';
                  var html = '<nav><ul class="pagination pagination-sm">';
                  for (var i = 1; i <= totalPages; i++) {
                      html += '<li class="page-item ' + (i === page ? 'active' : '') + '">';
                      html += '<a class="page-link" href="#" onclick="goPage(' + i + ');return false;">' + i + '</a></li>';
                  }
                  html += '</ul></nav>';
                  container.innerHTML = html;
              }

              // ── Modal actions ──────────────────────────────────
              window.openCampaignModal = function(data) {
                  document.getElementById('campId').value = data ? data.id : 0;
                  document.getElementById('campName').value = data ? data.name : '';
                  document.getElementById('campTemplate').value = data ? (data.template_id || '') : '';
                  document.getElementById('campProduct').value = data ? (data.product_id || '') : '';
                  document.getElementById('campSegment').value = data ? (data.segment_type || 'all_marketing') : 'all_marketing';
                  document.getElementById('campSchedule').value = data && data.schedule_date ? data.schedule_date.replace(' ', 'T').substring(0, 16) : '';
                  document.getElementById('campSubject').value = data ? (data.subject_override || '') : '';
                  document.getElementById('campBody').value = data ? (data.body_override || '') : '';
                  document.getElementById('campaignModalTitle').textContent = data ? 'Edit Campaign' : 'New Campaign';
                  updateRecipientCount();
                  $('#campaignModal').modal('show');
              };

              window.editCampaign = function(id) {
                  fetch('/crm/api/campaigns.php?action=get&id=' + id)
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (data.success) openCampaignModal(data.campaign);
                          else alert('Error: ' + (data.error || 'Unknown'));
                      });
              };

              window.saveCampaign = function() {
                  var body = {
                      csrf_token: csrf,
                      id: parseInt(document.getElementById('campId').value) || 0,
                      name: document.getElementById('campName').value,
                      template_id: document.getElementById('campTemplate').value || null,
                      product_id: document.getElementById('campProduct').value || null,
                      segment_type: document.getElementById('campSegment').value,
                      schedule_date: document.getElementById('campSchedule').value || null,
                      subject_override: document.getElementById('campSubject').value || null,
                      body_override: document.getElementById('campBody').value || null,
                      trigger_type: 'manual',
                  };

                  fetch('/crm/api/campaigns.php?action=save', {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/json' },
                      body: JSON.stringify(body)
                  }).then(function(r) { return r.json(); }).then(function(data) {
                      if (data.success) {
                          $('#campaignModal').modal('hide');
                          loadCampaigns();
                          loadStats();
                      } else {
                          alert('Error: ' + (data.error || 'Unknown'));
                      }
                  });
              };

              window.sendCampaign = function(id) {
                  if (!confirm('Send this campaign now? Emails will begin processing.')) return;
                  fetch('/crm/api/campaigns.php?action=send-now', {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/json' },
                      body: JSON.stringify({ id: id, csrf_token: csrf })
                  }).then(function(r) { return r.json(); }).then(function(data) {
                      if (data.success) {
                          alert(data.message || 'Campaign is sending!');
                          loadCampaigns();
                          loadStats();
                      } else {
                          alert('Error: ' + (data.error || 'Unknown'));
                      }
                  });
              };

              window.pauseCampaign = function(id) {
                  fetch('/crm/api/campaigns.php?action=pause', {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/json' },
                      body: JSON.stringify({ id: id, csrf_token: csrf })
                  }).then(function(r) { return r.json(); }).then(function(data) {
                      if (data.success) loadCampaigns();
                  });
              };

              window.resumeCampaign = function(id) {
                  fetch('/crm/api/campaigns.php?action=resume', {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/json' },
                      body: JSON.stringify({ id: id, csrf_token: csrf })
                  }).then(function(r) { return r.json(); }).then(function(data) {
                      if (data.success) loadCampaigns();
                  });
              };

              window.deleteCampaign = function(id) {
                  if (!confirm('Cancel this campaign? This cannot be undone.')) return;
                  fetch('/crm/api/campaigns.php?action=delete', {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/json' },
                      body: JSON.stringify({ id: id, csrf_token: csrf })
                  }).then(function(r) { return r.json(); }).then(function(data) {
                      if (data.success) { loadCampaigns(); loadStats(); }
                  });
              };

              window.updateRecipientCount = function() {
                  var segment = document.getElementById('campSegment').value;
                  var productId = document.getElementById('campProduct').value || 0;
                  var el = document.getElementById('recipientCount');
                  el.textContent = 'Counting recipients...';

                  fetch('/crm/api/campaigns.php?action=get-recipients&segment_type=' + encodeURIComponent(segment) + '&product_id=' + productId)
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (data.success) {
                              el.textContent = data.count + ' recipient' + (data.count !== 1 ? 's' : '') + ' match this segment';
                              el.style.color = data.count > 0 ? '#2D8659' : '#dc3545';
                          }
                      }).catch(function() {
                          el.textContent = 'Unable to count';
                      });
              };

              window.loadTemplatePreview = function() {
                  var tid = document.getElementById('campTemplate').value;
                  if (!tid) return;
                  var tpl = templates.find(function(t) { return t.id == tid; });
                  if (tpl && !document.getElementById('campSubject').value) {
                      document.getElementById('campSubject').value = tpl.subject;
                  }
              };

              window.insertMergeField = function() {
                  var fields = ['first_name', 'last_name', 'email', 'property_address', 'product_name', 'product_price', 'product_description', 'cta_url', 'review_url', 'unsubscribe_url', 'company_name', 'company_phone'];
                  var choice = prompt('Available fields:\\n' + fields.map(function(f) { return '{{' + f + '}}'; }).join('\\n') + '\\n\\nType field name:');
                  if (choice) {
                      var field = '{{' + choice.replace(/[{}]/g, '') + '}}';
                      var ta = document.getElementById('campBody');
                      var start = ta.selectionStart;
                      ta.value = ta.value.substring(0, start) + field + ta.value.substring(ta.selectionEnd);
                      ta.focus();
                  }
              };

              window.previewCampaign = function() {
                  var tid = document.getElementById('campTemplate').value;
                  var campId = document.getElementById('campId').value;
                  if (campId && campId !== '0') {
                      previewCampaignById(campId);
                  } else if (tid) {
                      previewByTemplate(tid);
                  } else {
                      alert('Select a template or save the campaign first to preview.');
                  }
              };

              window.previewCampaignById = function(id) {
                  fetch('/crm/api/campaigns.php?action=preview&campaign_id=' + id)
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (!data.success) { alert(data.error); return; }
                          showPreview(data);
                      });
              };

              function previewByTemplate(tid) {
                  fetch('/crm/api/campaigns.php?action=preview&template_id=' + tid)
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (!data.success) { alert(data.error); return; }
                          showPreview(data);
                      });
              }

              function showPreview(data) {
                  document.getElementById('previewTo').textContent = data.contact ? data.contact.name + ' <' + data.contact.email + '>' : '—';
                  document.getElementById('previewSubject').textContent = data.subject || '—';
                  var frame = document.getElementById('previewFrame');
                  frame.srcdoc = data.body || '<p>No content</p>';
                  $('#previewModal').modal('show');
              }

              window.goPage = function(p) { currentPage = p; loadCampaigns(); };

              // ── Helpers ────────────────────────────────────────
              function formatSegment(type) {
                  var map = {
                      'all_marketing': 'All Marketing Contacts',
                      'never_purchased_product': 'Never Purchased Product',
                      'inactive_3mo': 'Inactive 3+ Months',
                      'inactive_6mo': 'Inactive 6+ Months',
                      'service_type': 'Service Type',
                      'custom_list': 'Custom List'
                  };
                  return map[type] || type;
              }

              function esc(str) {
                  if (!str) return '';
                  var d = document.createElement('div');
                  d.appendChild(document.createTextNode(str));
                  return d.innerHTML;
              }

              function showError(msg) {
                  document.getElementById('campListContainer').innerHTML =
                      '<div class="alert alert-danger">' + esc(msg) + '</div>';
              }
          })();
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
