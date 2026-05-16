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
                  </a>
                  <?php endif; ?>
                  <a href="/crm/marketing/social-calendar.php" class="btn btn-outline-secondary">
                      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                      Calendar
                  </a>
                  <a href="/crm/marketing/social-setup-wizard.php" class="btn btn-outline-secondary">
                      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                      Connect Accounts
                  </a>
              </div>
          </div>

          <!-- KPI Stats -->
          <div class="mw-soc-stats-row mb-4" id="socStats">
              <div class="mw-soc-stat-card">
                  <div class="mw-soc-stat-icon mw-soc-icon-green">
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                  </div>
                  <div>
                      <div class="mw-soc-stat-num" id="statPublished">—</div>
                      <div class="mw-soc-stat-lbl">Published This Month</div>
                  </div>
              </div>
              <div class="mw-soc-stat-card">
                  <div class="mw-soc-stat-icon mw-soc-icon-blue">
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                  </div>
                  <div>
                      <div class="mw-soc-stat-num" id="statEngagement">—</div>
                      <div class="mw-soc-stat-lbl">Avg Engagement / Post</div>
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
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                  </div>
                  <div>
                      <div class="mw-soc-stat-num" id="statFailed">—</div>
                      <div class="mw-soc-stat-lbl">Failed Posts</div>
                  </div>
              </div>
          </div>

          <!-- Main Content Grid -->
          <div class="row">
              <!-- Left: Upcoming + Failed -->
              <div class="col-lg-8">

                  <!-- Upcoming Scheduled -->
                  <div class="card mb-4">
                      <div class="card-header d-flex justify-content-between align-items-center">
                          <h5 class="mb-0">
                              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                              Upcoming Posts
                          </h5>
                          <div class="d-flex gap-2 align-items-center">
                              <?php if (userHasPermission('marketing.approve')): ?>
                              <button class="btn btn-sm btn-outline-primary" id="btnRunPublisher" onclick="runPublisher()">
                                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="mr-1"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                  Publish Now
                              </button>
                              <?php endif; ?>
                              <a href="/crm/marketing/social-calendar.php" class="btn btn-sm btn-outline-secondary">Full Calendar</a>
                          </div>
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

                  <!-- This Month Summary -->
                  <div class="card mb-4">
                      <div class="card-header"><h5 class="mb-0">This Month</h5></div>
                      <div class="card-body">
                          <div class="mw-soc-month-stat">
                              <span class="mw-soc-month-num" id="monthImpressions">—</span>
                              <span class="mw-soc-month-lbl">Total Impressions</span>
                          </div>
                          <div class="mw-soc-month-stat">
                              <span class="mw-soc-month-num" id="monthClicks">—</span>
                              <span class="mw-soc-month-lbl">Link Clicks</span>
                          </div>
                          <div class="mw-soc-month-stat">
                              <span class="mw-soc-month-num" id="monthLikes">—</span>
                              <span class="mw-soc-month-lbl">Likes & Reactions</span>
                          </div>
                          <div class="mw-soc-month-stat">
                              <span class="mw-soc-month-num" id="monthScheduled">—</span>
                              <span class="mw-soc-month-lbl">Posts Scheduled</span>
                          </div>
                      </div>
                  </div>

              </div>
          </div>

          <script>
          (function() {
              'use strict';

              var csrf = '<?php echo generateCSRFToken(); ?>';
              var canApprove = <?php echo json_encode($canApprove); ?>;

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
                          document.getElementById('statPublished').textContent   = s.published_month   || 0;
                          document.getElementById('statEngagement').textContent  = s.avg_engagement    || 0;
                          document.getElementById('statPending').textContent     = s.pending_approval  || 0;
                          document.getElementById('statFailed').textContent      = s.failed_posts      || 0;
                          document.getElementById('monthImpressions').textContent = fmt(s.total_impressions || 0);
                          document.getElementById('monthClicks').textContent     = fmt(s.total_clicks   || 0);
                          document.getElementById('monthLikes').textContent      = fmt(s.total_likes    || 0);
                          document.getElementById('monthScheduled').textContent  = s.upcoming_scheduled || 0;

                          // Highlight failed card
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

              // ── Load upcoming posts ────────────────────────────────
              function loadUpcoming() {
                  fetch('/crm/api/social/posts.php?action=upcoming&limit=8')
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          var el = document.getElementById('upcomingList');
                          if (!data.success || !data.posts.length) {
                              el.innerHTML = '<div class="mw-soc-empty-state"><p>No upcoming posts scheduled.</p>'
                                  + (<?php echo json_encode($canEdit); ?> ? '<a href="/crm/marketing/social-post-editor.php" class="btn btn-sm btn-success mt-2">Create a Post</a>' : '') + '</div>';
                              return;
                          }
                          var html = '';
                          data.posts.forEach(function(p) {
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
                              html += '  <div class="mw-soc-upcoming-time">' + timeStr + '</div>';
                              html += '</div>';
                              html += '<div class="mw-soc-upcoming-actions">';
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
                  if (btn) { btn.disabled = true; btn.textContent = 'Publishing…'; }
                  fetch('/crm/cron/social_publisher.php', {
                      method: 'POST',
                      headers: {'Content-Type': 'application/json'},
                      body: JSON.stringify({csrf_token: csrf})
                  }).then(function(r) { return r.json(); }).then(function(data) {
                      if (btn) { btn.disabled = false; btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="mr-1"><polygon points="5 3 19 12 5 21 5 3"/></svg> Publish Now'; }
                      loadStats();
                      loadUpcoming();
                      loadFailed();
                      if (data.message) alert(data.message);
                  }).catch(function(e) {
                      if (btn) { btn.disabled = false; btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="mr-1"><polygon points="5 3 19 12 5 21 5 3"/></svg> Publish Now'; }
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

              // ── Init ──────────────────────────────────────────────
              loadStats();
              loadUpcoming();
              loadPlatforms();
          })();
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
