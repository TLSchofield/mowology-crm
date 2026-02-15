<?php
/**
 * Companies - Edit Company
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('clients.view');

$db = getDB();
$errors = [];

$companyId = (int)($_GET['id'] ?? 0);
if (!$companyId) {
    header('Location: index.php');
    exit;
}

$company = getCompanyById($companyId);
if (!$company) {
    header('Location: index.php');
    exit;
}

// Get contacts for dropdowns
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
        $accountStatus = $_POST['account_status'] ?? 'active';
        $lifecycleStage = $_POST['lifecycle_stage'] ?? 'prospect';
        $notes = trim($_POST['notes'] ?? '');

        $primaryContactId = !empty($_POST['primary_contact_id']) ? (int)$_POST['primary_contact_id'] : null;
        $billingContactId = null;
        if (!empty($_POST['billing_same_as_primary'])) {
            $billingContactId = $primaryContactId;
        } elseif (!empty($_POST['billing_contact_id'])) {
            $billingContactId = (int)$_POST['billing_contact_id'];
        }

        $billingAddress = trim($_POST['billing_address'] ?? '');
        $billingCity = trim($_POST['billing_city'] ?? 'Vancouver');
        $billingProvince = trim($_POST['billing_province'] ?? 'BC');
        $billingPostalCode = trim($_POST['billing_postal_code'] ?? '');
        $billingEmail = trim($_POST['billing_email'] ?? '');
        $billingPhone = trim($_POST['billing_phone'] ?? '');
        $paymentTerms = trim($_POST['payment_terms'] ?? 'Net 30');
        $paymentMethod = $_POST['payment_method'] ?? 'invoice';
        $invoiceRouting = $_POST['invoice_routing_method'] ?? 'primary_contact';

        if ($companyName === '') {
            $errors[] = 'Company name is required.';
        } elseif (mb_strlen($companyName) > 255) {
            $errors[] = 'Company name must be 255 characters or fewer.';
        }

        // Validate billing email (if provided)
        if ($billingEmail !== '' && !filter_var($billingEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Billing email is not a valid email address.';
        }

        // Validate billing phone — accept 10-digit North American numbers
        if ($billingPhone !== '') {
            $phoneDigits = preg_replace('/[^0-9]/', '', $billingPhone);
            if (strlen($phoneDigits) === 11 && $phoneDigits[0] === '1') {
                $phoneDigits = substr($phoneDigits, 1);
            }
            if (strlen($phoneDigits) !== 10) {
                $errors[] = 'Billing phone must be a valid 10-digit phone number (e.g. 604-555-1234).';
            }
        }

        // Validate postal code — Canadian format A1A 1A1
        if ($billingPostalCode !== '') {
            $postalClean = strtoupper(preg_replace('/\s+/', '', $billingPostalCode));
            if (!preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $postalClean)) {
                $errors[] = 'Billing postal code must be a valid Canadian postal code (e.g. V6B 1A1).';
            } else {
                // Normalize to "A1A 1A1" format
                $billingPostalCode = substr($postalClean, 0, 3) . ' ' . substr($postalClean, 3, 3);
            }
        }

        if (empty($errors)) {
            try {
                $stmt = $db->prepare("
                    UPDATE companies SET
                        company_name = ?, company_type = ?, primary_contact_id = ?, billing_contact_id = ?,
                        billing_address = ?, billing_city = ?, billing_province = ?, billing_postal_code = ?,
                        billing_email = ?, billing_phone = ?,
                        account_status = ?, payment_terms = ?, payment_method = ?, lifecycle_stage = ?,
                        invoice_routing_method = ?, notes = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $companyName, $companyType, $primaryContactId, $billingContactId,
                    $billingAddress ?: null, $billingCity, $billingProvince, $billingPostalCode ?: null,
                    $billingEmail ?: null, $billingPhone ?: null,
                    $accountStatus, $paymentTerms, $paymentMethod, $lifecycleStage,
                    $invoiceRouting, $notes ?: null,
                    $companyId
                ]);

                logActivityExtended($user['id'], 'Company updated', "Updated company: {$companyName}", $companyId);

                header("Location: view.php?id={$companyId}&updated=1");
                exit;
            } catch (Exception $e) {
                $errors[] = 'Save failed: ' . $e->getMessage();
            }
        }
    }
}

// Use POST data if available (validation failure), otherwise use DB data
$formData = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $company;

$pageTitle = 'Edit ' . htmlspecialchars($company['company_name']);
$activePage = 'companies';
$apiKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
if ($apiKey) {
    $extraHead = '<script src="https://maps.googleapis.com/maps/api/js?key=' . htmlspecialchars($apiKey, ENT_QUOTES, 'UTF-8') . '&libraries=places" defer></script>';
}
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-1">Edit Company</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0 bg-transparent p-0">
                            <li class="breadcrumb-item"><a href="index.php">Companies</a></li>
                            <li class="breadcrumb-item"><a href="view.php?id=<?= $companyId ?>"><?= htmlspecialchars($company['company_name']) ?></a></li>
                            <li class="breadcrumb-item active">Edit</li>
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
                                           value="<?= htmlspecialchars($formData['company_name'] ?? '') ?>" required>
                                </div>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="company_type">Company Type</label>
                                            <select id="company_type" name="company_type" class="form-control">
                                                <?php
                                                $types = ['individual' => 'Individual', 'business' => 'Business', 'strata' => 'Strata', 'property_manager' => 'Property Manager'];
                                                foreach ($types as $val => $label): ?>
                                                    <option value="<?= $val ?>" <?= ($formData['company_type'] ?? 'individual') === $val ? 'selected' : '' ?>>
                                                        <?= $label ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="lifecycle_stage">Lifecycle Stage</label>
                                            <select id="lifecycle_stage" name="lifecycle_stage" class="form-control">
                                                <?php if (!empty($stages)): ?>
                                                    <?php foreach ($stages as $stage): ?>
                                                        <option value="<?= htmlspecialchars($stage['stage_key']) ?>"
                                                                <?= ($formData['lifecycle_stage'] ?? 'prospect') === $stage['stage_key'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($stage['stage_label']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <option value="prospect" <?= ($formData['lifecycle_stage'] ?? '') === 'prospect' ? 'selected' : '' ?>>Prospect</option>
                                                    <option value="client" <?= ($formData['lifecycle_stage'] ?? '') === 'client' ? 'selected' : '' ?>>Client</option>
                                                    <option value="inactive" <?= ($formData['lifecycle_stage'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="account_status">Account Status</label>
                                            <select id="account_status" name="account_status" class="form-control">
                                                <option value="active" <?= ($formData['account_status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                                                <option value="inactive" <?= ($formData['account_status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Archived</option>
                                                <option value="suspended" <?= ($formData['account_status'] ?? '') === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group mb-0">
                                    <label for="notes">Internal Notes</label>
                                    <textarea id="notes" name="notes" class="form-control" rows="3"><?= htmlspecialchars($formData['notes'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Primary Contact -->
                        <div class="card mb-4">
                            <div class="card-header"><h5 class="card-title mb-0">Primary Contact</h5></div>
                            <div class="card-body">
                                <div class="form-group mb-0">
                                    <label for="primary_contact_id">Select Contact</label>
                                    <select id="primary_contact_id" name="primary_contact_id" class="form-control">
                                        <option value="">— No contact —</option>
                                        <?php foreach ($contacts as $ct): ?>
                                            <option value="<?= $ct['id'] ?>" <?= ($formData['primary_contact_id'] ?? '') == $ct['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars(trim($ct['first_name'] . ' ' . $ct['last_name'])) ?>
                                                <?php if ($ct['email']): ?>(<?= htmlspecialchars($ct['email']) ?>)<?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
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
                                               <?php
                                               $sameContact = ($formData['primary_contact_id'] ?? null) && ($formData['primary_contact_id'] == ($formData['billing_contact_id'] ?? null));
                                               echo $sameContact ? 'checked' : '';
                                               ?>>
                                        <label class="custom-control-label" for="billing_same_as_primary">Billing contact same as primary</label>
                                    </div>
                                </div>
                                <div id="billingContactSection" <?= $sameContact ? 'style="display:none;"' : '' ?>>
                                    <div class="form-group">
                                        <label for="billing_contact_id">Billing Contact</label>
                                        <select id="billing_contact_id" name="billing_contact_id" class="form-control">
                                            <option value="">— No billing contact —</option>
                                            <?php foreach ($contacts as $ct): ?>
                                                <option value="<?= $ct['id'] ?>" <?= ($formData['billing_contact_id'] ?? '') == $ct['id'] ? 'selected' : '' ?>>
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
                                    <input type="text" name="billing_address" id="billingAddress" class="form-control"
                                           value="<?= htmlspecialchars($formData['billing_address'] ?? '') ?>"
                                           placeholder="Start typing an address..." autocomplete="off">
                                </div>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label>City</label>
                                            <input type="text" name="billing_city" id="billingCity" class="form-control"
                                                   value="<?= htmlspecialchars($formData['billing_city'] ?? 'Vancouver') ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label>Province</label>
                                            <input type="text" name="billing_province" id="billingProvince" class="form-control"
                                                   value="<?= htmlspecialchars($formData['billing_province'] ?? 'BC') ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label>Postal Code</label>
                                            <input type="text" name="billing_postal_code" id="billingPostalCode" class="form-control"
                                                   value="<?= htmlspecialchars($formData['billing_postal_code'] ?? '') ?>">
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Billing Email</label>
                                            <input type="email" name="billing_email" class="form-control"
                                                   value="<?= htmlspecialchars($formData['billing_email'] ?? '') ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group mb-0">
                                            <label>Billing Phone</label>
                                            <input type="text" name="billing_phone" class="form-control"
                                                   value="<?= htmlspecialchars($formData['billing_phone'] ?? '') ?>">
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
                                           value="<?= htmlspecialchars($formData['payment_terms'] ?? 'Net 30') ?>">
                                </div>
                                <div class="form-group">
                                    <label>Payment Method</label>
                                    <select name="payment_method" class="form-control">
                                        <?php
                                        $methods = ['invoice' => 'Invoice', 'credit_card' => 'Credit Card', 'bank_transfer' => 'Bank Transfer', 'cheque' => 'Cheque'];
                                        foreach ($methods as $val => $label): ?>
                                            <option value="<?= $val ?>" <?= ($formData['payment_method'] ?? 'invoice') === $val ? 'selected' : '' ?>>
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
                                            <option value="<?= $val ?>" <?= ($formData['invoice_routing_method'] ?? 'primary_contact') === $val ? 'selected' : '' ?>>
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
                                    <i data-feather="save" class="align-middle mr-1" style="width:16px;height:16px;"></i> Save Changes
                                </button>
                                <a href="view.php?id=<?= $companyId ?>" class="btn btn-outline-secondary btn-block">Cancel</a>
                            </div>
                        </div>

                        <!-- Meta info -->
                        <div class="card">
                            <div class="card-body">
                                <small class="text-muted">
                                    Created: <?= formatDateTime($company['created_at'] ?? '') ?><br>
                                    Updated: <?= formatDateTime($company['updated_at'] ?? '') ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

            <script>
            document.addEventListener('DOMContentLoaded', function() {
                var billingCheckbox = document.getElementById('billing_same_as_primary');
                var billingSection = document.getElementById('billingContactSection');

                billingCheckbox.addEventListener('change', function() {
                    billingSection.style.display = this.checked ? 'none' : '';
                });

                // ── Client-side Validation ──
                var form = document.getElementById('companyForm');
                var emailInput = form.querySelector('[name="billing_email"]');
                var phoneInput = form.querySelector('[name="billing_phone"]');
                var postalInput = form.querySelector('[name="billing_postal_code"]');

                function setValidity(input, isValid, message) {
                    var feedback = input.parentNode.querySelector('.invalid-feedback');
                    if (!feedback) {
                        feedback = document.createElement('div');
                        feedback.className = 'invalid-feedback';
                        input.parentNode.appendChild(feedback);
                    }
                    if (isValid || input.value.trim() === '') {
                        input.classList.remove('is-invalid');
                        if (input.value.trim() !== '') input.classList.add('is-valid');
                        else input.classList.remove('is-valid');
                    } else {
                        input.classList.remove('is-valid');
                        input.classList.add('is-invalid');
                        feedback.textContent = message;
                    }
                }

                function validateEmail() {
                    var val = emailInput.value.trim();
                    if (val === '') { setValidity(emailInput, true, ''); return true; }
                    var valid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val);
                    setValidity(emailInput, valid, 'Please enter a valid email address.');
                    return valid;
                }

                function validatePhone() {
                    var val = phoneInput.value.trim();
                    if (val === '') { setValidity(phoneInput, true, ''); return true; }
                    var digits = val.replace(/[^0-9]/g, '');
                    if (digits.length === 11 && digits[0] === '1') digits = digits.substring(1);
                    var valid = digits.length === 10;
                    setValidity(phoneInput, valid, 'Enter a valid 10-digit phone number (e.g. 604-555-1234).');
                    return valid;
                }

                function validatePostal() {
                    var val = postalInput.value.trim();
                    if (val === '') { setValidity(postalInput, true, ''); return true; }
                    var clean = val.toUpperCase().replace(/\s+/g, '');
                    var valid = /^[A-Z]\d[A-Z]\d[A-Z]\d$/.test(clean);
                    setValidity(postalInput, valid, 'Enter a valid Canadian postal code (e.g. V6B 1A1).');
                    if (valid) {
                        postalInput.value = clean.substring(0, 3) + ' ' + clean.substring(3, 6);
                    }
                    return valid;
                }

                // Format phone on blur
                phoneInput.addEventListener('blur', function() {
                    var val = phoneInput.value.trim();
                    if (val === '') return;
                    var digits = val.replace(/[^0-9]/g, '');
                    if (digits.length === 11 && digits[0] === '1') digits = digits.substring(1);
                    if (digits.length === 10) {
                        phoneInput.value = digits.substring(0, 3) + '-' + digits.substring(3, 6) + '-' + digits.substring(6, 10);
                    }
                    validatePhone();
                });

                emailInput.addEventListener('blur', validateEmail);
                postalInput.addEventListener('blur', validatePostal);

                form.addEventListener('submit', function(e) {
                    var valid = true;
                    if (!validateEmail()) valid = false;
                    if (!validatePhone()) valid = false;
                    if (!validatePostal()) valid = false;
                    if (!valid) {
                        e.preventDefault();
                        var firstInvalid = form.querySelector('.is-invalid');
                        if (firstInvalid) firstInvalid.focus();
                    }
                });
            });

            // ── Google Places Address Autocomplete ──
            function initAddressAutocomplete(inputId, cityId, postalId, provinceId) {
              var input = document.getElementById(inputId);
              if (!input) return;
              if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
                setTimeout(function() { initAddressAutocomplete(inputId, cityId, postalId, provinceId); }, 200);
                return;
              }
              var ac = new google.maps.places.Autocomplete(input, {
                types: ['address'],
                componentRestrictions: { country: ['ca'] },
                fields: ['address_components', 'geometry']
              });
              ac.addListener('place_changed', function() {
                var place = ac.getPlace();
                if (!place || !place.address_components) return;
                var street = '', city = '', postal = '', province = '';
                for (var i = 0; i < place.address_components.length; i++) {
                  var c = place.address_components[i];
                  if (c.types.indexOf('street_number') !== -1) street = c.long_name + ' ' + street;
                  if (c.types.indexOf('route') !== -1) street = street + c.long_name;
                  if (c.types.indexOf('locality') !== -1) city = c.long_name;
                  if (c.types.indexOf('postal_code') !== -1) postal = c.long_name;
                  if (c.types.indexOf('administrative_area_level_1') !== -1) province = c.short_name;
                }
                if (street.trim()) input.value = street.trim();
                var cityEl = document.getElementById(cityId);
                if (cityEl && city) cityEl.value = city;
                var postalEl = document.getElementById(postalId);
                if (postalEl && postal) postalEl.value = postal;
                if (provinceId) {
                  var provEl = document.getElementById(provinceId);
                  if (provEl && province) provEl.value = province;
                }
              });
            }
            initAddressAutocomplete('billingAddress', 'billingCity', 'billingPostalCode', 'billingProvince');
            </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
