<?php
/**
 * Edit Invoice
 * ─────────────
 * Lets a user with billing.edit update an existing invoice's editable
 * fields. Paid invoices are blocked (use record-payment.php for those).
 *
 * Editable:
 *   - issue_date, due_date, status
 *   - service_* and billing_* address blocks (+ address_differs)
 *   - invoice_line_items (full CRUD — DELETE + re-INSERT inside a tx)
 *   - notes, internal_notes
 *
 * NOT editable here (protected):
 *   - invoice_number, created_at, created_by
 *   - company_id / contact_id (customer identity is locked)
 *   - amount_paid, balance_due (managed by record-payment.php)
 *   - sent_at, viewed_at, paid_at, payment_method, payment_reference
 *   - access_token, pdf_filename, pdf_path
 *
 * Side effects of a successful save:
 *   - subtotal / tax_amount / total_amount / total recomputed from
 *     line items server-side; form totals are display hints only
 *   - balance_due = total_amount - amount_paid
 *   - pdf_version = 0 and pdf_generated_at = NULL so the next
 *     "Regenerate PDF" / download produces a fresh copy
 *   - activity_log entry via logActivityExtended(..., 'Invoice updated')
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('billing.edit');

$db = getDB();
$error = '';
$flash = '';

$invoiceId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$invoiceId) {
    header('Location: index.php');
    exit;
}

// ── Load existing invoice + related data ─────────────────────────────────────
$stmt = $db->prepare("
    SELECT
        i.*,
        c.company_name,
        COALESCE(ct.first_name, dc.first_name) as contact_first,
        COALESCE(ct.last_name, dc.last_name) as contact_last,
        COALESCE(ct.email, dc.email) as contact_email,
        p.address as property_address,
        p.city as property_city,
        p.postal_code as property_postal,
        u.full_name as created_by_name
    FROM invoices i
    LEFT JOIN companies c ON i.company_id = c.id
    LEFT JOIN contacts ct ON c.primary_contact_id = ct.id
    LEFT JOIN contacts dc ON i.contact_id = dc.id
    LEFT JOIN properties p ON i.property_id = p.id
    LEFT JOIN users u ON i.created_by = u.id
    WHERE i.id = ?
");
$stmt->execute([$invoiceId]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    header('Location: index.php');
    exit;
}

// Paid invoices are locked — force user down the refund / void path.
if ($invoice['status'] === 'paid') {
    header('Location: view.php?id=' . $invoiceId . '&flash=paid_lock');
    exit;
}

// Existing line items (pre-fill the editor)
$stmt = $db->prepare("SELECT id, description, quantity, unit_price, line_total, sort_order, visit_id FROM invoice_line_items WHERE invoice_id = ? ORDER BY sort_order, id");
$stmt->execute([$invoiceId]);
$lineItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Handle form submission ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please reload the page and try again.';
    } else {
        // Scalar fields
        $issueDate     = $_POST['issue_date'] ?? $invoice['issue_date'];
        $dueDate       = $_POST['due_date']   ?? $invoice['due_date'];
        $newStatus     = $_POST['status']     ?? $invoice['status'];
        $notes         = trim($_POST['notes'] ?? '');
        $internalNotes = trim($_POST['internal_notes'] ?? '');

        $serviceAddress    = trim($_POST['service_address'] ?? '');
        $serviceCity       = trim($_POST['service_city'] ?? '');
        $serviceProvince   = trim($_POST['service_province'] ?? '');
        $servicePostalCode = trim($_POST['service_postal_code'] ?? '');

        $billingAddress    = trim($_POST['billing_address'] ?? '');
        $billingCity       = trim($_POST['billing_city'] ?? '');
        $billingProvince   = trim($_POST['billing_province'] ?? '');
        $billingPostalCode = trim($_POST['billing_postal_code'] ?? '');

        $addressDiffers = (
            $serviceAddress     !== $billingAddress    ||
            $serviceCity        !== $billingCity       ||
            $serviceProvince    !== $billingProvince   ||
            $servicePostalCode  !== $billingPostalCode
        ) ? 1 : 0;

        $taxRate = floatval($invoice['tax_rate'] ?? 0.05);
        if ($taxRate <= 0) $taxRate = 0.05;

        // Status is restricted to non-paid values — "paid" must come from
        // record-payment.php which also updates amount_paid + paid_at.
        $allowedStatuses = ['draft', 'sent', 'viewed', 'partial', 'overdue', 'cancelled'];
        if (!in_array($newStatus, $allowedStatuses, true)) {
            $newStatus = $invoice['status'] === 'paid' ? 'paid' : 'draft';
        }

        // Line items
        $postDescriptions = $_POST['li_description'] ?? [];
        $postQuantities   = $_POST['li_quantity']    ?? [];
        $postUnitPrices   = $_POST['li_unit_price']  ?? [];
        $postSortOrders   = $_POST['li_sort_order']  ?? [];

        $cleanLineItems = [];
        $runningSubtotal = 0.0;
        $rowCount = max(count($postDescriptions), count($postUnitPrices));
        for ($i = 0; $i < $rowCount; $i++) {
            $desc = trim((string)($postDescriptions[$i] ?? ''));
            $qty  = floatval($postQuantities[$i] ?? 0);
            $unit = floatval($postUnitPrices[$i] ?? 0);
            // Skip completely empty rows
            if ($desc === '' && $qty === 0.0 && $unit === 0.0) continue;
            if ($qty <= 0) $qty = 1;
            $lineTotal = round($qty * $unit, 2);
            $runningSubtotal += $lineTotal;
            $cleanLineItems[] = [
                'description' => $desc !== '' ? $desc : 'Services rendered',
                'quantity'    => $qty,
                'unit_price'  => $unit,
                'line_total'  => $lineTotal,
                'sort_order'  => intval($postSortOrders[$i] ?? $i),
            ];
        }

        if (empty($cleanLineItems)) {
            $error = 'Please keep at least one line item.';
        } else {
            $newSubtotal  = round($runningSubtotal, 2);
            $newTaxAmount = round($newSubtotal * $taxRate, 2);
            $newTotal     = round($newSubtotal + $newTaxAmount, 2);
            $amountPaid   = round(floatval($invoice['amount_paid'] ?? 0), 2);
            $newBalance   = round(max(0.0, $newTotal - $amountPaid), 2);

            try {
                $db->beginTransaction();

                // Update invoice scalar fields
                $db->prepare("
                    UPDATE invoices
                    SET issue_date        = ?,
                        due_date          = ?,
                        status            = ?,
                        service_address   = ?,
                        service_city      = ?,
                        service_province  = ?,
                        service_postal_code = ?,
                        billing_address   = ?,
                        billing_city      = ?,
                        billing_province  = ?,
                        billing_postal_code = ?,
                        address_differs   = ?,
                        notes             = ?,
                        internal_notes    = ?,
                        subtotal          = ?,
                        tax_rate          = ?,
                        tax_amount        = ?,
                        total_amount      = ?,
                        total             = ?,
                        balance_due       = ?,
                        pdf_version       = 0,
                        pdf_generated_at  = NULL,
                        updated_at        = NOW()
                    WHERE id = ?
                ")->execute([
                    $issueDate,
                    $dueDate,
                    $newStatus,
                    $serviceAddress,
                    $serviceCity,
                    $serviceProvince,
                    $servicePostalCode,
                    $billingAddress,
                    $billingCity,
                    $billingProvince,
                    $billingPostalCode,
                    $addressDiffers,
                    $notes,
                    $internalNotes,
                    $newSubtotal,
                    $taxRate,
                    $newTaxAmount,
                    $newTotal,
                    $newTotal,
                    $newBalance,
                    $invoiceId,
                ]);

                // Replace line items — simplest correct behaviour. A
                // stable-diff version that UPDATEs existing rows can come
                // later; for now this is one atomic transaction and the
                // client-facing invoice_line_items IDs are not referenced
                // anywhere else.
                $db->prepare("DELETE FROM invoice_line_items WHERE invoice_id = ?")->execute([$invoiceId]);

                $liStmt = $db->prepare("
                    INSERT INTO invoice_line_items
                        (invoice_id, description, quantity, unit_price, line_total, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                foreach ($cleanLineItems as $idx => $li) {
                    $liStmt->execute([
                        $invoiceId,
                        $li['description'],
                        $li['quantity'],
                        $li['unit_price'],
                        $li['line_total'],
                        $idx,
                    ]);
                }

                // Build a short "what changed" string for the activity log
                $changes = [];
                if ($invoice['issue_date']     !== $issueDate)     $changes[] = "issue {$invoice['issue_date']} → {$issueDate}";
                if ($invoice['due_date']       !== $dueDate)       $changes[] = "due {$invoice['due_date']} → {$dueDate}";
                if ($invoice['status']         !== $newStatus)     $changes[] = "status {$invoice['status']} → {$newStatus}";
                if (abs(floatval($invoice['total_amount'] ?? 0) - $newTotal) > 0.005) {
                    $changes[] = 'total $' . number_format(floatval($invoice['total_amount'] ?? 0), 2) . ' → $' . number_format($newTotal, 2);
                }
                if (count($lineItems) !== count($cleanLineItems)) {
                    $changes[] = 'line items ' . count($lineItems) . ' → ' . count($cleanLineItems);
                }
                $detail = 'Invoice ' . ($invoice['invoice_number'] ?? '#' . $invoiceId) . ' updated';
                if ($changes) $detail .= ' — ' . implode(' · ', $changes);

                logActivityExtended(
                    $user['id'],
                    'Invoice updated',
                    $detail,
                    $invoice['company_id'] ?: null,
                    null,
                    null,
                    $invoiceId,
                    $invoice['plan_id'] ?: null,
                    $invoice['visit_id'] ?: null
                );

                $db->commit();

                header('Location: view.php?id=' . $invoiceId . '&flash=updated');
                exit;

            } catch (Throwable $e) {
                $db->rollBack();
                error_log('Invoice edit error: ' . $e->getMessage());
                $error = 'Error saving invoice. Please try again.';
            }
        }
    }
}

$csrfToken = generateCSRFToken();

$pageTitle  = 'Edit Invoice';
$activePage = 'invoices';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

            <a href="view.php?id=<?php echo $invoiceId; ?>" class="mw-back-link">&larr; Back to Invoice</a>

            <h1 class="h3 mb-1">Edit Invoice</h1>
            <p class="text-muted mb-4">
                <?php echo htmlspecialchars($invoice['invoice_number']); ?>
                <?php if (!empty($invoice['company_name'])): ?>
                    &bull; <?php echo htmlspecialchars($invoice['company_name']); ?>
                <?php elseif (!empty($invoice['contact_first']) || !empty($invoice['contact_last'])): ?>
                    &bull; <?php echo htmlspecialchars(trim($invoice['contact_first'] . ' ' . $invoice['contact_last'])); ?>
                <?php endif; ?>
            </p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (floatval($invoice['amount_paid'] ?? 0) > 0 && $invoice['status'] !== 'paid'): ?>
                <div class="alert alert-warning">
                    <strong>Partial payment on file.</strong>
                    $<?php echo number_format(floatval($invoice['amount_paid']), 2); ?> has already been paid.
                    The balance due will recalculate from the new total.
                </div>
            <?php endif; ?>

            <form method="POST" id="invoiceEditForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                <div class="card">
                    <div class="card-body">
                        <h3 class="card-title mb-3">Invoice Details</h3>

                        <!-- Customer (locked) -->
                        <div class="mw-form-group">
                            <label class="form-label text-muted" style="font-size:.75rem; text-transform:uppercase; letter-spacing:.5px;">Customer (locked)</label>
                            <div class="form-control" style="background:#f7f7f7;">
                                <?php
                                if (!empty($invoice['company_name'])) {
                                    echo htmlspecialchars($invoice['company_name']);
                                } else {
                                    echo htmlspecialchars(trim($invoice['contact_first'] . ' ' . $invoice['contact_last']) ?: '—');
                                }
                                if (!empty($invoice['contact_email'])) {
                                    echo ' &bull; <span class="text-muted">' . htmlspecialchars($invoice['contact_email']) . '</span>';
                                }
                                ?>
                            </div>
                            <small class="text-muted">Changing the customer on an existing invoice is not allowed. Cancel this invoice and create a new one instead.</small>
                        </div>

                        <!-- Dates + Status -->
                        <div class="mw-form-row three mt-3">
                            <div class="mw-form-group">
                                <label class="form-label">Issue Date</label>
                                <input type="date" name="issue_date" class="form-control"
                                       value="<?php echo htmlspecialchars($invoice['issue_date']); ?>" required>
                            </div>
                            <div class="mw-form-group">
                                <label class="form-label">Due Date</label>
                                <input type="date" name="due_date" class="form-control"
                                       value="<?php echo htmlspecialchars($invoice['due_date']); ?>" required>
                            </div>
                            <div class="mw-form-group">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-control">
                                    <?php
                                    $statusOptions = [
                                        'draft'     => 'Draft',
                                        'sent'      => 'Sent',
                                        'viewed'    => 'Viewed',
                                        'partial'   => 'Partial',
                                        'overdue'   => 'Overdue',
                                        'cancelled' => 'Cancelled',
                                    ];
                                    foreach ($statusOptions as $value => $label) {
                                        $sel = ($invoice['status'] === $value) ? ' selected' : '';
                                        echo '<option value="' . $value . '"' . $sel . '>' . $label . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Addresses ───────────────────────────────────── -->
                <div class="card">
                    <div class="card-body">
                        <h3 class="card-title mb-3">Addresses</h3>

                        <h5 class="mb-3">Service Address <span class="text-muted fw-normal" style="font-size:.85rem;">(where work was performed)</span></h5>
                        <div class="mw-form-row">
                            <div class="mw-form-group full">
                                <label class="form-label">Address</label>
                                <input type="text" name="service_address" class="form-control"
                                       value="<?php echo htmlspecialchars($invoice['service_address'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="mw-form-row three">
                            <div class="mw-form-group">
                                <label class="form-label">City</label>
                                <input type="text" name="service_city" class="form-control"
                                       value="<?php echo htmlspecialchars($invoice['service_city'] ?? ''); ?>">
                            </div>
                            <div class="mw-form-group">
                                <label class="form-label">Province</label>
                                <select name="service_province" class="form-control">
                                    <?php
                                    $provinces = ['' => '—', 'AB'=>'AB','BC'=>'BC','MB'=>'MB','NB'=>'NB','NL'=>'NL','NS'=>'NS','NT'=>'NT','NU'=>'NU','ON'=>'ON','PE'=>'PE','QC'=>'QC','SK'=>'SK','YT'=>'YT'];
                                    $svcProv = $invoice['service_province'] ?? '';
                                    foreach ($provinces as $code => $label) {
                                        $sel = ($code === $svcProv) ? ' selected' : '';
                                        echo '<option value="' . $code . '"' . $sel . '>' . $label . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="mw-form-group">
                                <label class="form-label">Postal Code</label>
                                <input type="text" name="service_postal_code" class="form-control"
                                       value="<?php echo htmlspecialchars($invoice['service_postal_code'] ?? ''); ?>">
                            </div>
                        </div>

                        <hr>

                        <div class="custom-control custom-checkbox mb-3">
                            <input type="checkbox" class="custom-control-input" id="differentBillingAddress"
                                   <?php echo !empty($invoice['address_differs']) ? 'checked' : ''; ?>>
                            <label class="custom-control-label" for="differentBillingAddress">
                                Billing address differs from service address
                            </label>
                        </div>
                        <div id="billingAddressSection" style="<?php echo !empty($invoice['address_differs']) ? '' : 'display:none;'; ?>">
                            <h5 class="mb-3">Billing Address</h5>
                            <div class="mw-form-row">
                                <div class="mw-form-group full">
                                    <label class="form-label">Address</label>
                                    <input type="text" name="billing_address" class="form-control"
                                           value="<?php echo htmlspecialchars($invoice['billing_address'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="mw-form-row three">
                                <div class="mw-form-group">
                                    <label class="form-label">City</label>
                                    <input type="text" name="billing_city" class="form-control"
                                           value="<?php echo htmlspecialchars($invoice['billing_city'] ?? ''); ?>">
                                </div>
                                <div class="mw-form-group">
                                    <label class="form-label">Province</label>
                                    <select name="billing_province" class="form-control">
                                        <?php
                                        $billProv = $invoice['billing_province'] ?? '';
                                        foreach ($provinces as $code => $label) {
                                            $sel = ($code === $billProv) ? ' selected' : '';
                                            echo '<option value="' . $code . '"' . $sel . '>' . $label . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="mw-form-group">
                                    <label class="form-label">Postal Code</label>
                                    <input type="text" name="billing_postal_code" class="form-control"
                                           value="<?php echo htmlspecialchars($invoice['billing_postal_code'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Line Items ──────────────────────────────────── -->
                <div class="card">
                    <div class="card-body">
                        <h3 class="card-title mb-3">Line Items</h3>

                        <table class="table table-sm table-bordered mb-0" id="lineItemsTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Description</th>
                                    <th style="width:90px;">Qty</th>
                                    <th style="width:120px;">Unit Price</th>
                                    <th style="width:120px;">Line Total</th>
                                    <th style="width:46px;"></th>
                                </tr>
                            </thead>
                            <tbody id="lineItemsBody">
                                <?php if (empty($lineItems)): ?>
                                    <tr class="mw-li-row">
                                        <td><input type="text" name="li_description[]" class="form-control form-control-sm mw-li-desc" value="Services rendered"></td>
                                        <td><input type="number" step="0.01" min="0" name="li_quantity[]" class="form-control form-control-sm mw-li-qty" value="1"></td>
                                        <td><input type="number" step="0.01" min="0" name="li_unit_price[]" class="form-control form-control-sm mw-li-unit" value="0.00"></td>
                                        <td class="mw-li-total text-right font-weight-600 pr-2">$0.00</td>
                                        <td><button type="button" class="btn btn-sm btn-outline-danger mw-li-remove" title="Remove">&times;</button></td>
                                        <input type="hidden" name="li_sort_order[]" value="0">
                                    </tr>
                                <?php else: foreach ($lineItems as $idx => $li): ?>
                                    <tr class="mw-li-row">
                                        <td><input type="text" name="li_description[]" class="form-control form-control-sm mw-li-desc" value="<?php echo htmlspecialchars($li['description']); ?>"></td>
                                        <td><input type="number" step="0.01" min="0" name="li_quantity[]" class="form-control form-control-sm mw-li-qty" value="<?php echo htmlspecialchars((string)$li['quantity']); ?>"></td>
                                        <td><input type="number" step="0.01" min="0" name="li_unit_price[]" class="form-control form-control-sm mw-li-unit" value="<?php echo htmlspecialchars(number_format((float)$li['unit_price'], 2, '.', '')); ?>"></td>
                                        <td class="mw-li-total text-right font-weight-600 pr-2">$<?php echo number_format((float)$li['line_total'], 2); ?></td>
                                        <td><button type="button" class="btn btn-sm btn-outline-danger mw-li-remove" title="Remove">&times;</button></td>
                                        <input type="hidden" name="li_sort_order[]" value="<?php echo (int)($li['sort_order'] ?? $idx); ?>">
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>

                        <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="addLineItemBtn">
                            <i data-feather="plus" class="mr-1"></i> Add Line
                        </button>

                        <div class="mw-totals-box mt-3">
                            <div class="mw-totals-row">
                                <span>Subtotal</span>
                                <span class="mw-totals-value" id="editSubtotalDisplay">$0.00</span>
                            </div>
                            <div class="mw-totals-row">
                                <span>GST (<?php echo number_format((float)($invoice['tax_rate'] ?? 0.05) * 100, 0); ?>%)</span>
                                <span class="mw-totals-value" id="editTaxDisplay">$0.00</span>
                            </div>
                            <div class="mw-totals-row grand">
                                <span>Total</span>
                                <span class="mw-totals-value" id="editTotalDisplay">$0.00</span>
                            </div>
                            <?php if (floatval($invoice['amount_paid'] ?? 0) > 0): ?>
                            <div class="mw-totals-row text-muted" style="font-size:.85rem;">
                                <span>Paid to date</span>
                                <span class="mw-totals-value">&minus; $<?php echo number_format(floatval($invoice['amount_paid']), 2); ?></span>
                            </div>
                            <div class="mw-totals-row grand">
                                <span>Balance Due</span>
                                <span class="mw-totals-value" id="editBalanceDisplay">$0.00</span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- ── Notes ─────────────────────────────────────────── -->
                <div class="card">
                    <div class="card-body">
                        <h3 class="card-title mb-3">Notes</h3>

                        <div class="mw-form-group">
                            <label class="form-label">Invoice Notes <span class="text-muted">(shown to customer)</span></label>
                            <textarea name="notes" class="form-control" rows="3"><?php echo htmlspecialchars($invoice['notes'] ?? ''); ?></textarea>
                        </div>

                        <div class="mw-form-group">
                            <label class="form-label">Internal Notes <span class="text-muted">(not visible to customer)</span></label>
                            <textarea name="internal_notes" class="form-control" rows="2"><?php echo htmlspecialchars($invoice['internal_notes'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="mw-form-actions">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <a href="view.php?id=<?php echo $invoiceId; ?>" class="btn btn-secondary">Cancel</a>
                </div>

                <p class="text-muted mt-3" style="font-size:.8rem;">
                    Saving will invalidate the existing PDF. Re-download or re-send the invoice to generate a fresh copy.
                </p>
            </form>

<script>
(function () {
    'use strict';

    var AMOUNT_PAID = <?php echo json_encode(round(floatval($invoice['amount_paid'] ?? 0), 2)); ?>;
    var TAX_RATE    = <?php echo json_encode(round(floatval($invoice['tax_rate']    ?? 0.05), 4)); ?>;

    var tbody       = document.getElementById('lineItemsBody');
    var addBtn      = document.getElementById('addLineItemBtn');
    var subDisp     = document.getElementById('editSubtotalDisplay');
    var taxDisp     = document.getElementById('editTaxDisplay');
    var totalDisp   = document.getElementById('editTotalDisplay');
    var balDisp     = document.getElementById('editBalanceDisplay');

    // ── Totals recomputation ────────────────────────────
    function recalc() {
        var subtotal = 0;
        tbody.querySelectorAll('.mw-li-row').forEach(function (row) {
            var q = parseFloat(row.querySelector('.mw-li-qty').value)  || 0;
            var u = parseFloat(row.querySelector('.mw-li-unit').value) || 0;
            var lt = Math.round(q * u * 100) / 100;
            row.querySelector('.mw-li-total').textContent = '$' + lt.toFixed(2);
            subtotal += lt;
        });
        var tax   = Math.round(subtotal * TAX_RATE * 100) / 100;
        var total = subtotal + tax;
        subDisp.textContent   = '$' + subtotal.toFixed(2);
        taxDisp.textContent   = '$' + tax.toFixed(2);
        totalDisp.textContent = '$' + total.toFixed(2);
        if (balDisp) {
            var bal = Math.max(0, total - AMOUNT_PAID);
            balDisp.textContent = '$' + bal.toFixed(2);
        }
    }

    // ── Add / remove rows ──────────────────────────────
    function wireRow(row) {
        row.querySelectorAll('.mw-li-qty, .mw-li-unit').forEach(function (inp) {
            inp.addEventListener('input', recalc);
        });
        var removeBtn = row.querySelector('.mw-li-remove');
        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
                // Keep at least one row so the form is always valid
                if (tbody.querySelectorAll('.mw-li-row').length <= 1) {
                    row.querySelector('.mw-li-desc').value = '';
                    row.querySelector('.mw-li-qty').value  = '1';
                    row.querySelector('.mw-li-unit').value = '0.00';
                    recalc();
                    return;
                }
                row.remove();
                recalc();
            });
        }
    }

    tbody.querySelectorAll('.mw-li-row').forEach(wireRow);

    addBtn.addEventListener('click', function () {
        var row = document.createElement('tr');
        row.className = 'mw-li-row';
        row.innerHTML =
            '<td><input type="text" name="li_description[]" class="form-control form-control-sm mw-li-desc" value=""></td>' +
            '<td><input type="number" step="0.01" min="0" name="li_quantity[]" class="form-control form-control-sm mw-li-qty" value="1"></td>' +
            '<td><input type="number" step="0.01" min="0" name="li_unit_price[]" class="form-control form-control-sm mw-li-unit" value="0.00"></td>' +
            '<td class="mw-li-total text-right font-weight-600 pr-2">$0.00</td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger mw-li-remove" title="Remove">&times;</button></td>' +
            '<input type="hidden" name="li_sort_order[]" value="' + tbody.querySelectorAll('.mw-li-row').length + '">';
        tbody.appendChild(row);
        wireRow(row);
        row.querySelector('.mw-li-desc').focus();
        recalc();
    });

    // ── Billing address toggle ─────────────────────────
    var billingToggle = document.getElementById('differentBillingAddress');
    var billingSection = document.getElementById('billingAddressSection');
    if (billingToggle && billingSection) {
        billingToggle.addEventListener('change', function () {
            billingSection.style.display = billingToggle.checked ? '' : 'none';
        });
    }

    recalc();
}());
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
