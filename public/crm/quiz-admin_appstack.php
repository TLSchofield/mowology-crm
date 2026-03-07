<?php
/**
 * /public/crm/quiz-admin_appstack.php
 * Knowledge Quiz Admin — manage categories, questions, leaderboard & prizes.
 * Admin only.
 */
declare(strict_types=1);
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

if (($user['role'] ?? '') !== 'admin') {
    header('Location: /crm/quiz_appstack.php');
    exit;
}

$activeTab  = in_array($_GET['tab'] ?? '', ['categories','questions','leaderboard','campaigns']) ? $_GET['tab'] : 'questions';
$csrfToken  = function_exists('generateCSRFToken') ? generateCSRFToken() : '';
$pageTitle  = 'Quiz Admin';
$activePage = 'quiz';
?>
<?php include __DIR__ . '/includes/appstack_head.php'; ?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0 fw-bold">Quiz Admin</h1>
        <p class="text-muted mb-0 small">Manage questions, categories, and monthly prizes</p>
    </div>
    <a href="/crm/quiz_appstack.php" class="btn btn-sm btn-outline-secondary">
        <i data-feather="arrow-left" style="width:14px;height:14px;"></i> Back to Quiz Hub
    </a>
</div>

<!-- ── Tabs ─────────────────────────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-4" id="adminTabs">
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'questions'   ? 'active' : ''; ?>" href="?tab=questions">Questions</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'categories'  ? 'active' : ''; ?>" href="?tab=categories">Categories</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'leaderboard' ? 'active' : ''; ?>" href="?tab=leaderboard">Leaderboard &amp; Prizes</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'campaigns' ? 'active' : ''; ?>" href="?tab=campaigns">🌱 Seasonal Campaigns</a>
    </li>
</ul>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: QUESTIONS
═══════════════════════════════════════════════════════════════════════════ -->
<div id="tabQuestions" <?php echo $activeTab !== 'questions' ? 'style="display:none"' : ''; ?>>

    <div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
        <div class="d-flex gap-2 align-items-center">
            <label class="text-muted small mb-0">Category:</label>
            <select id="qFilterCat" class="form-select form-select-sm" style="width:200px;" onchange="loadQuestions()">
                <option value="">All Categories</option>
            </select>
        </div>
        <button class="btn mw-btn-green btn-sm" onclick="openQuestionModal(null)">
            <i data-feather="plus" style="width:14px;height:14px;"></i> Add Question
        </button>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div id="questionsList">
                <div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Loading…</div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: CATEGORIES
═══════════════════════════════════════════════════════════════════════════ -->
<div id="tabCategories" <?php echo $activeTab !== 'categories' ? 'style="display:none"' : ''; ?>>

    <div class="d-flex justify-content-end mb-3">
        <button class="btn mw-btn-green btn-sm" onclick="openCategoryModal(null)">
            <i data-feather="plus" style="width:14px;height:14px;"></i> Add Category
        </button>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div id="categoriesList">
                <div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Loading…</div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: LEADERBOARD & PRIZES
═══════════════════════════════════════════════════════════════════════════ -->
<div id="tabLeaderboard" <?php echo $activeTab !== 'leaderboard' ? 'style="display:none"' : ''; ?>>

    <div class="row g-4">
        <!-- Monthly Leaderboard -->
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center justify-content-between gap-2">
                    <h5 class="card-title mb-0">Monthly Standings</h5>
                    <div class="d-flex gap-2 align-items-center">
                        <input type="month" id="monthPicker" class="form-control form-control-sm" style="width:155px;"
                               value="<?php echo date('Y-m'); ?>" onchange="loadLeaderboard()">
                    </div>
                </div>
                <div class="card-body p-0">
                    <div id="adminLeaderboard">
                        <div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Loading…</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Award Prize -->
        <div class="col-lg-5">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Award Monthly Prize</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Winner</label>
                        <select id="prizeWinner" class="form-select form-select-sm">
                            <option value="">Select from leaderboard…</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Month</label>
                        <input type="month" id="prizeMonth" class="form-control form-control-sm" value="<?php echo date('Y-m'); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Prize Description</label>
                        <input type="text" id="prizeDesc" class="form-control form-control-sm" placeholder="e.g. $50 cash">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Notes (optional)</label>
                        <input type="text" id="prizeNotes" class="form-control form-control-sm" placeholder="Optional notes">
                    </div>
                    <button class="btn mw-btn-green w-100" onclick="awardPrize()">
                        <i data-feather="award" style="width:16px;height:16px;"></i> Record Prize
                    </button>
                </div>
            </div>

            <!-- Prize History -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Prize History</h5>
                </div>
                <div class="card-body p-0">
                    <div id="prizeHistory">
                        <div class="text-center py-3 text-muted small"><div class="spinner-border spinner-border-sm me-2"></div>Loading…</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: SEASONAL CAMPAIGNS
═══════════════════════════════════════════════════════════════════════════ -->
<div id="tabCampaigns" <?php echo $activeTab !== 'campaigns' ? 'style="display:none"' : ''; ?>>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <p class="text-muted small mb-0">Seasonal campaigns control tip text and priority boosts during specific months.</p>
        <button class="btn mw-btn-green btn-sm" onclick="openCampaignModal(null)">
            <i data-feather="plus" style="width:14px;height:14px;"></i> Add Campaign
        </button>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div id="campaignsList">
                <div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Loading…</div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     MODAL: Add / Edit Seasonal Campaign
═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="campaignModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="campaignModalTitle">Add Seasonal Campaign</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="campId">
                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="form-label small fw-bold">Campaign Name <span class="text-danger">*</span></label>
                        <input type="text" id="campName" class="form-control form-control-sm" placeholder="e.g. Spring Growth Push">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small fw-bold">Season Label <span class="text-danger">*</span></label>
                        <input type="text" id="campLabel" class="form-control form-control-sm" placeholder="e.g. spring, fall, winter">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Description</label>
                        <input type="text" id="campDesc" class="form-control form-control-sm" placeholder="Short description of what this campaign covers">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Start Month</label>
                        <select id="campStart" class="form-select form-select-sm">
                            <?php for($m=1;$m<=12;$m++): ?>
                            <option value="<?php echo $m; ?>"><?php echo date('F', mktime(0,0,0,$m,1)); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">End Month</label>
                        <select id="campEnd" class="form-select form-select-sm">
                            <?php for($m=1;$m<=12;$m++): ?>
                            <option value="<?php echo $m; ?>"><?php echo date('F', mktime(0,0,0,$m,1)); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold d-flex justify-content-between">
                            Priority Boost
                            <span class="text-muted fw-normal" id="campBoostVal">3</span>
                        </label>
                        <input type="range" id="campBoost" class="form-range" min="1" max="10" step="1" value="3"
                               oninput="document.getElementById('campBoostVal').textContent = this.value">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Sort Order</label>
                        <input type="number" id="campSort" class="form-control form-control-sm" value="5" min="1" max="99">
                    </div>
                    <div class="col-md-9">
                        <label class="form-label small fw-bold">Focus Tags <span class="text-muted fw-normal">(comma-separated)</span></label>
                        <input type="text" id="campTags" class="form-control form-control-sm"
                               placeholder="e.g. spring-lawn,pre-emergent,chafer-prevention">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Status</label>
                        <select id="campActive" class="form-select form-select-sm">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Tip Text <span class="text-muted fw-normal">(shown on quiz hub seasonal tip card)</span></label>
                        <textarea id="campTip" class="form-control form-control-sm" rows="2"
                                  placeholder="e.g. Check lawns for moss. This is the window for pre-emergent crabgrass control."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn mw-btn-green" onclick="saveCampaign()">Save Campaign</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     MODAL: Add / Edit Question
═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="questionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="questionModalTitle">Add Question</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="qmId">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label small fw-bold">Category <span class="text-danger">*</span></label>
                        <select id="qmCategory" class="form-select form-select-sm">
                            <option value="">Select category…</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Difficulty</label>
                        <select id="qmDifficulty" class="form-select form-select-sm">
                            <option value="easy">Easy</option>
                            <option value="medium" selected>Medium</option>
                            <option value="hard">Hard</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Question Type</label>
                        <select id="qmType" class="form-select form-select-sm">
                            <option value="multiple_choice" selected>Multiple Choice</option>
                            <option value="photo_id">Photo ID</option>
                            <option value="scenario">Scenario</option>
                            <option value="sequence">Sequence (Next Step)</option>
                            <option value="reverse_recall">Reverse Recall (Cause from Symptom)</option>
                            <option value="customer_explanation">Customer Explanation</option>
                            <option value="quality_judgement">Quality Judgement</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Learning Level</label>
                        <select id="qmLevel" class="form-select form-select-sm">
                            <option value="1" selected>1 — Recognition</option>
                            <option value="2">2 — Diagnosis</option>
                            <option value="3">3 — Treatment</option>
                            <option value="4">4 — Timing</option>
                            <option value="5">5 — Customer Explanation</option>
                            <option value="6">6 — Field Decision</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Question Text <span class="text-danger">*</span></label>
                        <textarea id="qmText" class="form-control form-control-sm" rows="2"
                                  placeholder="e.g. What is the name of this weed?"></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Learn Notes <span class="text-muted fw-normal">(shown on flashcard back)</span></label>
                        <textarea id="qmLearnNotes" class="form-control form-control-sm" rows="2"
                                  placeholder="e.g. Scientific name: Taraxacum officinale. Key ID: hollow stem with milky sap, deep taproot, jagged basal rosette leaves."></textarea>
                    </div>
                    <!-- Seasonal fields -->
                    <div class="col-12">
                        <label class="form-label small fw-bold">Active Months <span class="text-muted fw-normal">(when this question is most relevant)</span></label>
                        <div class="mw-month-grid" id="qmMonthGrid">
                            <?php
                            $months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                            foreach ($months as $i => $m): $n = $i + 1; ?>
                            <label class="mw-month-cb">
                                <input type="checkbox" name="qmMonths" value="<?php echo $n; ?>" checked>
                                <?php echo $m; ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label small fw-bold">Seasonal Tags <span class="text-muted fw-normal">(comma-separated)</span></label>
                        <input type="text" id="qmSeasonalTags" class="form-control form-control-sm"
                               placeholder="e.g. pre-emergent,spring-lawn,crabgrass-prevention">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold d-flex justify-content-between">
                            Seasonal Priority
                            <span class="text-muted fw-normal" id="qmPriorityVal">5</span>
                        </label>
                        <input type="range" id="qmSeasonalPriority" class="form-range" min="1" max="10" step="1" value="5"
                               oninput="document.getElementById('qmPriorityVal').textContent = this.value">
                        <div class="d-flex justify-content-between" style="font-size:10px;color:#999;margin-top:2px;">
                            <span>Low</span><span>High</span>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Question Image (optional)</label>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <input type="file" id="qmImageFile" class="form-control form-control-sm" accept="image/*" style="max-width:280px;" onchange="uploadImage()">
                            <span class="text-muted small">or paste URL:</span>
                            <input type="text" id="qmImagePath" class="form-control form-control-sm" placeholder="/uploads/quiz/..." style="max-width:280px;" oninput="previewImage()">
                        </div>
                        <div id="qmImagePreview" class="mt-2"></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Answer Options <span class="text-danger">*</span></label>
                        <p class="text-muted small mb-2">Enter 4 options. Mark exactly one as correct.</p>
                        <div id="qmOptions">
                            <!-- Rendered by JS -->
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="addOptionRow()">
                            <i data-feather="plus" style="width:14px;height:14px;"></i> Add Option
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn mw-btn-green" onclick="saveQuestion()">Save Question</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     MODAL: Add / Edit Category
═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="categoryModalTitle">Add Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="cmId">
                <div class="mb-3">
                    <label class="form-label small fw-bold">Name <span class="text-danger">*</span></label>
                    <input type="text" id="cmName" class="form-control form-control-sm" placeholder="e.g. Weed Identification">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Description</label>
                    <input type="text" id="cmDesc" class="form-control form-control-sm" placeholder="Short description">
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label small fw-bold">Feather Icon</label>
                        <input type="text" id="cmIcon" class="form-control form-control-sm" placeholder="alert-triangle">
                        <div class="form-text"><a href="https://feathericons.com" target="_blank">Browse icons</a></div>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-bold">Colour</label>
                        <input type="color" id="cmColour" class="form-control form-control-color" value="#2D8659">
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-6">
                        <label class="form-label small fw-bold">Sort Order</label>
                        <input type="number" id="cmOrder" class="form-control form-control-sm" value="0" min="0">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-bold">Status</label>
                        <select id="cmActive" class="form-select form-select-sm">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn mw-btn-green" onclick="saveCategory()">Save Category</button>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF = <?php echo json_encode($csrfToken); ?>;
let allCategories = [];

// ══ Questions ═══════════════════════════════════════════════════════════════

async function loadCategories() {
    const r = await fetch('/crm/api/quiz.php?action=categories');
    const d = await r.json();
    if (!d.success) return;
    allCategories = d.categories;

    // Populate filter select
    const filter = document.getElementById('qFilterCat');
    if (filter) {
        filter.innerHTML = '<option value="">All Categories</option>' +
            d.categories.map(c => `<option value="${c.id}">${escHtml(c.name)}</option>`).join('');
    }

    // Populate modal select
    const sel = document.getElementById('qmCategory');
    if (sel) {
        sel.innerHTML = '<option value="">Select category…</option>' +
            d.categories.map(c => `<option value="${c.id}">${escHtml(c.name)}</option>`).join('');
    }

    // Populate categories list
    loadCategoriesList(d.categories);
}

async function loadQuestions() {
    const catId = document.getElementById('qFilterCat')?.value || '';
    const url   = '/crm/api/quiz.php?action=questions' + (catId ? `&category_id=${catId}` : '');
    const r     = await fetch(url);
    const d     = await r.json();
    if (!d.success) return;

    const list = document.getElementById('questionsList');
    if (!d.questions.length) {
        list.innerHTML = '<div class="text-center py-5 text-muted">No questions yet. Click "Add Question" to get started.</div>';
        return;
    }

    const rows = d.questions.map(q => `
        <tr>
            <td class="ps-3 py-2">
                ${q.image_path ? `<img src="${escHtml(q.image_path)}" class="mw-quiz-admin-thumb me-2" alt="">` : ''}
                <span>${escHtml(q.question_text)}</span>
            </td>
            <td class="py-2">
                <span class="badge" style="background:${escHtml(q.category_colour)}">${escHtml(q.category_name)}</span>
                ${q.question_type && q.question_type !== 'multiple_choice' ? `<span class="badge bg-info text-dark ms-1">${escHtml(q.question_type.replace(/_/g,' '))}</span>` : ''}
            </td>
            <td class="py-2 text-muted small">
                ${q.difficulty}
                ${q.learning_level > 1 ? `<span class="text-primary ms-1">L${q.learning_level}</span>` : ''}
            </td>
            <td class="py-2 text-center">
                ${parseInt(q.is_active) ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Off</span>'}
            </td>
            <td class="py-2 text-end pe-3">
                <button class="btn btn-xs btn-outline-secondary me-1" onclick="openQuestionModal(${q.id})">Edit</button>
                <button class="btn btn-xs btn-outline-danger" onclick="deactivateQuestion(${q.id})">Remove</button>
            </td>
        </tr>
    `);

    list.innerHTML = `<table class="table table-hover mb-0">
        <thead class="table-light">
            <tr>
                <th class="ps-3">Question</th>
                <th>Category</th>
                <th>Difficulty</th>
                <th class="text-center">Status</th>
                <th class="pe-3"></th>
            </tr>
        </thead>
        <tbody>${rows.join('')}</tbody>
    </table>`;
}

function setMonthCheckboxes(monthsStr) {
    // monthsStr: "3,4,9,10" or "1,2,3,4,5,6,7,8,9,10,11,12" or ""
    const active = monthsStr ? monthsStr.split(',').map(s => s.trim()) : [];
    const allMonths = !monthsStr || monthsStr === '1,2,3,4,5,6,7,8,9,10,11,12';
    document.querySelectorAll('#qmMonthGrid input[type="checkbox"]').forEach(cb => {
        cb.checked = allMonths || active.includes(cb.value);
    });
}

function getMonthsFromCheckboxes() {
    const checked = [];
    document.querySelectorAll('#qmMonthGrid input[type="checkbox"]:checked').forEach(cb => checked.push(cb.value));
    if (checked.length === 12) return '1,2,3,4,5,6,7,8,9,10,11,12';
    return checked.join(',') || '1,2,3,4,5,6,7,8,9,10,11,12';
}

function openQuestionModal(id) {
    document.getElementById('qmId').value          = id || '';
    document.getElementById('qmText').value        = '';
    document.getElementById('qmLearnNotes').value  = '';
    document.getElementById('qmImagePath').value   = '';
    document.getElementById('qmImagePreview').innerHTML = '';
    document.getElementById('qmDifficulty').value  = 'medium';
    document.getElementById('qmType').value        = 'multiple_choice';
    document.getElementById('qmLevel').value       = '1';
    document.getElementById('qmCategory').value    = '';
    document.getElementById('qmSeasonalTags').value    = '';
    document.getElementById('qmSeasonalPriority').value = '5';
    document.getElementById('qmPriorityVal').textContent = '5';
    setMonthCheckboxes('');  // defaults to all checked
    document.getElementById('questionModalTitle').textContent = id ? 'Edit Question' : 'Add Question';

    // Default 4 option rows
    const optWrap = document.getElementById('qmOptions');
    optWrap.innerHTML = '';
    for (let i = 0; i < 4; i++) addOptionRow();

    if (id) {
        // Load existing question
        fetch(`/crm/api/quiz.php?action=get_question&id=${id}`)
            .then(r => r.json())
            .then(d => {
                if (!d.success) return;
                const q = d.question;
                document.getElementById('qmText').value       = q.question_text;
                document.getElementById('qmLearnNotes').value = q.learn_notes || '';
                document.getElementById('qmCategory').value   = q.category_id;
                document.getElementById('qmDifficulty').value = q.difficulty;
                document.getElementById('qmType').value       = q.question_type || 'multiple_choice';
                document.getElementById('qmLevel').value      = q.learning_level || '1';
                document.getElementById('qmImagePath').value  = q.image_path || '';
                document.getElementById('qmSeasonalTags').value = q.seasonal_tags || '';
                const pri = q.seasonal_priority || 5;
                document.getElementById('qmSeasonalPriority').value = pri;
                document.getElementById('qmPriorityVal').textContent = pri;
                setMonthCheckboxes(q.relevant_months || '');
                if (q.image_path) previewImage();

                // Re-render options
                optWrap.innerHTML = '';
                q.options.forEach((o, i) => addOptionRow(o.option_text, o.is_correct));
            });
    }

    $('#questionModal').modal('show');
}

function addOptionRow(text = '', isCorrect = false) {
    const wrap = document.getElementById('qmOptions');
    const idx  = wrap.children.length;
    const div  = document.createElement('div');
    div.className = 'mw-quiz-option-row';
    div.innerHTML = `
        <input type="radio" name="correctOpt" value="${idx}" class="mw-quiz-opt-radio" ${isCorrect ? 'checked' : ''}>
        <input type="text" class="form-control form-control-sm mw-quiz-opt-input" placeholder="Option ${idx + 1}" value="${escHtml(text)}">
        <button type="button" class="btn btn-xs btn-link text-danger p-0" onclick="this.parentElement.remove()" title="Remove">
            <i data-feather="x" style="width:14px;height:14px;"></i>
        </button>
    `;
    wrap.appendChild(div);
    if (typeof feather !== 'undefined') feather.replace();
}

async function uploadImage() {
    const file = document.getElementById('qmImageFile').files[0];
    if (!file) return;

    const formData = new FormData();
    formData.append('image', file);

    const r = await fetch('/crm/api/quiz.php?action=upload_image', {
        method: 'POST',
        body: formData,
    });
    const d = await r.json();
    if (d.success) {
        document.getElementById('qmImagePath').value = d.image_path;
        previewImage();
    } else {
        alert(d.error || 'Upload failed');
    }
}

function previewImage() {
    const path    = document.getElementById('qmImagePath').value.trim();
    const preview = document.getElementById('qmImagePreview');
    preview.innerHTML = path
        ? `<img src="${escHtml(path)}" class="mw-quiz-admin-preview" alt="Preview">`
        : '';
}

async function saveQuestion() {
    const id   = document.getElementById('qmId').value;
    const opts = [];
    let hasCorrect = false;

    document.querySelectorAll('#qmOptions .mw-quiz-option-row').forEach((row, i) => {
        const text      = row.querySelector('.mw-quiz-opt-input').value.trim();
        const isCorrect = row.querySelector('.mw-quiz-opt-radio').checked;
        if (isCorrect) hasCorrect = true;
        if (text) opts.push({ option_text: text, is_correct: isCorrect ? 1 : 0 });
    });

    if (opts.length < 2) { alert('Add at least 2 options'); return; }
    if (!hasCorrect)      { alert('Mark one option as correct'); return; }

    const payload = {
        csrf_token:        CSRF,
        category_id:      document.getElementById('qmCategory').value,
        question_text:     document.getElementById('qmText').value.trim(),
        learn_notes:      document.getElementById('qmLearnNotes').value.trim() || null,
        image_path:       document.getElementById('qmImagePath').value.trim() || null,
        difficulty:       document.getElementById('qmDifficulty').value,
        question_type:    document.getElementById('qmType').value,
        learning_level:   parseInt(document.getElementById('qmLevel').value) || 1,
        relevant_months:  getMonthsFromCheckboxes(),
        seasonal_tags:    document.getElementById('qmSeasonalTags').value.trim() || null,
        seasonal_priority: parseInt(document.getElementById('qmSeasonalPriority').value) || 5,
        options:          opts,
    };
    if (id) payload.id = parseInt(id);

    const r = await fetch('/crm/api/quiz.php?action=save_question', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    const d = await r.json();
    if (d.success) {
        $('#questionModal').modal('hide');
        loadQuestions();
    } else {
        alert(d.error || 'Save failed');
    }
}

async function deactivateQuestion(id) {
    if (!confirm('Remove this question from the quiz pool?')) return;
    const r = await fetch('/crm/api/quiz.php?action=delete_question', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, csrf_token: CSRF }),
    });
    const d = await r.json();
    if (d.success) loadQuestions();
    else alert(d.error || 'Failed');
}

// ══ Categories ═══════════════════════════════════════════════════════════════

function loadCategoriesList(categories) {
    const list = document.getElementById('categoriesList');
    if (!list) return;
    if (!categories.length) {
        list.innerHTML = '<div class="text-center py-5 text-muted">No categories.</div>';
        return;
    }
    const rows = categories.map(c => `
        <tr>
            <td class="ps-3 py-2">
                <i data-feather="${escHtml(c.icon)}" style="width:16px;height:16px;color:${escHtml(c.colour)};margin-right:8px;"></i>
                <strong>${escHtml(c.name)}</strong>
            </td>
            <td class="py-2 text-muted small">${escHtml(c.description || '')}</td>
            <td class="py-2 text-center">${parseInt(c.question_count)}</td>
            <td class="py-2">
                ${parseInt(c.is_active) ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'}
            </td>
            <td class="py-2 text-end pe-3">
                <button class="btn btn-xs btn-outline-secondary" onclick="openCategoryModal(${c.id})">Edit</button>
            </td>
        </tr>
    `);
    list.innerHTML = `<table class="table table-hover mb-0">
        <thead class="table-light">
            <tr><th class="ps-3">Category</th><th>Description</th><th class="text-center">Questions</th><th>Status</th><th class="pe-3"></th></tr>
        </thead>
        <tbody>${rows.join('')}</tbody>
    </table>`;
    if (typeof feather !== 'undefined') feather.replace();
}

function openCategoryModal(id) {
    document.getElementById('cmId').value     = id || '';
    document.getElementById('cmName').value   = '';
    document.getElementById('cmDesc').value   = '';
    document.getElementById('cmIcon').value   = 'help-circle';
    document.getElementById('cmColour').value = '#2D8659';
    document.getElementById('cmOrder').value  = '0';
    document.getElementById('cmActive').value = '1';
    document.getElementById('categoryModalTitle').textContent = id ? 'Edit Category' : 'Add Category';

    if (id) {
        const cat = allCategories.find(c => parseInt(c.id) === id);
        if (cat) {
            document.getElementById('cmName').value   = cat.name;
            document.getElementById('cmDesc').value   = cat.description || '';
            document.getElementById('cmIcon').value   = cat.icon;
            document.getElementById('cmColour').value = cat.colour;
            document.getElementById('cmOrder').value  = cat.sort_order;
            document.getElementById('cmActive').value = cat.is_active;
        }
    }

    $('#categoryModal').modal('show');
}

async function saveCategory() {
    const id = document.getElementById('cmId').value;
    const payload = {
        csrf_token:  CSRF,
        name:        document.getElementById('cmName').value.trim(),
        description: document.getElementById('cmDesc').value.trim(),
        icon:        document.getElementById('cmIcon').value.trim() || 'help-circle',
        colour:      document.getElementById('cmColour').value,
        sort_order:  parseInt(document.getElementById('cmOrder').value) || 0,
        is_active:   parseInt(document.getElementById('cmActive').value),
    };
    if (id) payload.id = parseInt(id);

    const r = await fetch('/crm/api/quiz.php?action=save_category', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    const d = await r.json();
    if (d.success) {
        $('#categoryModal').modal('hide');
        await loadCategories();
        loadQuestions();
    } else {
        alert(d.error || 'Save failed');
    }
}

// ══ Leaderboard & Prizes ═════════════════════════════════════════════════════

async function loadLeaderboard() {
    const month = document.getElementById('monthPicker')?.value || '';
    const url   = '/crm/api/quiz.php?action=leaderboard' + (month ? `&month=${month}` : '');
    const r     = await fetch(url);
    const d     = await r.json();
    if (!d.success) return;

    const lb = document.getElementById('adminLeaderboard');
    if (!d.leaderboard.length) {
        lb.innerHTML = '<div class="text-center py-5 text-muted small">No games played this month yet.</div>';
        // Clear winner dropdown
        document.getElementById('prizeWinner').innerHTML = '<option value="">No players yet</option>';
        return;
    }

    const medals = ['🥇','🥈','🥉'];
    const rows = d.leaderboard.map((p, i) => {
        const accuracy = p.total_questions > 0 ? Math.round((p.total_correct / p.total_questions) * 100) : 0;
        const prize    = p.prize_awarded ? ' 🏆' : '';
        return `<tr>
            <td class="ps-3 py-2 fw-bold">${medals[i] || '#'+(i+1)}</td>
            <td class="py-2">${escHtml(p.full_name)}${prize}</td>
            <td class="py-2 fw-bold text-success">${p.monthly_points}</td>
            <td class="py-2 text-muted small">${p.games_played}</td>
            <td class="py-2 text-muted small pe-3">${accuracy}%</td>
        </tr>`;
    });

    lb.innerHTML = `<table class="table table-hover table-sm mb-0">
        <thead class="table-light">
            <tr><th class="ps-3">#</th><th>Name</th><th>Points</th><th>Games</th><th class="pe-3">Accuracy</th></tr>
        </thead>
        <tbody>${rows.join('')}</tbody>
    </table>`;

    // Populate winner dropdown
    const sel = document.getElementById('prizeWinner');
    sel.innerHTML = '<option value="">Select winner…</option>' +
        d.leaderboard.map((p, i) => `<option value="${p.id}">${medals[i] || '#'+(i+1)} ${escHtml(p.full_name)} — ${p.monthly_points} pts</option>`).join('');
}

async function loadPrizeHistory() {
    const r = await fetch('/crm/api/quiz.php?action=prizes');
    const d = await r.json();
    if (!d.success) return;

    const hist = document.getElementById('prizeHistory');
    if (!d.prizes.length) {
        hist.innerHTML = '<div class="text-center py-3 text-muted small">No prizes recorded yet.</div>';
        return;
    }

    const rows = d.prizes.map(p => `
        <div class="mw-quiz-prize-row">
            <div class="fw-bold">${escHtml(p.winner_name)}</div>
            <div class="text-muted small">${escHtml(p.month_year)} — ${escHtml(p.prize_description)}</div>
        </div>
    `);
    hist.innerHTML = rows.join('');
}

async function awardPrize() {
    const winnerId  = document.getElementById('prizeWinner').value;
    const month     = document.getElementById('prizeMonth').value;
    const prize     = document.getElementById('prizeDesc').value.trim();
    const notes     = document.getElementById('prizeNotes').value.trim();

    if (!winnerId) { alert('Select a winner'); return; }
    if (!prize)    { alert('Enter prize description'); return; }

    const r = await fetch('/crm/api/quiz.php?action=award_prize', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ winner_user_id: parseInt(winnerId), month_year: month, prize_description: prize, notes, csrf_token: CSRF }),
    });
    const d = await r.json();
    if (d.success) {
        alert('Prize recorded!');
        document.getElementById('prizeDesc').value = '';
        document.getElementById('prizeNotes').value = '';
        loadLeaderboard();
        loadPrizeHistory();
    } else {
        alert(d.error || 'Failed');
    }
}

// ══ Seasonal Campaigns ════════════════════════════════════════════════════════

const MONTH_NAMES = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

async function loadCampaignsList() {
    const r = await fetch('/crm/api/quiz.php?action=list_campaigns');
    const d = await r.json();
    const list = document.getElementById('campaignsList');
    if (!list) return;
    if (!d.success || !d.campaigns.length) {
        list.innerHTML = '<div class="text-center py-5 text-muted">No campaigns yet. Click "Add Campaign" to create one.</div>';
        return;
    }
    const rows = d.campaigns.map(c => {
        const startName = MONTH_NAMES[c.start_month] || c.start_month;
        const endName   = MONTH_NAMES[c.end_month]   || c.end_month;
        const statusBadge = parseInt(c.is_active)
            ? '<span class="badge bg-success">Active</span>'
            : '<span class="badge bg-secondary">Inactive</span>';
        return `<tr>
            <td class="ps-3 py-2">
                <strong>${escHtml(c.name)}</strong>
                ${c.description ? `<div class="text-muted small">${escHtml(c.description)}</div>` : ''}
            </td>
            <td class="py-2">
                <span class="badge mw-season-campaign-badge">${escHtml(c.season_label)}</span>
            </td>
            <td class="py-2 text-muted small">${startName} → ${endName}</td>
            <td class="py-2 text-muted small">${parseInt(c.priority_boost)}×</td>
            <td class="py-2">${statusBadge}</td>
            <td class="py-2 text-end pe-3">
                <button class="btn btn-xs btn-outline-secondary me-1" onclick="openCampaignModal(${c.id})">Edit</button>
                <button class="btn btn-xs btn-outline-danger" onclick="deleteCampaign(${c.id})">Delete</button>
            </td>
        </tr>`;
    });
    list.innerHTML = `<table class="table table-hover mb-0">
        <thead class="table-light">
            <tr>
                <th class="ps-3">Campaign</th>
                <th>Season</th>
                <th>Months</th>
                <th>Boost</th>
                <th>Status</th>
                <th class="pe-3"></th>
            </tr>
        </thead>
        <tbody>${rows.join('')}</tbody>
    </table>`;
}

let allCampaignsCache = [];

function openCampaignModal(id) {
    document.getElementById('campId').value     = id || '';
    document.getElementById('campName').value   = '';
    document.getElementById('campLabel').value  = '';
    document.getElementById('campDesc').value   = '';
    document.getElementById('campTags').value   = '';
    document.getElementById('campTip').value    = '';
    document.getElementById('campBoost').value  = '3';
    document.getElementById('campBoostVal').textContent = '3';
    document.getElementById('campSort').value   = '5';
    document.getElementById('campActive').value = '1';
    document.getElementById('campStart').value  = '2';
    document.getElementById('campEnd').value    = '4';
    document.getElementById('campaignModalTitle').textContent = id ? 'Edit Campaign' : 'Add Campaign';

    if (id) {
        fetch('/crm/api/quiz.php?action=list_campaigns')
            .then(r => r.json())
            .then(d => {
                const c = d.campaigns?.find(x => parseInt(x.id) === id);
                if (!c) return;
                document.getElementById('campName').value   = c.name;
                document.getElementById('campLabel').value  = c.season_label;
                document.getElementById('campDesc').value   = c.description || '';
                document.getElementById('campTags').value   = c.focus_tags || '';
                document.getElementById('campTip').value    = c.tip_text || '';
                document.getElementById('campBoost').value  = c.priority_boost;
                document.getElementById('campBoostVal').textContent = c.priority_boost;
                document.getElementById('campSort').value   = c.sort_order;
                document.getElementById('campActive').value = c.is_active;
                document.getElementById('campStart').value  = c.start_month;
                document.getElementById('campEnd').value    = c.end_month;
            });
    }

    $('#campaignModal').modal('show');
}

async function saveCampaign() {
    const id = document.getElementById('campId').value;
    const payload = {
        csrf_token:     CSRF,
        name:           document.getElementById('campName').value.trim(),
        season_label:   document.getElementById('campLabel').value.trim(),
        description:    document.getElementById('campDesc').value.trim(),
        start_month:    parseInt(document.getElementById('campStart').value),
        end_month:      parseInt(document.getElementById('campEnd').value),
        priority_boost: parseInt(document.getElementById('campBoost').value),
        sort_order:     parseInt(document.getElementById('campSort').value) || 5,
        focus_tags:     document.getElementById('campTags').value.trim() || null,
        tip_text:       document.getElementById('campTip').value.trim() || null,
        is_active:      parseInt(document.getElementById('campActive').value),
    };
    if (id) payload.id = parseInt(id);

    const r = await fetch('/crm/api/quiz.php?action=save_campaign', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    const d = await r.json();
    if (d.success) {
        $('#campaignModal').modal('hide');
        loadCampaignsList();
    } else {
        alert(d.error || 'Save failed');
    }
}

async function deleteCampaign(id) {
    if (!confirm('Delete this campaign? This cannot be undone.')) return;
    const r = await fetch('/crm/api/quiz.php?action=delete_campaign', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, csrf_token: CSRF }),
    });
    const d = await r.json();
    if (d.success) loadCampaignsList();
    else alert(d.error || 'Failed');
}

function escHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Init ────────────────────────────────────────────────────────────────────
loadCategories().then(() => {
    <?php if ($activeTab === 'questions'): ?>
    loadQuestions();
    <?php elseif ($activeTab === 'leaderboard'): ?>
    loadLeaderboard();
    loadPrizeHistory();
    <?php elseif ($activeTab === 'campaigns'): ?>
    loadCampaignsList();
    <?php endif; ?>
});

// Pre-load leaderboard data for prize dropdown on that tab
<?php if ($activeTab !== 'leaderboard'): ?>
// Load leaderboard silently for prize winner dropdown in background
setTimeout(() => {
    if (document.getElementById('adminLeaderboard')) loadLeaderboard();
}, 500);
<?php endif; ?>

<?php if ($activeTab !== 'campaigns'): ?>
// Load campaigns silently for the campaigns tab when navigating to it
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/appstack_footer.php'; ?>
