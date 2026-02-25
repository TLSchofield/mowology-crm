<?php
/**
 * Invoice View - Internal CRM View
 * AppStack layout via shared includes.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/messaging.php';
// Note: pdf_bootstrap.php and PdfGenerator.php are loaded lazily below only when PDF generation is needed

requireLogin();
$user = getCurrentUser();
requirePermission('billing.view');

$invoiceId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$invoiceId) {
    header('Location: index.php');
    exit;
}

$db = getDB();

// Get invoice with related data
$stmt = $db->prepare("
    SELECT
        i.*,
        c.company_name,
        COALESCE(NULLIF(c.billing_email,''), NULLIF(dc.email,''), NULLIF(ct.email,'')) as billing_email,
        COALESCE(NULLIF(c.billing_phone,''), NULLIF(dc.phone,''), NULLIF(ct.phone,'')) as billing_phone,
        COALESCE(NULLIF(i.billing_address,''), NULLIF(c.billing_address,''))       as billing_address,
        COALESCE(NULLIF(i.billing_city,''),    NULLIF(c.billing_city,''))           as billing_city,
        COALESCE(NULLIF(i.billing_province,''),NULLIF(c.billing_province,''))       as billing_province,
        COALESCE(NULLIF(i.billing_postal_code,''),NULLIF(c.billing_postal_code,'')) as billing_postal_code,
        COALESCE(ct.first_name, dc.first_name) as contact_first,
        COALESCE(ct.last_name, dc.last_name) as contact_last,
        COALESCE(ct.email, dc.email) as contact_email,
        COALESCE(ct.phone, dc.phone) as contact_phone,
        p.address as property_address,
        p.city as property_city,
        p.postal_code as property_postal,
        jv.visit_number,
        jp.plan_number,
        jp.title as plan_title,
        u.full_name as created_by_name
    FROM invoices i
    LEFT JOIN companies c ON i.company_id = c.id
    LEFT JOIN contacts ct ON c.primary_contact_id = ct.id
    LEFT JOIN contacts dc ON i.contact_id = dc.id
    LEFT JOIN properties p ON i.property_id = p.id
    LEFT JOIN job_visits jv ON i.visit_id = jv.id
    LEFT JOIN job_plans jp ON i.plan_id = jp.id
    LEFT JOIN users u ON i.created_by = u.id
    WHERE i.id = ?
");
$stmt->execute([$invoiceId]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    header('Location: index.php');
    exit;
}

// Get line items
$stmt = $db->prepare("SELECT * FROM invoice_line_items WHERE invoice_id = ? ORDER BY sort_order");
$stmt->execute([$invoiceId]);
$lineItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Phase 2-3: Get invoice recipients
$stmt = $db->prepare("
    SELECT ic.id, ic.contact_id, ic.email_address, ic.contact_role,
           ic.invoice_sent_at, ic.bounced,
           c.first_name, c.last_name, c.receive_sms
    FROM invoice_contacts ic
    LEFT JOIN contacts c ON ic.contact_id = c.id
    WHERE ic.invoice_id = ?
    ORDER BY
        CASE ic.contact_role
            WHEN 'primary_recipient' THEN 1
            WHEN 'property_manager' THEN 2
            WHEN 'owner_contact' THEN 3
            WHEN 'billing_contact' THEN 4
            WHEN 'accounting' THEN 5
            WHEN 'strata_manager' THEN 6
            ELSE 7
        END,
        ic.created_at
");
$stmt->execute([$invoiceId]);
$invoiceRecipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get activity for this invoice
$stmt = $db->prepare("
    SELECT a.*, u.full_name
    FROM activity_log a
    LEFT JOIN users u ON a.user_id = u.id
    WHERE a.invoice_id = ?
    ORDER BY a.created_at DESC
    LIMIT 10
");
$stmt->execute([$invoiceId]);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle actions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'send') {
        // Update status to sent
        $stmt = $db->prepare("UPDATE invoices SET status = 'sent', sent_at = NOW() WHERE id = ? AND status IN ('draft', 'sent')");
        $stmt->execute([$invoiceId]);

        // Phase 2-3: Get all recipients from invoice_contacts table
        $stmt = $db->prepare("
            SELECT ic.id, ic.contact_id, ic.email_address, ic.contact_role,
                   c.first_name, c.last_name, c.receive_sms,
                   COALESCE(NULLIF(c.mobile,''), NULLIF(c.phone,'')) as sms_phone
            FROM invoice_contacts ic
            LEFT JOIN contacts c ON ic.contact_id = c.id
            WHERE ic.invoice_id = ?
            ORDER BY
                CASE ic.contact_role
                    WHEN 'primary_recipient' THEN 1
                    WHEN 'property_manager' THEN 2
                    WHEN 'owner_contact' THEN 3
                    WHEN 'billing_contact' THEN 4
                    WHEN 'accounting' THEN 5
                    WHEN 'strata_manager' THEN 6
                    ELSE 7
                END,
                ic.created_at
        ");
        $stmt->execute([$invoiceId]);
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Generate PDF once (used for all recipients)
        $attachPath = null;
        require_once dirname(__DIR__) . '/includes/pdf_bootstrap.php';
        require_once dirname(__DIR__) . '/includes/PdfGenerator.php';

        $pdfGen = new PdfGenerator();
        $existingPath = $pdfGen->getPdfPath('invoice', $invoiceId);
        if ($existingPath) {
            $attachPath = $existingPath;
        } else {
            $pdfResult = $pdfGen->generateInvoicePdf($invoiceId);
            if ($pdfResult['success']) {
                $attachPath = $pdfResult['path'];
            }
        }

        // Ensure the invoice has an access_token (older invoices may not have one)
        if (empty($invoice['access_token'])) {
            $newToken = generateAccessToken();
            $db->prepare("UPDATE invoices SET access_token = ?, token_expires_at = DATE_ADD(NOW(), INTERVAL 90 DAY) WHERE id = ?")
               ->execute([$newToken, $invoiceId]);
            $invoice['access_token'] = $newToken;
        }

        // Build Bill To / view URL once (same for all recipients)
        $billToContactName = trim(($invoice['contact_first'] ?? '') . ' ' . ($invoice['contact_last'] ?? ''));
        $billToCompany     = $invoice['company_name'] ?? '';
        $billToLines       = [];
        if ($billToCompany)     { $billToLines[] = '<strong>' . htmlspecialchars($billToCompany) . '</strong>'; }
        if ($billToContactName) { $billToLines[] = htmlspecialchars($billToContactName); }
        $invoiceViewUrl = 'https://mowology.ca/customer/invoice.php?token=' . urlencode($invoice['access_token']);

        // Send to each recipient
        $sentTo = [];
        $smsRecipients = [];

        foreach ($recipients as $recipient) {
            if (empty($recipient['email_address'])) {
                continue;
            }

            $recipientName = !empty($recipient['first_name'])
                ? "{$recipient['first_name']} {$recipient['last_name']}"
                : ($invoice['company_name'] ?: trim(($invoice['contact_first'] ?? '') . ' ' . ($invoice['contact_last'] ?? '')) ?: 'Valued Customer');

            $emailSubject = "Invoice {$invoice['invoice_number']} from Mowology";

            $emailBody = "
                <h2 style='color:#0D3B2E;margin-bottom:8px;'>Invoice from Mowology</h2>
                <p>Hi " . htmlspecialchars($recipientName) . ",</p>
                <p>Please find your invoice details below:</p>

                <table style='border-collapse:collapse;width:100%;max-width:480px;margin:16px 0;font-size:14px;'>
                    <tr>
                        <td style='padding:6px 12px 6px 0;color:#555;white-space:nowrap;'>Bill To</td>
                        <td style='padding:6px 0;color:#0D3B2E;font-weight:600;'>
                            " . (!empty($billToLines) ? implode('<br>', $billToLines) : htmlspecialchars($recipientName)) . "
                        </td>
                    </tr>
                    <tr>
                        <td style='padding:6px 12px 6px 0;color:#555;white-space:nowrap;'>Invoice #</td>
                        <td style='padding:6px 0;color:#0D3B2E;'><strong>{$invoice['invoice_number']}</strong></td>
                    </tr>
                    <tr>
                        <td style='padding:6px 12px 6px 0;color:#555;white-space:nowrap;'>Amount Due</td>
                        <td style='padding:6px 0;color:#0D3B2E;font-size:18px;font-weight:700;'>" . formatCurrency($invoice['balance_due']) . " CAD</td>
                    </tr>
                    <tr>
                        <td style='padding:6px 12px 6px 0;color:#555;white-space:nowrap;'>Due Date</td>
                        <td style='padding:6px 0;color:#0D3B2E;'>" . formatDate($invoice['due_date']) . "</td>
                    </tr>
                </table>
            " . ($invoiceViewUrl ? "
                <p style='margin:20px 0;'>
                    <a href='" . htmlspecialchars($invoiceViewUrl) . "' style='display:inline-block;background:#2D8659;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:700;font-size:15px;'>
                        View &amp; Pay Invoice Online
                    </a>
                </p>
                <p style='font-size:12px;color:#888;margin-top:4px;'>Or copy this link: <a href='" . htmlspecialchars($invoiceViewUrl) . "' style='color:#2D8659;'>" . htmlspecialchars($invoiceViewUrl) . "</a></p>
            " : '') . "
                <p>If you have any questions about this invoice, please contact us at <a href='tel:+17788469273'>(778) 846-9273</a> or reply to this email.</p>
                <p style='margin-top:20px;'>Thank you for your business!<br><strong>The Mowology Team</strong></p>
            ";

            // Send email
            $emailResult = sendCrmEmail($recipient['email_address'], $emailSubject, $emailBody, $attachPath);
            if ($emailResult) {
                $sentTo[] = $recipientName;

                // Update invoice_contacts with send timestamp
                $updateStmt = $db->prepare("
                    UPDATE invoice_contacts SET invoice_sent_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([$recipient['id']]);

                // Track SMS recipients (those who have consent and a phone number)
                if ($recipient['receive_sms'] && !empty($recipient['sms_phone'])) {
                    $smsRecipients[] = [
                        'name'  => $recipientName,
                        'role'  => $recipient['contact_role'],
                        'phone' => $recipient['sms_phone'],
                    ];
                }
            } else {
                error_log("Email send failed for invoice {$invoiceId} to {$recipient['email_address']}");
            }
        }

        // Send SMS notifications (no URLs — carrier gateways block them)
        $smsSentTo = [];
        if (!empty($smsRecipients)) {
            foreach ($smsRecipients as $smsR) {
                $smsMessage = sendInvoiceNotificationSms(
                    $smsR['phone'],
                    $invoice['invoice_number'],
                    $invoice['balance_due']
                );
                if ($smsMessage['success']) {
                    $smsSentTo[] = $smsR['name'];
                } else {
                    error_log("Invoice SMS failed for {$smsR['name']} ({$smsR['phone']}): " . implode(', ', $smsMessage['errors'] ?? []));
                }
            }
        }

        // Build activity log message
        $attachNote = $attachPath ? ' (with PDF attached)' : '';
        if (!empty($sentTo)) {
            $recipientList = implode(', ', $sentTo);
            $details = "Invoice sent to {$recipientList}{$attachNote}";
            if (!empty($smsSentTo)) {
                $details .= "; SMS sent to: " . implode(', ', $smsSentTo);
            }
            logActivityExtended($user['id'], 'Invoice sent', $details, null, null, null, $invoiceId);

            $invoice['status'] = 'sent';
            $message = "Invoice sent successfully to " . count($sentTo) . " recipient(s)";
            if (!empty($smsSentTo)) {
                $message .= " and SMS sent to " . count($smsSentTo) . " contact(s)";
            }
            $messageType = 'success';
        } else {
            $message = 'No valid recipients found. Please add recipients to this invoice first.';
            $messageType = 'warning';
        }
    }

    if ($action === 'mark_paid') {
        $paymentMethod    = trim($_POST['payment_method'] ?? 'other');
        $paymentReference = trim($_POST['payment_reference'] ?? '');
        $paymentAmount    = floatval($_POST['payment_amount'] ?? $invoice['balance_due']);
        $paymentDate      = trim($_POST['payment_date'] ?? '');

        // Validate date
        $paidAtVal = 'NOW()';
        $paidAtParam = null;
        if ($paymentDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
            $paidAtVal   = '?';
            $paidAtParam = $paymentDate . ' 12:00:00';
        }

        $newBalance = max(0, floatval($invoice['balance_due']) - $paymentAmount);
        $newStatus  = $newBalance <= 0.005 ? 'paid' : 'partial';

        $params = [$paymentAmount, $newBalance, $newStatus, $paymentMethod, $paymentReference];
        if ($paidAtParam) $params[] = $paidAtParam;
        $params[] = $invoiceId;

        $stmt = $db->prepare("
            UPDATE invoices
            SET amount_paid       = amount_paid + ?,
                balance_due       = ?,
                status            = ?,
                payment_method    = ?,
                payment_reference = ?,
                paid_at           = {$paidAtVal}
            WHERE id = ?
        ");
        $stmt->execute($params);

        $methodLabel = ['e_transfer'=>'e-Transfer','cash'=>'Cash','cheque'=>'Cheque','credit_card'=>'Credit Card','other'=>'Other'][$paymentMethod] ?? ucfirst($paymentMethod);
        $detail = "Payment of " . formatCurrency($paymentAmount) . " via {$methodLabel}";
        if ($paymentReference) $detail .= " (Ref: {$paymentReference})";
        logActivityExtended($user['id'], 'Payment recorded', $detail, null, null, null, $invoiceId);

        $invoice['status']      = $newStatus;
        $invoice['balance_due'] = $newBalance;
        $message     = "Payment of " . formatCurrency($paymentAmount) . " recorded successfully.";
        $messageType = 'success';
    }
}

$csrfToken = generateCSRFToken();

// Check for success messages
if (isset($_GET['created'])) {
    $message = 'Invoice created successfully!';
    $messageType = 'success';
}
if (isset($_GET['pdf_generated'])) {
    $message = 'PDF generated successfully.';
    $messageType = 'success';
}

$pageTitle = 'Invoice ' . htmlspecialchars($invoice['invoice_number']);
$activePage = 'invoices';

// Load Stripe.js only for invoices that can be paid online
$isPayable = in_array($invoice['status'], ['sent', 'viewed', 'partial', 'overdue']);
$extraHead = $isPayable
    ? '<script src="https://js.stripe.com/v3/" defer></script>'
    : '';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <a href="index.php" class="mw-back-link">&larr; Back to Invoices</a>

          <?php if ($message): ?>
              <div class="mw-message <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
          <?php endif; ?>

          <div class="mw-page-header">
              <div>
                  <h1 class="h3 mb-1"><?php echo htmlspecialchars($invoice['invoice_number']); ?></h1>
                  <div>
                      <?php echo getStatusBadge($invoice['status'], 'invoice'); ?>
                      <?php if (!empty($invoice['visit_number'])): ?>
                          <span class="ml-2 text-muted">
                              Visit: <a href="../jobs/view.php?id=<?php echo $invoice['plan_id']; ?>"><?php echo htmlspecialchars($invoice['visit_number']); ?></a>
                          </span>
                      <?php elseif (!empty($invoice['plan_number'])): ?>
                          <span class="ml-2 text-muted">
                              Plan: <a href="../jobs/view.php?id=<?php echo $invoice['plan_id']; ?>"><?php echo htmlspecialchars($invoice['plan_number']); ?></a>
                          </span>
                      <?php endif; ?>
                  </div>
              </div>
              <div class="mw-header-actions">
                  <!-- PDF Actions -->
                  <a href="../documents/generate_pdf.php?type=invoice&id=<?php echo $invoiceId; ?>&action=download"
                     class="btn btn-outline-secondary" title="Download PDF">
                      <i data-feather="download" class="mr-1"></i> PDF
                  </a>
                  <form method="POST" action="../documents/generate_pdf.php?type=invoice&id=<?php echo $invoiceId; ?>&action=generate" class="d-inline">
                      <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                      <button type="submit" class="btn btn-outline-secondary" title="Regenerate PDF">
                          <i data-feather="refresh-cw" class="mr-1"></i> Regenerate PDF
                      </button>
                  </form>

                  <?php if (in_array($invoice['status'], ['draft', 'sent'])): ?>
                      <form method="POST" class="d-inline">
                          <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                          <button type="submit" name="action" value="send" class="btn btn-<?php echo $invoice['status'] === 'draft' ? 'primary' : 'secondary'; ?>">
                              <i data-feather="send" class="mr-1"></i> <?php echo $invoice['status'] === 'draft' ? 'Send to Customer' : 'Resend'; ?>
                          </button>
                      </form>
                  <?php endif; ?>

                  <?php if (in_array($invoice['status'], ['sent', 'viewed', 'partial', 'overdue'])): ?>
                      <button type="button" class="btn btn-primary" onclick="openStripeModal()">
                          <i data-feather="credit-card" class="mr-1"></i> Pay Online
                      </button>
                      <button type="button" class="btn btn-success" onclick="document.getElementById('paymentModal').style.display='flex'">
                          <i data-feather="check-circle" class="mr-1"></i> Record Payment
                      </button>
                  <?php endif; ?>
              </div>
          </div>

          <div class="mw-content-grid">
              <div>
                  <!-- Customer Info -->
                  <div class="card">
                      <div class="card-header">
                          <h5 class="card-title mb-0">Bill To</h5>
                      </div>
                      <div class="card-body">
                          <?php
                              $contactFullName = trim(($invoice['contact_first'] ?? '') . ' ' . ($invoice['contact_last'] ?? ''));
                              $displayCompany = $invoice['company_name'] ?? '';
                          ?>
                          <?php if ($displayCompany): ?>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Company</span>
                              <span class="mw-detail-value"><?php echo htmlspecialchars($displayCompany); ?></span>
                          </div>
                          <?php endif; ?>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Contact</span>
                              <span class="mw-detail-value">
                                  <?php echo htmlspecialchars($contactFullName ?: 'N/A'); ?>
                              </span>
                          </div>
                          <?php
                              $billAddrParts = array_filter([
                                  $invoice['billing_address'] ?? '',
                                  trim(($invoice['billing_city'] ?? '') . ' ' . ($invoice['billing_province'] ?? '')),
                                  $invoice['billing_postal_code'] ?? '',
                              ]);
                              $billAddrFull = implode(', ', $billAddrParts);
                              $svcAddrFull  = !empty($invoice['property_address'])
                                  ? trim($invoice['property_address'] . ', ' . ($invoice['property_city'] ?? ''))
                                  : '';
                          ?>
                          <?php if ($billAddrFull): ?>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Billing Address</span>
                              <span class="mw-detail-value"><?php echo htmlspecialchars($billAddrFull); ?></span>
                          </div>
                          <?php endif; ?>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Email</span>
                              <span class="mw-detail-value"><?php echo htmlspecialchars($invoice['contact_email'] ?: $invoice['billing_email'] ?: 'N/A'); ?></span>
                          </div>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Phone</span>
                              <span class="mw-detail-value"><?php echo htmlspecialchars($invoice['contact_phone'] ?: $invoice['billing_phone'] ?: 'N/A'); ?></span>
                          </div>
                          <?php if ($svcAddrFull && $svcAddrFull !== $billAddrFull): ?>
                              <div class="mw-detail-row">
                                  <span class="mw-detail-label">Service Address</span>
                                  <span class="mw-detail-value"><?php echo htmlspecialchars($svcAddrFull); ?></span>
                              </div>
                          <?php endif; ?>
                      </div>
                  </div>

                  <!-- Phase 2-3: Invoice Recipients -->
                  <?php if (!empty($invoiceRecipients)): ?>
                      <div class="card">
                          <div class="card-header">
                              <h5 class="card-title mb-0">Invoice Recipients</h5>
                          </div>
                          <div class="card-body p-0">
                              <table class="table table-sm table-bordered mb-0">
                                  <thead class="table-light">
                                      <tr>
                                          <th style="width: 25%;">Contact</th>
                                          <th style="width: 20%;">Role</th>
                                          <th style="width: 30%;">Email</th>
                                          <th style="width: 10%;">SMS</th>
                                          <th style="width: 15%;">Sent</th>
                                      </tr>
                                  </thead>
                                  <tbody>
                                      <?php foreach ($invoiceRecipients as $recipient): ?>
                                          <tr>
                                              <td>
                                                  <?php if (!empty($recipient['first_name'])): ?>
                                                      <?php echo htmlspecialchars($recipient['first_name'] . ' ' . $recipient['last_name']); ?>
                                                  <?php else: ?>
                                                      <span class="text-muted">—</span>
                                                  <?php endif; ?>
                                              </td>
                                              <td>
                                                  <span class="badge badge-light">
                                                      <?php
                                                      $roles = [
                                                          'primary_recipient' => 'Primary',
                                                          'property_manager' => 'Property Manager',
                                                          'owner_contact' => 'Owner',
                                                          'strata_manager' => 'Strata Manager',
                                                          'billing_contact' => 'Billing',
                                                          'accounting' => 'Accounting',
                                                          'cc' => 'CC',
                                                          'bcc' => 'BCC'
                                                      ];
                                                      echo $roles[$recipient['contact_role']] ?? $recipient['contact_role'];
                                                      ?>
                                                  </span>
                                              </td>
                                              <td><small><?php echo htmlspecialchars($recipient['email_address']); ?></small></td>
                                              <td>
                                                  <?php if ($recipient['receive_sms']): ?>
                                                      <span class="text-success">✓</span>
                                                  <?php else: ?>
                                                      <span class="text-muted">—</span>
                                                  <?php endif; ?>
                                              </td>
                                              <td>
                                                  <?php if ($recipient['invoice_sent_at']): ?>
                                                      <small><?php echo formatDateTime($recipient['invoice_sent_at'], 'M j, g:i A'); ?></small>
                                                  <?php else: ?>
                                                      <span class="text-muted">Pending</span>
                                                  <?php endif; ?>
                                              </td>
                                          </tr>
                                      <?php endforeach; ?>
                                  </tbody>
                              </table>
                          </div>
                      </div>
                  <?php endif; ?>

                  <!-- Line Items -->
                  <div class="card">
                      <div class="card-header">
                          <h5 class="card-title mb-0">Services</h5>
                      </div>
                      <div class="card-body">
                          <table class="mw-line-items-table">
                              <thead>
                                  <tr>
                                      <th>Description</th>
                                      <th>Qty</th>
                                      <th class="text-right">Price</th>
                                      <th class="text-right">Total</th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php foreach ($lineItems as $item): ?>
                                      <tr>
                                          <td><?php echo htmlspecialchars($item['description'] ?: 'Services rendered'); ?></td>
                                          <td><?php echo $item['quantity']; ?></td>
                                          <td class="text-right mw-amount"><?php echo formatCurrency($item['unit_price']); ?></td>
                                          <td class="text-right mw-amount"><?php echo formatCurrency($item['line_total']); ?></td>
                                      </tr>
                                  <?php endforeach; ?>
                              </tbody>
                          </table>

                          <div class="mw-totals">
                              <div class="mw-total-row">
                                  <span>Subtotal</span>
                                  <span class="mw-totals-value"><?php echo formatCurrency($invoice['subtotal']); ?></span>
                              </div>
                              <div class="mw-total-row">
                                  <span>GST (<?php echo round(($invoice['tax_rate'] ?: 0.05) * 100); ?>%)</span>
                                  <span class="mw-totals-value"><?php echo formatCurrency($invoice['tax_amount'] ?: 0); ?></span>
                              </div>
                              <div class="mw-total-row mw-grand">
                                  <span>Total</span>
                                  <span class="mw-totals-value"><?php echo formatCurrency($invoice['total']); ?></span>
                              </div>
                              <?php if (floatval($invoice['amount_paid'] ?? 0) > 0): ?>
                                  <div class="mw-total-row">
                                      <span>Paid</span>
                                      <span class="mw-totals-value" style="color: var(--mw-green);">-<?php echo formatCurrency($invoice['amount_paid']); ?></span>
                                  </div>
                                  <div class="mw-total-row mw-grand">
                                      <span>Balance Due</span>
                                      <span class="mw-totals-value" style="color: <?php echo floatval($invoice['balance_due']) > 0 ? '#dc2626' : 'var(--mw-green)'; ?>;">
                                          <?php echo formatCurrency($invoice['balance_due']); ?>
                                      </span>
                                  </div>
                              <?php endif; ?>
                          </div>
                      </div>
                  </div>

                  <!-- Notes -->
                  <?php if (!empty($invoice['notes'])): ?>
                      <div class="card">
                          <div class="card-header">
                              <h5 class="card-title mb-0">Notes</h5>
                          </div>
                          <div class="card-body">
                              <p class="mb-0" style="white-space: pre-line;"><?php echo htmlspecialchars($invoice['notes']); ?></p>
                          </div>
                      </div>
                  <?php endif; ?>

                  <!-- Payment Info -->
                  <?php if ($invoice['status'] === 'paid' && !empty($invoice['paid_at'])): ?>
                      <div class="card">
                          <div class="card-header">
                              <h5 class="card-title mb-0">Payment Information</h5>
                          </div>
                          <div class="card-body">
                              <div class="mw-detail-row">
                                  <span class="mw-detail-label">Payment Date</span>
                                  <span class="mw-detail-value"><?php echo formatDateTime($invoice['paid_at']); ?></span>
                              </div>
                              <?php if (!empty($invoice['payment_method'])): ?>
                                  <div class="mw-detail-row">
                                      <span class="mw-detail-label">Method</span>
                                      <span class="mw-detail-value"><?php echo htmlspecialchars(ucfirst($invoice['payment_method'])); ?></span>
                                  </div>
                              <?php endif; ?>
                              <?php if (!empty($invoice['payment_reference'])): ?>
                                  <div class="mw-detail-row">
                                      <span class="mw-detail-label">Reference</span>
                                      <span class="mw-detail-value"><?php echo htmlspecialchars($invoice['payment_reference']); ?></span>
                                  </div>
                              <?php endif; ?>
                          </div>
                      </div>
                  <?php endif; ?>
              </div>

              <div>
                  <!-- Invoice Details -->
                  <div class="card">
                      <div class="card-header">
                          <h5 class="card-title mb-0">Invoice Details</h5>
                      </div>
                      <div class="card-body">
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Status</span>
                              <span class="mw-detail-value"><?php echo getStatusBadge($invoice['status'], 'invoice'); ?></span>
                          </div>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Issue Date</span>
                              <span class="mw-detail-value"><?php echo formatDate($invoice['issue_date']); ?></span>
                          </div>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Due Date</span>
                              <span class="mw-detail-value"><?php echo formatDate($invoice['due_date']); ?></span>
                          </div>
                          <?php if (!empty($invoice['sent_at'])): ?>
                              <div class="mw-detail-row">
                                  <span class="mw-detail-label">Sent</span>
                                  <span class="mw-detail-value"><?php echo formatDateTime($invoice['sent_at']); ?></span>
                              </div>
                          <?php endif; ?>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Created</span>
                              <span class="mw-detail-value"><?php echo formatDateTime($invoice['created_at']); ?></span>
                          </div>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Created By</span>
                              <span class="mw-detail-value"><?php echo htmlspecialchars($invoice['created_by_name'] ?? 'Unknown'); ?></span>
                          </div>
                          <?php if (!empty($invoice['pdf_version']) && $invoice['pdf_version'] > 0): ?>
                              <div class="mw-detail-row">
                                  <span class="mw-detail-label">PDF Version</span>
                                  <span class="mw-detail-value">
                                      v<?php echo (int)$invoice['pdf_version']; ?>
                                      <?php if (!empty($invoice['pdf_generated_at'])): ?>
                                          <span class="text-muted ml-1">(<?php echo formatDateTime($invoice['pdf_generated_at']); ?>)</span>
                                      <?php endif; ?>
                                  </span>
                              </div>
                          <?php endif; ?>
                          <?php if (!empty($invoice['plan_id'])): ?>
                              <div class="mw-detail-row">
                                  <span class="mw-detail-label">Linked Plan</span>
                                  <span class="mw-detail-value">
                                      <a href="../jobs/view.php?id=<?php echo $invoice['plan_id']; ?>">
                                          <?php echo htmlspecialchars($invoice['plan_number']); ?>
                                          <?php if (!empty($invoice['plan_title'])): ?>
                                              - <?php echo htmlspecialchars($invoice['plan_title']); ?>
                                          <?php endif; ?>
                                      </a>
                                  </span>
                              </div>
                          <?php endif; ?>
                          <?php if (!empty($invoice['visit_id'])): ?>
                              <div class="mw-detail-row">
                                  <span class="mw-detail-label">Linked Visit</span>
                                  <span class="mw-detail-value">
                                      <a href="../jobs/view.php?id=<?php echo $invoice['plan_id']; ?>">
                                          <?php echo htmlspecialchars($invoice['visit_number']); ?>
                                      </a>
                                  </span>
                              </div>
                          <?php endif; ?>
                      </div>
                  </div>

                  <!-- Activity -->
                  <div class="card">
                      <div class="card-header">
                          <h5 class="card-title mb-0">Activity</h5>
                      </div>
                      <div class="card-body">
                          <?php if (empty($activities)): ?>
                              <p class="text-muted mb-0" style="font-size: 14px;">No activity recorded yet.</p>
                          <?php else: ?>
                              <ul class="mw-activity-list">
                                  <?php foreach ($activities as $activity): ?>
                                      <li class="mw-activity-item">
                                          <div><?php echo htmlspecialchars($activity['action']); ?></div>
                                          <div class="mw-activity-time">
                                              <?php echo htmlspecialchars($activity['full_name'] ?? 'System'); ?> -
                                              <?php echo formatDateTime($activity['created_at']); ?>
                                          </div>
                                      </li>
                                  <?php endforeach; ?>
                              </ul>
                          <?php endif; ?>
                      </div>
                  </div>
              </div>
          </div>

          <!-- ═══════════════════════════════════════════════════════════════ -->
          <!-- Stripe Online Payment Modal                                       -->
          <!-- ═══════════════════════════════════════════════════════════════ -->
          <?php if (in_array($invoice['status'], ['sent', 'viewed', 'partial', 'overdue'])): ?>
          <div id="stripeModal" class="mw-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="stripeModalTitle">
              <div class="mw-modal" style="max-width:520px;">
                  <div class="mw-modal-header">
                      <h5 class="mb-0" id="stripeModalTitle">
                          <i data-feather="credit-card" style="width:18px;height:18px;vertical-align:middle;margin-right:6px;"></i>
                          Pay Invoice <?php echo h($invoice['invoice_number']); ?>
                      </h5>
                      <button type="button" class="mw-modal-close" onclick="closeStripeModal()" aria-label="Close">&times;</button>
                  </div>
                  <div class="mw-modal-body">

                      <!-- Amount summary -->
                      <div style="background:var(--mw-light);border-radius:6px;padding:12px 16px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;">
                          <span style="color:var(--mw-forest);font-size:14px;">Amount Due</span>
                          <strong style="color:var(--mw-forest);font-size:22px;"><?php echo h(formatCurrency($invoice['balance_due'])); ?> CAD</strong>
                      </div>

                      <!-- Loading state -->
                      <div id="stripeLoading" style="text-align:center;padding:40px 0;color:var(--mw-forest);">
                          <div class="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></div>
                          Loading payment form…
                      </div>

                      <!-- Stripe Payment Element mounts here -->
                      <div id="payment-element" style="display:none;"></div>

                      <!-- Error display -->
                      <div id="stripeError" class="alert alert-danger mt-3" style="display:none;" role="alert"></div>

                  </div>
                  <div class="mw-modal-footer" id="stripeFooter" style="display:none;">
                      <button type="button" class="btn btn-secondary" onclick="closeStripeModal()">Cancel</button>
                      <button id="stripePay" class="btn btn-primary" onclick="submitStripePayment()" disabled>
                          <span id="stripePayLabel">Pay <?php echo h(formatCurrency($invoice['balance_due'])); ?></span>
                          <span id="stripePaySpinner" style="display:none;">
                              <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                              Processing…
                          </span>
                      </button>
                  </div>
              </div>
          </div>
          <?php endif; ?>

          <!-- ═══════════════════════════════════════════════════════════════ -->
          <!-- Manual Record Payment Modal                                       -->
          <!-- ═══════════════════════════════════════════════════════════════ -->
          <div id="paymentModal" class="mw-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="paymentModalTitle">
              <div class="mw-modal mw-payment-modal-content">
                  <div class="mw-modal-header">
                      <h5 class="mb-0" id="paymentModalTitle">
                          <i data-feather="check-circle" style="width:18px;height:18px;vertical-align:middle;margin-right:6px;"></i>
                          Record Payment
                      </h5>
                      <button type="button" class="mw-modal-close" onclick="document.getElementById('paymentModal').style.display='none'" aria-label="Close">&times;</button>
                  </div>
                  <form id="paymentForm" method="POST">
                      <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                      <input type="hidden" name="action" value="mark_paid">
                      <div class="mw-modal-body">

                          <!-- Invoice summary -->
                          <div class="mw-payment-invoice-list">
                              <div class="mw-payment-inv-row">
                                  <div class="mw-payment-inv-info">
                                      <span class="mw-payment-inv-number"><?php echo h($invoice['invoice_number']); ?></span>
                                      <span class="mw-payment-inv-client">
                                          <?php echo h(trim(($invoice['company_name'] ?: '') . ' ' . ($invoice['contact_first'] ?? '') . ' ' . ($invoice['contact_last'] ?? ''))); ?>
                                      </span>
                                  </div>
                                  <div class="mw-payment-inv-amount"><?php echo h(formatCurrency($invoice['balance_due'])); ?></div>
                              </div>
                          </div>
                          <div class="mw-payment-total-row">
                              <span>Balance Due</span>
                              <strong><?php echo h(formatCurrency($invoice['balance_due'])); ?></strong>
                          </div>

                          <div class="mw-form-row" style="margin-top:16px;">
                              <div class="mw-form-group">
                                  <label class="form-label" for="vm-pay-amount">Amount</label>
                                  <div class="input-group">
                                      <div class="input-group-prepend">
                                          <span class="input-group-text">$</span>
                                      </div>
                                      <input type="number" id="vm-pay-amount" name="payment_amount" class="form-control"
                                             step="0.01" min="0.01"
                                             value="<?php echo number_format(floatval($invoice['balance_due']), 2, '.', ''); ?>">
                                  </div>
                              </div>
                              <div class="mw-form-group">
                                  <label class="form-label" for="vm-pay-date">Payment Date</label>
                                  <input type="date" id="vm-pay-date" name="payment_date" class="form-control"
                                         value="<?php echo date('Y-m-d'); ?>">
                              </div>
                          </div>

                          <div class="mw-form-group">
                              <label class="form-label" for="vm-pay-method">Payment Method</label>
                              <select id="vm-pay-method" name="payment_method" class="form-control" onchange="vmToggleRef()">
                                  <option value="e_transfer">e-Transfer</option>
                                  <option value="cash">Cash</option>
                                  <option value="cheque">Cheque</option>
                                  <option value="credit_card">Credit Card</option>
                                  <option value="other">Other</option>
                              </select>
                          </div>

                          <div class="mw-form-group">
                              <label class="form-label" id="vm-ref-label" for="vm-pay-ref">e-Transfer Confirmation #</label>
                              <input type="text" id="vm-pay-ref" name="payment_reference" class="form-control"
                                     placeholder="e.g., 123456789">
                          </div>

                      </div>
                      <div class="mw-modal-footer">
                          <button type="button" class="btn btn-secondary" onclick="document.getElementById('paymentModal').style.display='none'">Cancel</button>
                          <button type="submit" class="btn btn-success">
                              <i data-feather="check" style="width:14px;height:14px;margin-right:4px;vertical-align:middle;"></i>
                              Record Payment
                          </button>
                      </div>
                  </form>
              </div>
          </div>
          <script>
          function vmToggleRef() {
              var m = document.getElementById('vm-pay-method').value;
              var l = document.getElementById('vm-ref-label');
              var i = document.getElementById('vm-pay-ref');
              var labels = {e_transfer:'e-Transfer Confirmation #', cheque:'Cheque Number', cash:'Receipt / Notes', credit_card:'Authorization Code', other:'Reference / Notes'};
              var phs    = {e_transfer:'e.g., 123456789', cheque:'e.g., #1042', cash:'e.g., cash received', credit_card:'e.g., auth code', other:''};
              l.textContent = labels[m] || 'Reference';
              i.placeholder = phs[m] || '';
          }
          // Close on overlay click
          document.getElementById('paymentModal').addEventListener('click', function(e){ if(e.target===this) this.style.display='none'; });
          </script>

<?php if ($isPayable): ?>
<script>
(function () {
    'use strict';

    // ── State ──────────────────────────────────────────────────────────────────
    var stripe        = null;
    var elements      = null;
    var paymentEl     = null;
    var intentFetched = false;

    // ── Open modal ─────────────────────────────────────────────────────────────
    window.openStripeModal = function () {
        document.getElementById('stripeModal').style.display = 'flex';
        if (!intentFetched) {
            initStripe();
        }
    };

    // ── Close modal ────────────────────────────────────────────────────────────
    window.closeStripeModal = function () {
        document.getElementById('stripeModal').style.display = 'none';
        clearStripeError();
    };

    // Close on overlay click
    document.getElementById('stripeModal').addEventListener('click', function (e) {
        if (e.target === this) { window.closeStripeModal(); }
    });

    // ── Initialise Stripe + Payment Element ────────────────────────────────────
    function initStripe() {
        fetch('/crm/api/stripe/create-payment-intent.php', {
            method  : 'POST',
            headers : { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body    : JSON.stringify({ invoice_id: <?php echo (int) $invoiceId; ?> })
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.error) {
                showStripeError(data.error);
                document.getElementById('stripeLoading').style.display = 'none';
                return;
            }

            intentFetched = true;
            stripe        = Stripe(data.publishable_key);

            elements = stripe.elements({
                clientSecret : data.client_secret,
                appearance   : {
                    theme     : 'stripe',
                    variables : {
                        colorPrimary      : '#2D8659',
                        colorBackground   : '#ffffff',
                        colorText         : '#1A5F4A',
                        borderRadius      : '6px',
                        fontFamily        : 'system-ui, -apple-system, sans-serif',
                    }
                }
            });

            paymentEl = elements.create('payment', { layout: 'tabs' });
            paymentEl.mount('#payment-element');

            paymentEl.on('ready', function () {
                document.getElementById('stripeLoading').style.display  = 'none';
                document.getElementById('payment-element').style.display = 'block';
                document.getElementById('stripeFooter').style.display    = 'flex';
                document.getElementById('stripePay').disabled            = false;
            });

            paymentEl.on('change', function (event) {
                if (event.error) {
                    showStripeError(event.error.message);
                } else {
                    clearStripeError();
                }
            });
        })
        .catch(function (err) {
            console.error('[Stripe] initStripe error:', err);
            showStripeError('Unable to load payment form. Please refresh and try again.');
            document.getElementById('stripeLoading').style.display = 'none';
        });
    }

    // ── Submit payment ─────────────────────────────────────────────────────────
    window.submitStripePayment = function () {
        if (!stripe || !elements) { return; }

        clearStripeError();
        setLoading(true);

        stripe.confirmPayment({
            elements    : elements,
            confirmParams: {
                // Return URL after 3DS or redirect-based methods
                return_url: window.location.origin + window.location.pathname
                            + '?id=<?php echo (int) $invoiceId; ?>&payment=success',
            },
            // Don't redirect if not required (card payments usually don't need it)
            redirect: 'if_required',
        })
        .then(function (result) {
            setLoading(false);
            if (result.error) {
                // Show error to customer
                showStripeError(result.error.message);
            } else if (result.paymentIntent && result.paymentIntent.status === 'succeeded') {
                // Payment confirmed client-side — webhook will update DB.
                // Show success and reload after a short delay.
                showStripeSuccess();
            }
        })
        .catch(function (err) {
            setLoading(false);
            console.error('[Stripe] confirmPayment error:', err);
            showStripeError('An unexpected error occurred. Please try again.');
        });
    };

    // ── Helpers ────────────────────────────────────────────────────────────────
    function showStripeError(msg) {
        var el = document.getElementById('stripeError');
        el.textContent = msg;
        el.style.display = 'block';
    }
    function clearStripeError() {
        var el = document.getElementById('stripeError');
        el.textContent  = '';
        el.style.display = 'none';
    }
    function setLoading(loading) {
        var btn     = document.getElementById('stripePay');
        var label   = document.getElementById('stripePayLabel');
        var spinner = document.getElementById('stripePaySpinner');
        btn.disabled       = loading;
        label.style.display  = loading ? 'none'  : 'inline';
        spinner.style.display = loading ? 'inline' : 'none';
    }
    function showStripeSuccess() {
        var modal = document.querySelector('#stripeModal .mw-modal');
        modal.innerHTML = [
            '<div class="mw-modal-body" style="text-align:center;padding:40px 20px;">',
            '<div style="color:var(--mw-green);font-size:56px;line-height:1;">&#10003;</div>',
            '<h4 style="color:var(--mw-forest);margin-top:16px;">Payment Successful!</h4>',
            '<p style="color:#555;">Your payment has been received. This invoice will be marked paid shortly.</p>',
            '<p style="color:#888;font-size:13px;">Refreshing in 3 seconds…</p>',
            '</div>'
        ].join('');
        setTimeout(function () {
            window.location.href = '?id=<?php echo (int) $invoiceId; ?>&payment=success';
        }, 3000);
    }

    // ── Show payment success message if returning from redirect ───────────────
    <?php if (isset($_GET['payment']) && $_GET['payment'] === 'success'): ?>
    (function () {
        // Small delay to let the page render first
        setTimeout(function () {
            var msgEl = document.querySelector('.mw-message');
            if (!msgEl) {
                var container = document.querySelector('.container-fluid');
                if (container) {
                    var div = document.createElement('div');
                    div.className = 'mw-message success';
                    div.textContent = 'Payment submitted successfully! The invoice will be marked paid once confirmed by Stripe.';
                    container.insertBefore(div, container.firstChild);
                }
            }
        }, 200);
    }());
    <?php endif; ?>

}());
</script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
