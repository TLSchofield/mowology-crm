<?php
/**
 * Companies - Create New Company
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('clients.view');

$db = getDB();
$errors = [];
$success = false;

// Get contacts for dropdown
$contactsStmt = $db->query("SELECT id, first_name, last_name, email, phone FROM contacts WHERE is_active = 1 ORDER BY first_name ASC, last_name ASC");
$contacts = $contactsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get lifecycle stages
$stages = getLifecycleStages('company');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission. Please try again.';
    } else {
        $companyName = trim($_POST['company_name'] ?? '');
        $companyType = $_POST['company_type'] ?? 'individual';
        $lifecycleStage = $_POST['lifecycle_stage'] ?? 'prospect';
        $notes = trim($_POST['notes'] ?? '');

        // Primary contact
        $primaryContactSource = $_POST['primary_contact_source'] ?? 'existing';
        $primaryContactId = null;

        // Billing info
        $billingAddress = trim($_POST['billing_address'] ?? '');
        $billingCity = trim($_POST['billing_city'] ?? 'Vancouver');
        $billingProvince = trim($_POST['billing_province'] ?? 'BC');
        $billingPostalCode = trim($_POST['billing_postal_code'] ?? '');
        $billingEmail = trim($_POST['billing_email'] ?? '');
        $billingPhone = trim($_POST['billing_phone'] ?? '');
        $paymentTerms = trim($_POST['payment_terms'] ?? 'Net 30');
        $paymentMethod = $_POST['payment_method'] ?? 'invoice';
        $invoiceRouting = $_POST['invoice_routing_method'] ?? 'primary_contact';

        // Validation
        if ($companyName === '') {
            $errors[] = 'Company name is required.';
        }

        if (empty($errors)) {
            try {
                $db->beginTransaction();

                // Handle primary contact
                if ($primaryContactSource === 'new') {
                    $newFirst = trim($_POST['new_contact_first_name'] ?? '');
                    $newLast = trim($_POST['new_contact_last_name'] ?? '');
                    $newEmail = trim($_POST['new_contact_email'] ?? '');
                    $newPhone = trim($_POST['new_contact_phone'] ?? '');

                    if ($newFirst === '') {
                        throw new Exception('Contact first name is required when creating a new contact.');
                    }

                    $stmt = $db->prepare("
                        INSERT INTO contacts (first_name, last_name, email, phone, is_active)
                        VALUES (?, ?, ?, ?, 1)
                    ");
                    $stmt->execute([$newFirst, $newLast, $newEmail ?: null, $newPhone ?: null]);
                    $primaryContactId = $db->lastInsertId();
                } elseif (!empty($_POST['primary_contact_id'])) {
                    $primaryContactId = (int)$_POST['primary_contact_id'];
                }

                // Billing contact
                $billingContactId = null;
                if (!empty($_POST['billing_same_as_primary'])) {
                    $billingContactId = $primaryContactId;
                } elseif (!empty($_POST['billing_contact_id'])) {
                    $billingContactId = (int)$_POST['billing_contact_id'];
                }

                // Insert company
                $stmt = $db->prepare("
                    INSERT INTO companies (
                        company_name, company_type, primary_contact_id, billing_contact_id,
                        billing_address, billing_city, billing_province, billing_postal_code,
                        billing_email, billing_phone,
                        account_status, payment_terms, payment_method, lifecycle_stage,
                        invoice_routing_method, notes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $companyName, $companyType, $primaryContactId, $billingContactId,
                    $billingAddress ?: null, $billingCity, $billingProvince, $billingPostalCode ?: null,
                    $billingEmail ?: null, $billingPhone ?: null,
                    $paymentTerms, $paymentMethod, $lifecycleStage,
                    $invoiceRouting, $notes ?: null
                ]);
                $companyId = $db->lastInsertId();

                // Log activity
                logActivityExtended($user['id'], 'Company created', "Created company: {$companyName}", $companyId);

                $db->commit();

                header("Location: view.php?id={$companyId}&created=1");
                exit;
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $errors[] = $e->getMessage();
            }
        }
    }
}

$pageTitle = 'New Company';
$activePage = 'companies';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-1">New Company</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0 bg-transparent p-0">
                            <li class="breadcrumb-item"><a href="index.php">Companies</a></li>
                            <li class="breadcrumb-item active">New</li>
                        </ol>
                    </nav>
                </div>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $err): ?>
                            <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" id="companyForm">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">

                <div class="row">
                    <div class="col-lg-8">

                        <!-- Company Information -->
                        <div class="card mb-4">
                            <div class="card-header"><h5 class="card-title mb-0">Company Information</h5></div>
                            <div class="card-body">
                                <div class="form-group">
                                    <label for="company_name">Company Name <span class="text-danger">*</span></label>
                                    <input type="text" id="company_name" name="company_name" class="form-control"
                                           value="<?= htmlspecialchars($_POST['company_name'] ?? '') ?>" required>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="company_type">Company Type</label>
                                            <select id="company_type" name="company_type" class="form-control">
                                                <?php
                                                $types = ['individual' => 'Individual', 'business' => 'Business', 'strata' => 'Strata', 'property_manager' => 'Property Manager'];
                                                foreach ($types as $val => $label): ?>
                                                    <option value="<?= $val ?>" <?= ($_POST['company_type'] ?? 'individual') === $val ? 'selected' : '' ?>>
                                                        <?= $label ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="lifecycle_stage">Lifecycle Stage</label>
                                            <select id="lifecycle_stage" name="lifecycle_stage" class="form-control">
                                                <?php if (!empty($stages)): ?>
                                                    <?php foreach ($stages as $stage): ?>
                                                        <option value="<?= htmlspecialchars($stage['stage_key']) ?>"
                                                                <?= ($_POST['lifecycle_stage'] ?? 'prospect') === $stage['stage_key'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($stage['stage_label']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <option value="prospect" selected>Prospect</option>
                                                    <option value="client">Client</option>
                                                    <option value="inactive">Inactive</option>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group mb-0">
                                    <label for="notes">Internal Notes</label>
                                    <textarea id="notes" name="notes" class="form-control" rows="3"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Primary Contact -->
                        <div class="card mb-4">
                            <div class="card-header"><h5 class="card-title mb-0">Primary Contact</h5></div>
                            <div class="card-body">
                                <div class="form-group">
                                    <div class="custom-control custom-radio custom-control-inline">
                                        <input type="radio" id="contact_existing" name="primary_contact_source" value="existing"
                                               class="custom-control-input" <?= ($_POST['primary_contact_source'] ?? 'existing') === 'existing' ? 'checked' : '' ?>>
                                        <label class="custom-control-label" for="contact_existing">Select Existing Contact</label>
                                    </div>
                                    <div class="custom-control custom-radio custom-control-inline">
                                        <input type="radio" id="contact_new" name="primary_contact_source" value="new"
                                               class="custom-control-input" <?= ($_POST['primary_contact_source'] ?? '') === 'new' ? 'checked' : '' ?>>
                                        <label class="custom-control-label" for="contact_new">Create New Contact</label>
                                    </div>
                                </div>

                                <!-- Existing contact selector -->
                                <div id="existingContactSection">
                                    <div class="form-group mb-0">
                                        <label for="primary_contact_id">Select Contact</label>
                                        <select id="primary_contact_id" name="primary_contact_id" class="form-control">
                                            <option value="">— No contact —</option>
                                            <?php foreach ($contacts as $ct): ?>
                                                <option value="<?= $ct['id'] ?>" <?= ($_POST['primary_contact_id'] ?? '') == $ct['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars(trim($ct['first_name'] . ' ' . $ct['last_name'])) ?>
                                                    <?php if ($ct['email']): ?>(<?= htmlspecialchars($ct['email']) ?>)<?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <!-- New contact form -->
                                <div id="newContactSection" style="display:none;">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>First Name <span class="text-danger">*</span></label>
                                                <input type="text" name="new_contact_first_name" class="form-control"
                                                       value="<?= htmlspecialchars($_POST['new_contact_first_name'] ?? '') ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Last Name</label>
                                                <input type="text" name="new_contact_last_name" class="form-control"
                                                       value="<?= htmlspecialchars($_POST['new_contact_last_name'] ?? '') ?>">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Email</label>
                                                <input type="email" name="new_contact_email" class="form-control"
                                                       value="<?= htmlspecialchars($_POST['new_contact_email'] ?? '') ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group mb-0">
                                                <label>Phone</label>
                                                <input type="text" name="new_contact_phone" class="form-control"
                                                       value="<?= htmlspecialchars($_POST['new_contact_phone'] ?? '') ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Billing Information -->
                        <div class="card mb-4">
                            <div class="card-header"><h5 class="card-title mb-0">Billing Information</h5></div>
                            <div class="card-body">
                                <div class="form-group">
                                    <div class="custom-control custom-checkbox">
                                        <input type="checkbox" id="billing_same_as_primary" name="billing_same_as_primary"
                                               value="1" class="custom-control-input"
                                               <?= !empty($_POST['billing_same_as_primary']) ? 'checked' : '' ?>>
                                        <label class="custom-control-label" for="billing_same_as_primary">Billing contact same as primary</label>
                                    </div>
                                </div>
                                <div id="billingContactSection">
                                    <div class="form-group">
                                        <label for="billing_contact_id">Billing Contact</label>
                                        <select id="billing_contact_id" name="billing_contact_id" class="form-control">
                                            <option value="">— No billing contact —</option>
                                            <?php foreach ($contacts as $ct): ?>
                                                <option value="<?= $ct['id'] ?>" <?= ($_POST['billing_contact_id'] ?? '') == $ct['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars(trim($ct['first_name'] . ' ' . $ct['last_name'])) ?>
                                                    <?php if ($ct['email']): ?>(<?= htmlspecialchars($ct['email']) ?>)<?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <hr>

                                <div class="form-group">
                                    <label>Billing Address</label>
                                    <input type="text" name="billing_address" class="form-control"
                                           value="<?= htmlspecialchars($_POST['billing_address'] ?? '') ?>">
                                </div>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label>City</label>
                                            <input type="text" name="billing_city" class="form-control"
                                                   value="<?= htmlspecialchars($_POST['billing_city'] ?? 'Vancouver') ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label>Province</label>
                                            <input type="text" name="billing_province" class="form-control"
                                                   value="<?= htmlspecialchars($_POST['billing_province'] ?? 'BC') ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label>Postal Code</label>
                                            <input type="text" name="billing_postal_code" class="form-control"
                                                   value="<?= htmlspecialchars($_POST['billing_postal_code'] ?? '') ?>">
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Billing Email</label>
                                            <input type="email" name="billing_email" class="form-control"
                                                   value="<?= htmlspecialchars($_POST['billing_email'] ?? '') ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group mb-0">
                                            <label>Billing Phone</label>
                                            <input type="text" name="billing_phone" class="form-control"
                                                   value="<?= htmlspecialchars($_POST['billing_phone'] ?? '') ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div class="col-lg-4">
                        <div class="card mb-4">
                            <div class="card-header"><h5 class="card-title mb-0">Payment Settings</h5></div>
                            <div class="card-body">
                                <div class="form-group">
                                    <label>Payment Terms</label>
                                    <input type="text" name="payment_terms" class="form-control"
                                           value="<?= htmlspecialchars($_POST['payment_terms'] ?? 'Net 30') ?>">
                                </div>
                                <div class="form-group">
                                    <label>Payment Method</label>
                                    <select name="payment_method" class="form-control">
                                        <?php
                                        $methods = ['invoice' => 'Invoice', 'credit_card' => 'Credit Card', 'bank_transfer' => 'Bank Transfer', 'cheque' => 'Cheque'];
                                        foreach ($methods as $val => $label): ?>
                                            <option value="<?= $val ?>" <?= ($_POST['payment_method'] ?? 'invoice') === $val ? 'selected' : '' ?>>
                                                <?= $label ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group mb-0">
                                    <label>Invoice Routing</label>
                                    <select name="invoice_routing_method" class="form-control">
                                        <?php
                                        $routing = [
                                            'primary_contact' => 'Primary Contact',
                                            'billing_contact' => 'Billing Contact',
                                            'both_contacts' => 'Both Contacts',
                                            'email_address' => 'Email Address'
                                        ];
                                        foreach ($routing as $val => $label): ?>
                                            <option value="<?= $val ?>" <?= ($_POST['invoice_routing_method'] ?? 'primary_contact') === $val ? 'selected' : '' ?>>
                                                <?= $label ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="card mb-4">
                            <div class="card-body">
                                <button type="submit" class="btn btn-primary btn-block mb-2">
                                    <i data-feather="save" class="align-middle mr-1" style="width:16px;height:16px;"></i> Create Company
                                </button>
                                <a href="index.php" class="btn btn-outline-secondary btn-block">Cancel</a>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

            <script>
            document.addEventListener('DOMContentLoaded', function() {
                var sourceRadios = document.querySelectorAll('input[name="primary_contact_source"]');
                var existingSection = document.getElementById('existingContactSection');
                var newSection = document.getElementById('newContactSection');
                var billingCheckbox = document.getElementById('billing_same_as_primary');
                var billingSection = document.getElementById('billingContactSection');

                function toggleContactSections() {
                    var val = document.querySelector('input[name="primary_contact_source"]:checked').value;
                    existingSection.style.display = val === 'existing' ? '' : 'none';
                    newSection.style.display = val === 'new' ? '' : 'none';
                }

                function toggleBillingContact() {
                    billingSection.style.display = billingCheckbox.checked ? 'none' : '';
                }

                sourceRadios.forEach(function(r) { r.addEventListener('change', toggleContactSections); });
                billingCheckbox.addEventListener('change', toggleBillingContact);

                toggleContactSections();
                toggleBillingContact();
            });
            </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
