<?php
/**
 * Marketing Automation Engine — Campaigns, Automations, Analytics, Opt-In
 *
 * @package Mowology CRM
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('marketing.view');

$canEdit = userHasPermission('marketing.edit');

$pageTitle  = 'Marketing';
$activePage = 'marketing';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <!-- ── Page Header ─────────────────────────────────────────── -->
          <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                  <h1 class="h3 mb-0">Marketing Engine</h1>
                  <p class="text-muted mb-0">Campaigns · Automations · Analytics · Opt-In</p>
              </div>
              <div id="pageHeaderActions">
                  <?php if ($canEdit): ?>
                  <button class="btn btn-success" id="btnNewCampaign" onclick="openCampaignModal()" style="display:none;">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                      New Campaign
                  </button>
                  <button class="btn btn-success" id="btnNewRule" onclick="openRuleModal()" style="display:none;">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                      New Automation
                  </button>
                  <?php endif; ?>
              </div>
          </div>

          <!-- ── Top-level Section Tabs ─────────────────────────────── -->
          <ul class="nav nav-tabs mw-marketing-tabs mb-4" id="marketingTabs">
              <li class="nav-item">
                  <a class="nav-link active" href="#" data-section="campaigns" onclick="switchSection('campaigns');return false;">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                      Campaigns
                  </a>
              </li>
              <li class="nav-item">
                  <a class="nav-link" href="#" data-section="automations" onclick="switchSection('automations');return false;">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                      Automations
                      <span class="badge badge-success ml-1" id="badgeActiveRules" style="display:none;">0</span>
                  </a>
              </li>
              <li class="nav-item">
                  <a class="nav-link" href="#" data-section="analytics" onclick="switchSection('analytics');return false;">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                      Analytics
                  </a>
              </li>
              <li class="nav-item">
                  <a class="nav-link" href="#" data-section="optin" onclick="switchSection('optin');return false;">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                      Opt-In
                  </a>
              </li>
          </ul>

          <!-- ═══════════════════════════════════════════════════════════
               SECTION 1 — CAMPAIGNS
          ════════════════════════════════════════════════════════════════ -->
          <div id="sectionCampaigns">

              <!-- Stats Bar -->
              <div class="mw-camp-stats mb-3" id="campStats">
                  <div class="mw-camp-stat-card"><span class="mw-camp-stat-number" id="statCampaigns">—</span><span class="mw-camp-stat-label">Campaigns</span></div>
                  <div class="mw-camp-stat-card sent"><span class="mw-camp-stat-number" id="statSentMonth">—</span><span class="mw-camp-stat-label">Sent This Month</span></div>
                  <div class="mw-camp-stat-card"><span class="mw-camp-stat-number" id="statOpened">—</span><span class="mw-camp-stat-label">Total Opened</span></div>
                  <div class="mw-camp-stat-card"><span class="mw-camp-stat-number" id="statOpenRate">—</span><span class="mw-camp-stat-label">Open Rate</span></div>
              </div>

              <!-- Filter Tabs -->
              <div class="mw-camp-tabs mb-3" id="campTabs">
                  <button class="mw-camp-tab active" data-status="">All</button>
                  <button class="mw-camp-tab" data-status="draft">Draft</button>
                  <button class="mw-camp-tab" data-status="scheduled">Scheduled</button>
                  <button class="mw-camp-tab" data-status="sending">Sending</button>
                  <button class="mw-camp-tab" data-status="completed">Completed</button>
              </div>

              <div id="campListContainer">
                  <div class="mw-camp-empty"><p>Loading campaigns...</p></div>
              </div>
              <div id="campPagination" class="d-flex justify-content-center mt-3"></div>
          </div>

          <!-- ═══════════════════════════════════════════════════════════
               SECTION 2 — AUTOMATIONS
          ════════════════════════════════════════════════════════════════ -->
          <div id="sectionAutomations" style="display:none;">

              <!-- Stats -->
              <div class="mw-camp-stats mb-3" id="autoStats">
                  <div class="mw-camp-stat-card"><span class="mw-camp-stat-number" id="autoStatEnabled">—</span><span class="mw-camp-stat-label">Active Rules</span></div>
                  <div class="mw-camp-stat-card"><span class="mw-camp-stat-number" id="autoStatQueue">—</span><span class="mw-camp-stat-label">Queued Actions</span></div>
                  <div class="mw-camp-stat-card"><span class="mw-camp-stat-number" id="autoStatTotal">—</span><span class="mw-camp-stat-label">Total Rules</span></div>
              </div>

              <!-- Filter -->
              <div class="mw-camp-tabs mb-3">
                  <button class="mw-camp-tab active" data-autofilter="" onclick="filterAutomations(this,'')">All Rules</button>
                  <button class="mw-camp-tab" data-autofilter="enabled" onclick="filterAutomations(this,'enabled')">Enabled</button>
                  <button class="mw-camp-tab" data-autofilter="disabled" onclick="filterAutomations(this,'disabled')">Disabled</button>
              </div>

              <div id="autoListContainer"><div class="mw-camp-empty"><p>Loading automations...</p></div></div>

              <!-- Activity Log Toggle -->
              <div class="mt-4">
                  <button class="btn btn-sm btn-outline-secondary" onclick="toggleAutoLog()">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                      View Activity Log
                  </button>
              </div>
              <div id="autoLogContainer" style="display:none;" class="mt-3"></div>
          </div>

          <!-- ═══════════════════════════════════════════════════════════
               SECTION 3 — ANALYTICS
          ════════════════════════════════════════════════════════════════ -->
          <div id="sectionAnalytics" style="display:none;">

              <!-- Period selector -->
              <div class="d-flex align-items-center mb-3">
                  <span class="text-muted mr-2 small">Period:</span>
                  <?php foreach (['7'=>'7d','14'=>'14d','30'=>'30d','90'=>'90d','365'=>'1yr'] as $v=>$lbl): ?>
                  <button class="btn btn-sm btn-outline-secondary mr-1 mw-period-btn <?php echo $v==='30'?'active':''; ?>" data-period="<?php echo $v; ?>" onclick="setPeriod(this)"><?php echo $lbl; ?></button>
                  <?php endforeach; ?>
              </div>

              <!-- Overview Cards -->
              <div class="row" id="analyticsOverview">
                  <div class="col-sm-6 col-xl-3 mb-3"><div class="card mw-analytics-card"><div class="card-body"><div class="mw-anl-num" id="anlSent">—</div><div class="mw-anl-lbl">Emails Sent</div></div></div></div>
                  <div class="col-sm-6 col-xl-3 mb-3"><div class="card mw-analytics-card"><div class="card-body"><div class="mw-anl-num" id="anlOpenRate">—</div><div class="mw-anl-lbl">Open Rate</div></div></div></div>
                  <div class="col-sm-6 col-xl-3 mb-3"><div class="card mw-analytics-card"><div class="card-body"><div class="mw-anl-num" id="anlClickRate">—</div><div class="mw-anl-lbl">Click Rate</div></div></div></div>
                  <div class="col-sm-6 col-xl-3 mb-3"><div class="card mw-analytics-card revenue"><div class="card-body"><div class="mw-anl-num" id="anlRevenue">—</div><div class="mw-anl-lbl">Revenue Attributed</div></div></div></div>
              </div>

              <!-- Conversion Funnel -->
              <div class="card mb-3">
                  <div class="card-header"><h5 class="card-title mb-0">Conversion Funnel</h5></div>
                  <div class="card-body" id="funnelContainer"><p class="text-muted">Loading...</p></div>
              </div>

              <!-- Territory + Device row -->
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <div class="card h-100">
                          <div class="card-header"><h5 class="card-title mb-0">Territory Performance</h5></div>
                          <div class="card-body p-0" id="territoryContainer"><p class="p-3 text-muted">Loading...</p></div>
                      </div>
                  </div>
                  <div class="col-md-6 mb-3">
                      <div class="card h-100">
                          <div class="card-header"><h5 class="card-title mb-0">Hour-of-Day Engagement</h5></div>
                          <div class="card-body" id="hourlyContainer"><p class="text-muted">Loading...</p></div>
                      </div>
                  </div>
              </div>

              <!-- Top campaigns by revenue -->
              <div class="card mb-3">
                  <div class="card-header"><h5 class="card-title mb-0">Revenue by Campaign</h5></div>
                  <div class="card-body p-0" id="revenueContainer"><p class="p-3 text-muted">Loading...</p></div>
              </div>
          </div>

          <!-- ═══════════════════════════════════════════════════════════
               SECTION 4 — OPT-IN TOOLS
          ════════════════════════════════════════════════════════════════ -->
          <div id="sectionOptin" style="display:none;">

              <!-- Stats -->
              <div class="mw-camp-stats mb-3">
                  <div class="mw-camp-stat-card"><span class="mw-camp-stat-number" id="optTotal">—</span><span class="mw-camp-stat-label">Total Contacts</span></div>
                  <div class="mw-camp-stat-card sent"><span class="mw-camp-stat-number" id="optConfirmed">—</span><span class="mw-camp-stat-label">Confirmed</span></div>
                  <div class="mw-camp-stat-card"><span class="mw-camp-stat-number" id="optPending">—</span><span class="mw-camp-stat-label">Awaiting Response</span></div>
                  <div class="mw-camp-stat-card"><span class="mw-camp-stat-number text-danger" id="optUnsub">—</span><span class="mw-camp-stat-label">Unsubscribed</span></div>
              </div>

              <!-- Risk indicator -->
              <div id="optinRiskBanner" class="alert alert-warning d-none mb-3">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                  <strong>List hygiene warning:</strong> Fewer than 50% of contacts have confirmed opt-in. Consider sending a refresh campaign.
              </div>

              <!-- Bulk send panel -->
              <?php if ($canEdit): ?>
              <div class="card mb-3">
                  <div class="card-header d-flex justify-content-between align-items-center">
                      <h5 class="card-title mb-0">Personal Opt-In Refresh Campaign</h5>
                  </div>
                  <div class="card-body">
                      <p class="text-muted mb-3">Send a personalised 1:1 email to contacts asking them to confirm their marketing subscription. Each email contains a unique secure confirmation link.</p>
                      <div class="row">
                          <div class="col-md-4">
                              <div class="form-group">
                                  <label class="small">Send to</label>
                                  <select class="form-control form-control-sm" id="optBulkTarget">
                                      <option value="never_sent">Contacts never contacted</option>
                                      <option value="pending">Pending non-responders</option>
                                  </select>
                              </div>
                          </div>
                          <div class="col-md-3" id="optResendDaysWrap" style="display:none;">
                              <div class="form-group">
                                  <label class="small">Resend if no response after</label>
                                  <select class="form-control form-control-sm" id="optResendDays">
                                      <option value="7">7 days</option>
                                      <option value="14" selected>14 days</option>
                                      <option value="30">30 days</option>
                                  </select>
                              </div>
                          </div>
                          <div class="col-md-3 d-flex align-items-end">
                              <button class="btn btn-success btn-sm" onclick="sendBulkOptin()">
                                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                  Send Opt-In Emails
                              </button>
                          </div>
                      </div>
                  </div>
              </div>
              <?php endif; ?>

              <!-- Contact list with opt-in status -->
              <div class="mw-camp-tabs mb-3">
                  <button class="mw-camp-tab active" onclick="filterOptin(this,'')">All</button>
                  <button class="mw-camp-tab" onclick="filterOptin(this,'confirmed')">Confirmed</button>
                  <button class="mw-camp-tab" onclick="filterOptin(this,'pending')">Pending</button>
                  <button class="mw-camp-tab" onclick="filterOptin(this,'never_sent')">Never Sent</button>
                  <button class="mw-camp-tab" onclick="filterOptin(this,'unsubscribed')">Unsubscribed</button>
              </div>
              <div id="optinListContainer"><div class="mw-camp-empty"><p>Loading...</p></div></div>
              <div id="optinPagination" class="d-flex justify-content-center mt-3"></div>
          </div>


          <!-- ════════════════════════════════════════════════════════════
               MODALS
          ═════════════════════════════════════════════════════════════════ -->

          <!-- Campaign Modal -->
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
                              <textarea class="form-control" id="campBody" rows="8" placeholder="Leave blank to use template body. HTML supported with {{merge_fields}}."></textarea>
                          </div>
                      </div>
                      <div class="modal-footer">
                          <button class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                          <button class="btn btn-success" onclick="saveCampaign()">Save Draft</button>
                      </div>
                  </div>
              </div>
          </div>

          <!-- Automation Rule Modal -->
          <div class="modal fade" id="ruleModal" tabindex="-1">
              <div class="modal-dialog modal-xl">
                  <div class="modal-content">
                      <div class="modal-header">
                          <h5 class="modal-title" id="ruleModalTitle">New Automation Rule</h5>
                          <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                      </div>
                      <div class="modal-body">
                          <input type="hidden" id="ruleId" value="0">

                          <div class="row mb-3">
                              <div class="col-md-8">
                                  <div class="form-group mb-0">
                                      <label>Rule Name <span class="text-danger">*</span></label>
                                      <input type="text" class="form-control" id="ruleName" placeholder="e.g., Post-job thank you + review request">
                                  </div>
                              </div>
                              <div class="col-md-4">
                                  <div class="form-group mb-0">
                                      <label>Status</label>
                                      <select class="form-control" id="ruleEnabled">
                                          <option value="1">Enabled</option>
                                          <option value="0">Disabled (save as draft)</option>
                                      </select>
                                  </div>
                              </div>
                          </div>

                          <div class="form-group">
                              <label>Description</label>
                              <input type="text" class="form-control" id="ruleDesc" placeholder="Optional: explain what this rule does">
                          </div>

                          <!-- Trigger -->
                          <div class="mw-rule-section">
                              <div class="mw-rule-section-label">
                                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                  TRIGGER — When this happens…
                              </div>
                              <div class="form-group mb-2">
                                  <select class="form-control" id="ruleTriggerType" onchange="renderTriggerConfig()">
                                      <option value="">— Select trigger —</option>
                                  </select>
                              </div>
                              <div id="ruleTriggerConfig"></div>
                          </div>

                          <!-- Conditions -->
                          <div class="mw-rule-section">
                              <div class="mw-rule-section-label">
                                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 8 12 12 14 14"/></svg>
                                  CONDITIONS — …but only if…
                              </div>
                              <div id="ruleConditionsList"></div>
                              <button class="btn btn-sm btn-outline-secondary mt-1" onclick="addConditionRow()">+ Add Condition</button>
                              <small class="text-muted d-block mt-1">All conditions must be true. Leave empty to always trigger.</small>
                          </div>

                          <!-- Actions -->
                          <div class="mw-rule-section">
                              <div class="mw-rule-section-label">
                                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="5 12 12 5 19 12"/><polyline points="5 19 12 12 19 19"/></svg>
                                  ACTIONS — …then do this:
                              </div>
                              <div id="ruleActionsList"></div>
                              <button class="btn btn-sm btn-outline-secondary mt-1" onclick="addActionRow()">+ Add Action</button>
                          </div>

                          <!-- Rate limiting -->
                          <div class="row mt-3">
                              <div class="col-md-6">
                                  <div class="form-group mb-0">
                                      <label class="small">Cooldown (hours between re-triggers per contact)</label>
                                      <input type="number" class="form-control form-control-sm" id="ruleCooldown" value="0" min="0" placeholder="0 = no cooldown">
                                  </div>
                              </div>
                              <div class="col-md-6">
                                  <div class="form-group mb-0">
                                      <label class="small">Max total executions (0 = unlimited)</label>
                                      <input type="number" class="form-control form-control-sm" id="ruleMaxExec" value="0" min="0">
                                  </div>
                              </div>
                          </div>
                      </div>
                      <div class="modal-footer">
                          <button class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                          <button class="btn btn-success" onclick="saveRule()">Save Rule</button>
                      </div>
                  </div>
              </div>
          </div>

          <!-- Preview Modal -->
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

              var csrf     = '<?php echo generateCSRFToken(); ?>';
              var canEdit  = <?php echo json_encode($canEdit); ?>;
              var templates = [];
              var products  = [];
              var triggerDefs  = [];
              var conditionDefs = [];
              var actionDefs   = [];

              var currentSection    = 'campaigns';
              var campCurrentStatus = '';
              var campCurrentPage   = 1;
              var autoCurrentFilter = '';
              var autoCurrentPage   = 1;
              var optinCurrentFilter = '';
              var optinCurrentPage  = 1;
              var currentPeriod     = '30';

              // ── Init ─────────────────────────────────────────────────────
              loadCampaignStats();
              loadCampaigns();
              loadTemplates();
              loadProducts();
              loadAutomationMeta();

              // Campaign tab clicks
              document.querySelectorAll('#campTabs .mw-camp-tab').forEach(function(t) {
                  t.addEventListener('click', function() {
                      document.querySelectorAll('#campTabs .mw-camp-tab').forEach(function(x){x.classList.remove('active');});
                      this.classList.add('active');
                      campCurrentStatus = this.dataset.status;
                      campCurrentPage   = 1;
                      loadCampaigns();
                  });
              });

              // Opt-In resend days toggle — #optBulkTarget only renders for
              // users with marketing.edit ($canEdit), so guard against null
              // for view-only users.
              var optBulkTargetEl = document.getElementById('optBulkTarget');
              if (optBulkTargetEl) {
                  optBulkTargetEl.addEventListener('change', function() {
                      document.getElementById('optResendDaysWrap').style.display = this.value === 'pending' ? '' : 'none';
                  });
              }

              // ── Section switching ────────────────────────────────────────
              window.switchSection = function(section) {
                  document.querySelectorAll('.mw-marketing-tabs .nav-link').forEach(function(l){l.classList.remove('active');});
                  document.querySelector('[data-section="'+section+'"]').classList.add('active');

                  ['campaigns','automations','analytics','optin'].forEach(function(s) {
                      document.getElementById('section'+s.charAt(0).toUpperCase()+s.slice(1)).style.display = (s===section)?'':'none';
                  });

                  document.getElementById('btnNewCampaign') && (document.getElementById('btnNewCampaign').style.display = section==='campaigns'&&canEdit ? '' : 'none');
                  document.getElementById('btnNewRule')     && (document.getElementById('btnNewRule').style.display = section==='automations'&&canEdit ? '' : 'none');

                  currentSection = section;

                  if (section === 'automations' && !document.getElementById('autoListContainer').querySelector('.mw-auto-card')) {
                      loadAutomations();
                  }
                  if (section === 'analytics') loadAnalytics();
                  if (section === 'optin') { loadOptinStats(); loadOptinList(); }
              };

              // Initial button visibility
              if (canEdit) {
                  document.getElementById('btnNewCampaign').style.display = '';
              }


              // ══════════════════════════════════════════════════════════════
              // SECTION 1 — CAMPAIGNS
              // ══════════════════════════════════════════════════════════════

              function loadCampaignStats() {
                  fetch('/crm/api/campaigns.php?action=stats').then(r=>r.json()).then(function(d) {
                      if (!d.success) return;
                      document.getElementById('statCampaigns').textContent = d.campaigns || 0;
                      document.getElementById('statSentMonth').textContent = d.sent_this_month || 0;
                      document.getElementById('statOpened').textContent    = d.total_opened || 0;
                      document.getElementById('statOpenRate').textContent  = (d.open_rate||0) + '%';
                  });
              }

              function loadCampaigns() {
                  var url = '/crm/api/campaigns.php?action=list&page=' + campCurrentPage;
                  if (campCurrentStatus) url += '&status=' + encodeURIComponent(campCurrentStatus);
                  fetch(url).then(r=>r.json()).then(function(d) {
                      if (!d.success) { showError('campListContainer', d.error||'Failed to load'); return; }
                      renderCampaignList(d.campaigns||[]);
                      renderPagination('campPagination', d.page, d.pages, function(p){ campCurrentPage=p; loadCampaigns(); });
                  }).catch(function(){ showError('campListContainer', 'Network error'); });
              }

              function loadTemplates() {
                  fetch('/crm/api/campaigns.php?action=templates').then(r=>r.json()).then(function(d) {
                      if (!d.success) return;
                      templates = d.templates||[];
                      var sel = document.getElementById('campTemplate');
                      sel.innerHTML = '<option value="">— Select template —</option>';
                      templates.forEach(function(t) {
                          sel.innerHTML += '<option value="'+t.id+'">'+esc(t.name)+' ('+esc(t.category)+')</option>';
                      });
                  });
              }

              function loadProducts() {
                  fetch('/crm/products/api-products.php?action=list-products').then(r=>r.json()).then(function(d) {
                      var list = d.products||d.data||[];
                      products = list;
                      var sel = document.getElementById('campProduct');
                      sel.innerHTML = '<option value="">— No product filter —</option>';
                      list.forEach(function(p){ sel.innerHTML += '<option value="'+p.id+'">'+esc(p.name)+'</option>'; });
                  }).catch(function(){});
              }

              function renderCampaignList(campaigns) {
                  var container = document.getElementById('campListContainer');
                  if (!campaigns.length) {
                      container.innerHTML = '<div class="mw-camp-empty"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg><p>No campaigns found.</p></div>';
                      return;
                  }
                  var html = '<div class="mw-camp-grid">';
                  campaigns.forEach(function(c) {
                      var sc = 'mw-camp-badge-'+(c.status||'draft');
                      var date = c.created_at ? new Date(c.created_at).toLocaleDateString('en-CA',{year:'numeric',month:'short',day:'numeric'}) : '';
                      var revBadge = c.total_revenue > 0 ? '<span class="badge badge-success ml-2">$'+parseFloat(c.total_revenue||0).toFixed(0)+' rev</span>' : '';
                      html += '<div class="mw-camp-card" data-id="'+c.id+'">';
                      html += '  <div class="mw-camp-card-header"><h4>'+esc(c.name)+revBadge+'</h4><span class="mw-camp-badge '+sc+'">'+esc(c.status)+'</span></div>';
                      html += '  <div class="mw-camp-card-meta">';
                      if (c.template_name) html += '<span>Template: '+esc(c.template_name)+'</span>';
                      if (c.product_name)  html += '<span>Product: '+esc(c.product_name)+'</span>';
                      html += '<span>Segment: '+esc(fmtSeg(c.segment_type))+'</span>';
                      html += '<span>'+esc(date)+'</span>';
                      html += '  </div>';
                      html += '  <div class="mw-camp-card-stats">';
                      html += '    <div class="mw-camp-card-stat"><span class="num">'+(c.recipient_count||0)+'</span><span class="label">Recipients</span></div>';
                      html += '    <div class="mw-camp-card-stat"><span class="num">'+(c.sent_count||0)+'</span><span class="label">Sent</span></div>';
                      html += '    <div class="mw-camp-card-stat"><span class="num">'+(c.open_count||0)+'</span><span class="label">Opened</span></div>';
                      html += '    <div class="mw-camp-card-stat"><span class="num">'+(c.click_count||0)+'</span><span class="label">Clicked</span></div>';
                      html += '  </div>';
                      if (canEdit) {
                          html += '  <div class="mw-camp-card-actions">';
                          if (c.status==='draft'||c.status==='scheduled') {
                              html += '<button class="btn btn-sm btn-outline-primary" onclick="editCampaign('+c.id+')">Edit</button>';
                              html += '<button class="btn btn-sm btn-success" onclick="sendCampaign('+c.id+')">Send Now</button>';
                              html += '<button class="btn btn-sm btn-outline-danger" onclick="deleteCampaign('+c.id+')">Cancel</button>';
                          }
                          if (c.status==='sending') html += '<button class="btn btn-sm btn-warning" onclick="pauseCampaign('+c.id+')">Pause</button>';
                          if (c.status==='paused')  html += '<button class="btn btn-sm btn-success" onclick="resumeCampaign('+c.id+')">Resume</button>';
                          html += '    <button class="btn btn-sm btn-outline-info" onclick="previewCampaignById('+c.id+')">Preview</button>';
                          if (c.status==='completed') html += '<button class="btn btn-sm btn-outline-secondary" onclick="viewCampaignAnalytics('+c.id+')">Analytics</button>';
                          html += '  </div>';
                      }
                      html += '</div>';
                  });
                  html += '</div>';
                  container.innerHTML = html;
              }

              window.openCampaignModal = function(data) {
                  document.getElementById('campId').value = data ? data.id : 0;
                  document.getElementById('campName').value = data ? data.name : '';
                  document.getElementById('campTemplate').value = data ? (data.template_id||'') : '';
                  document.getElementById('campProduct').value  = data ? (data.product_id||'') : '';
                  document.getElementById('campSegment').value  = data ? (data.segment_type||'all_marketing') : 'all_marketing';
                  document.getElementById('campSchedule').value = data&&data.schedule_date ? data.schedule_date.replace(' ','T').substring(0,16) : '';
                  document.getElementById('campSubject').value  = data ? (data.subject_override||'') : '';
                  document.getElementById('campBody').value     = data ? (data.body_override||'') : '';
                  document.getElementById('campaignModalTitle').textContent = data ? 'Edit Campaign' : 'New Campaign';
                  updateRecipientCount();
                  $('#campaignModal').modal('show');
              };

              window.editCampaign = function(id) {
                  fetch('/crm/api/campaigns.php?action=get&id='+id).then(r=>r.json()).then(function(d){
                      if (d.success) openCampaignModal(d.campaign); else alert('Error: '+(d.error||'Unknown'));
                  });
              };

              window.saveCampaign = function() {
                  fetch('/crm/api/campaigns.php?action=save', {
                      method:'POST', headers:{'Content-Type':'application/json'},
                      body: JSON.stringify({
                          csrf_token: csrf,
                          id: parseInt(document.getElementById('campId').value)||0,
                          name: document.getElementById('campName').value,
                          template_id: document.getElementById('campTemplate').value||null,
                          product_id:  document.getElementById('campProduct').value||null,
                          segment_type: document.getElementById('campSegment').value,
                          schedule_date: document.getElementById('campSchedule').value||null,
                          subject_override: document.getElementById('campSubject').value||null,
                          body_override: document.getElementById('campBody').value||null,
                          trigger_type: 'manual',
                      })
                  }).then(r=>r.json()).then(function(d){
                      if (d.success) { $('#campaignModal').modal('hide'); loadCampaigns(); loadCampaignStats(); }
                      else alert('Error: '+(d.error||'Unknown'));
                  });
              };

              window.sendCampaign = function(id) {
                  if (!confirm('Send this campaign now?')) return;
                  fetch('/crm/api/campaigns.php?action=send-now',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:id,csrf_token:csrf})}).then(r=>r.json()).then(function(d){
                      if (d.success) { alert(d.message||'Campaign is sending!'); loadCampaigns(); loadCampaignStats(); }
                      else alert('Error: '+(d.error||'Unknown'));
                  });
              };

              window.pauseCampaign  = function(id){ post('campaigns','pause',{id:id},function(){loadCampaigns();}); };
              window.resumeCampaign = function(id){ post('campaigns','resume',{id:id},function(){loadCampaigns();}); };
              window.deleteCampaign = function(id){
                  if (!confirm('Cancel this campaign?')) return;
                  post('campaigns','delete',{id:id},function(){loadCampaigns();loadCampaignStats();});
              };

              window.updateRecipientCount = function() {
                  var seg = document.getElementById('campSegment').value;
                  var pid = document.getElementById('campProduct').value||0;
                  var el  = document.getElementById('recipientCount');
                  el.textContent = 'Counting...';
                  fetch('/crm/api/campaigns.php?action=get-recipients&segment_type='+encodeURIComponent(seg)+'&product_id='+pid)
                      .then(r=>r.json()).then(function(d){
                          if (d.success) {
                              el.textContent = d.count+' recipient'+(d.count!==1?'s':'')+' match';
                              el.style.color = d.count>0?'var(--mw-green)':'#dc3545';
                          }
                      }).catch(function(){ el.textContent='Unable to count'; });
              };

              window.loadTemplatePreview = function() {
                  var tid = document.getElementById('campTemplate').value;
                  if (!tid) return;
                  var tpl = templates.find(function(t){return t.id==tid;});
                  if (tpl && !document.getElementById('campSubject').value) {
                      document.getElementById('campSubject').value = tpl.subject;
                  }
              };

              window.insertMergeField = function() {
                  var fields = ['first_name','last_name','email','property_address','product_name','product_price','product_description','cta_url','review_url','unsubscribe_url','company_name','company_phone'];
                  var choice = prompt('Available fields:\n'+fields.map(function(f){return '{{'+f+'}}';}).join('\n')+'\n\nType field name:');
                  if (choice) {
                      var field = '{{'+choice.replace(/[{}]/g,'')+'}}';
                      var ta = document.getElementById('campBody');
                      var s = ta.selectionStart;
                      ta.value = ta.value.substring(0,s)+field+ta.value.substring(ta.selectionEnd);
                  }
              };

              window.previewCampaign = function() {
                  var tid = document.getElementById('campTemplate').value;
                  var cid = document.getElementById('campId').value;
                  if (cid && cid!=='0') previewCampaignById(cid);
                  else if (tid) fetch('/crm/api/campaigns.php?action=preview&template_id='+tid).then(r=>r.json()).then(showPreview);
                  else alert('Select a template or save the campaign first.');
              };

              window.previewCampaignById = function(id) {
                  fetch('/crm/api/campaigns.php?action=preview&campaign_id='+id).then(r=>r.json()).then(function(d){
                      if (!d.success){alert(d.error);return;} showPreview(d);
                  });
              };

              window.viewCampaignAnalytics = function(id) {
                  switchSection('analytics');
                  loadCampaignDetail(id);
              };

              function showPreview(d) {
                  document.getElementById('previewTo').textContent = d.contact ? d.contact.name+' <'+d.contact.email+'>' : '—';
                  document.getElementById('previewSubject').textContent = d.subject||'—';
                  document.getElementById('previewFrame').srcdoc = d.body||'<p>No content</p>';
                  $('#previewModal').modal('show');
              }

              function fmtSeg(t) {
                  return {all_marketing:'All Marketing',never_purchased_product:'Never Purchased Product',inactive_3mo:'Inactive 3mo',inactive_6mo:'Inactive 6mo',service_type:'Service Type',custom_list:'Custom List'}[t]||t;
              }


              // ══════════════════════════════════════════════════════════════
              // SECTION 2 — AUTOMATIONS
              // ══════════════════════════════════════════════════════════════

              function loadAutomationMeta() {
                  fetch('/crm/api/automations.php?action=trigger-types').then(r=>r.json()).then(function(d){
                      if (!d.success) return;
                      triggerDefs   = d.triggers||[];
                      conditionDefs = d.conditions||[];
                      actionDefs    = d.actions||[];
                      // Populate trigger select
                      var sel = document.getElementById('ruleTriggerType');
                      sel.innerHTML = '<option value="">— Select trigger —</option>';
                      triggerDefs.forEach(function(t){
                          sel.innerHTML += '<option value="'+t.type+'">'+esc(t.label)+'</option>';
                      });
                  });
              }

              function loadAutomations() {
                  var url = '/crm/api/automations.php?action=list&page='+autoCurrentPage;
                  if (autoCurrentFilter) url += '&filter='+autoCurrentFilter;
                  fetch(url).then(r=>r.json()).then(function(d){
                      if (!d.success){ showError('autoListContainer',d.error||'Failed'); return; }
                      document.getElementById('autoStatEnabled').textContent = d.enabled_count||0;
                      document.getElementById('autoStatQueue').textContent   = d.pending_queue||0;
                      document.getElementById('autoStatTotal').textContent   = d.total||0;
                      var badge = document.getElementById('badgeActiveRules');
                      if (d.enabled_count>0){ badge.textContent=d.enabled_count; badge.style.display=''; }
                      renderAutomationList(d.rules||[]);
                  }).catch(function(){ showError('autoListContainer','Network error'); });
              }

              function renderAutomationList(rules) {
                  var c = document.getElementById('autoListContainer');
                  if (!rules.length) {
                      c.innerHTML = '<div class="mw-camp-empty"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg><p>No automation rules yet.</p><p class="text-muted small">Click "New Automation" to build your first if-this-then-that rule.</p></div>';
                      return;
                  }
                  var html = '<div class="mw-auto-grid">';
                  rules.forEach(function(r) {
                      var tDef = triggerDefs.find(function(t){return t.type===r.trigger_type;})||{label:r.trigger_type, icon:'zap'};
                      var enabledClass = r.is_enabled ? 'mw-auto-card--enabled' : 'mw-auto-card--disabled';
                      html += '<div class="mw-auto-card '+enabledClass+'" data-id="'+r.id+'">';
                      html += '  <div class="mw-auto-card-header">';
                      html += '    <div class="mw-auto-toggle">';
                      if (canEdit) {
                          html += '      <div class="mw-rule-toggle-switch '+(r.is_enabled?'on':'')+'" onclick="toggleRule('+r.id+',this)" title="'+(r.is_enabled?'Disable':'Enable')+'"></div>';
                      }
                      html += '    </div>';
                      html += '    <div class="mw-auto-card-info">';
                      html += '      <h4 class="mw-auto-card-name">'+esc(r.name)+'</h4>';
                      html += '      <span class="mw-auto-trigger-badge">'+esc(tDef.label)+'</span>';
                      if (r.description) html += '      <p class="mw-auto-card-desc">'+esc(r.description)+'</p>';
                      html += '    </div>';
                      html += '  </div>';
                      html += '  <div class="mw-auto-card-stats">';
                      html += '    <div><span class="num">'+r.execution_count+'</span><span class="lbl">Executions</span></div>';
                      html += '    <div><span class="num">'+r.completions_30d+'</span><span class="lbl">Last 30 days</span></div>';
                      html += '    <div><span class="num">'+(r.actions||[]).length+'</span><span class="lbl">Actions</span></div>';
                      html += '    <div><span class="num">'+(r.cooldown_hours?r.cooldown_hours+'h':'—')+'</span><span class="lbl">Cooldown</span></div>';
                      html += '  </div>';
                      if (canEdit) {
                          html += '  <div class="mw-camp-card-actions">';
                          html += '    <button class="btn btn-sm btn-outline-primary" onclick="editRule('+r.id+')">Edit</button>';
                          html += '    <button class="btn btn-sm btn-outline-danger" onclick="deleteRule('+r.id+')">Delete</button>';
                          html += '  </div>';
                      }
                      html += '</div>';
                  });
                  html += '</div>';
                  c.innerHTML = html;
              }

              window.filterAutomations = function(btn, filter) {
                  document.querySelectorAll('[data-autofilter]').forEach(function(b){b.classList.remove('active');});
                  btn.classList.add('active');
                  autoCurrentFilter = filter;
                  autoCurrentPage   = 1;
                  loadAutomations();
              };

              window.openRuleModal = function(data) {
                  document.getElementById('ruleId').value    = data ? data.id : 0;
                  document.getElementById('ruleName').value  = data ? data.name : '';
                  document.getElementById('ruleDesc').value  = data ? (data.description||'') : '';
                  document.getElementById('ruleEnabled').value = data ? (data.is_enabled?'1':'0') : '1';
                  document.getElementById('ruleCooldown').value = data ? (data.cooldown_hours||0) : 0;
                  document.getElementById('ruleMaxExec').value  = data ? (data.max_executions||0) : 0;
                  document.getElementById('ruleTriggerType').value = data ? (data.trigger_type||'') : '';
                  document.getElementById('ruleConditionsList').innerHTML = '';
                  document.getElementById('ruleActionsList').innerHTML    = '';
                  document.getElementById('ruleModalTitle').textContent = data ? 'Edit Automation Rule' : 'New Automation Rule';

                  if (data) {
                      renderTriggerConfig(data.trigger_config||{});
                      (data.conditions||[]).forEach(function(c){ addConditionRow(c); });
                      (data.actions||[]).forEach(function(a){ addActionRow(a); });
                  } else {
                      renderTriggerConfig();
                  }
                  $('#ruleModal').modal('show');
              };

              window.editRule = function(id) {
                  fetch('/crm/api/automations.php?action=get&id='+id).then(r=>r.json()).then(function(d){
                      if (d.success) openRuleModal(d.rule); else alert('Error: '+(d.error||'Unknown'));
                  });
              };

              window.saveRule = function() {
                  var conditions = collectConditions();
                  var actions    = collectActions();
                  var trigConfig = collectTriggerConfig();

                  fetch('/crm/api/automations.php?action=save',{
                      method:'POST',headers:{'Content-Type':'application/json'},
                      body: JSON.stringify({
                          csrf_token:     csrf,
                          id:             parseInt(document.getElementById('ruleId').value)||0,
                          name:           document.getElementById('ruleName').value,
                          description:    document.getElementById('ruleDesc').value,
                          is_enabled:     parseInt(document.getElementById('ruleEnabled').value),
                          trigger_type:   document.getElementById('ruleTriggerType').value,
                          trigger_config: trigConfig,
                          conditions:     conditions,
                          actions:        actions,
                          cooldown_hours: parseInt(document.getElementById('ruleCooldown').value)||0,
                          max_executions: parseInt(document.getElementById('ruleMaxExec').value)||0,
                      })
                  }).then(r=>r.json()).then(function(d){
                      if (d.success){ $('#ruleModal').modal('hide'); loadAutomations(); }
                      else alert('Error: '+(d.error||'Unknown'));
                  });
              };

              window.toggleRule = function(id, el) {
                  fetch('/crm/api/automations.php?action=toggle',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:id,csrf_token:csrf})})
                      .then(r=>r.json()).then(function(d){
                          if (d.success){
                              el.classList.toggle('on', !!d.is_enabled);
                              var card = el.closest('.mw-auto-card');
                              if (card) {
                                  card.classList.toggle('mw-auto-card--enabled', !!d.is_enabled);
                                  card.classList.toggle('mw-auto-card--disabled', !d.is_enabled);
                              }
                              loadAutomations();
                          }
                      });
              };

              window.deleteRule = function(id) {
                  if (!confirm('Delete this automation rule? This cannot be undone.')) return;
                  fetch('/crm/api/automations.php?action=delete',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:id,csrf_token:csrf})})
                      .then(r=>r.json()).then(function(d){ if(d.success) loadAutomations(); });
              };

              window.toggleAutoLog = function() {
                  var el = document.getElementById('autoLogContainer');
                  if (el.style.display === 'none') {
                      el.style.display = '';
                      loadAutoLog();
                  } else {
                      el.style.display = 'none';
                  }
              };

              function loadAutoLog() {
                  fetch('/crm/api/automations.php?action=logs&page=1').then(r=>r.json()).then(function(d){
                      if (!d.success){ document.getElementById('autoLogContainer').innerHTML='<p class="text-danger">'+esc(d.error||'Error')+'</p>'; return; }
                      var logs = d.logs||[];
                      if (!logs.length){ document.getElementById('autoLogContainer').innerHTML='<p class="text-muted small">No activity yet.</p>'; return; }
                      var html = '<div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>Rule</th><th>Contact</th><th>Trigger</th><th>Status</th><th>Time</th></tr></thead><tbody>';
                      logs.forEach(function(l){
                          var sc = {'completed':'success','triggered':'info','conditions_failed':'warning','failed':'danger'}[l.status]||'secondary';
                          html += '<tr>';
                          html += '<td class="small">'+esc(l.rule_name||'#'+l.rule_id)+'</td>';
                          html += '<td class="small">'+(l.first_name?esc(l.first_name+' '+l.last_name):'—')+'</td>';
                          html += '<td class="small">'+esc(l.trigger_type||'—')+'</td>';
                          html += '<td><span class="badge badge-'+sc+'">'+esc(l.status)+'</span></td>';
                          html += '<td class="small text-muted">'+esc(l.created_at||'')+'</td>';
                          html += '</tr>';
                      });
                      html += '</tbody></table></div>';
                      document.getElementById('autoLogContainer').innerHTML = html;
                  });
              }

              // ── Rule Builder helpers ─────────────────────────────────────
              window.renderTriggerConfig = function(existing) {
                  var type = document.getElementById('ruleTriggerType').value;
                  var tDef = triggerDefs.find(function(t){return t.type===type;});
                  var container = document.getElementById('ruleTriggerConfig');
                  container.innerHTML = '';
                  if (!tDef || !tDef.config_fields || !tDef.config_fields.length) return;
                  tDef.config_fields.forEach(function(f){
                      var val = existing&&existing[f.key] !== undefined ? existing[f.key] : (f.default||'');
                      var el = '<div class="form-group form-group-sm mb-2"><label class="small">'+esc(f.label)+'</label>';
                      if (f.type==='select') {
                          el += '<select class="form-control form-control-sm" data-config-key="'+esc(f.key)+'">';
                          (f.options||[]).forEach(function(o){ el += '<option value="'+esc(o)+'" '+(val==o?'selected':'')+'>'+esc(o)+'</option>'; });
                          el += '</select>';
                      } else {
                          el += '<input type="'+(f.type||'text')+'" class="form-control form-control-sm" data-config-key="'+esc(f.key)+'" value="'+esc(String(val))+'" placeholder="'+(f.placeholder||'')+'">';
                      }
                      el += '</div>';
                      container.innerHTML += el;
                  });
              };

              function collectTriggerConfig() {
                  var config = {};
                  document.querySelectorAll('#ruleTriggerConfig [data-config-key]').forEach(function(el){
                      config[el.dataset.configKey] = el.value;
                  });
                  return config;
              }

              window.addConditionRow = function(existing) {
                  var list = document.getElementById('ruleConditionsList');
                  var idx = list.children.length;
                  var row = document.createElement('div');
                  row.className = 'mw-rule-row d-flex align-items-center mb-2';

                  var fieldSel = '<select class="form-control form-control-sm mr-2" style="flex:2" data-cond-field>';
                  conditionDefs.forEach(function(c){
                      var sel = existing&&existing.field===c.field?'selected':'';
                      fieldSel += '<option value="'+esc(c.field)+'" '+sel+'>'+esc(c.label)+'</option>';
                  });
                  fieldSel += '</select>';

                  var opSel = '<select class="form-control form-control-sm mr-2" style="flex:1" data-cond-op>';
                  var defaultField = existing?existing.field:(conditionDefs[0]&&conditionDefs[0].field);
                  var fDef = conditionDefs.find(function(c){return c.field===defaultField;})||{operators:['eq']};
                  var opMap = {eq:'equals',gt:'>',gte:'>=',lt:'<',lte:'<=',not_eq:'not equals',has:'has',not_has:"doesn't have",contains:'contains'};
                  fDef.operators.forEach(function(o){
                      var sel = existing&&existing.operator===o?'selected':'';
                      opSel += '<option value="'+o+'" '+sel+'>'+esc(opMap[o]||o)+'</option>';
                  });
                  opSel += '</select>';

                  row.innerHTML = fieldSel + opSel +
                      '<input type="text" class="form-control form-control-sm mr-2" style="flex:2" data-cond-val placeholder="value" value="'+esc(existing?String(existing.value||''):'')+'">' +
                      '<button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest(\'.mw-rule-row\').remove()">×</button>';
                  list.appendChild(row);
              };

              function collectConditions() {
                  var rows = document.querySelectorAll('#ruleConditionsList .mw-rule-row');
                  var out = [];
                  rows.forEach(function(row){
                      out.push({
                          field:    row.querySelector('[data-cond-field]').value,
                          operator: row.querySelector('[data-cond-op]').value,
                          value:    row.querySelector('[data-cond-val]').value,
                      });
                  });
                  return out;
              }

              window.addActionRow = function(existing) {
                  var list = document.getElementById('ruleActionsList');
                  var row = document.createElement('div');
                  row.className = 'mw-rule-action-row mb-2';

                  var typeSel = '<div class="d-flex align-items-center mb-1"><select class="form-control form-control-sm mr-2" data-action-type onchange="renderActionConfig(this)">';
                  actionDefs.forEach(function(a){
                      var sel = existing&&existing.type===a.type?'selected':'';
                      typeSel += '<option value="'+esc(a.type)+'" '+sel+'>'+esc(a.label)+'</option>';
                  });
                  typeSel += '</select><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest(\'.mw-rule-action-row\').remove()">×</button></div>';
                  typeSel += '<div class="mw-action-config pl-2"></div>';
                  row.innerHTML = typeSel;
                  list.appendChild(row);

                  // Render config for the selected type
                  var typeEl = row.querySelector('[data-action-type]');
                  renderActionConfig(typeEl, existing?existing.config:null);
              };

              window.renderActionConfig = function(selectEl, existingConfig) {
                  var type    = selectEl.value;
                  var aDef    = actionDefs.find(function(a){return a.type===type;});
                  var container = selectEl.closest('.mw-rule-action-row').querySelector('.mw-action-config');
                  container.innerHTML = '';
                  if (!aDef||!aDef.config_fields||!aDef.config_fields.length) return;
                  aDef.config_fields.forEach(function(f){
                      var val = existingConfig&&existingConfig[f.key]!==undefined ? existingConfig[f.key] : (f.default||'');
                      var el = '<div class="form-group form-group-sm mb-1"><label class="small text-muted">'+esc(f.label)+'</label>';
                      if (f.type==='textarea') {
                          el += '<textarea class="form-control form-control-sm" data-aconf-key="'+esc(f.key)+'" rows="3">'+esc(String(val))+'</textarea>';
                      } else {
                          el += '<input type="text" class="form-control form-control-sm" data-aconf-key="'+esc(f.key)+'" value="'+esc(String(val))+'" placeholder="'+(f.placeholder||'')+'">';
                      }
                      el += '</div>';
                      container.innerHTML += el;
                  });
              };

              function collectActions() {
                  var rows = document.querySelectorAll('#ruleActionsList .mw-rule-action-row');
                  var out = [];
                  rows.forEach(function(row){
                      var type = row.querySelector('[data-action-type]').value;
                      var config = {};
                      row.querySelectorAll('[data-aconf-key]').forEach(function(el){
                          config[el.dataset.aconfKey] = el.value;
                      });
                      out.push({type:type, config:config});
                  });
                  return out;
              }


              // ══════════════════════════════════════════════════════════════
              // SECTION 3 — ANALYTICS
              // ══════════════════════════════════════════════════════════════

              window.setPeriod = function(btn) {
                  document.querySelectorAll('.mw-period-btn').forEach(function(b){b.classList.remove('active');});
                  btn.classList.add('active');
                  currentPeriod = btn.dataset.period;
                  loadAnalytics();
              };

              function loadAnalytics() {
                  loadAnalyticsOverview();
                  loadFunnel();
                  loadTerritory();
                  loadHourly();
                  loadRevenueSummary();
              }

              function loadAnalyticsOverview() {
                  fetch('/crm/api/marketing-analytics.php?action=overview&period='+currentPeriod).then(r=>r.json()).then(function(d){
                      if (!d.success) return;
                      document.getElementById('anlSent').textContent      = (d.sent||0).toLocaleString();
                      document.getElementById('anlOpenRate').textContent  = (d.open_rate||0)+'%';
                      document.getElementById('anlClickRate').textContent = (d.click_rate||0)+'%';
                      document.getElementById('anlRevenue').textContent   = '$'+(d.revenue||0).toFixed(2);
                  });
              }

              function loadFunnel() {
                  fetch('/crm/api/marketing-analytics.php?action=conversions&period='+currentPeriod).then(r=>r.json()).then(function(d){
                      if (!d.success) return;
                      var stages = d.funnel||[];
                      var html = '<div class="mw-funnel">';
                      stages.forEach(function(s){
                          var w = Math.max(5, s.rate);
                          html += '<div class="mw-funnel-stage">';
                          html += '  <div class="mw-funnel-bar" style="width:'+w+'%"><span class="mw-funnel-count">'+s.count+'</span></div>';
                          html += '  <div class="mw-funnel-label">'+esc(s.stage)+' <span class="text-muted small">('+s.rate+'%)</span></div>';
                          html += '</div>';
                      });
                      html += '</div>';
                      if (d.revenue > 0) html += '<p class="mt-2 mb-0"><strong>Total revenue attributed: $'+parseFloat(d.revenue).toFixed(2)+'</strong></p>';
                      document.getElementById('funnelContainer').innerHTML = html;
                  });
              }

              function loadTerritory() {
                  fetch('/crm/api/marketing-analytics.php?action=territory').then(r=>r.json()).then(function(d){
                      if (!d.success) return;
                      var rows = d.territory||[];
                      if (!rows.length){ document.getElementById('territoryContainer').innerHTML='<p class="p-3 text-muted small">No territory data yet.</p>'; return; }
                      var html = '<table class="table table-sm mb-0"><thead><tr><th>City</th><th>Sent</th><th>Opened</th><th>Clicked</th><th>Revenue</th></tr></thead><tbody>';
                      rows.forEach(function(r){
                          var or = r.sent>0?Math.round((r.opened/r.sent)*100):0;
                          html += '<tr><td>'+esc(r.city)+'</td><td>'+r.sent+'</td><td>'+r.opened+' <span class="text-muted small">('+or+'%)</span></td><td>'+r.clicked+'</td><td>'+(r.revenue>0?'$'+parseFloat(r.revenue).toFixed(2):'—')+'</td></tr>';
                      });
                      html += '</tbody></table>';
                      document.getElementById('territoryContainer').innerHTML = html;
                  });
              }

              function loadHourly() {
                  fetch('/crm/api/marketing-analytics.php?action=hourly').then(r=>r.json()).then(function(d){
                      if (!d.success) return;
                      var rows = d.hourly||[];
                      if (!rows.length){ document.getElementById('hourlyContainer').innerHTML='<p class="text-muted small">No engagement data yet.</p>'; return; }
                      var maxOpens = Math.max.apply(null, rows.map(function(r){return parseInt(r.opens);}));
                      var html = '<div class="mw-hourly-chart">';
                      for (var h=0; h<24; h++) {
                          var r = rows.find(function(x){return parseInt(x.hour)===h;})||{opens:0,clicks:0};
                          var pct = maxOpens>0 ? Math.round((parseInt(r.opens)/maxOpens)*100) : 0;
                          var label = h===0?'12am':(h<12?h+'am':(h===12?'12pm':(h-12)+'pm'));
                          html += '<div class="mw-hour-bar" title="'+label+': '+r.opens+' opens, '+r.clicks+' clicks">';
                          html += '  <div class="mw-hour-fill" style="height:'+pct+'%"></div>';
                          html += '  <div class="mw-hour-lbl">'+label+'</div>';
                          html += '</div>';
                      }
                      html += '</div>';
                      document.getElementById('hourlyContainer').innerHTML = html;
                  });
              }

              function loadRevenueSummary() {
                  fetch('/crm/api/marketing-analytics.php?action=revenue&period='+currentPeriod).then(r=>r.json()).then(function(d){
                      if (!d.success) return;
                      var top = d.top_campaigns||[];
                      if (!top.length){ document.getElementById('revenueContainer').innerHTML='<p class="p-3 text-muted small">No revenue data yet. Revenue is attributed when a campaign click leads to a booked job or paid invoice.</p>'; return; }
                      var html = '<table class="table table-sm mb-0"><thead><tr><th>Campaign</th><th>Revenue</th><th>Converters</th></tr></thead><tbody>';
                      top.forEach(function(r){
                          html += '<tr><td>'+esc(r.name)+'</td><td>$'+parseFloat(r.revenue).toFixed(2)+'</td><td>'+r.converters+'</td></tr>';
                      });
                      html += '</tbody></table>';
                      document.getElementById('revenueContainer').innerHTML = html;
                  });
              }

              function loadCampaignDetail(id) {
                  // Future: load per-campaign analytics in a panel
              }


              // ══════════════════════════════════════════════════════════════
              // SECTION 4 — OPT-IN
              // ══════════════════════════════════════════════════════════════

              function loadOptinStats() {
                  fetch('/crm/api/optin.php?action=stats').then(r=>r.json()).then(function(d){
                      if (!d.success) return;
                      document.getElementById('optTotal').textContent     = d.total_contacts||0;
                      document.getElementById('optConfirmed').textContent = d.confirmed||0;
                      document.getElementById('optPending').textContent   = d.pending||0;
                      document.getElementById('optUnsub').textContent     = d.unsubscribed||0;
                      // Risk banner
                      var banner = document.getElementById('optinRiskBanner');
                      if (d.confirm_rate < 50 && d.total_contacts > 0) banner.classList.remove('d-none');
                      else banner.classList.add('d-none');
                  });
              }

              function loadOptinList() {
                  var url = '/crm/api/optin.php?action=list&page='+optinCurrentPage;
                  if (optinCurrentFilter) url += '&filter='+optinCurrentFilter;
                  fetch(url).then(r=>r.json()).then(function(d){
                      if (!d.success){ showError('optinListContainer',d.error||'Failed'); return; }
                      renderOptinList(d.contacts||[]);
                      renderPagination('optinPagination', d.page||1, d.pages||1, function(p){ optinCurrentPage=p; loadOptinList(); });
                  });
              }

              function renderOptinList(contacts) {
                  var c = document.getElementById('optinListContainer');
                  if (!contacts.length){
                      c.innerHTML = '<div class="mw-camp-empty"><p>No contacts in this filter.</p></div>'; return;
                  }
                  var html = '<div class="table-responsive"><table class="table table-hover table-sm"><thead><tr><th>Contact</th><th>Email</th><th>Status</th><th>Sent</th><th>Confirmed</th>';
                  if (canEdit) html += '<th></th>';
                  html += '</tr></thead><tbody>';
                  contacts.forEach(function(c){
                      var st = c.optin_status||'never_sent';
                      var sc = {confirmed:'success',pending:'warning',unsubscribed:'danger',never_sent:'secondary'}[st]||'secondary';
                      var stLabel = {confirmed:'Confirmed',pending:'Pending',unsubscribed:'Unsubscribed',never_sent:'Never Sent'}[st]||st;
                      html += '<tr>';
                      html += '<td>'+esc((c.first_name||'')+' '+(c.last_name||''))+'</td>';
                      html += '<td class="small">'+esc(c.email||'')+'</td>';
                      html += '<td><span class="badge badge-'+sc+'">'+esc(stLabel)+'</span></td>';
                      html += '<td class="small text-muted">'+(c.sent_at?esc(c.sent_at.substring(0,10)):'—')+'</td>';
                      html += '<td class="small text-muted">'+(c.confirmed_at?esc(c.confirmed_at.substring(0,10)):'—')+'</td>';
                      if (canEdit) {
                          html += '<td>';
                          if (st !== 'confirmed' && st !== 'unsubscribed') {
                              html += '<button class="btn btn-xs btn-sm btn-outline-success" onclick="sendOptinSingle('+c.id+')">Send</button>';
                          }
                          html += '</td>';
                      }
                      html += '</tr>';
                  });
                  html += '</tbody></table></div>';
                  c.innerHTML = html;
              }

              window.filterOptin = function(btn, filter) {
                  document.querySelectorAll('#sectionOptin .mw-camp-tab').forEach(function(b){b.classList.remove('active');});
                  btn.classList.add('active');
                  optinCurrentFilter = filter;
                  optinCurrentPage   = 1;
                  loadOptinList();
              };

              window.sendOptinSingle = function(contactId) {
                  fetch('/crm/api/optin.php?action=send',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({contact_id:contactId,csrf_token:csrf})})
                      .then(r=>r.json()).then(function(d){
                          if (d.success){ loadOptinStats(); loadOptinList(); }
                          else alert('Error: '+(d.error||'Unknown'));
                      });
              };

              window.sendBulkOptin = function() {
                  var target = document.getElementById('optBulkTarget').value;
                  var resend = document.getElementById('optResendDays').value;
                  var msg = target==='never_sent' ? 'Send opt-in email to all contacts who have never been contacted?' : 'Resend to non-responders who haven\'t replied in '+resend+' days?';
                  if (!confirm(msg)) return;
                  fetch('/crm/api/optin.php?action=send-bulk',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({target:target,resend_after_days:parseInt(resend),csrf_token:csrf})})
                      .then(r=>r.json()).then(function(d){
                          if (d.success){ alert('Sent '+d.sent+' opt-in emails'+(d.errors>0?' ('+d.errors+' errors)':'')+'.');
                              loadOptinStats(); loadOptinList(); }
                          else alert('Error: '+(d.error||'Unknown'));
                      });
              };


              // ══════════════════════════════════════════════════════════════
              // SHARED UTILS
              // ══════════════════════════════════════════════════════════════

              function post(endpoint, action, data, cb) {
                  data.csrf_token = csrf;
                  fetch('/crm/api/'+endpoint+'.php?action='+action,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)})
                      .then(r=>r.json()).then(function(d){ if(d.success&&cb) cb(d); else if(!d.success) alert(d.error||'Error'); });
              }

              function renderPagination(containerId, page, totalPages, cb) {
                  var container = document.getElementById(containerId);
                  if (totalPages <= 1){ container.innerHTML=''; return; }
                  var html = '<nav><ul class="pagination pagination-sm">';
                  for (var i=1;i<=totalPages;i++){
                      html += '<li class="page-item '+(i===page?'active':'')+'">';
                      html += '<a class="page-link" href="#" data-p="'+i+'">'+i+'</a></li>';
                  }
                  html += '</ul></nav>';
                  container.innerHTML = html;
                  container.querySelectorAll('[data-p]').forEach(function(a){
                      a.addEventListener('click',function(e){ e.preventDefault(); cb(parseInt(this.dataset.p)); });
                  });
              }

              function showError(containerId, msg) {
                  document.getElementById(containerId).innerHTML = '<div class="alert alert-danger m-3">'+esc(msg)+'</div>';
              }

              function esc(str) {
                  if (!str && str!==0) return '';
                  var d = document.createElement('div');
                  d.appendChild(document.createTextNode(String(str)));
                  return d.innerHTML;
              }

          })();
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
