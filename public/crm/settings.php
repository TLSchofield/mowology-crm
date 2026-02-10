<?php
require_once __DIR__ . '/../loginAuth/auth.php';
require_once 'includes/error-handler.php';

requireLogin();
$user = getCurrentUser();

// Only admins can access settings
if ($user['role'] !== 'admin') {
    header('Location: dashboard_appstack.php');
    exit;
}

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

        <!-- Database / Migrations Tab -->
        <div class="tab-pane fade" id="database" role="tabpanel">
            <div class="card">
                <div class="card-header"><h5 class="card-title">Database Health</h5></div>
                <div class="card-body">
                    <div id="dbHealthLoading" class="text-center py-3">
                        <div class="spinner-border spinner-border-sm" role="status"></div>
                        <p class="mt-2 small text-muted">Checking database...</p>
                    </div>
                    <div id="dbHealthInfo" style="display: none;">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="text-muted small">Database</label>
                                <p id="dbName" class="font-monospace">-</p>
                            </div>
                            <div class="col-md-6">
                                <label class="text-muted small">MySQL Version</label>
                                <p id="dbVersion" class="font-monospace">-</p>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="text-muted small">Status</label>
                            <div id="dbStatus" class="badge badge-secondary">Checking...</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h5 class="card-title">Pending Migrations (<span id="pendingCount">0</span>)</h5></div>
                <div class="card-body">
                    <div id="pendingMigrationsLoading" class="text-center py-3">
                        <div class="spinner-border spinner-border-sm" role="status"></div>
                        <p class="mt-2 small text-muted">Loading migrations...</p>
                    </div>
                    <div id="pendingMigrationsContainer" style="display: none;">
                        <div id="pendingMigrationsList" class="row"></div>
                        <div id="noPendingMessage" class="alert alert-info" style="display: none;">
                            <strong>Great!</strong> All migrations have been applied. Your database is up to date.
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h5 class="card-title">Migration History</h5></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label small">Filter by Status</label>
                        <select id="historyStatusFilter" class="form-select form-select-sm" style="max-width: 200px;">
                            <option value="all">All Migrations</option>
                            <option value="success">Applied (Success)</option>
                            <option value="failed">Failed</option>
                        </select>
                    </div>
                    <div id="migrationHistoryLoading" class="text-center py-3">
                        <div class="spinner-border spinner-border-sm" role="status"></div>
                        <p class="mt-2 small text-muted">Loading history...</p>
                    </div>
                    <div id="migrationHistoryContainer" style="display: none;">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Migration</th>
                                        <th>Status</th>
                                        <th>Executed By</th>
                                        <th>Executed At</th>
                                    </tr>
                                </thead>
                                <tbody id="migrationHistoryList"></tbody>
                            </table>
                        </div>
                    </div>
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

<script src="js/business-settings.js"></script>
<script src="js/migrations-manager.js"></script>
<?php include 'includes/appstack_footer.php'; ?>
