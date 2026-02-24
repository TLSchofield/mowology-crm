<?php
require_once __DIR__ . '/../loginAuth/auth.php';
require_once 'includes/error-handler.php';

requireLogin();
$user = getCurrentUser();
requirePermission('settings.edit');

// Initialize error handler
$errorHandler = new CRMErrorHandler('Settings', $_SERVER['REQUEST_METHOD']);
$GLOBALS['crm_error_handler'] = $errorHandler;

$pageTitle = 'Business Settings';
$activePage = 'settings';
$csrfToken = generateCSRFToken();
$extraHead = '<meta name="csrf-token" content="' . htmlspecialchars($csrfToken) . '">';
?>
<?php include 'includes/appstack_head.php'; ?>

<!-- Session Alert Display -->
<?php if (isset($_SESSION['alert'])):
    $alert = $_SESSION['alert'];
    $alertClass = [
        'error' => 'alert-danger',
        'warning' => 'alert-warning',
        'success' => 'alert-success',
        'info' => 'alert-info'
    ][$alert['type']] ?? 'alert-info';
?>
    <div class="alert <?php echo $alertClass; ?> alert-dismissible fade show" role="alert">
        <strong><?php echo ucfirst($alert['type']); ?>:</strong> <?php echo h($alert['message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['alert']); ?>
<?php endif; ?>

<div class="mw-page-header">
    <h1 class="h3">Business Settings</h1>
</div>

<!-- Settings Tabs -->
<ul class="mw-settings-nav nav nav-tabs mb-3" role="tablist">
    <li class="nav-item">
        <a class="nav-link active" id="company-tab" data-toggle="tab" href="#company" role="tab">Company Info</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="branding-tab" data-toggle="tab" href="#branding" role="tab">Branding</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="invoice-tab" data-toggle="tab" href="#invoice" role="tab">Invoices</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="email-tab" data-toggle="tab" href="#email" role="tab">Email</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="messages-tab" data-toggle="tab" href="#messages" role="tab">Messages</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="receipts-tab" data-toggle="tab" href="#receipts" role="tab">Receipt Forwarding</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="holidays-tab" data-toggle="tab" href="#holidays" role="tab">Holidays</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="tags-tab" data-toggle="tab" href="#tags" role="tab">Tags</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="service-types-tab" data-toggle="tab" href="#service-types" role="tab">Service Types</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="measurement-types-tab" data-toggle="tab" href="#measurement-types" role="tab">Measurement Types</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="database-tab" data-toggle="tab" href="#database" role="tab">Database / Migrations</a>
    </li>
</ul>

<!-- Loading State -->
<div id="settingsLoading" class="card text-center py-5">
    <div class="spinner-border text-primary" role="status"></div>
    <p class="mt-3">Loading settings...</p>
</div>

<!-- Messages -->
<div id="settingsError" class="alert alert-danger" style="display: none;"></div>
<div id="settingsSuccess" class="alert alert-success" style="display: none;"></div>

<!-- Settings Form -->
<form id="settingsForm" style="display: none;">
    <div class="tab-content">

        <!-- Company Info Tab -->
        <div class="tab-pane fade show active" id="company" role="tabpanel">
            <div class="card">
                <div class="card-header"><h5 class="card-title">Company Information</h5></div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="company_name" class="form-label">Company Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="company_name" required maxlength="255">
                        </div>
                        <div class="col-md-6">
                            <label for="company_phone" class="form-label">Phone</label>
                            <input type="tel" class="form-control" id="company_phone" maxlength="20">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="company_email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="company_email" maxlength="255">
                        </div>
                        <div class="col-md-6">
                            <label for="company_website" class="form-label">Website</label>
                            <input type="url" class="form-control" id="company_website" maxlength="255">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="company_address" class="form-label">Address</label>
                        <textarea class="form-control" id="company_address" rows="3" maxlength="1000"></textarea>
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h5 class="card-title">Legal Information</h5></div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="gst_registration" class="form-label">GST # (e.g., R123456789)</label>
                            <input type="text" class="form-control" id="gst_registration" maxlength="50">
                        </div>
                        <div class="col-md-6">
                            <label for="pst_registration" class="form-label">PST #</label>
                            <input type="text" class="form-control" id="pst_registration" maxlength="50">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="business_license" class="form-label">Business License</label>
                        <input type="text" class="form-control" id="business_license" maxlength="100">
                    </div>
                </div>
            </div>
        </div>

        <!-- Branding Tab -->
        <div class="tab-pane fade" id="branding" role="tabpanel">
            <div class="card">
                <div class="card-header"><h5 class="card-title">Logo</h5></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="logo_path" class="form-label">Logo Path (e.g., /uploads/logo.png)</label>
                        <input type="text" class="form-control" id="logo_path" maxlength="255" placeholder="/assets/images/logo.png">
                    </div>
                    <div class="mb-3">
                        <label for="logo_alt_text" class="form-label">Alt Text</label>
                        <input type="text" class="form-control" id="logo_alt_text" maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label>Preview</label>
                        <div id="logoPreview" class="card p-3 bg-light text-center" style="min-height: 150px; display: flex; align-items: center; justify-content: center;">
                            <span class="text-muted">No logo configured</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h5 class="card-title">Brand Colors</h5></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="brand_color_primary" class="form-label">Primary Color</label>
                            <div class="input-group">
                                <input type="color" class="form-control form-control-color" id="brand_color_primary" style="max-width: 60px;">
                                <input type="text" class="form-control" id="brand_color_primary_text" readonly maxlength="7">
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="brand_color_secondary" class="form-label">Secondary Color</label>
                            <div class="input-group">
                                <input type="color" class="form-control form-control-color" id="brand_color_secondary" style="max-width: 60px;">
                                <input type="text" class="form-control" id="brand_color_secondary_text" readonly maxlength="7">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Invoice Tab -->
        <div class="tab-pane fade" id="invoice" role="tabpanel">
            <div class="card">
                <div class="card-header"><h5 class="card-title">Invoice Settings</h5></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="invoice_message_header" class="form-label">Header Message</label>
                        <textarea class="form-control" id="invoice_message_header" rows="3" maxlength="1000"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="invoice_terms_text" class="form-label">Terms & Conditions</label>
                        <textarea class="form-control" id="invoice_terms_text" rows="4" maxlength="2000"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="invoice_payment_instructions" class="form-label">Payment Instructions</label>
                        <textarea class="form-control" id="invoice_payment_instructions" rows="4" maxlength="2000"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="invoice_footer_text" class="form-label">Footer Text</label>
                        <textarea class="form-control" id="invoice_footer_text" rows="3" maxlength="1000"></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Email Tab -->
        <div class="tab-pane fade" id="email" role="tabpanel">
            <div class="card">
                <div class="card-header"><h5 class="card-title">Email Configuration</h5></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="email_signature_text" class="form-label">Signature</label>
                        <textarea class="form-control" id="email_signature_text" rows="5" maxlength="2000" placeholder="Name, Title, Company, Phone..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="email_footer_html" class="form-label">Footer HTML</label>
                        <textarea class="form-control font-monospace" id="email_footer_html" rows="6" maxlength="5000" placeholder="&lt;p&gt;Footer...&lt;/p&gt;"></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Messages Tab -->
        <div class="tab-pane fade" id="messages" role="tabpanel">
            <div class="card">
                <div class="card-header"><h5 class="card-title">Quote Messages</h5></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="quote_message_header" class="form-label">Header</label>
                        <textarea class="form-control" id="quote_message_header" rows="3" maxlength="1000"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="quote_message_footer" class="form-label">Footer</label>
                        <textarea class="form-control" id="quote_message_footer" rows="3" maxlength="1000"></textarea>
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h5 class="card-title">Receipt Messages</h5></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="receipt_message_header" class="form-label">Header</label>
                        <textarea class="form-control" id="receipt_message_header" rows="3" maxlength="1000"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="receipt_message_footer" class="form-label">Footer</label>
                        <textarea class="form-control" id="receipt_message_footer" rows="3" maxlength="1000"></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Receipt Forwarding Tab -->
        <div class="tab-pane fade" id="receipts" role="tabpanel">
            <div class="card">
                <div class="card-header"><h5 class="card-title">Receipt Forwarding</h5></div>
                <div class="card-body">
                    <p class="text-muted mb-4">Forward expense receipts to your accounting software (e.g., QuickBooks) via email.</p>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="receipt_forwarding_enabled" class="form-label">Receipt Forwarding</label>
                            <select class="form-control" id="receipt_forwarding_enabled">
                                <option value="0">Disabled</option>
                                <option value="1">Enabled</option>
                            </select>
                            <small class="form-text text-muted">Enable to allow sending receipts to accounting.</small>
                        </div>
                        <div class="col-md-6">
                            <label for="receipt_auto_send" class="form-label">Auto-Send on Upload</label>
                            <select class="form-control" id="receipt_auto_send">
                                <option value="0">Off — manual send only</option>
                                <option value="1">On — auto-send when expense is created with receipt</option>
                            </select>
                            <small class="form-text text-muted">Automatically forward receipts when a new expense is saved.</small>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="receipt_accounting_email" class="form-label">Accounting Email Address <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="receipt_accounting_email" maxlength="255" placeholder="receipts@quickbooks.intuit.com">
                            <small class="form-text text-muted">The email address your accounting software monitors for receipts.</small>
                        </div>
                        <div class="col-md-6">
                            <label for="receipt_from_name" class="form-label">From Name</label>
                            <input type="text" class="form-control" id="receipt_from_name" maxlength="255" placeholder="Mowology Receipts">
                            <small class="form-text text-muted">Sender name on forwarded receipt emails.</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h5 class="card-title">Test Connection</h5></div>
                <div class="card-body">
                    <p class="text-muted mb-3">Send a test email to verify your accounting email receives receipts correctly.</p>
                    <button type="button" class="btn btn-outline-primary" id="btnTestReceiptEmail" disabled>
                        <i data-feather="send" style="width:16px;height:16px;"></i> Send Test Email
                    </button>
                    <div id="receiptTestResult" class="mt-2" style="display:none;"></div>
                </div>
            </div>
        </div>

        <!-- Holidays Tab -->
        <div class="tab-pane fade" id="holidays" role="tabpanel">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Company Holidays</h5>
                    <div>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnSeedHolidays">
                            <i data-feather="download" style="width:14px;height:14px;"></i> Load BC Stat Holidays
                        </button>
                        <button type="button" class="btn btn-primary btn-sm ml-2" id="btnAddHoliday">
                            <i data-feather="plus" style="width:14px;height:14px;"></i> Add Holiday
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">
                        Scheduled visits that land on a holiday are automatically moved to the last working day before the holiday.
                        Per-plan blackout dates still skip visits entirely.
                    </p>
                    <div id="holidaysLoading" class="text-center py-3" style="display:none;">
                        <div class="spinner-border spinner-border-sm text-primary"></div> Loading holidays...
                    </div>
                    <table class="table table-hover mb-0" id="holidaysTable" style="display:none;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Name</th>
                                <th>Annual</th>
                                <th>Active</th>
                                <th style="width:80px;"></th>
                            </tr>
                        </thead>
                        <tbody id="holidaysTableBody"></tbody>
                    </table>
                    <div id="holidaysEmpty" class="text-center py-4 text-muted" style="display:none;">
                        No holidays configured. Click "Load BC Stat Holidays" to get started.
                    </div>
                </div>
            </div>

            <!-- Add/Edit Holiday Form -->
            <div class="card mt-3" id="holidayFormCard" style="display:none;">
                <div class="card-header"><h5 class="card-title mb-0" id="holidayFormTitle">Add Holiday</h5></div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="holiday_date" class="form-label">Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="holiday_date" required>
                        </div>
                        <div class="col-md-4">
                            <label for="holiday_name" class="form-label">Holiday Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="holiday_name" maxlength="100" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Annual</label>
                            <select class="form-control" id="holiday_is_annual">
                                <option value="0">No</option>
                                <option value="1">Yes</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="button" class="btn btn-primary mr-2" id="btnSaveHoliday">Save</button>
                            <button type="button" class="btn btn-secondary" id="btnCancelHoliday">Cancel</button>
                        </div>
                    </div>
                    <input type="hidden" id="holiday_edit_id" value="0">
                </div>
            </div>
        </div>

        <!-- Tags Tab -->
        <div class="tab-pane fade" id="tags" role="tabpanel">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Tag Vocabulary</h5>
                    <div class="d-flex align-items-center">
                        <select class="form-control form-control-sm mr-2" id="tagGroupFilter" style="width:180px;">
                            <option value="">All Groups</option>
                            <option value="property_access">Property Access</option>
                            <option value="property_warning">Property Warning</option>
                            <option value="service">Service</option>
                            <option value="condition">Condition</option>
                            <option value="media">Media</option>
                        </select>
                        <button type="button" class="btn btn-primary btn-sm" id="btnAddTag">
                            <i data-feather="plus" style="width:14px;height:14px;"></i> Add Tag
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div id="tagsLoading" class="text-center py-4" style="display:none;">
                        <div class="spinner-border spinner-border-sm text-primary"></div> Loading tags...
                    </div>
                    <table class="table table-hover mb-0" id="tagsTable" style="display:none;">
                        <thead>
                            <tr>
                                <th style="width:40px;"></th>
                                <th>Label</th>
                                <th>Key</th>
                                <th>Group</th>
                                <th>Icon</th>
                                <th style="width:60px;">Card</th>
                                <th style="width:60px;">Value</th>
                                <th style="width:60px;">Used</th>
                                <th style="width:90px;"></th>
                            </tr>
                        </thead>
                        <tbody id="tagsTableBody"></tbody>
                    </table>
                    <div id="tagsEmpty" class="text-center py-4 text-muted" style="display:none;">
                        No tags found. Click "Add Tag" to create one.
                    </div>
                </div>
            </div>

            <!-- Add/Edit Tag Form -->
            <div class="card mt-3" id="tagFormCard" style="display:none;">
                <div class="card-header"><h5 class="card-title mb-0" id="tagFormTitle">Add Tag</h5></div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="tag_label" class="form-label">Label <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="tag_label" maxlength="100" required placeholder="e.g. Dog Warning">
                        </div>
                        <div class="col-md-4">
                            <label for="tag_key" class="form-label">Key <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="tag_key" maxlength="100" required placeholder="e.g. dog_warning">
                            <small class="form-text text-muted">Lowercase, underscores only. Auto-generated from label.</small>
                        </div>
                        <div class="col-md-4">
                            <label for="tag_group" class="form-label">Group <span class="text-danger">*</span></label>
                            <select class="form-control" id="tag_group" required>
                                <option value="">Select group...</option>
                                <option value="property_access">Property Access</option>
                                <option value="property_warning">Property Warning</option>
                                <option value="service">Service</option>
                                <option value="condition">Condition</option>
                                <option value="media">Media</option>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label for="tag_color" class="form-label">Color</label>
                            <div class="d-flex align-items-center">
                                <input type="color" class="form-control form-control-color mr-2" id="tag_color" value="#6B7280" style="width:50px;height:38px;">
                                <input type="text" class="form-control" id="tag_color_hex" value="#6B7280" maxlength="7" style="width:90px;">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label for="tag_icon" class="form-label">Icon</label>
                            <div class="d-flex align-items-center">
                                <input type="text" class="form-control" id="tag_icon" maxlength="30" placeholder="e.g. key, alert-triangle">
                                <span id="tagIconPreview" class="ml-2" style="min-width:24px;"></span>
                            </div>
                            <small class="form-text text-muted">Feather icon name</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Show on Card</label>
                            <select class="form-control" id="tag_show_on_card">
                                <option value="0">No</option>
                                <option value="1">Yes</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Has Custom Value</label>
                            <select class="form-control" id="tag_has_value">
                                <option value="0">No</option>
                                <option value="1">Yes</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <button type="button" class="btn btn-primary mr-2" id="btnSaveTag">Save</button>
                        <button type="button" class="btn btn-secondary" id="btnCancelTag">Cancel</button>
                    </div>
                    <input type="hidden" id="tag_edit_id" value="0">
                </div>
            </div>
        </div>

        <!-- Service Types Tab -->
        <div class="tab-pane fade" id="service-types" role="tabpanel">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Service Types</h5>
                    <button type="button" class="btn btn-primary btn-sm" id="btnAddServiceType">
                        <i data-feather="plus" style="width:14px;height:14px;"></i> Add Service Type
                    </button>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">
                        Manage the service types used throughout the CRM — job plans, schedule filters, job cards, and area measurement.
                        <strong>Show in JobFlow</strong> controls whether the type appears on the public quote form (note: also update <code>validators.php</code> to accept new quote form types).
                    </p>
                    <div id="stLoading" class="text-center py-3" style="display:none;">
                        <div class="spinner-border spinner-border-sm text-primary"></div> Loading...
                    </div>
                    <table class="table table-hover mb-0" id="stTable" style="display:none;">
                        <thead>
                            <tr>
                                <th style="width:40px;">Color</th>
                                <th>Label</th>
                                <th>Slug</th>
                                <th style="width:90px;">JobFlow</th>
                                <th style="width:80px;">Active</th>
                                <th style="width:100px;"></th>
                            </tr>
                        </thead>
                        <tbody id="stTableBody"></tbody>
                    </table>
                    <div id="stEmpty" class="text-center py-4 text-muted" style="display:none;">
                        No service types found.
                    </div>
                </div>
            </div>

            <!-- Add/Edit Service Type Form -->
            <div class="card mt-3" id="stFormCard" style="display:none;">
                <div class="card-header"><h5 class="card-title mb-0" id="stFormTitle">Add Service Type</h5></div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="st_label" class="form-label">Label <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="st_label" maxlength="100" required placeholder="e.g. Lawn Care">
                        </div>
                        <div class="col-md-3">
                            <label for="st_slug" class="form-label">Slug <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="st_slug" maxlength="100" placeholder="e.g. lawn_care">
                            <small class="form-text text-muted">Lowercase, underscores only. Auto-generated.</small>
                        </div>
                        <div class="col-md-2">
                            <label for="st_icon" class="form-label">Icon</label>
                            <input type="text" class="form-control" id="st_icon" maxlength="50" placeholder="e.g. scissors">
                            <small class="form-text text-muted">Feather icon name</small>
                        </div>
                        <div class="col-md-3">
                            <label for="st_color" class="form-label">Color</label>
                            <div class="d-flex align-items-center">
                                <input type="color" class="form-control form-control-color mr-2" id="st_color" value="#455A64" style="width:50px;height:38px;">
                                <input type="text" class="form-control" id="st_color_hex" value="#455A64" maxlength="7" style="width:90px;">
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Show in JobFlow</label>
                            <select class="form-control" id="st_show_in_jobflow">
                                <option value="0">No</option>
                                <option value="1">Yes</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Active</label>
                            <select class="form-control" id="st_is_active">
                                <option value="1">Yes</option>
                                <option value="0">No</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <button type="button" class="btn btn-primary mr-2" id="btnSaveServiceType">Save</button>
                        <button type="button" class="btn btn-secondary" id="btnCancelServiceType">Cancel</button>
                    </div>
                    <input type="hidden" id="st_edit_id" value="0">
                </div>
            </div>
        </div>

        <!-- Measurement Types Tab -->
        <div class="tab-pane fade" id="measurement-types" role="tabpanel">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Measurement Groups &amp; Types</h5>
                    <button type="button" class="btn btn-primary btn-sm" id="btnAddMeasGroup">
                        <i data-feather="plus" style="width:14px;height:14px;"></i> Add Group
                    </button>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">
                        Measurement groups organize property area types (e.g., Lawn Area, Hard Surface) for pricing and quoting.
                        Each group contains one or more measurement types that are assigned when drawing on the map.
                    </p>
                    <div id="mgLoading" class="text-center py-3" style="display:none;">
                        <div class="spinner-border spinner-border-sm text-primary"></div> Loading...
                    </div>
                    <div id="mgList"></div>
                    <div id="mgEmpty" class="text-center py-4 text-muted" style="display:none;">
                        No measurement groups found.
                    </div>
                </div>
            </div>

            <!-- Add Group Form -->
            <div class="card mt-3" id="mgFormCard" style="display:none;">
                <div class="card-header"><h5 class="card-title mb-0">Add Measurement Group</h5></div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="mg_label" class="form-label">Group Label <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="mg_label" maxlength="100" placeholder="e.g. Lawn Area">
                        </div>
                        <div class="col-md-3">
                            <label for="mg_key" class="form-label">Group Key <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="mg_key" maxlength="50" placeholder="e.g. lawn_area">
                            <small class="form-text text-muted">Auto-generated. Lowercase, underscores.</small>
                        </div>
                        <div class="col-md-3">
                            <label for="mg_unit" class="form-label">Unit</label>
                            <select class="form-control" id="mg_unit">
                                <option value="sqft">Square Feet (sqft)</option>
                                <option value="linear_ft">Linear Feet (linear_ft)</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="button" class="btn btn-primary mr-2" id="btnSaveMeasGroup">Save</button>
                            <button type="button" class="btn btn-secondary" id="btnCancelMeasGroup">Cancel</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Database / Migrations Tab -->
        <div class="tab-pane fade" id="database" role="tabpanel">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Database</h5></div>
                <div class="card-body">
                    <p class="text-muted mb-3">
                        Schema browsing, migrations, and drift detection have moved to the dedicated Database Manager.
                    </p>
                    <a href="/crm/database_appstack.php" class="btn btn-primary">
                        <i data-feather="database" style="width:16px;height:16px;"></i> Open Database Manager
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Save Button -->
    <div class="mt-4 mb-3">
        <button type="submit" class="btn btn-primary btn-lg">Save Settings</button>
        <a href="dashboard_appstack.php" class="btn btn-secondary btn-lg">Cancel</a>
    </div>
</form>

<script src="js/business-settings.js?v=2"></script>

<script>
// ─────────────────────────────────────────────────────────────────────────────
// SERVICE TYPES — inline CRUD
// ─────────────────────────────────────────────────────────────────────────────
(function () {
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    let stData = [];
    let stLoaded = false;

    function apiFetch(url, opts) {
        return fetch(url, Object.assign({ headers: { 'Content-Type': 'application/json' } }, opts))
            .then(r => r.json());
    }

    function loadServiceTypes() {
        const loading = document.getElementById('stLoading');
        const table   = document.getElementById('stTable');
        const empty   = document.getElementById('stEmpty');
        const tbody   = document.getElementById('stTableBody');
        if (!tbody) return;

        loading.style.display = '';
        table.style.display = 'none';
        empty.style.display = 'none';

        apiFetch('/crm/api/service-types.php?action=list').then(data => {
            loading.style.display = 'none';
            stData = data.service_types || [];
            if (!stData.length) { empty.style.display = ''; return; }

            tbody.innerHTML = stData.map(st => `
                <tr>
                    <td><span style="display:inline-block;width:24px;height:24px;border-radius:4px;background:${esc(st.color)};vertical-align:middle;"></span></td>
                    <td><strong>${esc(st.label)}</strong>${st.icon ? ` <small class="text-muted ml-1">(${esc(st.icon)})</small>` : ''}</td>
                    <td><code>${esc(st.slug)}</code></td>
                    <td>${st.show_in_jobflow ? '<span class="badge badge-success">Yes</span>' : '<span class="badge badge-secondary">No</span>'}</td>
                    <td>${st.is_active ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-warning">Inactive</span>'}</td>
                    <td class="text-right">
                        <button class="btn btn-sm btn-outline-primary mr-1" onclick="stEdit(${st.id})">Edit</button>
                        <button class="btn btn-sm btn-outline-danger" onclick="stDelete(${st.id}, '${esc(st.label)}')">Delete</button>
                    </td>
                </tr>
            `).join('');
            table.style.display = '';
        }).catch(() => { loading.style.display = 'none'; empty.style.display = ''; });
    }

    function esc(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Load on tab show — both native and jQuery (Bootstrap 4 fires on the element)
    var stTabEl = document.getElementById('service-types-tab');
    if (stTabEl) {
        stTabEl.addEventListener('shown.bs.tab', function () {
            if (!stLoaded) { stLoaded = true; loadServiceTypes(); }
        });
        if (typeof $ !== 'undefined') {
            $(stTabEl).on('shown.bs.tab', function () {
                if (!stLoaded) { stLoaded = true; loadServiceTypes(); }
            });
        }
    }
    // Load immediately if already on this tab
    if (document.getElementById('service-types')?.classList.contains('active')) {
        stLoaded = true; loadServiceTypes();
    }

    // Add button
    document.getElementById('btnAddServiceType')?.addEventListener('click', function () {
        stResetForm();
        document.getElementById('stFormCard').style.display = '';
        document.getElementById('stFormTitle').textContent = 'Add Service Type';
    });

    // Cancel button
    document.getElementById('btnCancelServiceType')?.addEventListener('click', function () {
        document.getElementById('stFormCard').style.display = 'none';
    });

    // Auto-slug from label
    document.getElementById('st_label')?.addEventListener('input', function () {
        if (!document.getElementById('st_edit_id').value || document.getElementById('st_edit_id').value === '0') {
            const slug = this.value.toLowerCase().replace(/[^a-z0-9]+/g,'_').replace(/^_|_$/g,'');
            document.getElementById('st_slug').value = slug;
        }
    });

    // Color picker sync
    document.getElementById('st_color')?.addEventListener('input', function () {
        document.getElementById('st_color_hex').value = this.value;
    });
    document.getElementById('st_color_hex')?.addEventListener('input', function () {
        if (/^#[0-9a-fA-F]{6}$/.test(this.value)) {
            document.getElementById('st_color').value = this.value;
        }
    });

    // Save
    document.getElementById('btnSaveServiceType')?.addEventListener('click', function () {
        const id    = parseInt(document.getElementById('st_edit_id').value) || 0;
        const label = document.getElementById('st_label').value.trim();
        const slug  = document.getElementById('st_slug').value.trim();
        if (!label || !slug) { alert('Label and slug are required'); return; }

        const payload = {
            csrf_token:      csrf(),
            id:              id,
            label:           label,
            slug:            slug,
            color:           document.getElementById('st_color_hex').value || document.getElementById('st_color').value,
            icon:            document.getElementById('st_icon').value.trim(),
            is_active:       parseInt(document.getElementById('st_is_active').value),
            show_in_jobflow: parseInt(document.getElementById('st_show_in_jobflow').value),
        };

        this.disabled = true;
        apiFetch('/crm/api/service-types.php?action=save', { method: 'POST', body: JSON.stringify(payload) })
            .then(data => {
                this.disabled = false;
                if (data.success) {
                    document.getElementById('stFormCard').style.display = 'none';
                    stLoaded = false;
                    loadServiceTypes();
                } else {
                    alert(data.error || 'Save failed');
                }
            }).catch(() => { this.disabled = false; alert('Network error'); });
    });

    function stResetForm() {
        document.getElementById('st_edit_id').value = '0';
        document.getElementById('st_label').value = '';
        document.getElementById('st_slug').value = '';
        document.getElementById('st_icon').value = '';
        document.getElementById('st_color').value = '#455A64';
        document.getElementById('st_color_hex').value = '#455A64';
        document.getElementById('st_is_active').value = '1';
        document.getElementById('st_show_in_jobflow').value = '0';
    }

    window.stEdit = function (id) {
        const st = stData.find(s => s.id === id);
        if (!st) return;
        document.getElementById('st_edit_id').value = id;
        document.getElementById('st_label').value = st.label;
        document.getElementById('st_slug').value = st.slug;
        document.getElementById('st_icon').value = st.icon || '';
        document.getElementById('st_color').value = st.color || '#455A64';
        document.getElementById('st_color_hex').value = st.color || '#455A64';
        document.getElementById('st_is_active').value = st.is_active ? '1' : '0';
        document.getElementById('st_show_in_jobflow').value = st.show_in_jobflow ? '1' : '0';
        document.getElementById('stFormTitle').textContent = 'Edit Service Type';
        document.getElementById('stFormCard').style.display = '';
        document.getElementById('stFormCard').scrollIntoView({ behavior: 'smooth' });
    };

    window.stDelete = function (id, label) {
        if (!confirm('Delete "' + label + '"? If it\'s used in job plans it will be deactivated instead.')) return;
        apiFetch('/crm/api/service-types.php?action=delete', {
            method: 'POST',
            body: JSON.stringify({ csrf_token: csrf(), id: id })
        }).then(data => {
            if (data.success) { stLoaded = false; loadServiceTypes(); alert(data.message); }
            else alert(data.error || 'Delete failed');
        });
    };
})();


// ─────────────────────────────────────────────────────────────────────────────
// MEASUREMENT TYPES (Groups) — inline CRUD
// ─────────────────────────────────────────────────────────────────────────────
(function () {
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    let mgLoaded = false;

    function apiFetch(url, opts) {
        return fetch(url, Object.assign({ headers: { 'Content-Type': 'application/json' } }, opts))
            .then(r => r.json());
    }

    function esc(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function loadMeasGroups() {
        const loading = document.getElementById('mgLoading');
        const list    = document.getElementById('mgList');
        const empty   = document.getElementById('mgEmpty');
        if (!list) return;

        loading.style.display = '';
        list.innerHTML = '';
        empty.style.display = 'none';

        fetch('/crm/api/measurement-groups.php?action=list')
            .then(r => r.json())
            .then(data => {
                loading.style.display = 'none';
                const groups = data.groups || [];
                if (!groups.length) { empty.style.display = ''; return; }

                list.innerHTML = groups.map(g => `
                    <div class="card mb-2" id="mg-card-${g.id}">
                        <div class="card-header d-flex justify-content-between align-items-center py-2">
                            <div>
                                <span class="font-weight-bold">${esc(g.group_label)}</span>
                                <code class="ml-2 text-muted small">${esc(g.group_key)}</code>
                                <span class="badge badge-info ml-2">${esc(g.unit)}</span>
                                ${!g.is_active ? '<span class="badge badge-warning ml-1">Inactive</span>' : ''}
                            </div>
                            <div>
                                <button class="btn btn-sm btn-outline-secondary mr-1" onclick="mgRenamePrompt(${g.id}, '${esc(g.group_label)}')">Rename</button>
                                <button class="btn btn-sm btn-outline-danger" onclick="mgDelete(${g.id}, '${esc(g.group_label)}')">Delete</button>
                            </div>
                        </div>
                        <div class="card-body py-2">
                            <div class="d-flex flex-wrap align-items-center" id="mg-types-${g.id}">
                                ${(g.types_array || []).map(t => `
                                    <span class="badge badge-light border mr-1 mb-1" style="font-size:13px;">
                                        ${esc(t)}
                                        <button type="button" class="close ml-1" style="font-size:11px;line-height:1;" onclick="mgRemoveType(${g.id}, '${esc(t)}')" title="Remove type">&times;</button>
                                    </span>
                                `).join('')}
                                <span class="ml-1">
                                    <input type="text" class="form-control form-control-sm d-inline-block" id="mg-new-type-${g.id}" placeholder="new type..." style="width:120px;">
                                    <button class="btn btn-sm btn-outline-primary ml-1" onclick="mgAddType(${g.id})">+ Add</button>
                                </span>
                            </div>
                        </div>
                    </div>
                `).join('');
            })
            .catch(() => { loading.style.display = 'none'; empty.style.display = ''; });
    }

    // Load on tab show — both native and jQuery (Bootstrap 4 fires on the element)
    var mgTabEl = document.getElementById('measurement-types-tab');
    if (mgTabEl) {
        mgTabEl.addEventListener('shown.bs.tab', function () {
            if (!mgLoaded) { mgLoaded = true; loadMeasGroups(); }
        });
        if (typeof $ !== 'undefined') {
            $(mgTabEl).on('shown.bs.tab', function () {
                if (!mgLoaded) { mgLoaded = true; loadMeasGroups(); }
            });
        }
    }
    if (document.getElementById('measurement-types')?.classList.contains('active')) {
        mgLoaded = true; loadMeasGroups();
    }

    // Add Group button
    document.getElementById('btnAddMeasGroup')?.addEventListener('click', function () {
        document.getElementById('mgFormCard').style.display = '';
        document.getElementById('mg_label').value = '';
        document.getElementById('mg_key').value = '';
        document.getElementById('mg_unit').value = 'sqft';
    });

    // Auto-key from label
    document.getElementById('mg_label')?.addEventListener('input', function () {
        const key = this.value.toLowerCase().replace(/[^a-z0-9]+/g,'_').replace(/^_|_$/g,'');
        document.getElementById('mg_key').value = key;
    });

    // Cancel
    document.getElementById('btnCancelMeasGroup')?.addEventListener('click', function () {
        document.getElementById('mgFormCard').style.display = 'none';
    });

    // Save group
    document.getElementById('btnSaveMeasGroup')?.addEventListener('click', function () {
        const label = document.getElementById('mg_label').value.trim();
        const key   = document.getElementById('mg_key').value.trim();
        const unit  = document.getElementById('mg_unit').value;
        if (!label || !key) { alert('Label and key are required'); return; }

        this.disabled = true;
        const body = new URLSearchParams({ group_key: key, group_label: label, unit: unit });
        fetch('/crm/api/measurement-groups.php?action=add', { method: 'POST', body: body })
            .then(r => r.json())
            .then(data => {
                this.disabled = false;
                if (data.success) {
                    document.getElementById('mgFormCard').style.display = 'none';
                    mgLoaded = false;
                    loadMeasGroups();
                } else {
                    alert(data.error || 'Save failed');
                }
            }).catch(() => { this.disabled = false; });
    });

    window.mgRenamePrompt = function (id, current) {
        const label = prompt('New label for this group:', current);
        if (!label || label === current) return;
        const body = new URLSearchParams({ group_id: id, group_label: label });
        fetch('/crm/api/measurement-groups.php?action=update-label', { method: 'POST', body: body })
            .then(r => r.json())
            .then(data => {
                if (data.success) { mgLoaded = false; loadMeasGroups(); }
                else alert(data.error || 'Rename failed');
            });
    };

    window.mgDelete = function (id, label) {
        if (!confirm('Delete group "' + label + '"? It will be deactivated if measurements reference it.')) return;
        const body = new URLSearchParams({ group_id: id });
        fetch('/crm/api/measurement-groups.php?action=delete', { method: 'POST', body: body })
            .then(r => r.json())
            .then(data => {
                if (data.success) { mgLoaded = false; loadMeasGroups(); alert(data.message); }
                else alert(data.error || 'Delete failed');
            });
    };

    window.mgAddType = function (groupId) {
        const input = document.getElementById('mg-new-type-' + groupId);
        const type  = input.value.trim();
        if (!type) { input.focus(); return; }
        const body = new URLSearchParams({ group_id: groupId, measurement_type: type });
        fetch('/crm/api/measurement-groups.php?action=add-type', { method: 'POST', body: body })
            .then(r => r.json())
            .then(data => {
                if (data.success) { mgLoaded = false; loadMeasGroups(); }
                else alert(data.error || 'Failed to add type');
            });
    };

    window.mgRemoveType = function (groupId, type) {
        if (!confirm('Remove type "' + type + '" from this group?')) return;
        const body = new URLSearchParams({ group_id: groupId, measurement_type: type });
        fetch('/crm/api/measurement-groups.php?action=remove-type', { method: 'POST', body: body })
            .then(r => r.json())
            .then(data => {
                if (data.success) { mgLoaded = false; loadMeasGroups(); }
                else alert(data.error || 'Failed to remove type');
            });
    };
})();
</script>

<?php include 'includes/appstack_footer.php'; ?>
