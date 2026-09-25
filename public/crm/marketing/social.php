<?php
/**
 * Social Marketing — Dashboard
 *
 * KPI tiles, upcoming scheduled posts, failed posts with retry,
 * recent activity, and quick-action buttons.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('marketing.view');

$pageTitle  = 'Social Marketing';
$activePage = 'social';
$canEdit    = userHasPermission('marketing.edit');
$canApprove = userHasPermission('marketing.approve');

// ── Flagged visits awaiting social draft (Phase 3) ──────────────────────
$flaggedVisitsPendingDraft = [];
$autoDraftCount = 0;
try {
    $db = getDB();
    // Visits flagged by crew but no social draft created yet
    // Only query if columns exist (after migration 609 runs)
    $colCheck = $db->query("SHOW COLUMNS FROM job_visits LIKE 'social_draft_id'")->fetch();
    if ($colCheck) {
        $flaggedStmt = $db->query("
            SELECT jv.id AS visit_id, jv.visit_number, jv.flagged_at,
                   jp.service_type,
                   p.address AS property_address
            FROM job_visits jv
            JOIN job_plans jp ON jp.id = jv.plan_id
            JOIN properties p ON p.id  = jp.property_id
            WHERE jv.is_flagged = 1
              AND jv.social_draft_id IS NULL
              AND jv.status = 'completed'
            ORDER BY jv.flagged_at DESC
            LIMIT 20
        ");
        $flaggedVisitsPendingDraft = $flaggedStmt ? $flaggedStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        // Count auto-generated drafts awaiting review
        $autoColCheck = $db->query("SHOW COLUMNS FROM social_posts LIKE 'auto_generated'")->fetch();
        if ($autoColCheck) {
            $autoDraftCount = (int)$db->query(
                "SELECT COUNT(*) FROM social_posts WHERE status = 'draft' AND auto_generated = 1"
            )->fetchColumn();
        }
    }
} catch (Throwable $e) {
    // Non-fatal — widget simply doesn't show
}
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <!-- Page Header -->
          <div class="d-flex justify-content-between align-items-center mb-4">
              <div>
                  <h1 class="h3 mb-0">Social Marketing</h1>
                  <p class="text-muted mb-0">
                      <a href="/crm/marketing/campaigns.php" class="text-muted">Email Campaigns</a>
                      <span class="mx-1">›</span>
                      <strong class="text-dark">Social Posts</strong>
                  </p>
              </div>
              <div class="d-flex gap-2">
                  <?php if ($canEdit): ?>
                  <a href="/crm/marketing/social-post-wizard.php" class="btn btn-success mw-soc-btn-new">
                      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                      New Post
                      <?php if ($autoDraftCount > 0): ?>
                      <span class="badge badge-pill ml-1" style="background:var(--mw-lime);color:#1a3a2a;font-size:11px"><?php echo $autoDraftCount; ?> AI</span>
                      <?php endif; ?>
                  </a>
                  <?php endif; ?>
                  <a href="/crm/marketing/social-calendar.php" class="btn btn-outline-secondary">
                      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                      Calendar
                  </a>
                  <a href="/crm/marketing/social-analytics.php" class="btn btn-outline-secondary">
                      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                      Analytics
                  </a>
                  <a href="/crm/marketing/social-setup-wizard.php" class="btn btn-outline-secondary">
                      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                      Connect Accounts
                  </a>
              </div>
          </div>

          <!-- KPI Stats — 6 tiles -->
          <div class="mw-soc-stats-row mb-4" id="socStats">
              <div class="mw-soc-stat-card">
                  <div class="mw-soc-stat-icon mw-soc-icon-green">
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                  </div>
                  <div>
                      <div class="mw-soc-stat-num" id="statPublished">—</div>
                      <div class="mw-soc-stat-lbl">Published This Month</div>
                      <div id="trendPublished"></div>
                  </div>
              </div>
              <div class="mw-soc-stat-card">
                  <div class="mw-soc-stat-icon mw-soc-icon-blue">
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  </div>
                  <div>
                      <div class="mw-soc-stat-num" id="statImpressions">—</div>
                      <div class="mw-soc-stat-lbl">Impressions</div>
                      <div id="trendImpressions"></div>
                  </div>
              </div>
              <div class="mw-soc-stat-card">
                  <div class="mw-soc-stat-icon mw-soc-icon-purple">
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                  </div>
                  <div>
                      <div class="mw-soc-stat-num" id="statReach">—</div>
                      <div class="mw-soc-stat-lbl">Total Reach</div>
                      <div id="trendReach"></div>
                  </div>
              </div>
              <div class="mw-soc-stat-card">
                  <div class="mw-soc-stat-icon mw-soc-icon-orange">
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                  </div>
                  <div>
                      <div class="mw-soc-stat-num" id="statPending">—</div>
                      <div class="mw-soc-stat-lbl">Pending Approval</div>
                  </div>
              </div>
              <div class="mw-soc-stat-card">
                  <div class="mw-soc-stat-icon mw-soc-icon-red">
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                  </div>
                  <div>
                      <div class="mw-soc-stat-num" id="statLikes">—</div>
                      <div class="mw-soc-stat-lbl">Likes</div>
                      <div id="trendLikes"></div>
                  </div>
              </div>
              <div class="mw-soc-stat-card">
                  <div class="mw-soc-stat-icon mw-soc-icon-blue">
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                  </div>
                  <div>
                      <div class="mw-soc-stat-num" id="statScheduled">—</div>
                      <div class="mw-soc-stat-lbl">Scheduled</div>
                  </div>
              </div>
          </div>

          <!-- Top Post Spotlight (populated by JS when data exists) -->
          <div class="mw-soc-top-post-strip" id="topPostStrip" style="display:none;">
              <div style="font-size:1.4rem;flex-shrink:0;line-height:1;">&#9733;</div>
              <div style="flex:1;min-width:0;">
                  <div class="mw-soc-top-post-label">Top Post This Month</div>
                  <div class="mw-soc-top-post-caption" id="topPostCaption"></div>
                  <div id="topPostPlatforms" class="mt-1" style="display:flex;gap:4px;"></div>
              </div>
              <div class="mw-soc-top-post-metrics" id="topPostMetrics"></div>
              <a class="btn btn-sm btn-outline-success ml-2" id="topPostLink" href="#" style="flex-shrink:0;">View</a>
          </div>

          <!-- Visual Format Decision Tree -->
          <div class="card mb-4 mw-dtree-card">
              <div class="card-header mw-dtree-header" id="dtreeToggle" role="button" tabindex="0"
                   aria-expanded="false" aria-controls="dtreeBody">
                  <span class="mw-dtree-header-text">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                      Not sure which format? Use our decision tree
                  </span>
                  <svg class="mw-dtree-caret" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
              </div>
              <div class="card-body mw-dtree-hidden" id="dtreeBody">
                  <p class="text-muted mw-dtree-intro">Answer a few quick questions and we'll point you to the Motion ad format that fits this post.</p>
                  <div class="mw-dtree-crumbs" id="dtreeCrumbs"></div>
                  <div id="dtreeStage"></div>
              </div>
          </div>

          <!-- Main Content Grid -->
          <div class="row">
              <!-- Left: Upcoming + Failed -->
              <div class="col-lg-8">

                  <!-- Upcoming Scheduled -->
                  <div class="card mb-4">
                      <div class="card-header" style="flex-wrap:wrap;gap:8px;">
                          <div class="d-flex justify-content-between align-items-center w-100">
                              <div class="d-flex align-items-center gap-2">
                                  <h5 class="mb-0 mr-3">
                                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                      Posts
                                  </h5>
                                  <div class="mw-soc-post-tabs" id="postTabs">
                                      <button class="mw-soc-post-tab active" data-tab="scheduled" onclick="switchPostTab('scheduled',this)">Scheduled</button>
                                      <button class="mw-soc-post-tab" data-tab="draft" onclick="switchPostTab('draft',this)">Drafts<?php if ($autoDraftCount > 0): ?> <span class="mw-soc-tab-badge"><?php echo $autoDraftCount; ?></span><?php endif; ?></button>
                                      <button class="mw-soc-post-tab" data-tab="published" onclick="switchPostTab('published',this)">Published</button>
                                  </div>
                              </div>
                              <div class="d-flex gap-2 align-items-center">
                                  <?php if (userHasPermission('marketing.approve')): ?>
                                  <button class="btn btn-sm btn-outline-primary" id="btnRunPublisher" onclick="runPublisher()">
                                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="mr-1"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                      Publish Now
                                  </button>
                                  <?php endif; ?>
                                  <a href="/crm/marketing/social-calendar.php" class="btn btn-sm btn-outline-secondary">Calendar</a>
                              </div>
                          </div>
                          <!-- 7-day Activity Pulse -->
                          <div class="mw-soc-pulse-strip w-100" id="pulseStrip"></div>
                      </div>
                      <div class="card-body p-0" id="upcomingList">
                          <div class="mw-soc-loading">Loading...</div>
                      </div>
                  </div>

                  <!-- Failed Posts -->
                  <div class="card mb-4" id="failedCard" style="display:none;">
                      <div class="card-header d-flex justify-content-between align-items-center">
                          <h5 class="mb-0 text-danger">
                              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                              Failed Posts
                          </h5>
                          <span class="badge badge-danger" id="failedBadge">0</span>
                      </div>
                      <div class="card-body p-0" id="failedList"></div>
                  </div>

                  <!-- Pending Approval -->
                  <?php if ($canApprove): ?>
                  <div class="card mb-4" id="approvalCard" style="display:none;">
                      <div class="card-header">
                          <h5 class="mb-0 text-warning">
                              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                              Needs Your Approval
                          </h5>
                      </div>
                      <div class="card-body p-0" id="approvalList"></div>
                  </div>
                  <?php endif; ?>

                  <!-- Flagged Visits Awaiting Social Draft (Phase 3) -->
                  <?php if (!empty($flaggedVisitsPendingDraft) && $canEdit): ?>
                  <div class="card mb-4">
                      <div class="card-header d-flex justify-content-between align-items-center"
                           style="cursor:pointer" data-toggle="collapse" data-target="#flaggedVisitsBody" aria-expanded="false">
                          <h5 class="mb-0" style="color:var(--mw-orange)">
                              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-2"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>
                              Flagged Visits — Awaiting Social Draft
                          </h5>
                          <span class="badge badge-warning"><?php echo count($flaggedVisitsPendingDraft); ?></span>
                      </div>
                      <div class="collapse" id="flaggedVisitsBody">
                          <div class="card-body p-0">
                              <div class="list-group list-group-flush">
                                  <?php foreach ($flaggedVisitsPendingDraft as $fv): ?>
                                  <div class="list-group-item d-flex justify-content-between align-items-center py-2">
                                      <div>
                                          <div class="font-weight-medium small"><?php echo htmlspecialchars($fv['property_address'] ?? ''); ?></div>
                                          <div class="text-muted x-small">
                                              <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $fv['service_type'] ?? ''))); ?>
                                              <?php if (!empty($fv['flagged_at'])): ?>
                                              &mdash; flagged <?php echo date('M j', strtotime($fv['flagged_at'])); ?>
                                              <?php endif; ?>
                                          </div>
                                      </div>
                                      <button class="btn btn-sm btn-outline-success"
                                              onclick="generateDraftForVisit(<?php echo (int)$fv['visit_id']; ?>, this)"
                                              style="white-space:nowrap">
                                          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="mr-1"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                          Generate Draft
                                      </button>
                                  </div>
                                  <?php endforeach; ?>
                              </div>
                          </div>
                      </div>
                  </div>
                  <?php endif; ?>

              </div>

              <!-- Right: Quick Actions + Platform Status -->
              <div class="col-lg-4">

                  <!-- Quick Actions -->
                  <div class="card mb-4">
                      <div class="card-header"><h5 class="mb-0">Quick Actions</h5></div>
                      <div class="card-body">
                          <div class="mw-soc-quick-actions">
                              <?php if ($canEdit): ?>
                              <a href="/crm/marketing/social-post-editor.php" class="mw-soc-quick-btn mw-soc-quick-new">
                                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                  <span>New Post</span>
                              </a>
                              <a href="/crm/marketing/social-library.php" class="mw-soc-quick-btn">
                                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                  <span>Templates</span>
                              </a>
                              <?php endif; ?>
                              <?php if ($canApprove): ?>
                              <a href="/crm/marketing/social-accounts.php" class="mw-soc-quick-btn">
                                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>
                                  <span>Connect Account</span>
                              </a>
                              <?php endif; ?>
                              <a href="/crm/marketing/social-calendar.php" class="mw-soc-quick-btn">
                                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                  <span>Calendar</span>
                              </a>
                          </div>
                      </div>
                  </div>

                  <!-- Connected Platforms -->
                  <div class="card mb-4">
                      <div class="card-header d-flex justify-content-between align-items-center">
                          <h5 class="mb-0">Connected Platforms</h5>
                          <?php if ($canApprove): ?>
                          <a href="/crm/marketing/social-accounts.php" class="btn btn-sm btn-outline-secondary">Manage</a>
                          <?php endif; ?>
                      </div>
                      <div class="card-body p-0" id="platformStatus">
                          <div class="mw-soc-loading">Loading...</div>
                      </div>
                  </div>

                  <!-- Mini Calendar -->
                  <div class="card mb-4">
                      <div class="card-header d-flex justify-content-between align-items-center">
                          <h5 class="mb-0" id="miniCalTitle">Loading…</h5>
                          <div style="display:flex;gap:4px;">
                              <button class="btn btn-sm btn-outline-secondary" onclick="miniCalNav(-1)" title="Previous month">&lsaquo;</button>
                              <button class="btn btn-sm btn-outline-secondary" onclick="miniCalNav(1)"  title="Next month">&rsaquo;</button>
                          </div>
                      </div>
                      <div class="card-body p-0 mw-soc-mini-cal" id="miniCalBody">
                          <div class="mw-soc-loading">Loading…</div>
                      </div>
                      <div class="card-footer text-center" style="background:none;border-top:1px solid #f1f3f5;padding:8px;">
                          <a href="/crm/marketing/social-calendar.php" class="small text-muted">Open full calendar →</a>
                      </div>
                  </div>

                  <!-- Schedule Density — next 12 months -->
                  <div class="card mb-4">
                      <div class="card-header d-flex justify-content-between align-items-center">
                          <h5 class="mb-0">
                              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-1"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                              Schedule Fill
                          </h5>
                          <?php if ($canApprove): ?>
                          <span class="text-muted" style="font-size:.72rem;">next 12 months</span>
                          <?php endif; ?>
                      </div>
                      <div class="card-body" style="padding:12px 16px 10px;">
                          <div id="densityRow" style="display:flex;gap:4px;align-items:flex-end;overflow-x:hidden;">
                              <div style="width:100%;font-size:.75rem;color:#adb5bd;">Loading…</div>
                          </div>
                          <p class="text-muted mb-0 mt-2" style="font-size:.7rem;">Bars show scheduled posts vs seasonal target. Create a Campaign from any post to auto-fill gaps.</p>
                      </div>
                  </div>

              </div>
          </div>

          <script>
          (function() {
              'use strict';

              var csrf       = '<?php echo generateCSRFToken(); ?>';
              var canApprove = <?php echo json_encode($canApprove); ?>;

              // Mini-calendar state
              var miniCalYear  = new Date().getFullYear();
              var miniCalMonth = new Date().getMonth() + 1;
              var monthNames   = ['January','February','March','April','May','June',
                                  'July','August','September','October','November','December'];

              function pad(n) { return n < 10 ? '0' + n : '' + n; }

              // ── Platform icon SVGs ─────────────────────────────────
              var platformIcons = {
                  gbp:       '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>',
                  facebook:  '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"/></svg>',
                  instagram: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1112.63 8 4 4 0 0116 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>',
                  linkedin:  '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 8a6 6 0 016 6v7h-4v-7a2 2 0 00-2-2 2 2 0 00-2 2v7h-4v-7a6 6 0 016-6zM2 9h4v12H2z"/><circle cx="4" cy="4" r="2"/></svg>',
              };

              var statusLabels = {
                  draft:            ['Draft',            'mw-soc-badge-draft'],
                  pending_approval: ['Awaiting Approval','mw-soc-badge-pending'],
                  approved:         ['Approved',         'mw-soc-badge-approved'],
                  scheduled:        ['Scheduled',        'mw-soc-badge-scheduled'],
                  publishing:       ['Publishing…',      'mw-soc-badge-publishing'],
                  published:        ['Published',        'mw-soc-badge-published'],
                  failed:           ['Failed',           'mw-soc-badge-failed'],
                  cancelled:        ['Cancelled',        'mw-soc-badge-cancelled'],
              };

              function statusBadge(status) {
                  var info = statusLabels[status] || [status, 'mw-soc-badge-draft'];
                  return '<span class="mw-soc-badge ' + info[1] + '">' + esc(info[0]) + '</span>';
              }

              // ── Load dashboard stats ───────────────────────────────
              function loadStats() {
                  fetch('/crm/api/social/metrics.php?action=dashboard')
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (!data.success) return;
                          var s = data.stats;

                          // 6 stat tiles
                          document.getElementById('statPublished').textContent   = s.published_month    || 0;
                          document.getElementById('statImpressions').textContent = fmt(s.total_impressions || 0);
                          document.getElementById('statReach').textContent       = fmt(s.total_reach      || 0);
                          document.getElementById('statPending').textContent     = s.pending_approval   || 0;
                          document.getElementById('statLikes').textContent       = fmt(s.total_likes     || 0);
                          document.getElementById('statScheduled').textContent   = s.upcoming_scheduled || 0;

                          // Trend arrows
                          renderTrend('trendPublished',   s.published_month,    s.prev_published);
                          renderTrend('trendImpressions', s.total_impressions,  s.prev_impressions);
                          renderTrend('trendReach',       s.total_reach,        s.prev_reach);
                          renderTrend('trendLikes',       s.total_likes,        s.prev_likes);

                          // Activity pulse
                          if (data.activity_pulse && data.activity_pulse.length) {
                              renderPulse(data.activity_pulse);
                          }

                          // Show failed / approval cards
                          if (s.failed_posts > 0) {
                              document.getElementById('failedCard').style.display = '';
                              document.getElementById('failedBadge').textContent = s.failed_posts;
                              loadFailed();
                          }
                          if (s.pending_approval > 0 && canApprove) {
                              document.getElementById('approvalCard').style.display = '';
                              loadPendingApproval();
                          }
                      });
              }

              // ── Trend arrow renderer ───────────────────────────────
              function renderTrend(elId, current, prev) {
                  var el = document.getElementById(elId);
                  if (!el) return;
                  current = current || 0;
                  prev    = prev    || 0;
                  if (prev === 0 && current === 0) { el.innerHTML = ''; return; }
                  var diff  = current - prev;
                  var pct   = prev > 0 ? Math.round(Math.abs(diff) / prev * 100) : 0;
                  var cls   = diff > 0 ? 'mw-soc-trend-up' : (diff < 0 ? 'mw-soc-trend-down' : 'mw-soc-trend-flat');
                  var arrow = diff > 0 ? '&#9650;' : (diff < 0 ? '&#9660;' : '&#8213;');
                  var lbl   = diff !== 0 ? (pct + '% vs last mo') : 'same as last mo';
                  el.innerHTML = '<span class="mw-soc-trend ' + cls + '">' + arrow + ' ' + lbl + '</span>';
              }

              // ── Activity Pulse renderer (current week Sun→Sat) ────
              function renderPulse(days) {
                  var el = document.getElementById('pulseStrip');
                  if (!el) return;
                  var letters  = ['S','M','T','W','T','F','S'];
                  var todayStr = (function() {
                      var t = new Date();
                      return t.getFullYear() + '-'
                           + (t.getMonth() < 9 ? '0' : '') + (t.getMonth() + 1) + '-'
                           + (t.getDate()  < 10 ? '0' : '') + t.getDate();
                  })();
                  var html = '';
                  days.forEach(function(d) {
                      var dt      = new Date(d.date + 'T12:00:00');
                      var ltr     = letters[dt.getDay()];
                      var isToday = (d.date === todayStr);
                      var pub     = parseInt(d.published, 10);
                      var sch     = parseInt(d.scheduled, 10);
                      var cls     = pub > 0 ? 'mw-soc-pulse-dot-published'
                                  : sch > 0 ? 'mw-soc-pulse-dot-scheduled'
                                  : 'mw-soc-pulse-dot-empty';
                      var title   = d.date + (pub > 0 ? ' — ' + pub + ' published'
                                  : sch > 0 ? ' — ' + sch + ' scheduled' : '');
                      var todayCls   = isToday ? ' mw-soc-pulse-today' : '';
                      var todayStyle = isToday ? 'font-weight:700;color:#212529;' : '';
                      html += '<div class="mw-soc-pulse-day' + todayCls + '">'
                           +  '<span class="mw-soc-pulse-lbl" style="' + todayStyle + '">' + esc(ltr) + '</span>'
                           +  '<span class="mw-soc-pulse-dot ' + cls + '" title="' + esc(title) + '"></span>'
                           + '</div>';
                  });
                  el.innerHTML = html;
              }

              // ── Top Post Spotlight ─────────────────────────────────
              function loadTopPost() {
                  fetch('/crm/api/social/metrics.php?action=top-posts&limit=1')
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (!data.success || !data.posts || !data.posts.length) return;
                          var p   = data.posts[0];
                          var imp = parseInt(p.total_impressions, 10) || 0;
                          var lk  = parseInt(p.total_likes,       10) || 0;
                          var cm  = parseInt(p.total_comments,    10) || 0;
                          var er  = imp > 0 ? ((lk + cm) / imp * 100).toFixed(1) + '%' : '—';
                          document.getElementById('topPostCaption').textContent = truncate(p.caption || '', 100);
                          var pills = (p.platforms || []).map(function(pl) {
                              return '<span class="mw-soc-platform-pill mw-soc-pl-' + esc(pl) + '" title="' + esc(pl) + '">'
                                   + (platformIcons[pl] || pl) + '</span>';
                          }).join('');
                          document.getElementById('topPostPlatforms').innerHTML = pills;
                          document.getElementById('topPostMetrics').innerHTML =
                              '<span class="mw-soc-top-post-metric">&#128065; ' + fmt(imp) + '</span>' +
                              '<span class="mw-soc-top-post-metric">&#10084; ' + fmt(lk)  + '</span>' +
                              '<span class="mw-soc-top-post-metric">&#128200; ' + er + '</span>';
                          document.getElementById('topPostLink').href = '/crm/marketing/social-post-editor.php?id=' + p.id;
                          document.getElementById('topPostStrip').style.display = '';
                      });
              }

              // ── Mini Calendar ──────────────────────────────────────
              function loadMiniCalendar(year, month) {
                  var el = document.getElementById('miniCalBody');
                  var ti = document.getElementById('miniCalTitle');
                  if (!el) return;
                  el.innerHTML = '<div class="mw-soc-loading">Loading…</div>';
                  if (ti) { ti.textContent = monthNames[month - 1] + ' ' + year; }

                  fetch('/crm/api/social/posts.php?action=calendar&year=' + year + '&month=' + month)
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (!data.success) { el.innerHTML = '<div class="p-3 text-muted small">Could not load.</div>'; return; }
                          var cal      = data.calendar || {};
                          var today    = new Date();
                          var todayStr = today.getFullYear() + '-' + pad(today.getMonth() + 1) + '-' + pad(today.getDate());
                          var firstDow = new Date(year, month - 1, 1).getDay();
                          var daysInM  = new Date(year, month, 0).getDate();

                          var dow = '<div class="mw-soc-cal-dow">';
                          ['S','M','T','W','T','F','S'].forEach(function(d) { dow += '<div>' + d + '</div>'; });
                          dow += '</div>';

                          var grid = '<div class="mw-soc-cal-grid">';
                          for (var e = 0; e < firstDow; e++) {
                              grid += '<div class="mw-soc-cal-cell mw-soc-cal-empty"></div>';
                          }
                          for (var day = 1; day <= daysInM; day++) {
                              var ds    = year + '-' + pad(month) + '-' + pad(day);
                              var posts = cal[ds] || [];
                              var cls   = 'mw-soc-cal-cell' + (ds === todayStr ? ' mw-soc-cal-today' : '');
                              var dots  = '';
                              if (posts.length) {
                                  dots = '<div class="mw-soc-cal-dots">';
                                  posts.slice(0, 4).forEach(function(p) {
                                      var dc = p.status === 'published' ? 'mw-soc-dot-published'
                                             : (p.status === 'scheduled' || p.status === 'approved') ? 'mw-soc-dot-scheduled'
                                             : 'mw-soc-dot-draft';
                                      dots += '<span class="mw-soc-cal-dot ' + dc + '" title="' + esc(p.status) + '"></span>';
                                  });
                                  if (posts.length > 4) {
                                      dots += '<span class="mw-soc-cal-dot" style="background:#ced4da;" title="' + (posts.length - 4) + ' more"></span>';
                                  }
                                  dots += '</div>';
                              }
                              grid += '<div class="' + cls + '" onclick="window.location.href=\'/crm/marketing/social-calendar.php?date=' + ds + '\'">'
                                    + '<div class="mw-soc-cal-day-num">' + day + '</div>'
                                    + dots + '</div>';
                          }
                          grid += '</div>';
                          el.innerHTML = dow + grid;
                      });
              }

              window.miniCalNav = function(dir) {
                  miniCalMonth += dir;
                  if (miniCalMonth > 12) { miniCalMonth = 1; miniCalYear++; }
                  if (miniCalMonth < 1)  { miniCalMonth = 12; miniCalYear--; }
                  loadMiniCalendar(miniCalYear, miniCalMonth);
              };

              // ── Post tab switcher ──────────────────────────────────
              var currentPostTab = 'scheduled';
              window.switchPostTab = function(tab, el) {
                  currentPostTab = tab;
                  document.querySelectorAll('.mw-soc-post-tab').forEach(function(b) { b.classList.remove('active'); });
                  el.classList.add('active');
                  loadUpcoming();
              };

              // ── Load upcoming / draft / published posts ────────────
              function loadUpcoming() {
                  var url = currentPostTab === 'scheduled'
                      ? '/crm/api/social/posts.php?action=upcoming&limit=20'
                      : '/crm/api/social/posts.php?action=list&status=' + currentPostTab + '&page=1';
                  fetch(url)
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          var el = document.getElementById('upcomingList');
                          var posts = data.posts || [];
                          var emptyMsg = currentPostTab === 'draft' ? 'No drafts.'
                                       : currentPostTab === 'published' ? 'No published posts yet.'
                                       : 'No upcoming posts scheduled.';
                          if (!data.success || !posts.length) {
                              el.innerHTML = '<div class="mw-soc-empty-state"><p>' + emptyMsg + '</p>'
                                  + (<?php echo json_encode($canEdit); ?> ? '<a href="/crm/marketing/social-post-editor.php" class="btn btn-sm btn-success mt-2">Create a Post</a>' : '') + '</div>';
                              return;
                          }
                          var html = '';
                          posts.forEach(function(p) {
                              var platforms = (p.platforms || []).map(function(pl) {
                                  return '<span class="mw-soc-platform-pill mw-soc-pl-' + esc(pl) + '" title="' + esc(pl) + '">' + (platformIcons[pl] || pl) + '</span>';
                              }).join('');

                              var timeStr = p.scheduled_at
                                  ? formatDt(p.scheduled_at)
                                  : '<em class="text-muted">Not scheduled</em>';

                              html += '<div class="mw-soc-upcoming-row">';
                              if (p.thumb_url) {
                                  html += '<div class="mw-soc-upcoming-thumb"><img src="' + esc(p.thumb_url) + '" alt=""></div>';
                              } else {
                                  html += '<div class="mw-soc-upcoming-thumb mw-soc-thumb-empty"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div>';
                              }
                              html += '<div class="mw-soc-upcoming-body">';
                              html += '  <div class="mw-soc-upcoming-meta">' + statusBadge(p.status) + platforms + '</div>';
                              html += '  <div class="mw-soc-upcoming-caption">' + esc(truncate(p.caption, 80)) + '</div>';
                              if (p.fail_reason) {
                                  html += '  <div class="text-danger small mt-1" style="font-size:0.8rem"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-1"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' + esc(p.fail_reason) + '</div>';
                              }
                              html += '  <div class="mw-soc-upcoming-time">' + timeStr + '</div>';
                              html += '</div>';
                              html += '<div class="mw-soc-upcoming-actions">';
                              var isRetryable = (p.status === 'publishing' || (p.status === 'published' && p.fail_reason));
                              if (isRetryable && <?php echo json_encode($canApprove); ?>) {
                                  html += '  <button class="btn btn-sm btn-outline-danger mr-1" onclick="retryPost(' + p.id + ')">Retry</button>';
                              }
                              html += '  <a href="/crm/marketing/social-post-editor.php?id=' + p.id + '" class="btn btn-sm btn-outline-secondary">Edit</a>';
                              html += '</div>';
                              html += '</div>';
                          });
                          el.innerHTML = html;
                      });
              }

              // ── Load failed posts ──────────────────────────────────
              function loadFailed() {
                  fetch('/crm/api/social/posts.php?action=failed')
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (!data.success || !data.posts.length) return;
                          var html = '';
                          data.posts.forEach(function(p) {
                              html += '<div class="mw-soc-failed-row">';
                              html += '  <div class="mw-soc-failed-info">';
                              html += '    <strong>' + esc(p.title || truncate(p.caption, 50)) + '</strong>';
                              html += '    <div class="text-danger small mt-1">' + esc(p.last_fail_reason || 'Unknown error') + '</div>';
                              html += '    <div class="text-muted small">Failed ' + p.fail_count + ' time(s) &bull; ' + formatDt(p.updated_at) + '</div>';
                              html += '  </div>';
                              if (<?php echo json_encode($canApprove); ?>) {
                                  html += '  <button class="btn btn-sm btn-outline-danger" onclick="retryPost(' + p.id + ')">Retry</button>';
                              }
                              html += '</div>';
                          });
                          document.getElementById('failedList').innerHTML = html;
                      });
              }

              // ── Load pending approval ──────────────────────────────
              function loadPendingApproval() {
                  fetch('/crm/api/social/posts.php?action=list&status=pending_approval')
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (!data.success || !data.posts.length) return;
                          var html = '';
                          data.posts.forEach(function(p) {
                              html += '<div class="mw-soc-upcoming-row">';
                              html += '  <div class="mw-soc-upcoming-body">';
                              html += '    <div class="mw-soc-upcoming-meta">' + statusBadge('pending_approval') + '</div>';
                              html += '    <div class="mw-soc-upcoming-caption">' + esc(truncate(p.caption, 80)) + '</div>';
                              html += '    <div class="text-muted small">by ' + esc(p.created_by_name || '?') + '</div>';
                              html += '  </div>';
                              html += '  <div class="d-flex gap-1">';
                              html += '    <a href="/crm/marketing/social-post-editor.php?id=' + p.id + '" class="btn btn-sm btn-success">Review</a>';
                              html += '  </div>';
                              html += '</div>';
                          });
                          document.getElementById('approvalList').innerHTML = html;
                      });
              }

              // ── Load platform status ───────────────────────────────
              function loadPlatforms() {
                  fetch('/crm/api/social/accounts.php?action=list')
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          var el = document.getElementById('platformStatus');
                          if (!data.success || !data.accounts.length) {
                              el.innerHTML = '<div class="mw-soc-empty-state p-3">'
                                  + '<p class="mb-2 text-muted small">No platforms connected yet.</p>'
                                  + (<?php echo json_encode($canApprove); ?> ? '<a href="/crm/marketing/social-accounts.php" class="btn btn-sm btn-success">Connect Now</a>' : '')
                                  + '</div>';
                              return;
                          }

                          var platformNames = { gbp: 'Google Business', facebook: 'Facebook', instagram: 'Instagram', linkedin: 'LinkedIn' };
                          var html = '';
                          data.accounts.forEach(function(a) {
                              var healthClass = {good: 'text-success', expiring: 'text-warning', expired: 'text-danger', unknown: 'text-muted'}[a.token_health] || 'text-muted';
                              var healthLabel = {good: '● Active', expiring: '● Expiring soon', expired: '✗ Token expired', unknown: '○ Unknown'}[a.token_health] || '';
                              html += '<div class="mw-soc-platform-row">';
                              html += '  <div class="mw-soc-platform-icon mw-soc-pl-' + esc(a.platform) + '">' + (platformIcons[a.platform] || '') + '</div>';
                              html += '  <div class="flex-grow-1">';
                              html += '    <div class="mw-soc-platform-name">' + esc(a.location_name_display || a.account_name) + '</div>';
                              html += '    <div class="small ' + healthClass + '">' + healthLabel + '</div>';
                              html += '  </div>';
                              html += '  <span class="badge badge-' + (a.is_active ? 'success' : 'secondary') + '">' + (a.is_active ? 'On' : 'Off') + '</span>';
                              html += '</div>';
                          });
                          el.innerHTML = html;
                      });
              }

              // ── Retry a failed post ────────────────────────────────
              window.retryPost = function(id) {
                  if (!confirm('Re-queue this post for publishing?')) return;
                  fetch('/crm/api/social/posts.php?action=retry', {
                      method: 'POST',
                      headers: {'Content-Type': 'application/json'},
                      body: JSON.stringify({id: id, csrf_token: csrf})
                  }).then(function(r) { return r.json(); }).then(function(data) {
                      if (data.success) {
                          loadStats();
                          loadFailed();
                      } else {
                          alert('Error: ' + (data.error || 'Unknown'));
                      }
                  });
              };

              window.runPublisher = function() {
                  var btn = document.getElementById('btnRunPublisher');
                  var icon = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="mr-1"><polygon points="5 3 19 12 5 21 5 3"/></svg> ';
                  if (btn) { btn.disabled = true; btn.innerHTML = icon + 'Publishing…'; }
                  fetch('/crm/cron/social_publisher.php', {
                      method: 'POST',
                      headers: {'Content-Type': 'application/json'},
                      body: JSON.stringify({csrf_token: csrf})
                  }).then(function(r) { return r.json(); }).then(function(data) {
                      if (btn) { btn.disabled = false; btn.innerHTML = icon + 'Publish Now'; }
                      loadStats();
                      loadUpcoming();
                      loadFailed();
                      // Build a detailed result message
                      var msg = data.message || (data.error ? 'Error: ' + data.error : 'Done');
                      if (data.results && data.results.length) {
                          msg += '\n\nDetails:';
                          data.results.forEach(function(r) {
                              msg += '\n' + (r.success ? '✓' : '✗') + ' [' + r.platform + '] ' + r.message;
                          });
                      }
                      alert(msg);
                  }).catch(function(e) {
                      if (btn) { btn.disabled = false; btn.innerHTML = icon + 'Publish Now'; }
                      alert('Publisher error: ' + e.message);
                  });
              };

              // ── Helpers ────────────────────────────────────────────
              function esc(str) {
                  if (!str) return '';
                  var d = document.createElement('div');
                  d.appendChild(document.createTextNode(str));
                  return d.innerHTML;
              }
              function truncate(str, n) {
                  if (!str) return '';
                  return str.length > n ? str.substring(0, n) + '…' : str;
              }
              function fmt(n) {
                  return (n || 0).toLocaleString();
              }
              function formatDt(dt) {
                  if (!dt) return '';
                  var d = new Date(dt.replace(' ', 'T'));
                  return d.toLocaleDateString('en-CA', {month: 'short', day: 'numeric'})
                      + ' at ' + d.toLocaleTimeString('en-CA', {hour: 'numeric', minute: '2-digit', hour12: true});
              }

              // ── Generate draft from flagged visit ─────────────────
              window.generateDraftForVisit = function(visitId, btn) {
                  if (btn) { btn.disabled = true; btn.textContent = 'Generating…'; }
                  var csrf = document.querySelector('meta[name="csrf-token"]');
                  var csrfVal = csrf ? csrf.content : '';

                  var fd = new FormData();
                  fd.append('visit_id', visitId);
                  fd.append('csrf_token', csrfVal);
                  fd.append('is_flagged', '1'); // force-flag if not already

                  // POST to visit-flag.php which triggers SocialDraftPipeline
                  fetch('/crm/api/visit-flag.php', { method: 'POST', body: fd })
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (data.social_draft_id) {
                              window.location.href = '/crm/marketing/social-post-editor.php?id=' + data.social_draft_id;
                          } else if (data.success) {
                              alert('Draft generation triggered — check Social Posts for the new draft.');
                              window.location.reload();
                          } else {
                              alert('Error: ' + (data.error || 'Unknown error'));
                              if (btn) { btn.disabled = false; btn.innerHTML = 'Generate Draft'; }
                          }
                      })
                      .catch(function(e) {
                          alert('Request failed: ' + e.message);
                          if (btn) { btn.disabled = false; btn.innerHTML = 'Generate Draft'; }
                      });
              };

              // ── Schedule Density bar ───────────────────────────────
              var shortMonths = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
              function loadDensity() {
                  var el = document.getElementById('densityRow');
                  if (!el) return;
                  fetch('/crm/api/social/schedules.php?action=density')
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (!data.success) return;
                          var html = '';
                          data.density.forEach(function(d) {
                              var pct    = d.target > 0 ? Math.min(100, Math.round(d.count / d.target * 100)) : 0;
                              var mo     = shortMonths[parseInt(d.month.split('-')[1], 10) - 1];
                              var barH   = Math.max(3, Math.round(pct * 0.36)); // 0–36px range
                              var bgCol  = d.count > 0 ? 'var(--mw-green)' : '#e9ecef';
                              var ctTxt  = d.count > 0 ? String(d.count) : '';
                              var tip    = d.month + ': ' + d.count + '/' + d.target;
                              // Each column: flex column, center-aligned, fixed width ~8.33%
                              html += '<div style="flex:1;min-width:0;display:flex;flex-direction:column;align-items:center;gap:2px;" title="' + esc(tip) + '">'
                                   +  '<span style="font-size:.6rem;color:#6c757d;font-weight:700;line-height:1;">' + esc(ctTxt) + '</span>'
                                   +  '<div style="width:100%;height:36px;display:flex;align-items:flex-end;">'
                                   +  '<div style="width:100%;height:' + barH + 'px;background:' + bgCol + ';border-radius:2px 2px 0 0;min-height:3px;"></div>'
                                   +  '</div>'
                                   +  '<span style="font-size:.58rem;color:#adb5bd;font-weight:600;text-transform:uppercase;white-space:nowrap;overflow:hidden;">' + esc(mo.charAt(0)) + '</span>'
                                   +  '</div>';
                          });
                          el.innerHTML = html;
                      });
              }

              // ── Init ──────────────────────────────────────────────
              loadStats();
              loadUpcoming();
              loadPlatforms();
              loadTopPost();
              loadMiniCalendar(miniCalYear, miniCalMonth);
              loadDensity();
          })();
          </script>

          <!-- Visual Format Decision Tree logic -->
          <script>
          (function () {
              'use strict';

              var RESULTS = {
                  testimonial: {
                      name: 'Testimonial',
                      desc: 'Real customers share their experience and the results they got.',
                      bestFor: 'Building trust fast with authentic social proof.',
                      budget: 'Low-Mid'
                  },
                  tutorial: {
                      name: 'Tutorial / How-To',
                      desc: 'A step-by-step walkthrough focused on the result the viewer can achieve.',
                      bestFor: 'Educating prospects while showcasing your expertise and the outcome.',
                      budget: 'Mid'
                  },
                  demo: {
                      name: 'Demo',
                      desc: 'The product shown in action, solving a real problem.',
                      bestFor: 'Visual products where seeing it work is what closes the sale.',
                      budget: 'Mid'
                  },
                  splitscreen: {
                      name: 'Split Screen',
                      desc: 'Side-by-side comparison — before/after or you vs. the alternative.',
                      bestFor: 'Dramatizing a transformation or a clear competitive edge.',
                      budget: 'Low-Mid'
                  },
                  listicle: {
                      name: 'Listicle',
                      desc: 'A numbered rundown of 3–5 key benefits or features.',
                      bestFor: 'Communicating multiple value points quickly and scannably.',
                      budget: 'Low'
                  },
                  unboxing: {
                      name: 'Unboxing',
                      desc: 'A first-look reveal of the packaging and product experience.',
                      bestFor: 'Products with a premium or memorable unboxing moment.',
                      budget: 'Low-Mid'
                  },
                  behindthescenes: {
                      name: 'Behind the Scenes',
                      desc: 'An authentic look at your process, team, or founder story.',
                      bestFor: 'Humanizing the brand and building a genuine connection.',
                      budget: 'Low'
                  },
                  montage: {
                      name: 'Montage',
                      desc: 'Fast-paced, energetic cuts set to music or a strong beat.',
                      bestFor: 'Brand awareness and emotional resonance when no single format fits.',
                      budget: 'Low-Mid'
                  }
              };

              // node: { q: question, yes: nextId|result:key, no: nextId|result:key }
              var TREE = {
                  q1: { q: 'Do you have real customers with a compelling story?',                       yes: 'r:testimonial',     no: 'q2'  },
                  q2: { q: 'Is your product visual — does seeing it in action sell it?',                yes: 'q2b',               no: 'q3'  },
                  q2b:{ q: 'Is the focus on the outcome / result rather than the product itself?',      yes: 'r:tutorial',        no: 'r:demo' },
                  q3: { q: 'Do you want to compare before/after, or us vs. them?',                      yes: 'r:splitscreen',     no: 'q4'  },
                  q4: { q: 'Do you have 3–5 distinct benefits or features to highlight?',               yes: 'r:listicle',        no: 'q5'  },
                  q5: { q: 'Is your packaging / unboxing experience impressive?',                       yes: 'r:unboxing',        no: 'q6'  },
                  q6: { q: 'Can you show behind the scenes — your process, team, or founder story?',    yes: 'r:behindthescenes', no: 'r:montage' }
              };

              var START = 'q1';
              var stage  = document.getElementById('dtreeStage');
              var crumbs = document.getElementById('dtreeCrumbs');
              var body   = document.getElementById('dtreeBody');
              var toggle = document.getElementById('dtreeToggle');
              if (!stage || !toggle) { return; }

              var history = []; // [{ node, answer }]

              function budgetClass(b) {
                  if (b === 'Low')     { return 'mw-dtree-budget-low'; }
                  if (b === 'Low-Mid') { return 'mw-dtree-budget-lowmid'; }
                  return 'mw-dtree-budget-mid';
              }

              function renderCrumbs() {
                  if (!history.length) { crumbs.innerHTML = ''; return; }
                  var html = '';
                  history.forEach(function (h, i) {
                      html += '<span class="mw-dtree-crumb">' + (i + 1) + '. ' +
                              (h.answer ? 'Yes' : 'No') + '</span>';
                  });
                  crumbs.innerHTML = html;
              }

              function showQuestion(nodeId) {
                  var node = TREE[nodeId];
                  var html = '' +
                      '<div class="mw-dtree-q">' + node.q + '</div>' +
                      '<div class="mw-dtree-btns">' +
                          '<button type="button" class="mw-dtree-btn" data-ans="yes">Yes</button>' +
                          '<button type="button" class="mw-dtree-btn mw-dtree-btn-no" data-ans="no">No</button>' +
                      '</div>' +
                      '<div class="mw-dtree-nav">' +
                          (history.length ? '<button type="button" class="mw-dtree-link" id="dtreeBack">← Back</button>' : '') +
                          '<button type="button" class="mw-dtree-link" id="dtreeReset">Start over</button>' +
                      '</div>';
                  stage.innerHTML = html;
                  stage.setAttribute('data-node', nodeId);

                  stage.querySelectorAll('.mw-dtree-btn').forEach(function (btn) {
                      btn.addEventListener('click', function () {
                          var ans = this.getAttribute('data-ans') === 'yes';
                          history.push({ node: nodeId, answer: ans });
                          go(ans ? node.yes : node.no);
                      });
                  });
                  var backBtn = document.getElementById('dtreeBack');
                  if (backBtn) { backBtn.addEventListener('click', stepBack); }
                  document.getElementById('dtreeReset').addEventListener('click', reset);
                  renderCrumbs();
              }

              function showResult(key) {
                  var r = RESULTS[key];
                  stage.setAttribute('data-node', 'r:' + key);
                  stage.innerHTML = '' +
                      '<div class="mw-dtree-result">' +
                          '<div class="mw-dtree-result-tag">Recommended format</div>' +
                          '<div class="mw-dtree-result-name">' + r.name + '</div>' +
                          '<p class="mw-dtree-result-desc">' + r.desc + '</p>' +
                          '<div class="mw-dtree-result-row">' +
                              '<span class="mw-dtree-result-lbl">Best for</span>' +
                              '<span class="mw-dtree-result-val">' + r.bestFor + '</span>' +
                          '</div>' +
                          '<div class="mw-dtree-result-row">' +
                              '<span class="mw-dtree-result-lbl">Budget</span>' +
                              '<span class="mw-dtree-budget ' + budgetClass(r.budget) + '">' + r.budget + '</span>' +
                          '</div>' +
                          '<div class="mw-dtree-nav">' +
                              '<button type="button" class="mw-dtree-link" id="dtreeBack">← Back</button>' +
                              '<button type="button" class="mw-dtree-link" id="dtreeReset">Start over</button>' +
                          '</div>' +
                      '</div>';
                  document.getElementById('dtreeBack').addEventListener('click', stepBack);
                  document.getElementById('dtreeReset').addEventListener('click', reset);
                  renderCrumbs();
              }

              function go(target) {
                  if (target.indexOf('r:') === 0) {
                      showResult(target.slice(2));
                  } else {
                      showQuestion(target);
                  }
              }

              function stepBack() {
                  if (!history.length) { return; }
                  var last = history.pop();
                  showQuestion(last.node);
              }

              function reset() {
                  history = [];
                  showQuestion(START);
              }

              // Collapsible toggle
              function setExpanded(open) {
                  toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                  if (open) {
                      body.classList.remove('mw-dtree-hidden');
                      toggle.classList.add('mw-dtree-open');
                      if (!stage.hasChildNodes()) { reset(); }
                  } else {
                      body.classList.add('mw-dtree-hidden');
                      toggle.classList.remove('mw-dtree-open');
                  }
              }
              toggle.addEventListener('click', function () {
                  setExpanded(toggle.getAttribute('aria-expanded') !== 'true');
              });
              toggle.addEventListener('keydown', function (e) {
                  if (e.key === 'Enter' || e.key === ' ') {
                      e.preventDefault();
                      setExpanded(toggle.getAttribute('aria-expanded') !== 'true');
                  }
              });
          })();
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
