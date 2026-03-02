<?php
/**
 * Social Post Wizard — 4-step guided post creation.
 *
 * Guides users through: Goal → Template & Content → Platforms & Schedule → Review & Post.
 * Designed for daily use — takes under 2 minutes from click to scheduled post.
 *
 * Query params (optional pre-fill):
 *   job_id=N        Pre-select proof_of_work goal, pull neighbourhood from job
 *   visit_id=N      As above, also pre-select visit for media
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('marketing.edit');

$db = getDB();

// ── Optional context from a job ────────────────────────────────────────────
$jobId      = (int)($_GET['job_id']   ?? 0);
$visitId    = (int)($_GET['visit_id'] ?? 0);
$preGoal    = '';
$preNeigh   = '';
$preService = '';
$preTitle   = '';

if ($jobId > 0) {
    $job = $db->prepare("
        SELECT jp.title, jp.service_type, p.address, c.full_name
        FROM job_plans jp
        LEFT JOIN properties p  ON jp.property_id = p.id
        LEFT JOIN contacts   c  ON p.site_contact_id = c.id
        WHERE jp.id = ?
        LIMIT 1
    ");
    $job->execute([$jobId]);
    $job = $job->fetch(PDO::FETCH_ASSOC);
    if ($job) {
        $preGoal    = 'proof_of_work';
        $preNeigh   = ''; // neighbourhood comes from user input
        $preService = $job['service_type'] ?? '';
        $preTitle   = $job['title'] ?? '';
    }
}

// ── Load active templates from DB ──────────────────────────────────────────
$templates = $db->query("
    SELECT id, name, category, caption_template, hashtag_preset,
           cta_preset, platform_targets
    FROM social_templates
    WHERE is_active = 1
    ORDER BY id
")->fetchAll(PDO::FETCH_ASSOC);

// ── Load connected accounts ────────────────────────────────────────────────
$accounts = $db->query("
    SELECT id, platform, account_name, is_active, is_verified
    FROM social_accounts
    WHERE is_active = 1
    ORDER BY platform
")->fetchAll(PDO::FETCH_ASSOC);

// Index accounts by platform for easy JS access
$accountsByPlatform = [];
foreach ($accounts as $acct) {
    $accountsByPlatform[$acct['platform']] = $acct;
}

$pageTitle  = 'New Social Post';
$activePage = 'social';
$canApprove = userHasPermission('marketing.approve');

$templatesJson = json_encode($templates);
$accountsJson  = json_encode($accountsByPlatform);
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <!-- Page Header -->
          <div class="d-flex justify-content-between align-items-center mb-4">
              <div>
                  <h1 class="h3 mb-0">New Social Post</h1>
                  <p class="text-muted mb-0">
                      <a href="/crm/marketing/social.php" class="text-muted">Social</a>
                      <span class="mx-1">›</span>
                      Post Wizard
                  </p>
              </div>
              <a href="/crm/marketing/social-post-editor.php" class="btn btn-outline-secondary btn-sm">
                  Advanced Editor
              </a>
          </div>

          <div class="mw-sw-shell">

              <!-- Progress Steps -->
              <div class="mw-sw-progress" id="wizProgress">
                  <div class="mw-sw-step active" data-step="1">
                      <div class="mw-sw-step-dot">1</div>
                      <span class="mw-sw-step-lbl">Goal</span>
                  </div>
                  <div class="mw-sw-step" data-step="2">
                      <div class="mw-sw-step-dot">2</div>
                      <span class="mw-sw-step-lbl">Content</span>
                  </div>
                  <div class="mw-sw-step" data-step="3">
                      <div class="mw-sw-step-dot">3</div>
                      <span class="mw-sw-step-lbl">Schedule</span>
                  </div>
                  <div class="mw-sw-step" data-step="4">
                      <div class="mw-sw-step-dot">4</div>
                      <span class="mw-sw-step-lbl">Review</span>
                  </div>
              </div>

              <!-- ═══ STEP 1 — Goal ═══════════════════════════════════════════ -->
              <div class="mw-sw-panel active" id="panel-1">
                  <div class="card">
                      <div class="card-header">
                          <h5 class="mb-0">What are you sharing today?</h5>
                          <small class="text-muted">Pick the type of content that matches your goal.</small>
                      </div>
                      <div class="card-body">
                          <div class="mw-sw-goals" id="goalCards">

                              <div class="mw-sw-goal-card" data-goal="proof_of_work" onclick="selectGoal('proof_of_work',this)">
                                  <div class="mw-sw-goal-icon">
                                      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                  </div>
                                  <div style="flex:1">
                                      <div class="mw-sw-goal-title">After a Job</div>
                                      <p class="mw-sw-goal-desc">Show off completed work — fresh cuts, tidy edges, before &amp; after transformations. Builds trust and attracts new clients.</p>
                                  </div>
                                  <div class="mw-sw-goal-check">
                                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                  </div>
                              </div>

                              <div class="mw-sw-goal-card" data-goal="upsell" onclick="selectGoal('upsell',this)">
                                  <div class="mw-sw-goal-icon">
                                      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                                  </div>
                                  <div style="flex:1">
                                      <div class="mw-sw-goal-title">Seasonal Service</div>
                                      <p class="mw-sw-goal-desc">Promote a seasonal treatment — fertilizer, aeration, power raking, spring or fall cleanup. Perfect for filling your schedule.</p>
                                  </div>
                                  <div class="mw-sw-goal-check">
                                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                  </div>
                              </div>

                              <div class="mw-sw-goal-card" data-goal="announcement" onclick="selectGoal('announcement',this)">
                                  <div class="mw-sw-goal-icon">
                                      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                  </div>
                                  <div style="flex:1">
                                      <div class="mw-sw-goal-title">New Neighbourhood</div>
                                      <p class="mw-sw-goal-desc">Announce that you're now serving a new area. Great for expanding routes and picking up clusters of clients in new neighbourhoods.</p>
                                  </div>
                                  <div class="mw-sw-goal-check">
                                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                  </div>
                              </div>

                              <div class="mw-sw-goal-card" data-goal="review" onclick="selectGoal('review',this)">
                                  <div class="mw-sw-goal-icon">
                                      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                  </div>
                                  <div style="flex:1">
                                      <div class="mw-sw-goal-title">Customer Review</div>
                                      <p class="mw-sw-goal-desc">Share a 5-star review from a happy client. Social proof is your most powerful sales tool — show it off.</p>
                                  </div>
                                  <div class="mw-sw-goal-check">
                                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                  </div>
                              </div>

                          </div>
                          <div id="step1Error" class="text-danger small" style="display:none">
                              Please choose a goal to continue.
                          </div>
                      </div>
                  </div>

                  <div class="mw-sw-nav">
                      <span class="mw-sw-nav-info">Step 1 of 4</span>
                      <button class="btn btn-success btn-lg" onclick="nextStep(1)">
                          Continue
                          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-left:4px"><polyline points="9 18 15 12 9 6"/></svg>
                      </button>
                  </div>
              </div>

              <!-- ═══ STEP 2 — Content ════════════════════════════════════════ -->
              <div class="mw-sw-panel" id="panel-2">
                  <div class="row">

                      <!-- Template picker -->
                      <div class="col-md-5">
                          <div class="card mb-3">
                              <div class="card-header">
                                  <h5 class="mb-0">Choose a template</h5>
                                  <small class="text-muted">Filtered for your goal. Click to select.</small>
                              </div>
                              <div class="card-body" style="max-height:480px;overflow-y:auto;padding:.75rem">
                                  <div class="mw-sw-templates" id="templateList">
                                      <div class="mw-sw-no-templates text-muted">Select a goal first.</div>
                                  </div>
                              </div>
                          </div>
                      </div>

                      <!-- Variable fill + preview -->
                      <div class="col-md-7">
                          <div class="card mb-3">
                              <div class="card-header">
                                  <h5 class="mb-0">Personalise your post</h5>
                              </div>
                              <div class="card-body" id="varPanel">
                                  <div class="text-center text-muted py-4" id="varPlaceholder">
                                      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="text-muted mb-2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                      <p class="mb-0 small">Select a template to personalise it.</p>
                                  </div>
                                  <div id="varFields" style="display:none">
                                      <div class="mw-sw-vars-wrap mb-3">
                                          <div id="varInputsArea"><!-- filled by JS --></div>
                                      </div>
                                      <div class="mb-1 d-flex justify-content-between align-items-center">
                                          <label class="mw-sw-var-label mb-0">Caption Preview</label>
                                          <small class="text-muted" id="charCounter">0 chars</small>
                                      </div>
                                      <div class="mw-sw-caption-preview" id="captionPreview"></div>

                                      <div class="mt-3">
                                          <label class="mw-sw-var-label">Hashtags</label>
                                          <textarea class="form-control form-control-sm" id="wizHashtags"
                                              rows="2" placeholder="#VancouverLandscaping #Mowology"></textarea>
                                          <small class="text-muted">Will be appended to your caption when publishing.</small>
                                      </div>
                                  </div>
                              </div>
                          </div>
                      </div>
                  </div>

                  <div id="step2Error" class="alert alert-warning" style="display:none">
                      Please select a template and fill in at least the neighbourhood.
                  </div>

                  <div class="mw-sw-nav">
                      <button class="btn btn-outline-secondary" onclick="prevStep(2)">
                          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
                          Back
                      </button>
                      <span class="mw-sw-nav-info">Step 2 of 4</span>
                      <button class="btn btn-success" onclick="nextStep(2)">
                          Continue
                          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-left:4px"><polyline points="9 18 15 12 9 6"/></svg>
                      </button>
                  </div>
              </div>

              <!-- ═══ STEP 3 — Platforms & Schedule ═════════════════════════ -->
              <div class="mw-sw-panel" id="panel-3">
                  <div class="row">
                      <!-- Platforms -->
                      <div class="col-md-5">
                          <div class="card mb-3">
                              <div class="card-header">
                                  <h5 class="mb-0">Publish to</h5>
                                  <small class="text-muted">Select one or more platforms.</small>
                              </div>
                              <div class="card-body">
                                  <div class="mw-sw-platforms" id="platformList"><!-- filled by JS --></div>
                                  <div id="noPlatformsWarn" class="mw-sw-warning-box mt-3" style="display:none">
                                      <strong>No connected accounts.</strong>
                                      <a href="/crm/marketing/social-setup-wizard.php" class="ml-1">Connect a platform →</a>
                                      Posts can still be saved as drafts.
                                  </div>
                              </div>
                          </div>
                      </div>

                      <!-- Schedule -->
                      <div class="col-md-7">
                          <div class="card mb-3">
                              <div class="card-header">
                                  <h5 class="mb-0">When to post</h5>
                                  <small class="text-muted">Morning posts (9–10am) typically get the best engagement.</small>
                              </div>
                              <div class="card-body">
                                  <p class="text-muted small mb-2">Quick options:</p>
                                  <div class="mw-sw-sched-presets" id="schedPresets">
                                      <button class="mw-sw-sched-btn" onclick="setSchedule('now',this)">Post Now</button>
                                      <button class="mw-sw-sched-btn" onclick="setSchedule('today9',this)">Today 9am</button>
                                      <button class="mw-sw-sched-btn" onclick="setSchedule('today6pm',this)">Today 6pm</button>
                                      <button class="mw-sw-sched-btn" onclick="setSchedule('tomorrow9',this)">Tomorrow 9am</button>
                                      <button class="mw-sw-sched-btn" onclick="setSchedule('custom',this)">Custom…</button>
                                  </div>
                                  <div class="mw-sw-sched-custom" id="customSchedWrap">
                                      <label class="mw-sw-var-label">Choose date &amp; time</label>
                                      <input type="datetime-local" class="form-control" id="customSchedInput" onchange="updateScheduleFromInput()">
                                  </div>

                                  <div id="schedSummary" class="mt-3 p-3 rounded" style="background:#f2faf5;display:none">
                                      <strong class="d-block mb-1" style="font-size:.85rem;color:var(--mw-dark)">Scheduled for:</strong>
                                      <span class="text-dark" id="schedSummaryText" style="font-size:.9rem"></span>
                                  </div>

                                  <div class="mt-3 pt-3 border-top">
                                      <p class="text-muted small mb-2"><strong>Why timing matters:</strong></p>
                                      <ul class="text-muted small pl-3 mb-0">
                                          <li>Morning 9–10am: homeowners planning their day see your post</li>
                                          <li>Evening 6–7pm: people browsing after dinner</li>
                                          <li>Avoid Monday mornings and late nights</li>
                                      </ul>
                                  </div>
                              </div>
                          </div>
                      </div>
                  </div>

                  <div id="step3Error" class="alert alert-warning" style="display:none">
                      Please choose when to post.
                  </div>

                  <div class="mw-sw-nav">
                      <button class="btn btn-outline-secondary" onclick="prevStep(3)">
                          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
                          Back
                      </button>
                      <span class="mw-sw-nav-info">Step 3 of 4</span>
                      <button class="btn btn-success" onclick="nextStep(3)">
                          Review Post
                          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-left:4px"><polyline points="9 18 15 12 9 6"/></svg>
                      </button>
                  </div>
              </div>

              <!-- ═══ STEP 4 — Review ════════════════════════════════════════ -->
              <div class="mw-sw-panel" id="panel-4">
                  <div class="card mb-3">
                      <div class="card-header d-flex justify-content-between align-items-center">
                          <h5 class="mb-0">Review your post</h5>
                          <button class="btn btn-sm btn-outline-secondary" onclick="prevStep(4)">Edit</button>
                      </div>
                      <div class="card-body">
                          <div id="reviewPreviews"><!-- filled by JS --></div>

                          <div id="approvalNote" class="mw-sw-warning-box" style="display:none">
                              <strong>Approval required.</strong>
                              This post will be submitted for review before publishing.
                              You'll be notified when it's approved.
                          </div>
                      </div>
                  </div>

                  <div id="step4Error" class="alert alert-danger" style="display:none"></div>

                  <div class="mw-sw-nav">
                      <button class="btn btn-outline-secondary" onclick="prevStep(4)">
                          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
                          Back
                      </button>
                      <span class="mw-sw-nav-info">Step 4 of 4</span>
                      <button class="btn btn-success btn-lg" id="submitBtn" onclick="submitPost()">
                          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right:5px"><path d="M22 2L11 13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                          <?php echo $canApprove ? 'Create Post' : 'Submit for Approval'; ?>
                      </button>
                  </div>
              </div>

              <!-- ═══ SUCCESS ═══════════════════════════════════════════════ -->
              <div class="mw-sw-panel" id="panel-success">
                  <div class="card">
                      <div class="card-body mw-sw-success">
                          <div class="mw-sw-success-icon">
                              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><polyline points="20 6 9 17 4 12" stroke-width="2.5"/></svg>
                          </div>
                          <h4 class="mb-2">Post Created!</h4>
                          <p class="text-muted mb-4" id="successMsg">Your post has been scheduled.</p>
                          <div class="d-flex justify-content-center gap-3 flex-wrap">
                              <a href="/crm/marketing/social.php" class="btn btn-success">
                                  View Dashboard
                              </a>
                              <a href="/crm/marketing/social-calendar.php" class="btn btn-outline-secondary">
                                  Open Calendar
                              </a>
                              <a href="/crm/marketing/social-post-wizard.php" class="btn btn-outline-secondary">
                                  Create Another Post
                              </a>
                          </div>
                      </div>
                  </div>
              </div>

          </div><!-- /.mw-sw-shell -->

<script>
// ── Data from PHP ─────────────────────────────────────────────────────────
const TEMPLATES    = <?php echo $templatesJson; ?>;
const ACCOUNTS     = <?php echo $accountsJson; ?>;
const CAN_APPROVE  = <?php echo $canApprove ? 'true' : 'false'; ?>;
const CSRF_TOKEN   = '<?php echo htmlspecialchars(generateCSRFToken(), ENT_QUOTES); ?>';

// Map review goal → category (review has no DB category so we alias it)
const GOAL_CATEGORY = {
    proof_of_work: 'proof_of_work',
    upsell:        'upsell',
    announcement:  'announcement',
    review:        'proof_of_work'   // 5-star template is in proof_of_work
};

// Friendly platform labels
const PLAT_NAMES = {
    gbp:       'Google Business Profile',
    facebook:  'Facebook',
    instagram: 'Instagram'
};
const PLAT_ABBR = {
    gbp:       'GBP',
    facebook:  'FB',
    instagram: 'IG'
};

// ── Wizard state ─────────────────────────────────────────────────────────
const state = {
    step:       1,
    goal:       '<?php echo $preGoal; ?>',
    templateId: null,
    tmpl:       null,
    vars:       { neighborhood: '', city: 'Vancouver', service: '<?php echo $preService; ?>' },
    hashtags:   '',
    platforms:  [],
    schedule:   null,      // ISO datetime string, or 'now'
    schedLabel: ''
};

// ── Step navigation ──────────────────────────────────────────────────────
function goStep(n) {
    document.querySelectorAll('.mw-sw-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('panel-' + (n === 'success' ? 'success' : n))?.classList.add('active');

    if (n !== 'success') {
        document.querySelectorAll('.mw-sw-step').forEach(el => {
            const s = +el.dataset.step;
            el.classList.remove('active','done');
            if (s < n)  el.classList.add('done');
            if (s === n) el.classList.add('active');
        });
    }
    state.step = n;
    window.scrollTo({top: 0, behavior: 'smooth'});
}

function nextStep(from) {
    if (!validate(from)) return;
    if (from === 2) buildPlatformList();
    if (from === 3) buildReview();
    goStep(from + 1);
}
function prevStep(from) { goStep(from - 1); }

// ── Validation ───────────────────────────────────────────────────────────
function validate(step) {
    if (step === 1) {
        const ok = !!state.goal;
        document.getElementById('step1Error').style.display = ok ? 'none' : 'block';
        if (ok) buildTemplateList(GOAL_CATEGORY[state.goal]);
        return ok;
    }
    if (step === 2) {
        const ok = state.templateId && state.vars.neighborhood.trim().length > 0;
        document.getElementById('step2Error').style.display = ok ? 'none' : 'block';
        return ok;
    }
    if (step === 3) {
        const ok = !!state.schedule;
        document.getElementById('step3Error').style.display = ok ? 'none' : 'block';
        return ok;
    }
    return true;
}

// ── Step 1 — Goal selection ──────────────────────────────────────────────
function selectGoal(goal, el) {
    state.goal = goal;
    state.templateId = null;
    state.tmpl = null;
    document.querySelectorAll('.mw-sw-goal-card').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('step1Error').style.display = 'none';
}

// Pre-select goal from PHP if provided
(function () {
    if (state.goal) {
        const card = document.querySelector('[data-goal="' + state.goal + '"]');
        if (card) card.classList.add('selected');
    }
})();

// ── Step 2 — Templates ───────────────────────────────────────────────────
function buildTemplateList(category) {
    const list = document.getElementById('templateList');
    // For "review" goal, show 5-star template specifically, plus any proof_of_work
    let filtered = TEMPLATES.filter(t => t.category === category);
    if (state.goal === 'review') {
        // Prefer "5-Star" template first
        filtered.sort((a,b) => (b.name.includes('5-Star') ? 1 : 0) - (a.name.includes('5-Star') ? 1 : 0));
    }
    if (!filtered.length) {
        list.innerHTML = '<div class="mw-sw-no-templates">No templates for this goal yet.</div>';
        return;
    }
    list.innerHTML = filtered.map(t => {
        const prev = t.caption_template.replace(/\{[^}]+\}/g, m => '<em>' + m + '</em>').substring(0, 110) + '…';
        const plats = (t.platform_targets || '').split(',').map(p => `<span class="mw-sw-tmpl-pill">${(PLAT_ABBR[p.trim()] || p.trim())}</span>`).join('');
        return `<div class="mw-sw-tmpl-card" data-id="${t.id}" onclick="selectTemplate(${t.id},this)">
            <div style="flex:1">
                <div class="mw-sw-tmpl-name">${escHtml(t.name)}</div>
                <p class="mw-sw-tmpl-prev">${prev}</p>
                <div class="mw-sw-tmpl-pills">${plats}</div>
            </div>
            <div class="mw-sw-tmpl-check">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
        </div>`;
    }).join('');
}

function selectTemplate(id, el) {
    state.templateId = id;
    state.tmpl = TEMPLATES.find(t => t.id === id);
    document.querySelectorAll('.mw-sw-tmpl-card').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    buildVarPanel();
    document.getElementById('step2Error').style.display = 'none';
}

// ── Step 2 — Variables ───────────────────────────────────────────────────
function buildVarPanel() {
    if (!state.tmpl) return;
    document.getElementById('varPlaceholder').style.display = 'none';
    document.getElementById('varFields').style.display = 'block';

    // Extract {variables} from template
    const tmplText = state.tmpl.caption_template;
    const found = [...new Set([...tmplText.matchAll(/\{([^}]+)\}/g)].map(m => m[1]))];

    const varDefs = {
        neighborhood: { label: 'Neighbourhood', placeholder: 'e.g., Kitsilano, South Granville, Burnaby…', type: 'text' },
        city:         { label: 'City',           placeholder: 'Vancouver',                                   type: 'text', default: 'Vancouver' },
        service:      { label: 'Service',         placeholder: '',                                            type: 'select',
                        options: ['Lawn Care','Hedge Trimming','Aeration','Power Raking','Fertilization','Spring Cleanup','Fall Cleanup','Landscaping'] },
        date:         { label: 'Date',            placeholder: '',                                            type: 'text',
                        default: new Date().toLocaleDateString('en-CA',{month:'long',day:'numeric',year:'numeric'}) },
        crew_name:    { label: 'Crew / Staff',    placeholder: 'e.g., Tim, The Mowology Team',               type: 'text' }
    };

    const html = found.map(v => {
        const def = varDefs[v] || { label: v.replace(/_/g,' '), placeholder: '', type: 'text' };
        const curVal = state.vars[v] || def.default || '';
        if (def.type === 'select') {
            const opts = def.options.map(o => `<option value="${o}" ${curVal === o ? 'selected' : ''}>${o}</option>`).join('');
            return `<div class="mw-sw-var-row">
                <div class="mw-sw-var-label">{${v}} — ${def.label}</div>
                <select class="form-control form-control-sm" onchange="setVar('${v}',this.value)">
                    <option value="">— Select —</option>${opts}
                </select>
            </div>`;
        }
        return `<div class="mw-sw-var-row">
            <div class="mw-sw-var-label">{${v}} — ${def.label}</div>
            <input type="text" class="form-control form-control-sm" value="${escHtml(curVal)}"
                placeholder="${escHtml(def.placeholder)}"
                oninput="setVar('${v}',this.value)">
        </div>`;
    }).join('');

    document.getElementById('varInputsArea').innerHTML = html || '<p class="text-muted small">No variables needed — this template is ready to use as-is.</p>';

    // Initialise vars that have defaults
    found.forEach(v => {
        const def = varDefs[v];
        if (def?.default && !state.vars[v]) state.vars[v] = def.default;
    });

    // Load hashtags from template
    if (state.tmpl.hashtag_preset && !document.getElementById('wizHashtags').value) {
        document.getElementById('wizHashtags').value = state.tmpl.hashtag_preset;
        state.hashtags = state.tmpl.hashtag_preset;
    }

    updatePreview();
}

function setVar(key, val) {
    state.vars[key] = val;
    updatePreview();
}

function updatePreview() {
    if (!state.tmpl) return;
    let text = state.tmpl.caption_template;
    // Replace filled vars
    text = text.replace(/\{([^}]+)\}/g, (m, key) => {
        const val = state.vars[key];
        return val ? `[${val}]` : m;
    });
    document.getElementById('captionPreview').textContent = text;
    document.getElementById('charCounter').textContent = text.length + ' chars';
    state.hashtags = document.getElementById('wizHashtags').value;
}

// Sync hashtag field changes
document.getElementById('wizHashtags')?.addEventListener('input', e => { state.hashtags = e.target.value; });

// ── Step 3 — Platforms ───────────────────────────────────────────────────
function buildPlatformList() {
    const platforms = ['gbp','facebook','instagram'];
    const hasAny = platforms.some(p => ACCOUNTS[p]);
    document.getElementById('noPlatformsWarn').style.display = hasAny ? 'none' : 'block';

    const html = platforms.map(p => {
        const acct = ACCOUNTS[p];
        const statusBadge = acct
            ? `<span class="mw-sw-plat-badge connected">Connected</span>`
            : `<span class="mw-sw-plat-badge not-connected">Not connected</span>`;
        const info = acct ? acct.account_name : `<a href="/crm/marketing/social-setup-wizard.php">Set up ${PLAT_NAMES[p]}</a>`;
        const isSelected = state.platforms.includes(p);
        return `<div class="mw-sw-plat-row${isSelected ? ' selected' : ''}${!acct ? ' disabled' : ''}"
                     onclick="togglePlatform('${p}',this)">
            <div class="mw-sw-plat-logo ${p}">${PLAT_ABBR[p]}</div>
            <div style="flex:1">
                <div class="mw-sw-plat-name">${PLAT_NAMES[p]}</div>
                <div class="mw-sw-plat-info">${info}</div>
            </div>
            <div class="mw-sw-plat-status">${statusBadge}</div>
            <div class="mw-sw-plat-toggle">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
        </div>`;
    }).join('');

    document.getElementById('platformList').innerHTML = html;
}

function togglePlatform(p, el) {
    const idx = state.platforms.indexOf(p);
    if (idx === -1) { state.platforms.push(p); el.classList.add('selected'); }
    else            { state.platforms.splice(idx,1); el.classList.remove('selected'); }
}

// ── Step 3 — Schedule ────────────────────────────────────────────────────
function setSchedule(preset, btn) {
    document.querySelectorAll('.mw-sw-sched-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    const customWrap = document.getElementById('customSchedWrap');

    const now = new Date();
    let dt, label;

    if (preset === 'now') {
        dt = 'now'; label = 'Immediately after saving';
        customWrap.classList.remove('show');
    } else if (preset === 'today9') {
        const d = new Date(now); d.setHours(9,0,0,0);
        if (d < now) d.setDate(d.getDate()+1); // if past 9am push to tomorrow
        dt = d.toISOString().slice(0,16); label = formatDt(d, 'at 9:00am');
        customWrap.classList.remove('show');
    } else if (preset === 'today6pm') {
        const d = new Date(now); d.setHours(18,0,0,0);
        if (d < now) d.setDate(d.getDate()+1);
        dt = d.toISOString().slice(0,16); label = formatDt(d, 'at 6:00pm');
        customWrap.classList.remove('show');
    } else if (preset === 'tomorrow9') {
        const d = new Date(now); d.setDate(d.getDate()+1); d.setHours(9,0,0,0);
        dt = d.toISOString().slice(0,16); label = formatDt(d, 'at 9:00am');
        customWrap.classList.remove('show');
    } else if (preset === 'custom') {
        customWrap.classList.add('show');
        return; // wait for datetime input
    }

    state.schedule  = dt;
    state.schedLabel = label;
    showSchedSummary(label);
}

function updateScheduleFromInput() {
    const val = document.getElementById('customSchedInput').value;
    if (!val) return;
    const d = new Date(val);
    state.schedule   = val;
    state.schedLabel = formatDt(d, 'at ' + d.toLocaleTimeString('en-CA',{hour:'2-digit',minute:'2-digit'}));
    showSchedSummary(state.schedLabel);
}

function showSchedSummary(label) {
    const el = document.getElementById('schedSummary');
    el.style.display = 'block';
    document.getElementById('schedSummaryText').textContent = label;
}

function formatDt(d, timeSuffix) {
    return d.toLocaleDateString('en-CA',{weekday:'long',month:'long',day:'numeric'}) + ' ' + timeSuffix;
}

// ── Step 4 — Review ──────────────────────────────────────────────────────
function buildReview() {
    const finalCaption = buildFinalCaption();
    const platforms = state.platforms.length ? state.platforms : ['draft'];
    const schedLabel = state.schedLabel || 'Now';

    document.getElementById('approvalNote').style.display = CAN_APPROVE ? 'none' : 'block';

    const html = (state.platforms.length ? state.platforms : ['(Draft — no platforms selected)']).map(p => {
        const realPlat = ['gbp','facebook','instagram'].includes(p);
        return `<div class="mw-sw-review-post">
            <div class="mw-sw-review-header">
                ${realPlat ? `<span class="mw-sw-review-plat-badge ${p}">${PLAT_NAMES[p] || p}</span>` : `<span class="badge badge-secondary">${p}</span>`}
                <small class="text-muted ml-auto">Mowology Landscaping</small>
            </div>
            <div class="mw-sw-review-body">
                <div class="mw-sw-review-caption">${escHtml(finalCaption)}</div>
                ${state.hashtags ? `<div class="mw-sw-review-hashtags">${escHtml(state.hashtags)}</div>` : ''}
            </div>
            <div class="mw-sw-review-schedule">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                ${escHtml(schedLabel)}
            </div>
        </div>`;
    }).join('');

    document.getElementById('reviewPreviews').innerHTML = html;
}

function buildFinalCaption() {
    if (!state.tmpl) return '';
    let text = state.tmpl.caption_template;
    text = text.replace(/\{([^}]+)\}/g, (m, key) => state.vars[key] || m);
    return text;
}

// ── Submit ───────────────────────────────────────────────────────────────
async function submitPost() {
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    const payload = {
        csrf_token:  CSRF_TOKEN,
        goal:        state.goal,
        template_id: state.templateId,
        caption:     buildFinalCaption(),
        hashtags:    state.hashtags,
        neighborhood: state.vars.neighborhood || '',
        city:         state.vars.city || 'Vancouver',
        service_type: state.vars.service || '',
        platforms:    state.platforms,
        schedule:     state.schedule === 'now' ? null : (state.schedule || null),
        can_approve:  CAN_APPROVE
    };

    try {
        const resp = await fetch('/crm/api/social-post-save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await resp.json();

        if (data.success) {
            const status = CAN_APPROVE ? 'approved' : 'pending_approval';
            const msg = state.schedule && state.schedule !== 'now'
                ? `Scheduled for ${state.schedLabel}.`
                : 'Submitted for approval.';
            document.getElementById('successMsg').textContent = msg;
            document.querySelectorAll('.mw-sw-step').forEach(s => s.classList.replace('active','done'));
            goStep('success');
        } else {
            document.getElementById('step4Error').textContent = 'Error: ' + (data.error || 'Unknown error');
            document.getElementById('step4Error').style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Try Again';
        }
    } catch (e) {
        document.getElementById('step4Error').textContent = 'Network error. Please try again.';
        document.getElementById('step4Error').style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Try Again';
    }
}

// ── Utility ──────────────────────────────────────────────────────────────
function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Auto-init ─────────────────────────────────────────────────────────────
// If coming from a job, skip to step 2 and pre-select the goal
(function () {
    if ('<?php echo $preGoal; ?>' && '<?php echo $jobId; ?>' > 0) {
        validate(1); // triggers buildTemplateList
        goStep(2);
    }
})();
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
