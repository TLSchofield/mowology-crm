<?php
/**
 * Social Platform Setup Wizard — Connect GBP, Facebook, and Instagram.
 *
 * A 3-step guide: choose platform → follow connection steps → verify & save.
 * Provides step-by-step instructions for each platform so any team member
 * can connect an account without needing developer support.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('marketing.edit');

$db = getDB();

// ── Load currently connected accounts ─────────────────────────────────────
$connected = $db->query("
    SELECT id, platform, account_name, is_active, is_verified, connected_at, last_sync_at
    FROM social_accounts
    ORDER BY platform
")->fetchAll(PDO::FETCH_ASSOC);

$connectedMap = [];
foreach ($connected as $acct) {
    $connectedMap[$acct['platform']] = $acct;
}

$pageTitle  = 'Connect Social Accounts';
$activePage = 'social';
$csrf       = generateCSRFToken();
$connJson   = json_encode($connectedMap);
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <!-- Page Header -->
          <div class="d-flex justify-content-between align-items-center mb-4">
              <div>
                  <h1 class="h3 mb-0">Connect Social Accounts</h1>
                  <p class="text-muted mb-0">
                      <a href="/crm/marketing/social.php" class="text-muted">Social</a>
                      <span class="mx-1">›</span>
                      Platform Setup
                  </p>
              </div>
              <a href="/crm/marketing/social.php" class="btn btn-outline-secondary btn-sm">Back to Dashboard</a>
          </div>

          <div class="mw-sw-shell">

              <!-- Currently connected -->
              <div class="card mb-4">
                  <div class="card-header d-flex justify-content-between align-items-center">
                      <h5 class="mb-0">Connected Platforms</h5>
                      <button class="btn btn-success btn-sm" onclick="showSetup()">
                          + Add Platform
                      </button>
                  </div>
                  <div class="card-body">
                      <?php if (empty($connected)): ?>
                          <div class="text-center text-muted py-3">
                              <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="mb-2 text-muted"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                              <p class="mb-0">No platforms connected yet. Click <strong>Add Platform</strong> to get started.</p>
                          </div>
                      <?php else: ?>
                          <div class="mw-sw-platforms">
                              <?php foreach ($connected as $acct): ?>
                                  <?php
                                  $plat = $acct['platform'];
                                  $abbr = ['gbp'=>'GBP','facebook'=>'FB','instagram'=>'IG'][$plat] ?? strtoupper($plat);
                                  $name = ['gbp'=>'Google Business Profile','facebook'=>'Facebook','instagram'=>'Instagram'][$plat] ?? ucfirst($plat);
                                  $connDate = $acct['connected_at'] ? date('M j, Y', strtotime($acct['connected_at'])) : '—';
                                  ?>
                                  <div class="mw-sw-plat-row" style="cursor:default">
                                      <div class="mw-sw-plat-logo <?php echo $plat; ?>"><?php echo $abbr; ?></div>
                                      <div style="flex:1">
                                          <div class="mw-sw-plat-name"><?php echo $name; ?></div>
                                          <div class="mw-sw-plat-info"><?php echo htmlspecialchars($acct['account_name']); ?>
                                              — connected <?php echo $connDate; ?></div>
                                      </div>
                                      <div class="mw-sw-plat-status d-flex align-items-center gap-2">
                                          <?php if ($acct['is_verified']): ?>
                                              <span class="mw-sw-plat-badge connected">Verified</span>
                                          <?php else: ?>
                                              <span class="mw-sw-plat-badge not-connected">Unverified</span>
                                          <?php endif; ?>
                                          <button class="btn btn-sm btn-outline-danger"
                                              onclick="disconnectAccount(<?php echo $acct['id']; ?>, '<?php echo $name; ?>')">
                                              Remove
                                          </button>
                                      </div>
                                  </div>
                              <?php endforeach; ?>
                          </div>
                      <?php endif; ?>
                  </div>
              </div>

              <!-- Setup Wizard (shown on click) -->
              <div id="setupWizard" style="display:none">

                  <!-- Progress -->
                  <div class="mw-sw-progress" id="setupProgress">
                      <div class="mw-sw-step active" data-step="A">
                          <div class="mw-sw-step-dot">1</div>
                          <span class="mw-sw-step-lbl">Choose Platform</span>
                      </div>
                      <div class="mw-sw-step" data-step="B">
                          <div class="mw-sw-step-dot">2</div>
                          <span class="mw-sw-step-lbl">Connect</span>
                      </div>
                      <div class="mw-sw-step" data-step="C">
                          <div class="mw-sw-step-dot">3</div>
                          <span class="mw-sw-step-lbl">Verify</span>
                      </div>
                  </div>

                  <!-- ── Setup Step A — Choose platform ── -->
                  <div class="mw-sw-panel active" id="setup-A">
                      <div class="card">
                          <div class="card-header">
                              <h5 class="mb-0">Which platform do you want to connect?</h5>
                          </div>
                          <div class="card-body">
                              <div class="mw-setup-platforms" id="setupPlatCards">

                                  <div class="mw-setup-plat-card" data-plat="gbp" onclick="selectSetupPlat('gbp',this)">
                                      <div class="mw-setup-plat-logo gbp">GBP</div>
                                      <div class="mw-setup-plat-name">Google Business Profile</div>
                                      <div class="mw-setup-plat-status text-muted small">Best for local SEO</div>
                                      <div id="gbp-status" class="mt-2"></div>
                                  </div>

                                  <div class="mw-setup-plat-card" data-plat="facebook" onclick="selectSetupPlat('facebook',this)">
                                      <div class="mw-setup-plat-logo facebook">FB</div>
                                      <div class="mw-setup-plat-name">Facebook</div>
                                      <div class="mw-setup-plat-status text-muted small">Reach homeowner groups</div>
                                      <div id="facebook-status" class="mt-2"></div>
                                  </div>

                                  <div class="mw-setup-plat-card" data-plat="instagram" onclick="selectSetupPlat('instagram',this)">
                                      <div class="mw-setup-plat-logo instagram">IG</div>
                                      <div class="mw-setup-plat-name">Instagram</div>
                                      <div class="mw-setup-plat-status text-muted small">Show off your best work</div>
                                      <div id="instagram-status" class="mt-2"></div>
                                  </div>

                              </div>
                              <div id="setupAError" class="text-danger small mt-2" style="display:none">
                                  Please choose a platform.
                              </div>
                          </div>
                      </div>
                      <div class="mw-sw-nav">
                          <button class="btn btn-outline-secondary" onclick="hideSetup()">Cancel</button>
                          <button class="btn btn-success" onclick="setupNext('A')">
                              Continue
                              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-left:4px"><polyline points="9 18 15 12 9 6"/></svg>
                          </button>
                      </div>
                  </div>

                  <!-- ── Setup Step B — Instructions ── -->
                  <div class="mw-sw-panel" id="setup-B">
                      <div class="card">
                          <div class="card-header">
                              <h5 class="mb-0" id="setupBTitle">Connect your account</h5>
                              <small class="text-muted">Follow these steps to authorise Mowology to post on your behalf.</small>
                          </div>
                          <div class="card-body" id="setupBBody"><!-- filled by JS --></div>
                      </div>
                      <div class="mw-sw-nav">
                          <button class="btn btn-outline-secondary" onclick="setupPrev('B')">
                              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
                              Back
                          </button>
                          <button class="btn btn-success" onclick="setupNext('B')">
                              I've Completed These Steps
                              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-left:4px"><polyline points="9 18 15 12 9 6"/></svg>
                          </button>
                      </div>
                  </div>

                  <!-- ── Setup Step C — Verify & Save ── -->
                  <div class="mw-sw-panel" id="setup-C">
                      <div class="card">
                          <div class="card-header">
                              <h5 class="mb-0">Verify &amp; save connection</h5>
                          </div>
                          <div class="card-body">
                              <p class="text-muted mb-3">
                                  Enter the account name exactly as it appears on the platform, then save.
                                  You can verify the connection is working by posting a test draft after saving.
                              </p>
                              <div class="row">
                                  <div class="col-md-6">
                                      <div class="form-group">
                                          <label><strong>Account / Page Name</strong></label>
                                          <input type="text" class="form-control" id="acctName"
                                              placeholder="e.g., Mowology Landscaping">
                                          <small class="text-muted">This is how it will appear in the CRM.</small>
                                      </div>
                                  </div>
                                  <div class="col-md-6">
                                      <div class="form-group">
                                          <label><strong>Platform Account / Page ID</strong> <small class="text-muted">(optional)</small></label>
                                          <input type="text" class="form-control" id="acctExternalId"
                                              placeholder="Numeric ID or username">
                                          <small class="text-muted">Found in your platform settings.</small>
                                      </div>
                                  </div>
                              </div>

                              <div class="mw-setup-step-block" style="background:#fff3cd;border-color:#ffc107">
                                  <div class="mw-setup-step-num" style="background:#ffc10733;color:#856404">!</div>
                                  <div class="mw-setup-step-body">
                                      <div class="mw-setup-step-title">API Credentials Required</div>
                                      <p class="mw-setup-step-desc">
                                          Full OAuth integration requires developer API credentials to be configured
                                          in <code>secrets.php</code>. Until configured, posts are queued and
                                          must be manually published from each platform's dashboard.
                                          Contact your developer to complete the OAuth setup.
                                      </p>
                                  </div>
                              </div>

                              <div id="setupCError" class="alert alert-danger mt-3" style="display:none"></div>
                          </div>
                      </div>
                      <div class="mw-sw-nav">
                          <button class="btn btn-outline-secondary" onclick="setupPrev('C')">
                              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
                              Back
                          </button>
                          <button class="btn btn-success" id="saveAcctBtn" onclick="saveAccount()">
                              Save Connection
                          </button>
                      </div>
                  </div>

                  <!-- ── Setup Success ── -->
                  <div class="mw-sw-panel" id="setup-success">
                      <div class="card">
                          <div class="card-body mw-sw-success">
                              <div class="mw-sw-success-icon">
                                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><polyline points="20 6 9 17 4 12" stroke-width="2.5"/></svg>
                              </div>
                              <h4 class="mb-2">Platform Connected!</h4>
                              <p class="text-muted mb-4" id="setupSuccessMsg">Your account has been saved.</p>
                              <div class="d-flex justify-content-center gap-3 flex-wrap">
                                  <a href="/crm/marketing/social-post-wizard.php" class="btn btn-success">
                                      Create Your First Post
                                  </a>
                                  <a href="/crm/marketing/social-setup-wizard.php" class="btn btn-outline-secondary">
                                      Add Another Platform
                                  </a>
                              </div>
                          </div>
                      </div>
                  </div>

              </div><!-- /#setupWizard -->

              <!-- Tips card -->
              <div class="card mt-4" id="tipsCard">
                  <div class="card-header"><h5 class="mb-0">Platform tips for Mowology</h5></div>
                  <div class="card-body">
                      <div class="row">
                          <div class="col-md-4">
                              <div class="d-flex align-items-start gap-2 mb-3">
                                  <div class="mw-sw-plat-logo gbp flex-shrink-0" style="width:32px;height:32px;font-size:.6rem">GBP</div>
                                  <div>
                                      <strong class="d-block mb-1">Google Business Profile</strong>
                                      <p class="text-muted small mb-0">GBP posts appear directly in Google Search when someone looks for landscaping in your area. <strong>Post proof-of-work photos weekly</strong> — it directly improves your local search ranking. Most impactful platform for a local business.</p>
                                  </div>
                              </div>
                          </div>
                          <div class="col-md-4">
                              <div class="d-flex align-items-start gap-2 mb-3">
                                  <div class="mw-sw-plat-logo facebook flex-shrink-0" style="width:32px;height:32px;font-size:.6rem">FB</div>
                                  <div>
                                      <strong class="d-block mb-1">Facebook</strong>
                                      <p class="text-muted small mb-0">Best for community groups and word-of-mouth. Use it for <strong>seasonal upsell announcements</strong> (spring aeration, fall cleanup) and neighbourhood announcements. Homeowner groups share local service recommendations actively.</p>
                                  </div>
                              </div>
                          </div>
                          <div class="col-md-4">
                              <div class="d-flex align-items-start gap-2 mb-3">
                                  <div class="mw-sw-plat-logo instagram flex-shrink-0" style="width:32px;height:32px;font-size:.6rem">IG</div>
                                  <div>
                                      <strong class="d-block mb-1">Instagram</strong>
                                      <p class="text-muted small mb-0">Visual platform — great for <strong>before/after transformations</strong> and showcase work. Younger homeowners (25–40) discover local services here. Requires a Business account linked through Facebook. Hashtags drive discovery.</p>
                                  </div>
                              </div>
                          </div>
                      </div>
                  </div>
              </div>

          </div><!-- /.mw-sw-shell -->

<script>
const CONNECTED   = <?php echo $connJson; ?>;
const CSRF_TOKEN  = '<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>';

const PLAT_NAMES = {
    gbp:       'Google Business Profile',
    facebook:  'Facebook',
    instagram: 'Instagram'
};

let selectedPlat = null;

// ── Show/hide setup wizard ───────────────────────────────────────────────
function showSetup() {
    document.getElementById('setupWizard').style.display = 'block';
    document.getElementById('tipsCard').style.display = 'none';
    document.getElementById('setupWizard').scrollIntoView({behavior:'smooth'});
}
function hideSetup() {
    document.getElementById('setupWizard').style.display = 'none';
    document.getElementById('tipsCard').style.display = 'block';
}

// ── Mark connected platforms on cards ───────────────────────────────────
(function() {
    ['gbp','facebook','instagram'].forEach(p => {
        const el = document.getElementById(p + '-status');
        if (!el) return;
        if (CONNECTED[p]) {
            el.innerHTML = '<span class="badge badge-success" style="font-size:.7rem">Connected</span>';
        }
    });
})();

// ── Step navigation ──────────────────────────────────────────────────────
function goSetupStep(step) {
    ['A','B','C','success'].forEach(s => {
        document.getElementById('setup-' + s)?.classList.remove('active');
    });
    document.getElementById('setup-' + step)?.classList.add('active');

    if (step !== 'success') {
        const steps = ['A','B','C'];
        const idx = steps.indexOf(step);
        document.querySelectorAll('#setupProgress .mw-sw-step').forEach((el,i) => {
            el.classList.remove('active','done');
            if (i < idx)  el.classList.add('done');
            if (i === idx) el.classList.add('active');
        });
    }
}
function setupNext(from) {
    if (from === 'A') {
        if (!selectedPlat) {
            document.getElementById('setupAError').style.display = 'block';
            return;
        }
        buildInstructions(selectedPlat);
        goSetupStep('B');
    } else if (from === 'B') {
        goSetupStep('C');
    }
}
function setupPrev(from) {
    if (from === 'B') goSetupStep('A');
    if (from === 'C') goSetupStep('B');
}

function selectSetupPlat(plat, el) {
    selectedPlat = plat;
    document.querySelectorAll('.mw-setup-plat-card').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('setupAError').style.display = 'none';
}

// ── Platform-specific instructions ──────────────────────────────────────
function buildInstructions(plat) {
    document.getElementById('setupBTitle').textContent = 'Connect ' + PLAT_NAMES[plat];

    const instructions = {
        gbp: [
            {
                title: 'Sign in to Google Business Profile',
                desc:  'Go to <a href="https://business.google.com" target="_blank" rel="noopener">business.google.com</a> and sign in with the Google account that manages your Mowology listing.'
            },
            {
                title: 'Open your Profile settings',
                desc:  'Click your business name → <strong>Settings</strong> → <strong>Managers</strong>. Add the Mowology API service account email as a Manager so it can post updates on your behalf.'
            },
            {
                title: 'Note your Location ID',
                desc:  'In the URL when viewing your listing you\'ll see a numeric ID (e.g., <code>1234567890123456789</code>). Copy this — you\'ll need it in the next step.'
            },
            {
                title: 'Return here and save',
                desc:  'Click "I\'ve Completed These Steps" to continue to the save screen where you\'ll enter your account name and Location ID.'
            }
        ],
        facebook: [
            {
                title: 'Switch to your Business Page',
                desc:  'Log in to <a href="https://facebook.com" target="_blank" rel="noopener">Facebook</a> and switch to your Mowology business page (not your personal profile).'
            },
            {
                title: 'Open Page Settings → Advanced Messaging',
                desc:  'Go to <strong>Page Settings → Messaging</strong>. Make sure the page is set up as a Business Account with Messenger enabled.'
            },
            {
                title: 'Get your Page ID',
                desc:  'Go to <strong>About → Page Transparency</strong>. Your Page ID is shown at the bottom (e.g., <code>123456789012345</code>). Copy it.'
            },
            {
                title: 'Note: API access via Meta for Developers',
                desc:  'Full posting automation requires a Meta App with "Pages API" permission approved by Meta. Your developer needs to configure this in <code>secrets.php</code> with the Page Access Token.'
            }
        ],
        instagram: [
            {
                title: 'Ensure you have an Instagram Business Account',
                desc:  'Personal accounts cannot be connected. In the Instagram app go to <strong>Settings → Account → Switch to Professional Account</strong> and choose Business.'
            },
            {
                title: 'Link Instagram to your Facebook Page',
                desc:  'In <strong>Facebook Page Settings → Instagram</strong>, connect your Instagram Business account. Instagram posting is done via the Facebook Graph API — they must be linked.'
            },
            {
                title: 'Get your Instagram Business Account ID',
                desc:  'Once linked, the Instagram Account ID appears in <strong>Facebook Page Settings → Instagram</strong>. It\'s a long numeric ID.'
            },
            {
                title: 'Note: Requires Facebook developer setup first',
                desc:  'Instagram uses the same Facebook App as Facebook posting. If you\'ve already connected Facebook, ask your developer to add Instagram permissions to the existing Meta App.'
            }
        ]
    };

    const steps = instructions[plat] || [];
    const html = steps.map((s, i) => `
        <div class="mw-setup-step-block">
            <div class="mw-setup-step-num">${i+1}</div>
            <div class="mw-setup-step-body">
                <div class="mw-setup-step-title">${s.title}</div>
                <p class="mw-setup-step-desc">${s.desc}</p>
            </div>
        </div>
    `).join('');

    document.getElementById('setupBBody').innerHTML = html;
}

// ── Save account ─────────────────────────────────────────────────────────
async function saveAccount() {
    const name = document.getElementById('acctName').value.trim();
    const extId = document.getElementById('acctExternalId').value.trim();
    const errEl = document.getElementById('setupCError');

    if (!name) {
        errEl.textContent = 'Please enter the account name.';
        errEl.style.display = 'block';
        return;
    }
    errEl.style.display = 'none';

    const btn = document.getElementById('saveAcctBtn');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    try {
        const resp = await fetch('/crm/api/social-account-save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token:          CSRF_TOKEN,
                platform:            selectedPlat,
                account_name:        name,
                account_id_external: extId || null
            })
        });
        const data = await resp.json();

        if (data.success) {
            document.getElementById('setupSuccessMsg').textContent =
                PLAT_NAMES[selectedPlat] + ' "' + name + '" has been saved. Posts will be queued until full API access is configured.';
            goSetupStep('success');
        } else {
            errEl.textContent = 'Error: ' + (data.error || 'Unknown error');
            errEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Save Connection';
        }
    } catch (e) {
        errEl.textContent = 'Network error. Please try again.';
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Save Connection';
    }
}

// ── Disconnect account ────────────────────────────────────────────────────
async function disconnectAccount(id, name) {
    if (!confirm('Remove connection to "' + name + '"? Any scheduled posts for this platform will not be published.')) return;

    const resp = await fetch('/crm/api/social-account-save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: CSRF_TOKEN, action: 'disconnect', id: id })
    });
    const data = await resp.json();
    if (data.success) {
        location.reload();
    } else {
        alert('Error: ' + (data.error || 'Could not remove account'));
    }
}
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
