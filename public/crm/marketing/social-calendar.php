<?php
/**
 * Social Marketing — Calendar View
 *
 * Month grid with post status dots per day.
 * Click a day to see that day's posts in a detail panel.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('marketing.view');

$pageTitle  = 'Social Calendar';
$activePage = 'social';
$canEdit    = userHasPermission('marketing.edit');
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <!-- Header -->
          <div class="d-flex justify-content-between align-items-center mb-4">
              <div>
                  <h1 class="h3 mb-0">Social Calendar</h1>
                  <p class="text-muted mb-0">
                      <a href="/crm/marketing/social.php" class="text-muted">Social</a>
                      <span class="mx-1">›</span> Calendar
                  </p>
              </div>
              <div class="d-flex gap-2">
                  <?php if ($canEdit): ?>
                  <a href="/crm/marketing/social-post-editor.php" class="btn btn-success">
                      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                      New Post
                  </a>
                  <?php endif; ?>
              </div>
          </div>

          <div class="row">
              <!-- Calendar grid -->
              <div class="col-lg-8">
                  <div class="card">
                      <div class="card-header">
                          <!-- Month navigation -->
                          <div class="mw-soc-cal-nav">
                              <button class="btn btn-sm btn-outline-secondary" id="calPrev">
                                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                              </button>
                              <h5 class="mb-0 mw-soc-cal-title" id="calTitle">Loading...</h5>
                              <button class="btn btn-sm btn-outline-secondary" id="calNext">
                                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                              </button>
                              <button class="btn btn-sm btn-outline-primary ml-2" id="calToday">Today</button>
                          </div>
                      </div>
                      <div class="card-body p-0">
                          <!-- Day-of-week headers -->
                          <div class="mw-soc-cal-dow">
                              <div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div>
                              <div>Thu</div><div>Fri</div><div>Sat</div>
                          </div>
                          <!-- Day cells -->
                          <div class="mw-soc-cal-grid" id="calGrid">
                              <div class="mw-soc-loading p-4">Loading calendar...</div>
                          </div>
                      </div>
                      <!-- Legend -->
                      <div class="card-footer">
                          <div class="mw-soc-cal-legend">
                              <span><span class="mw-soc-dot mw-soc-dot-draft"></span> Draft</span>
                              <span><span class="mw-soc-dot mw-soc-dot-scheduled"></span> Scheduled</span>
                              <span><span class="mw-soc-dot mw-soc-dot-pending"></span> Needs Approval</span>
                              <span><span class="mw-soc-dot mw-soc-dot-published"></span> Published</span>
                              <span><span class="mw-soc-dot mw-soc-dot-failed"></span> Failed</span>
                          </div>
                      </div>
                  </div>
              </div>

              <!-- Day detail panel -->
              <div class="col-lg-4">
                  <div class="card" id="dayPanel">
                      <div class="card-header">
                          <h5 class="mb-0" id="dayPanelTitle">Select a day</h5>
                      </div>
                      <div class="card-body p-0" id="dayPanelContent">
                          <div class="mw-soc-empty-state">
                              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                              <p>Click a day to see posts</p>
                          </div>
                      </div>
                  </div>
              </div>
          </div>

          <script>
          (function() {
              'use strict';

              var today    = new Date();
              var viewYear = today.getFullYear();
              var viewMonth = today.getMonth() + 1; // 1-based
              var calData  = {};

              var monthNames = ['January','February','March','April','May','June',
                                'July','August','September','October','November','December'];

              var statusColors = {
                  draft:            'draft',
                  pending_approval: 'pending',
                  approved:         'scheduled',
                  scheduled:        'scheduled',
                  publishing:       'published',
                  published:        'published',
                  failed:           'failed',
              };

              var statusLabels = {
                  draft:            'Draft',
                  pending_approval: 'Needs Approval',
                  approved:         'Approved',
                  scheduled:        'Scheduled',
                  publishing:       'Publishing…',
                  published:        'Published',
                  failed:           'Failed',
                  cancelled:        'Cancelled',
              };

              var platformIcons = {
                  gbp:       '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>',
                  facebook:  '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"/></svg>',
                  instagram: '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5"/><path d="M16 11.37A4 4 0 1112.63 8 4 4 0 0116 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>',
              };

              // ── Load calendar data ─────────────────────────────────
              function loadCalendar() {
                  document.getElementById('calTitle').textContent = monthNames[viewMonth - 1] + ' ' + viewYear;
                  document.getElementById('calGrid').innerHTML = '<div class="mw-soc-loading p-4">Loading…</div>';

                  fetch('/crm/api/social/posts.php?action=calendar&year=' + viewYear + '&month=' + viewMonth)
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (!data.success) { return; }
                          calData = data.calendar || {};
                          renderGrid(viewYear, viewMonth);
                      });
              }

              // ── Render grid ────────────────────────────────────────
              function renderGrid(year, month) {
                  var grid   = document.getElementById('calGrid');
                  var today  = new Date();
                  var todayStr = formatDate(today);

                  // First day of month and days in month
                  var firstDay = new Date(year, month - 1, 1).getDay(); // 0=Sun
                  var daysInMonth = new Date(year, month, 0).getDate();

                  var html = '';
                  var dayCount = 0;

                  // Empty cells before month starts
                  for (var i = 0; i < firstDay; i++) {
                      html += '<div class="mw-soc-cal-cell mw-soc-cal-empty"></div>';
                      dayCount++;
                  }

                  // Day cells
                  for (var d = 1; d <= daysInMonth; d++) {
                      var dateStr = year + '-' + pad(month) + '-' + pad(d);
                      var posts   = calData[dateStr] || [];
                      var isToday = (dateStr === todayStr);

                      html += '<div class="mw-soc-cal-cell' + (isToday ? ' mw-soc-cal-today' : '') + '" data-date="' + dateStr + '" onclick="selectDay(\'' + dateStr + '\')">';
                      html += '  <div class="mw-soc-cal-day-num">' + d + '</div>';

                      if (posts.length) {
                          // Show up to 3 post titles as chips, rest as dots
                          var shown = 0;
                          posts.forEach(function(p) {
                              if (shown < 2) {
                                  var color = statusColors[p.status] || 'draft';
                                  html += '<div class="mw-soc-cal-chip mw-soc-chip-' + color + '" title="' + esc(p.title || truncate(p.caption, 40)) + '">'
                                        + esc(truncate(p.title || p.caption, 22)) + '</div>';
                                  shown++;
                              }
                          });
                          if (posts.length > 2) {
                              html += '<div class="mw-soc-cal-chip mw-soc-chip-more">+' + (posts.length - 2) + ' more</div>';
                          }
                      }

                      html += '</div>';
                      dayCount++;
                  }

                  // Fill trailing cells to complete the last row
                  while (dayCount % 7 !== 0) {
                      html += '<div class="mw-soc-cal-cell mw-soc-cal-empty"></div>';
                      dayCount++;
                  }

                  grid.innerHTML = html;
              }

              // ── Select a day ───────────────────────────────────────
              window.selectDay = function(dateStr) {
                  // Deselect all
                  document.querySelectorAll('.mw-soc-cal-cell').forEach(function(el) {
                      el.classList.remove('mw-soc-cal-selected');
                  });
                  var cell = document.querySelector('[data-date="' + dateStr + '"]');
                  if (cell) cell.classList.add('mw-soc-cal-selected');

                  var posts  = calData[dateStr] || [];
                  var d      = new Date(dateStr + 'T12:00:00');
                  var label  = d.toLocaleDateString('en-CA', {weekday:'long', month:'long', day:'numeric', year:'numeric'});
                  document.getElementById('dayPanelTitle').textContent = label;

                  var panel = document.getElementById('dayPanelContent');
                  if (!posts.length) {
                      panel.innerHTML = '<div class="mw-soc-empty-state">'
                          + '<p>No posts on this day.</p>'
                          + (<?php echo json_encode($canEdit); ?> ? '<a href="/crm/marketing/social-post-editor.php?scheduled=' + encodeURIComponent(dateStr + 'T09:00') + '" class="btn btn-sm btn-success mt-2">Schedule a Post</a>' : '')
                          + '</div>';
                      return;
                  }

                  var html = '';
                  posts.forEach(function(p) {
                      var platforms = (p.platforms || []).map(function(pl) {
                          return '<span class="mw-soc-platform-pill mw-soc-pl-' + esc(pl) + '">' + (platformIcons[pl] || pl) + '</span>';
                      }).join('');
                      var statusLabel = statusLabels[p.status] || p.status;
                      var color = statusColors[p.status] || 'draft';

                      html += '<div class="mw-soc-day-post">';
                      html += '  <div class="mw-soc-day-post-header">';
                      html += '    <span class="mw-soc-dot mw-soc-dot-' + color + '"></span>';
                      html += '    <span class="font-weight-medium">' + esc(truncate(p.title || p.caption, 40)) + '</span>';
                      html += '  </div>';
                      html += '  <div class="mw-soc-day-post-meta">' + esc(statusLabel) + ' &bull; ' + platforms + '</div>';
                      html += '  <div class="mw-soc-day-post-actions">';
                      html += '    <a href="/crm/marketing/social-post-editor.php?id=' + p.id + '" class="btn btn-xs btn-outline-secondary">Edit</a>';
                      html += '  </div>';
                      html += '</div>';
                  });
                  panel.innerHTML = html;
              };

              // ── Navigation ─────────────────────────────────────────
              document.getElementById('calPrev').addEventListener('click', function() {
                  viewMonth--;
                  if (viewMonth < 1) { viewMonth = 12; viewYear--; }
                  loadCalendar();
              });
              document.getElementById('calNext').addEventListener('click', function() {
                  viewMonth++;
                  if (viewMonth > 12) { viewMonth = 1; viewYear++; }
                  loadCalendar();
              });
              document.getElementById('calToday').addEventListener('click', function() {
                  var t = new Date();
                  viewYear  = t.getFullYear();
                  viewMonth = t.getMonth() + 1;
                  loadCalendar();
              });

              // ── Helpers ────────────────────────────────────────────
              function pad(n) { return n < 10 ? '0' + n : '' + n; }
              function formatDate(d) {
                  return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
              }
              function truncate(s, n) { return s && s.length > n ? s.substring(0, n) + '…' : (s || ''); }
              function esc(str) {
                  if (!str) return '';
                  var d = document.createElement('div');
                  d.appendChild(document.createTextNode(str));
                  return d.innerHTML;
              }

              // ── Init ──────────────────────────────────────────────
              loadCalendar();
          })();
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
