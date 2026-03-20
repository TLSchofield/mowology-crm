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

$activeTab    = in_array($_GET['tab'] ?? '', ['categories','questions','leaderboard','campaigns','library']) ? $_GET['tab'] : 'questions';
$autoImport   = isset($_GET['action']) && $_GET['action'] === 'import';
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
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'library' ? 'active' : ''; ?>" href="?tab=library">🌿 Plant &amp; Weed Library</a>
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
     TAB: PLANT & WEED LIBRARY
═══════════════════════════════════════════════════════════════════════════ -->
<div id="tabLibrary" <?php echo $activeTab !== 'library' ? 'style="display:none"' : ''; ?>>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <p class="text-muted small mb-0">Reference library of plants and weeds. Use entries to quickly create quiz questions.</p>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-secondary btn-sm" onclick="openPlantImportModal()" title="Upload a PictureThis card to auto-extract plant data">
                <i data-feather="camera" style="width:14px;height:14px;"></i> Import from Photo
            </button>
            <button class="btn mw-btn-green btn-sm" onclick="openLibraryModal(null)">
                <i data-feather="plus" style="width:14px;height:14px;"></i> Add Entry
            </button>
        </div>
    </div>

    <!-- Search + filter -->
    <div class="card mb-3">
        <div class="card-body py-2 px-3">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div class="flex-grow-1" style="max-width:340px;">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i data-feather="search" style="width:13px;height:13px;"></i></span>
                        <input type="text" id="libSearch" class="form-control" placeholder="Search by name, latin name, or tags…"
                               oninput="filterLibraryDisplay()">
                    </div>
                </div>
                <div class="d-flex gap-1 flex-wrap" id="libTypeFilters">
                    <button class="btn btn-sm btn-primary lib-type-btn active" data-type="">All</button>
                    <button class="btn btn-sm btn-outline-danger lib-type-btn"   data-type="weed">Weeds</button>
                    <button class="btn btn-sm btn-outline-success lib-type-btn"  data-type="plant">Plants</button>
                    <button class="btn btn-sm btn-outline-info lib-type-btn"     data-type="grass">Grass</button>
                    <button class="btn btn-sm btn-outline-secondary lib-type-btn" data-type="shrub">Shrubs</button>
                    <button class="btn btn-sm btn-outline-secondary lib-type-btn" data-type="tree">Trees</button>
                    <button class="btn btn-sm btn-outline-warning lib-type-btn"  data-type="fungus">Fungus</button>
                </div>
                <span class="text-muted small ms-auto" id="libCount"></span>
            </div>
        </div>
    </div>

    <!-- Card grid -->
    <div id="libraryGrid" class="mw-lib-grid">
        <div class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Loading…</div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     MODAL: Import Plant from Photo
═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="plantImportModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable mw-plant-import-dialog">
        <div class="modal-content">
            <div class="modal-header py-2">
                <div>
                    <h5 class="modal-title mb-0">Import Plant Card</h5>
                    <small class="text-muted" id="piStepLabel">Step 1 · Choose a photo</small>
                </div>
                <button type="button" class="btn-close" data-dismiss="modal"></button>
            </div>
            <div class="modal-body">

                <!-- Step 1: Choose photo -->
                <div id="piStep1">
                    <div id="piDropzone" onclick="document.getElementById('piFileInput').click()"
                         class="mw-pi-dropzone">
                        <i data-feather="camera" style="width:40px;height:40px;color:#2D8659;margin-bottom:12px;display:block;margin-left:auto;margin-right:auto;"></i>
                        <p class="mb-1 fw-bold" style="color:#2D8659;">Tap to choose a photo</p>
                        <p class="text-muted small mb-0">Use a PictureThis card or any plant photo with text</p>
                    </div>
                    <input type="file" id="piFileInput" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none;" onchange="handlePlantImportFile(this)">
                    <div id="piFilePreview" class="mt-3 text-center" style="display:none;">
                        <img id="piFileThumb" src="" alt="" style="max-height:220px;max-width:100%;border-radius:8px;border:1px solid #dee2e6;object-fit:contain;">
                        <p class="small text-muted mt-2 mb-0" id="piFileName"></p>
                    </div>
                    <div class="mt-3">
                        <button class="btn mw-btn-green mw-pi-scan-btn" id="piRunOcrBtn" onclick="runPlantImport()" style="display:none;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;vertical-align:-3px;margin-right:6px;"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>Scan Plant Card
                        </button>
                    </div>
                    <div id="piOcrStatus" class="mt-3" style="display:none;">
                        <div class="d-flex align-items-center gap-2 text-muted justify-content-center py-3">
                            <div class="spinner-border spinner-border-sm"></div>
                            <span id="piOcrStatusText">Scanning…</span>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Review extracted data -->
                <div id="piStep2" style="display:none;">

                    <!-- Saved image preview -->
                    <div id="piStep2ImgWrap" class="mb-3 text-center" style="display:none;">
                        <img id="piSavedThumb" src="" alt="" style="max-height:140px;max-width:100%;border-radius:8px;border:1px solid #dee2e6;object-fit:contain;">
                        <p class="text-muted small mt-1 mb-0">✓ Photo saved</p>
                    </div>

                    <!-- Merge alert (shown when plant already exists) -->
                    <div id="piMergeBanner" class="alert alert-info d-flex align-items-start gap-2 py-2 mb-3" style="display:none;">
                        <i data-feather="refresh-cw" style="width:16px;height:16px;flex-shrink:0;margin-top:2px;"></i>
                        <div class="flex-grow-1">
                            <strong id="piMergeTitle" class="small"></strong>
                            <p class="mb-1 small" id="piMergeDesc"></p>
                            <button type="button" class="btn btn-xs btn-outline-secondary" style="font-size:11px;padding:1px 8px;" onclick="piSaveAsNew()">Save as new entry instead</button>
                        </div>
                    </div>

                    <div class="row g-3">
                        <!-- Left: extracted plant fields -->
                        <div class="col-md-5">
                            <div class="card">
                                <div class="card-header py-2 px-3 d-flex align-items-center justify-content-between">
                                    <strong class="small">Plant Details</strong>
                                    <span class="badge small" id="piConfidenceBadge"></span>
                                </div>
                                <div class="card-body py-2">
                                    <div class="row g-2">
                                        <div class="col-12">
                                            <label class="form-label small fw-bold mb-1">Common Name <span class="text-danger">*</span></label>
                                            <input type="text" id="piCommonName" class="form-control form-control-sm">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label small fw-bold mb-1">Scientific Name</label>
                                            <input type="text" id="piLatinName" class="form-control form-control-sm" style="font-style:italic;">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small fw-bold mb-1">Type</label>
                                            <select id="piType" class="form-select form-select-sm">
                                                <option value="plant">Plant</option>
                                                <option value="weed">Weed</option>
                                                <option value="grass">Grass</option>
                                                <option value="shrub">Shrub</option>
                                                <option value="tree">Tree</option>
                                                <option value="fungus">Fungus</option>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small fw-bold mb-1">Sunlight</label>
                                            <input type="text" id="piSunlight" class="form-control form-control-sm">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small fw-bold mb-1">Watering</label>
                                            <input type="text" id="piWatering" class="form-control form-control-sm">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small fw-bold mb-1">Toxicity</label>
                                            <input type="text" id="piToxicity" class="form-control form-control-sm">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label small fw-bold mb-1">Tags</label>
                                            <input type="text" id="piTags" class="form-control form-control-sm" placeholder="comma-separated">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label small fw-bold mb-1">Description</label>
                                            <textarea id="piDescription" class="form-control form-control-sm" rows="3"></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Right: suggested quiz questions -->
                        <div class="col-md-7">
                            <div class="card">
                                <div class="card-header py-2 px-3">
                                    <strong class="small">Suggested Questions</strong>
                                    <span class="text-muted small ms-2">Check the ones to generate</span>
                                </div>
                                <div class="card-body p-0">
                                    <div id="piQuestionList"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            <div class="modal-footer py-2" id="piFooter" style="display:none;">
                <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="startImportOver()">
                    <i data-feather="refresh-cw" style="width:13px;height:13px;"></i> Try Another
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="piSaveNewBtn" onclick="piSaveAsNew()" style="display:none;">Save as New</button>
                <button type="button" class="btn btn-sm btn-outline-success" onclick="saveImportToLibrary()">
                    <i data-feather="bookmark" style="width:13px;height:13px;"></i> <span id="piSaveLibLabel">Save to Library</span>
                </button>
                <button type="button" class="btn btn-sm mw-btn-green" onclick="saveImportAll()">
                    <i data-feather="check-circle" style="width:13px;height:13px;"></i> <span id="piSaveAllLabel">Save + Add Questions</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     MODAL: Add / Edit Library Entry
═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="libraryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="libModalTitle">Add Library Entry</h5>
                <button type="button" class="btn-close" data-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="libId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Common Name <span class="text-danger">*</span></label>
                        <input type="text" id="libCommonName" class="form-control form-control-sm"
                               placeholder="e.g. Dandelion">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Latin / Scientific Name</label>
                        <input type="text" id="libLatinName" class="form-control form-control-sm"
                               placeholder="e.g. Taraxacum officinale" style="font-style:italic;">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Type <span class="text-danger">*</span></label>
                        <select id="libType" class="form-select form-select-sm">
                            <option value="weed">Weed</option>
                            <option value="plant">Plant</option>
                            <option value="grass">Grass</option>
                            <option value="shrub">Shrub</option>
                            <option value="tree">Tree</option>
                            <option value="fungus">Fungus / Disease</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label small fw-bold">Tags <span class="text-muted fw-normal">(comma-separated, for search)</span></label>
                        <input type="text" id="libTags" class="form-control form-control-sm"
                               placeholder="e.g. broadleaf, summer, invasive, lawn">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Description</label>
                        <textarea id="libDescription" class="form-control form-control-sm" rows="2"
                                  placeholder="Brief description shown in library cards and pre-filled into quiz learn notes."></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Identification Notes <span class="text-muted fw-normal">(how to ID it in the field)</span></label>
                        <textarea id="libIdNotes" class="form-control form-control-sm" rows="2"
                                  placeholder="e.g. Hollow stem with milky sap, deep taproot, jagged basal rosette leaves."></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Image</label>
                        <div class="d-flex gap-2 align-items-start flex-wrap">
                            <div id="libImgPreview" style="width:80px;height:80px;border:1px solid #dee2e6;border-radius:6px;background:#f8f9fa;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;">
                                <i data-feather="image" style="width:28px;height:28px;color:#ccc;" id="libImgPlaceholder"></i>
                                <img id="libImgThumb" src="" alt="" style="width:100%;height:100%;object-fit:cover;display:none;">
                            </div>
                            <div class="flex-grow-1">
                                <input type="text" id="libImagePath" class="form-control form-control-sm mb-2"
                                       placeholder="Paste image URL, or upload below"
                                       oninput="updateLibImgPreview(this.value)">
                                <label class="btn btn-sm btn-outline-secondary mb-0" style="cursor:pointer;">
                                    <i data-feather="upload" style="width:13px;height:13px;"></i> Upload Image
                                    <input type="file" id="libImageFile" accept="image/*" style="display:none;" onchange="uploadLibraryImage(this)">
                                </label>
                                <div class="text-muted mt-1" style="font-size:11px;" id="libUploadStatus"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm mw-btn-green" onclick="saveLibraryEntry()">Save Entry</button>
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
                <button type="button" class="btn-close" data-dismiss="modal"></button>
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
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
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
                <button type="button" class="btn-close" data-dismiss="modal"></button>
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
                        <label class="form-label small fw-bold">
                            Question Images
                            <span class="text-muted fw-normal">(up to 5 — shown as rotating stack for recognition training)</span>
                        </label>
                        <div id="qmImageList" class="mw-img-manager mb-2"></div>
                        <div class="d-flex gap-2 flex-wrap">
                            <label class="btn btn-sm btn-outline-secondary mb-0" style="cursor:pointer;">
                                <i data-feather="upload" style="width:13px;height:13px;"></i> Upload Image
                                <input type="file" id="qmImageFile" accept="image/*" style="display:none;" onchange="uploadAndAddImage(this)">
                            </label>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addImageByUrl()">
                                <i data-feather="link" style="width:13px;height:13px;"></i> Add by URL
                            </button>
                        </div>
                        <div class="text-muted" style="font-size:11px;margin-top:5px;">First image is primary. Crew sees all images as a rotating stack during the quiz and study mode.</div>
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
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
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
                <button type="button" class="btn-close" data-dismiss="modal"></button>
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
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
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
    document.getElementById('qmDifficulty').value  = 'medium';
    document.getElementById('qmType').value        = 'multiple_choice';
    document.getElementById('qmLevel').value       = '1';
    document.getElementById('qmCategory').value    = '';
    document.getElementById('qmSeasonalTags').value    = '';
    document.getElementById('qmSeasonalPriority').value = '5';
    document.getElementById('qmPriorityVal').textContent = '5';
    setMonthCheckboxes('');  // defaults to all checked
    qmImages = [];
    renderImageManager();
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
                document.getElementById('qmSeasonalTags').value = q.seasonal_tags || '';
                const pri = q.seasonal_priority || 5;
                document.getElementById('qmSeasonalPriority').value = pri;
                document.getElementById('qmPriorityVal').textContent = pri;
                setMonthCheckboxes(q.relevant_months || '');
                // Load images
                qmImages = (q.images || []).map(i => ({ image_path: i.image_path, caption: i.caption || '' }));
                if (!qmImages.length && q.image_path) {
                    qmImages = [{ image_path: q.image_path, caption: '' }];
                }
                renderImageManager();

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

// ── Multi-image Manager ───────────────────────────────────────────────────────

let qmImages = []; // [{image_path, caption}]

function renderImageManager() {
    const list = document.getElementById('qmImageList');
    if (!list) return;
    if (!qmImages.length) {
        list.innerHTML = '<div class="mw-img-manager-empty">No images yet — add up to 5 for recognition training</div>';
        return;
    }
    list.innerHTML = qmImages.map((img, i) => `
        <div class="mw-img-manager-item">
            ${i === 0 ? '<span class="mw-img-manager-primary-badge">Primary</span>' : ''}
            <img src="${escHtml(img.image_path)}" alt="Image ${i+1}" class="mw-img-manager-thumb"
                 onerror="this.src='/crm/css/../img/placeholder.png'">
            <button type="button" class="mw-img-manager-remove" onclick="removeImage(${i})" title="Remove">×</button>
        </div>
    `).join('');
}

function removeImage(idx) {
    qmImages.splice(idx, 1);
    renderImageManager();
}

async function uploadAndAddImage(input) {
    if (qmImages.length >= 5) { alert('Maximum 5 images per question'); input.value = ''; return; }
    const file = input.files[0];
    if (!file) return;
    const formData = new FormData();
    formData.append('image', file);
    try {
        const r = await fetch('/crm/api/quiz.php?action=upload_image', { method: 'POST', body: formData });
        const d = await r.json();
        if (d.success) {
            qmImages.push({ image_path: d.image_path, caption: '' });
            renderImageManager();
        } else {
            alert(d.error || 'Upload failed');
        }
    } catch(e) {
        alert('Upload error');
    }
    input.value = '';
    if (typeof feather !== 'undefined') feather.replace();
}

function addImageByUrl() {
    if (qmImages.length >= 5) { alert('Maximum 5 images per question'); return; }
    const url = prompt('Enter image URL (e.g. /uploads/quiz/filename.jpg):');
    if (!url || !url.trim()) return;
    qmImages.push({ image_path: url.trim(), caption: '' });
    renderImageManager();
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
        difficulty:       document.getElementById('qmDifficulty').value,
        question_type:    document.getElementById('qmType').value,
        learning_level:   parseInt(document.getElementById('qmLevel').value) || 1,
        relevant_months:  getMonthsFromCheckboxes(),
        seasonal_tags:    document.getElementById('qmSeasonalTags').value.trim() || null,
        seasonal_priority: parseInt(document.getElementById('qmSeasonalPriority').value) || 5,
        images:           qmImages.filter(i => i.image_path),
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

// ══ Plant & Weed Library ══════════════════════════════════════════════════════

const LIB_TYPE_COLORS = {
    weed:   { bg: '#dc3545', label: 'Weed' },
    plant:  { bg: '#198754', label: 'Plant' },
    grass:  { bg: '#0dcaf0', label: 'Grass' },
    shrub:  { bg: '#6f42c1', label: 'Shrub' },
    tree:   { bg: '#146c43', label: 'Tree' },
    fungus: { bg: '#fd7e14', label: 'Fungus' },
};

let allLibraryEntries = [];
let libActiveType = '';

async function loadLibrary() {
    const grid = document.getElementById('libraryGrid');
    if (!grid) return;
    grid.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Loading…</div>';
    const r = await fetch('/crm/api/quiz.php?action=library_list');
    const d = await r.json();
    if (!d.success) { grid.innerHTML = '<div class="text-center py-4 text-danger">Failed to load library.</div>'; return; }
    allLibraryEntries = d.entries || [];
    filterLibraryDisplay();
}

function filterLibraryDisplay() {
    const search = (document.getElementById('libSearch')?.value || '').toLowerCase();
    const grid   = document.getElementById('libraryGrid');
    if (!grid) return;

    let entries = allLibraryEntries;
    if (libActiveType) entries = entries.filter(e => e.type === libActiveType);
    if (search) {
        entries = entries.filter(e =>
            (e.common_name  || '').toLowerCase().includes(search) ||
            (e.latin_name   || '').toLowerCase().includes(search) ||
            (e.tags         || '').toLowerCase().includes(search) ||
            (e.description  || '').toLowerCase().includes(search)
        );
    }

    const countEl = document.getElementById('libCount');
    if (countEl) countEl.textContent = entries.length + ' entr' + (entries.length === 1 ? 'y' : 'ies');

    if (!entries.length) {
        grid.innerHTML = '<div class="text-center py-5 text-muted" style="grid-column:1/-1;">No entries found. Click "Add Entry" to get started.</div>';
        return;
    }

    grid.innerHTML = entries.map(e => {
        const tc = LIB_TYPE_COLORS[e.type] || { bg: '#6c757d', label: e.type };
        const img = e.image_path
            ? `<img src="${escHtml(e.image_path)}" alt="" style="width:100%;height:140px;object-fit:cover;">`
            : `<div style="width:100%;height:140px;background:#f0f4f1;display:flex;align-items:center;justify-content:center;"><i data-feather="image" style="width:36px;height:36px;color:#ccc;"></i></div>`;
        const tags = (e.tags || '').split(',').filter(Boolean).map(t =>
            `<span class="badge bg-light text-dark border" style="font-size:10px;">${escHtml(t.trim())}</span>`
        ).join(' ');
        const latin = e.latin_name ? `<div class="text-muted" style="font-size:11px;font-style:italic;">${escHtml(e.latin_name)}</div>` : '';
        const desc  = e.description ? `<div class="text-muted mt-1" style="font-size:12px;line-height:1.3;">${escHtml(e.description).slice(0,100)}${e.description.length>100?'…':''}</div>` : '';
        const entryJson = escHtml(JSON.stringify(e));
        return `<div class="mw-lib-card" data-type="${escHtml(e.type)}">
            <div class="mw-lib-card-img">${img}</div>
            <span class="mw-lib-type-badge" style="background:${tc.bg};">${tc.label}</span>
            <div class="mw-lib-card-body">
                <div class="fw-bold" style="font-size:13px;">${escHtml(e.common_name)}</div>
                ${latin}
                ${desc}
                ${tags ? `<div class="mt-1 d-flex flex-wrap gap-1">${tags}</div>` : ''}
                <div class="mw-lib-card-actions">
                    <button class="btn btn-xs btn-outline-secondary" onclick="openLibraryModal(${e.id})">Edit</button>
                    <button class="btn btn-xs mw-btn-green" onclick='useInQuestion(${JSON.stringify(e)})'>Use in Question</button>
                    <button class="btn btn-xs btn-outline-danger" onclick="deleteLibraryEntry(${e.id})">Delete</button>
                </div>
            </div>
        </div>`;
    }).join('');
    feather.replace();
}

// Type filter pills
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.lib-type-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.lib-type-btn').forEach(b => b.classList.remove('active','btn-primary','btn-danger','btn-success','btn-info','btn-warning'));
            document.querySelectorAll('.lib-type-btn').forEach(b => {
                if (!b.classList.contains('active')) {
                    const t = b.dataset.type;
                    b.classList.add(t ? 'btn-outline-' + (LIB_TYPE_COLORS[t] ? typeToBootstrap(t) : 'secondary') : 'btn-outline-secondary');
                }
            });
            this.classList.add('active');
            if (!this.dataset.type) {
                this.classList.remove('btn-outline-secondary'); this.classList.add('btn-primary');
            }
            libActiveType = this.dataset.type;
            filterLibraryDisplay();
        });
    });
});

function typeToBootstrap(t) {
    const m = { weed:'danger', plant:'success', grass:'info', shrub:'secondary', tree:'secondary', fungus:'warning' };
    return m[t] || 'secondary';
}

function openLibraryModal(id) {
    document.getElementById('libId').value          = '';
    document.getElementById('libCommonName').value  = '';
    document.getElementById('libLatinName').value   = '';
    document.getElementById('libType').value        = 'weed';
    document.getElementById('libTags').value        = '';
    document.getElementById('libDescription').value = '';
    document.getElementById('libIdNotes').value     = '';
    document.getElementById('libImagePath').value   = '';
    document.getElementById('libUploadStatus').textContent = '';
    updateLibImgPreview('');
    document.getElementById('libModalTitle').textContent = id ? 'Edit Entry' : 'Add Library Entry';

    if (id) {
        const entry = allLibraryEntries.find(e => parseInt(e.id) === id);
        if (entry) {
            document.getElementById('libId').value          = entry.id;
            document.getElementById('libCommonName').value  = entry.common_name || '';
            document.getElementById('libLatinName').value   = entry.latin_name  || '';
            document.getElementById('libType').value        = entry.type        || 'weed';
            document.getElementById('libTags').value        = entry.tags        || '';
            document.getElementById('libDescription').value = entry.description || '';
            document.getElementById('libIdNotes').value     = entry.identification_notes || '';
            document.getElementById('libImagePath').value   = entry.image_path  || '';
            updateLibImgPreview(entry.image_path || '');
        }
    }
    $('#libraryModal').modal('show');
}

async function saveLibraryEntry() {
    const name = document.getElementById('libCommonName').value.trim();
    if (!name) { alert('Common name is required.'); return; }
    const id = document.getElementById('libId').value;
    const payload = {
        csrf_token:           CSRF,
        common_name:          name,
        latin_name:           document.getElementById('libLatinName').value.trim() || null,
        type:                 document.getElementById('libType').value,
        tags:                 document.getElementById('libTags').value.trim() || null,
        description:          document.getElementById('libDescription').value.trim() || null,
        identification_notes: document.getElementById('libIdNotes').value.trim() || null,
        image_path:           document.getElementById('libImagePath').value.trim() || null,
    };
    if (id) payload.id = parseInt(id);
    const r = await fetch('/crm/api/quiz.php?action=save_library_entry', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    const d = await r.json();
    if (d.success) {
        $('#libraryModal').modal('hide');
        loadLibrary();
    } else {
        alert(d.error || 'Save failed');
    }
}

async function deleteLibraryEntry(id) {
    if (!confirm('Delete this entry from the library?')) return;
    const r = await fetch('/crm/api/quiz.php?action=delete_library_entry', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, csrf_token: CSRF }),
    });
    const d = await r.json();
    if (d.success) loadLibrary();
    else alert(d.error || 'Delete failed');
}

async function uploadLibraryImage(input) {
    const file = input.files[0];
    if (!file) return;
    const statusEl = document.getElementById('libUploadStatus');
    statusEl.textContent = 'Uploading…';
    const fd = new FormData();
    fd.append('image', file);
    const r = await fetch('/crm/api/quiz.php?action=upload_library_image', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.success && d.image_path) {
        document.getElementById('libImagePath').value = d.image_path;
        updateLibImgPreview(d.image_path);
        statusEl.textContent = 'Uploaded.';
    } else {
        statusEl.textContent = d.error || 'Upload failed';
    }
    input.value = '';
}

function updateLibImgPreview(url) {
    const thumb = document.getElementById('libImgThumb');
    const placeholder = document.getElementById('libImgPlaceholder');
    if (url) {
        thumb.src = url;
        thumb.style.display = 'block';
        if (placeholder) placeholder.style.display = 'none';
    } else {
        thumb.style.display = 'none';
        if (placeholder) placeholder.style.display = '';
    }
}

/**
 * Pre-fill the question modal from a library entry and switch to the Questions tab.
 * The admin still needs to add answer options and save.
 */
function useInQuestion(entry) {
    $('#libraryModal').modal('hide');

    // Switch to Questions tab
    document.querySelectorAll('.nav-link').forEach(a => a.classList.remove('active'));
    document.querySelectorAll('[id^="tab"]').forEach(d => d.style.display = 'none');
    const qTab = document.querySelector('a[href="?tab=questions"]');
    if (qTab) qTab.classList.add('active');
    const qDiv = document.getElementById('tabQuestions');
    if (qDiv) qDiv.style.display = '';

    // Build learn notes from library data
    let learnNotes = entry.common_name;
    if (entry.latin_name) learnNotes += ' (' + entry.latin_name + ')';
    if (entry.description) learnNotes += '\n' + entry.description;
    if (entry.identification_notes) learnNotes += '\n\nID Notes: ' + entry.identification_notes;

    // Auto-select category: Weed ID for weeds, Plant/Grass ID for plants/grass
    const targetCatName = (entry.type === 'weed') ? 'Weed Identification' : 'Plant & Grass ID';

    openQuestionModal(null);

    // Fill fields after modal opens (short delay for DOM)
    setTimeout(() => {
        document.getElementById('qmText').value       = 'Identify this ' + entry.type + ':';
        document.getElementById('qmLearnNotes').value = learnNotes;

        // Set category
        const catSelect = document.getElementById('qmCategory');
        if (catSelect) {
            for (let opt of catSelect.options) {
                if (opt.text.includes('Weed') && entry.type === 'weed') { catSelect.value = opt.value; break; }
                if ((opt.text.includes('Plant') || opt.text.includes('Grass')) && entry.type !== 'weed') { catSelect.value = opt.value; break; }
            }
        }

        // Add image if present
        if (entry.image_path && qmImages.length < 5) {
            qmImages.push({ image_path: entry.image_path, caption: '' });
            renderImageManager();
        }

        feather.replace();
    }, 200);
}

// ══════════════════════════════════════════════════════════════════════════════
// PLANT IMPORT FROM PHOTO
// ══════════════════════════════════════════════════════════════════════════════

let piImportData  = null;  // holds last API response
let piPendingFile = null;  // holds File object across iOS (DataTransfer not supported)
let piMergeId     = 0;     // >0 = update existing library entry, 0 = create new

function openPlantImportModal() {
    startImportOver();
    $('#plantImportModal').modal('show');
}

function startImportOver() {
    piImportData  = null;
    piPendingFile = null;
    piMergeId     = 0;
    document.getElementById('piStep1').style.display = '';
    document.getElementById('piStep2').style.display = 'none';
    document.getElementById('piFooter').style.display = 'none';
    document.getElementById('piFilePreview').style.display = 'none';
    document.getElementById('piRunOcrBtn').style.display = 'none';
    document.getElementById('piOcrStatus').style.display = 'none';
    document.getElementById('piFileInput').value = '';
    document.getElementById('piStepLabel').textContent = 'Step 1 · Choose a photo';
    document.getElementById('piMergeBanner').style.display = 'none';
    document.getElementById('piSaveNewBtn').style.display = 'none';
    document.getElementById('piSaveLibLabel').textContent = 'Save to Library';
    document.getElementById('piSaveAllLabel').textContent = 'Save + Add Questions';
    document.getElementById('piStep2ImgWrap').style.display = 'none';
}

function handlePlantImportFile(input) {
    const file = input ? input.files[0] : null;
    if (!file) return;
    piPendingFile = file;  // store for upload — avoids DataTransfer (unsupported on iOS)
    const objUrl = URL.createObjectURL(file);
    document.getElementById('piFileThumb').src = objUrl;
    document.getElementById('piFileName').textContent = file.name + ' (' + (file.size / 1024).toFixed(0) + ' KB)';
    document.getElementById('piFilePreview').style.display = '';
    document.getElementById('piRunOcrBtn').style.display = '';
}

// Called from auto-import flow (IndexedDB → file blob, no input element)
function loadPlantFileFromBlob(file) {
    piPendingFile = file;
    const objUrl = URL.createObjectURL(file);
    document.getElementById('piFileThumb').src = objUrl;
    document.getElementById('piFileName').textContent = file.name + ' (' + (file.size / 1024).toFixed(0) + ' KB)';
    document.getElementById('piFilePreview').style.display = '';
    document.getElementById('piRunOcrBtn').style.display = '';
}

// Dropzone drag-over highlight
document.addEventListener('DOMContentLoaded', function() {
    const dz = document.getElementById('piDropzone');
    if (!dz) return;
    dz.addEventListener('dragover', e => { e.preventDefault(); dz.style.borderColor = 'var(--mw-green)'; });
    dz.addEventListener('dragleave', () => { dz.style.borderColor = '#dee2e6'; });
    dz.addEventListener('drop', e => {
        e.preventDefault();
        dz.style.borderColor = '#dee2e6';
        const files = e.dataTransfer.files;
        if (files.length) {
            const dt = new DataTransfer();
            dt.items.add(files[0]);
            document.getElementById('piFileInput').files = dt.files;
            handlePlantImportFile(document.getElementById('piFileInput'));
        }
    });
});

async function runPlantImport() {
    const file = piPendingFile || (document.getElementById('piFileInput').files[0] || null);
    if (!file) { alert('Please choose an image first.'); return; }

    document.getElementById('piRunOcrBtn').style.display = 'none';
    document.getElementById('piOcrStatus').style.display = '';
    document.getElementById('piOcrStatusText').textContent = 'Running OCR on image…';

    const fd = new FormData();
    fd.append('image', file);
    fd.append('csrf_token', CSRF);

    let data;
    try {
        document.getElementById('piOcrStatusText').textContent = 'Extracting plant data + looking up Wikipedia…';
        const r = await fetch('/crm/api/quiz-plant-import.php', { method: 'POST', body: fd });
        data = await r.json();
    } catch (e) {
        document.getElementById('piOcrStatus').style.display = 'none';
        document.getElementById('piRunOcrBtn').style.display = '';
        alert('Network error — try again.');
        return;
    }

    document.getElementById('piOcrStatus').style.display = 'none';

    if (!data.success) {
        document.getElementById('piRunOcrBtn').style.display = '';
        alert('Import failed: ' + (data.error || 'Unknown error'));
        return;
    }

    piImportData = data;
    populateImportReview(data);
}

function populateImportReview(data) {
    const p = data.plant;

    document.getElementById('piCommonName').value  = p.common_name || '';
    document.getElementById('piLatinName').value   = p.latin_name  || '';
    document.getElementById('piType').value        = p.type        || 'plant';
    document.getElementById('piSunlight').value    = p.sunlight    || '';
    document.getElementById('piWatering').value    = p.watering    || '';
    document.getElementById('piToxicity').value    = p.toxicity    || '';
    document.getElementById('piTags').value        = p.tags        || '';
    document.getElementById('piDescription').value = data.wikipedia_summary || '';

    // Show saved image at top of step 2
    if (data.image_path) {
        const thumb = document.getElementById('piSavedThumb');
        thumb.src = data.image_path;
        document.getElementById('piStep2ImgWrap').style.display = '';
    }

    // Update step label
    document.getElementById('piStepLabel').textContent = 'Step 2 · Review & save';

    // Handle merge — existing plant in library
    if (data.existing_entry) {
        piMergeId = data.existing_entry.id;
        const name = data.existing_entry.common_name || 'This plant';
        document.getElementById('piMergeTitle').textContent = name + ' is already in your library';
        document.getElementById('piMergeDesc').textContent = 'Saving will update the existing entry with the new photo and any filled-in fields.';
        document.getElementById('piMergeBanner').style.display = '';
        document.getElementById('piSaveNewBtn').style.display = '';
        document.getElementById('piSaveLibLabel').textContent = 'Update Library Entry';
        document.getElementById('piSaveAllLabel').textContent = 'Update + Add Questions';
    } else {
        piMergeId = 0;
        document.getElementById('piMergeBanner').style.display = 'none';
        document.getElementById('piSaveNewBtn').style.display = 'none';
        document.getElementById('piSaveLibLabel').textContent = 'Save to Library';
        document.getElementById('piSaveAllLabel').textContent = 'Save + Add Questions';
    }

    // Confidence badge
    const fields = [p.common_name, p.latin_name, p.sunlight, p.toxicity].filter(Boolean).length;
    const badge  = document.getElementById('piConfidenceBadge');
    if (fields >= 3) { badge.textContent = 'Good match'; badge.className = 'badge bg-success small'; }
    else if (fields >= 2) { badge.textContent = 'Partial match'; badge.className = 'badge bg-warning small'; }
    else { badge.textContent = 'Low confidence — check fields'; badge.className = 'badge bg-danger small'; }

    // Build question checklist
    const list = document.getElementById('piQuestionList');
    list.innerHTML = '';
    if (!data.questions || !data.questions.length) {
        list.innerHTML = '<p class="text-muted small p-3">No questions could be generated. Fill in the plant data and try again.</p>';
    } else {
        data.questions.forEach((q, i) => {
            const checked = q.selected ? 'checked' : '';
            const typeLabel = q.type === 'photo_id' ? '📷 Photo ID' : '❓ Multiple Choice';
            const imgHtml = q.image_path
                ? `<img src="${q.image_path}" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:4px;border:1px solid #dee2e6;flex-shrink:0;">`
                : '';
            const opts = (q.options || []).map((o, oi) =>
                `<span class="badge ${oi === 0 ? 'bg-success' : 'bg-light text-dark border'} me-1 mb-1">${o}</span>`
            ).join('');
            list.innerHTML += `
                <div class="d-flex gap-3 align-items-start p-3 border-bottom" id="piQ${i}">
                    <div class="form-check pt-1" style="flex-shrink:0;">
                        <input class="form-check-input" type="checkbox" id="piQCheck${i}" ${checked}>
                    </div>
                    ${imgHtml}
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge bg-secondary" style="font-size:10px;">${typeLabel}</span>
                            <span class="badge bg-light text-dark border" style="font-size:10px;">${q.difficulty}</span>
                        </div>
                        <p class="mb-1 small fw-bold">${q.question_text}</p>
                        <div class="mb-1">${opts}</div>
                        <p class="text-muted mb-0" style="font-size:11px;">Learn notes: ${(q.learn_notes || '').substring(0, 100)}…</p>
                    </div>
                </div>`;
        });
    }

    document.getElementById('piStep1').style.display = 'none';
    document.getElementById('piStep2').style.display = '';
    document.getElementById('piFooter').style.display = '';
    feather.replace();
}

function getImportPlantPayload() {
    return {
        csrf_token:           CSRF,
        id:                   piMergeId || 0,
        common_name:          document.getElementById('piCommonName').value.trim(),
        latin_name:           document.getElementById('piLatinName').value.trim() || null,
        type:                 document.getElementById('piType').value,
        tags:                 document.getElementById('piTags').value.trim() || null,
        description:          document.getElementById('piDescription').value.trim() || null,
        identification_notes: null,
        image_path:           (piImportData && piImportData.image_path) || null,
    };
}

function piSaveAsNew() {
    piMergeId = 0;
    document.getElementById('piMergeBanner').style.display = 'none';
    document.getElementById('piSaveNewBtn').style.display = 'none';
    document.getElementById('piSaveLibLabel').textContent = 'Save to Library';
    document.getElementById('piSaveAllLabel').textContent = 'Save + Add Questions';
}

async function saveImportToLibrary() {
    const name = document.getElementById('piCommonName').value.trim();
    if (!name) { alert('Common name is required.'); return; }

    const r = await fetch('/crm/api/quiz.php?action=save_library_entry', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(getImportPlantPayload()),
    });
    const d = await r.json();
    if (d.success) {
        $('#plantImportModal').modal('hide');
        loadLibrary();
        alert('Plant saved to library.');
    } else {
        alert(d.error || 'Save failed');
    }
}

async function saveImportAll() {
    const name = document.getElementById('piCommonName').value.trim();
    if (!name) { alert('Common name is required.'); return; }

    // 1. Save to library
    const libR = await fetch('/crm/api/quiz.php?action=save_library_entry', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(getImportPlantPayload()),
    });
    const libD = await libR.json();
    if (!libD.success) { alert(libD.error || 'Library save failed'); return; }

    // 2. Generate selected questions
    if (!piImportData || !piImportData.questions) {
        $('#plantImportModal').modal('hide');
        loadLibrary();
        return;
    }

    const selectedQuestions = piImportData.questions.filter((q, i) => {
        const cb = document.getElementById('piQCheck' + i);
        return cb && cb.checked;
    });

    if (!selectedQuestions.length) {
        $('#plantImportModal').modal('hide');
        loadLibrary();
        alert('Plant saved to library. No questions were selected to generate.');
        return;
    }

    // Find the correct category ID (Plant & Grass ID or Weed ID)
    const plantType = document.getElementById('piType').value;
    const targetCatName = plantType === 'weed' ? 'Weed Identification' : 'Plant & Grass ID';
    let catId = null;
    if (window.allCategories) {
        const cat = window.allCategories.find(c => c.name && c.name.includes(plantType === 'weed' ? 'Weed' : 'Plant'));
        if (cat) catId = parseInt(cat.id);
    }

    let saved = 0, failed = 0;
    for (const q of selectedQuestions) {
        const options = (q.options || []).map((o, i) => ({ text: o, is_correct: i === 0 }));
        const payload = {
            csrf_token:    CSRF,
            category_id:   catId,
            question_text: q.question_text,
            question_type: q.type || 'multiple_choice',
            difficulty:    q.difficulty || 'medium',
            learn_notes:   q.learn_notes || '',
            is_active:     1,
            options:       options,
            image_path:    q.image_path || null,
            images:        q.image_path ? [{ image_path: q.image_path, caption: name }] : [],
        };
        const qr = await fetch('/crm/api/quiz.php?action=save_question', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const qd = await qr.json();
        if (qd.success) saved++; else failed++;
    }

    $('#plantImportModal').modal('hide');
    loadLibrary();
    const msg = `Plant saved to library. ${saved} question${saved !== 1 ? 's' : ''} generated.`
        + (failed ? ` (${failed} failed — check category assignment)` : '');
    alert(msg);
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
    <?php elseif ($activeTab === 'library'): ?>
    loadLibrary();
    <?php endif; ?>
});

// ── Auto-open plant import modal (from mobile menu file picker) ──────────────
<?php if ($autoImport): ?>
(function() {
    function openImportWithFile(file) {
        openPlantImportModal();
        // Small delay for modal animation to settle
        setTimeout(function() {
            if (!file) return;
            // Use loadPlantFileFromBlob — avoids DataTransfer which iOS Safari doesn't support
            loadPlantFileFromBlob(file);
        }, 300);
    }

    // Try to retrieve pending file from IndexedDB
    var req = indexedDB.open('mw_plant_import', 1);
    req.onupgradeneeded = function(e) {
        e.target.result.createObjectStore('pending', { keyPath: 'id' });
    };
    req.onsuccess = function(e) {
        var db  = e.target.result;
        var tx  = db.transaction('pending', 'readwrite');
        var get = tx.objectStore('pending').get(1);
        get.onsuccess = function() {
            var record = get.result;
            // Clear it right away so it doesn't re-open on refresh
            tx.objectStore('pending').delete(1);
            if (record && record.file) {
                openImportWithFile(record.file);
            } else {
                // No file stored — just open the modal (manual upload)
                openPlantImportModal();
            }
        };
        get.onerror = function() { openPlantImportModal(); };
    };
    req.onerror = function() { openPlantImportModal(); };
})();
<?php endif; ?>

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
