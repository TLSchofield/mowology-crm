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
$extraHead = '<meta name="csrf-token" content="' . htmlspecialchars($csrfToken) . '">'
           . '<script src="/crm/js/email-templates.js?v=1" defer></script>';
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
        <button type="button" class="btn-close" data-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['alert']); ?>
<?php endif; ?>

<div class="mw-page-header">
    <h1 class="h3">Business Settings</h1>
</div>

<!-- Settings Categories -->
<div class="mw-settings-categories mb-4" id="settingsCategories">
    <div class="mw-settings-cat-card active" data-category="business" onclick="switchSettingsCategory('business')">
        <div class="mw-settings-cat-icon"><i data-feather="briefcase"></i></div>
        <div class="mw-settings-cat-info">
            <h6 class="mw-settings-cat-name mb-0">Business Setup</h6>
            <small class="mw-settings-cat-desc">Company info, branding, tax &amp; hours</small>
        </div>
        <span class="mw-settings-cat-badge" id="businessCompletionBadge"></span>
    </div>
    <div class="mw-settings-cat-card" data-category="documents" onclick="switchSettingsCategory('documents')">
        <div class="mw-settings-cat-icon"><i data-feather="file-text"></i></div>
        <div class="mw-settings-cat-info">
            <h6 class="mw-settings-cat-name mb-0">Documents</h6>
            <small class="mw-settings-cat-desc">Invoices, email &amp; templates</small>
        </div>
    </div>
    <div class="mw-settings-cat-card" data-category="operations" onclick="switchSettingsCategory('operations')">
        <div class="mw-settings-cat-icon"><i data-feather="tool"></i></div>
        <div class="mw-settings-cat-info">
            <h6 class="mw-settings-cat-name mb-0">Operations</h6>
            <small class="mw-settings-cat-desc">Services, measurements, holidays &amp; more</small>
        </div>
    </div>
    <?php if ($user['role'] === 'admin'): ?>
    <div class="mw-settings-cat-card" data-category="admin" onclick="switchSettingsCategory('admin')">
        <div class="mw-settings-cat-icon"><i data-feather="shield"></i></div>
        <div class="mw-settings-cat-info">
            <h6 class="mw-settings-cat-name mb-0">Admin</h6>
            <small class="mw-settings-cat-desc">Database &amp; developer tools</small>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Sub-tab Navs (one per category) -->
<ul class="mw-settings-subtabs nav nav-tabs mb-3" id="subtabs-business" role="tablist">
    <li class="nav-item"><a class="nav-link active" id="company-tab" data-toggle="tab" href="#company" role="tab">Company Info</a></li>
    <li class="nav-item"><a class="nav-link" id="branding-tab" data-toggle="tab" href="#branding" role="tab">Branding</a></li>
</ul>
<ul class="mw-settings-subtabs nav nav-tabs mb-3" id="subtabs-documents" role="tablist" style="display:none;">
    <li class="nav-item"><a class="nav-link" id="invoice-tab" data-toggle="tab" href="#invoice" role="tab">Invoices</a></li>
    <li class="nav-item"><a class="nav-link" id="email-tab" data-toggle="tab" href="#email" role="tab">Email</a></li>
    <li class="nav-item"><a class="nav-link" id="email-templates-tab" data-toggle="tab" href="#email-templates" role="tab">Email Templates</a></li>
    <li class="nav-item"><a class="nav-link" id="receipts-tab" data-toggle="tab" href="#receipts" role="tab">Receipt Forwarding</a></li>
</ul>
<ul class="mw-settings-subtabs nav nav-tabs mb-3" id="subtabs-operations" role="tablist" style="display:none;">
    <li class="nav-item"><a class="nav-link" id="holidays-tab" data-toggle="tab" href="#holidays" role="tab">Holidays</a></li>
    <li class="nav-item"><a class="nav-link" id="tags-tab" data-toggle="tab" href="#tags" role="tab">Tags</a></li>
    <li class="nav-item"><a class="nav-link" id="service-types-tab" data-toggle="tab" href="#service-types" role="tab">Service Types</a></li>
    <li class="nav-item"><a class="nav-link" id="measurement-types-tab" data-toggle="tab" href="#measurement-types" role="tab">Measurement Types</a></li>
    <li class="nav-item"><a class="nav-link" id="reviews-tab" data-toggle="tab" href="#reviews" role="tab">Reviews</a></li>
    <li class="nav-item"><a class="nav-link" id="extras-tab" data-toggle="tab" href="#extras" role="tab">Extras Billing</a></li>
    <li class="nav-item"><a class="nav-link" id="summary-card-tab" data-toggle="tab" href="#summary-card" role="tab">Summary Card</a></li>
</ul>
<?php if ($user['role'] === 'admin'): ?>
<ul class="mw-settings-subtabs nav nav-tabs mb-3" id="subtabs-admin" role="tablist" style="display:none;">
    <li class="nav-item"><a class="nav-link" id="database-tab" data-toggle="tab" href="#database" role="tab">Database / Migrations</a></li>
    <li class="nav-item"><a class="nav-link" id="dev-tools-tab" data-toggle="tab" href="#dev-tools" role="tab">Developer Tools</a></li>
</ul>
<?php endif; ?>

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
                            <input type="text" class="form-control" id="company_website" maxlength="255" placeholder="https://mowology.ca">
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
                            <label for="gst_registration" class="form-label">GST # (e.g., R123456789) <span class="mw-help-tooltip" data-help="Your CRA GST/HST registration number. Displayed on invoices and quotes for tax compliance.">?</span></label>
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

            <div class="card mt-3">
                <div class="card-header"><h5 class="card-title">Tax Settings</h5></div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="gst_rate" class="form-label">GST Rate (%)</label>
                            <input type="number" class="form-control" id="gst_rate" min="0" max="100" step="0.01" placeholder="5.00">
                            <small class="form-text text-muted">Federal Goods &amp; Services Tax — 5% Canada-wide</small>
                        </div>
                        <div class="col-md-4">
                            <label for="pst_rate" class="form-label">PST Rate (%) <small class="text-muted">optional</small></label>
                            <input type="number" class="form-control" id="pst_rate" min="0" max="100" step="0.01" placeholder="0.00">
                            <small class="form-text text-muted">Provincial Sales Tax — 7% in BC, 0 if not registered</small>
                        </div>
                        <div class="col-md-4">
                            <label for="show_tax_on_invoices" class="form-label">Show Tax on Invoices &amp; Quotes <span class="mw-help-tooltip" data-help="When enabled, invoices and quotes display GST/PST as separate line items. When disabled, prices are shown as all-inclusive.">?</span></label>
                            <select class="form-control" id="show_tax_on_invoices">
                                <option value="1">Yes — show tax line</option>
                                <option value="0">No — hide tax breakdown</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h5 class="card-title">Business Hours</h5></div>
                <div class="card-body">
                    <p class="text-muted mb-3">Set your regular operating hours. Used for scheduling constraints and optional display on client documents.</p>
                    <div id="businessHoursTable">
                        <div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div> Loading hours...</div>
                    </div>
                    <div class="mt-3">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" id="bh_show_on_invoices" data-bh-ignore="1">
                            <label class="form-check-label" for="bh_show_on_invoices">Show on invoices</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" id="bh_show_on_quotes" data-bh-ignore="1">
                            <label class="form-check-label" for="bh_show_on_quotes">Show on quotes</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h5 class="card-title">Regional Settings</h5></div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="country" class="form-label">Country</label>
                            <select class="form-control" id="country">
                                <option value="Canada">Canada</option>
                                <option value="United States">United States</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="timezone" class="form-label">Timezone</label>
                            <select class="form-control" id="timezone">
                                <optgroup label="Canada">
                                    <option value="America/Vancouver">Pacific — Vancouver, BC</option>
                                    <option value="America/Edmonton">Mountain — Calgary, AB</option>
                                    <option value="America/Winnipeg">Central — Winnipeg, MB</option>
                                    <option value="America/Toronto">Eastern — Toronto, ON</option>
                                    <option value="America/Halifax">Atlantic — Halifax, NS</option>
                                    <option value="America/St_Johns">Newfoundland — St. John's, NL</option>
                                </optgroup>
                                <optgroup label="United States">
                                    <option value="America/Los_Angeles">Pacific — US West</option>
                                    <option value="America/Denver">Mountain — US</option>
                                    <option value="America/Chicago">Central — US</option>
                                    <option value="America/New_York">Eastern — US East</option>
                                </optgroup>
                                <optgroup label="Other">
                                    <option value="UTC">UTC</option>
                                </optgroup>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="date_format" class="form-label">Date Format</label>
                            <select class="form-control" id="date_format">
                                <option value="M j, Y">Jan 15, 2026</option>
                                <option value="F j, Y">January 15, 2026</option>
                                <option value="Y-m-d">2026-01-15 (ISO)</option>
                                <option value="m/d/Y">01/15/2026 (US)</option>
                                <option value="d/m/Y">15/01/2026 (EU/CA)</option>
                                <option value="d-m-Y">15-01-2026</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="time_format" class="form-label">Time Format</label>
                            <select class="form-control" id="time_format">
                                <option value="12h">12-hour (3:30 PM)</option>
                                <option value="24h">24-hour (15:30)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="first_day_of_week" class="form-label">First Day of Week</label>
                            <select class="form-control" id="first_day_of_week">
                                <option value="1">Monday</option>
                                <option value="0">Sunday</option>
                            </select>
                        </div>
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
                        <label class="form-label">Upload Logo</label>
                        <div class="mw-logo-upload-zone" id="logoUploadZone" onclick="document.getElementById('logoFileInput').click();">
                            <i data-feather="upload-cloud" style="width:32px;height:32px;color:var(--mw-green);margin-bottom:8px;"></i>
                            <p class="mb-1"><strong>Click to upload</strong> or drag and drop</p>
                            <small class="text-muted">PNG, JPG, SVG — max 2 MB</small>
                        </div>
                        <input type="file" id="logoFileInput" accept="image/png,image/jpeg,image/svg+xml" style="display:none;" onchange="handleLogoUpload(this)">
                        <input type="hidden" id="logo_path">
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
                        <label for="invoice_payment_instructions" class="form-label">Payment Instructions <span class="mw-help-tooltip" data-help="Shown on every invoice. Include your e-transfer email, cheque payee name, or online payment link.">?</span></label>
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

        <!-- Email Templates Tab -->
        <div class="tab-pane fade" id="email-templates" role="tabpanel">

            <p class="text-muted mb-3">
                Edit the subject line and message body for each outbound email. Use
                <code>{{placeholder}}</code> chips to insert dynamic values.
                All emails are wrapped in the Mowology branded shell automatically.
            </p>

            <!-- Preview Modal -->
            <div id="et-preview-overlay">
                <div id="et-preview-modal">
                    <div class="et-preview-header">
                        <strong>Email Preview</strong>
                        <button type="button" class="et-preview-close" onclick="etClosePreview()">&times;</button>
                    </div>
                    <div id="et-preview-loading">Loading preview…</div>
                    <iframe id="et-preview-frame" title="Email Preview"></iframe>
                </div>
            </div>

            <!-- Accordion: one card per template type -->
            <div id="et-accordion">

                <?php
                $etTemplates = [
                    [
                        'key'   => 'quote_sent',
                        'label' => 'Quote Sent',
                        'icon'  => 'file-text',
                        'desc'  => 'Sent when a quote is delivered to a client.',
                        'vars'  => ['{{customer_first_name}}', '{{customer_name}}', '{{quote_number}}', '{{quote_amount}}', '{{quote_valid_until}}', '{{company_name}}', '{{company_phone}}'],
                        'open'  => true,
                    ],
                    [
                        'key'   => 'invoice_sent',
                        'label' => 'Invoice Sent',
                        'icon'  => 'credit-card',
                        'desc'  => 'Sent when an invoice is delivered to a client.',
                        'vars'  => ['{{customer_first_name}}', '{{customer_name}}', '{{invoice_number}}', '{{amount_due}}', '{{due_date}}', '{{company_name}}', '{{company_phone}}'],
                        'open'  => false,
                    ],
                    [
                        'key'   => 'receipt_sent',
                        'label' => 'Payment Receipt',
                        'icon'  => 'check-circle',
                        'desc'  => 'Sent automatically when a payment is received.',
                        'vars'  => ['{{customer_first_name}}', '{{customer_name}}', '{{invoice_number}}', '{{amount_paid}}', '{{payment_date}}', '{{company_name}}', '{{company_phone}}'],
                        'open'  => false,
                    ],
                    [
                        'key'   => 'job_complete',
                        'label' => 'Service Complete',
                        'icon'  => 'check-square',
                        'desc'  => 'Sent when a job is marked complete (Proof of Work).',
                        'vars'  => ['{{customer_first_name}}', '{{customer_name}}', '{{service_type}}', '{{job_date}}', '{{property_address}}', '{{company_name}}', '{{company_phone}}'],
                        'open'  => false,
                    ],
                ];
                foreach ($etTemplates as $et):
                    $collapseId = 'et-collapse-' . $et['key'];
                    $headerId   = 'et-header-'   . $et['key'];
                    $subjectId  = 'et_subject_'  . $et['key'];
                    $bodyId     = 'et_body_'      . $et['key'];
                    $isOpen     = $et['open'] ? 'show' : '';
                    $collapsed  = $et['open'] ? '' : 'collapsed';
                ?>
                <div class="card mb-2">
                    <div class="card-header p-0" id="<?= $headerId ?>">
                        <button class="btn btn-link btn-block text-left d-flex align-items-center px-3 py-3 <?= $collapsed ?>"
                                type="button"
                                data-toggle="collapse"
                                data-target="#<?= $collapseId ?>"
                                aria-expanded="<?= $et['open'] ? 'true' : 'false' ?>"
                                aria-controls="<?= $collapseId ?>">
                            <i data-feather="<?= $et['icon'] ?>" style="width:16px;height:16px;margin-right:10px;flex-shrink:0;color:var(--mw-green);"></i>
                            <span style="font-weight:600;color:#0D3B2E;flex:1;"><?= htmlspecialchars($et['label']) ?></span>
                            <small class="text-muted mr-3 d-none d-md-inline"><?= htmlspecialchars($et['desc']) ?></small>
                            <i data-feather="chevron-down" style="width:16px;height:16px;color:#7a9e8c;transition:transform .2s;"></i>
                        </button>
                    </div>
                    <div id="<?= $collapseId ?>" class="collapse <?= $isOpen ?>" aria-labelledby="<?= $headerId ?>" data-parent="#et-accordion">
                        <div class="card-body pt-3">
                            <!-- Subject line -->
                            <div class="mb-3">
                                <label for="<?= $subjectId ?>" class="form-label font-weight-600">Subject line</label>
                                <input type="text"
                                       class="form-control"
                                       id="<?= $subjectId ?>"
                                       maxlength="255"
                                       placeholder="Email subject…"
                                       oninput="etMarkDirty('<?= $et['key'] ?>')">
                            </div>

                            <!-- Placeholder chips -->
                            <div class="mb-3">
                                <label class="form-label text-muted" style="font-size:12px;margin-bottom:6px;">
                                    Click a placeholder to insert at cursor &darr;
                                </label>
                                <div style="display:flex;flex-wrap:wrap;gap:6px;">
                                    <?php foreach ($et['vars'] as $var): ?>
                                    <button type="button"
                                            class="btn btn-sm"
                                            style="font-family:monospace;font-size:11px;padding:3px 9px;background:#f8fffe;border:1px solid #e5ede9;color:#1A5F4A;border-radius:4px;"
                                            onclick="etInsertPlaceholder('<?= $bodyId ?>', '<?= $var ?>')">
                                        <?= htmlspecialchars($var) ?>
                                    </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Body textarea -->
                            <div class="mb-3">
                                <label for="<?= $bodyId ?>" class="form-label font-weight-600">Message body</label>
                                <small class="form-text text-muted d-block mb-1">
                                    Plain text. Blank lines create paragraph breaks. The branded email wrapper and CTA button are added automatically.
                                </small>
                                <textarea class="form-control"
                                          id="<?= $bodyId ?>"
                                          rows="9"
                                          style="font-family:monospace;font-size:13px;resize:vertical;"
                                          placeholder="Write your message here…"
                                          oninput="etMarkDirty('<?= $et['key'] ?>')"></textarea>
                            </div>

                            <!-- Actions -->
                            <div class="d-flex gap-2" style="gap:10px;">
                                <button type="button"
                                        class="btn btn-outline-primary btn-sm"
                                        onclick="etPreview('<?= $et['key'] ?>')">
                                    <i data-feather="eye" style="width:14px;height:14px;margin-right:4px;"></i>
                                    Preview Email
                                </button>
                                <button type="button"
                                        class="btn btn-outline-secondary btn-sm"
                                        onclick="etResetDefault('<?= $et['key'] ?>')">
                                    <i data-feather="rotate-ccw" style="width:14px;height:14px;margin-right:4px;"></i>
                                    Reset to Default
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

            </div><!-- /et-accordion -->

        </div><!-- /email-templates tab -->

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

        <!-- Reviews Tab -->
        <div class="tab-pane fade" id="reviews" role="tabpanel">
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title mb-0">Google Reviews Automation</h5></div>
                <div class="card-body">
                    <p class="text-muted mb-3">
                        After each job visit is completed, Mowology automatically sends a review request to the
                        customer via email (and SMS if they have consent). The email includes a direct link to your
                        Google Business Profile review page.
                    </p>
                    <div class="row mb-3">
                        <div class="col-md-8">
                            <label for="google_review_url" class="form-label">Google Review URL</label>
                            <input type="url" class="form-control" id="google_review_url"
                                   placeholder="https://g.page/r/your-business/review"
                                   maxlength="500">
                            <small class="form-text text-muted">
                                Your Google Business Profile short review link. Find it in Google Business Manager
                                under <strong>Get more reviews</strong>. Leave blank to disable review requests.
                            </small>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-12">
                            <div class="card bg-light border-0">
                                <div class="card-body py-2 px-3">
                                    <small class="text-muted">
                                        <strong>Rate limits:</strong>
                                        Requests are sent at most once every <strong>30 days</strong> per customer,
                                        and no more than <strong>3 times total</strong> per contact.
                                        Customers can opt out at any time by contacting you.
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="reviewsSaveResult" class="alert" style="display:none;"></div>
                    <button type="button" class="btn btn-primary" id="saveReviewSettingsBtn">
                        <i data-feather="save" style="width:15px;height:15px;margin-right:4px;vertical-align:-2px;"></i>
                        Save Review Settings
                    </button>
                    <a href="/crm/api/run-migration-606.php" target="_blank" class="btn btn-outline-secondary ml-2" id="runMigration606Btn">
                        <i data-feather="database" style="width:15px;height:15px;margin-right:4px;vertical-align:-2px;"></i>
                        Run DB Migration
                    </a>
                </div>
            </div>
        </div>

        <!-- Extras Billing Tab -->
        <div class="tab-pane fade" id="extras" role="tabpanel">
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title mb-0">Billable Extras Rate</h5></div>
                <div class="card-body">
                    <p class="text-muted mb-3">
                        When crew tap <strong>Add-On Services</strong> at the end of a visit, the timer and
                        quick-add buttons track extra time. Time is billed in 5-minute blocks at the rate
                        below. The amount is automatically added as a separate line item on the invoice.
                    </p>
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="extras_rate_per_5min" class="form-label">Rate per 5-minute block (CAD) <span class="text-danger">*</span></label>
                            <div class="input-group" style="max-width:200px;">
                                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                                <input type="number" class="form-control" id="extras_rate_per_5min"
                                       step="0.25" min="0" max="500" placeholder="5.00">
                            </div>
                            <small class="form-text text-muted">
                                e.g. $5.00/block = $60/hr &nbsp;·&nbsp; $7.50/block = $90/hr
                            </small>
                        </div>
                    </div>
                    <div id="extrasBillingSaveResult" class="alert" style="display:none;"></div>
                    <button type="button" class="btn btn-primary" id="saveExtrasRateBtn">
                        <i data-feather="save" style="width:15px;height:15px;margin-right:4px;vertical-align:-2px;"></i>
                        Save Rate
                    </button>
                    <a href="/crm/api/run-migration-extras.php" target="_blank" class="btn btn-outline-secondary ml-2">
                        <i data-feather="database" style="width:15px;height:15px;margin-right:4px;vertical-align:-2px;"></i>
                        Run DB Migration
                    </a>
                    <div class="card bg-light border-0 mt-4">
                        <div class="card-body py-2 px-3">
                            <small class="text-muted">
                                <strong>How it works:</strong>
                                Crew tap the <em>Extras</em> timer or use quick-add buttons (+5/+10/+15/+30 min).
                                Time is rounded <strong>up</strong> to the nearest 5-min block on every invoice.
                                An optional crew note is pre-filled into the invoice notes for the client.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary Card Tab -->
        <div class="tab-pane fade" id="summary-card" role="tabpanel">
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title mb-0">Day Summary Card</h5></div>
                <div class="card-body">
                    <p class="text-muted mb-4">
                        Controls which metrics appear on the daily summary card shown at the top of the
                        Schedule page on mobile. Changes take effect immediately for all users.
                    </p>

                    <h6 class="text-uppercase text-muted mb-3" style="font-size:0.72rem;letter-spacing:1px;">Metrics</h6>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="custom-control custom-switch mb-3">
                                <input type="checkbox" class="custom-control-input" id="sc_show_job_count">
                                <label class="custom-control-label" for="sc_show_job_count">Show Stop Count</label>
                            </div>
                            <div class="custom-control custom-switch mb-3">
                                <input type="checkbox" class="custom-control-input" id="sc_show_revenue">
                                <label class="custom-control-label" for="sc_show_revenue">Show Estimated Revenue</label>
                            </div>
                            <div class="custom-control custom-switch mb-3">
                                <input type="checkbox" class="custom-control-input" id="sc_show_total_time">
                                <label class="custom-control-label" for="sc_show_total_time">Show Estimated Time</label>
                            </div>
                        </div>
                    </div>

                    <h6 class="text-uppercase text-muted mb-3" style="font-size:0.72rem;letter-spacing:1px;">Weather</h6>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="custom-control custom-switch mb-3">
                                <input type="checkbox" class="custom-control-input" id="sc_show_morning_weather">
                                <label class="custom-control-label" for="sc_show_morning_weather">Show Morning Forecast</label>
                            </div>
                            <div class="custom-control custom-switch mb-3">
                                <input type="checkbox" class="custom-control-input" id="sc_show_afternoon_weather">
                                <label class="custom-control-label" for="sc_show_afternoon_weather">Show Afternoon Forecast</label>
                            </div>
                        </div>
                    </div>

                    <h6 class="text-uppercase text-muted mb-3" style="font-size:0.72rem;letter-spacing:1px;">Clock</h6>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="custom-control custom-switch mb-3">
                                <input type="checkbox" class="custom-control-input" id="sc_show_clock_card">
                                <label class="custom-control-label" for="sc_show_clock_card">Show Clock In / Out Card</label>
                            </div>
                        </div>
                    </div>

                    <div id="summaryCardSaveResult" class="alert" style="display:none;"></div>
                    <button type="button" class="btn btn-primary" id="saveSummaryCardBtn">
                        <i data-feather="save" style="width:15px;height:15px;margin-right:4px;vertical-align:-2px;"></i>
                        Save Summary Card Settings
                    </button>
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

        <!-- Developer Tools Tab -->
        <div class="tab-pane fade" id="dev-tools" role="tabpanel">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Developer &amp; Admin Tools</h5></div>
                <div class="card-body">
                    <p class="text-muted mb-3">Internal tools for development, design, and operations. These are no longer visible in the main navigation.</p>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <div class="card h-100 border">
                                <div class="card-body text-center py-4">
                                    <i data-feather="database" style="width:32px;height:32px;color:var(--mw-green);margin-bottom:12px;"></i>
                                    <h6 class="mb-1">Database Manager</h6>
                                    <p class="text-muted small mb-3">Schema browser, migrations, and drift detection.</p>
                                    <a href="/crm/database_appstack.php" class="btn btn-sm btn-outline-primary">Open</a>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="card h-100 border">
                                <div class="card-body text-center py-4">
                                    <i data-feather="smartphone" style="width:32px;height:32px;color:var(--mw-green);margin-bottom:12px;"></i>
                                    <h6 class="mb-1">Mobile Preview</h6>
                                    <p class="text-muted small mb-3">Preview the Mowology app in a mobile frame.</p>
                                    <a href="/crm/mobile-preview_appstack.php" class="btn btn-sm btn-outline-primary">Open</a>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="card h-100 border">
                                <div class="card-body text-center py-4">
                                    <i data-feather="credit-card" style="width:32px;height:32px;color:var(--mw-green);margin-bottom:12px;"></i>
                                    <h6 class="mb-1">Card Designer</h6>
                                    <p class="text-muted small mb-3">Design and preview business card layouts.</p>
                                    <a href="/crm/card-designer_appstack.php" class="btn btn-sm btn-outline-primary">Open</a>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="card h-100 border">
                                <div class="card-body text-center py-4">
                                    <i data-feather="layout" style="width:32px;height:32px;color:var(--mw-green);margin-bottom:12px;"></i>
                                    <h6 class="mb-1">Design Mockups</h6>
                                    <p class="text-muted small mb-3">UI mockups and design exploration tools.</p>
                                    <a href="/crm/mockups_appstack.php" class="btn btn-sm btn-outline-primary">Open</a>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="card h-100 border">
                                <div class="card-body text-center py-4">
                                    <i data-feather="cloud-rain" style="width:32px;height:32px;color:var(--mw-green);margin-bottom:12px;"></i>
                                    <h6 class="mb-1">Ops Weather</h6>
                                    <p class="text-muted small mb-3">Weather actions and operations scheduling tools.</p>
                                    <a href="/crm/ops/weather_actions.php" class="btn btn-sm btn-outline-primary">Open</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Save Button (shown for Business Setup & Documents categories) -->
    <div class="mt-4 mb-3" id="mainSaveButtonArea">
        <button type="submit" class="btn btn-primary btn-lg">Save Settings</button>
        <a href="dashboard_appstack.php" class="btn btn-secondary btn-lg">Cancel</a>
    </div>
</form>

<script src="js/business-settings.js?v=2"></script>

<!-- Settings Category Navigation -->
<script>
(function () {
    var currentCategory = 'business';
    var categoryTabs = {
        business:   ['company', 'branding'],
        documents:  ['invoice', 'email', 'email-templates', 'receipts'],
        operations: ['holidays', 'tags', 'service-types', 'measurement-types', 'reviews', 'extras', 'summary-card'],
        admin:      ['database', 'dev-tools']
    };

    window.switchSettingsCategory = function (name) {
        if (name === currentCategory) return;
        currentCategory = name;

        // Update category cards
        var cards = document.querySelectorAll('.mw-settings-cat-card');
        for (var i = 0; i < cards.length; i++) {
            cards[i].classList.toggle('active', cards[i].getAttribute('data-category') === name);
        }

        // Show/hide sub-tab navs
        var allSubtabs = document.querySelectorAll('.mw-settings-subtabs');
        for (var j = 0; j < allSubtabs.length; j++) {
            allSubtabs[j].style.display = 'none';
        }
        var activeSubtabs = document.getElementById('subtabs-' + name);
        if (activeSubtabs) activeSubtabs.style.display = '';

        // Activate the first sub-tab in this category.
        // Bootstrap 4 .tab('show') can't deactivate a pane whose nav-link is in
        // a different <ul>, so we manually deactivate the old pane first,
        // then directly activate the new one without relying on CSS transitions.
        var allPanes = document.querySelectorAll('.tab-content .tab-pane');
        var allNavLinks = document.querySelectorAll('.mw-settings-subtabs .nav-link');
        for (var p = 0; p < allPanes.length; p++) {
            allPanes[p].classList.remove('show', 'active');
        }
        for (var n = 0; n < allNavLinks.length; n++) {
            allNavLinks[n].classList.remove('active');
        }

        var firstLink = activeSubtabs ? activeSubtabs.querySelector('.nav-link') : null;
        if (firstLink) {
            var targetId = firstLink.getAttribute('href');
            var targetPane = targetId ? document.querySelector(targetId) : null;
            firstLink.classList.add('active');
            if (targetPane) {
                targetPane.classList.add('active', 'show');
            }
        }

        // Show/hide main save button (only for business & documents)
        var saveArea = document.getElementById('mainSaveButtonArea');
        if (saveArea) {
            saveArea.style.display = (name === 'business' || name === 'documents') ? '' : 'none';
        }

        // Update URL hash
        if (history.replaceState) {
            history.replaceState(null, '', '#' + name);
        }

        // Re-render feather icons (for newly-visible category card icons)
        if (typeof feather !== 'undefined') feather.replace();
    };

    // Business Setup completion indicator
    window.updateBusinessCompletion = function () {
        var fields = ['company_name', 'company_phone', 'company_email', 'company_address', 'gst_registration'];
        var filled = 0;
        for (var i = 0; i < fields.length; i++) {
            var el = document.getElementById(fields[i]);
            if (el && el.value && el.value.trim() !== '') filled++;
        }
        var badge = document.getElementById('businessCompletionBadge');
        if (!badge) return;
        if (filled === fields.length) {
            badge.className = 'mw-settings-cat-badge complete';
            badge.textContent = 'Complete';
        } else {
            badge.className = 'mw-settings-cat-badge incomplete';
            badge.textContent = filled + '/' + fields.length;
        }
    };

    // On page load: read hash and switch category, check completion
    document.addEventListener('DOMContentLoaded', function () {
        var hash = window.location.hash.replace('#', '');
        if (hash && categoryTabs[hash]) {
            // Delay slightly to let Bootstrap initialize
            setTimeout(function () { switchSettingsCategory(hash); }, 100);
        }

        // Check business completion after settings load
        var checkInterval = setInterval(function () {
            var form = document.getElementById('settingsForm');
            if (form && form.style.display !== 'none') {
                clearInterval(checkInterval);
                updateBusinessCompletion();
            }
        }, 300);
    });
})();
</script>

<script>
// ─────────────────────────────────────────────────────────────────────────────
// REVIEW SETTINGS — load + save google_review_url from ops_settings
// ─────────────────────────────────────────────────────────────────────────────
(function () {
    var csrf = function () { return document.querySelector('meta[name="csrf-token"]')?.content || ''; };

    function loadReviewSettings() {
        fetch('/crm/api/ops-settings.php?action=get&key=google_review_url')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success && data.value !== null) {
                    var el = document.getElementById('google_review_url');
                    if (el) el.value = data.value;
                }
            })
            .catch(function () {});
    }

    function saveReviewSettings() {
        var el  = document.getElementById('google_review_url');
        var btn = document.getElementById('saveReviewSettingsBtn');
        var res = document.getElementById('reviewsSaveResult');
        if (!el) return;

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';
        res.style.display = 'none';

        fetch('/crm/api/ops-settings.php?action=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: csrf(),
                key: 'google_review_url',
                value: el.value.trim(),
                description: 'Google Business Profile review short URL'
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            res.style.display = '';
            if (data.success) {
                res.className = 'alert alert-success';
                res.textContent = 'Review URL saved.';
            } else {
                res.className = 'alert alert-danger';
                res.textContent = data.error || 'Save failed.';
            }
        })
        .catch(function (e) {
            res.style.display = '';
            res.className = 'alert alert-danger';
            res.textContent = 'Network error: ' + e.message;
        })
        .finally(function () {
            btn.disabled = false;
            btn.innerHTML = '<i data-feather="save" style="width:15px;height:15px;margin-right:4px;vertical-align:-2px;"></i> Save Review Settings';
            if (typeof feather !== 'undefined') feather.replace();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        loadReviewSettings();

        var btn = document.getElementById('saveReviewSettingsBtn');
        if (btn) btn.addEventListener('click', saveReviewSettings);

        // Load when tab is shown (in case it wasn't loaded yet)
        var tab = document.getElementById('reviews-tab');
        if (tab) tab.addEventListener('shown.bs.tab', loadReviewSettings);
    });
})();

// ─────────────────────────────────────────────────────────────────────────────
// EXTRAS BILLING RATE — load + save extras_rate_per_5min from ops_settings
// ─────────────────────────────────────────────────────────────────────────────
(function () {
    var csrf = function () { return document.querySelector('meta[name="csrf-token"]')?.content || ''; };

    function loadExtrasRate() {
        fetch('/crm/api/ops-settings.php?action=get&key=extras_rate_per_5min')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success && data.value !== null) {
                    var el = document.getElementById('extras_rate_per_5min');
                    if (el) el.value = parseFloat(data.value).toFixed(2);
                }
            })
            .catch(function () {});
    }

    function saveExtrasRate() {
        var el  = document.getElementById('extras_rate_per_5min');
        var btn = document.getElementById('saveExtrasRateBtn');
        var res = document.getElementById('extrasBillingSaveResult');
        if (!el) return;

        var rate = parseFloat(el.value);
        if (isNaN(rate) || rate < 0) {
            res.style.display = '';
            res.className = 'alert alert-danger';
            res.textContent = 'Please enter a valid rate (0 or higher).';
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';
        res.style.display = 'none';

        fetch('/crm/api/ops-settings.php?action=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token:  csrf(),
                key:         'extras_rate_per_5min',
                value:       rate.toFixed(2),
                description: 'Billable extras rate per 5-minute block (CAD)'
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            res.style.display = '';
            if (data.success) {
                res.className = 'alert alert-success';
                res.textContent = 'Rate saved — $' + rate.toFixed(2) + '/block ($' + (rate * 12).toFixed(2) + '/hr).';
            } else {
                res.className = 'alert alert-danger';
                res.textContent = data.error || 'Save failed.';
            }
        })
        .catch(function (e) {
            res.style.display = '';
            res.className = 'alert alert-danger';
            res.textContent = 'Network error: ' + e.message;
        })
        .finally(function () {
            btn.disabled = false;
            btn.innerHTML = '<i data-feather="save" style="width:15px;height:15px;margin-right:4px;vertical-align:-2px;"></i> Save Rate';
            if (typeof feather !== 'undefined') feather.replace();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        loadExtrasRate();
        var btn = document.getElementById('saveExtrasRateBtn');
        if (btn) btn.addEventListener('click', saveExtrasRate);
        var tab = document.getElementById('extras-tab');
        if (tab) tab.addEventListener('shown.bs.tab', loadExtrasRate);
    });
})();

// ─────────────────────────────────────────────────────────────────────────────
// SUMMARY CARD SETTINGS
// ─────────────────────────────────────────────────────────────────────────────
(function () {
    var FIELDS = [
        'show_job_count', 'show_revenue', 'show_total_time',
        'show_morning_weather', 'show_afternoon_weather', 'show_clock_card'
    ];

    function loadSummaryCardSettings() {
        fetch('/crm/api/ops-settings.php?action=get&key=summary_card_config', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;
                var vals = data.value || {};
                FIELDS.forEach(function (f) {
                    var el = document.getElementById('sc_' + f);
                    if (el) {
                        el.checked = vals[f] !== false; // default true if not set
                    }
                });
            })
            .catch(function () {});
    }

    function saveSummaryCardSettings() {
        var btn = document.getElementById('saveSummaryCardBtn');
        var res = document.getElementById('summaryCardSaveResult');
        if (btn) btn.disabled = true;

        var value = {};
        FIELDS.forEach(function (f) {
            var el = document.getElementById('sc_' + f);
            value[f] = el ? el.checked : true;
        });

        fetch('/crm/api/ops-settings.php?action=save', {
            method:      'POST',
            credentials: 'same-origin',
            headers:     { 'Content-Type': 'application/json' },
            body:        JSON.stringify({
                key:         'summary_card_config',
                value:       value,
                description: 'Day summary card display settings (mobile schedule page)',
            }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (res) {
                res.className = 'alert alert-' + (data.success ? 'success' : 'danger');
                res.textContent = data.success ? 'Summary card settings saved.' : (data.error || 'Save failed.');
                res.style.display = 'block';
                setTimeout(function () { res.style.display = 'none'; }, 3000);
            }
        })
        .catch(function () {
            if (res) {
                res.className = 'alert alert-danger';
                res.textContent = 'Network error. Please try again.';
                res.style.display = 'block';
            }
        })
        .finally(function () {
            if (btn) btn.disabled = false;
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        loadSummaryCardSettings();

        var saveBtn = document.getElementById('saveSummaryCardBtn');
        if (saveBtn) saveBtn.addEventListener('click', saveSummaryCardSettings);

        var tab = document.getElementById('summary-card-tab');
        if (tab) tab.addEventListener('shown.bs.tab', loadSummaryCardSettings);
    });
})();

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

    // Load on tab click (reliable cross-browser, works before Bootstrap initialises)
    var stTabEl = document.getElementById('service-types-tab');
    if (stTabEl) {
        stTabEl.addEventListener('click', function () {
            setTimeout(function () {
                if (!stLoaded) { stLoaded = true; loadServiceTypes(); }
            }, 50);
        });
    }
    // Also hook shown.bs.tab via jQuery (fires after Bootstrap animates in)
    if (typeof $ !== 'undefined') {
        $(document).on('shown.bs.tab', 'a[href="#service-types"]', function () {
            if (!stLoaded) { stLoaded = true; loadServiceTypes(); }
        });
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

    // Load on tab click (reliable cross-browser, works before Bootstrap initialises)
    var mgTabEl = document.getElementById('measurement-types-tab');
    if (mgTabEl) {
        mgTabEl.addEventListener('click', function () {
            setTimeout(function () {
                if (!mgLoaded) { mgLoaded = true; loadMeasGroups(); }
            }, 50);
        });
    }
    // Also hook shown.bs.tab via jQuery
    if (typeof $ !== 'undefined') {
        $(document).on('shown.bs.tab', 'a[href="#measurement-types"]', function () {
            if (!mgLoaded) { mgLoaded = true; loadMeasGroups(); }
        });
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

<!-- Logo Upload Handler -->
<script>
function handleLogoUpload(input) {
    var file = input.files[0];
    if (!file) return;

    // Validate
    var validTypes = ['image/png', 'image/jpeg', 'image/svg+xml'];
    if (validTypes.indexOf(file.type) === -1) {
        alert('Please select a PNG, JPG, or SVG file.');
        input.value = '';
        return;
    }
    if (file.size > 2 * 1024 * 1024) {
        alert('File is too large. Maximum size is 2 MB.');
        input.value = '';
        return;
    }

    var zone = document.getElementById('logoUploadZone');
    zone.innerHTML = '<span class="spinner-border spinner-border-sm mr-2"></span> Uploading…';

    var csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    var formData = new FormData();
    formData.append('file', file);
    formData.append('csrf_token', csrf);
    formData.append('type', 'logo');

    fetch('/crm/api/upload-logo.php', {
        method: 'POST',
        body: formData
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.success && data.path) {
            document.getElementById('logo_path').value = data.path;
            // Update preview
            var preview = document.getElementById('logoPreview');
            preview.innerHTML = '<img src="' + data.path + '?t=' + Date.now() + '" alt="Logo" style="max-width:200px;max-height:120px;">';
            zone.innerHTML = '<i data-feather="check-circle" style="width:24px;height:24px;color:var(--mw-green);margin-right:8px;"></i>' +
                '<span style="color:var(--mw-green);font-weight:600;">Uploaded!</span> ' +
                '<a href="#" onclick="event.stopPropagation();document.getElementById(\'logoFileInput\').value=\'\';resetLogoZone();return false;" class="ml-2">Change</a>';
            if (typeof feather !== 'undefined') feather.replace();
        } else {
            alert(data.error || 'Upload failed.');
            resetLogoZone();
        }
    })
    .catch(function (e) {
        alert('Upload failed: ' + e.message);
        resetLogoZone();
    });
}

function resetLogoZone() {
    var zone = document.getElementById('logoUploadZone');
    zone.innerHTML = '<i data-feather="upload-cloud" style="width:32px;height:32px;color:var(--mw-green);margin-bottom:8px;"></i>' +
        '<p class="mb-1"><strong>Click to upload</strong> or drag and drop</p>' +
        '<small class="text-muted">PNG, JPG, SVG — max 2 MB</small>';
    if (typeof feather !== 'undefined') feather.replace();
}

// Drag and drop support
document.addEventListener('DOMContentLoaded', function () {
    var zone = document.getElementById('logoUploadZone');
    if (!zone) return;
    ['dragover', 'dragenter'].forEach(function (evt) {
        zone.addEventListener(evt, function (e) {
            e.preventDefault();
            zone.style.borderColor = 'var(--mw-green)';
            zone.style.background = 'var(--mw-light)';
        });
    });
    ['dragleave', 'drop'].forEach(function (evt) {
        zone.addEventListener(evt, function (e) {
            e.preventDefault();
            zone.style.borderColor = '';
            zone.style.background = '';
        });
    });
    zone.addEventListener('drop', function (e) {
        var input = document.getElementById('logoFileInput');
        if (e.dataTransfer.files.length > 0) {
            input.files = e.dataTransfer.files;
            handleLogoUpload(input);
        }
    });
});
</script>

<!-- Unsaved Changes Warning -->
<script>
(function () {
    var formDirty = false;
    var formEl = document.getElementById('settingsForm');
    if (!formEl) return;

    // Track changes on all inputs within the settings form
    formEl.addEventListener('input', function () { formDirty = true; });
    formEl.addEventListener('change', function () { formDirty = true; });

    // Clear dirty flag on successful form submit
    formEl.addEventListener('submit', function () { formDirty = false; });

    // Warn before leaving with unsaved changes
    window.addEventListener('beforeunload', function (e) {
        if (formDirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // Clear dirty flag when switching categories (saves are per-category)
    var origSwitch = window.switchSettingsCategory;
    window.switchSettingsCategory = function (name) {
        if (formDirty) {
            if (!confirm('You have unsaved changes. Switch anyway?')) return;
            formDirty = false;
        }
        origSwitch(name);
    };
})();
</script>

<!-- Email Footer Live Preview -->
<script>
(function () {
    var textarea = document.getElementById('email_footer_html');
    if (!textarea) return;

    // Create preview pane below the textarea
    var wrapper = textarea.closest('.mb-3');
    if (!wrapper) return;

    var previewDiv = document.createElement('div');
    previewDiv.className = 'mt-2';
    previewDiv.innerHTML = '<label class="form-label d-flex align-items-center" style="gap:8px;">' +
        'Preview <small class="text-muted">(live)</small></label>' +
        '<div id="emailFooterPreview" class="mw-email-footer-preview"></div>';
    wrapper.appendChild(previewDiv);

    var previewEl = document.getElementById('emailFooterPreview');

    function updatePreview() {
        var html = textarea.value.trim();
        if (!html) {
            previewEl.innerHTML = '<span class="text-muted" style="font-style:italic;">No footer configured</span>';
        } else {
            // Sanitize: strip script tags for safety
            var clean = html.replace(/<script[\s\S]*?<\/script>/gi, '');
            previewEl.innerHTML = clean;
        }
    }

    textarea.addEventListener('input', updatePreview);
    // Also update when email tab is shown
    var emailTab = document.getElementById('email-tab');
    if (emailTab) {
        emailTab.addEventListener('click', function () { setTimeout(updatePreview, 100); });
    }
    // Initial render (delayed so business-settings.js populates the value first)
    setTimeout(updatePreview, 1500);
})();
</script>

<!-- Test Email Button for Email Settings Tab -->
<script>
(function () {
    var emailTabPane = document.getElementById('email');
    if (!emailTabPane) return;

    // Find the card body and add a Test Email section
    var card = emailTabPane.querySelector('.card');
    if (!card) return;

    var testCard = document.createElement('div');
    testCard.className = 'card mt-3';
    testCard.innerHTML =
        '<div class="card-header"><h5 class="card-title mb-0">Test Email</h5></div>' +
        '<div class="card-body">' +
            '<p class="text-muted mb-3">Send a test email to verify your signature and footer render correctly.</p>' +
            '<div class="input-group mb-3" style="max-width:400px;">' +
                '<input type="email" class="form-control" id="testEmailAddress" placeholder="your@email.com">' +
                '<div class="input-group-append">' +
                    '<button type="button" class="btn btn-outline-primary" id="btnSendTestEmail" data-no-loading="true">' +
                        '<i data-feather="send" style="width:16px;height:16px;"></i> Send Test' +
                    '</button>' +
                '</div>' +
            '</div>' +
            '<div id="testEmailResult" style="display:none;"></div>' +
        '</div>';
    card.after(testCard);

    if (typeof feather !== 'undefined') feather.replace();

    document.getElementById('btnSendTestEmail')?.addEventListener('click', function () {
        var email = document.getElementById('testEmailAddress').value.trim();
        if (!email || email.indexOf('@') === -1) {
            alert('Please enter a valid email address.');
            return;
        }
        var btn = this;
        var res = document.getElementById('testEmailResult');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Sending…';
        res.style.display = 'none';

        var csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        fetch('/crm/api/settings-actions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'send_test_email',
                csrf_token: csrf,
                to_email: email
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            res.style.display = '';
            if (data.success) {
                res.className = 'alert alert-success';
                res.textContent = 'Test email sent! Check your inbox.';
            } else {
                res.className = 'alert alert-danger';
                res.textContent = data.error || 'Failed to send test email.';
            }
        })
        .catch(function (e) {
            res.style.display = '';
            res.className = 'alert alert-danger';
            res.textContent = 'Network error: ' + e.message;
        })
        .finally(function () {
            btn.disabled = false;
            btn.innerHTML = '<i data-feather="send" style="width:16px;height:16px;"></i> Send Test';
            if (typeof feather !== 'undefined') feather.replace();
        });
    });
})();
</script>

<?php include 'includes/appstack_footer.php'; ?>
