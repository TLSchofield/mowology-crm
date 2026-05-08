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
    $stmt = $db->prepare("
        SELECT jv.id as visit_id, jv.visit_number, jv.actual_amount,
               jv.plan_id, jv.scheduled_date, jv.invoice_id as existing_invoice_id,
               jv.extras_minutes, jv.extras_amount, jv.extras_note,
               jp.plan_number, jp.title, jp.price_per_visit, jp.estimated_amount,
               jp.property_id, jp.company_id,
               c.company_name,
               p.address, p.city, p.province, p.postal_code,
               p.site_contact_id,
               COALESCE(con.first_name, '') as contact_first,
               COALESCE(con.last_name, '') as contact_last,
               con.email as contact_email,
               con.mobile as contact_mobile,
               con.receive_sms as contact_receive_sms
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
        if (!empty($visit['existing_invoice_id'])) {
            header("Location: view.php?id={$visit['existing_invoice_id']}&already_invoiced=1");
            exit;
        }
        $visitAmount  = $visit['actual_amount'] ?: $visit['price_per_visit'] ?: $visit['estimated_amount'];
        $contactName  = trim($visit['contact_first'] . ' ' . $visit['contact_last']) ?: null;

        // Fetch plan line items so the invoice can be generated with zero re-entry
        $prefillLineItems = [];
        if (!empty($visit['plan_id'])) {
            try {
                $pliStmt = $db->prepare("
                    SELECT service_type, description, quantity, unit_type, unit_price, line_total, sort_order
                    FROM plan_line_items
                    WHERE plan_id = ?
                    ORDER BY sort_order, id
                ");
                $pliStmt->execute([$visit['plan_id']]);
                $prefillLineItems = $pliStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                // plan_line_items may not exist on this environment
            }
        }

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
            'contact_name'      => $contactName,
            'contact_email'     => $visit['contact_email'],
            'contact_mobile'    => $visit['contact_mobile'],
            'contact_receive_sms' => $visit['contact_receive_sms'],
            'service_address'   => $visit['address'] ?? '',
            'service_city'      => $visit['city'] ?? '',
            'service_province'  => $visit['province'] ?? 'BC',
            'service_postal'    => $visit['postal_code'] ?? '',
            'visit_number'      => $visit['visit_number'],
            'plan_number'       => $visit['plan_number'],
            'plan_line_items'   => $prefillLineItems,
            'extras_minutes'    => (int)($visit['extras_minutes'] ?? 0),
            'extras_amount'     => round(floatval($visit['extras_amount'] ?? 0), 2),
            'extras_note'       => trim($visit['extras_note'] ?? ''),
        ];
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $companyId        = intval($_POST['company_id'] ?? 0);
        $contactId        = intval($_POST['contact_id'] ?? 0);
        $linkedVisitId    = intval($_POST['visit_id'] ?? 0);
        $linkedPlanId     = intval($_POST['plan_id'] ?? 0);
        $usePlanLineItems = !empty($_POST['use_plan_line_items']) && $linkedPlanId;
        $propertyId    = intval($_POST['property_id'] ?? 0);
        $issueDate     = $_POST['issue_date'] ?? date('Y-m-d');
        $dueDate       = $_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days'));
        $description   = trim($_POST['description'] ?? '');
        $subtotal      = floatval($_POST['subtotal'] ?? 0);
        // Read GST rate from business settings (falls back to 5% if not configured)
        $bsStmt = $db->query("SELECT gst_rate, gst_registration FROM business_settings LIMIT 1");
        $bs = $bsStmt ? $bsStmt->fetch(PDO::FETCH_ASSOC) : [];
        $taxRate   = round(floatval($bs['gst_rate'] ?? 5.00) / 100, 4);
        $gstNumber = trim($bs['gst_registration'] ?? '');
        $taxAmount = round($subtotal * $taxRate, 2);
        $total     = $subtotal + $taxAmount;
        $notes         = trim($_POST['notes'] ?? '');
        $extrasMinutes = max(0, intval($_POST['extras_minutes'] ?? 0));
        $extrasAmount  = round(max(0.0, floatval($_POST['extras_amount'] ?? 0)), 2);

        $serviceAddress    = trim($_POST['service_address'] ?? '');
        $serviceCity       = trim($_POST['service_city'] ?? '');
        $serviceProvince   = trim($_POST['service_province'] ?? '');
        $servicePostalCode = trim($_POST['service_postal_code'] ?? '');

        $billingAddress    = trim($_POST['billing_address'] ?? '');
        $billingCity       = trim($_POST['billing_city'] ?? '');
        $billingProvince   = trim($_POST['billing_province'] ?? '');
        $billingPostalCode = trim($_POST['billing_postal_code'] ?? '');

        $selectedRecipients = [];
        if (!empty($_POST['selected_recipients'])) {
            $selectedRecipients = json_decode($_POST['selected_recipients'], true) ?? [];
            $selectedRecipients = array_filter(array_map('intval', $selectedRecipients));
        }

        $addressDiffers = (
            ($serviceAddress  !== $billingAddress)  ||
            ($serviceCity     !== $billingCity)      ||
            ($serviceProvince !== $billingProvince)  ||
            ($servicePostalCode !== $billingPostalCode)
        ) ? 1 : 0;

        if (!$companyId && !$contactId) {
            $error = 'Please select a customer.';
        } elseif ($subtotal <= 0) {
            $error = 'Please enter a valid amount.';
        } elseif (empty($selectedRecipients)) {
            $error = 'Please select at least one invoice recipient.';
        } else {
            try {
                $db->beginTransaction();

                $invoiceNumber = generateInvoiceNumber();
                $accessToken   = generateAccessToken();

                $stmt = $db->prepare("
                    INSERT INTO invoices (
                        invoice_number, company_id, contact_id, property_id,
                        plan_id, visit_id,
                        invoice_date, issue_date, due_date,
                        subtotal, tax_rate, tax_amount, gst_number,
                        total_amount, total, balance_due,
                        notes, access_token, token_expires_at,
                        service_address, service_city, service_province, service_postal_code,
                        billing_address, billing_city, billing_province, billing_postal_code,
                        address_differs,
                        status, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 90 DAY), ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)
                ");
                $stmt->execute([
                    $invoiceNumber,
                    $companyId ?: null,
                    $contactId ?: null,
                    $propertyId ?: null,
                    $linkedPlanId ?: null,
                    $linkedVisitId ?: null,
                    $issueDate,
                    $issueDate,
                    $dueDate,
                    $subtotal,
                    $taxRate,
                    $taxAmount,
                    $gstNumber ?: null,
                    $total,
                    $total,
                    $total,
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

                // ── Line items: copy from plan if available (zero re-entry), else single row ──
                if ($usePlanLineItems) {
                    $pliRows = $db->prepare("
                        SELECT service_type, description, quantity, unit_type, unit_price, line_total, sort_order
                        FROM plan_line_items
                        WHERE plan_id = ?
                        ORDER BY sort_order, id
                    ");
                    $pliRows->execute([$linkedPlanId]);
                    $planLineItems = $pliRows->fetchAll(PDO::FETCH_ASSOC);

                    if ($planLineItems) {
                        // Recalculate totals from actual plan items (overrides form subtotal)
                        $lineSubtotal = array_sum(array_column($planLineItems, 'line_total'));
                        $lineTax      = round($lineSubtotal * $taxRate, 2);
                        $lineTotal    = $lineSubtotal + $lineTax;
                        $db->prepare("
                            UPDATE invoices
                            SET subtotal = ?, tax_amount = ?, total_amount = ?, total = ?, balance_due = ?
                            WHERE id = ?
                        ")->execute([$lineSubtotal, $lineTax, $lineTotal, $lineTotal, $lineTotal, $invoiceId]);

                        $liStmt = $db->prepare("
                            INSERT INTO invoice_line_items
                                (invoice_id, description, quantity, unit_price, line_total, visit_id, sort_order)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        foreach ($planLineItems as $i => $pli) {
                            $liStmt->execute([
                                $invoiceId,
                                $pli['description'] ?: ($pli['service_type'] ?: 'Service'),
                                $pli['quantity'],
                                $pli['unit_price'],
                                $pli['line_total'],
                                $linkedVisitId ?: null,
                                (int)($pli['sort_order'] ?? $i),
                            ]);
                        }
                    } else {
                        // Plan has no line items yet — fall through to single row
                        $usePlanLineItems = false;
                    }
                }

                if (!$usePlanLineItems) {
                    $db->prepare("
                        INSERT INTO invoice_line_items (invoice_id, description, quantity, unit_price, line_total, visit_id)
                        VALUES (?, ?, 1, ?, ?, ?)
                    ")->execute([
                        $invoiceId,
                        $description ?: 'Services rendered',
                        $subtotal,
                        $subtotal,
                        $linkedVisitId ?: null,
                    ]);
                }

                // ── Billable extras line item ──────────────────────────────
                if ($extrasAmount > 0 && $extrasMinutes > 0 && $linkedVisitId) {
                    $db->prepare("
                        INSERT INTO invoice_line_items
                            (invoice_id, description, quantity, unit_price, line_total, visit_id, sort_order)
                        VALUES (?, ?, 1, ?, ?, ?, 999)
                    ")->execute([
                        $invoiceId,
                        'Additional services (' . $extrasMinutes . ' min)',
                        $extrasAmount,
                        $extrasAmount,
                        $linkedVisitId,
                    ]);

                    // Recalculate invoice totals to include extras
                    $sumStmt = $db->prepare("SELECT COALESCE(SUM(line_total),0) FROM invoice_line_items WHERE invoice_id = ?");
                    $sumStmt->execute([$invoiceId]);
                    $newSubtotal = round(floatval($sumStmt->fetchColumn()), 2);
                    $newTax      = round($newSubtotal * $taxRate, 2);
                    $newTotal    = round($newSubtotal + $newTax, 2);
                    $db->prepare("
                        UPDATE invoices SET subtotal = ?, tax_amount = ?, total_amount = ?, total = ?, balance_due = ?
                        WHERE id = ?
                    ")->execute([$newSubtotal, $newTax, $newTotal, $newTotal, $newTotal, $invoiceId]);
                }

                $recipientContacts = $db->prepare("
                    SELECT id, first_name, last_name, email, mobile, receive_sms
                    FROM contacts WHERE id = ?
                ");

                $insertRecipient = $db->prepare("
                    INSERT INTO invoice_contacts (
                        invoice_id, contact_id, contact_role, email_address
                    ) VALUES (?, ?, ?, ?)
                ");

                $recipientNames = [];
                $smsRecipients  = [];

                foreach ($selectedRecipients as $rcptContactId) {
                    $recipientContacts->execute([$rcptContactId]);
                    $contact = $recipientContacts->fetch(PDO::FETCH_ASSOC);

                    if ($contact) {
                        $insertRecipient->execute([
                            $invoiceId,
                            $rcptContactId,
                            'primary_recipient',
                            $contact['email']
                        ]);

                        $recipientNames[] = "{$contact['first_name']} {$contact['last_name']}";

                        if ($contact['receive_sms']) {
                            $smsRecipients[] = [
                                'contact_id' => $rcptContactId,
                                'phone'      => $contact['mobile'] ?? null,
                                'email'      => $contact['email']
                            ];
                        }
                    }
                }

                $recipientList = implode(', ', $recipientNames);
                $details = "Invoice {$invoiceNumber} created for " . ($recipientNames ? $recipientList : 'no recipients');
                if (!empty($smsRecipients)) {
                    $details .= " (SMS to: " . count($smsRecipients) . " recipients)";
                }

                logActivityExtended($user['id'], 'Invoice created', $details, $companyId ?: null, null, null, $invoiceId, $linkedPlanId ?: null, $linkedVisitId ?: null);

                if ($linkedVisitId) {
                    $db->prepare("UPDATE job_visits SET is_invoiced = 1, invoice_id = ? WHERE id = ?")
                       ->execute([$invoiceId, $linkedVisitId]);
                }

                $db->commit();

                header("Location: view.php?id={$invoiceId}&created=1");
                exit;

            } catch (\Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log("Invoice creation error: " . $e->getMessage());
                $error = 'Error creating invoice. Please try again.';
            }
        }
    }
}

$csrfToken = generateCSRFToken();

$pageTitle  = 'Create Invoice';
$activePage = 'invoices';
$apiKey     = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
$extraHead  = '';
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
                } else {
                    echo 'Create a new invoice manually';
                }
            ?></p>

            <?php if ($error): ?>
                <div class="mw-error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($visitId && isset($visit)): ?>
                <div class="mw-info-banner">
                    <strong>Creating from Visit <?php echo htmlspecialchars($visit['visit_number']); ?></strong><br>
                    Plan: <?php echo htmlspecialchars($visit['plan_number'] . ' — ' . $visit['title']); ?><br>
                    <?php echo htmlspecialchars($visit['company_name'] ?? $prefill['company_name'] ?? ''); ?><?php if (!empty($visit['address'])): ?> &mdash; <?php echo htmlspecialchars($visit['address']); ?><?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="invoiceForm">
                <input type="hidden" name="csrf_token"          value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="visit_id"            value="<?php echo $prefill['visit_id'] ?? ''; ?>">
                <input type="hidden" name="plan_id"             value="<?php echo $prefill['plan_id'] ?? ''; ?>">
                <input type="hidden" name="property_id"         id="propertyIdInput"    value="<?php echo $prefill['property_id'] ?? ''; ?>">
                <input type="hidden" name="company_id"          id="companyIdInput"     value="<?php echo (int)($prefill['company_id'] ?? 0); ?>">
                <input type="hidden" name="contact_id"          id="contactIdInput"     value="<?php echo (int)($prefill['contact_id'] ?? 0); ?>">
                <input type="hidden" name="selected_recipients" id="selectedRecipientsInput" value="[]">
                <input type="hidden" name="extras_minutes" value="<?php echo (int)($prefill['extras_minutes'] ?? 0); ?>">
                <input type="hidden" name="extras_amount"  value="<?php echo htmlspecialchars((string)($prefill['extras_amount'] ?? '0')); ?>">

                <div class="card">
                    <div class="card-body">
                        <h3 class="card-title">Invoice Details</h3>

                        <!-- ── Customer Typeahead ── -->
                        <div class="mw-form-group">
                            <label class="form-label">Customer *</label>
                            <?php if ($visitId && !empty($prefill['company_name'])): ?>
                                <!-- Pre-filled from visit — read only -->
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($prefill['company_name']); ?>" readonly>
                            <?php else: ?>
                                <div class="mw-customer-search-wrap" id="customerSearchWrap">
                                    <div id="customerInputRow">
                                        <input type="text"
                                               id="customerSearchInput"
                                               class="form-control"
                                               placeholder="Type a name or email to search customers&hellip;"
                                               autocomplete="off"
                                               aria-label="Search customers">
                                    </div>
                                    <ul id="customerDropdown" class="mw-customer-dropdown" style="display:none;" role="listbox"></ul>
                                    <div id="customerSelectedCard" class="mw-customer-selected-card" style="display:none;">
                                        <div class="mw-selected-avatar" id="selectedAvatar"></div>
                                        <div class="mw-selected-info" id="selectedInfo"></div>
                                        <button type="button" id="customerClearBtn" class="mw-customer-clear-btn" title="Clear selection">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- ── Property Selector (shown when customer has multiple properties) ── -->
                        <div id="propertySection" class="mw-form-group" style="display:none;">
                            <label class="form-label">Property <span class="text-muted">(optional — loads recipients)</span></label>
                            <select id="propertySelect" class="form-control">
                                <option value="">All properties / select one…</option>
                            </select>
                        </div>

                        <!-- ── Recipient Table ── -->
                        <div id="recipientSection" class="mw-recipient-section" style="display:none;">
                            <label class="form-label mt-3">Invoice Recipients *</label>
                            <div class="mw-recipient-table">
                                <table class="table table-sm table-bordered mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:40px;"><input type="checkbox" id="selectAllRecipients"></th>
                                            <th>Contact</th>
                                            <th>Email</th>
                                            <th style="width:80px;">SMS</th>
                                        </tr>
                                    </thead>
                                    <tbody id="recipientTableBody">
                                        <tr><td colspan="4" class="text-center text-muted py-3">Loading…</td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <div id="recipientSummary" class="alert alert-info mt-2 mb-0" style="display:none;">
                                Will send to: <strong id="recipientSummaryText"></strong>
                            </div>
                        </div>

                        <!-- ── Dates ── -->
                        <div class="mw-form-row mt-3">
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

                        <!-- ── Service Address ── -->
                        <div class="alert alert-light mt-2 pt-3 border-top">
                            <h5 class="mb-3">Service Address <span class="text-muted fw-normal" style="font-size:.85rem;">(where work was performed)</span></h5>
                            <div class="mw-form-row">
                                <div class="mw-form-group full">
                                    <label class="form-label">Address</label>
                                    <input type="text" name="service_address" id="serviceAddress" class="form-control"
                                           placeholder="Start typing an address…" autocomplete="off"
                                           value="<?php echo htmlspecialchars($prefill['service_address'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="mw-form-row three">
                                <div class="mw-form-group">
                                    <label class="form-label">City</label>
                                    <input type="text" name="service_city" id="serviceCity" class="form-control" placeholder="Vancouver"
                                           value="<?php echo htmlspecialchars($prefill['service_city'] ?? ''); ?>">
                                </div>
                                <div class="mw-form-group">
                                    <label class="form-label">Province</label>
                                    <select name="service_province" id="serviceProvince" class="form-control">
                                        <?php
                                        $provinces = [''=>'—','AB'=>'AB','BC'=>'BC','MB'=>'MB','NB'=>'NB','NL'=>'NL','NS'=>'NS','NT'=>'NT','NU'=>'NU','ON'=>'ON','PE'=>'PE','QC'=>'QC','SK'=>'SK','YT'=>'YT'];
                                        $selProv = $prefill['service_province'] ?? '';
                                        foreach ($provinces as $code => $label) {
                                            $sel = ($code === $selProv) ? ' selected' : '';
                                            echo "<option value=\"{$code}\"{$sel}>{$label}</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="mw-form-group">
                                    <label class="form-label">Postal Code</label>
                                    <input type="text" name="service_postal_code" id="servicePostalCode" class="form-control" placeholder="V6B 1A1"
                                           value="<?php echo htmlspecialchars($prefill['service_postal'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>

                        <!-- ── Billing Address ── -->
                        <div class="alert alert-light pt-3 border-top">
                            <div class="custom-control custom-checkbox mb-3">
                                <input type="checkbox" class="custom-control-input" id="differentBillingAddress">
                                <label class="custom-control-label" for="differentBillingAddress">
                                    Billing address differs from service address
                                </label>
                            </div>
                            <div id="billingAddressSection" style="display:none;">
                                <h5 class="mb-3">Billing Address</h5>
                                <div class="mw-form-row">
                                    <div class="mw-form-group full">
                                        <label class="form-label">Address</label>
                                        <input type="text" name="billing_address" id="invBillingAddress" class="form-control"
                                               placeholder="Start typing an address…" autocomplete="off">
                                    </div>
                                </div>
                                <div class="mw-form-row three">
                                    <div class="mw-form-group">
                                        <label class="form-label">City</label>
                                        <input type="text" name="billing_city" id="invBillingCity" class="form-control" placeholder="Vancouver">
                                    </div>
                                    <div class="mw-form-group">
                                        <label class="form-label">Province</label>
                                        <select name="billing_province" id="invBillingProvince" class="form-control">
                                            <option value="">—</option>
                                            <option value="AB">AB</option><option value="BC">BC</option>
                                            <option value="MB">MB</option><option value="NB">NB</option>
                                            <option value="NL">NL</option><option value="NS">NS</option>
                                            <option value="NT">NT</option><option value="NU">NU</option>
                                            <option value="ON">ON</option><option value="PE">PE</option>
                                            <option value="QC">QC</option><option value="SK">SK</option>
                                            <option value="YT">YT</option>
                                        </select>
                                    </div>
                                    <div class="mw-form-group">
                                        <label class="form-label">Postal Code</label>
                                        <input type="text" name="billing_postal_code" id="invBillingPostalCode" class="form-control" placeholder="V6B 1A1">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ── Description & Amount ── -->
                        <?php
                        $planLineItems = $prefill['plan_line_items'] ?? [];
                        $lineItemsSubtotal = array_sum(array_column($planLineItems, 'line_total'));
                        ?>
                        <?php if ($planLineItems): ?>
                        <!-- Zero-re-entry: line items auto-populated from quote/plan -->
                        <input type="hidden" name="use_plan_line_items" value="1">
                        <input type="hidden" name="subtotal" value="<?php echo htmlspecialchars((string)$lineItemsSubtotal); ?>">

                        <div class="alert alert-success d-flex align-items-center gap-2 py-2 mb-3" style="font-size:.85rem;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><polyline points="20 6 9 17 4 12"/></svg>
                            Line items auto-populated from job plan — no re-entry needed
                        </div>

                        <table class="table table-sm table-bordered mb-0" style="font-size:.85rem;">
                            <thead class="table-light">
                                <tr>
                                    <th>Service</th>
                                    <th style="width:70px;">Qty</th>
                                    <th style="width:90px;">Unit Price</th>
                                    <th style="width:90px;">Line Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($planLineItems as $pli): ?>
                                <tr>
                                    <td>
                                        <div class="font-weight-600"><?php echo htmlspecialchars($pli['service_type'] ?? 'Service'); ?></div>
                                        <?php if (!empty($pli['description'])): ?>
                                        <div class="text-muted" style="font-size:.8rem;"><?php echo htmlspecialchars($pli['description']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)$pli['quantity']); ?> <span class="text-muted"><?php echo htmlspecialchars($pli['unit_type'] ?? ''); ?></span></td>
                                    <td>$<?php echo number_format(floatval($pli['unit_price']), 2); ?></td>
                                    <td>$<?php echo number_format(floatval($pli['line_total']), 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <div class="mw-totals-box mt-3">
                            <div class="mw-totals-row">
                                <span>Subtotal</span>
                                <span class="mw-totals-value">$<?php echo number_format($lineItemsSubtotal, 2); ?></span>
                            </div>
                            <div class="mw-totals-row">
                                <span>GST (5%)</span>
                                <span class="mw-totals-value">$<?php echo number_format($lineItemsSubtotal * 0.05, 2); ?></span>
                            </div>
                            <div class="mw-totals-row grand">
                                <span>Total</span>
                                <span class="mw-totals-value">$<?php echo number_format($lineItemsSubtotal * 1.05, 2); ?></span>
                            </div>
                        </div>

                        <?php else: ?>
                        <!-- Manual / no plan items: single description + amount -->
                        <div class="mw-form-group">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2"
                                      placeholder="Services rendered…"><?php echo htmlspecialchars($prefill['description'] ?? ''); ?></textarea>
                        </div>

                        <div class="mw-form-group" style="max-width:300px;">
                            <label class="form-label">Amount (before tax) *</label>
                            <input type="number" name="subtotal" id="subtotalInput" class="form-control"
                                   step="0.01" min="0" required
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
                        <?php endif; ?>

                        <?php
                        // Extras add-on preview
                        $prevExtrasAmt  = floatval($prefill['extras_amount'] ?? 0);
                        $prevExtrasMins = intval($prefill['extras_minutes'] ?? 0);
                        $prevExtrasNote = $prefill['extras_note'] ?? '';
                        if ($prevExtrasAmt > 0 && $prevExtrasMins > 0):
                        ?>
                        <div class="alert alert-info d-flex align-items-start gap-2 py-2 mt-3 mb-0" style="font-size:.85rem;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:2px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <div>
                                <strong>+ $<?php echo number_format($prevExtrasAmt, 2); ?> add-on services (<?php echo $prevExtrasMins; ?> min)</strong> will be added as a separate line item.
                                <?php if ($prevExtrasNote): ?><br><span class="text-muted">Crew note: &ldquo;<?php echo htmlspecialchars($prevExtrasNote); ?>&rdquo; — pre-filled in Notes below.</span><?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h3 class="card-title">Notes</h3>
                        <div class="mw-form-group">
                            <label class="form-label">Invoice Notes (shown to customer)</label>
                            <?php
                            $defaultNote = 'Thank you for your business!';
                            $notesValue  = !empty($prevExtrasNote)
                                ? $prevExtrasNote . "\n\n" . $defaultNote
                                : $defaultNote;
                            ?>
                            <textarea name="notes" class="form-control" rows="3"
                                      placeholder="Payment terms, thank you message, etc."><?php echo htmlspecialchars($notesValue); ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="mw-form-actions">
                    <button type="submit" class="btn btn-primary">Create Invoice</button>
                    <a href="index.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>

<script>
// =====================================================
// Customer Typeahead + Recipient Logic
// =====================================================

const customerSearchInput    = document.getElementById('customerSearchInput');
const customerInputRow       = document.getElementById('customerInputRow');
const customerDropdown       = document.getElementById('customerDropdown');
const customerSelectedCard   = document.getElementById('customerSelectedCard');
const selectedAvatar         = document.getElementById('selectedAvatar');
const selectedInfo           = document.getElementById('selectedInfo');
const customerClearBtn       = document.getElementById('customerClearBtn');
const companyIdInput         = document.getElementById('companyIdInput');
const contactIdInput         = document.getElementById('contactIdInput');
const propertyIdInput        = document.getElementById('propertyIdInput');
const propertySection        = document.getElementById('propertySection');
const propertySelect         = document.getElementById('propertySelect');
const recipientSection       = document.getElementById('recipientSection');
const recipientTableBody     = document.getElementById('recipientTableBody');
const recipientSummary       = document.getElementById('recipientSummary');
const recipientSummaryText   = document.getElementById('recipientSummaryText');
const selectAllCheckbox      = document.getElementById('selectAllRecipients');
const selectedRecipientsInput = document.getElementById('selectedRecipientsInput');
const differentBillingCheckbox = document.getElementById('differentBillingAddress');
const billingAddressSection  = document.getElementById('billingAddressSection');

let searchTimeout = null;
let selectedCustomer = null;

// ── Typeahead search ──
if (customerSearchInput) {
    customerSearchInput.addEventListener('input', function () {
        clearTimeout(searchTimeout);
        const q = this.value.trim();
        if (q.length < 1) {
            hideDropdown();
            return;
        }
        searchTimeout = setTimeout(() => fetchCustomers(q), 220);
    });

    customerSearchInput.addEventListener('keydown', function (e) {
        const items = customerDropdown.querySelectorAll('.mw-customer-option');
        const active = customerDropdown.querySelector('.mw-customer-option.active');
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            const next = active ? (active.nextElementSibling || items[0]) : items[0];
            if (next) { active && active.classList.remove('active'); next.classList.add('active'); next.scrollIntoView({block:'nearest'}); }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            const prev = active ? (active.previousElementSibling || items[items.length - 1]) : items[items.length - 1];
            if (prev) { active && active.classList.remove('active'); prev.classList.add('active'); prev.scrollIntoView({block:'nearest'}); }
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (active) active.click();
        } else if (e.key === 'Escape') {
            hideDropdown();
        }
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('#customerSearchWrap')) hideDropdown();
    });
}

function fetchCustomers(q) {
    fetch(`/crm/invoices/api-search-customers.php?q=${encodeURIComponent(q)}`)
        .then(r => r.json())
        .then(data => renderDropdown(data.results || []))
        .catch(() => hideDropdown());
}

function renderDropdown(results) {
    customerDropdown.innerHTML = '';
    if (!results.length) {
        customerDropdown.innerHTML = '<li class="mw-customer-option mw-customer-empty">No customers found</li>';
        customerDropdown.style.display = 'block';
        return;
    }
    results.forEach(item => {
        const li = document.createElement('li');
        li.className = 'mw-customer-option';
        li.setAttribute('role', 'option');
        const icon = item.type === 'company'
            ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/></svg>'
            : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
        li.innerHTML = `
            <span class="mw-customer-icon">${icon}</span>
            <span class="mw-customer-meta">
                <span class="mw-customer-name">${escHtml(item.label)}</span>
                ${item.sublabel ? `<span class="mw-customer-sub">${escHtml(item.sublabel)}</span>` : ''}
            </span>
        `;
        li.addEventListener('click', () => selectCustomer(item));
        customerDropdown.appendChild(li);
    });
    customerDropdown.style.display = 'block';
}

function hideDropdown() {
    customerDropdown.style.display = 'none';
}

function selectCustomer(item) {
    selectedCustomer = item;
    hideDropdown();

    // Update hidden inputs
    if (item.type === 'contact') {
        contactIdInput.value = item.id;
        companyIdInput.value = 0;
    } else {
        companyIdInput.value = item.id;
        contactIdInput.value = 0;
    }

    // Show selected card, hide input row
    customerInputRow.style.display = 'none';
    customerSelectedCard.style.display = 'flex';
    selectedAvatar.textContent = item.label.charAt(0).toUpperCase();
    selectedInfo.innerHTML = `<strong>${escHtml(item.label)}</strong>${item.sublabel ? `<span>${escHtml(item.sublabel)}</span>` : ''}`;

    // Load properties / recipients
    loadCustomerContext(item);
}

function clearCustomer() {
    selectedCustomer = null;
    contactIdInput.value  = 0;
    companyIdInput.value  = 0;
    propertyIdInput.value = '';

    customerSearchInput.value          = '';
    customerInputRow.style.display     = '';
    customerSelectedCard.style.display = 'none';
    selectedAvatar.textContent         = '';
    selectedInfo.innerHTML             = '';

    propertySection.style.display   = 'none';
    recipientSection.style.display  = 'none';
    selectedRecipientsInput.value   = '[]';
    customerSearchInput.focus();
}

if (customerClearBtn) {
    customerClearBtn.addEventListener('click', clearCustomer);
}

function loadCustomerContext(item) {
    // For contacts: auto-add them as recipient immediately, also fetch their properties
    if (item.type === 'contact') {
        // Immediately populate them as recipient (no property required)
        const recipients = [{
            contact_id:   item.id,
            contact_name: item.label,
            email_address: item.email || '',
            receive_sms:  item.receive_sms || false,
        }];
        renderRecipientTable(recipients);
        recipientSection.style.display = 'block';

        // Also fetch their properties for optional address pre-fill
        fetch(`/crm/invoices/api-get-properties.php?contact_id=${item.id}`)
            .then(r => r.json())
            .then(data => {
                const props = data.properties || [];
                if (props.length === 1) {
                    // Auto-select single property
                    propertyIdInput.value = props[0].id;
                    prefillServiceAddress(props[0]);
                } else if (props.length > 1) {
                    // Show property selector
                    propertySelect.innerHTML = '<option value="">Select property…</option>';
                    props.forEach(p => {
                        const o = document.createElement('option');
                        o.value = p.id;
                        o.textContent = `${p.address}, ${p.city}`;
                        propertySelect.appendChild(o);
                    });
                    propertySection.style.display = 'block';
                }
            })
            .catch(() => {});

    } else {
        // Company: load properties to pick recipients
        fetch(`/crm/invoices/api-get-properties.php?company_id=${item.id}`)
            .then(r => r.json())
            .then(data => {
                const props = data.properties || [];
                if (props.length === 0) {
                    recipientTableBody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">No properties found for this company.</td></tr>';
                    recipientSection.style.display = 'block';
                } else if (props.length === 1) {
                    propertyIdInput.value = props[0].id;
                    prefillServiceAddress(props[0]);
                    loadRecipientsByProperty(props[0].id, item.id, 0);
                } else {
                    propertySelect.innerHTML = '<option value="">Select property…</option>';
                    props.forEach(p => {
                        const o = document.createElement('option');
                        o.value = p.id;
                        o.textContent = `${p.address}, ${p.city}`;
                        propertySelect.appendChild(o);
                    });
                    propertySection.style.display = 'block';
                }
            })
            .catch(() => {});
    }
}

// Property selector change
if (propertySelect) {
    propertySelect.addEventListener('change', function () {
        const propertyId = this.value;
        if (!propertyId) return;
        propertyIdInput.value = propertyId;

        // Find property details for address prefill
        const option = this.options[this.selectedIndex];
        if (option) {
            // Parse address from option text (format: "address, city")
            const parts = option.textContent.split(', ');
            if (parts.length >= 2) {
                document.getElementById('serviceAddress').value = parts.slice(0, -1).join(', ');
                document.getElementById('serviceCity').value    = parts[parts.length - 1];
            }
        }

        const companyId = companyIdInput ? parseInt(companyIdInput.value) : 0;
        const contactId = contactIdInput ? parseInt(contactIdInput.value) : 0;

        if (selectedCustomer && selectedCustomer.type === 'contact') {
            // For contact: property just sets address; contact is already the recipient
        } else {
            loadRecipientsByProperty(propertyId, companyId, contactId);
        }
    });
}

function prefillServiceAddress(prop) {
    if (prop.address) document.getElementById('serviceAddress').value = prop.address;
    if (prop.city)    document.getElementById('serviceCity').value    = prop.city;
    if (prop.province) document.getElementById('serviceProvince').value = prop.province || 'BC';
    if (prop.postal_code) document.getElementById('servicePostalCode').value = prop.postal_code;
}

function loadRecipientsByProperty(propertyId, companyId, contactId) {
    recipientTableBody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">Loading recipients…</td></tr>';
    recipientSection.style.display = 'block';

    fetch('/crm/invoices/api-get-recipients.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            property_id: parseInt(propertyId),
            company_id:  parseInt(companyId),
            contact_id:  parseInt(contactId)
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.recipients && data.recipients.length > 0) {
            renderRecipientTable(data.recipients);
        } else {
            recipientTableBody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">No recipients found — add a contact email to send this invoice.</td></tr>';
        }
    })
    .catch(() => {
        recipientTableBody.innerHTML = '<tr><td colspan="4" class="text-center text-danger py-3">Error loading recipients.</td></tr>';
    });
}

function renderRecipientTable(recipients) {
    recipientTableBody.innerHTML = '';
    recipients.forEach(r => {
        const row = document.createElement('tr');
        row.className = 'recipient-row';
        row.dataset.contactId = r.contact_id;
        const name = r.contact_name || ((r.first_name || '') + ' ' + (r.last_name || '')).trim();
        const smsLabel = r.receive_sms ? '<span class="badge badge-success">SMS</span>' : '<span class="text-muted">—</span>';
        row.innerHTML = `
            <td><input type="checkbox" class="recipient-checkbox" value="${r.contact_id}" checked></td>
            <td>${escHtml(name)}</td>
            <td><small class="text-muted">${escHtml(r.email_address || '')}</small></td>
            <td>${smsLabel}</td>
        `;
        recipientTableBody.appendChild(row);
    });

    document.querySelectorAll('.recipient-checkbox').forEach(cb => cb.addEventListener('change', updateRecipientSummary));
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function () {
            document.querySelectorAll('.recipient-checkbox').forEach(cb => { cb.checked = this.checked; });
            updateRecipientSummary();
        });
    }
    updateRecipientSummary();
}

function updateRecipientSummary() {
    const checked = Array.from(document.querySelectorAll('.recipient-checkbox:checked'));
    selectedRecipientsInput.value = JSON.stringify(checked.map(cb => parseInt(cb.value)));

    if (checked.length > 0) {
        const names = checked.map(cb => cb.closest('tr').cells[1].textContent.trim());
        recipientSummaryText.textContent = names.join(', ');
        recipientSummary.style.display = 'block';
        if (selectAllCheckbox) {
            selectAllCheckbox.indeterminate = checked.length < document.querySelectorAll('.recipient-checkbox').length;
        }
    } else {
        recipientSummary.style.display = 'none';
    }
}

// ── Billing address toggle ──
if (differentBillingCheckbox) {
    differentBillingCheckbox.addEventListener('change', function () {
        billingAddressSection.style.display = this.checked ? 'block' : 'none';
    });
}

// ── Form validation ──
document.getElementById('invoiceForm').addEventListener('submit', function (e) {
    const selected = document.querySelectorAll('.recipient-checkbox:checked').length;
    if (selected === 0) {
        e.preventDefault();
        alert('Please select at least one invoice recipient.');
        return;
    }
    const btn = this.querySelector('[type="submit"]');
    if (btn && !btn.disabled) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" style="width:14px;height:14px;margin-right:4px;vertical-align:middle;"></span> Creating…';
    }
});

// ── Totals calculation ──
function calculateTotals() {
    const el = document.getElementById('subtotalInput');
    if (!el) return;
    const subtotal = parseFloat(el.value) || 0;
    const tax   = subtotal * 0.05;
    const total = subtotal + tax;
    document.getElementById('subtotalDisplay').textContent = '$' + subtotal.toFixed(2);
    document.getElementById('taxDisplay').textContent      = '$' + tax.toFixed(2);
    document.getElementById('totalDisplay').textContent    = '$' + total.toFixed(2);
}
calculateTotals();

// ── HTML escape helper ──
function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Auto-load recipients from visit prefill ──
<?php if ($visitId && !empty($prefill['property_id'])): ?>
(function () {
    const propertyId = <?php echo (int)$prefill['property_id']; ?>;
    const companyId  = <?php echo (int)($prefill['company_id'] ?? 0); ?>;
    const contactId  = <?php echo (int)($prefill['contact_id'] ?? 0); ?>;
    propertyIdInput.value = propertyId;

    <?php if (!empty($prefill['contact_id'])): ?>
    // Contact-linked visit — add contact directly as recipient
    renderRecipientTable([{
        contact_id:    <?php echo (int)$prefill['contact_id']; ?>,
        contact_name:  <?php echo json_encode($prefill['contact_name'] ?? ''); ?>,
        email_address: <?php echo json_encode($prefill['contact_email'] ?? ''); ?>,
        receive_sms:   <?php echo !empty($prefill['contact_receive_sms']) ? 'true' : 'false'; ?>,
    }]);
    recipientSection.style.display = 'block';
    <?php else: ?>
    fetch('/crm/invoices/api-get-recipients.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ property_id: propertyId, company_id: companyId, contact_id: contactId })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.recipients && data.recipients.length > 0) {
            renderRecipientTable(data.recipients);
        } else {
            recipientTableBody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">No email recipients found — add a contact email to send this invoice.</td></tr>';
        }
        recipientSection.style.display = 'block';
    })
    .catch(() => {
        recipientTableBody.innerHTML = '<tr><td colspan="4" class="text-center text-danger py-3">Error loading recipients.</td></tr>';
        recipientSection.style.display = 'block';
    });
    <?php endif; ?>
})();
<?php endif; ?>

// ── Google Places Autocomplete ──
function initAddressAutocomplete(inputId, cityId, postalId, provinceId) {
    var input = document.getElementById(inputId);
    if (!input) return;
    if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
        setTimeout(function () { initAddressAutocomplete(inputId, cityId, postalId, provinceId); }, 200);
        return;
    }
    var ac = new google.maps.places.Autocomplete(input, {
        types: ['address'],
        componentRestrictions: { country: ['ca'] },
        fields: ['address_components', 'geometry']
    });
    ac.addListener('place_changed', function () {
        var place = ac.getPlace();
        if (!place || !place.address_components) return;
        var street = '', city = '', postal = '', province = '';
        place.address_components.forEach(function (c) {
            if (c.types.indexOf('street_number') !== -1) street = c.long_name + ' ' + street;
            if (c.types.indexOf('route')          !== -1) street = street + c.long_name;
            if (c.types.indexOf('locality')       !== -1) city   = c.long_name;
            if (c.types.indexOf('postal_code')    !== -1) postal = c.long_name;
            if (c.types.indexOf('administrative_area_level_1') !== -1) province = c.short_name;
        });
        if (street.trim()) input.value = street.trim();
        var el;
        if (cityId    && (el = document.getElementById(cityId)))     el.value = city;
        if (postalId  && (el = document.getElementById(postalId)))   el.value = postal;
        if (provinceId && (el = document.getElementById(provinceId))) el.value = province;
    });
}
initAddressAutocomplete('serviceAddress',    'serviceCity',    'servicePostalCode',    'serviceProvince');
initAddressAutocomplete('invBillingAddress', 'invBillingCity', 'invBillingPostalCode', 'invBillingProvince');
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
