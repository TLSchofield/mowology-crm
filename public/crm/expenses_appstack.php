<?php
require_once __DIR__ . '/../loginAuth/auth.php';
require_once 'includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('expenses.view');

$canEdit = userHasPermission('expenses.edit');
$canSend = userHasPermission('expenses.send');

$pageTitle = 'Expenses';
$activePage = 'expenses';
$csrfToken = generateCSRFToken();
$extraHead = '<meta name="csrf-token" content="' . htmlspecialchars($csrfToken) . '">'
           . '<link href="/crm/css/mobile-cards.css?v=20260215b" rel="stylesheet">';
?>
<?php include 'includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center">
    <h1 class="h3 mb-0">Expenses</h1>
</div>

<?php if ($canEdit): ?>
<!-- Hidden file inputs (must stay outside hidden containers for mobile access) -->
<input type="file" id="receiptFileInput" accept="image/*" capture="environment" class="d-none">
<input type="file" id="receiptGalleryInput" accept="image/*" class="d-none">

<!-- ═══════ RECEIPT CAPTURE AREA ═══════════════════════════════════ -->
<div class="card mb-3" id="receiptCaptureCard">
    <div class="card-body text-center" id="captureArea">
        <div id="capturePrompt">
            <button type="button" class="btn btn-lg btn-primary mw-capture-btn" onclick="triggerCamera()">
                <i data-feather="camera" style="width:28px;height:28px;"></i>
                <span>Snap Receipt</span>
            </button>
            <div class="mt-2">
                <label class="mw-gallery-link" for="receiptFileInput">
                    <i data-feather="image" style="width:14px;height:14px;"></i> Choose from gallery
                </label>
            </div>
        </div>

        <!-- Analyzing spinner (hidden by default) -->
        <div id="analyzeSpinner" style="display:none;">
            <div class="spinner-border text-primary mb-2" style="width:3rem;height:3rem;" role="status"></div>
            <p class="mb-0 text-muted">Analyzing receipt...</p>
        </div>
    </div>
</div>

<!-- ═══════ RECEIPT REVIEW PANEL (hidden until photo processed) ═════ -->
<div class="card mb-3" id="receiptReviewPanel" style="display:none;">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">Review Receipt</h5>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetCapture()">
            <i data-feather="x" style="width:14px;height:14px;"></i> Cancel
        </button>
    </div>
    <div class="card-body">
        <div class="row">
            <!-- Left: Receipt Preview -->
            <div class="col-md-5 mb-3 mb-md-0">
                <div class="mw-receipt-preview-container">
                    <img id="receiptPreviewImg" src="" alt="Receipt" class="mw-receipt-preview-img">
                </div>
                <div id="ocrStatusBadge" class="mt-2 text-center"></div>
            </div>
            <!-- Right: Pre-filled Form -->
            <div class="col-md-7">
                <input type="hidden" id="intakeMediaId">
                <input type="hidden" id="intakeOcrText">

                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label small mb-0">Vendor
                            <span class="mw-confidence-dot" id="confVendor" title=""></span>
                        </label>
                        <input type="text" class="form-control form-control-sm" id="rvVendorSearch" placeholder="Search vendors..." autocomplete="off">
                        <input type="hidden" id="rvVendorId">
                        <div class="dropdown-menu w-100" id="rvVendorDropdown" style="max-height:200px;overflow-y:auto;"></div>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Date
                            <span class="mw-confidence-dot" id="confDate" title=""></span>
                        </label>
                        <input type="date" class="form-control form-control-sm" id="rvDate">
                    </div>
                    <div class="col-4">
                        <label class="form-label small mb-0">Amount</label>
                        <input type="number" class="form-control form-control-sm" id="rvAmount" step="0.01" min="0">
                    </div>
                    <div class="col-4">
                        <label class="form-label small mb-0">Tax
                            <span class="mw-confidence-dot" id="confTax" title=""></span>
                        </label>
                        <input type="number" class="form-control form-control-sm" id="rvTax" step="0.01" min="0" value="0">
                    </div>
                    <div class="col-4">
                        <label class="form-label small mb-0">Total
                            <span class="mw-confidence-dot" id="confTotal" title=""></span>
                        </label>
                        <input type="number" class="form-control form-control-sm" id="rvTotal" step="0.01" min="0">
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Accounting Category
                            <span class="mw-confidence-dot" id="confCategory" title=""></span>
                        </label>
                        <select class="form-select form-select-sm" id="rvAcctCategory"></select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">GBP Category</label>
                        <select class="form-select form-select-sm" id="rvGbpCategory"></select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Payment Method
                            <span class="mw-confidence-dot" id="confPayment" title=""></span>
                        </label>
                        <select class="form-select form-select-sm" id="rvPayment">
                            <option value="">Select...</option>
                            <option value="company_card">Company Card</option>
                            <option value="credit_card">Credit Card</option>
                            <option value="debit">Debit</option>
                            <option value="cash">Cash</option>
                            <option value="etransfer">E-Transfer</option>
                            <option value="cheque">Cheque</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">Job #</label>
                        <input type="number" class="form-control form-control-sm" id="rvJobId" placeholder="Optional">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Description</label>
                        <input type="text" class="form-control form-control-sm" id="rvDescription">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-0">Notes</label>
                        <textarea class="form-control form-control-sm" id="rvNotes" rows="1"></textarea>
                    </div>
                </div>

                <!-- Line Items from OCR -->
                <div class="mw-line-items-section" id="rvLineItemsSection" style="display:none;">
                    <button type="button" class="mw-line-items-toggle" onclick="toggleLineItems('rv')">
                        <i data-feather="list" style="width:12px;height:12px;"></i>
                        <span id="rvLineItemsCount">0</span> items detected
                    </button>
                    <div id="rvLineItemsList" style="display:none;">
                        <table class="mw-line-items-table w-100"></table>
                    </div>
                </div>

                <!-- Duplicate Warning (hidden until detected) -->
                <div class="mw-duplicate-warning" id="rvDuplicateWarning" style="display:none;">
                    <div class="mw-duplicate-warning-header">
                        <span><i data-feather="alert-triangle" style="width:16px;height:16px;"></i> Possible Duplicate</span>
                        <button type="button" class="mw-duplicate-warning-dismiss" onclick="dismissDuplicateWarning('rv')" title="Dismiss">&times;</button>
                    </div>
                    <div class="mw-duplicate-warning-body" id="rvDuplicateList"></div>
                </div>

                <div class="mt-3 d-flex gap-2">
                    <button type="button" class="btn btn-primary flex-grow-1" onclick="saveFromReview()">
                        <i data-feather="save" style="width:16px;height:16px;"></i> Save Expense
                    </button>
                    <?php if ($canSend): ?>
                    <button type="button" class="btn btn-success" onclick="saveAndSend()" title="Save and send to QuickBooks">
                        <i data-feather="send" style="width:16px;height:16px;"></i> Save & Send
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item">
        <a class="nav-link active" data-toggle="tab" href="#expenses-tab" role="tab">Expenses</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-toggle="tab" href="#vendors-tab" role="tab">Vendors</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-toggle="tab" href="#send-log-tab" role="tab">Send Log</a>
    </li>
</ul>

<div class="tab-content">

<!-- ───── Expenses List Tab ──────────────────────────────────────── -->
<div class="tab-pane fade show active" id="expenses-tab" role="tabpanel">

    <!-- Filters -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="row align-items-end g-2">
                <div class="col-md-2">
                    <label class="form-label small mb-0">From</label>
                    <input type="date" class="form-control form-control-sm" id="filterDateFrom">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-0">To</label>
                    <input type="date" class="form-control form-control-sm" id="filterDateTo">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-0">Category</label>
                    <select class="form-select form-select-sm" id="filterCategory">
                        <option value="">All</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-0">Status</label>
                    <select class="form-select form-select-sm" id="filterStatus">
                        <option value="">All</option>
                        <option value="draft">Draft</option>
                        <option value="approved">Approved</option>
                        <option value="forwarded">Forwarded</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-0">Search</label>
                    <input type="text" class="form-control form-control-sm" id="filterSearch" placeholder="Vendor, notes...">
                </div>
                <div class="col-md-2 d-flex gap-1">
                    <button class="btn btn-sm btn-primary flex-grow-1" onclick="loadExpenses()">Filter</button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="clearFilters()">Clear</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Summary -->
    <div class="row mb-3" id="expenseStats" style="display:none;">
        <div class="col-md-3">
            <div class="card">
                <div class="card-body py-2 text-center">
                    <div class="text-muted small">This Month</div>
                    <div class="h5 mb-0" id="statTotal">$0.00</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body py-2 text-center">
                    <div class="text-muted small">Expenses</div>
                    <div class="h5 mb-0" id="statCount">0</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body py-2 text-center">
                    <div class="text-muted small">Forwarded</div>
                    <div class="h5 mb-0" id="statForwarded">0</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body py-2 text-center">
                    <div class="text-muted small">Drafts</div>
                    <div class="h5 mb-0" id="statDrafts">0</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Expenses Table -->
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Vendor</th>
                        <th>Category</th>
                        <th class="text-end">Total</th>
                        <th>Job</th>
                        <th>Status</th>
                        <th>Receipt</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="expensesTableBody">
                    <tr><td colspan="8" class="text-center py-4 text-muted">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <div class="d-flex justify-content-between align-items-center mt-3" id="expensePagination" style="display:none!important;">
        <div class="text-muted small" id="paginationInfo"></div>
        <nav>
            <ul class="pagination pagination-sm mb-0" id="paginationLinks"></ul>
        </nav>
    </div>
</div>

<!-- ───── Vendors Tab ────────────────────────────────────────────── -->
<div class="tab-pane fade" id="vendors-tab" role="tabpanel">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Vendor Directory</h5>
        <?php if ($canEdit): ?>
        <button class="btn btn-sm btn-primary" onclick="showVendorModal()">
            <i data-feather="plus-circle" style="width:14px;height:14px;"></i> Add Vendor
        </button>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Vendor</th>
                        <th>Aliases</th>
                        <th>Accounting Category</th>
                        <th>GBP Category</th>
                        <th>Locations</th>
                        <th>Total Spent</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="vendorsTableBody">
                    <tr><td colspan="7" class="text-center py-4 text-muted">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ───── Send Log Tab ───────────────────────────────────────────── -->
<div class="tab-pane fade" id="send-log-tab" role="tabpanel">
    <div class="card">
        <div class="card-header"><h5 class="card-title mb-0">Receipt Forwarding Log</h5></div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Expense</th>
                        <th>To</th>
                        <th>Subject</th>
                        <th>Status</th>
                        <th>Sent By</th>
                    </tr>
                </thead>
                <tbody id="sendLogBody">
                    <tr><td colspan="6" class="text-center py-4 text-muted">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

</div><!-- /tab-content -->


<!-- ═══════════════════════════════════════════════
     MOBILE: Receipt Snapper View (hidden on desktop)
     ═══════════════════════════════════════════════ -->
<div class="mw-mc-container" data-page="expenses">

    <!-- Fixed Top Bar -->
    <div class="mw-mc-topbar">
        <div class="mw-mc-topbar-left">
            <div class="mw-mc-topbar-day">Receipts</div>
            <div class="mw-mc-topbar-date"><?php echo date('M j, Y'); ?></div>
        </div>
        <div class="mw-mc-topbar-center"></div>
        <div class="mw-mc-topbar-right">
            <div class="mw-mc-expense-month-pill" id="mobileMonthTotal">
                <span class="mw-mc-expense-month-label">This month</span>
                <span class="mw-mc-expense-month-value" id="mobileStatTotal">$0</span>
            </div>
        </div>
    </div>

    <!-- Scrollable Content Area -->
    <div class="mw-mc-scroll-area" id="mobileExpenseScrollArea">

        <?php if ($canEdit): ?>
        <!-- Receipt Capture Hero -->
        <div class="mw-mc-expense-capture" id="mobileCaptureArea">
            <div class="mw-mc-expense-capture-inner">
                <button type="button" class="mw-mc-expense-snap-btn" onclick="triggerCamera()">
                    <div class="mw-mc-expense-snap-icon">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    </div>
                    <div class="mw-mc-expense-snap-text">
                        <span class="mw-mc-expense-snap-title">Snap Receipt</span>
                        <span class="mw-mc-expense-snap-sub">Take a photo or choose from gallery</span>
                    </div>
                </button>
                <div class="mw-mc-expense-capture-actions">
                    <button type="button" class="mw-mc-expense-action-chip" onclick="triggerCamera()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>
                        Camera
                    </button>
                    <button type="button" class="mw-mc-expense-action-chip" onclick="document.getElementById('receiptGalleryInput').click()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                        Gallery
                    </button>
                    <button type="button" class="mw-mc-expense-action-chip" onclick="mobileManualEntry()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        Manual
                    </button>
                </div>
            </div>
        </div>

        <!-- Analyzing Spinner (hidden by default) -->
        <div class="mw-mc-expense-spinner" id="mobileAnalyzeSpinner" style="display:none;">
            <div class="mw-mc-expense-spinner-ring">
                <div class="spinner-dot"></div>
            </div>
            <div class="mw-mc-expense-spinner-text">
                <span class="mw-mc-expense-spinner-title">Analyzing receipt...</span>
                <span class="mw-mc-expense-spinner-sub">Reading text and matching vendor</span>
            </div>
        </div>

        <!-- Mobile Review Panel (hidden until receipt captured) -->
        <div class="mw-mc-expense-review" id="mobileReviewPanel" style="display:none;">
            <div class="mw-mc-expense-review-header">
                <div class="mw-mc-expense-review-header-left">
                    <span class="mw-mc-expense-review-title">Review Receipt</span>
                    <span class="mw-mc-expense-review-badge" id="mobileOcrBadge"></span>
                </div>
                <button type="button" class="mw-mc-expense-review-cancel" onclick="mobileResetReview()">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <!-- Collapsible Receipt Image -->
            <div class="mw-mc-expense-review-img-wrap" id="mobileReceiptWrap" onclick="toggleMobileReceiptExpand()">
                <img id="mobileReceiptImg" src="" alt="Receipt">
                <div class="mw-mc-expense-review-img-hint">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
                    <span>Tap to expand</span>
                </div>
            </div>

            <div class="mw-mc-expense-review-form">
                <!-- Top row: Total prominently displayed -->
                <div class="mw-mc-expense-total-hero">
                    <label>Total</label>
                    <div class="mw-mc-expense-total-input-wrap">
                        <span class="mw-mc-expense-currency">$</span>
                        <input type="number" id="mobileRvTotal" step="0.01" min="0" inputmode="decimal" placeholder="0.00">
                        <span class="mw-mc-expense-conf-dot" id="mobileConfTotal"></span>
                    </div>
                </div>

                <!-- Amount / Tax in a compact row -->
                <div class="mw-mc-expense-field-row">
                    <div class="mw-mc-expense-field">
                        <label>Subtotal</label>
                        <input type="number" id="mobileRvAmount" step="0.01" min="0" inputmode="decimal" placeholder="0.00">
                    </div>
                    <div class="mw-mc-expense-field mw-mc-expense-field-narrow">
                        <label>Tax</label>
                        <input type="number" id="mobileRvTax" step="0.01" min="0" value="0" inputmode="decimal" placeholder="0.00">
                    </div>
                </div>

                <div class="mw-mc-expense-field">
                    <label>Vendor <span class="mw-mc-expense-conf-dot" id="mobileConfVendor"></span></label>
                    <input type="text" id="mobileRvVendor" placeholder="Start typing vendor name..." autocomplete="off">
                    <input type="hidden" id="mobileRvVendorId">
                    <div class="mw-mc-expense-vendor-dropdown" id="mobileVendorDropdown"></div>
                </div>

                <div class="mw-mc-expense-field-row">
                    <div class="mw-mc-expense-field">
                        <label>Date</label>
                        <input type="date" id="mobileRvDate">
                    </div>
                    <div class="mw-mc-expense-field">
                        <label>Category</label>
                        <select id="mobileRvCategory"></select>
                    </div>
                </div>

                <div class="mw-mc-expense-field-row">
                    <div class="mw-mc-expense-field">
                        <label>Payment</label>
                        <select id="mobileRvPayment">
                            <option value="">Select...</option>
                            <option value="company_card">Company Card</option>
                            <option value="credit_card">Credit Card</option>
                            <option value="debit">Debit</option>
                            <option value="cash">Cash</option>
                            <option value="etransfer">E-Transfer</option>
                            <option value="cheque">Cheque</option>
                        </select>
                    </div>
                    <div class="mw-mc-expense-field">
                        <label>Job #</label>
                        <input type="number" id="mobileRvJobId" placeholder="Optional" inputmode="numeric">
                    </div>
                </div>

                <div class="mw-mc-expense-field">
                    <label>Description</label>
                    <input type="text" id="mobileRvDescription" placeholder="What was this for?">
                </div>

                <!-- Line Items (if OCR detected) -->
                <div class="mw-mc-expense-line-items" id="mobileLineItemsSection" style="display:none;">
                    <button type="button" class="mw-mc-expense-line-items-toggle" onclick="toggleMobileLineItems()">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                        <span id="mobileLineItemsCount">0</span> items detected
                        <svg class="mw-mc-expense-chevron" id="mobileLineItemsChevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <div class="mw-mc-expense-line-items-list" id="mobileLineItemsList" style="display:none;"></div>
                </div>

                <div class="mw-mc-expense-save-row">
                    <button type="button" class="mw-mc-expense-save-btn" onclick="mobileSaveExpense(false)">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                        Save
                    </button>
                    <?php if ($canSend): ?>
                    <button type="button" class="mw-mc-expense-send-btn" onclick="mobileSaveExpense(true)">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                        Send
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Quick Stats Row -->
        <div class="mw-mc-expense-quick-stats" id="mobileQuickStats">
            <div class="mw-mc-expense-stat">
                <span class="mw-mc-expense-stat-value" id="mobileStatCount">0</span>
                <span class="mw-mc-expense-stat-label">Receipts</span>
            </div>
            <div class="mw-mc-expense-stat-divider"></div>
            <div class="mw-mc-expense-stat">
                <span class="mw-mc-expense-stat-value" id="mobileStatDrafts">0</span>
                <span class="mw-mc-expense-stat-label">Drafts</span>
            </div>
            <div class="mw-mc-expense-stat-divider"></div>
            <div class="mw-mc-expense-stat">
                <span class="mw-mc-expense-stat-value" id="mobileStatSent">0</span>
                <span class="mw-mc-expense-stat-label">Sent</span>
            </div>
        </div>

        <!-- Recent Expenses -->
        <div class="mw-mc-section-label" id="mobileExpenseListLabel">Recent Expenses</div>
        <div id="mobileExpenseList">
            <div class="mw-mc-empty">
                <div class="mw-mc-empty-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.3"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                </div>
                <div class="mw-mc-empty-text">Loading...</div>
            </div>
        </div>

    </div><!-- /.mw-mc-scroll-area -->

    <!-- Fixed Bottom Bar -->
    <div class="mw-mc-bottombar">
        <a href="/crm/jobs/schedule.php" class="mw-mc-bottombar-btn">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span>Schedule</span>
        </a>
        <button type="button" class="mw-mc-bottombar-btn mw-mc-fab-snap" onclick="mobileScrollToCapture()">
            <div class="mw-mc-fab-snap-inner">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>
            </div>
            <span>Snap</span>
        </button>
        <button type="button" class="mw-mc-bottombar-btn" onclick="mobileScrollToExpenses()">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
            <span>History</span>
        </button>
    </div>

    <!-- Mobile Success Toast (hidden) -->
    <div class="mw-mc-expense-toast" id="mobileExpenseToast">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        <span id="mobileExpenseToastText">Saved!</span>
    </div>

</div><!-- /.mw-mc-container -->


<!-- ═══════ EDIT EXPENSE MODAL (for editing existing) ═══════════════ -->
<div class="modal fade" id="expenseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="expenseModalTitle">Edit Expense</h5>
                <button type="button" class="btn-close" data-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="expenseId">
                <div class="row">
                    <!-- Left: Receipt Image (shown only when image exists) -->
                    <div class="col-md-4" id="expReceiptCol" style="display:none;">
                        <div class="mw-modal-receipt-preview" onclick="openLightbox(this.querySelector('img')?.src)">
                            <img id="expReceiptImg" src="" alt="Receipt">
                        </div>
                        <!-- Line Items -->
                        <div class="mw-line-items-section" id="expLineItemsSection" style="display:none;">
                            <button type="button" class="mw-line-items-toggle" onclick="toggleLineItems('exp')">
                                <i data-feather="list" style="width:12px;height:12px;"></i>
                                <span id="expLineItemsCount">0</span> items detected
                            </button>
                            <div id="expLineItemsList" style="display:none;">
                                <table class="mw-line-items-table w-100"></table>
                            </div>
                        </div>
                    </div>
                    <!-- Right: Form Fields -->
                    <div id="expFormCol">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="expDate" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Vendor</label>
                                <input type="text" class="form-control" id="expVendorSearch" placeholder="Search vendors..." autocomplete="off">
                                <input type="hidden" id="expVendorId">
                                <div class="dropdown-menu w-100" id="vendorDropdown" style="max-height:200px;overflow-y:auto;"></div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Payment Method</label>
                                <select class="form-select" id="expPayment">
                                    <option value="">Select...</option>
                                    <option value="company_card">Company Card</option>
                                    <option value="credit_card">Credit Card</option>
                                    <option value="debit">Debit</option>
                                    <option value="cash">Cash</option>
                                    <option value="etransfer">E-Transfer</option>
                                    <option value="cheque">Cheque</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Amount (pre-tax)</label>
                                <input type="number" class="form-control" id="expAmount" step="0.01" min="0">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Tax</label>
                                <input type="number" class="form-control" id="expTax" step="0.01" min="0" value="0">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Total <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="expTotal" step="0.01" min="0" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Accounting Category</label>
                                <select class="form-select" id="expAcctCategory"></select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">GBP Category</label>
                                <select class="form-select" id="expGbpCategory"></select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Job #</label>
                                <input type="number" class="form-control" id="expJobId" placeholder="Job ID (optional)">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select class="form-select" id="expStatus">
                                    <option value="draft">Draft</option>
                                    <option value="approved">Approved</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" id="expDescription" rows="2"></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" id="expNotes" rows="2"></textarea>
                            </div>

                            <!-- Smart Match Confidence -->
                            <div class="col-12" id="matchConfidenceRow" style="display:none;">
                                <div class="alert alert-info py-2 mb-0">
                                    <strong>Smart Match:</strong>
                                    <span id="matchConfidenceText"></span>
                                    <span class="badge bg-primary ms-2" id="matchConfidenceBadge"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <?php if ($canEdit): ?>
                <button type="button" class="btn btn-primary" onclick="saveExpense()">Save Expense</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>


<!-- ═══════ VENDOR MODAL ═════════════════════════════════════════ -->
<div class="modal fade" id="vendorModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="vendorModalTitle">Add Vendor</h5>
                <button type="button" class="btn-close" data-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="vendorId">
                <div class="mb-3">
                    <label class="form-label">Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="vendorName" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Aliases <small class="text-muted">(comma-separated for OCR matching)</small></label>
                    <input type="text" class="form-control" id="vendorAliases" placeholder="HD, Home Depot, HomeDepot">
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Default Accounting Category</label>
                        <select class="form-select" id="vendorAcctCategory"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Default GBP Category</label>
                        <select class="form-select" id="vendorGbpCategory"></select>
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="text" class="form-control" id="vendorPhone">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Website</label>
                        <input type="text" class="form-control" id="vendorWebsite">
                    </div>
                </div>
                <div class="mb-3 mt-3">
                    <label class="form-label">Notes</label>
                    <textarea class="form-control" id="vendorNotes" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveVendor()">Save Vendor</button>
            </div>
        </div>
    </div>
</div>


<script>
(function() {
    'use strict';

    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const CAN_EDIT = <?php echo $canEdit ? 'true' : 'false'; ?>;
    const CAN_SEND = <?php echo $canSend ? 'true' : 'false'; ?>;

    let categories = { accounting_categories: [], gbp_categories: [], payment_methods: [] };
    let currentPage = 1;
    let currentGpsLat = null;
    let currentGpsLng = null;

    // ── Init ─────────────────────────────────────────────────────
    async function init() {
        await loadCategories();
        loadExpenses();
        loadVendors();
        loadStats();
        loadSendLog();
        setupVendorSearch('expVendorSearch', 'vendorDropdown', 'expVendorId', 'expAcctCategory', 'expGbpCategory');
        setupVendorSearch('rvVendorSearch', 'rvVendorDropdown', 'rvVendorId', 'rvAcctCategory', 'rvGbpCategory');

        // Auto-calc totals
        document.getElementById('expAmount')?.addEventListener('input', function() { calcTotalFor('exp'); });
        document.getElementById('expTax')?.addEventListener('input', function() { calcTotalFor('exp'); });
        document.getElementById('rvAmount')?.addEventListener('input', function() { calcTotalFor('rv'); });
        document.getElementById('rvTax')?.addEventListener('input', function() { calcTotalFor('rv'); });

        // File inputs
        var fileInput = document.getElementById('receiptFileInput');
        if (fileInput) fileInput.addEventListener('change', handleReceiptFile);
        var galleryInput = document.getElementById('receiptGalleryInput');
        if (galleryInput) galleryInput.addEventListener('change', handleReceiptFile);

        // Gallery link click
        var galleryLink = document.querySelector('.mw-gallery-link');
        if (galleryLink) {
            galleryLink.addEventListener('click', function(e) {
                e.preventDefault();
                document.getElementById('receiptGalleryInput').click();
            });
        }

        // Try to get GPS silently
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                function(pos) {
                    currentGpsLat = pos.coords.latitude;
                    currentGpsLng = pos.coords.longitude;
                },
                function() { /* silently fail */ },
                { timeout: 10000, enableHighAccuracy: false }
            );
        }
    }

    function calcTotalFor(prefix) {
        var amt = parseFloat(document.getElementById(prefix + 'Amount').value) || 0;
        var tax = parseFloat(document.getElementById(prefix + 'Tax').value) || 0;
        document.getElementById(prefix + 'Total').value = (amt + tax).toFixed(2);
    }

    // ── Camera / Photo Capture ────────────────────────────────────
    window.triggerCamera = function() {
        document.getElementById('receiptFileInput').click();
    };

    function handleReceiptFile(e) {
        var file = e.target.files[0];
        if (!file) return;

        // Show spinner (desktop)
        document.getElementById('capturePrompt').style.display = 'none';
        document.getElementById('analyzeSpinner').style.display = 'block';

        // Show spinner (mobile)
        var mobileCap = document.getElementById('mobileCaptureArea');
        var mobileSpin = document.getElementById('mobileAnalyzeSpinner');
        if (mobileCap) mobileCap.style.display = 'none';
        if (mobileSpin) mobileSpin.style.display = 'flex';

        // Upload to receipt-intake API
        var formData = new FormData();
        formData.append('receipt_photo', file);
        formData.append('csrf_token', CSRF);
        if (currentGpsLat !== null) formData.append('lat', currentGpsLat);
        if (currentGpsLng !== null) formData.append('lng', currentGpsLng);

        fetch('/crm/api/receipt-intake.php', {
            method: 'POST',
            body: formData,
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) throw new Error(data.error || 'Upload failed');
            showReviewPanel(data, file);
        })
        .catch(function(err) {
            alert('Error: ' + err.message);
            resetCapture();
            mobileResetReview();
        });
    }

    function showReviewPanel(data, file) {
        // Hide capture card, show review panel
        document.getElementById('receiptCaptureCard').style.display = 'none';
        document.getElementById('receiptReviewPanel').style.display = 'block';

        // Show receipt image preview
        var reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('receiptPreviewImg').src = e.target.result;
        };
        reader.readAsDataURL(file);

        // Store media ID and OCR text
        document.getElementById('intakeMediaId').value = data.media_id;
        document.getElementById('intakeOcrText').value = data.ocr_text || '';

        // OCR status badge
        var statusEl = document.getElementById('ocrStatusBadge');
        if (data.ocr_available && data.ocr_text) {
            statusEl.innerHTML = '<span class="badge bg-success">OCR extracted text</span>';
        } else if (data.ocr_available && !data.ocr_text) {
            statusEl.innerHTML = '<span class="badge bg-warning text-dark">No text detected</span>';
        } else {
            statusEl.innerHTML = '<span class="badge bg-secondary">OCR not available — fill manually</span>';
        }

        // Pre-fill form from parsed + suggestions
        var p = data.parsed || {};
        var s = data.suggestions || {};

        // Date
        document.getElementById('rvDate').value = p.date || new Date().toISOString().slice(0, 10);
        setConfidence('confDate', p.date ? 70 : 0);

        // Vendor
        if (s.vendor_id) {
            document.getElementById('rvVendorId').value = s.vendor_id;
            document.getElementById('rvVendorSearch').value = s.vendor_name || '';
            setConfidence('confVendor', s.vendor_confidence || 0);
        } else if (p.vendor_hint) {
            document.getElementById('rvVendorSearch').value = p.vendor_hint;
            setConfidence('confVendor', 30);
        }

        // Total/Tax/Amount
        if (p.total) {
            document.getElementById('rvTotal').value = p.total;
            setConfidence('confTotal', 70);
        }
        if (p.tax) {
            document.getElementById('rvTax').value = p.tax;
            setConfidence('confTax', 60);
        }
        if (p.subtotal) {
            document.getElementById('rvAmount').value = p.subtotal;
        } else if (p.total && p.tax) {
            document.getElementById('rvAmount').value =
                (parseFloat(p.total) - parseFloat(p.tax)).toFixed(2);
        }

        // Categories
        if (s.accounting_category) {
            document.getElementById('rvAcctCategory').value = s.accounting_category;
            setConfidence('confCategory', s.category_confidence || 0);
        }
        if (s.gbp_category) {
            document.getElementById('rvGbpCategory').value = s.gbp_category;
        }

        // Payment
        if (p.payment_method) {
            document.getElementById('rvPayment').value = p.payment_method;
            setConfidence('confPayment', 60);
        }

        // Job
        if (s.suggested_job_id) {
            document.getElementById('rvJobId').value = s.suggested_job_id;
        }

        // Line items in review panel
        var lineItems = (p.line_items || []);
        var rvLiSection = document.getElementById('rvLineItemsSection');
        if (lineItems.length > 0 && rvLiSection) {
            document.getElementById('rvLineItemsCount').textContent = lineItems.length;
            document.getElementById('rvLineItemsList').querySelector('table').innerHTML =
                renderLineItemsTable(lineItems);
            document.getElementById('rvLineItemsList').style.display = 'block';
            rvLiSection.style.display = 'block';
        } else if (rvLiSection) {
            rvLiSection.style.display = 'none';
        }

        if (window.feather) feather.replace();

        // ── Mobile: populate mobile review panel ──
        var mobileSpinner = document.getElementById('mobileAnalyzeSpinner');
        var mobileReview = document.getElementById('mobileReviewPanel');
        var mobileCap = document.getElementById('mobileCaptureArea');
        if (mobileReview) {
            if (mobileSpinner) mobileSpinner.style.display = 'none';
            if (mobileCap) mobileCap.style.display = 'none';
            mobileReview.style.display = 'block';

            // Show image preview in mobile panel
            var imgWrap = document.getElementById('mobileReceiptWrap');
            if (imgWrap) imgWrap.style.display = 'block';
            var mobileReader = new FileReader();
            mobileReader.onload = function(ev) {
                var mImg = document.getElementById('mobileReceiptImg');
                if (mImg) mImg.src = ev.target.result;
            };
            mobileReader.readAsDataURL(file);

            // OCR badge
            var ocrBadge = document.getElementById('mobileOcrBadge');
            if (ocrBadge) {
                if (data.ocr_available && data.ocr_text) {
                    ocrBadge.innerHTML = '<span class="mw-mc-expense-badge-ocr">AI detected</span>';
                } else if (data.ocr_available) {
                    ocrBadge.innerHTML = '<span class="mw-mc-expense-badge-warn">No text found</span>';
                } else {
                    ocrBadge.innerHTML = '<span class="mw-mc-expense-badge-manual">Manual</span>';
                }
            }

            // Copy parsed values into mobile form fields
            var setMobileVal = function(id, val) { var el = document.getElementById(id); if (el && val) el.value = val; };
            setMobileVal('mobileRvVendor', s.vendor_name || p.vendor_hint || '');
            setMobileVal('mobileRvVendorId', s.vendor_id || '');
            setMobileVal('mobileRvDate', p.date || new Date().toISOString().slice(0, 10));
            setMobileVal('mobileRvTotal', p.total || '');
            setMobileVal('mobileRvTax', p.tax || '0');
            setMobileVal('mobileRvAmount', p.subtotal || (p.total && p.tax ? (parseFloat(p.total) - parseFloat(p.tax)).toFixed(2) : ''));
            setMobileVal('mobileRvCategory', s.accounting_category || '');
            setMobileVal('mobileRvPayment', p.payment_method || '');

            // Mobile confidence dots
            var setMobileConf = function(dotId, confidence) {
                var dot = document.getElementById(dotId);
                if (!dot) return;
                dot.className = 'mw-mc-expense-conf-dot';
                if (confidence >= 70) dot.classList.add('mw-mc-conf-high');
                else if (confidence >= 40) dot.classList.add('mw-mc-conf-medium');
                else if (confidence > 0) dot.classList.add('mw-mc-conf-low');
            };
            setMobileConf('mobileConfTotal', p.total ? 70 : 0);
            setMobileConf('mobileConfVendor', s.vendor_confidence || (p.vendor_hint ? 30 : 0));

            // Mobile line items
            var mLiSection = document.getElementById('mobileLineItemsSection');
            var mLiList = document.getElementById('mobileLineItemsList');
            var mLiCount = document.getElementById('mobileLineItemsCount');
            var lineItems = (p.line_items || []);
            if (lineItems.length > 0 && mLiSection) {
                mLiCount.textContent = lineItems.length;
                mLiList.innerHTML = lineItems.map(function(item) {
                    var amt = parseFloat(item.amount);
                    return '<div class="mw-mc-expense-line-item">' +
                        '<span class="mw-mc-expense-line-item-name">' + esc(item.name) + '</span>' +
                        '<span class="mw-mc-expense-line-item-amt' + (amt < 0 ? ' mw-mc-expense-line-item-neg' : '') + '">$' + amt.toFixed(2) + '</span>' +
                    '</div>';
                }).join('');
                mLiSection.style.display = 'block';
            } else if (mLiSection) {
                mLiSection.style.display = 'none';
            }

            // Store media/ocr refs for mobile save
            mobileReview.dataset.mediaId = data.media_id || '';
            mobileReview.dataset.ocrText = data.ocr_text || '';

            // Scroll to top of review
            var scrollArea = document.getElementById('mobileExpenseScrollArea');
            if (scrollArea) scrollArea.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // ── Check for duplicate receipts at upload time ──
        var dupTotal = p.total ? parseFloat(p.total) : null;
        var dupDate  = p.date || new Date().toISOString().slice(0, 10);
        var dupVendorName = s.vendor_name || p.vendor_hint || null;
        var dupVendorId   = s.vendor_id || null;
        if (dupTotal && dupTotal > 0) {
            checkDuplicates(dupVendorName, dupVendorId, dupTotal, dupDate, null, 'rv');
        }
    }

    function setConfidence(dotId, confidence) {
        var dot = document.getElementById(dotId);
        if (!dot) return;
        dot.className = 'mw-confidence-dot';
        if (confidence >= 70) {
            dot.classList.add('mw-conf-high');
            dot.title = 'High confidence (' + confidence + '%)';
        } else if (confidence >= 40) {
            dot.classList.add('mw-conf-medium');
            dot.title = 'Medium confidence (' + confidence + '%)';
        } else if (confidence > 0) {
            dot.classList.add('mw-conf-low');
            dot.title = 'Low confidence (' + confidence + '%)';
        }
    }

    // ── Duplicate Detection ─────────────────────────────────────
    // Returns a promise that resolves to { has_duplicates, duplicates }
    async function checkDuplicates(vendorName, vendorId, total, date, excludeId, prefix) {
        try {
            var params = new URLSearchParams({ action: 'check_duplicates' });
            if (vendorName) params.set('vendor_name', vendorName);
            if (vendorId) params.set('vendor_id', vendorId);
            if (total) params.set('total', total);
            if (date) params.set('expense_date', date);
            if (excludeId) params.set('exclude_id', excludeId);

            var r = await fetch('/crm/api/expenses.php?' + params);
            var d = await r.json();
            if (d.success && d.has_duplicates) {
                renderDuplicateWarning(d.duplicates, prefix);
                return d;
            } else {
                hideDuplicateWarning(prefix);
                return { has_duplicates: false, duplicates: [] };
            }
        } catch(e) {
            console.error('checkDuplicates', e);
            return { has_duplicates: false, duplicates: [] };
        }
    }

    function renderDuplicateWarning(duplicates, prefix) {
        var warningEl = document.getElementById(prefix + 'DuplicateWarning');
        var listEl = document.getElementById(prefix + 'DuplicateList');
        if (!warningEl || !listEl) return;

        listEl.innerHTML = duplicates.map(function(d) {
            var vendorDisplay = d.vendor_name || d.vendor_name_raw || 'Unknown vendor';
            return '<div class="mw-duplicate-warning-item">' +
                '<span class="mw-duplicate-warning-detail">' +
                    '<strong>' + esc(vendorDisplay) + '</strong> — $' + parseFloat(d.total).toFixed(2) +
                    ' on ' + d.expense_date +
                    ' <span class="badge bg-' + (d.status === 'forwarded' ? 'success' : d.status === 'approved' ? 'primary' : 'secondary') + '">' + d.status + '</span>' +
                '</span>' +
                '<button type="button" class="btn btn-sm btn-outline-primary mw-duplicate-warning-view" onclick="editExpense(' + d.id + ')" title="View this expense">' +
                    '<i data-feather="external-link" style="width:12px;height:12px;"></i> View' +
                '</button>' +
            '</div>';
        }).join('');

        warningEl.style.display = 'block';
        if (window.feather) feather.replace();
    }

    function hideDuplicateWarning(prefix) {
        var warningEl = document.getElementById(prefix + 'DuplicateWarning');
        if (warningEl) warningEl.style.display = 'none';
    }

    window.dismissDuplicateWarning = function(prefix) {
        hideDuplicateWarning(prefix);
    };

    // Prompt-based duplicate check for save time — returns true if OK to proceed
    async function confirmDuplicateCheck(vendorName, vendorId, total, date, excludeId) {
        try {
            var params = new URLSearchParams({ action: 'check_duplicates' });
            if (vendorName) params.set('vendor_name', vendorName);
            if (vendorId) params.set('vendor_id', vendorId);
            if (total) params.set('total', total);
            if (date) params.set('expense_date', date);
            if (excludeId) params.set('exclude_id', excludeId);

            var r = await fetch('/crm/api/expenses.php?' + params);
            var d = await r.json();

            if (d.success && d.has_duplicates && d.duplicates.length > 0) {
                var dup = d.duplicates[0];
                var vendorDisplay = dup.vendor_name || dup.vendor_name_raw || 'Unknown';
                var msg = 'Possible duplicate detected!\n\n' +
                    'Existing expense: ' + vendorDisplay + ' — $' + parseFloat(dup.total).toFixed(2) + ' on ' + dup.expense_date + ' (' + dup.status + ')';
                if (d.duplicates.length > 1) {
                    msg += '\n+ ' + (d.duplicates.length - 1) + ' more similar expense(s)';
                }
                msg += '\n\nSave anyway?';
                return confirm(msg);
            }
            return true; // No duplicates, proceed
        } catch(e) {
            console.error('confirmDuplicateCheck', e);
            return true; // On error, allow save
        }
    }

    window.resetCapture = function() {
        document.getElementById('receiptCaptureCard').style.display = 'block';
        document.getElementById('receiptReviewPanel').style.display = 'none';
        document.getElementById('capturePrompt').style.display = 'block';
        document.getElementById('analyzeSpinner').style.display = 'none';

        // Reset file inputs
        document.getElementById('receiptFileInput').value = '';
        document.getElementById('receiptGalleryInput').value = '';

        // Clear review form
        document.getElementById('intakeMediaId').value = '';
        document.getElementById('intakeOcrText').value = '';
        document.getElementById('rvVendorSearch').value = '';
        document.getElementById('rvVendorId').value = '';
        document.getElementById('rvDate').value = '';
        document.getElementById('rvAmount').value = '';
        document.getElementById('rvTax').value = '0';
        document.getElementById('rvTotal').value = '';
        document.getElementById('rvAcctCategory').value = '';
        document.getElementById('rvGbpCategory').value = '';
        document.getElementById('rvPayment').value = '';
        document.getElementById('rvJobId').value = '';
        document.getElementById('rvDescription').value = '';
        document.getElementById('rvNotes').value = '';

        // Clear confidence dots
        ['confVendor','confDate','confTax','confTotal','confCategory','confPayment'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) { el.className = 'mw-confidence-dot'; el.title = ''; }
        });

        // Clear line items
        var rvLiSection = document.getElementById('rvLineItemsSection');
        if (rvLiSection) rvLiSection.style.display = 'none';

        // Clear duplicate warning
        hideDuplicateWarning('rv');
    };

    // ── Save from Review Panel ────────────────────────────────────
    window.saveFromReview = function() { saveReviewExpense(false); };
    window.saveAndSend = function() { saveReviewExpense(true); };

    async function saveReviewExpense(andSend) {
        var data = {
            action: 'create',
            csrf_token: CSRF,
            expense_date: document.getElementById('rvDate').value,
            vendor_id: document.getElementById('rvVendorId').value || null,
            vendor_name_raw: document.getElementById('rvVendorSearch').value,
            payment_method: document.getElementById('rvPayment').value,
            amount: document.getElementById('rvAmount').value,
            tax_amount: document.getElementById('rvTax').value,
            total: document.getElementById('rvTotal').value,
            accounting_category: document.getElementById('rvAcctCategory').value,
            gbp_category: document.getElementById('rvGbpCategory').value,
            job_id: document.getElementById('rvJobId').value || null,
            description: document.getElementById('rvDescription').value,
            notes: document.getElementById('rvNotes').value,
            receipt_media_id: document.getElementById('intakeMediaId').value || null,
            receipt_lat: currentGpsLat,
            receipt_lng: currentGpsLng,
            raw_ocr_json: document.getElementById('intakeOcrText').value || null,
            status: 'draft',
        };

        if (!data.total || parseFloat(data.total) <= 0) {
            alert('Please enter a total amount');
            return;
        }

        // Duplicate check at save time
        var okToSave = await confirmDuplicateCheck(
            data.vendor_name_raw, data.vendor_id, data.total, data.expense_date, null
        );
        if (!okToSave) return;

        try {
            var r = await fetch('/crm/api/expenses.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });
            var d = await r.json();
            if (!d.success) throw new Error(d.error);

            // If "Save & Send", send receipt
            if (andSend && d.expense_id) {
                var sr = await fetch('/crm/api/receipt-send.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ csrf_token: CSRF, expense_id: d.expense_id }),
                });
                var sd = await sr.json();
                if (!sd.success) {
                    alert('Expense saved, but send failed: ' + (sd.error || sd.message));
                }
            }

            // Reset and refresh
            resetCapture();
            loadExpenses(currentPage);
            loadStats();
            if (andSend) loadSendLog();
        } catch(e) { alert('Error: ' + e.message); }
    }

    // ── Categories ───────────────────────────────────────────────
    async function loadCategories() {
        try {
            var r = await fetch('/crm/api/vendors.php?action=categories');
            var d = await r.json();
            if (d.success) {
                categories = d;
                populateCategoryDropdowns();
            }
        } catch(e) { console.error('loadCategories', e); }
    }

    function populateCategoryDropdowns() {
        var acctSelects = ['expAcctCategory', 'vendorAcctCategory', 'filterCategory', 'rvAcctCategory', 'mobileRvCategory'];
        var gbpSelects = ['expGbpCategory', 'vendorGbpCategory', 'rvGbpCategory'];

        acctSelects.forEach(function(id) {
            var el = document.getElementById(id);
            if (!el) return;
            var current = el.value;
            el.innerHTML = '<option value="">Select...</option>';
            categories.accounting_categories.forEach(function(c) {
                el.innerHTML += '<option value="' + esc(c) + '">' + esc(c) + '</option>';
            });
            el.value = current;
        });

        gbpSelects.forEach(function(id) {
            var el = document.getElementById(id);
            if (!el) return;
            var current = el.value;
            el.innerHTML = '<option value="">Select...</option>';
            categories.gbp_categories.forEach(function(c) {
                el.innerHTML += '<option value="' + esc(c) + '">' + esc(c) + '</option>';
            });
            el.value = current;
        });
    }

    // ── Expenses List ────────────────────────────────────────────
    window.loadExpenses = async function(page) {
        currentPage = page || 1;
        var params = new URLSearchParams({ action: 'list', page: currentPage });

        var dateFrom = document.getElementById('filterDateFrom').value;
        var dateTo = document.getElementById('filterDateTo').value;
        var cat = document.getElementById('filterCategory').value;
        var status = document.getElementById('filterStatus').value;
        var search = document.getElementById('filterSearch').value;

        if (dateFrom) params.set('date_from', dateFrom);
        if (dateTo) params.set('date_to', dateTo);
        if (cat) params.set('category', cat);
        if (status) params.set('status', status);
        if (search) params.set('search', search);

        try {
            var r = await fetch('/crm/api/expenses.php?' + params);
            var d = await r.json();
            if (!d.success) throw new Error(d.error);
            renderExpenses(d.expenses, d.total, d.page, d.pages, d.per_page);
            renderMobileExpenses(d.expenses);
        } catch(e) {
            document.getElementById('expensesTableBody').innerHTML =
                '<tr><td colspan="8" class="text-center py-4 text-danger">' + e.message + '</td></tr>';
        }
    };

    function renderExpenses(expenses, total, page, pages, perPage) {
        var tbody = document.getElementById('expensesTableBody');

        if (!expenses.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-muted">No expenses found</td></tr>';
            return;
        }

        tbody.innerHTML = expenses.map(function(e) {
            var vendorName = e.vendor_name || e.vendor_name_raw || '—';
            var statusBadge = {
                draft: 'bg-secondary',
                approved: 'bg-primary',
                forwarded: 'bg-success',
            }[e.status] || 'bg-secondary';

            var receiptIcon = e.receipt_path
                ? '<img src="' + esc(e.receipt_path) + '" class="mw-receipt-thumb" title="Click to view receipt" onclick="event.stopPropagation();openLightbox(\'' + esc(e.receipt_path) + '\')">'
                : '<span class="mw-receipt-no-img" title="No receipt"><i data-feather="image" style="width:14px;height:14px;opacity:0.3"></i></span>';

            var actions = '';
            if (CAN_SEND && e.receipt_media_id && !e.forwarded_to_accounting) {
                actions += '<button class="btn btn-sm btn-outline-success me-1" onclick="sendReceipt(' + e.id + ')" title="Send to Accounting"><i data-feather="send" style="width:14px;height:14px;"></i></button>';
            }
            if (e.forwarded_to_accounting) {
                actions += '<span class="badge bg-success" title="Sent to accounting">Sent</span> ';
            }
            if (CAN_EDIT) {
                actions += '<button class="btn btn-sm btn-outline-primary" onclick="editExpense(' + e.id + ')" title="Edit"><i data-feather="edit-2" style="width:14px;height:14px;"></i></button>';
            }

            return '<tr>' +
                '<td>' + e.expense_date + '</td>' +
                '<td>' + esc(vendorName) + '</td>' +
                '<td><small>' + esc(e.accounting_category || '—') + '</small></td>' +
                '<td class="text-end fw-bold">$' + parseFloat(e.total).toFixed(2) + '</td>' +
                '<td>' + (e.job_id ? '#' + e.job_id : '—') + '</td>' +
                '<td><span class="badge ' + statusBadge + '">' + e.status + '</span></td>' +
                '<td>' + receiptIcon + '</td>' +
                '<td class="text-end">' + actions + '</td>' +
            '</tr>';
        }).join('');

        if (window.feather) feather.replace();
        renderPagination(total, page, pages, perPage);
    }

    function renderPagination(total, page, pages, perPage) {
        var info = document.getElementById('paginationInfo');
        var links = document.getElementById('paginationLinks');
        var wrapper = document.getElementById('expensePagination');

        if (pages <= 1) {
            if (wrapper) wrapper.style.display = 'none';
            return;
        }

        if (wrapper) wrapper.style.display = 'flex';
        info.textContent = 'Showing ' + ((page-1)*perPage + 1) + '–' + Math.min(page*perPage, total) + ' of ' + total;

        var html = '';
        if (page > 1) html += '<li class="page-item"><a class="page-link" href="#" onclick="loadExpenses(' + (page-1) + ');return false;">&laquo;</a></li>';
        for (var i = Math.max(1, page-2); i <= Math.min(pages, page+2); i++) {
            html += '<li class="page-item ' + (i===page?'active':'') + '"><a class="page-link" href="#" onclick="loadExpenses(' + i + ');return false;">' + i + '</a></li>';
        }
        if (page < pages) html += '<li class="page-item"><a class="page-link" href="#" onclick="loadExpenses(' + (page+1) + ');return false;">&raquo;</a></li>';
        links.innerHTML = html;
    }

    // ── Stats ────────────────────────────────────────────────────
    async function loadStats() {
        try {
            var r = await fetch('/crm/api/expenses.php?action=stats');
            var d = await r.json();
            if (d.success) {
                document.getElementById('statTotal').textContent = '$' + parseFloat(d.stats.total_amount).toFixed(2);
                document.getElementById('statCount').textContent = d.stats.total_count;
                document.getElementById('statForwarded').textContent = d.stats.forwarded_count;
                document.getElementById('statDrafts').textContent = d.stats.draft_count;
                document.getElementById('expenseStats').style.display = 'flex';
                updateMobileStats(d.stats);
            }
        } catch(e) { console.error('loadStats', e); }
    }

    // ── Send Receipt ─────────────────────────────────────────────
    window.sendReceipt = async function(expenseId) {
        if (!confirm('Send this receipt to the accounting email?')) return;

        try {
            var r = await fetch('/crm/api/receipt-send.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: CSRF, expense_id: expenseId }),
            });
            var d = await r.json();
            alert(d.message || (d.success ? 'Sent!' : 'Failed'));
            if (d.success) {
                loadExpenses(currentPage);
                loadStats();
                loadSendLog();
            }
        } catch(e) { alert('Error: ' + e.message); }
    };

    // ── Edit Expense (modal) ─────────────────────────────────────
    window.editExpense = async function(id) {
        try {
            var r = await fetch('/crm/api/expenses.php?action=get&id=' + id);
            var d = await r.json();
            if (!d.success) throw new Error(d.error);

            var e = d.expense;
            document.getElementById('expenseModalTitle').textContent = 'Edit Expense';
            document.getElementById('expenseId').value = e.id;
            document.getElementById('expDate').value = e.expense_date;
            document.getElementById('expVendorSearch').value = e.vendor_name || e.vendor_name_raw || '';
            document.getElementById('expVendorId').value = e.vendor_id || '';
            document.getElementById('expPayment').value = e.payment_method || '';
            document.getElementById('expAmount').value = e.amount;
            document.getElementById('expTax').value = e.tax_amount;
            document.getElementById('expTotal').value = e.total;
            document.getElementById('expAcctCategory').value = e.accounting_category || '';
            document.getElementById('expGbpCategory').value = e.gbp_category || '';
            document.getElementById('expJobId').value = e.job_id || '';
            document.getElementById('expStatus').value = e.status || 'draft';
            document.getElementById('expDescription').value = e.description || '';
            document.getElementById('expNotes').value = e.notes || '';

            // Receipt image
            var receiptCol = document.getElementById('expReceiptCol');
            var formCol = document.getElementById('expFormCol');
            if (e.receipt_path) {
                document.getElementById('expReceiptImg').src = e.receipt_path;
                receiptCol.style.display = 'block';
                formCol.className = 'col-md-8';
            } else {
                receiptCol.style.display = 'none';
                formCol.className = 'col-12';
            }

            // Line items
            var lineItems = e.parsed_line_items || [];
            var liSection = document.getElementById('expLineItemsSection');
            if (lineItems.length > 0) {
                document.getElementById('expLineItemsCount').textContent = lineItems.length;
                document.getElementById('expLineItemsList').querySelector('table').innerHTML =
                    renderLineItemsTable(lineItems);
                document.getElementById('expLineItemsList').style.display = 'none';
                liSection.style.display = 'block';
            } else {
                liSection.style.display = 'none';
            }

            if (e.match_confidence > 0) {
                document.getElementById('matchConfidenceRow').style.display = 'block';
                document.getElementById('matchConfidenceBadge').textContent = e.match_confidence + '%';
            } else {
                document.getElementById('matchConfidenceRow').style.display = 'none';
            }

            if (window.feather) feather.replace();
            $('#expenseModal').modal('show');
        } catch(e) { alert('Error: ' + e.message); }
    };

    window.saveExpense = async function() {
        var id = document.getElementById('expenseId').value;
        var data = {
            action: id ? 'update' : 'create',
            csrf_token: CSRF,
            expense_date: document.getElementById('expDate').value,
            vendor_id: document.getElementById('expVendorId').value || null,
            vendor_name_raw: document.getElementById('expVendorSearch').value,
            payment_method: document.getElementById('expPayment').value,
            amount: document.getElementById('expAmount').value,
            tax_amount: document.getElementById('expTax').value,
            total: document.getElementById('expTotal').value,
            accounting_category: document.getElementById('expAcctCategory').value,
            gbp_category: document.getElementById('expGbpCategory').value,
            job_id: document.getElementById('expJobId').value || null,
            status: document.getElementById('expStatus').value,
            description: document.getElementById('expDescription').value,
            notes: document.getElementById('expNotes').value,
        };
        if (id) data.id = parseInt(id);

        try {
            var r = await fetch('/crm/api/expenses.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });
            var d = await r.json();
            if (!d.success) throw new Error(d.error);
            $('#expenseModal').modal('hide');
            loadExpenses(currentPage);
            loadStats();
        } catch(e) { alert('Error: ' + e.message); }
    };

    // ── Vendor Search Autocomplete ───────────────────────────────
    function setupVendorSearch(inputId, dropdownId, hiddenId, acctId, gbpId) {
        var input = document.getElementById(inputId);
        var dropdown = document.getElementById(dropdownId);
        if (!input || !dropdown) return;

        var debounce;
        input.addEventListener('input', function() {
            clearTimeout(debounce);
            var q = this.value.trim();
            if (q.length < 2) { dropdown.classList.remove('show'); return; }
            debounce = setTimeout(async function() {
                try {
                    var r = await fetch('/crm/api/vendors.php?action=search&q=' + encodeURIComponent(q));
                    var d = await r.json();
                    if (d.success && d.vendors.length) {
                        dropdown.innerHTML = d.vendors.map(function(v) {
                            return '<a class="dropdown-item" href="#" data-vid="' + v.id + '" data-vname="' + esc(v.name) + '" data-vacct="' + esc(v.default_accounting_category||'') + '" data-vgbp="' + esc(v.default_gbp_category||'') + '">' + esc(v.name) + '<br><small class="text-muted">' + esc(v.default_accounting_category||'') + '</small></a>';
                        }).join('');

                        // Attach click handlers
                        dropdown.querySelectorAll('.dropdown-item').forEach(function(item) {
                            item.addEventListener('click', function(e) {
                                e.preventDefault();
                                document.getElementById(hiddenId).value = this.dataset.vid;
                                document.getElementById(inputId).value = this.dataset.vname;
                                dropdown.classList.remove('show');
                                if (this.dataset.vacct && document.getElementById(acctId)) {
                                    document.getElementById(acctId).value = this.dataset.vacct;
                                }
                                if (this.dataset.vgbp && document.getElementById(gbpId)) {
                                    document.getElementById(gbpId).value = this.dataset.vgbp;
                                }
                            });
                        });

                        dropdown.classList.add('show');
                    } else {
                        dropdown.classList.remove('show');
                    }
                } catch(e) { dropdown.classList.remove('show'); }
            }, 300);
        });

        document.addEventListener('click', function(e) {
            if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.classList.remove('show');
            }
        });
    }

    // ── Vendors Tab ──────────────────────────────────────────────
    async function loadVendors() {
        try {
            var r = await fetch('/crm/api/vendors.php?action=list');
            var d = await r.json();
            if (!d.success) throw new Error(d.error);
            renderVendors(d.vendors);
        } catch(e) {
            document.getElementById('vendorsTableBody').innerHTML =
                '<tr><td colspan="7" class="text-center text-danger">' + e.message + '</td></tr>';
        }
    }

    function renderVendors(vendors) {
        var tbody = document.getElementById('vendorsTableBody');
        if (!vendors.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">No vendors yet</td></tr>';
            return;
        }

        tbody.innerHTML = vendors.map(function(v) {
            var editBtn = CAN_EDIT
                ? '<button class="btn btn-sm btn-outline-primary" onclick="editVendor(' + v.id + ')" title="Edit"><i data-feather="edit-2" style="width:14px;height:14px;"></i></button>'
                : '';

            return '<tr>' +
                '<td><strong>' + esc(v.name) + '</strong></td>' +
                '<td><small class="text-muted">' + esc(v.aliases || '—') + '</small></td>' +
                '<td>' + esc(v.default_accounting_category || '—') + '</td>' +
                '<td>' + esc(v.default_gbp_category || '—') + '</td>' +
                '<td>' + (v.location_count || 0) + '</td>' +
                '<td>$' + parseFloat(v.total_spent || 0).toFixed(2) + '</td>' +
                '<td>' + editBtn + '</td>' +
            '</tr>';
        }).join('');

        if (window.feather) feather.replace();
    }

    window.showVendorModal = function(data) {
        document.getElementById('vendorModalTitle').textContent = data ? 'Edit Vendor' : 'Add Vendor';
        document.getElementById('vendorId').value = data?.id || '';
        document.getElementById('vendorName').value = data?.name || '';
        document.getElementById('vendorAliases').value = data?.aliases || '';
        document.getElementById('vendorAcctCategory').value = data?.default_accounting_category || '';
        document.getElementById('vendorGbpCategory').value = data?.default_gbp_category || '';
        document.getElementById('vendorPhone').value = data?.phone || '';
        document.getElementById('vendorWebsite').value = data?.website || '';
        document.getElementById('vendorNotes').value = data?.notes || '';
        $('#vendorModal').modal('show');
    };

    window.editVendor = async function(id) {
        try {
            var r = await fetch('/crm/api/vendors.php?action=get&id=' + id);
            var d = await r.json();
            if (!d.success) throw new Error(d.error);
            showVendorModal(d.vendor);
        } catch(e) { alert('Error: ' + e.message); }
    };

    window.saveVendor = async function() {
        var id = document.getElementById('vendorId').value;
        var data = {
            action: id ? 'update' : 'create',
            csrf_token: CSRF,
            name: document.getElementById('vendorName').value,
            aliases: document.getElementById('vendorAliases').value,
            default_accounting_category: document.getElementById('vendorAcctCategory').value,
            default_gbp_category: document.getElementById('vendorGbpCategory').value,
            phone: document.getElementById('vendorPhone').value,
            website: document.getElementById('vendorWebsite').value,
            notes: document.getElementById('vendorNotes').value,
        };
        if (id) data.id = parseInt(id);

        try {
            var r = await fetch('/crm/api/vendors.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });
            var d = await r.json();
            if (!d.success) throw new Error(d.error);
            $('#vendorModal').modal('hide');
            loadVendors();
        } catch(e) { alert('Error: ' + e.message); }
    };

    // ── Send Log ─────────────────────────────────────────────────
    async function loadSendLog() {
        try {
            var r = await fetch('/crm/api/expenses.php?action=list&status=forwarded&per_page=10');
            var d = await r.json();
            if (!d.success) return;

            var tbody = document.getElementById('sendLogBody');
            if (!d.expenses.length) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No forwarded receipts yet</td></tr>';
                return;
            }

            tbody.innerHTML = d.expenses.map(function(e) {
                return '<tr>' +
                    '<td>' + (e.forwarded_at || e.expense_date) + '</td>' +
                    '<td>#' + e.id + ' — ' + esc(e.vendor_name || e.vendor_name_raw || 'Unknown') + '</td>' +
                    '<td class="text-muted"><small>Accounting inbox</small></td>' +
                    '<td><small>Receipt - ' + esc(e.vendor_name || 'Unknown') + ' - $' + parseFloat(e.total).toFixed(2) + '</small></td>' +
                    '<td><span class="badge bg-success">Sent</span></td>' +
                    '<td>' + esc(e.created_by_name || '—') + '</td>' +
                '</tr>';
            }).join('');
        } catch(e) { console.error('loadSendLog', e); }
    }

    // ── Filter helpers ───────────────────────────────────────────
    window.clearFilters = function() {
        document.getElementById('filterDateFrom').value = '';
        document.getElementById('filterDateTo').value = '';
        document.getElementById('filterCategory').value = '';
        document.getElementById('filterStatus').value = '';
        document.getElementById('filterSearch').value = '';
        loadExpenses();
    };

    // ── Mobile Expense Helpers ─────────────────────────────────
    window.mobileScrollToCapture = function() {
        var area = document.getElementById('mobileExpenseScrollArea');
        if (area) area.scrollTo({ top: 0, behavior: 'smooth' });
    };

    window.mobileScrollToExpenses = function() {
        var label = document.getElementById('mobileExpenseListLabel');
        if (label) label.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    window.mobileResetReview = function() {
        var cap = document.getElementById('mobileCaptureArea');
        var review = document.getElementById('mobileReviewPanel');
        var spinner = document.getElementById('mobileAnalyzeSpinner');
        if (review) { review.classList.add('mw-mc-expense-review-exit'); }
        setTimeout(function() {
            if (cap) cap.style.display = 'flex';
            if (review) { review.style.display = 'none'; review.classList.remove('mw-mc-expense-review-exit'); }
            if (spinner) spinner.style.display = 'none';
        }, 200);

        // Clear mobile form fields
        ['mobileRvVendor','mobileRvVendorId','mobileRvAmount','mobileRvTax','mobileRvTotal',
         'mobileRvDate','mobileRvCategory','mobileRvPayment','mobileRvDescription','mobileRvJobId'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.value = '';
        });

        // Clear confidence dots
        ['mobileConfTotal','mobileConfVendor'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.className = 'mw-mc-expense-conf-dot';
        });

        // Hide line items
        var liSection = document.getElementById('mobileLineItemsSection');
        if (liSection) liSection.style.display = 'none';

        // Reset receipt image expand
        var imgWrap = document.getElementById('mobileReceiptWrap');
        if (imgWrap) imgWrap.classList.remove('mw-mc-expense-review-img-expanded');

        // Also reset desktop capture area
        resetCapture();
    };

    // Manual entry — show review panel without a photo
    window.mobileManualEntry = function() {
        var cap = document.getElementById('mobileCaptureArea');
        var review = document.getElementById('mobileReviewPanel');
        if (cap) cap.style.display = 'none';
        if (review) {
            review.style.display = 'block';
            review.dataset.mediaId = '';
            review.dataset.ocrText = '';
        }
        // Hide image section for manual entry
        var imgWrap = document.getElementById('mobileReceiptWrap');
        if (imgWrap) imgWrap.style.display = 'none';
        // Set OCR badge
        var badge = document.getElementById('mobileOcrBadge');
        if (badge) badge.innerHTML = '<span class="mw-mc-expense-badge-manual">Manual entry</span>';
        // Set today's date
        var dateEl = document.getElementById('mobileRvDate');
        if (dateEl) dateEl.value = new Date().toISOString().slice(0, 10);
        // Focus total
        setTimeout(function() {
            var totalEl = document.getElementById('mobileRvTotal');
            if (totalEl) totalEl.focus();
        }, 300);
    };

    // Toggle receipt image expand/collapse
    window.toggleMobileReceiptExpand = function() {
        var wrap = document.getElementById('mobileReceiptWrap');
        if (wrap) wrap.classList.toggle('mw-mc-expense-review-img-expanded');
    };

    // Toggle mobile line items
    window.toggleMobileLineItems = function() {
        var list = document.getElementById('mobileLineItemsList');
        var chevron = document.getElementById('mobileLineItemsChevron');
        if (list) {
            var isOpen = list.style.display !== 'none';
            list.style.display = isOpen ? 'none' : 'block';
            if (chevron) chevron.style.transform = isOpen ? '' : 'rotate(180deg)';
        }
    };

    // Mobile vendor autocomplete
    (function() {
        var input = document.getElementById('mobileRvVendor');
        var dropdown = document.getElementById('mobileVendorDropdown');
        var hiddenId = 'mobileRvVendorId';
        if (!input || !dropdown) return;

        var debounce;
        input.addEventListener('input', function() {
            clearTimeout(debounce);
            var q = this.value.trim();
            document.getElementById(hiddenId).value = '';
            if (q.length < 2) { dropdown.style.display = 'none'; return; }
            debounce = setTimeout(async function() {
                try {
                    var r = await fetch('/crm/api/vendors.php?action=search&q=' + encodeURIComponent(q));
                    var d = await r.json();
                    if (d.success && d.vendors.length) {
                        dropdown.innerHTML = d.vendors.map(function(v) {
                            return '<div class="mw-mc-expense-vendor-option" data-vid="' + v.id + '" data-vname="' + esc(v.name) + '" data-vacct="' + esc(v.default_accounting_category||'') + '">' +
                                '<span class="mw-mc-expense-vendor-option-name">' + esc(v.name) + '</span>' +
                                (v.default_accounting_category ? '<span class="mw-mc-expense-vendor-option-cat">' + esc(v.default_accounting_category) + '</span>' : '') +
                            '</div>';
                        }).join('');
                        dropdown.querySelectorAll('.mw-mc-expense-vendor-option').forEach(function(opt) {
                            opt.addEventListener('click', function() {
                                document.getElementById(hiddenId).value = this.dataset.vid;
                                input.value = this.dataset.vname;
                                dropdown.style.display = 'none';
                                if (this.dataset.vacct) {
                                    var catEl = document.getElementById('mobileRvCategory');
                                    if (catEl) catEl.value = this.dataset.vacct;
                                }
                            });
                        });
                        dropdown.style.display = 'block';
                    } else {
                        dropdown.style.display = 'none';
                    }
                } catch(e) { dropdown.style.display = 'none'; }
            }, 250);
        });

        document.addEventListener('click', function(e) {
            if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });
    })();

    window.mobileSaveExpense = async function(andSend) {
        var review = document.getElementById('mobileReviewPanel');
        var saveBtn = review.querySelector('.mw-mc-expense-save-btn');
        var sendBtn = review.querySelector('.mw-mc-expense-send-btn');
        var activeBtn = andSend ? sendBtn : saveBtn;

        var data = {
            action: 'create',
            csrf_token: CSRF,
            expense_date: document.getElementById('mobileRvDate').value || new Date().toISOString().slice(0, 10),
            vendor_id: document.getElementById('mobileRvVendorId').value || null,
            vendor_name_raw: document.getElementById('mobileRvVendor').value,
            payment_method: document.getElementById('mobileRvPayment').value,
            amount: document.getElementById('mobileRvAmount').value,
            tax_amount: document.getElementById('mobileRvTax').value,
            total: document.getElementById('mobileRvTotal').value,
            accounting_category: document.getElementById('mobileRvCategory').value,
            job_id: document.getElementById('mobileRvJobId')?.value || null,
            description: document.getElementById('mobileRvDescription').value,
            receipt_media_id: review ? (review.dataset.mediaId || null) : null,
            receipt_lat: currentGpsLat,
            receipt_lng: currentGpsLng,
            raw_ocr_json: review ? (review.dataset.ocrText || null) : null,
            status: 'draft',
        };

        if (!data.total || parseFloat(data.total) <= 0) {
            mobileToast('Please enter a total amount', true);
            return;
        }

        // Duplicate check at save time
        var okToSave = await confirmDuplicateCheck(
            data.vendor_name_raw, data.vendor_id, data.total, data.expense_date, null
        );
        if (!okToSave) return;

        // Disable buttons and show loading
        if (activeBtn) { activeBtn.disabled = true; activeBtn.classList.add('mw-mc-expense-btn-loading'); }

        try {
            var r = await fetch('/crm/api/expenses.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });
            var d = await r.json();
            if (!d.success) throw new Error(d.error);

            if (andSend && d.expense_id) {
                var sr = await fetch('/crm/api/receipt-send.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ csrf_token: CSRF, expense_id: d.expense_id }),
                });
                var sd = await sr.json();
                if (!sd.success) {
                    mobileToast('Saved, but send failed', true);
                }
            }

            mobileToast(andSend ? 'Saved & sent!' : 'Expense saved!');
            setTimeout(function() {
                mobileResetReview();
                loadExpenses(1);
                loadStats();
                if (andSend) loadSendLog();
            }, 600);
        } catch(e) {
            mobileToast('Error: ' + e.message, true);
        } finally {
            if (activeBtn) { activeBtn.disabled = false; activeBtn.classList.remove('mw-mc-expense-btn-loading'); }
        }
    };

    function renderMobileExpenses(expenses) {
        var list = document.getElementById('mobileExpenseList');
        if (!list) return;

        if (!expenses || !expenses.length) {
            list.innerHTML = '<div class="mw-mc-empty">' +
                '<div class="mw-mc-empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.3"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></div>' +
                '<div class="mw-mc-empty-text">No expenses yet</div>' +
                '<div class="mw-mc-empty-sub">Snap a receipt to get started</div>' +
                '</div>';
            return;
        }

        // Group by date
        var grouped = {};
        expenses.slice(0, 30).forEach(function(e) {
            var d = e.expense_date || 'Unknown';
            if (!grouped[d]) grouped[d] = [];
            grouped[d].push(e);
        });

        var html = '';
        Object.keys(grouped).forEach(function(date) {
            // Format date label
            var dateObj = new Date(date + 'T12:00:00');
            var today = new Date();
            var yesterday = new Date(today);
            yesterday.setDate(yesterday.getDate() - 1);
            var dateLabel = date;
            if (dateObj.toDateString() === today.toDateString()) dateLabel = 'Today';
            else if (dateObj.toDateString() === yesterday.toDateString()) dateLabel = 'Yesterday';
            else dateLabel = dateObj.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });

            // Sum for this date group
            var dayTotal = grouped[date].reduce(function(sum, e) { return sum + parseFloat(e.total || 0); }, 0);

            html += '<div class="mw-mc-expense-date-group">' +
                '<div class="mw-mc-expense-date-header">' +
                    '<span class="mw-mc-expense-date-label">' + dateLabel + '</span>' +
                    '<span class="mw-mc-expense-date-total">$' + dayTotal.toFixed(2) + '</span>' +
                '</div>';

            grouped[date].forEach(function(e) {
                var vendorName = e.vendor_name || e.vendor_name_raw || 'Unknown';
                var statusIcon = '';
                if (e.status === 'forwarded' || e.forwarded_to_accounting) {
                    statusIcon = '<span class="mw-mc-expense-item-sent" title="Sent to accounting"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg></span>';
                } else if (e.status === 'approved') {
                    statusIcon = '<span class="mw-mc-expense-item-approved" title="Approved"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="16 10 11 16 8 13"/></svg></span>';
                }

                var thumbHtml = '';
                if (e.receipt_path) {
                    thumbHtml = '<div class="mw-mc-expense-item-thumb"><img src="' + esc(e.receipt_path) + '" alt="" loading="lazy"></div>';
                } else {
                    thumbHtml = '<div class="mw-mc-expense-item-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>';
                }

                var catLabel = e.accounting_category ? '<span class="mw-mc-expense-item-cat">' + esc(e.accounting_category) + '</span>' : '';

                html += '<div class="mw-mc-expense-item" onclick="editExpense(' + e.id + ')">' +
                    thumbHtml +
                    '<div class="mw-mc-expense-item-left">' +
                        '<div class="mw-mc-expense-item-vendor">' + esc(vendorName) + '</div>' +
                        '<div class="mw-mc-expense-item-meta">' + catLabel +
                            (e.job_id ? '<span class="mw-mc-expense-item-job">Job #' + e.job_id + '</span>' : '') +
                        '</div>' +
                    '</div>' +
                    '<div class="mw-mc-expense-item-right">' +
                        '<div class="mw-mc-expense-item-amount">$' + parseFloat(e.total).toFixed(2) + '</div>' +
                        statusIcon +
                    '</div>' +
                '</div>';
            });

            html += '</div>';
        });

        list.innerHTML = html;
    }

    // Mobile auto-calc total (amount + tax = total)
    (function() {
        var mAmtEl = document.getElementById('mobileRvAmount');
        var mTaxEl = document.getElementById('mobileRvTax');
        if (mAmtEl && mTaxEl) {
            function mCalc() {
                var amt = parseFloat(mAmtEl.value) || 0;
                var tax = parseFloat(mTaxEl.value) || 0;
                var totalEl = document.getElementById('mobileRvTotal');
                if (totalEl) totalEl.value = (amt + tax).toFixed(2);
            }
            mAmtEl.addEventListener('input', mCalc);
            mTaxEl.addEventListener('input', mCalc);
        }
    })();

    function mobileToast(msg, isError) {
        var toast = document.getElementById('mobileExpenseToast');
        var textEl = document.getElementById('mobileExpenseToastText');
        if (!toast || !textEl) return;
        textEl.textContent = msg;
        toast.className = 'mw-mc-expense-toast mw-mc-expense-toast-show' + (isError ? ' mw-mc-expense-toast-error' : '');
        clearTimeout(toast._hideTimer);
        toast._hideTimer = setTimeout(function() {
            toast.classList.remove('mw-mc-expense-toast-show');
        }, 2800);
    }

    // Update mobile stats from the stats API
    function updateMobileStats(stats) {
        var el;
        el = document.getElementById('mobileStatTotal');
        if (el && stats.total_amount !== undefined) el.textContent = '$' + parseFloat(stats.total_amount).toFixed(0);
        el = document.getElementById('mobileStatCount');
        if (el && stats.total_count !== undefined) el.textContent = stats.total_count;
        el = document.getElementById('mobileStatDrafts');
        if (el && stats.draft_count !== undefined) el.textContent = stats.draft_count;
        el = document.getElementById('mobileStatSent');
        if (el && stats.forwarded_count !== undefined) el.textContent = stats.forwarded_count;
    }

    // ── Lightbox ─────────────────────────────────────────────────
    window.openLightbox = function(src) {
        if (!src) return;
        var overlay = document.createElement('div');
        overlay.className = 'mw-receipt-lightbox';
        overlay.innerHTML = '<span class="mw-receipt-lightbox-close">&times;</span>' +
            '<img src="' + src + '" alt="Receipt">';
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay || e.target.classList.contains('mw-receipt-lightbox-close')) {
                overlay.remove();
            }
        });
        document.body.appendChild(overlay);
        var escHandler = function(e) {
            if (e.key === 'Escape') { overlay.remove(); document.removeEventListener('keydown', escHandler); }
        };
        document.addEventListener('keydown', escHandler);
    };

    // ── Line Items ──────────────────────────────────────────────
    window.toggleLineItems = function(prefix) {
        var list = document.getElementById(prefix + 'LineItemsList');
        if (list) list.style.display = list.style.display === 'none' ? 'block' : 'none';
    };

    function renderLineItemsTable(items) {
        if (!items || !items.length) return '';
        return items.map(function(item) {
            var amount = parseFloat(item.amount);
            var amountClass = amount < 0 ? 'text-danger' : '';
            return '<tr><td>' + esc(item.name) + '</td><td class="' + amountClass + '">$' + amount.toFixed(2) + '</td></tr>';
        }).join('');
    }

    // ── Utility ──────────────────────────────────────────────────
    function esc(s) {
        if (!s) return '';
        var div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }

    // Go
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>

<?php include 'includes/appstack_footer.php'; ?>
