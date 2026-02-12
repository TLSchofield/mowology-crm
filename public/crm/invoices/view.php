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
        c.billing_email,
        c.billing_phone,
        c.billing_address,
        c.billing_city,
        c.billing_province,
        c.billing_postal_code,
        ct.first_name as contact_first,
        ct.last_name as contact_last,
        ct.email as contact_email,
        ct.phone as contact_phone,
        p.address as property_address,
        p.city as property_city,
        p.postal_code as property_postal,
        j.job_number,
        j.title as job_title,
        jv.visit_number,
        jp.plan_number,
        jp.title as plan_title,
        u.full_name as created_by_name
    FROM invoices i
    LEFT JOIN companies c ON i.company_id = c.id
    LEFT JOIN contacts ct ON c.primary_contact_id = ct.id
    LEFT JOIN properties p ON i.property_id = p.id
    LEFT JOIN jobs j ON i.job_id = j.id
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

        // Send to each recipient
        $sentTo = [];
        $smsRecipients = [];

        foreach ($recipients as $recipient) {
            if (empty($recipient['email_address'])) {
                continue;
            }

            $recipientName = !empty($recipient['first_name'])
                ? "{$recipient['first_name']} {$recipient['last_name']}"
                : $invoice['company_name'];

            $emailSubject = "Invoice {$invoice['invoice_number']} from Mowology";
            $emailBody = "
                <h2>Invoice from Mowology</h2>
                <p>Hi " . htmlspecialchars($recipientName) . ",</p>
                <p>Please find your invoice details below:</p>
                <p><strong>Invoice Number:</strong> {$invoice['invoice_number']}<br>
                <strong>Amount Due:</strong> " . formatCurrency($invoice['balance_due']) . "<br>
                <strong>Due Date:</strong> " . formatDate($invoice['due_date']) . "</p>
                <p>If you have any questions about this invoice, please contact us at (778) 846-9273.</p>
                <p>Thank you for your business!<br>The Mowology Team</p>
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

                // Track SMS recipients (those who have consent)
                if ($recipient['receive_sms']) {
                    $smsRecipients[] = [
                        'name' => $recipientName,
                        'role' => $recipient['contact_role']
                    ];
                }
            } else {
                error_log("Email send failed for invoice {$invoiceId} to {$recipient['email_address']}");
            }
        }

        // Build activity log message
        $attachNote = $attachPath ? ' (with PDF attached)' : '';
        if (!empty($sentTo)) {
            $recipientList = implode(', ', $sentTo);
            $details = "Invoice sent to {$recipientList}{$attachNote}";
            if (!empty($smsRecipients)) {
                $details .= " (SMS pending for: " . implode(', ', array_map(fn($r) => $r['name'], $smsRecipients)) . ")";
            }
            logActivityExtended($user['id'], 'Invoice sent', $details, null, null, null, $invoiceId);

            $invoice['status'] = 'sent';
            $message = "Invoice sent successfully to " . count($sentTo) . " recipient(s)";
            if (!empty($smsRecipients)) {
                $message .= " and SMS pending for " . count($smsRecipients) . " contact(s)";
            }
            $messageType = 'success';
        } else {
            $message = 'No valid recipients found. Please add recipients to this invoice first.';
            $messageType = 'warning';
        }
    }

    if ($action === 'mark_paid') {
        $paymentMethod = trim($_POST['payment_method'] ?? 'other');
        $paymentReference = trim($_POST['payment_reference'] ?? '');
        $paymentAmount = floatval($_POST['payment_amount'] ?? $invoice['balance_due']);

        $stmt = $db->prepare("
            UPDATE invoices
            SET status = 'paid',
                amount_paid = amount_paid + ?,
                balance_due = balance_due - ?,
                payment_date = NOW(),
                payment_method = ?,
                payment_reference = ?
            WHERE id = ?
        ");
        $stmt->execute([$paymentAmount, $paymentAmount, $paymentMethod, $paymentReference, $invoiceId]);

        logActivityExtended($user['id'], 'Payment recorded', "Payment of " . formatCurrency($paymentAmount) . " recorded ({$paymentMethod})", null, null, null, $invoiceId);

        $invoice['status'] = 'paid';
        $message = "Payment of " . formatCurrency($paymentAmount) . " recorded successfully.";
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
                      <?php elseif (!empty($invoice['job_number'])): ?>
                          <span class="ml-2 text-muted">
                              Job: <a href="../jobs/view.php?id=<?php echo $invoice['job_id']; ?>"><?php echo htmlspecialchars($invoice['job_number']); ?></a>
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
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Company</span>
                              <span class="mw-detail-value"><?php echo htmlspecialchars($invoice['company_name'] ?? 'N/A'); ?></span>
                          </div>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Contact</span>
                              <span class="mw-detail-value">
                                  <?php echo htmlspecialchars(trim(($invoice['contact_first'] ?? '') . ' ' . ($invoice['contact_last'] ?? '')) ?: 'N/A'); ?>
                              </span>
                          </div>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Email</span>
                              <span class="mw-detail-value"><?php echo htmlspecialchars($invoice['contact_email'] ?: $invoice['billing_email'] ?: 'N/A'); ?></span>
                          </div>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Phone</span>
                              <span class="mw-detail-value"><?php echo htmlspecialchars($invoice['contact_phone'] ?: $invoice['billing_phone'] ?: 'N/A'); ?></span>
                          </div>
                          <?php if (!empty($invoice['property_address'])): ?>
                              <div class="mw-detail-row">
                                  <span class="mw-detail-label">Service Location</span>
                                  <span class="mw-detail-value">
                                      <?php echo htmlspecialchars($invoice['property_address'] . ', ' . $invoice['property_city']); ?>
                                  </span>
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
                                                      <small><?php echo formatDate($recipient['invoice_sent_at'], 'short'); ?></small>
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
                  <?php if ($invoice['status'] === 'paid' && !empty($invoice['payment_date'])): ?>
                      <div class="card">
                          <div class="card-header">
                              <h5 class="card-title mb-0">Payment Information</h5>
                          </div>
                          <div class="card-body">
                              <div class="mw-detail-row">
                                  <span class="mw-detail-label">Payment Date</span>
                                  <span class="mw-detail-value"><?php echo formatDateTime($invoice['payment_date']); ?></span>
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
                          <?php if (!empty($invoice['job_id']) && empty($invoice['plan_id'])): ?>
                              <div class="mw-detail-row">
                                  <span class="mw-detail-label">Linked Job</span>
                                  <span class="mw-detail-value">
                                      <a href="../jobs/view.php?id=<?php echo $invoice['job_id']; ?>">
                                          <?php echo htmlspecialchars($invoice['job_number']); ?>
                                          <?php if (!empty($invoice['job_title'])): ?>
                                              - <?php echo htmlspecialchars($invoice['job_title']); ?>
                                          <?php endif; ?>
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

          <!-- Payment Modal -->
          <div id="paymentModal" class="mw-modal-overlay" style="display: none;">
              <div class="mw-modal">
                  <div class="mw-modal-header">
                      <h5 class="mb-0">Record Payment</h5>
                      <button type="button" class="mw-modal-close" onclick="document.getElementById('paymentModal').style.display='none'">&times;</button>
                  </div>
                  <form method="POST">
                      <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                      <input type="hidden" name="action" value="mark_paid">
                      <div class="mw-modal-body">
                          <div class="mw-form-group">
                              <label class="form-label">Amount</label>
                              <input type="number" name="payment_amount" class="form-control" step="any" min="0"
                                     value="<?php echo number_format(floatval($invoice['balance_due']), 2, '.', ''); ?>">
                          </div>
                          <div class="mw-form-group">
                              <label class="form-label">Payment Method</label>
                              <select name="payment_method" class="form-control">
                                  <option value="etransfer">e-Transfer</option>
                                  <option value="cash">Cash</option>
                                  <option value="cheque">Cheque</option>
                                  <option value="credit_card">Credit Card</option>
                                  <option value="other">Other</option>
                              </select>
                          </div>
                          <div class="mw-form-group">
                              <label class="form-label">Reference / Notes</label>
                              <input type="text" name="payment_reference" class="form-control" placeholder="e.g., e-Transfer confirmation #">
                          </div>
                      </div>
                      <div class="mw-modal-footer">
                          <button type="button" class="btn btn-secondary" onclick="document.getElementById('paymentModal').style.display='none'">Cancel</button>
                          <button type="submit" class="btn btn-success">Record Payment</button>
                      </div>
                  </form>
              </div>
          </div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
