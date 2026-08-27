<?php
/**
 * Field Recommendations — Admin Review Queue
 *
 * Displays pending field observations from crew, allowing admin to:
 * - Approve & send recommendation emails
 * - Edit email before sending
 * - Dismiss with reason
 *
 * @package Mowology CRM
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

$pageTitle = 'Field Recommendations';
$activePage = 'products';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                  <a href="index.php" class="text-muted" style="text-decoration:none; font-size:0.85rem;">
                      &larr; Back to Products Hub
                  </a>
                  <h1 class="h3 mb-0 mt-1">Field Recommendations</h1>
                  <p class="text-muted mb-0">Crew field observations that may trigger product recommendation emails.</p>
              </div>
          </div>

          <!-- Stats Bar -->
          <div class="mw-obs-stats" id="obsStats">
              <div class="mw-obs-stat-card pending">
                  <span class="mw-obs-stat-number" id="statPending">-</span>
                  <span class="mw-obs-stat-label">Pending</span>
              </div>
              <div class="mw-obs-stat-card sent">
                  <span class="mw-obs-stat-number" id="statSent">-</span>
                  <span class="mw-obs-stat-label">Sent This Month</span>
              </div>
              <div class="mw-obs-stat-card">
                  <span class="mw-obs-stat-number" id="statConverted">-</span>
                  <span class="mw-obs-stat-label">Converted</span>
              </div>
              <div class="mw-obs-stat-card">
                  <span class="mw-obs-stat-number" id="statTotal">-</span>
                  <span class="mw-obs-stat-label">Total All Time</span>
              </div>
          </div>

          <!-- Filter Tabs -->
          <div class="mw-obs-tabs" id="obsTabs">
              <button class="mw-obs-tab active" data-status="">
                  All
              </button>
              <button class="mw-obs-tab" data-status="pending">
                  Pending <span class="badge bg-warning text-dark" id="tabPendingCount"></span>
              </button>
              <button class="mw-obs-tab" data-status="email_sent">
                  Sent
              </button>
              <button class="mw-obs-tab" data-status="converted">
                  Converted
              </button>
              <button class="mw-obs-tab" data-status="dismissed">
                  Dismissed
              </button>
          </div>

          <!-- Observations List -->
          <div id="obsListContainer">
              <div class="mw-obs-empty">
                  <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                      <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                      <polyline points="14 2 14 8 20 8"/>
                  </svg>
                  <p>Loading observations...</p>
              </div>
          </div>

          <!-- Pagination -->
          <div id="obsPagination" class="d-flex justify-content-center mt-3" style="display:none !important;"></div>

          <script>
          (function() {
              'use strict';

              var currentStatus = '';
              var currentPage = 1;
              var csrf = '<?php echo generateCSRFToken(); ?>';
              var isAdmin = <?php echo json_encode(($user['role'] ?? '') === 'admin'); ?>;

              // ── Init ──────────────────────────────────────────
              loadObservations();

              // Tab click handlers
              document.querySelectorAll('.mw-obs-tab').forEach(function(tab) {
                  tab.addEventListener('click', function() {
                      document.querySelectorAll('.mw-obs-tab').forEach(function(t) {
                          t.classList.remove('active');
                      });
                      this.classList.add('active');
                      currentStatus = this.dataset.status;
                      currentPage = 1;
                      loadObservations();
                  });
              });

              // ── Load observations from API ───────────────────
              function loadObservations() {
                  var url = '/crm/api/field-observations.php?action=list&page=' + currentPage;
                  if (currentStatus) url += '&status=' + encodeURIComponent(currentStatus);

                  fetch(url)
                      .then(function(r) { return r.json(); })
                      .then(function(data) {
                          if (!data.success) {
                              showError(data.error || 'Failed to load observations');
                              return;
                          }
                          renderStats(data.counts_by_status || {});
                          renderList(data.observations || []);
                          renderPagination(data.page, data.pages, data.total);
                      })
                      .catch(function(err) {
                          showError('Network error loading observations');
                      });
              }

              // ── Render stats bar ─────────────────────────────
              function renderStats(counts) {
                  var pending = counts.pending || 0;
                  var sent = counts.email_sent || 0;
                  var converted = counts.converted || 0;
                  var total = 0;
                  for (var k in counts) total += counts[k];

                  document.getElementById('statPending').textContent = pending;
                  document.getElementById('statSent').textContent = sent;
                  document.getElementById('statConverted').textContent = converted;
                  document.getElementById('statTotal').textContent = total;

                  var pendingBadge = document.getElementById('tabPendingCount');
                  if (pendingBadge) {
                      pendingBadge.textContent = pending > 0 ? pending : '';
                  }
              }

              // ── Render observation cards ─────────────────────
              function renderList(observations) {
                  var container = document.getElementById('obsListContainer');

                  if (!observations.length) {
                      container.innerHTML =
                          '<div class="mw-obs-empty">' +
                          '  <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">' +
                          '    <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>' +
                          '    <polyline points="14 2 14 8 20 8"/>' +
                          '  </svg>' +
                          '  <p>No observations found' + (currentStatus ? ' with status "' + escHtml(currentStatus) + '"' : '') + '.</p>' +
                          '  <p class="text-muted small">Observations are logged by crew during visits via the mobile schedule.</p>' +
                          '</div>';
                      return;
                  }

                  var html = '';
                  observations.forEach(function(obs) {
                      var typeLabel = formatType(obs.observation_type);
                      var contactName = ((obs.contact_first || '') + ' ' + (obs.contact_last || '')).trim() || 'Unknown';
                      var address = ((obs.property_address || '') + (obs.property_city ? ', ' + obs.property_city : '')).trim();
                      var date = obs.created_at ? new Date(obs.created_at).toLocaleDateString('en-CA', {
                          year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
                      }) : '';

                      html += '<div class="mw-obs-card" data-obs-id="' + obs.id + '">';
                      html += '  <div class="mw-obs-card-header">';
                      html += '    <div>';
                      html += '      <span class="mw-obs-card-type">' + escHtml(typeLabel) + '</span>';
                      if (obs.observation_value) {
                          html += ' <span class="text-muted">— ' + escHtml(obs.observation_value) + '</span>';
                      }
                      html += '      <span class="mw-obs-badge mw-obs-badge-' + escHtml(obs.status) + '" style="margin-left:8px;">' + escHtml(obs.status) + '</span>';
                      html += '    </div>';
                      html += '    <span class="mw-obs-card-date">' + escHtml(date) + '</span>';
                      html += '  </div>';

                      html += '  <div class="mw-obs-card-meta">';
                      html += '    <span><strong>Client:</strong> ' + escHtml(contactName) + '</span>';
                      if (address) html += '    <span><strong>Property:</strong> ' + escHtml(address) + '</span>';
                      if (obs.crew_name) html += '    <span><strong>Crew:</strong> ' + escHtml(obs.crew_name) + '</span>';
                      html += '  </div>';

                      if (obs.notes) {
                          html += '  <div class="mw-obs-card-notes">' + escHtml(obs.notes) + '</div>';
                      }

                      if (obs.product_name) {
                          html += '  <div class="mw-obs-card-product">';
                          html += '    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>';
                          html += '    Recommend: ' + escHtml(obs.product_name);
                          html += '  </div>';
                      }

                      // Everything the crew photographed — the office needs all
                      // the angles to price the work, not just the cover shot.
                      if (obs.photos && obs.photos.length) {
                          html += '  <div class="mw-obs-card-photos">';
                          obs.photos.forEach(function(photo) {
                              var src = photo.thumb_url || photo.file_path;
                              if (!src) return;
                              html += '<a href="' + escHtml(photo.file_path) + '" target="_blank" rel="noopener">';
                              html += '<img src="' + escHtml(src) + '" alt="Site photo" class="mw-obs-photo">';
                              html += '</a>';
                          });
                          html += '  </div>';
                      }

                      if (obs.quote_number) {
                          html += '  <div class="mw-obs-card-quote">Quote ' + escHtml(obs.quote_number);
                          if (obs.quote_status) html += ' &middot; ' + escHtml(obs.quote_status);
                          html += '</div>';
                      }

                      // Actions (admin only, for pending observations)
                      if (isAdmin && obs.status === 'pending') {
                          html += '  <div class="mw-obs-card-actions">';
                          if (obs.recommended_product_id) {
                              var suggested = obs.recommended_price || obs.product_price || '';
                              html += '    <label class="mw-obs-price-label">$';
                              html += '      <input type="number" step="0.01" min="0" class="mw-obs-price" ';
                              html += '             id="price-' + obs.id + '" value="' + escHtml(String(suggested)) + '">';
                              html += '    </label>';
                              html += '    <button class="btn btn-sm btn-success" onclick="approveQuote(' + obs.id + ')">Approve &amp; Send Quote</button>';
                          }
                          html += '    <button class="btn btn-sm btn-outline-primary" onclick="approveObs(' + obs.id + ')">Send Info Email</button>';
                          html += '    <button class="btn btn-sm btn-outline-secondary" onclick="dismissObs(' + obs.id + ')">Dismiss</button>';
                          html += '  </div>';
                      }

                      if (obs.status === 'email_sent' && obs.email_sent_at) {
                          html += '  <div class="text-muted small mt-2">';
                          html += '    Email sent: ' + escHtml(new Date(obs.email_sent_at).toLocaleDateString('en-CA'));
                          if (obs.contact_email) html += ' to ' + escHtml(obs.contact_email);
                          html += '  </div>';
                      }

                      if (obs.status === 'dismissed' && obs.dismissed_reason) {
                          html += '  <div class="text-muted small mt-2">Reason: ' + escHtml(obs.dismissed_reason) + '</div>';
                      }

                      html += '</div>';
                  });

                  container.innerHTML = html;
              }

              // ── Pagination ───────────────────────────────────
              function renderPagination(page, totalPages, total) {
                  var container = document.getElementById('obsPagination');
                  if (totalPages <= 1) {
                      container.style.display = 'none';
                      return;
                  }
                  container.style.display = 'flex';
                  var html = '<nav><ul class="pagination pagination-sm">';
                  for (var i = 1; i <= totalPages; i++) {
                      html += '<li class="page-item ' + (i === page ? 'active' : '') + '">';
                      html += '<a class="page-link" href="#" onclick="goToPage(' + i + '); return false;">' + i + '</a>';
                      html += '</li>';
                  }
                  html += '</ul></nav>';
                  container.innerHTML = html;
              }

              // ── Actions ──────────────────────────────────────
              window.approveObs = function(obsId) {
                  if (!confirm('Send recommendation email for this observation?')) return;

                  fetch('/crm/api/field-observations.php?action=approve', {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/json' },
                      body: JSON.stringify({ id: obsId, csrf_token: csrf })
                  })
                  .then(function(r) { return r.json(); })
                  .then(function(data) {
                      if (data.success) {
                          alert(data.message || 'Email sent!');
                          loadObservations();
                      } else {
                          alert('Error: ' + (data.error || 'Unknown'));
                      }
                  })
                  .catch(function() { alert('Network error'); });
              };

              // Builds a real Quote from the recommendation and emails it to
              // whoever authorises spend on that property, then refreshes.
              window.approveQuote = function(obsId) {
                  var priceEl = document.getElementById('price-' + obsId);
                  var price = priceEl ? priceEl.value : '';

                  if (!confirm('Create a quote' + (price ? ' for $' + price : '') + ' and email it to the client?')) return;

                  fetch('/crm/api/field-observations.php?action=approve-quote', {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/json' },
                      body: JSON.stringify({ id: obsId, price: price, csrf_token: csrf })
                  })
                  .then(function(r) { return r.json(); })
                  .then(function(data) {
                      if (data.message) alert(data.message);
                      if (!data.success && data.error) showError(data.error);
                      loadObservations();
                  })
                  .catch(function() { showError('Could not create the quote.'); });
              };

              window.dismissObs = function(obsId) {
                  var reason = prompt('Reason for dismissing (optional):');
                  if (reason === null) return; // cancelled

                  fetch('/crm/api/field-observations.php?action=dismiss', {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/json' },
                      body: JSON.stringify({ id: obsId, reason: reason, csrf_token: csrf })
                  })
                  .then(function(r) { return r.json(); })
                  .then(function(data) {
                      if (data.success) {
                          loadObservations();
                      } else {
                          alert('Error: ' + (data.error || 'Unknown'));
                      }
                  })
                  .catch(function() { alert('Network error'); });
              };

              window.goToPage = function(page) {
                  currentPage = page;
                  loadObservations();
              };

              // ── Helpers ──────────────────────────────────────
              function formatType(type) {
                  if (!type) return 'Unknown';
                  return type.replace(/_/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); });
              }

              function escHtml(str) {
                  if (!str) return '';
                  var div = document.createElement('div');
                  div.appendChild(document.createTextNode(str));
                  return div.innerHTML;
              }

              function showError(msg) {
                  document.getElementById('obsListContainer').innerHTML =
                      '<div class="alert alert-danger">' + escHtml(msg) + '</div>';
              }
          })();
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
