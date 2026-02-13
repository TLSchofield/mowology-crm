<?php
/**
 * Quote View - Internal CRM View
 * AppStack layout via shared includes.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/plan-functions.php';
require_once dirname(__DIR__) . '/includes/messaging.php';
require_once dirname(__DIR__) . '/includes/roi-functions.php';
// Note: pdf_bootstrap.php and PdfGenerator.php are loaded lazily below only when PDF generation is needed

requireLogin();
$user = getCurrentUser();

$quoteId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$quoteId) {
    header('Location: index.php');
    exit;
}

$db = getDB();

// Get quote with related data - also check quote_requests for original contact info
$stmt = $db->prepare("
    SELECT
        q.*,
        p.address as property_address,
        p.city as property_city,
        p.postal_code as property_postal,
        p.property_type,
        c.company_name,
        c.billing_email,
        c.billing_phone,
        ct.id as contact_id,
        ct.first_name as contact_first,
        ct.last_name as contact_last,
        ct.email as contact_email,
        ct.phone as contact_phone,
        qr.contact_id as qr_contact_id,
        qrc.first_name as qr_first_name,
        qrc.last_name as qr_last_name,
        qrc.email as qr_email,
        qrc.phone as qr_phone,
        u.full_name as created_by_name
    FROM quotes q
    LEFT JOIN properties p ON q.property_id = p.id
    LEFT JOIN company_properties cp ON p.id = cp.property_id AND cp.is_primary = 1
    LEFT JOIN companies c ON q.company_id = c.id
    LEFT JOIN contacts ct ON c.primary_contact_id = ct.id
    LEFT JOIN quote_requests qr ON qr.quote_id = q.id
    LEFT JOIN contacts qrc ON qr.contact_id = qrc.id
    LEFT JOIN users u ON q.created_by = u.id
    WHERE q.id = ?
");
$stmt->execute([$quoteId]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quote) {
    header('Location: index.php');
    exit;
}

// Get line items
$stmt = $db->prepare("SELECT * FROM quote_line_items WHERE quote_id = ? ORDER BY sort_order");
$stmt->execute([$quoteId]);
$lineItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get activity for this quote
// Note: activity_log.quote_id column may not exist in older databases
$activities = [];
try {
    $stmt = $db->prepare("
        SELECT a.*, u.full_name
        FROM activity_log a
        LEFT JOIN users u ON a.user_id = u.id
        WHERE a.quote_id = ?
        ORDER BY a.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$quoteId]);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // activity_log table doesn't have quote_id column in this database version
    error_log("Activity log query failed - quote_id column may not exist: " . $e->getMessage());
    $activities = [];
}

// Get notes for this quote
$quoteNotes = getQuoteNotes($quoteId);

// Handle actions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'send') {
        // Resolve customer contact info (quote request contact → company contact → billing)
        $customerEmail = $quote['qr_email'] ?? $quote['contact_email'] ?? $quote['billing_email'] ?? null;
        $customerPhone = $quote['qr_phone'] ?? $quote['contact_phone'] ?? $quote['billing_phone'] ?? null;
        $customerConsentsToSms = false;

        // Check SMS consent via contacts table (quote request contact → company primary contact)
        if (!empty($quote['qr_contact_id'])) {
            $customerConsentsToSms = hasSmConsent((int)$quote['qr_contact_id']);
        } elseif (!empty($quote['contact_id'])) {
            $customerConsentsToSms = hasSmConsent((int)$quote['contact_id']);
        }

        if (!$customerEmail && !$customerPhone) {
            $message = 'Error: No contact information found (no email or phone). Please add contact details first.';
            $messageType = 'error';
        } else {
            $emailSent = false;
            $smsSent = false;
            $sentVia = [];
            $attachPath = null;

            // Generate access token if not exists
            if (empty($quote['access_token'])) {
                $accessToken = generateAccessToken();
                $stmt = $db->prepare("UPDATE quotes SET access_token = ?, token_expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = ?");
                $stmt->execute([$accessToken, $quoteId]);
                $quote['access_token'] = $accessToken;
            }

            // Build customer name and quote URL
            $customerName = trim(($quote['qr_first_name'] ?? $quote['contact_first'] ?? '') . ' ' . ($quote['qr_last_name'] ?? $quote['contact_last'] ?? '')) ?: ($quote['company_name'] ?? 'Valued Customer');
            $quoteUrl = "https://" . $_SERVER['HTTP_HOST'] . "/customer/quote.php?token=" . $quote['access_token'];

            // --- SEND EMAIL ---
            if ($customerEmail) {
                $emailSubject = "Quote {$quote['quote_number']} from Mowology";
                $emailBody = "
                    <h2>Your Quote is Ready</h2>
                    <p>Hi " . htmlspecialchars($customerName ?: 'Valued Customer') . ",</p>
                    <p>Thank you for your interest in Mowology's services. Please find your quote details below:</p>
                    <p><strong>Quote Number:</strong> {$quote['quote_number']}<br>
                    <strong>Amount:</strong> " . formatCurrency($quote['amount']) . "<br>
                    <strong>Valid Until:</strong> " . formatDate($quote['valid_until']) . "</p>
                    <p><a href='{$quoteUrl}' style='display: inline-block; padding: 14px 28px; background: #2D8659; color: white; text-decoration: none; border-radius: 8px; font-weight: bold;'>View & Accept Quote</a></p>
                    <p>If you have any questions, please don't hesitate to contact us at (778) 846-9273.</p>
                    <p>Thank you,<br>The Mowology Team</p>
                ";

                $emailResult = sendEmail($customerEmail, $emailSubject, $emailBody);

                if ($emailResult['success']) {
                    $emailSent = true;
                    $sentVia[] = 'email';

                    // Generate PDF for future resends (non-critical)
                    try {
                        require_once dirname(__DIR__) . '/includes/pdf_bootstrap.php';
                        require_once dirname(__DIR__) . '/includes/PdfGenerator.php';

                        $pdfGen = new PdfGenerator();
                        $existingPath = $pdfGen->getPdfPath('quote', $quoteId);
                        if ($existingPath) {
                            $attachPath = $existingPath;
                        } else {
                            $pdfResult = $pdfGen->generateQuotePdf($quoteId);
                            if ($pdfResult['success']) {
                                $attachPath = $pdfResult['path'];
                            }
                        }
                    } catch (Exception $pdfEx) {
                        error_log("PDF generation error (non-critical): " . $pdfEx->getMessage());
                    }
                } else {
                    error_log("Email failed for quote {$quoteId}: " . ($emailResult['error'] ?? 'unknown'));
                }
            }

            // --- SEND SMS (if consented) ---
            if ($customerPhone && $customerConsentsToSms) {
                $smsMessage = "Your quote #{$quote['quote_number']} from Mowology is ready. Check your inbox or junk folder for details. Reply STOP to opt out.";
                $smsResult = sendSms($customerPhone, $smsMessage);

                if ($smsResult['success']) {
                    $smsSent = true;
                    $sentVia[] = 'SMS (' . ($smsResult['carrier'] ?? 'unknown') . ')';
                }
            }

            // --- UPDATE QUOTE STATUS ---
            if ($emailSent || $smsSent) {
                $stmt = $db->prepare("UPDATE quotes SET status = 'sent', sent_at = NOW(), sent_via = ? WHERE id = ?");
                $stmt->execute([implode(',', $sentVia), $quoteId]);

                logQuoteSentEvent($quoteId);

                $activityDetails = "Quote sent via " . implode(' + ', $sentVia);
                if ($customerEmail) {
                    $activityDetails .= " (email: {$customerEmail})";
                }
                if ($customerPhone && $customerConsentsToSms) {
                    $activityDetails .= " (SMS: {$customerPhone})";
                }
                if ($attachPath) {
                    $activityDetails .= " with PDF attached";
                }

                logActivityExtended($user['id'], 'Quote sent', $activityDetails, null, null, $quoteId);

                $quote['status'] = 'sent';
                $sentViaText = implode(' & ', $sentVia);
                $message = "Quote sent successfully via {$sentViaText}";
                $messageType = 'success';
            } else {
                $message = 'Error sending quote. Please check that the email address is valid and try again.';
                $messageType = 'error';
                error_log("Quote send failed for quote {$quoteId}");
            }
        }
    }

    if ($action === 'approve_verbal') {
        // Admin approves quote on behalf of customer (verbal confirmation)
        $approverName = trim($_POST['approver_name'] ?? '');
        if (empty($approverName)) {
            $approverName = trim(($quote['qr_first_name'] ?? $quote['contact_first'] ?? '') . ' ' . ($quote['qr_last_name'] ?? $quote['contact_last'] ?? ''));
        }
        if (empty($approverName)) {
            $approverName = 'Customer (verbal)';
        }

        try {
            $stmt = $db->prepare("
                UPDATE quotes SET
                    status = 'accepted',
                    accepted_at = NOW(),
                    accepted_by_name = ?,
                    accepted_ip_address = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $approverName . ' (verbal approval by ' . $user['full_name'] . ')',
                $_SERVER['REMOTE_ADDR'],
                $quoteId
            ]);

            logActivityExtended($user['id'], 'Quote approved (verbal)', "Approved on behalf of {$approverName} by {$user['full_name']}", null, null, $quoteId);

            $quote['status'] = 'accepted';
            $message = "Quote approved (verbal confirmation from {$approverName}).";
            $messageType = 'success';
        } catch (Exception $e) {
            error_log("Quote verbal approval error: " . $e->getMessage());
            $message = 'Error approving quote. Please try again.';
            $messageType = 'error';
        }
    }

    if ($action === 'update_contact') {
        // Update contact details from quote view (AJAX or form)
        $contactId = intval($_POST['contact_id'] ?? 0);
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if (!$contactId) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'No contact linked to this quote.']); exit; }
            $message = 'No contact linked to this quote.';
            $messageType = 'error';
        } elseif (empty($firstName)) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'First name is required.']); exit; }
            $message = 'First name is required.';
            $messageType = 'error';
        } else {
            try {
                $stmt = $db->prepare("
                    UPDATE contacts SET
                        first_name = ?,
                        last_name = ?,
                        email = ?,
                        phone = ?
                    WHERE id = ?
                ");
                $stmt->execute([$firstName, $lastName, $email, $phone, $contactId]);

                logActivityExtended($user['id'], 'Contact updated', "Updated contact #{$contactId}: {$firstName} {$lastName}", null, null, $quoteId);

                // If this is an AJAX request, return JSON
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Contact updated.']);
                    exit;
                }

                // Otherwise redirect back
                header("Location: view.php?id={$quoteId}&saved=1");
                exit;
            } catch (Exception $e) {
                error_log("Contact update error: " . $e->getMessage());
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'error' => 'Database error.']);
                    exit;
                }
                $message = 'Error updating contact.';
                $messageType = 'error';
            }
        }
    }

    if ($action === 'convert_to_job') {
        $result = createPlanFromQuote($quoteId, (int)$user['id']);
        if ($result['success']) {
            header("Location: ../jobs/view.php?id={$result['plan_id']}&created=1");
            exit;
        } else {
            $message = "Error creating plan: " . implode(' ', $result['errors']);
            $messageType = 'error';
        }
    }
}

$csrfToken = generateCSRFToken();

// Check for saved message
if (isset($_GET['saved'])) {
    $message = 'Quote saved successfully!';
    $messageType = 'success';
}
if (isset($_GET['pdf_generated'])) {
    $message = 'PDF generated successfully.';
    $messageType = 'success';
}

$pageTitle = 'Quote ' . htmlspecialchars($quote['quote_number']);
$activePage = 'quotes';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

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

          <a href="index.php" class="mw-back-link">&larr; Back to Quotes</a>

          <!-- PRODUCTION DEBUG PANELS: Auto-collapse unless there are issues -->
          <?php
              // Determine if we should show debug panels
              $showDebug = isset($_GET['debug']) || isset($_GET['_debug']);

              // Show if this was a POST request
              $wasPostRequest = $_SERVER['REQUEST_METHOD'] === 'POST';
          ?>

          <!-- POST Request Status (shown if POST was received) -->
          <?php if ($wasPostRequest): ?>
          <div class="alert alert-info">
              <strong>ℹ️ Form Submission Received:</strong> Your "Send to Customer" request was received by the server.
              Check the Contact Information panel above for any data issues, or check server logs for detailed send attempt logs.
          </div>
          <?php endif; ?>

          <!-- PRODUCTION DEBUG PANELS: Auto-collapse unless there are issues -->
          <?php
              // Determine if we should show debug panels (resumed below)
              $showDebug = isset($_GET['debug']) || isset($_GET['_debug']);

              // Check for issues that warrant showing debug info
              $debugEmail = $quote['qr_email'] ?? $quote['contact_email'] ?? $quote['billing_email'] ?? null;
              $debugPhone = $quote['qr_phone'] ?? $quote['contact_phone'] ?? $quote['billing_phone'] ?? null;
              $hasEmailIssue = empty($debugEmail);
              $hasPhoneIssue = empty($debugPhone);
              $hasContactId = !empty($quote['qr_contact_id']);
              $hasAccessToken = !empty($quote['access_token']);

              // Auto-expand debug if there are issues
              $autoExpandDebug = $hasEmailIssue || ($hasPhoneIssue && $quote['status'] === 'draft');
          ?>

          <!-- Contact Info Debug Panel -->
          <div class="card" style="border-color: <?php echo $hasEmailIssue ? '#dc3545' : '#28a745'; ?>;">
              <div class="card-header" style="background-color: <?php echo $hasEmailIssue ? '#f8d7da' : '#d4edda'; ?>; cursor: pointer;" onclick="toggleDebugPanel(this, 'contact-debug')">
                  <h5 style="margin: 0; color: <?php echo $hasEmailIssue ? '#721c24' : '#155724'; ?>;">
                      <span class="debug-toggle-icon" style="display: inline-block; width: 20px;">▼</span>
                      📧 Contact Information
                      <?php if ($hasEmailIssue): ?>
                          <span class="badge badge-danger ml-2">No Email!</span>
                      <?php elseif (!$hasPhoneIssue): ?>
                          <span class="badge badge-success ml-2">✓ Complete</span>
                      <?php else: ?>
                          <span class="badge badge-warning ml-2">Missing Phone</span>
                      <?php endif; ?>
                  </h5>
              </div>
              <div id="contact-debug" class="card-body" style="display: <?php echo ($autoExpandDebug || $showDebug) ? 'block' : 'none'; ?>; font-size: 13px;">
                  <table style="width: 100%;">
                      <tr>
                          <td><strong>Source → Email:</strong></td>
                          <td>
                              qr_email: <?php echo htmlspecialchars($quote['qr_email'] ?? '-'); ?><br>
                              contact_email: <?php echo htmlspecialchars($quote['contact_email'] ?? '-'); ?><br>
                              billing_email: <?php echo htmlspecialchars($quote['billing_email'] ?? '-'); ?>
                          </td>
                      </tr>
                      <tr style="background: #f9f9f9;">
                          <td><strong>→ FINAL EMAIL:</strong></td>
                          <td style="color: <?php echo $debugEmail ? '#28a745' : '#dc3545'; ?>; font-weight: bold;">
                              <?php echo $debugEmail ? htmlspecialchars($debugEmail) : '❌ NO EMAIL (SEND WILL FAIL)'; ?>
                          </td>
                      </tr>
                      <tr>
                          <td><strong>Source → Phone:</strong></td>
                          <td>
                              qr_phone: <?php echo htmlspecialchars($quote['qr_phone'] ?? '-'); ?><br>
                              contact_phone: <?php echo htmlspecialchars($quote['contact_phone'] ?? '-'); ?><br>
                              billing_phone: <?php echo htmlspecialchars($quote['billing_phone'] ?? '-'); ?>
                          </td>
                      </tr>
                      <tr style="background: #f9f9f9;">
                          <td><strong>→ FINAL PHONE:</strong></td>
                          <td style="color: <?php echo $debugPhone ? '#28a745' : '#ffc107'; ?>; font-weight: bold;">
                              <?php echo $debugPhone ? htmlspecialchars($debugPhone) : '⚠️ NO PHONE (SMS WILL NOT SEND)'; ?>
                          </td>
                      </tr>
                  </table>
              </div>
          </div>

          <!-- SMS Consent Debug Panel -->
          <div class="card mt-3" style="border-color: #17a2b8;">
              <div class="card-header" style="background-color: #d1ecf1; cursor: pointer;" onclick="toggleDebugPanel(this, 'sms-debug')">
                  <h5 style="margin: 0; color: #0c5460;">
                      <span class="debug-toggle-icon" style="display: inline-block; width: 20px;">▶</span>
                      📱 SMS Consent
                  </h5>
              </div>
              <div id="sms-debug" class="card-body" style="display: none; font-size: 13px;">
                  <?php
                      // Determine which contact ID is used for SMS consent (same fallback as send logic)
                      $smsContactId = !empty($quote['qr_contact_id']) ? $quote['qr_contact_id'] : ($quote['contact_id'] ?? null);
                      $smsContactSource = !empty($quote['qr_contact_id']) ? 'quote request' : (!empty($quote['contact_id']) ? 'primary contact' : 'none');
                  ?>
                  <table style="width: 100%;">
                      <tr>
                          <td><strong>Contact ID:</strong></td>
                          <td><?php echo $smsContactId ? htmlspecialchars($smsContactId) . ' (' . $smsContactSource . ')' : '❌ NOT SET'; ?></td>
                      </tr>
                      <tr style="background: #f9f9f9;">
                          <td><strong>SMS Consent:</strong></td>
                          <td>
                              <?php if ($smsContactId):
                                  $smsStmt = $db->prepare("SELECT receive_sms, consent_sms FROM contacts WHERE id = ?");
                                  $smsStmt->execute([$smsContactId]);
                                  $smsPrefs = $smsStmt->fetch(PDO::FETCH_ASSOC);
                                  $hasSmsConsent = !empty($smsPrefs['receive_sms']) || !empty($smsPrefs['consent_sms']);
                                  echo $hasSmsConsent ? '✅ OPTED IN' : '❌ NOT CONSENTED (receive_sms=' . ($smsPrefs['receive_sms'] ?? '0') . ', consent_sms=' . ($smsPrefs['consent_sms'] ?? '0') . ')';
                              else:
                                  echo '⚠️ Cannot check - no contact_id linked';
                              endif;
                              ?>
                          </td>
                      </tr>
                  </table>
              </div>
          </div>

          <!-- Token Debug Panel -->
          <div class="card mt-3" style="border-color: #6c757d;">
              <div class="card-header" style="background-color: #e2e3e5; cursor: pointer;" onclick="toggleDebugPanel(this, 'token-debug')">
                  <h5 style="margin: 0; color: #383d41;">
                      <span class="debug-toggle-icon" style="display: inline-block; width: 20px;">▶</span>
                      🔐 Access Tokens
                  </h5>
              </div>
              <div id="token-debug" class="card-body" style="display: none; font-size: 13px;">
                  <table style="width: 100%;">
                      <tr>
                          <td><strong>Access Token:</strong></td>
                          <td><?php echo !empty($quote['access_token']) ? '✅ SET (' . substr($quote['access_token'], 0, 8) . '...)' : '❌ NOT SET'; ?></td>
                      </tr>
                      <tr style="background: #f9f9f9;">
                          <td><strong>Token Expires:</strong></td>
                          <td><?php echo !empty($quote['token_expires_at']) ? htmlspecialchars($quote['token_expires_at']) : '❌ NOT SET'; ?></td>
                      </tr>
                      <tr>
                          <td><strong>Customer Link:</strong></td>
                          <td>
                              <?php if (!empty($quote['access_token'])): ?>
                                  <code style="background:#f5f5f5; padding:2px 5px; word-break: break-all;">https://<?php echo $_SERVER['HTTP_HOST']; ?>/customer/quote.php?token=<?php echo $quote['access_token']; ?></code>
                              <?php else: ?>
                                  Will be generated on first send
                              <?php endif; ?>
                          </td>
                      </tr>
                  </table>
              </div>
          </div>

          <script>
              function toggleDebugPanel(header, panelId) {
                  const panel = document.getElementById(panelId);
                  const icon = header.querySelector('.debug-toggle-icon');

                  if (panel.style.display === 'none') {
                      panel.style.display = 'block';
                      icon.textContent = '▼';
                  } else {
                      panel.style.display = 'none';
                      icon.textContent = '▶';
                  }
              }
          </script>

          <?php if ($message): ?>
              <div class="mw-message <?php echo $messageType; ?>" style="margin-top: 20px;"><?php echo htmlspecialchars($message); ?></div>
          <?php endif; ?>

          <div class="mw-page-header">
              <div>
                  <h1 class="h3 mb-1"><?php echo htmlspecialchars($quote['quote_number']); ?></h1>
                  <div>
                      <?php echo getStatusBadge($quote['status']); ?>
                      <?php if ($quote['title']): ?>
                          <span class="ml-2 text-muted"><?php echo htmlspecialchars($quote['title']); ?></span>
                      <?php endif; ?>
                  </div>
              </div>
              <div class="mw-header-actions">
                  <!-- PDF Actions -->
                  <a href="../documents/generate_pdf.php?type=quote&id=<?php echo $quoteId; ?>&action=download"
                     class="btn btn-outline-secondary" title="Download PDF">
                      <i data-feather="download" class="mr-1"></i> PDF
                  </a>
                  <form method="POST" action="../documents/generate_pdf.php?type=quote&id=<?php echo $quoteId; ?>&action=generate" class="d-inline">
                      <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                      <button type="submit" class="btn btn-outline-secondary" title="Regenerate PDF">
                          <i data-feather="refresh-cw" class="mr-1"></i> Regenerate PDF
                      </button>
                  </form>

                  <a href="create.php?id=<?php echo $quoteId; ?>" class="btn btn-secondary">Edit</a>

                  <?php if ($quote['status'] === 'draft'): ?>
                      <form method="POST" class="d-inline">
                          <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                          <button type="submit" name="action" value="send" class="btn btn-primary">
                              <i data-feather="send" class="mr-1"></i> Send to Customer
                          </button>
                      </form>
                      <button type="button" class="btn btn-outline-success" onclick="showApproveModal()">
                          <i data-feather="check-circle" class="mr-1"></i> Approve (Verbal)
                      </button>
                  <?php elseif ($quote['status'] === 'sent'): ?>
                      <form method="POST" class="d-inline">
                          <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                          <button type="submit" name="action" value="send" class="btn btn-secondary">
                              <i data-feather="send" class="mr-1"></i> Resend
                          </button>
                      </form>
                      <button type="button" class="btn btn-outline-success" onclick="showApproveModal()">
                          <i data-feather="check-circle" class="mr-1"></i> Approve (Verbal)
                      </button>
                  <?php elseif ($quote['status'] === 'accepted'): ?>
                      <form method="POST" class="d-inline">
                          <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                          <button type="submit" name="action" value="convert_to_job" class="btn btn-primary">
                              <i data-feather="tool" class="mr-1"></i> Convert to Plan
                          </button>
                      </form>
                  <?php endif; ?>
              </div>
          </div>

          <div class="mw-content-grid">
              <div>
                  <!-- Customer Info -->
                  <div class="card">
                      <div class="card-header d-flex justify-content-between align-items-center">
                          <h5 class="card-title mb-0">Customer Information</h5>
                          <?php
                              $editContactId = $quote['qr_contact_id'] ?? $quote['contact_id'] ?? null;
                          ?>
                          <?php if ($editContactId): ?>
                              <button type="button" class="btn btn-sm btn-outline-secondary" onclick="showContactEditModal()">
                                  <i data-feather="edit-2" style="width: 14px; height: 14px;"></i> Edit
                              </button>
                          <?php endif; ?>
                      </div>
                      <div class="card-body">
                          <?php
                              // For commercial properties, show company. For residential, show contact name as primary
                              $isResidential = $quote['property_type'] === 'residential';

                              // Prefer quote_request contact info if available (from original request)
                              $contactName = trim(($quote['qr_first_name'] ?? '') . ' ' . ($quote['qr_last_name'] ?? ''));
                              $contactEmail = $quote['qr_email'] ?? null;
                              $contactPhone = $quote['qr_phone'] ?? null;

                              // Fall back to company contact if no quote request contact
                              if (!$contactName) {
                                  $contactName = trim(($quote['contact_first'] ?? '') . ' ' . ($quote['contact_last'] ?? ''));
                                  $contactEmail = $quote['contact_email'] ?? null;
                                  $contactPhone = $quote['contact_phone'] ?? null;
                              }

                              // Final fallback
                              if (!$contactName) {
                                  $contactName = 'N/A';
                              }
                              if (!$contactEmail) {
                                  $contactEmail = $quote['billing_email'] ?? 'N/A';
                              }
                              if (!$contactPhone) {
                                  $contactPhone = $quote['billing_phone'] ?? 'N/A';
                              }
                          ?>
                          <?php if (!$isResidential && $quote['company_name']): ?>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Company</span>
                              <span class="mw-detail-value"><?php echo htmlspecialchars($quote['company_name']); ?></span>
                          </div>
                          <?php endif; ?>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label"><?php echo $isResidential ? 'Owner Name' : 'Contact'; ?></span>
                              <span class="mw-detail-value"><?php echo htmlspecialchars($contactName); ?></span>
                          </div>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Email</span>
                              <span class="mw-detail-value"><?php echo htmlspecialchars($contactEmail); ?></span>
                          </div>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Phone</span>
                              <span class="mw-detail-value"><?php echo htmlspecialchars($contactPhone); ?></span>
                          </div>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Property</span>
                              <span class="mw-detail-value">
                                  <?php echo htmlspecialchars($quote['property_address'] . ', ' . $quote['property_city']); ?>
                              </span>
                          </div>
                      </div>
                  </div>

                  <!-- Line Items -->
                  <div class="card">
                      <div class="card-header">
                          <h5 class="card-title mb-0">Services</h5>
                      </div>
                      <div class="card-body">
                          <table class="mw-line-items-table">
                              <thead>
                                  <tr>
                                      <th>Service</th>
                                      <th>Description</th>
                                      <th>Qty</th>
                                      <th class="text-right">Price</th>
                                      <th class="text-right">Total</th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php foreach ($lineItems as $item): ?>
                                      <tr>
                                          <td><?php echo htmlspecialchars($item['service_type']); ?></td>
                                          <td><?php echo htmlspecialchars($item['description'] ?: '-'); ?></td>
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
                                  <span class="mw-totals-value"><?php echo formatCurrency($quote['subtotal'] ?: $quote['amount']); ?></span>
                              </div>
                              <div class="mw-total-row">
                                  <span>GST (<?php echo ($quote['tax_rate'] ?: 0.05) * 100; ?>%)</span>
                                  <span class="mw-totals-value"><?php echo formatCurrency($quote['tax_amount'] ?: 0); ?></span>
                              </div>
                              <div class="mw-total-row mw-grand">
                                  <span>Total</span>
                                  <span class="mw-totals-value"><?php echo formatCurrency($quote['amount']); ?></span>
                              </div>
                          </div>
                      </div>
                  </div>

                  <!-- Terms -->
                  <?php if ($quote['terms']): ?>
                      <div class="card">
                          <div class="card-header">
                              <h5 class="card-title mb-0">Terms &amp; Conditions</h5>
                          </div>
                          <div class="card-body">
                              <p class="mb-0" style="white-space: pre-line;"><?php echo htmlspecialchars($quote['terms']); ?></p>
                          </div>
                      </div>
                  <?php endif; ?>

                  <!-- Signature if accepted -->
                  <?php if ($quote['status'] === 'accepted' && $quote['signature_data']): ?>
                      <div class="card">
                          <div class="card-header">
                              <h5 class="card-title mb-0">Customer Signature</h5>
                          </div>
                          <div class="card-body">
                              <div class="mw-signature-section">
                                  <img src="<?php echo htmlspecialchars($quote['signature_data']); ?>" alt="Customer Signature">
                                  <div class="mw-signature-info">
                                      Signed by: <?php echo htmlspecialchars($quote['accepted_by_name']); ?><br>
                                      Date: <?php echo formatDateTime($quote['signature_timestamp']); ?><br>
                                      IP: <?php echo htmlspecialchars($quote['accepted_ip_address']); ?>
                                  </div>
                              </div>
                          </div>
                      </div>
                  <?php endif; ?>
              </div>

              <div>
                  <!-- Quote Details -->
                  <div class="card">
                      <div class="card-header">
                          <h5 class="card-title mb-0">Quote Details</h5>
                      </div>
                      <div class="card-body">
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Status</span>
                              <span class="mw-detail-value"><?php echo getStatusBadge($quote['status']); ?></span>
                          </div>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Created</span>
                              <span class="mw-detail-value"><?php echo formatDateTime($quote['created_at']); ?></span>
                          </div>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Valid Until</span>
                              <span class="mw-detail-value"><?php echo formatDate($quote['valid_until']); ?></span>
                          </div>
                          <?php if ($quote['sent_at']): ?>
                              <div class="mw-detail-row">
                                  <span class="mw-detail-label">Sent</span>
                                  <span class="mw-detail-value"><?php echo formatDateTime($quote['sent_at']); ?></span>
                              </div>
                          <?php endif; ?>
                          <?php if ($quote['viewed_at']): ?>
                              <div class="mw-detail-row">
                                  <span class="mw-detail-label">Viewed</span>
                                  <span class="mw-detail-value"><?php echo formatDateTime($quote['viewed_at']); ?> (<?php echo $quote['view_count']; ?>x)</span>
                              </div>
                          <?php endif; ?>
                          <?php if ($quote['accepted_at']): ?>
                              <div class="mw-detail-row">
                                  <span class="mw-detail-label">Accepted</span>
                                  <span class="mw-detail-value"><?php echo formatDateTime($quote['accepted_at']); ?></span>
                              </div>
                          <?php endif; ?>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Created By</span>
                              <span class="mw-detail-value"><?php echo htmlspecialchars($quote['created_by_name'] ?? 'Unknown'); ?></span>
                          </div>
                          <?php if (!empty($quote['pdf_version']) && $quote['pdf_version'] > 0): ?>
                              <div class="mw-detail-row">
                                  <span class="mw-detail-label">PDF Version</span>
                                  <span class="mw-detail-value">
                                      v<?php echo (int)$quote['pdf_version']; ?>
                                      <?php if (!empty($quote['pdf_generated_at'])): ?>
                                          <span class="text-muted ml-1">(<?php echo formatDateTime($quote['pdf_generated_at']); ?>)</span>
                                      <?php endif; ?>
                                  </span>
                              </div>
                          <?php endif; ?>

                          <?php if ($quote['access_token'] && in_array($quote['status'], ['sent', 'accepted'])): ?>
                              <div class="mt-3">
                                  <span class="mw-detail-label">Customer Link:</span>
                                  <div class="mw-customer-link">
                                      <?php echo "https://" . $_SERVER['HTTP_HOST'] . "/customer/quote.php?token=" . $quote['access_token']; ?>
                                  </div>
                              </div>
                          <?php endif; ?>
                      </div>
                  </div>

                  <!-- Internal Notes -->
                  <?php if ($quote['notes_internal']): ?>
                      <div class="card">
                          <div class="card-header">
                              <h5 class="card-title mb-0">Internal Notes</h5>
                          </div>
                          <div class="card-body">
                              <p class="mb-0" style="white-space: pre-line; font-size: 14px;"><?php echo htmlspecialchars($quote['notes_internal']); ?></p>
                          </div>
                      </div>
                  <?php endif; ?>

                  <!-- Notes & Comments -->
                  <div class="card mw-notes-panel">
                      <div class="card-header d-flex justify-content-between align-items-center">
                          <h5 class="card-title mb-0">Notes & Comments</h5>
                          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleNoteForm()">
                              <i data-feather="plus" style="width: 14px; height: 14px;"></i> Add Note
                          </button>
                      </div>
                      <div class="card-body">
                          <!-- Add Note Form -->
                          <div id="noteForm" class="mw-note-form mb-3" style="display: none;">
                              <div class="mw-form-group mb-2">
                                  <textarea id="noteContent" class="form-control" rows="3" placeholder="Write your note here..." style="font-size: 13px;"></textarea>
                              </div>
                              <div class="mw-form-group mb-3">
                                  <select id="noteType" class="form-control form-control-sm" style="font-size: 13px;">
                                      <option value="general">General Note</option>
                                      <option value="follow_up">Follow-up</option>
                                      <option value="issue">Issue</option>
                                      <option value="customer_request">Customer Request</option>
                                      <option value="internal">Internal</option>
                                  </select>
                              </div>
                              <div class="d-flex gap-2">
                                  <button type="button" class="btn btn-sm btn-primary" onclick="saveNote()">Save Note</button>
                                  <button type="button" class="btn btn-sm btn-secondary" onclick="toggleNoteForm()">Cancel</button>
                              </div>
                          </div>

                          <!-- Notes List -->
                          <?php if (empty($quoteNotes)): ?>
                              <p class="text-muted mb-0" style="font-size: 13px;">No notes yet. Add one to get started.</p>
                          <?php else: ?>
                              <ul class="list-unstyled" id="notesList">
                                  <?php foreach ($quoteNotes as $note): ?>
                                      <li class="mw-note-item mb-3 pb-3 border-bottom">
                                          <div class="mw-note-header d-flex justify-content-between align-items-start mb-1">
                                              <span class="badge <?php echo getNoteTypeClass($note['note_type']); ?>" style="font-size: 11px;">
                                                  <?php echo getNoteTypeLabel($note['note_type']); ?>
                                              </span>
                                              <small class="text-muted"><?php echo timeAgo($note['created_at']); ?></small>
                                          </div>
                                          <div class="mw-note-content" style="font-size: 13px; line-height: 1.5; white-space: pre-wrap;">
                                              <?php echo htmlspecialchars($note['content']); ?>
                                          </div>
                                          <div class="mw-note-footer mt-1">
                                              <small class="text-muted">by <?php echo htmlspecialchars($note['created_by_name'] ?? 'System'); ?></small>
                                          </div>
                                      </li>
                                  <?php endforeach; ?>
                              </ul>
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

          <!-- Approve (Verbal) Modal -->
          <?php if (in_array($quote['status'], ['draft', 'sent'])): ?>
          <div class="modal fade" id="approveModal" tabindex="-1" role="dialog">
              <div class="modal-dialog" role="document">
                  <div class="modal-content">
                      <form method="POST">
                          <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                          <input type="hidden" name="action" value="approve_verbal">
                          <div class="modal-header">
                              <h5 class="modal-title">Approve Quote (Verbal Confirmation)</h5>
                              <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                          </div>
                          <div class="modal-body">
                              <p class="text-muted mb-3">Record verbal approval from the customer. This will mark the quote as accepted without requiring a digital signature.</p>
                              <div class="form-group">
                                  <label for="approverName">Customer Name</label>
                                  <input type="text" class="form-control" id="approverName" name="approver_name"
                                         value="<?php echo htmlspecialchars(trim(($quote['qr_first_name'] ?? $quote['contact_first'] ?? '') . ' ' . ($quote['qr_last_name'] ?? $quote['contact_last'] ?? ''))); ?>"
                                         placeholder="Name of person who gave verbal approval">
                              </div>
                          </div>
                          <div class="modal-footer">
                              <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                              <button type="submit" class="btn btn-success">
                                  <i data-feather="check-circle" class="mr-1"></i> Confirm Approval
                              </button>
                          </div>
                      </form>
                  </div>
              </div>
          </div>
          <?php endif; ?>

          <!-- Edit Contact Modal -->
          <?php
              $editContactId = $quote['qr_contact_id'] ?? $quote['contact_id'] ?? null;
              if ($editContactId):
                  // Fetch current contact data for the edit form
                  $editStmt = $db->prepare("SELECT id, first_name, last_name, email, phone FROM contacts WHERE id = ?");
                  $editStmt->execute([$editContactId]);
                  $editContact = $editStmt->fetch(PDO::FETCH_ASSOC);
          ?>
          <?php if ($editContact): ?>
          <div class="modal fade" id="contactEditModal" tabindex="-1" role="dialog">
              <div class="modal-dialog" role="document">
                  <div class="modal-content">
                      <form method="POST" id="contactEditForm">
                          <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                          <input type="hidden" name="action" value="update_contact">
                          <input type="hidden" name="contact_id" value="<?php echo (int)$editContact['id']; ?>">
                          <div class="modal-header">
                              <h5 class="modal-title">Edit Contact Details</h5>
                              <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                          </div>
                          <div class="modal-body">
                              <div class="form-group">
                                  <label for="editFirstName">First Name *</label>
                                  <input type="text" class="form-control" id="editFirstName" name="first_name"
                                         value="<?php echo htmlspecialchars($editContact['first_name']); ?>" required>
                              </div>
                              <div class="form-group">
                                  <label for="editLastName">Last Name</label>
                                  <input type="text" class="form-control" id="editLastName" name="last_name"
                                         value="<?php echo htmlspecialchars($editContact['last_name']); ?>">
                              </div>
                              <div class="form-group">
                                  <label for="editEmail">Email</label>
                                  <input type="email" class="form-control" id="editEmail" name="email"
                                         value="<?php echo htmlspecialchars($editContact['email']); ?>">
                              </div>
                              <div class="form-group">
                                  <label for="editPhone">Phone</label>
                                  <input type="tel" class="form-control" id="editPhone" name="phone"
                                         value="<?php echo htmlspecialchars($editContact['phone']); ?>">
                              </div>
                          </div>
                          <div class="modal-footer">
                              <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                              <button type="submit" class="btn btn-primary" id="contactSaveBtn">
                                  <i data-feather="save" class="mr-1"></i> Save Changes
                              </button>
                          </div>
                      </form>
                  </div>
              </div>
          </div>
          <?php endif; ?>
          <?php endif; ?>

          <script>
            const quoteId = <?php echo (int)$quoteId; ?>;
            const csrfToken = '<?php echo htmlspecialchars($csrfToken); ?>';

            // --- Approve Modal ---
            function showApproveModal() {
              $('#approveModal').modal('show');
            }

            // --- Contact Edit Modal ---
            function showContactEditModal() {
              $('#contactEditModal').modal('show');
            }

            // Handle contact edit form via AJAX
            const contactForm = document.getElementById('contactEditForm');
            if (contactForm) {
              contactForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const btn = document.getElementById('contactSaveBtn');
                btn.disabled = true;
                btn.innerHTML = '<i data-feather="loader" class="mr-1"></i> Saving...';

                const formData = new FormData(this);
                fetch('view.php?id=' + quoteId, {
                  method: 'POST',
                  headers: { 'X-Requested-With': 'XMLHttpRequest' },
                  body: formData
                })
                .then(r => r.json())
                .then(data => {
                  if (data.success) {
                    // Reload to show updated info
                    window.location.href = 'view.php?id=' + quoteId + '&saved=1';
                  } else {
                    alert('Error: ' + (data.error || 'Failed to save.'));
                    btn.disabled = false;
                    btn.innerHTML = '<i data-feather="save" class="mr-1"></i> Save Changes';
                  }
                })
                .catch(err => {
                  console.error(err);
                  alert('Error saving contact.');
                  btn.disabled = false;
                  btn.innerHTML = '<i data-feather="save" class="mr-1"></i> Save Changes';
                });
              });
            }

            // --- Notes ---
            function toggleNoteForm() {
              const form = document.getElementById('noteForm');
              form.style.display = form.style.display === 'none' ? 'block' : 'none';
              if (form.style.display === 'block') {
                document.getElementById('noteContent').focus();
              } else {
                document.getElementById('noteContent').value = '';
                document.getElementById('noteType').value = 'general';
              }
            }

            function saveNote() {
              const content = document.getElementById('noteContent').value.trim();
              const noteType = document.getElementById('noteType').value;

              if (!content) {
                alert('Please enter a note.');
                return;
              }

              const formData = new FormData();
              formData.append('quote_id', quoteId);
              formData.append('content', content);
              formData.append('note_type', noteType);
              formData.append('csrf_token', csrfToken);

              fetch('handle-note-save.php', {
                method: 'POST',
                body: formData
              })
              .then(response => response.json())
              .then(data => {
                if (data.success) {
                  addNoteToList(data.note);
                  document.getElementById('noteContent').value = '';
                  document.getElementById('noteType').value = 'general';
                  toggleNoteForm();
                } else {
                  alert('Error: ' + (data.error || 'Failed to save note'));
                }
              })
              .catch(err => {
                console.error('Error:', err);
                alert('Error saving note');
              });
            }

            function addNoteToList(note) {
              const notesList = document.getElementById('notesList');
              const noNotesMsg = document.querySelector('.mw-notes-panel .text-muted');

              if (noNotesMsg && noNotesMsg.textContent.includes('No notes')) {
                noNotesMsg.remove();
              }

              const noteHtml = `
                <li class="mw-note-item mb-3 pb-3 border-bottom">
                  <div class="mw-note-header d-flex justify-content-between align-items-start mb-1">
                    <span class="badge ${note.type_class}" style="font-size: 11px;">
                      ${note.type_label}
                    </span>
                    <small class="text-muted">just now</small>
                  </div>
                  <div class="mw-note-content" style="font-size: 13px; line-height: 1.5; white-space: pre-wrap;">
                    ${note.content}
                  </div>
                  <div class="mw-note-footer mt-1">
                    <small class="text-muted">by ${note.created_by_name}</small>
                  </div>
                </li>
              `;

              if (notesList) {
                notesList.insertAdjacentHTML('afterbegin', noteHtml);
              }
            }
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
