<?php
/**
 * Social Analytics — first-party (organic) post analytics.
 *
 * Posting cadence, content mix, and UTM → quote-request attribution,
 * built only from data the CRM already owns (no Meta API dependency).
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('marketing.view');

$pageTitle  = 'Social Analytics';
$activePage = 'social-analytics';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <!-- Page Header -->
          <div class="d-flex justify-content-between align-items-center mb-4">
              <div>
                  <h1 class="h3 mb-0">Social Analytics</h1>
                  <p class="text-muted mb-0">
                      <a href="/crm/marketing/social.php" class="text-muted">Social Posts</a>
                      <span class="mx-1">›</span>
                      <strong class="text-dark">Analytics</strong>
                  </p>
              </div>
              <div class="d-flex gap-2">
                  <a href="/crm/marketing/social.php" class="btn btn-outline-secondary">
                      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                      Back to Social
                  </a>
              </div>
          </div>

          <!-- Scope note -->
          <div class="mw-sa-note">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
              <span>
                  This is <strong>first-party analytics</strong> — posting activity and which posts drove quote
                  requests through tracked links. Platform engagement (impressions, likes, reach) needs a
                  connected Meta account and will appear here once that's wired up.
              </span>
          </div>

          <!-- KPI Summary -->
          <div class="mw-sa-kpis" id="saKpis">
              <div class="mw-sa-loading">Loading analytics…</div>
          </div>

          <div class="row">
              <div class="col-lg-7">
                  <div class="card mb-4">
                      <div class="card-header"><h5 class="mb-0">Publishing Cadence</h5></div>
                      <div class="card-body" id="saCadence">
                          <div class="mw-sa-loading">Loading…</div>
                      </div>
                  </div>
                  <div class="card mb-4">
                      <div class="card-header d-flex justify-content-between align-items-center">
                          <h5 class="mb-0">Quote Requests Driven by Posts</h5>
                          <span class="mw-sa-coverage" id="saCoverage"></span>
                      </div>
                      <div class="card-body p-0" id="saAttribution">
                          <div class="mw-sa-loading">Loading…</div>
                      </div>
                  </div>
              </div>
              <div class="col-lg-5">
                  <div class="card mb-4">
                      <div class="card-header"><h5 class="mb-0">Post Status</h5></div>
                      <div class="card-body" id="saStatus">
                          <div class="mw-sa-loading">Loading…</div>
                      </div>
                  </div>
                  <div class="card mb-4">
                      <div class="card-header"><h5 class="mb-0">By Platform</h5></div>
                      <div class="card-body p-0" id="saPlatform">
                          <div class="mw-sa-loading">Loading…</div>
                      </div>
                  </div>
                  <div class="card mb-4">
                      <div class="card-header"><h5 class="mb-0">Content Mix</h5></div>
                      <div class="card-body" id="saContent">
                          <div class="mw-sa-loading">Loading…</div>
                      </div>
                  </div>
              </div>
          </div>

          <script>
          (function () {
              'use strict';
              function esc(s) {
                  return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                      return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
                  });
              }
              function num(n) { return (n == null ? 0 : n).toLocaleString(); }

              function renderKpis(s) {
                  var el = document.getElementById('saKpis');
                  var cards = [
                      ['Published (all time)', num(s.published_total)],
                      ['Published this month', num(s.published_month)],
                      ['Scheduled ahead',      num(s.scheduled_upcoming)],
                      ['Quote requests (last ' + s.attribution_window + 'd)', num(s.attributed_quotes)]
                  ];
                  el.innerHTML = cards.map(function (c) {
                      return '<div class="mw-sa-kpi"><div class="mw-sa-kpi-num">' + esc(c[1]) +
                             '</div><div class="mw-sa-kpi-lbl">' + esc(c[0]) + '</div></div>';
                  }).join('');
              }

              function renderCadence(a) {
                  var el = document.getElementById('saCadence');
                  if (!a.monthly || !a.monthly.length) { el.innerHTML = emptyMsg('No published posts yet.'); return; }
                  var max = Math.max.apply(null, a.monthly.map(function (m) { return m.count; }).concat([1]));
                  var bars = a.monthly.map(function (m) {
                      var pct = Math.round(m.count / max * 100);
                      return '<div class="mw-sa-bar-row">' +
                                 '<div class="mw-sa-bar-lbl">' + esc(m.label) + '</div>' +
                                 '<div class="mw-sa-bar-track"><div class="mw-sa-bar-fill" style="width:' + pct + '%"></div></div>' +
                                 '<div class="mw-sa-bar-val">' + num(m.count) + '</div>' +
                             '</div>';
                  }).join('');
                  var rate = (a.success_rate == null) ? '—' : (a.success_rate + '%');
                  el.innerHTML = bars +
                      '<div class="mw-sa-foot">Publish success rate: <strong>' + esc(rate) + '</strong></div>';
              }

              function renderStatus(a) {
                  var el = document.getElementById('saStatus');
                  if (!a.by_status || !a.by_status.length) { el.innerHTML = emptyMsg('No posts yet.'); return; }
                  el.innerHTML = a.by_status.map(function (r) {
                      return '<div class="mw-sa-chip-row">' +
                                 '<span class="mw-sa-chip mw-sa-chip-' + esc(r.status) + '">' + esc(r.status.replace(/_/g,' ')) + '</span>' +
                                 '<span class="mw-sa-chip-val">' + num(r.count) + '</span>' +
                             '</div>';
                  }).join('');
              }

              function renderPlatform(a) {
                  var el = document.getElementById('saPlatform');
                  if (!a.by_platform || !a.by_platform.length) { el.innerHTML = emptyMsg('No platform data yet.'); return; }
                  var rows = a.by_platform.map(function (p) {
                      return '<tr><td>' + esc(p.platform) + '</td><td>' + num(p.published) +
                             '</td><td class="mw-sa-muted">' + num(p.failed) + '</td></tr>';
                  }).join('');
                  el.innerHTML = '<table class="mw-sa-table"><thead><tr><th>Platform</th><th>Published</th><th>Failed</th></tr></thead><tbody>' + rows + '</tbody></table>';
              }

              function renderContent(c) {
                  var el = document.getElementById('saContent');
                  function block(title, rows) {
                      if (!rows || !rows.length) { return ''; }
                      var items = rows.map(function (r) {
                          return '<div class="mw-sa-mini-row"><span>' + esc(r.label) +
                                 '</span><span class="mw-sa-chip-val">' + num(r.count) + '</span></div>';
                      }).join('');
                      return '<div class="mw-sa-mini-block"><div class="mw-sa-mini-title">' + esc(title) + '</div>' + items + '</div>';
                  }
                  var html = block('By service', c.by_service) +
                             block('By area', c.by_city) +
                             (c.by_template && c.by_template.length ? block('By template', c.by_template) : '');
                  el.innerHTML = html || emptyMsg('No published posts yet.');
              }

              function renderAttribution(at) {
                  var el = document.getElementById('saAttribution');
                  var cov = document.getElementById('saCoverage');
                  if (!at.available) {
                      el.innerHTML = emptyMsg('Attribution tables are not available on this database yet.');
                      return;
                  }
                  if (at.coverage) {
                      cov.textContent = at.coverage.with_utm + ' / ' + at.coverage.published_total + ' posts have tracking links';
                  }
                  if (!at.posts || !at.posts.length) {
                      el.innerHTML = emptyMsg('No quote requests have been attributed to a post yet. Posts need a tracked CTA link (UTM campaign) for attribution to work.');
                      return;
                  }
                  var rows = at.posts.map(function (p) {
                      return '<tr>' +
                          '<td><div class="mw-sa-cap">' + esc(p.caption) + '</div>' +
                              '<div class="mw-sa-muted mw-sa-sm">' + esc(p.utm_campaign || '—') + '</div></td>' +
                          '<td class="mw-sa-center"><strong>' + num(p.quote_request) + '</strong></td>' +
                          '<td class="mw-sa-center">' + num(p.quote_accepted) + '</td>' +
                          '<td class="mw-sa-center">' + num(p.job_created) + '</td>' +
                      '</tr>';
                  }).join('');
                  el.innerHTML =
                      '<table class="mw-sa-table"><thead><tr>' +
                          '<th>Post</th><th class="mw-sa-center">Quote reqs</th>' +
                          '<th class="mw-sa-center">Accepted</th><th class="mw-sa-center">Jobs</th>' +
                      '</tr></thead><tbody>' + rows +
                      '<tr class="mw-sa-total"><td>Total</td>' +
                          '<td class="mw-sa-center">' + num(at.totals.quote_request) + '</td>' +
                          '<td class="mw-sa-center">' + num(at.totals.quote_accepted) + '</td>' +
                          '<td class="mw-sa-center">' + num(at.totals.job_created) + '</td></tr>' +
                      '</tbody></table>';
              }

              function emptyMsg(t) { return '<div class="mw-sa-empty">' + esc(t) + '</div>'; }

              fetch('/crm/api/social/analytics.php?action=overview', { credentials: 'same-origin' })
                  .then(function (r) { return r.json(); })
                  .then(function (j) {
                      if (!j.success || !j.data) { throw new Error(j.error || 'Failed to load'); }
                      var d = j.data;
                      renderKpis(d.summary);
                      renderCadence(d.activity);
                      renderStatus(d.activity);
                      renderPlatform(d.activity);
                      renderContent(d.content);
                      renderAttribution(d.attribution);
                  })
                  .catch(function (e) {
                      document.getElementById('saKpis').innerHTML =
                          '<div class="mw-sa-empty">Could not load analytics: ' + esc(e.message) + '</div>';
                  });
          })();
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
