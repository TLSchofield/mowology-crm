<?php
/**
 * Create Invoice - from Job or Manual
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('billing.edit');

$db = getDB();
$error = '';
$prefill = [];

// Check if creating from a completed visit
$visitId = isset($_GET['visit_id']) ? intval($_GET['visit_id']) : 0;

if ($visitId) {
    // New path: create invoice from a completed visit
    // LEFT JOINs on company/contact since plans may link to contacts not companies
    $stmt = $db->prepare("
        SELECT jv.id as visit_id, jv.visit_number, jv.actual_amount,
               jv.plan_id, jv.scheduled_date, jv.invoice_id as existing_invoice_id,
               jp.plan_number, jp.title, jp.price_per_visit, jp.estimated_amount,
               jp.property_id, jp.company_id,
               c.company_name,
               p.address, p.city, p.province, p.postal_code,
               p.site_contact_id,
               COALESCE(con.first_name, '') as contact_first,
               COALESCE(con.last_name, '') as contact_last
        FROM job_visits jv
        JOIN job_plans jp ON jv.plan_id = jp.id
        LEFT JOIN companies c ON jp.company_id = c.id
        LEFT JOIN properties p ON jp.property_id = p.id
        LEFT JOIN contacts con ON p.site_contact_id = con.id
        WHERE jv.id = ? AND jv.status = 'completed'
    ");
    $stmt->execute([$visitId]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($visit) {
        // Redirect if already invoiced
        if (!empty($visit['existing_invoice_id'])) {
            header("Location: view.php?id={$visit['existing_invoice_id']}&already_invoiced=1");
            exit;
        }
        $visitAmount = $visit['actual_amount'] ?: $visit['price_per_visit'] ?: $visit['estimated_amount'];
        $contactName = trim($visit['contact_first'] . ' ' . $visit['contact_last']) ?: null;
        $prefill = [
            'company_id'        => $visit['company_id'],
            'property_id'       => $visit['property_id'],
            'contact_id'        => $visit['site_contact_id'],
            'visit_id'          => $visitId,
            'plan_id'           => $visit['plan_id'],
            'scheduled_date'    => $visit['scheduled_date'],
            'description'       => $visit['title'] . ' — ' . date('M j, Y', strtotime($visit['scheduled_date'])),
            'amount'            => $visitAmount,
            'company_name'      => $visit['company_name'] ?: $contactName,
            'service_address'   => $visit['address'] ?? '',
            'service_city'      => $visit['city'] ?? '',
            'service_province'  => $visit['province'] ?? 'BC',
            'service_postal'    => $visit['postal_code'] ?? '',
            'visit_number'      => $visit['visit_number'],
            'plan_number'       => $visit['plan_number'],
        ];
    }
}

// Get companies for dropdown (if not from job)
$companies = $db->query("
    SELECT c.id, c.company_name, c.billing_email
    FROM companies c
    ORDER BY c.company_name
")->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $companyId = intval($_POST['company_id'] ?? 0);
        $linkedVisitId = intval($_POST['visit_id'] ?? 0);
        $linkedPlanId = intval($_POST['plan_id'] ?? 0);
        $propertyId = intval($_POST['property_id'] ?? 0);
        $issueDate = $_POST['issue_date'] ?? date('Y-m-d');
        $dueDate = $_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days'));
        $description = trim($_POST['description'] ?? '');
        $subtotal = floatval($_POST['subtotal'] ?? 0);
        $taxRate = 0.05;
        $taxAmount = round($subtotal * $taxRate, 2);
        $total = $subtotal + $taxAmount;
        $notes = trim($_POST['notes'] ?? '');

        // Phase 2-3: Address fields
        $serviceAddress = trim($_POST['service_address'] ?? '');
        $serviceCity = trim($_POST['service_city'] ?? '');
        $serviceProvince = trim($_POST['service_province'] ?? '');
        $servicePostalCode = trim($_POST['service_postal_code'] ?? '');

        $billingAddress = trim($_POST['billing_address'] ?? '');
        $billingCity = trim($_POST['billing_city'] ?? '');
        $billingProvince = trim($_POST['billing_province'] ?? '');
        $billingPostalCode = trim($_POST['billing_postal_code'] ?? '');

        // Phase 2-3: Recipient selections (JSON array of contact IDs to include)
        $selectedRecipients = [];
        if (!empty($_POST['selected_recipients'])) {
            $selectedRecipients = json_decode($_POST['selected_recipients'], true) ?? [];
            $selectedRecipients = array_filter(array_map('intval', $selectedRecipients));
        }

        // Determine if addresses differ
        $addressDiffers = (
            ($serviceAddress !== $billingAddress) ||
            ($serviceCity !== $billingCity) ||
            ($serviceProvince !== $billingProvince) ||
            ($servicePostalCode !== $billingPostalCode)
        ) ? 1 : 0;

        if (!$companyId) {
            $error = 'Please select a customer.';
        } elseif ($subtotal <= 0) {
            $error = 'Please enter a valid amount.';
        } elseif (empty($selectedRecipients)) {
            $error = 'Please select at least one invoice recipient.';
        } else {
            try {
                $db->beginTransaction();

                $invoiceNumber = generateInvoiceNumber();
                $accessToken = generateAccessToken();

                // Phase 2-3: Include address fields in invoice insert
                $stmt = $db->prepare("
                    INSERT INTO invoices (
                        invoice_number, company_id, property_id,
                        plan_id, visit_id,
                        issue_date, due_date, subtotal, tax_rate, tax_amount,
                        total, balance_due, notes, access_token, token_expires_at,
                        service_address, service_city, service_province, service_postal_code,
                        billing_address, billing_city, billing_province, billing_postal_code,
                        address_differs,
                        status, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 90 DAY), ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)
                ");
                $stmt->execute([
                    $invoiceNumber,
                    $companyId,
                    $propertyId ?: null,
                    $linkedPlanId ?: null,
                    $linkedVisitId ?: null,
                    $issueDate,
                    $dueDate,
                    $subtotal,
                    $taxRate,
                    $taxAmount,
                    $total,
                    $total, // balance_due starts as total
                    $notes,
                    $accessToken,
                    $serviceAddress,
                    $serviceCity,
                    $serviceProvince,
                    $servicePostalCode,
                    $billingAddress,
                    $billingCity,
                    $billingProvince,
                    $billingPostalCode,
                    $addressDiffers,
                    $user['id']
                ]);

                $invoiceId = $db->lastInsertId();

                // Add line item
                $stmt = $db->prepare("
                    INSERT INTO invoice_line_items (invoice_id, description, quantity, unit_price, line_total, visit_id)
                    VALUES (?, ?, 1, ?, ?, ?)
                ");
                $stmt->execute([
                    $invoiceId,
                    $description ?: 'Services rendered',
                    $subtotal,
                    $subtotal,
                    $linkedVisitId ?: null
                ]);

                // Phase 2-3: Insert selected recipients into invoice_contacts table
                $recipientContacts = $db->prepare("
                    SELECT id, first_name, last_name, email, receive_sms
                    FROM contacts WHERE id = ?
                ");

                $insertRecipient = $db->prepare("
                    INSERT INTO invoice_contacts (
                        invoice_id, contact_id, contact_role, email_address
                    ) VALUES (?, ?, ?, ?)
                ");

                $recipientNames = [];
                $smsRecipients = [];

                foreach ($selectedRecipients as $contactId) {
                    $recipientContacts->execute([$contactId]);
                    $contact = $recipientContacts->fetch(PDO::FETCH_ASSOC);

                    if ($contact) {
                        $insertRecipient->execute([
                            $invoiceId,
                            $contactId,
                            'primary_recipient',
                            $contact['email']
                        ]);

                        $recipientNames[] = "{$contact['first_name']} {$contact['last_name']}";

                        // Track SMS recipients (those who have consent)
                        if ($contact['receive_sms']) {
                            $smsRecipients[] = [
                                'contact_id' => $contactId,
                                'phone' => $contact['phone'] ?? null,
                                'email' => $contact['email']
                            ];
                        }
                    }
                }

                // Log with recipient details
                $recipientList = implode(', ', $recipientNames);
                $details = "Invoice {$invoiceNumber} created for " . ($recipientNames ? $recipientList : 'no recipients');
                if (!empty($smsRecipients)) {
                    $details .= " (SMS to: " . count($smsRecipients) . " recipients)";
                }

                logActivityExtended($user['id'], 'Invoice created', $details, $companyId, null, null, $invoiceId, $linkedPlanId ?: null, $linkedVisitId ?: null);

                // Mark the visit as invoiced
                if ($linkedVisitId) {
                    $db->prepare("UPDATE job_visits SET is_invoiced = 1, invoice_id = ? WHERE id = ?")
                       ->execute([$invoiceId, $linkedVisitId]);
                }

                $db->commit();

                header("Location: view.php?id={$invoiceId}&created=1");
                exit;

            } catch (Exception $e) {
                $db->rollBack();
                error_log("Invoice creation error: " . $e->getMessage());
                $error = 'Error creating invoice. Please try again.';
            }
        }
    }
}

$csrfToken = generateCSRFToken();

$pageTitle = 'Create Invoice';
$activePage = 'invoices';
$apiKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
if ($apiKey) {
    $extraHead = '<script src="https://maps.googleapis.com/maps/api/js?key=' . htmlspecialchars($apiKey, ENT_QUOTES, 'UTF-8') . '&libraries=places" defer></script>';
}
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

            <a href="index.php" class="mw-back-link">&larr; Back to Invoices</a>

            <h1 class="h3 mb-3">Create Invoice</h1>
            <p class="text-muted mb-4"><?php
                if ($visitId && isset($visit)) {
                    echo 'Creating invoice from completed visit';
                } elseif (!empty($jobId) && isset($job)) {
                    echo 'Creating invoice from completed job';
                } else {
                    echo 'Create a new invoice';
                }
            ?></p>

            <?php if ($error): ?>
                <div class="mw-error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($visitId && isset($visit)): ?>
                <div class="mw-info-banner">
                    <strong>Creating from Visit <?php echo htmlspecialchars($visit['visit_number']); ?></strong><br>
                    Plan: <?php echo htmlspecialchars($visit['plan_number'] . ' — ' . $visit['title']); ?><br>
                    <?php echo htmlspecialchars($visit['company_name'] ?? $prefill['company_name'] ?? ''); ?><?php if (!empty($visit['address'])): ?> - <?php echo htmlspecialchars($visit['address']); ?><?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="invoiceForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="visit_id" value="<?php echo $prefill['visit_id'] ?? ''; ?>">
                <input type="hidden" name="plan_id" value="<?php echo $prefill['plan_id'] ?? ''; ?>">
                <input type="hidden" name="property_id" id="propertyIdInput" value="<?php echo $prefill['property_id'] ?? ''; ?>">
                <input type="hidden" name="selected_recipients" id="selectedRecipientsInput" value="[]">

                <div class="card">
                    <div class="card-body">
                        <h3 class="card-title">Invoice Details</h3>

                        <div class="mw-form-group">
                            <label class="form-label">Customer *</label>
                            <?php if ($visitId && !empty($prefill['company_name'])): ?>
                                <input type="hidden" name="company_id" value="<?php echo (int)($prefill['company_id'] ?? 0); ?>">
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($prefill['company_name']); ?>" readonly>
                            <?php else: ?>
                                <select name="company_id" id="companySelect" class="form-control" required>
                                    <option value="">Select customer...</option>
                                    <?php foreach ($companies as $company): ?>
                                        <option value="<?php echo $company['id']; ?>"><?php echo htmlspecialchars($company['company_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>

                        <!-- Phase 2: Property & Recipient Selector (hidden when prefilling from visit) -->
                        <div class="mw-form-group"<?php if ($visitId && !empty($prefill['property_id'])): ?> style="display:none"<?php endif; ?>>
                            <label class="form-label">Property (optional)</label>
                            <select id="propertySelect" class="form-control">
                                <option value="">Select property to load recipients...</option>
                            </select>
                            <small class="text-muted">Select a property to automatically populate invoice recipients based on their management setup</small>
                        </div>

                        <!-- Phase 2: Recipient Preview Table -->
                        <div id="recipientSection" class="mw-recipient-section" style="display: none;">
                            <label class="form-label mt-3">Invoice Recipients *</label>
                            <div class="mw-recipient-table">
                                <table class="table table-sm table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 40px;"><input type="checkbox" id="selectAllRecipients"></th>
                                            <th>Contact</th>
                                            <th>Role</th>
                                            <th>Email</th>
                                            <th style="width: 80px;">SMS</th>
                                        </tr>
                                    </thead>
                                    <tbody id="recipientTableBody">
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">Loading recipients...</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div id="recipientSummary" class="alert alert-info mt-2" style="display: none;">
                                Will send invoice to: <strong id="recipientSummaryText"></strong>
                            </div>
                        </div>

                        <div class="mw-form-row">
                            <div class="mw-form-group">
                                <label class="form-label">Issue Date</label>
                                <input type="date" name="issue_date" class="form-control"
                                       value="<?php echo htmlspecialchars($prefill['scheduled_date'] ?? date('Y-m-d')); ?>">
                            </div>
                            <div class="mw-form-group">
                                <label class="form-label">Due Date</label>
                                <input type="date" name="due_date" class="form-control"
                                       value="<?php echo date('Y-m-d', strtotime(($prefill['scheduled_date'] ?? 'now') . ' +30 days')); ?>">
                            </div>
                        </div>

                        <!-- Phase 2: Address Fields -->
                        <div class="alert alert-light mt-3 pt-3 border-top">
                            <h5 class="mb-3">Service Address (where work was performed)</h5>
                            <div class="mw-form-row">
                                <div class="mw-form-group" style="flex: 1 1 100%;">
                                    <label class="form-label">Address</label>
                                    <input type="text" name="service_address" id="serviceAddress" class="form-control"
                                           placeholder="Start typing an address..." autocomplete="off"
                                           value="<?php echo htmlspecialchars($prefill['service_address'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="mw-form-row">
                                <div class="mw-form-group">
                                    <label class="form-label">City</label>
                                    <input type="text" name="service_city" id="serviceCity" class="form-control" placeholder="Vancouver"
                                           value="<?php echo htmlspecialchars($prefill['service_city'] ?? ''); ?>">
                                </div>
                                <div class="mw-form-group">
                                    <label class="form-label">Province</label>
                                    <input type="text" name="service_province" id="serviceProvince" class="form-control" placeholder="BC" maxlength="2"
                                           value="<?php echo htmlspecialchars($prefill['service_province'] ?? ''); ?>">
                                </div>
                                <div class="mw-form-group">
                                    <label class="form-label">Postal Code</label>
                                    <input type="text" name="service_postal_code" id="servicePostalCode" class="form-control" placeholder="V6B 1A1"
                                           value="<?php echo htmlspecialchars($prefill['service_postal'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Phase 2: Billing Address (separate or same as service) -->
                        <div class="alert alert-light pt-3 border-top">
                            <div class="custom-control custom-checkbox mb-3">
                                <input type="checkbox" class="custom-control-input" id="differentBillingAddress">
                                <label class="custom-control-label" for="differentBillingAddress">
                                    Billing address is different from service address
                                </label>
                            </div>
                            <div id="billingAddressSection" style="display: none;">
                                <h5 class="mb-3">Billing Address (where invoice is sent)</h5>
                                <div class="mw-form-row">
                                    <div class="mw-form-group" style="flex: 1 1 100%;">
                                        <label class="form-label">Address</label>
                                        <input type="text" name="billing_address" id="invBillingAddress" class="form-control"
                                               placeholder="Start typing an address..." autocomplete="off">
                                    </div>
                                </div>
                                <div class="mw-form-row">
                                    <div class="mw-form-group">
                                        <label class="form-label">City</label>
                                        <input type="text" name="billing_city" id="invBillingCity" class="form-control" placeholder="Vancouver">
                                    </div>
                                    <div class="mw-form-group">
                                        <label class="form-label">Province</label>
                                        <input type="text" name="billing_province" id="invBillingProvince" class="form-control" placeholder="BC" maxlength="2">
                                    </div>
                                    <div class="mw-form-group">
                                        <label class="form-label">Postal Code</label>
                                        <input type="text" name="billing_postal_code" id="invBillingPostalCode" class="form-control" placeholder="V6B 1A1">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mw-form-group">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control"
                                      placeholder="Services rendered..."><?php echo htmlspecialchars($prefill['description'] ?? ''); ?></textarea>
                        </div>

                        <div class="mw-form-group" style="max-width: 300px;">
                            <label class="form-label">Amount (before tax) *</label>
                            <input type="number" name="subtotal" id="subtotalInput" class="form-control" step="0.01" min="0" required
                                   value="<?php echo htmlspecialchars($prefill['amount'] ?? ''); ?>"
                                   oninput="calculateTotals()">
                        </div>

                        <div class="mw-totals-box">
                            <div class="mw-totals-row">
                                <span>Subtotal</span>
                                <span class="mw-totals-value" id="subtotalDisplay">$0.00</span>
                            </div>
                            <div class="mw-totals-row">
                                <span>GST (5%)</span>
                                <span class="mw-totals-value" id="taxDisplay">$0.00</span>
                            </div>
                            <div class="mw-totals-row grand">
                                <span>Total</span>
                                <span class="mw-totals-value" id="totalDisplay">$0.00</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h3 class="card-title">Notes</h3>

                        <div class="mw-form-group">
                            <label class="form-label">Invoice Notes (shown to customer)</label>
                            <textarea name="notes" class="form-control" placeholder="Payment terms, thank you message, etc.">Thank you for your business!</textarea>
                        </div>
                    </div>
                </div>

                <div class="mw-form-actions">
                    <button type="submit" class="btn btn-primary">Create Invoice</button>
                    <a href="index.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>

            <script>
                // ========================================
                // PHASE 2-3: Invoice Recipient & Address Logic
                // ========================================

                const companySelect = document.getElementById('companySelect');
                const propertySelect = document.getElementById('propertySelect');
                const recipientSection = document.getElementById('recipientSection');
                const recipientTableBody = document.getElementById('recipientTableBody');
                const recipientSummary = document.getElementById('recipientSummary');
                const recipientSummaryText = document.getElementById('recipientSummaryText');
                const differentBillingCheckbox = document.getElementById('differentBillingAddress');
                const billingAddressSection = document.getElementById('billingAddressSection');
                const selectAllCheckbox = document.getElementById('selectAllRecipients');
                const selectedRecipientsInput = document.getElementById('selectedRecipientsInput');
                const propertyIdInput = document.getElementById('propertyIdInput');

                // Load properties when company is selected
                if (companySelect) {
                    companySelect.addEventListener('change', function() {
                        const companyId = this.value;
                        if (!companyId) {
                            propertySelect.innerHTML = '<option value="">Select property...</option>';
                            recipientSection.style.display = 'none';
                            return;
                        }

                        // Load properties for this company
                        fetch(`/crm/invoices/api-get-properties.php?company_id=${companyId}`)
                            .then(r => r.json())
                            .then(data => {
                                if (data.success && data.properties) {
                                    propertySelect.innerHTML = '<option value="">Select property...</option>';
                                    data.properties.forEach(prop => {
                                        const opt = document.createElement('option');
                                        opt.value = prop.id;
                                        opt.textContent = `${prop.address}, ${prop.city}`;
                                        propertySelect.appendChild(opt);
                                    });
                                }
                            })
                            .catch(err => console.error('Error loading properties:', err));
                    });
                }

                // Load recipients when property is selected
                propertySelect.addEventListener('change', function() {
                    const propertyId = this.value;
                    const companyId = companySelect ? companySelect.value : (document.querySelector('input[name="company_id"]')?.value || 0);

                    if (!propertyId || !companyId) {
                        recipientSection.style.display = 'none';
                        return;
                    }

                    propertyIdInput.value = propertyId;

                    // Fetch recipients via AJAX
                    fetch('/crm/invoices/api-get-recipients.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            property_id: parseInt(propertyId),
                            company_id: parseInt(companyId)
                        })
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success && data.recipients && data.recipients.length > 0) {
                            renderRecipientTable(data.recipients);
                            recipientSection.style.display = 'block';
                        } else {
                            recipientTableBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No recipients found for this property</td></tr>';
                            recipientSection.style.display = 'block';
                        }
                    })
                    .catch(err => {
                        console.error('Error loading recipients:', err);
                        recipientTableBody.innerHTML = '<tr><td colspan="5" class="text-center text-danger">Error loading recipients</td></tr>';
                    });
                });

                // Render recipient table with checkboxes
                function renderRecipientTable(recipients) {
                    recipientTableBody.innerHTML = '';

                    recipients.forEach(recipient => {
                        const row = document.createElement('tr');
                        row.className = 'recipient-row';
                        row.dataset.contactId = recipient.contact_id;

                        const roleLabel = formatRole(recipient.contact_role);
                        const smsIndicator = recipient.receive_sms ? '✓ SMS' : '—';

                        row.innerHTML = `
                            <td>
                                <input type="checkbox" class="recipient-checkbox" value="${recipient.contact_id}" checked>
                            </td>
                            <td>${recipient.contact_name || (recipient.first_name + ' ' + recipient.last_name)}</td>
                            <td><span class="badge badge-light">${roleLabel}</span></td>
                            <td><small>${recipient.email_address}</small></td>
                            <td><small>${smsIndicator}</small></td>
                        `;
                        recipientTableBody.appendChild(row);
                    });

                    // Add event listeners to checkboxes
                    document.querySelectorAll('.recipient-checkbox').forEach(cb => {
                        cb.addEventListener('change', updateRecipientSummary);
                    });

                    selectAllCheckbox.addEventListener('change', function() {
                        document.querySelectorAll('.recipient-checkbox').forEach(cb => {
                            cb.checked = this.checked;
                        });
                        updateRecipientSummary();
                    });

                    updateRecipientSummary();
                }

                // Update summary and hidden input with selected recipients
                function updateRecipientSummary() {
                    const selected = Array.from(document.querySelectorAll('.recipient-checkbox:checked'))
                        .map(cb => ({
                            id: cb.value,
                            name: cb.closest('tr').cells[1].textContent.trim()
                        }));

                    selectedRecipientsInput.value = JSON.stringify(selected.map(s => parseInt(s.id)));

                    if (selected.length > 0) {
                        recipientSummaryText.textContent = selected.map(s => s.name).join(', ');
                        recipientSummary.style.display = 'block';
                        selectAllCheckbox.indeterminate = selected.length < document.querySelectorAll('.recipient-checkbox').length;
                    } else {
                        recipientSummary.style.display = 'none';
                    }
                }

                // Format contact role for display
                function formatRole(role) {
                    const roles = {
                        'primary_recipient': 'Primary',
                        'property_manager': 'Property Manager',
                        'owner_contact': 'Owner',
                        'strata_manager': 'Strata Manager',
                        'billing_contact': 'Billing',
                        'accounting': 'Accounting'
                    };
                    return roles[role] || role;
                }

                // Toggle billing address section
                differentBillingCheckbox.addEventListener('change', function() {
                    billingAddressSection.style.display = this.checked ? 'block' : 'none';
                });

                // Form validation
                document.getElementById('invoiceForm').addEventListener('submit', function(e) {
                    const selectedCount = document.querySelectorAll('.recipient-checkbox:checked').length;
                    if (selectedCount === 0) {
                        e.preventDefault();
                        alert('Please select at least one invoice recipient.');
                        return false;
                    }
                });

                // Calculate totals
                function calculateTotals() {
                    const subtotal = parseFloat(document.getElementById('subtotalInput').value) || 0;
                    const tax = subtotal * 0.05;
                    const total = subtotal + tax;

                    document.getElementById('subtotalDisplay').textContent = '$' + subtotal.toFixed(2);
                    document.getElementById('taxDisplay').textContent = '$' + tax.toFixed(2);
                    document.getElementById('totalDisplay').textContent = '$' + total.toFixed(2);
                }

                // Initialize
                calculateTotals();

                <?php if ($visitId && !empty($prefill['property_id'])): ?>
                // Auto-load recipients from visit property
                (function() {
                    const propertyId = <?php echo (int)$prefill['property_id']; ?>;
                    const companyId  = <?php echo (int)($prefill['company_id'] ?? 0); ?>;
                    const contactId  = <?php echo (int)($prefill['contact_id'] ?? 0); ?>;
                    propertyIdInput.value = propertyId;

                    fetch('/crm/invoices/api-get-recipients.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ property_id: propertyId, company_id: companyId, contact_id: contactId })
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success && data.recipients && data.recipients.length > 0) {
                            renderRecipientTable(data.recipients);
                            // Auto-check all recipients
                            document.querySelectorAll('.recipient-checkbox').forEach(cb => { cb.checked = true; });
                            updateRecipientSummary();
                        } else {
                            recipientTableBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No email recipients found — add a contact email to send this invoice.</td></tr>';
                        }
                        recipientSection.style.display = 'block';
                    })
                    .catch(() => {
                        recipientTableBody.innerHTML = '<tr><td colspan="5" class="text-center text-danger">Error loading recipients.</td></tr>';
                        recipientSection.style.display = 'block';
                    });
                })();
                <?php endif; ?>

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
                initAddressAutocomplete('serviceAddress', 'serviceCity', 'servicePostalCode', 'serviceProvince');
                initAddressAutocomplete('invBillingAddress', 'invBillingCity', 'invBillingPostalCode', 'invBillingProvince');
            </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
