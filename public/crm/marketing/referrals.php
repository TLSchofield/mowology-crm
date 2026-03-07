<?php
/**
 * Referral Program — Marketing / Refer a Friend
 *
 * Lets Mowology staff manage the client referral program:
 *   - Enable/disable and configure the program
 *   - See program-wide stats
 *   - Browse clients with referral links and send invite emails
 *   - Track all referrals by status (pending → converted → rewarded)
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('marketing.view');

$canEdit = userHasPermission('marketing.edit');
$isAdmin = isAdmin();

$pageTitle  = 'Referral Program';
$activePage = 'marketing';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <!-- ── Page Header ──────────────────────────────────────────────── -->
          <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
              <h1 class="h3 mb-0">Refer a Friend</h1>
              <p class="text-muted mb-0">Reward clients who send new business your way</p>
            </div>
            <div class="d-flex gap-2 align-items-center">
              <a href="/crm/marketing/campaigns.php" class="btn btn-outline-secondary btn-sm">
                <i data-feather="mail" style="width:14px;height:14px;"></i> Campaigns
              </a>
              <?php if ($isAdmin): ?>
              <button class="btn btn-outline-secondary btn-sm" id="btnSettings" onclick="openSettings()">
                <i data-feather="settings" style="width:14px;height:14px;"></i> Program Settings
              </button>
              <?php endif; ?>
            </div>
          </div>

          <!-- ── Program Disabled Banner ─────────────────────────────────── -->
          <div id="disabledBanner" class="alert alert-warning d-none mb-4" role="alert">
            <i data-feather="alert-triangle" style="width:16px;height:16px;vertical-align:-2px;margin-right:6px;"></i>
            The referral program is currently <strong>disabled</strong>.
            <?php if ($isAdmin): ?>Enable it in <a href="#" onclick="openSettings()">Program Settings</a>.<?php endif; ?>
          </div>

          <!-- ── Stats Row ───────────────────────────────────────────────── -->
          <div class="row mb-4" id="statsRow">
            <?php
            $statCards = [
              ['id'=>'statReferrers', 'label'=>'Active Referrers',  'icon'=>'users',    'color'=>'var(--mw-green)'],
              ['id'=>'statTotal',     'label'=>'Total Referrals',   'icon'=>'share-2',  'color'=>'var(--mw-green)'],
              ['id'=>'statConverted', 'label'=>'Converted',         'icon'=>'check-circle','color'=>'#0ea5e9'],
              ['id'=>'statRewards',   'label'=>'Rewards Applied',   'icon'=>'gift',     'color'=>'var(--mw-orange)'],
            ];
            foreach ($statCards as $s): ?>
            <div class="col-6 col-lg-3 mb-3">
              <div class="card mw-ref-stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                  <div class="mw-ref-stat-icon" style="color:<?= $s['color'] ?>;">
                    <i data-feather="<?= $s['icon'] ?>"></i>
                  </div>
                  <div>
                    <div class="mw-ref-stat-num" id="<?= $s['id'] ?>">—</div>
                    <div class="mw-ref-stat-lbl"><?= $s['label'] ?></div>
                  </div>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>

          <!-- ── Main Tabs ───────────────────────────────────────────────── -->
          <ul class="nav nav-tabs mw-filter-tabs mb-4" id="refTabs">
            <li class="nav-item">
              <a class="nav-link active" data-tab="referrers" href="#">
                <i data-feather="users" style="width:14px;height:14px;"></i> Your Referrers
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" data-tab="all" href="#">
                <i data-feather="list" style="width:14px;height:14px;"></i> All Referrals
              </a>
            </li>
          </ul>

          <!-- ── Tab: Referrers ──────────────────────────────────────────── -->
          <div id="tabReferrers">
            <!-- Search -->
            <div class="d-flex gap-2 mb-3">
              <input type="text" id="referrerSearch" class="form-control" placeholder="Search clients…" style="max-width:280px;">
            </div>
            <div class="card">
              <div class="card-body p-0">
                <table class="table table-hover mb-0" id="referrersTable">
                  <thead class="thead-light">
                    <tr>
                      <th>Client</th>
                      <th>Referral Code</th>
                      <th class="text-center">Invites Sent</th>
                      <th class="text-center">Referrals</th>
                      <th class="text-center">Converted</th>
                      <th>Last Invite</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody id="referrersBody">
                    <tr><td colspan="7" class="text-center py-4 text-muted">Loading…</td></tr>
                  </tbody>
                </table>
              </div>
              <div class="card-footer d-flex justify-content-between align-items-center py-2">
                <span id="referrersPagInfo" class="text-muted small"></span>
                <div id="referrersPagBtns" class="d-flex gap-1"></div>
              </div>
            </div>
          </div>

          <!-- ── Tab: All Referrals ──────────────────────────────────────── -->
          <div id="tabAll" class="d-none">
            <!-- Status filter -->
            <div class="d-flex gap-2 mb-3 flex-wrap">
              <?php foreach ([''=>'All','pending'=>'Pending','converted'=>'Converted','rewarded'=>'Rewarded','cancelled'=>'Cancelled'] as $v => $l): ?>
              <button class="btn btn-sm mw-filter-tab <?= $v==='' ? 'active' : '' ?>"
                      data-status="<?= $v ?>"><?= $l ?></button>
              <?php endforeach; ?>
            </div>
            <div class="card">
              <div class="card-body p-0">
                <table class="table table-hover mb-0" id="referralsTable">
                  <thead class="thead-light">
                    <tr>
                      <th>Referrer</th>
                      <th>Referred Person</th>
                      <th>Submitted</th>
                      <th>Status</th>
                      <th>Reward</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody id="referralsBody">
                    <tr><td colspan="6" class="text-center py-4 text-muted">Loading…</td></tr>
                  </tbody>
                </table>
              </div>
              <div class="card-footer d-flex justify-content-between align-items-center py-2">
                <span id="referralsPagInfo" class="text-muted small"></span>
                <div id="referralsPagBtns" class="d-flex gap-1"></div>
              </div>
            </div>
          </div>

          <!-- ── Settings Modal ──────────────────────────────────────────── -->
          <?php if ($isAdmin): ?>
          <div class="modal fade" id="settingsModal" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title">Program Settings</h5>
                  <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                  </button>
                </div>
                <div class="modal-body">
                  <div class="form-group">
                    <label class="d-flex align-items-center gap-2">
                      <input type="checkbox" id="settEnabled" class="mr-2">
                      <strong>Program Enabled</strong>
                    </label>
                    <small class="text-muted">When disabled, referral links still work but no new rewards are issued.</small>
                  </div>
                  <div class="form-group">
                    <label for="settName">Program Name</label>
                    <input type="text" id="settName" class="form-control" placeholder="Refer a Friend" maxlength="80">
                  </div>
                  <div class="form-group">
                    <label for="settProduct">Reward — Free Service Trial</label>
                    <select id="settProduct" class="form-control">
                      <option value="">Auto-suggest (based on client's service history)</option>
                    </select>
                    <small class="text-muted">The referring client receives this service for free after their referral's first visit completes.</small>
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                  <button type="button" class="btn btn-success" id="btnSaveSettings" onclick="saveSettings()">Save Settings</button>
                </div>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <!-- ── Reward Applied Modal ────────────────────────────────────── -->
          <div class="modal fade" id="rewardModal" tabindex="-1" role="dialog">
            <div class="modal-dialog modal-sm" role="document">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title">Mark Reward Applied</h5>
                  <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                  <input type="hidden" id="rewardModalId">
                  <div class="form-group">
                    <label for="rewardNote">Note (optional)</label>
                    <input type="text" id="rewardNote" class="form-control" placeholder="e.g. Scheduled for March 20" maxlength="200">
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                  <button type="button" class="btn btn-success" onclick="submitMarkApplied()">Confirm Applied</button>
                </div>
              </div>
            </div>
          </div>

<script>
/* ═══════════════════════════════════════════════════════════════════
   Referral Program page JS
   ═══════════════════════════════════════════════════════════════════ */

const API = '/crm/api/referrals.php';

let currentTab        = 'referrers';
let referrersPage     = 1;
let referralsPage     = 1;
let currentStatus     = '';
let referrerSearchTmr = null;

/* ── Boot ──────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  loadStats();
  loadReferrers(1);

  // Tab switching
  document.querySelectorAll('[data-tab]').forEach(el => {
    el.addEventListener('click', e => {
      e.preventDefault();
      switchTab(el.dataset.tab);
    });
  });

  // Status filter buttons
  document.querySelectorAll('[data-status]').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('[data-status]').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      currentStatus = btn.dataset.status;
      loadReferrals(1);
    });
  });

  // Search debounce
  document.getElementById('referrerSearch').addEventListener('input', e => {
    clearTimeout(referrerSearchTmr);
    referrerSearchTmr = setTimeout(() => loadReferrers(1), 350);
  });

  feather.replace();
});

function switchTab(tab) {
  currentTab = tab;
  document.querySelectorAll('[data-tab]').forEach(el => {
    el.classList.toggle('active', el.dataset.tab === tab);
  });
  document.getElementById('tabReferrers').classList.toggle('d-none', tab !== 'referrers');
  document.getElementById('tabAll').classList.toggle('d-none', tab !== 'all');
  if (tab === 'all' && referralsPage === 1) {
    loadReferrals(1);
  }
}

/* ── Stats ─────────────────────────────────────────────────────── */
function loadStats() {
  fetch(`${API}?action=stats`)
    .then(r => r.json())
    .then(d => {
      if (!d.success) return;
      const s = d.stats;
      setText('statReferrers', s.total_referrers   ?? 0);
      setText('statTotal',     s.total_referrals   ?? 0);
      setText('statConverted', s.converted         ?? 0);
      setText('statRewards',   s.rewards_applied   ?? 0);
    })
    .catch(console.error);
}

/* ── Referrers Tab ─────────────────────────────────────────────── */
function loadReferrers(page) {
  referrersPage = page;
  const q = encodeURIComponent(document.getElementById('referrerSearch').value.trim());
  fetch(`${API}?action=referrers&page=${page}&q=${q}`)
    .then(r => r.json())
    .then(d => {
      if (!d.success) return;
      renderReferrers(d.referrers, d.total, d.page, d.pages);
    })
    .catch(console.error);
}

function renderReferrers(rows, total, page, pages) {
  const tbody = document.getElementById('referrersBody');
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted">No clients found.</td></tr>';
    return;
  }

  tbody.innerHTML = rows.map(r => {
    const code    = r.referral_code || '—';
    const copyBtn = r.referral_code
      ? `<button class="btn btn-xs btn-outline-secondary ml-1" onclick="copyCode('${r.referral_code}')" title="Copy link">
           <i data-feather="copy" style="width:12px;height:12px;"></i>
         </button>` : '';
    return `<tr>
      <td>
        <a href="/crm/clients_appstack.php?id=${r.id}" class="font-weight-500">
          ${esc(r.first_name)} ${esc(r.last_name)}
        </a>
        <div class="text-muted small">${esc(r.email||'')}</div>
      </td>
      <td>
        <code class="mw-ref-code">${esc(code)}</code>${copyBtn}
      </td>
      <td class="text-center">${r.invites_sent || 0}</td>
      <td class="text-center">${r.referral_count || 0}</td>
      <td class="text-center">${r.converted_count || 0}</td>
      <td class="text-muted small">${r.last_invite_sent_at ? fmtDate(r.last_invite_sent_at) : '—'}</td>
      <td class="text-right">
        <?php if ($canEdit): ?>
        <button class="btn btn-sm btn-outline-success" onclick="sendInvite(${r.id}, '${esc(r.first_name)}')">
          <i data-feather="send" style="width:13px;height:13px;"></i> Send Invite
        </button>
        <?php endif; ?>
      </td>
    </tr>`;
  }).join('');

  document.getElementById('referrersPagInfo').textContent = `Showing ${rows.length} of ${total} clients`;
  renderPagination('referrersPagBtns', page, pages, p => loadReferrers(p));
  feather.replace();
}

/* ── All Referrals Tab ─────────────────────────────────────────── */
function loadReferrals(page) {
  referralsPage = page;
  fetch(`${API}?action=list&page=${page}&status=${encodeURIComponent(currentStatus)}`)
    .then(r => r.json())
    .then(d => {
      if (!d.success) return;
      renderReferrals(d.referrals, d.total, d.page, d.pages);
    })
    .catch(console.error);
}

function renderReferrals(rows, total, page, pages) {
  const tbody = document.getElementById('referralsBody');
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">No referrals yet.</td></tr>';
    return;
  }

  const statusBadge = {
    pending:   'badge-secondary',
    converted: 'badge-info',
    rewarded:  'badge-success',
    cancelled: 'badge-light text-muted',
  };

  tbody.innerHTML = rows.map(r => {
    const badgeCls = statusBadge[r.status] || 'badge-secondary';
    const rewardTxt = r.reward_product_name
      ? `Free ${esc(r.reward_product_name)}`
      : (r.reward_value > 0 ? `$${parseFloat(r.reward_value).toFixed(2)} credit` : '—');

    const actions = [];
    <?php if ($canEdit): ?>
    if (r.reward_status === 'pending') {
      actions.push(`<button class="btn btn-xs btn-outline-success" onclick="markRewardApplied(${r.id})">Mark Applied</button>`);
    }
    if (r.status === 'pending') {
      actions.push(`<button class="btn btn-xs btn-outline-danger ml-1" onclick="cancelReferral(${r.id})">Cancel</button>`);
    }
    <?php endif; ?>

    return `<tr>
      <td>
        <div class="font-weight-500">${esc(r.referrer_name || '—')}</div>
        <div class="text-muted small">${esc(r.referrer_email || '')}</div>
      </td>
      <td>
        <div>${esc(r.referred_name || '—')}</div>
        <div class="text-muted small">${esc(r.referred_email || '')}</div>
      </td>
      <td class="text-muted small">${fmtDate(r.created_at)}</td>
      <td><span class="badge ${badgeCls}">${r.status}</span></td>
      <td class="small">${rewardTxt}</td>
      <td class="text-right">${actions.join('')}</td>
    </tr>`;
  }).join('');

  document.getElementById('referralsPagInfo').textContent = `${total} referral${total !== 1 ? 's' : ''}`;
  renderPagination('referralsPagBtns', page, pages, p => loadReferrals(p));
  feather.replace();
}

/* ── Actions ───────────────────────────────────────────────────── */
function sendInvite(contactId, firstName) {
  if (!confirm(`Send referral invite email to ${firstName}?`)) return;
  fetch(`${API}?action=send-invite`, {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({contact_id: contactId})
  })
    .then(r => r.json())
    .then(d => {
      showToast(d.success ? 'Invite sent!' : (d.message || d.error), d.success ? 'success' : 'error');
      if (d.success) loadReferrers(referrersPage);
    })
    .catch(() => showToast('Network error', 'error'));
}

function copyCode(code) {
  const url = `https://mowology.ca/quote?referral_code=${code}`;
  navigator.clipboard.writeText(url).then(() => showToast('Link copied!', 'success'));
}

function markRewardApplied(rewardId) {
  document.getElementById('rewardModalId').value = rewardId;
  document.getElementById('rewardNote').value = '';
  $('#rewardModal').modal('show');
}

function submitMarkApplied() {
  const rewardId = parseInt(document.getElementById('rewardModalId').value);
  const note     = document.getElementById('rewardNote').value.trim();
  fetch(`${API}?action=mark-reward-applied`, {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({reward_id: rewardId, note})
  })
    .then(r => r.json())
    .then(d => {
      $('#rewardModal').modal('hide');
      showToast(d.success ? 'Reward marked applied' : d.error, d.success ? 'success' : 'error');
      if (d.success) { loadStats(); loadReferrals(referralsPage); }
    });
}

function cancelReferral(referralId) {
  if (!confirm('Cancel this referral?')) return;
  fetch(`${API}?action=cancel`, {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({referral_id: referralId})
  })
    .then(r => r.json())
    .then(d => {
      showToast(d.success ? 'Referral cancelled' : d.error, d.success ? 'success' : 'error');
      if (d.success) { loadStats(); loadReferrals(referralsPage); }
    });
}

/* ── Settings ──────────────────────────────────────────────────── */
let settingsData = {};

function openSettings() {
  fetch(`${API}?action=get-settings`)
    .then(r => r.json())
    .then(d => {
      if (!d.success) return;
      settingsData = d;
      const s = d.settings;

      document.getElementById('settEnabled').checked = s.referral_program_enabled === '1';
      document.getElementById('settName').value       = s.referral_program_name || 'Refer a Friend';

      // Populate product dropdown
      const sel = document.getElementById('settProduct');
      sel.innerHTML = '<option value="">Auto-suggest (based on client\'s service history)</option>';
      (d.products || []).forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = `${p.name}${p.base_price > 0 ? ' ($' + parseFloat(p.base_price).toFixed(0) + ')' : ''}`;
        if (String(p.id) === String(s.referral_reward_product_id)) opt.selected = true;
        sel.appendChild(opt);
      });

      // Update disabled banner
      document.getElementById('disabledBanner').classList.toggle('d-none', s.referral_program_enabled === '1');

      $('#settingsModal').modal('show');
    });
}

function saveSettings() {
  const payload = {
    referral_program_enabled:   document.getElementById('settEnabled').checked ? '1' : '0',
    referral_program_name:      document.getElementById('settName').value.trim(),
    referral_reward_product_id: document.getElementById('settProduct').value,
  };

  document.getElementById('btnSaveSettings').disabled = true;

  fetch(`${API}?action=save-settings`, {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  })
    .then(r => r.json())
    .then(d => {
      document.getElementById('btnSaveSettings').disabled = false;
      showToast(d.success ? 'Settings saved' : (d.error || 'Save failed'), d.success ? 'success' : 'error');
      if (d.success) {
        $('#settingsModal').modal('hide');
        document.getElementById('disabledBanner').classList.toggle('d-none', payload.referral_program_enabled === '1');
      }
    })
    .catch(() => { document.getElementById('btnSaveSettings').disabled = false; });
}

/* ── Helpers ───────────────────────────────────────────────────── */
function setText(id, val) {
  const el = document.getElementById(id);
  if (el) el.textContent = val;
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fmtDate(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  return d.toLocaleDateString('en-CA', {month:'short', day:'numeric', year:'numeric'});
}

function renderPagination(containerId, current, total, cb) {
  const el = document.getElementById(containerId);
  if (!el || total <= 1) { if (el) el.innerHTML = ''; return; }
  let html = '';
  if (current > 1) html += `<button class="btn btn-sm btn-outline-secondary" onclick="(${cb})(${current - 1})">‹</button>`;
  for (let p = Math.max(1, current - 2); p <= Math.min(total, current + 2); p++) {
    html += `<button class="btn btn-sm ${p === current ? 'btn-success' : 'btn-outline-secondary'}" onclick="(${cb})(${p})">${p}</button>`;
  }
  if (current < total) html += `<button class="btn btn-sm btn-outline-secondary" onclick="(${cb})(${current + 1})">›</button>`;
  el.innerHTML = html;
}

function showToast(msg, type) {
  // Use AppStack toast if available, otherwise alert fallback
  if (typeof toastr !== 'undefined') {
    toastr[type === 'error' ? 'error' : 'success'](msg);
  } else {
    const d = document.createElement('div');
    d.className = `alert alert-${type === 'error' ? 'danger' : 'success'} mw-ref-toast`;
    d.textContent = msg;
    document.body.appendChild(d);
    setTimeout(() => d.remove(), 3500);
  }
}
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
