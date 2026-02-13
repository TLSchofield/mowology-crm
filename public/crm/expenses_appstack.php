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
$extraHead = '<meta name="csrf-token" content="' . htmlspecialchars($csrfToken) . '">';
?>
<?php include 'includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center">
    <h1 class="h3 mb-0">Expenses</h1>
</div>

<?php if ($canEdit): ?>
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
            <input type="file" id="receiptFileInput" accept="image/*" capture="environment" class="d-none">
            <input type="file" id="receiptGalleryInput" accept="image/*" class="d-none">
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

        // Show spinner
        document.getElementById('capturePrompt').style.display = 'none';
        document.getElementById('analyzeSpinner').style.display = 'block';

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

        if (window.feather) feather.replace();
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
        var acctSelects = ['expAcctCategory', 'vendorAcctCategory', 'filterCategory', 'rvAcctCategory'];
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

            var receiptIcon = e.receipt_media_id
                ? '<span class="text-success" title="Receipt attached"><i data-feather="image" style="width:14px;height:14px;"></i></span>'
                : '<span class="text-muted" title="No receipt"><i data-feather="image" style="width:14px;height:14px;opacity:0.3"></i></span>';

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

            if (e.match_confidence > 0) {
                document.getElementById('matchConfidenceRow').style.display = 'block';
                document.getElementById('matchConfidenceBadge').textContent = e.match_confidence + '%';
            } else {
                document.getElementById('matchConfidenceRow').style.display = 'none';
            }

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
