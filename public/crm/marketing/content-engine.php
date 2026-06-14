<?php
/**
 * Content Engine — SEO Autopilot + Content Cascade
 *
 * Write one great article. Amplify it everywhere.
 *
 * Workflow:
 *   1. Identify GSC keyword opportunity (or enter manually)
 *   2. Generate article draft via Claude (ArticleGeneratorService)
 *   3. Human review + edit + add real photos
 *   4. Publish & Amplify → fan-out to social, email, PDF footer, signature
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
requirePermission('marketing.view');

$canEdit = userHasPermission('marketing.edit');

$pageTitle  = 'Content Engine';
$activePage = 'marketing';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h3 mb-0">Content Engine</h1>
        <p class="text-muted mb-0">Write once &mdash; amplify everywhere</p>
    </div>
    <?php if ($canEdit): ?>
    <button class="btn btn-success" onclick="showStep(1)">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        New Article
    </button>
    <?php endif; ?>
</div>

<!-- Step Wizard -->
<div id="ceWizard">

    <!-- Step indicator -->
    <div class="mw-ce-steps mb-4">
        <div class="mw-ce-step active" data-step="1">
            <span class="mw-ce-step-num">1</span>
            <span class="mw-ce-step-label">Brief</span>
        </div>
        <div class="mw-ce-step-line"></div>
        <div class="mw-ce-step" data-step="2">
            <span class="mw-ce-step-num">2</span>
            <span class="mw-ce-step-label">Review</span>
        </div>
        <div class="mw-ce-step-line"></div>
        <div class="mw-ce-step" data-step="3">
            <span class="mw-ce-step-num">3</span>
            <span class="mw-ce-step-label">Amplify</span>
        </div>
    </div>

    <!-- ── STEP 1: Brief ─────────────────────────────────────────────── -->
    <div id="ceStep1" class="mw-ce-panel">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <h5 class="mb-1">Article Brief</h5>
                <p class="text-muted small mb-4">All three fields are required. The more specific you are, the better the article.</p>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Target Search Query <span class="text-danger">*</span></label>
                        <input type="text" id="ceKeyword" class="form-control"
                               placeholder="e.g. lawn aeration cost Surrey BC">
                        <div class="form-text">The exact phrase someone would Google</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">City / Area <span class="text-danger">*</span></label>
                        <input type="text" id="ceCity" class="form-control" placeholder="Surrey">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Service Type <span class="text-danger">*</span></label>
                        <input type="text" id="ceServiceType" class="form-control" placeholder="lawn aeration">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Season</label>
                        <select id="ceSeason" class="form-select">
                            <option value="">Any / evergreen</option>
                            <option value="spring">Spring</option>
                            <option value="summer">Summer</option>
                            <option value="fall">Fall</option>
                            <option value="winter">Winter</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Target Length</label>
                        <select id="ceWordCount" class="form-select">
                            <option value="1200">~1,200 words (quick guide)</option>
                            <option value="1800" selected>~1,800 words (standard)</option>
                            <option value="2400">~2,400 words (competitive)</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Real Project Details <span class="text-muted fw-normal">(optional but strongly recommended)</span></label>
                        <textarea id="ceProjectDetails" class="form-control" rows="3"
                            placeholder="e.g. 3,500 sq ft property in South Surrey, clay soil, had significant compaction and drainage issues. Aerated + overseeded. Lawn showed visible improvement within 3 weeks."></textarea>
                        <div class="form-text">These details become the case study section — the most human-sounding part of the article.</div>
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2 align-items-center">
                    <?php if ($canEdit): ?>
                    <button class="btn btn-success px-4" id="ceBtnGenerate" onclick="generateArticle()">
                        Generate Article Draft
                    </button>
                    <?php endif; ?>
                    <div id="ceGenerateSpinner" class="d-none">
                        <div class="spinner-border spinner-border-sm text-success me-2" role="status"></div>
                        <span class="text-muted small">Claude is writing your article&hellip; (30–60 seconds)</span>
                    </div>
                </div>
                <div id="ceGenerateError" class="alert alert-danger mt-3 d-none"></div>
            </div>
        </div>
    </div>

    <!-- ── STEP 2: Review & Edit ─────────────────────────────────────── -->
    <div id="ceStep2" class="mw-ce-panel d-none">
        <div class="row g-3">

            <!-- Left: Article editor -->
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
                        <span class="fw-semibold">Article Draft</span>
                        <div class="d-flex gap-2 align-items-center">
                            <span id="ceWordCountBadge" class="badge bg-light text-muted"></span>
                            <button class="btn btn-sm btn-outline-secondary" onclick="showStep(1)">← Edit Brief</button>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">H1 Title</label>
                            <input type="text" id="ceTitle" class="form-control form-control-lg fw-semibold">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Meta Description <span class="text-muted fw-normal small">(shown in Google results)</span></label>
                            <textarea id="ceMetaDesc" class="form-control" rows="2" maxlength="160"></textarea>
                            <div class="form-text"><span id="ceMetaCharCount">0</span>/160 chars</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Article Body</label>
                            <textarea id="ceBodyHtml" class="form-control font-monospace" rows="22" style="font-size:13px;"></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: Sidebar -->
            <div class="col-lg-4">

                <!-- Photo prompts -->
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white border-bottom py-3">
                        <span class="fw-semibold">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                            Photos to Shoot
                        </span>
                    </div>
                    <div class="card-body p-3">
                        <p class="text-muted small mb-2">Take these shots before publishing. Real photos are the biggest E-E-A-T signal.</p>
                        <ul id="cePhotoPrompts" class="list-unstyled mb-0 small"></ul>
                    </div>
                </div>

                <!-- Internal link suggestions -->
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white border-bottom py-3">
                        <span class="fw-semibold">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                            Suggested Internal Links
                        </span>
                    </div>
                    <div class="card-body p-3">
                        <div id="ceSuggestedLinks" class="small"></div>
                    </div>
                </div>

                <!-- Schema preview -->
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between">
                        <span class="fw-semibold">JSON-LD Schema</span>
                        <button class="btn btn-sm btn-link p-0 text-muted" onclick="toggleSchema()">show/hide</button>
                    </div>
                    <div id="ceSchemaPanel" class="card-body p-3 d-none">
                        <textarea id="ceSchemaJson" class="form-control font-monospace" rows="8" style="font-size:11px;"></textarea>
                    </div>
                </div>

                <!-- Publish button -->
                <?php if ($canEdit): ?>
                <div class="card border-0 shadow-sm border border-success" style="border-width:2px!important;">
                    <div class="card-body p-3">
                        <h6 class="mb-2">Ready to publish?</h6>
                        <p class="text-muted small mb-3">Add photos, review the copy, then publish and amplify.</p>
                        <button class="btn btn-success w-100" onclick="showStep(3)">
                            Publish &amp; Amplify →
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── STEP 3: Amplify ───────────────────────────────────────────── -->
    <div id="ceStep3" class="mw-ce-panel d-none">
        <div class="row g-3">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom py-3">
                        <span class="fw-semibold">Choose Channels</span>
                    </div>
                    <div class="card-body p-4">
                        <p class="text-muted small mb-4">Select which channels to generate drafts for. Nothing publishes automatically — you'll review each draft before it goes live.</p>

                        <div class="mw-cascade-channel" id="chSocial">
                            <div class="form-check d-flex align-items-start gap-3">
                                <input class="form-check-input mt-1 flex-shrink-0" type="checkbox" id="chkSocial" value="social" checked>
                                <label class="form-check-label" for="chkSocial">
                                    <span class="fw-semibold d-block">Social Posts</span>
                                    <span class="text-muted small">Facebook · Instagram · Google Business Profile — adapted for each platform's tone and format</span>
                                </label>
                            </div>
                        </div>

                        <div class="mw-cascade-channel" id="chEmail">
                            <div class="form-check d-flex align-items-start gap-3">
                                <input class="form-check-input mt-1 flex-shrink-0" type="checkbox" id="chkEmail" value="email" checked>
                                <label class="form-check-label" for="chkEmail">
                                    <span class="fw-semibold d-block">Email Campaign</span>
                                    <span class="text-muted small">3 subject line variants · article intro preview · "Read full article" CTA — saved as draft for your review</span>
                                </label>
                            </div>
                        </div>

                        <div class="mw-cascade-channel" id="chPdfFooter">
                            <div class="form-check d-flex align-items-start gap-3">
                                <input class="form-check-input mt-1 flex-shrink-0" type="checkbox" id="chkPdfFooter" value="pdf_footer" checked>
                                <label class="form-check-label" for="chkPdfFooter">
                                    <span class="fw-semibold d-block">Invoice &amp; Quote Footer</span>
                                    <span class="text-muted small">"From the blog: [title]" added to every invoice and quote PDF — replaces the previous article</span>
                                </label>
                            </div>
                        </div>

                        <div class="mw-cascade-channel" id="chEmailSig">
                            <div class="form-check d-flex align-items-start gap-3">
                                <input class="form-check-input mt-1 flex-shrink-0" type="checkbox" id="chkEmailSig" value="email_signature" checked>
                                <label class="form-check-label" for="chkEmailSig">
                                    <span class="fw-semibold d-block">Email Signature</span>
                                    <span class="text-muted small">"Latest from the blog: [title] →" appended to every outgoing email — replaces the previous article</span>
                                </label>
                            </div>
                        </div>

                        <div class="mt-4 d-flex gap-2 align-items-center">
                            <button class="btn btn-outline-secondary" onclick="showStep(2)">← Back</button>
                            <?php if ($canEdit): ?>
                            <button class="btn btn-success px-4" id="ceBtnCascade" onclick="runCascade()">
                                Publish &amp; Amplify
                            </button>
                            <?php endif; ?>
                            <div id="ceCascadeSpinner" class="d-none">
                                <div class="spinner-border spinner-border-sm text-success me-2" role="status"></div>
                                <span class="text-muted small">Generating drafts&hellip;</span>
                            </div>
                        </div>
                        <div id="ceCascadeError" class="alert alert-danger mt-3 d-none"></div>
                    </div>
                </div>
            </div>

            <!-- Results panel -->
            <div class="col-lg-5">
                <div id="ceCascadeResults" class="d-none">
                    <div class="card border-0 shadow-sm border-success" style="border-width:2px!important;">
                        <div class="card-header bg-success text-white py-3">
                            <span class="fw-semibold">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><polyline points="20 6 9 17 4 12"/></svg>
                                Cascade complete
                            </span>
                        </div>
                        <div class="card-body p-3" id="ceCascadeResultsBody"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div><!-- /ceWizard -->

<script>
// ── State ────────────────────────────────────────────────────────────────
const ceState = {
    title: '',
    metaDesc: '',
    bodyHtml: '',
    schemaJson: '',
    faqItems: [],
    suggestedLinks: [],
    photoPrompts: [],
    wordCount: 0,
    articleUrl: '',  // set after CMS publish (future)
};

// ── Step navigation ──────────────────────────────────────────────────────
function showStep(n) {
    [1, 2, 3].forEach(i => {
        document.getElementById('ceStep' + i).classList.toggle('d-none', i !== n);
        const stepEl = document.querySelector('.mw-ce-step[data-step="' + i + '"]');
        if (stepEl) stepEl.classList.toggle('active', i <= n);
    });
}

// ── Step 1: Generate article ─────────────────────────────────────────────
async function generateArticle() {
    const keyword     = document.getElementById('ceKeyword').value.trim();
    const city        = document.getElementById('ceCity').value.trim();
    const serviceType = document.getElementById('ceServiceType').value.trim();

    if (!keyword || !city || !serviceType) {
        showError('ceGenerateError', 'Please fill in search query, city, and service type.');
        return;
    }

    const btn     = document.getElementById('ceBtnGenerate');
    const spinner = document.getElementById('ceGenerateSpinner');
    btn.disabled  = true;
    spinner.classList.remove('d-none');
    hideError('ceGenerateError');

    try {
        const res = await fetch('/crm/api/generate-article.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                keyword,
                city,
                service_type:    serviceType,
                season:          document.getElementById('ceSeason').value,
                project_details: document.getElementById('ceProjectDetails').value.trim(),
                word_count:      parseInt(document.getElementById('ceWordCount').value),
            }),
        });

        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Generation failed');

        // Populate state
        ceState.title         = data.title || '';
        ceState.metaDesc      = data.meta_description || '';
        ceState.bodyHtml      = data.body_html || '';
        ceState.schemaJson    = data.schema_json || '';
        ceState.faqItems      = data.faq_items || [];
        ceState.suggestedLinks = data.suggested_links || [];
        ceState.photoPrompts  = data.photo_prompts || [];
        ceState.wordCount     = data.word_count || 0;

        // Populate Step 2 fields
        document.getElementById('ceTitle').value     = ceState.title;
        document.getElementById('ceMetaDesc').value  = ceState.metaDesc;
        document.getElementById('ceBodyHtml').value  = ceState.bodyHtml;
        document.getElementById('ceSchemaJson').value = ceState.schemaJson;

        updateMetaCharCount();

        document.getElementById('ceWordCountBadge').textContent =
            ceState.wordCount.toLocaleString() + ' words';

        renderPhotoPrompts(ceState.photoPrompts);
        renderSuggestedLinks(ceState.suggestedLinks);

        showStep(2);
    } catch (err) {
        showError('ceGenerateError', err.message);
    } finally {
        btn.disabled = false;
        spinner.classList.add('d-none');
    }
}

// ── Step 3: Run cascade ──────────────────────────────────────────────────
async function runCascade() {
    // Capture any edits made in Step 2
    ceState.title    = document.getElementById('ceTitle').value.trim();
    ceState.metaDesc = document.getElementById('ceMetaDesc').value.trim();
    ceState.bodyHtml = document.getElementById('ceBodyHtml').value;

    const channels = Array.from(
        document.querySelectorAll('.mw-cascade-channel input[type=checkbox]:checked')
    ).map(el => el.value);

    if (!channels.length) {
        showError('ceCascadeError', 'Select at least one channel.');
        return;
    }

    const btn     = document.getElementById('ceBtnCascade');
    const spinner = document.getElementById('ceCascadeSpinner');
    btn.disabled  = true;
    spinner.classList.remove('d-none');
    hideError('ceCascadeError');

    // Note: article_id is 0 here — in the future this will be the CMS page ID
    // created when the article is published to the CMS. For now the cascade
    // runs from the in-memory draft.
    try {
        const res = await fetch('/crm/api/cascade.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                article_id:       0,
                title:            ceState.title,
                url:              ceState.articleUrl || 'https://mowology.ca/blog/',
                body_html:        ceState.bodyHtml,
                meta_description: ceState.metaDesc,
                channels,
            }),
        });

        const data = await res.json();
        if (!data.success && !data.social_posts) throw new Error(data.error || 'Cascade failed');

        renderCascadeResults(data);
    } catch (err) {
        showError('ceCascadeError', err.message);
    } finally {
        btn.disabled = false;
        spinner.classList.add('d-none');
    }
}

// ── Render helpers ───────────────────────────────────────────────────────
function renderPhotoPrompts(prompts) {
    const ul = document.getElementById('cePhotoPrompts');
    ul.innerHTML = prompts.length
        ? prompts.map(p => `<li class="mb-2 d-flex gap-2">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="flex-shrink-0 mt-1"><circle cx="12" cy="12" r="3"/><path d="M20.2 20.2c2.04-2.03.02-7.36-4.5-11.9-4.5-4.5-9.9-6.54-11.9-4.5-2.04 2.03-.02 7.36 4.5 11.9 4.5 4.5 9.9 6.54 11.9 4.5z"/></svg>
            ${escHtml(p)}</li>`).join('')
        : '<li class="text-muted">No prompts generated.</li>';
}

function renderSuggestedLinks(links) {
    const el = document.getElementById('ceSuggestedLinks');
    if (!links.length) {
        el.innerHTML = '<p class="text-muted">No suggestions.</p>';
        return;
    }
    el.innerHTML = links.map(l => `
        <div class="mb-2 p-2 bg-light rounded small">
            <code>${escHtml(l.url || '')}</code><br>
            <span class="text-muted">${escHtml(l.context || '')}</span>
        </div>`).join('');
}

function renderCascadeResults(data) {
    const body = document.getElementById('ceCascadeResultsBody');
    const rows = [];

    if (data.social_posts && data.social_posts.length) {
        const platforms = data.social_posts.map(p => p.platform).join(', ');
        rows.push(resultRow('✓', 'Social drafts', `${data.social_posts.length} posts created (${platforms})`, 'social-calendar.php'));
    }

    if (data.email_campaign) {
        rows.push(resultRow('✓', 'Email campaign', `Draft saved — subject: "${escHtml(data.email_campaign.subject)}"`, 'campaigns.php'));
    }

    if (data.pdf_promo && data.pdf_promo.updated) {
        rows.push(resultRow('✓', 'PDF footer', 'Updated on all future invoices & quotes', null));
    }

    if (data.email_signature && data.email_signature.updated) {
        rows.push(resultRow('✓', 'Email signature', 'Updated for all outgoing emails', null));
    }

    if (data.errors && data.errors.length) {
        data.errors.forEach(e => rows.push(resultRow('!', 'Error', escHtml(e), null, true)));
    }

    body.innerHTML = rows.join('');
    document.getElementById('ceCascadeResults').classList.remove('d-none');
}

function resultRow(icon, label, detail, link, isError = false) {
    const color = isError ? 'text-danger' : 'text-success';
    const linkHtml = link
        ? ` — <a href="${escHtml(link)}" class="text-decoration-none">Review →</a>`
        : '';
    return `<div class="d-flex gap-2 mb-2 small">
        <span class="${color} fw-bold flex-shrink-0">${icon}</span>
        <span><strong>${escHtml(label)}</strong> ${detail}${linkHtml}</span>
    </div>`;
}

function updateMetaCharCount() {
    const ta = document.getElementById('ceMetaDesc');
    document.getElementById('ceMetaCharCount').textContent = ta.value.length;
}

function toggleSchema() {
    document.getElementById('ceSchemaPanel').classList.toggle('d-none');
}

// ── Utilities ────────────────────────────────────────────────────────────
function showError(id, msg) {
    const el = document.getElementById(id);
    el.textContent = msg;
    el.classList.remove('d-none');
}
function hideError(id) { document.getElementById(id).classList.add('d-none'); }

function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

document.getElementById('ceMetaDesc').addEventListener('input', updateMetaCharCount);
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
