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
requirePermission('billing.view');

$quoteId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$quoteId) {
    header('Location: index.php');
    exit;
}

$db = getDB();

// Get quote with related data - also check quote_requests for original contact info
// Contact resolution priority: quote_request contact → company primary contact → property site contact
$stmt = $db->prepare("
    SELECT
        q.*,
        p.address as property_address,
        p.city as property_city,
        p.postal_code as property_postal,
        p.property_type,
        p.site_contact_id,
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
        pc.id as prop_contact_id,
        pc.first_name as prop_contact_first,
        pc.last_name as prop_contact_last,
        pc.email as prop_contact_email,
        pc.phone as prop_contact_phone,
        u.full_name as created_by_name
    FROM quotes q
    LEFT JOIN properties p ON q.property_id = p.id
    LEFT JOIN company_properties cp ON p.id = cp.property_id AND cp.is_primary = 1
    LEFT JOIN companies c ON q.company_id = c.id
    LEFT JOIN contacts ct ON c.primary_contact_id = ct.id
    LEFT JOIN quote_requests qr ON qr.quote_id = q.id
    LEFT JOIN contacts qrc ON qr.contact_id = qrc.id
    LEFT JOIN contacts pc ON p.site_contact_id = pc.id
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

// Smart conversion detection: single distinct service_type → one-click; multiple → multi-plan flow
$distinctServiceTypes = array_unique(array_filter(array_column($lineItems, 'service_type')));
$quoteIsSingleService = (count($distinctServiceTypes) <= 1);

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
        // Resolve customer contact info (quote request contact → company contact → property contact → billing)
        $customerEmail = $quote['qr_email'] ?? $quote['contact_email'] ?? $quote['prop_contact_email'] ?? $quote['billing_email'] ?? null;
        $customerPhone = $quote['qr_phone'] ?? $quote['contact_phone'] ?? $quote['prop_contact_phone'] ?? $quote['billing_phone'] ?? null;
        $customerConsentsToSms = false;

        // Check SMS consent via contacts table (quote request contact → company primary contact → property contact)
        if (!empty($quote['qr_contact_id'])) {
            $customerConsentsToSms = hasSmConsent((int)$quote['qr_contact_id']);
        } elseif (!empty($quote['contact_id'])) {
            $customerConsentsToSms = hasSmConsent((int)$quote['contact_id']);
        } elseif (!empty($quote['prop_contact_id'])) {
            $customerConsentsToSms = hasSmConsent((int)$quote['prop_contact_id']);
        }

        if (!$customerEmail && !$customerPhone) {
            $message = 'Error: No contact information found (no email or phone). Please add contact details first.';
            $messageType = 'error';
        } else {
            $emailSent = false;
            $smsSent = false;
            $sentVia = [];
            $attachPath = null;

            // Generate access token if not exists; always refresh expiry on send/resend
            if (empty($quote['access_token'])) {
                $quote['access_token'] = generateAccessToken();
                $stmt = $db->prepare("UPDATE quotes SET access_token = ?, token_expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = ?");
                $stmt->execute([$quote['access_token'], $quoteId]);
            } else {
                // Token exists — refresh the 30-day expiry window so resent links don't expire
                $stmt = $db->prepare("UPDATE quotes SET token_expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = ?");
                $stmt->execute([$quoteId]);
            }

            // Build customer name and quote URL
            $customerName = trim(($quote['qr_first_name'] ?? $quote['contact_first'] ?? $quote['prop_contact_first'] ?? '') . ' ' . ($quote['qr_last_name'] ?? $quote['contact_last'] ?? $quote['prop_contact_last'] ?? '')) ?: ($quote['company_name'] ?? 'Valued Customer');
            $quoteUrl = "https://" . $_SERVER['HTTP_HOST'] . "/customer/quote.php?token=" . $quote['access_token'];

            // --- SEND EMAIL ---
            if ($customerEmail) {
                require_once APP_ROOT . '/Services/Messaging/EmailWrapper.php';

                $nameParts    = explode(' ', $customerName, 2);
                $firstName    = $nameParts[0] ?: 'there';
                $companyInfo  = EmailWrapper::getCompanyInfo();

                $tplVars = [
                    '{{customer_first_name}}' => $firstName,
                    '{{customer_name}}'       => $customerName ?: 'Valued Customer',
                    '{{quote_number}}'        => $quote['quote_number'],
                    '{{quote_amount}}'        => formatCurrency($quote['amount']),
                    '{{quote_valid_until}}'   => formatDate($quote['valid_until']),
                    '{{company_name}}'        => $companyInfo['company_name'],
                    '{{company_phone}}'       => $companyInfo['company_phone'],
                ];

                $tpl          = loadEmailTemplate('quote_sent', $tplVars);
                $emailSubject = $tpl['subject'];
                $emailBody    = EmailWrapper::wrap(
                    $tpl['body_html'],
                    'View &amp; Accept Quote',
                    $quoteUrl,
                    $companyInfo
                );

                // Inject email open tracking pixel (1x1 transparent PNG)
                $pixelUrl = "https://" . $_SERVER['HTTP_HOST'] . "/crm/api/track-quote-open.php?t=" . urlencode($quote['access_token']);
                $emailBody = str_replace('</body>', '<img src="' . htmlspecialchars($pixelUrl) . '" width="1" height="1" alt="" style="display:none;border:none;" /></body>', $emailBody);

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
                $oldStatus = $quote['status'];
                $stmt = $db->prepare("UPDATE quotes SET status = 'sent', sent_at = NOW(), sent_via = ? WHERE id = ?");
                $stmt->execute([implode(',', $sentVia), $quoteId]);
                trackFieldChange('quote', $quoteId, 'status', $oldStatus, 'sent', $user['id']);

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
            $approverName = trim(($quote['qr_first_name'] ?? $quote['contact_first'] ?? $quote['prop_contact_first'] ?? '') . ' ' . ($quote['qr_last_name'] ?? $quote['contact_last'] ?? $quote['prop_contact_last'] ?? ''));
        }
        if (empty($approverName)) {
            $approverName = 'Customer (verbal)';
        }

        try {
            $oldStatus = $quote['status'];
            $stmt = $db->prepare("
                UPDATE quotes SET
                    status = 'accepted',
                    accepted_at = NOW(),
                    accepted_by_name = ?,
                    accepted_ip_address = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $approverName . ' (verbal approval by ' . $user['name'] . ')',
                $_SERVER['REMOTE_ADDR'],
                $quoteId
            ]);
            trackFieldChange('quote', $quoteId, 'status', $oldStatus, 'accepted', $user['id']);

            logActivityExtended($user['id'], 'Quote approved (verbal)', "Approved on behalf of {$approverName} by {$user['name']}", null, null, $quoteId);

            $quote['status'] = 'accepted';
            $message = "Quote approved (verbal confirmation from {$approverName}).";
            $messageType = 'success';
        } catch (Exception $e) {
            error_log("Quote verbal approval error: " . $e->getMessage());
            $message = 'Error approving quote. Please try again.';
            $messageType = 'error';
        }
    }

    if ($action === 'send_followup') {
        // Manual follow-up — uses the quote_followup template
        $customerEmail = $quote['qr_email'] ?? $quote['contact_email'] ?? $quote['prop_contact_email'] ?? $quote['billing_email'] ?? null;
        $customerPhone = $quote['qr_phone'] ?? $quote['contact_phone'] ?? $quote['prop_contact_phone'] ?? $quote['billing_phone'] ?? null;
        $customerConsentsToSms = false;

        if (!empty($quote['qr_contact_id'])) {
            $customerConsentsToSms = hasSmConsent((int)$quote['qr_contact_id']);
        } elseif (!empty($quote['contact_id'])) {
            $customerConsentsToSms = hasSmConsent((int)$quote['contact_id']);
        } elseif (!empty($quote['prop_contact_id'])) {
            $customerConsentsToSms = hasSmConsent((int)$quote['prop_contact_id']);
        }

        if (!$customerEmail) {
            $message = 'Error: No email address found for this quote contact.';
            $messageType = 'error';
        } else {
            require_once APP_ROOT . '/Services/Messaging/EmailWrapper.php';

            // Ensure access token exists
            if (empty($quote['access_token'])) {
                $quote['access_token'] = generateAccessToken();
                $db->prepare("UPDATE quotes SET access_token = ?, token_expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = ?")
                   ->execute([$quote['access_token'], $quoteId]);
            } else {
                $db->prepare("UPDATE quotes SET token_expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = ?")
                   ->execute([$quoteId]);
            }

            $customerName = trim(($quote['qr_first_name'] ?? $quote['contact_first'] ?? $quote['prop_contact_first'] ?? '') . ' ' . ($quote['qr_last_name'] ?? $quote['contact_last'] ?? $quote['prop_contact_last'] ?? '')) ?: ($quote['company_name'] ?? 'Valued Customer');
            $firstName    = explode(' ', $customerName)[0] ?: 'there';
            $quoteUrl     = "https://" . $_SERVER['HTTP_HOST'] . "/customer/quote.php?token=" . $quote['access_token'];
            $companyInfo  = EmailWrapper::getCompanyInfo();

            $tplVars = [
                '{{customer_first_name}}' => $firstName,
                '{{customer_name}}'       => $customerName,
                '{{quote_number}}'        => $quote['quote_number'],
                '{{quote_amount}}'        => formatCurrency($quote['amount']),
                '{{quote_valid_until}}'   => formatDate($quote['valid_until']),
                '{{company_name}}'        => $companyInfo['company_name'],
                '{{company_phone}}'       => $companyInfo['company_phone'],
            ];

            $tpl     = loadEmailTemplate('quote_followup', $tplVars);
            $subject = $tpl['subject'] ?? 'Following up on your Mowology quote (' . $quote['quote_number'] . ')';
            $body    = EmailWrapper::wrap($tpl['body_html'] ?? '', 'View Your Quote', $quoteUrl, $companyInfo);

            $pixelUrl = "https://" . $_SERVER['HTTP_HOST'] . "/crm/api/track-quote-open.php?t=" . urlencode($quote['access_token']);
            $body = str_replace('</body>', '<img src="' . htmlspecialchars($pixelUrl) . '" width="1" height="1" alt="" style="display:none;border:none;" /></body>', $body);

            $emailResult = sendEmail($customerEmail, $subject, $body);

            if ($emailResult['success']) {
                // Update follow-up tracking (graceful — columns may not exist pre-migration)
                try {
                    $db->prepare("UPDATE quotes SET follow_up_sent_at = NOW(), follow_up_count = COALESCE(follow_up_count, 0) + 1 WHERE id = ?")
                       ->execute([$quoteId]);
                } catch (PDOException $fuEx) {
                    error_log("follow_up column update skipped (pre-migration?): " . $fuEx->getMessage());
                }

                // SMS notification (if consented) — no URL, just a nudge
                if ($customerPhone && $customerConsentsToSms) {
                    $smsMsg = "Hi {$firstName}, following up on Mowology quote #{$quote['quote_number']}. Check your email for the link. Questions? Call (778) 846-9273.";
                    sendSms($customerPhone, $smsMsg);
                }

                logActivityExtended($user['id'], 'Follow-up sent', "Quote follow-up email sent to {$customerEmail}", null, null, $quoteId);

                $message = 'Follow-up sent successfully to ' . $customerEmail . '.';
                $messageType = 'success';

                // Reload quote to show updated follow_up_count
                $stmt->execute([$quoteId]);
                $quote = $stmt->fetch(PDO::FETCH_ASSOC) ?: $quote;
            } else {
                $message = 'Error sending follow-up. Please check the email address and try again.';
                $messageType = 'error';
            }
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

    if ($action === 'delete') {
        // Check if any job plans reference this quote
        $stmt = $db->prepare("SELECT COUNT(*) FROM job_plans WHERE quote_id = ?");
        $stmt->execute([$quoteId]);
        $planCount = (int)$stmt->fetchColumn();

        if ($planCount > 0) {
            $message = "Cannot delete this quote — {$planCount} job plan(s) are linked to it. Delete the plans first.";
            $messageType = 'error';
        } else {
            try {
                $db->beginTransaction();

                // Clean up activity_log references (no FK constraint)
                $db->prepare("DELETE FROM activity_log WHERE quote_id = ?")->execute([$quoteId]);

                // quote_line_items and quote_notes cascade automatically via FK
                $db->prepare("DELETE FROM quotes WHERE id = ?")->execute([$quoteId]);

                $db->commit();

                // Log after commit (quote is gone, log under property/contact context)
                logActivityExtended($user['id'], 'Quote deleted', "Deleted quote {$quote['quote_number']}");

                // Redirect to quotes list
                header("Location: ../quotes_appstack.php?deleted=1");
                exit;
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Quote delete error: " . $e->getMessage());
                $message = 'Error deleting quote. Please try again.';
                $messageType = 'error';
            }
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
              $debugEmail = $quote['qr_email'] ?? $quote['contact_email'] ?? $quote['prop_contact_email'] ?? $quote['billing_email'] ?? null;
              $debugPhone = $quote['qr_phone'] ?? $quote['contact_phone'] ?? $quote['prop_contact_phone'] ?? $quote['billing_phone'] ?? null;
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
                              prop_contact_email: <?php echo htmlspecialchars($quote['prop_contact_email'] ?? '-'); ?><br>
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
                              prop_contact_phone: <?php echo htmlspecialchars($quote['prop_contact_phone'] ?? '-'); ?><br>
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
                      $smsContactId = !empty($quote['qr_contact_id']) ? $quote['qr_contact_id'] : (!empty($quote['contact_id']) ? $quote['contact_id'] : ($quote['prop_contact_id'] ?? null));
                      $smsContactSource = !empty($quote['qr_contact_id']) ? 'quote request' : (!empty($quote['contact_id']) ? 'primary contact' : (!empty($quote['prop_contact_id']) ? 'property contact' : 'none'));
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
                      <?php
                          // Show "Send Follow-Up" when quote is 2+ days old and hasn't been accepted
                          $daysSent = $quote['sent_at'] ? (int)floor((time() - strtotime($quote['sent_at'])) / 86400) : 0;
                          if ($daysSent >= 2):
                      ?>
                      <form method="POST" class="d-inline">
                          <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                          <button type="submit" name="action" value="send_followup"
                                  class="btn btn-warning"
                                  onclick="return confirm('Send a follow-up email to this customer about quote <?php echo htmlspecialchars($quote['quote_number']); ?>?')">
                              <i data-feather="bell" class="mr-1"></i> Send Follow-Up
                              <?php
                                  $fuCount = (int)($quote['follow_up_count'] ?? 0);
                                  if ($fuCount > 0): ?><small>(#<?php echo $fuCount + 1; ?>)</small><?php endif;
                              ?>
                          </button>
                      </form>
                      <?php endif; ?>
                      <button type="button" class="btn btn-outline-success" onclick="showApproveModal()">
                          <i data-feather="check-circle" class="mr-1"></i> Approve (Verbal)
                      </button>
                  <?php elseif ($quote['status'] === 'accepted'): ?>
                      <?php if (!empty($quote['is_contract'])): ?>
                          <a href="../contracts/create.php?quote_id=<?php echo $quoteId; ?>" class="btn btn-primary">
                              <i data-feather="pen-tool" class="mr-1"></i> Create Contract
                          </a>
                      <?php else: ?>
                          <?php if ($quoteIsSingleService): ?>
                          <!-- Single service type → one-click conversion, line items auto-copied -->
                          <form method="POST" class="d-inline">
                              <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                              <button type="submit" name="action" value="convert_to_job" class="btn btn-primary">
                                  <i data-feather="zap" class="mr-1"></i> Convert to Job
                              </button>
                          </form>
                          <?php else: ?>
                          <!-- Multiple service types → split-plan workflow -->
                          <a href="../jobs/create-from-quote.php?quote_id=<?php echo $quoteId; ?>" class="btn btn-primary">
                              <i data-feather="briefcase" class="mr-1"></i> Create Plans
                          </a>
                          <?php endif; ?>
                      <?php endif; ?>
                      <form method="POST" class="d-inline">
                          <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                          <button type="submit" name="action" value="send" class="btn btn-outline-secondary">
                              <i data-feather="send" class="mr-1"></i> Resend Link
                          </button>
                      </form>
                  <?php elseif (in_array($quote['status'], ['declined', 'expired'])): ?>
                      <form method="POST" class="d-inline">
                          <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                          <button type="submit" name="action" value="send" class="btn btn-outline-secondary">
                              <i data-feather="send" class="mr-1"></i> Resend Link
                          </button>
                      </form>
                  <?php endif; ?>

                  <button type="button" class="btn btn-outline-danger" onclick="confirmDeleteQuote()">
                      <i data-feather="trash-2" class="mr-1"></i> Delete
                  </button>
              </div>
          </div>

          <div class="mw-content-grid">
              <div>
                  <!-- Customer Info -->
                  <div class="card">
                      <div class="card-header d-flex justify-content-between align-items-center">
                          <h5 class="card-title mb-0">Customer Information</h5>
                          <?php
                              $editContactId = $quote['qr_contact_id'] ?? $quote['contact_id'] ?? $quote['prop_contact_id'] ?? null;
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

                              // Contact resolution priority:
                              // 1. Quote request contact (from original request)
                              // 2. Company primary contact
                              // 3. Property site contact (from properties.site_contact_id)
                              $contactName = trim(($quote['qr_first_name'] ?? '') . ' ' . ($quote['qr_last_name'] ?? ''));
                              $contactEmail = $quote['qr_email'] ?? null;
                              $contactPhone = $quote['qr_phone'] ?? null;

                              // Fall back to company contact if no quote request contact
                              if (!$contactName) {
                                  $contactName = trim(($quote['contact_first'] ?? '') . ' ' . ($quote['contact_last'] ?? ''));
                                  $contactEmail = $quote['contact_email'] ?? null;
                                  $contactPhone = $quote['contact_phone'] ?? null;
                              }

                              // Fall back to property site contact
                              if (!$contactName) {
                                  $contactName = trim(($quote['prop_contact_first'] ?? '') . ' ' . ($quote['prop_contact_last'] ?? ''));
                                  $contactEmail = $quote['prop_contact_email'] ?? null;
                                  $contactPhone = $quote['prop_contact_phone'] ?? null;
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
                      <div class="card-header d-flex justify-content-between align-items-center">
                          <h5 class="card-title mb-0">Services</h5>
                          <div class="d-flex align-items-center" style="gap: 12px; font-size: 12px;">
                              <span id="mw-reorder-status" class="mw-reorder-status" style="display: none;"></span>
                              <span class="text-muted mw-drag-hint">
                                  <i data-feather="move" style="width: 12px; height: 12px; vertical-align: middle;"></i> Drag to reorder
                              </span>
                          </div>
                      </div>
                      <div class="card-body" style="padding: 0;">
                          <table class="mw-line-items-table mw-sortable-table" id="lineItemsTable">
                              <thead>
                                  <tr>
                                      <th class="mw-drag-col"></th>
                                      <th>Service</th>
                                      <th>Description</th>
                                      <th>Qty</th>
                                      <th class="text-right">Price</th>
                                      <th class="text-right">Total</th>
                                  </tr>
                              </thead>
                              <tbody id="sortableLineItems">
                                  <?php
                                  $currentSection = '__UNSET__';
                                  foreach ($lineItems as $item):
                                      $itemSection = $item['section_name'] ?? null;
                                      if ($itemSection !== $currentSection):
                                          $currentSection = $itemSection;
                                          if ($itemSection !== null):
                                  ?>
                                  <tr class="mw-section-header-row" data-section="<?php echo htmlspecialchars($itemSection); ?>">
                                      <td colspan="6">
                                          <span class="mw-section-label">
                                              <i data-feather="layers" style="width: 13px; height: 13px; margin-right: 5px; vertical-align: middle;"></i><?php echo htmlspecialchars($itemSection); ?>
                                          </span>
                                      </td>
                                  </tr>
                                  <?php endif; endif; ?>
                                  <tr class="mw-sortable-row"
                                      data-item-id="<?php echo (int)$item['id']; ?>"
                                      data-section="<?php echo htmlspecialchars($item['section_name'] ?? ''); ?>"
                                      draggable="true">
                                      <td class="mw-drag-col"><span class="mw-drag-handle" title="Drag to reorder">⠿</span></td>
                                      <td><?php echo htmlspecialchars($item['service_type']); ?></td>
                                      <td><?php echo htmlspecialchars($item['description'] ?: '-'); ?></td>
                                      <td><?php echo $item['quantity']; ?></td>
                                      <td class="text-right mw-amount"><?php echo formatCurrency($item['unit_price']); ?></td>
                                      <td class="text-right mw-amount"><?php echo formatCurrency($item['line_total']); ?></td>
                                  </tr>
                                  <?php endforeach; ?>
                              </tbody>
                          </table>

                          <div class="mw-totals" style="padding: 16px 20px;">
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
                          <?php if (!empty($quote['email_opened_at'])): ?>
                              <div class="mw-detail-row">
                                  <span class="mw-detail-label">Email Opened</span>
                                  <span class="mw-detail-value"><?php echo formatDateTime($quote['email_opened_at']); ?></span>
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

                  <!-- Follow-Up Status Card (shown for sent/declined quotes) -->
                  <?php if (in_array($quote['status'], ['sent', 'declined', 'expired'])): ?>
                  <?php
                      $fuDaysSent    = $quote['sent_at'] ? (int)floor((time() - strtotime($quote['sent_at'])) / 86400) : null;
                      $fuEmailOpened = !empty($quote['email_opened_at']);
                      $fuViewed      = !empty($quote['viewed_at']) || !empty($quote['last_viewed_at']);
                      $fuCount       = (int)($quote['follow_up_count'] ?? 0);
                      $fuLastSent    = $quote['follow_up_sent_at'] ?? null;

                      // Urgency colour
                      $fuCardClass = '';
                      if ($quote['status'] === 'sent' && $fuDaysSent !== null) {
                          if ($fuDaysSent >= 7)     $fuCardClass = 'mw-followup-card-urgent';
                          elseif ($fuDaysSent >= 3) $fuCardClass = 'mw-followup-card-warning';
                          else                      $fuCardClass = 'mw-followup-card-ok';
                      }
                  ?>
                  <div class="card mw-followup-status-card <?php echo $fuCardClass; ?>">
                      <div class="card-header d-flex justify-content-between align-items-center">
                          <h5 class="card-title mb-0">
                              <i data-feather="activity" style="width:15px;height:15px;vertical-align:-2px;" class="mr-1"></i>
                              Follow-Up Status
                          </h5>
                          <?php if ($quote['status'] === 'sent'): ?>
                          <?php if ($fuDaysSent !== null && $fuDaysSent >= 7): ?>
                              <span class="badge badge-danger">Urgent</span>
                          <?php elseif ($fuDaysSent !== null && $fuDaysSent >= 3): ?>
                              <span class="badge badge-warning">Needs Follow-Up</span>
                          <?php else: ?>
                              <span class="badge badge-success">On Track</span>
                          <?php endif; ?>
                          <?php endif; ?>
                      </div>
                      <div class="card-body">
                          <?php if ($fuDaysSent !== null): ?>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Days Since Sent</span>
                              <span class="mw-detail-value">
                                  <strong><?php echo $fuDaysSent; ?></strong> day<?php echo $fuDaysSent === 1 ? '' : 's'; ?>
                              </span>
                          </div>
                          <?php endif; ?>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Email Opened</span>
                              <span class="mw-detail-value">
                                  <?php if ($fuEmailOpened): ?>
                                      <span style="color: var(--mw-green);">✓ Yes</span>
                                      <small class="text-muted d-block"><?php echo formatDateTime($quote['email_opened_at']); ?></small>
                                  <?php else: ?>
                                      <span class="text-muted">Not yet</span>
                                  <?php endif; ?>
                              </span>
                          </div>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Quote Viewed</span>
                              <span class="mw-detail-value">
                                  <?php if ($fuViewed): ?>
                                      <span style="color: var(--mw-green);">✓ <?php echo (int)($quote['view_count'] ?? 0); ?>x</span>
                                      <small class="text-muted d-block">Last: <?php echo formatDateTime($quote['last_viewed_at'] ?? $quote['viewed_at']); ?></small>
                                  <?php else: ?>
                                      <span class="text-muted">Not yet</span>
                                  <?php endif; ?>
                              </span>
                          </div>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Follow-Ups Sent</span>
                              <span class="mw-detail-value">
                                  <?php if ($fuCount > 0): ?>
                                      <strong><?php echo $fuCount; ?></strong>
                                      <?php if ($fuLastSent): ?>
                                          <small class="text-muted d-block">Last: <?php echo formatDateTime($fuLastSent); ?></small>
                                      <?php endif; ?>
                                  <?php else: ?>
                                      <span class="text-muted">None yet</span>
                                  <?php endif; ?>
                              </span>
                          </div>
                          <?php if ($quote['status'] === 'sent' && $fuDaysSent !== null && $fuDaysSent >= 2): ?>
                          <div class="mt-3">
                              <form method="POST" class="d-inline">
                                  <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                  <button type="submit" name="action" value="send_followup"
                                          class="btn btn-sm btn-warning btn-block"
                                          onclick="return confirm('Send a follow-up email for quote <?php echo htmlspecialchars($quote['quote_number']); ?>?')">
                                      <i data-feather="bell" style="width:13px;height:13px;"></i>
                                      Send Follow-Up<?php echo $fuCount > 0 ? ' #' . ($fuCount + 1) : ''; ?>
                                  </button>
                              </form>
                          </div>
                          <?php elseif ($quote['status'] === 'sent'): ?>
                          <p class="text-muted mt-2 mb-0" style="font-size: 12px;">
                              Follow-up available after 2 days.
                          </p>
                          <?php endif; ?>
                      </div>
                  </div>
                  <?php endif; ?>

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
                                         value="<?php echo htmlspecialchars(trim(($quote['qr_first_name'] ?? $quote['contact_first'] ?? $quote['prop_contact_first'] ?? '') . ' ' . ($quote['qr_last_name'] ?? $quote['contact_last'] ?? $quote['prop_contact_last'] ?? ''))); ?>"
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
              $editContactId = $quote['qr_contact_id'] ?? $quote['contact_id'] ?? $quote['prop_contact_id'] ?? null;
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

            // --- Delete Confirmation ---
            function confirmDeleteQuote() {
              if (!confirm('Delete quote <?php echo htmlspecialchars($quote['quote_number']); ?>? This cannot be undone.')) return;
              const form = document.createElement('form');
              form.method = 'POST';
              form.innerHTML = '<input type="hidden" name="csrf_token" value="' + csrfToken + '">' +
                               '<input type="hidden" name="action" value="delete">';
              document.body.appendChild(form);
              form.submit();
            }

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

            // ── Drag-and-drop line item reordering ─────────────────────────────
            (function () {
              const tbody = document.getElementById('sortableLineItems');
              if (!tbody) return;

              let dragSrc = null;

              // Walk backwards to find the section this row belongs to
              function getRowSection(row) {
                let el = row.previousElementSibling;
                while (el) {
                  if (el.classList.contains('mw-section-header-row')) return el.dataset.section || '';
                  if (el.classList.contains('mw-sortable-row'))       return el.dataset.section || '';
                  el = el.previousElementSibling;
                }
                return '';
              }

              function initRow(row) {
                row.addEventListener('dragstart', function (e) {
                  dragSrc = row;
                  e.dataTransfer.effectAllowed = 'move';
                  e.dataTransfer.setData('text/plain', row.dataset.itemId);
                  setTimeout(() => row.classList.add('mw-dragging'), 0);
                });

                row.addEventListener('dragend', function () {
                  row.classList.remove('mw-dragging');
                  tbody.querySelectorAll('.mw-drop-before, .mw-drop-after').forEach(r => r.classList.remove('mw-drop-before', 'mw-drop-after'));
                  dragSrc = null;
                });

                row.addEventListener('dragover', function (e) {
                  e.preventDefault();
                  e.dataTransfer.dropEffect = 'move';
                  if (row !== dragSrc) {
                    tbody.querySelectorAll('.mw-drop-before, .mw-drop-after').forEach(r => r.classList.remove('mw-drop-before', 'mw-drop-after'));
                    const rect = row.getBoundingClientRect();
                    row.classList.add(e.clientY < rect.top + rect.height / 2 ? 'mw-drop-before' : 'mw-drop-after');
                  }
                });

                row.addEventListener('drop', function (e) {
                  e.preventDefault();
                  if (!dragSrc || dragSrc === row) return;
                  row.classList.remove('mw-drop-before', 'mw-drop-after');
                  const rect = row.getBoundingClientRect();
                  if (e.clientY < rect.top + rect.height / 2) {
                    tbody.insertBefore(dragSrc, row);
                  } else {
                    row.after(dragSrc);
                  }
                  dragSrc.dataset.section = getRowSection(dragSrc);
                  saveOrder();
                });
              }

              tbody.querySelectorAll('.mw-sortable-row').forEach(initRow);

              // Dropping onto a section header places the item as the first in that section
              tbody.querySelectorAll('.mw-section-header-row').forEach(function (sectionRow) {
                sectionRow.addEventListener('dragover', function (e) {
                  e.preventDefault();
                  e.dataTransfer.dropEffect = 'move';
                  sectionRow.classList.add('mw-drag-over-section');
                });
                sectionRow.addEventListener('dragleave', function () {
                  sectionRow.classList.remove('mw-drag-over-section');
                });
                sectionRow.addEventListener('drop', function (e) {
                  e.preventDefault();
                  sectionRow.classList.remove('mw-drag-over-section');
                  if (!dragSrc) return;
                  sectionRow.after(dragSrc);
                  dragSrc.dataset.section = sectionRow.dataset.section;
                  saveOrder();
                });
              });

              function saveOrder() {
                const allRows = tbody.querySelectorAll('tr');
                const items = [];
                let currentSection = '';
                let sortIndex = 0;

                allRows.forEach(function (row) {
                  if (row.classList.contains('mw-section-header-row')) {
                    currentSection = row.dataset.section || '';
                  } else if (row.classList.contains('mw-sortable-row')) {
                    items.push({
                      id: parseInt(row.dataset.itemId),
                      sort_order: sortIndex++,
                      section_name: currentSection || null
                    });
                  }
                });

                showReorderStatus('saving');

                fetch('../api/quote-reorder.php', {
                  method: 'POST',
                  headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                  },
                  body: JSON.stringify({ quote_id: quoteId, items: items, csrf_token: csrfToken })
                })
                .then(r => r.json())
                .then(data => showReorderStatus(data.success ? 'saved' : 'error'))
                .catch(() => showReorderStatus('error'));
              }

              function showReorderStatus(status) {
                const el = document.getElementById('mw-reorder-status');
                if (!el) return;
                if (status === 'saving') {
                  el.textContent = 'Saving…';
                  el.className = 'mw-reorder-status mw-reorder-saving';
                  el.style.display = 'inline-block';
                } else if (status === 'saved') {
                  el.textContent = '✓ Saved';
                  el.className = 'mw-reorder-status mw-reorder-saved';
                  el.style.display = 'inline-block';
                  setTimeout(() => { el.style.display = 'none'; }, 2000);
                } else {
                  el.textContent = '⚠ Error saving';
                  el.className = 'mw-reorder-status mw-reorder-error';
                  el.style.display = 'inline-block';
                  setTimeout(() => { el.style.display = 'none'; }, 3000);
                }
              }
            })();
          </script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
