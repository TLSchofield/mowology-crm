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
        COALESCE(NULLIF(c.billing_phone,''), NULLIF(dc.mobile,''), NULLIF(dc.phone,''), NULLIF(ct.mobile,''), NULLIF(ct.phone,'')) as billing_phone,
        COALESCE(NULLIF(i.billing_address,''), NULLIF(c.billing_address,''))       as billing_address,
        COALESCE(NULLIF(i.billing_city,''),    NULLIF(c.billing_city,''))           as billing_city,
        COALESCE(NULLIF(i.billing_province,''),NULLIF(c.billing_province,''))       as billing_province,
        COALESCE(NULLIF(i.billing_postal_code,''),NULLIF(c.billing_postal_code,'')) as billing_postal_code,
        COALESCE(ct.first_name, dc.first_name) as contact_first,
        COALESCE(ct.last_name, dc.last_name) as contact_last,
        COALESCE(ct.email, dc.email) as contact_email,
        COALESCE(NULLIF(ct.mobile,''), NULLIF(ct.phone,''), NULLIF(dc.mobile,''), NULLIF(dc.phone,'')) as contact_phone,
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

// When the invoice has no direct company_id but is linked to a plan that has a
// billing company, pull that company so Bill To shows the right name/email.
if (empty($invoice['company_name']) && !empty($invoice['plan_id'])) {
    $cFallback = $db->prepare("
        SELECT co.id, co.company_name,
               co.billing_email, co.billing_phone,
               co.billing_address, co.billing_city, co.billing_province, co.billing_postal_code
        FROM job_plans jp
        JOIN companies co ON jp.company_id = co.id
        WHERE jp.id = ?
        LIMIT 1
    ");
    $cFallback->execute([$invoice['plan_id']]);
    $planCo = $cFallback->fetch(PDO::FETCH_ASSOC);
    if ($planCo) {
        $invoice['company_name']         = $planCo['company_name'];
        $invoice['_plan_company_id']     = $planCo['id'];
        $invoice['billing_email']        = $planCo['billing_email'] ?: $invoice['billing_email'];
        $invoice['billing_phone']        = $planCo['billing_phone'] ?: $invoice['billing_phone'];
        $invoice['billing_address']      = $invoice['billing_address'] ?: $planCo['billing_address'];
        $invoice['billing_city']         = $invoice['billing_city']    ?: $planCo['billing_city'];
        $invoice['billing_province']     = $invoice['billing_province'] ?: $planCo['billing_province'];
        $invoice['billing_postal_code']  = $invoice['billing_postal_code'] ?: $planCo['billing_postal_code'];
    }
}

// Get line items
$stmt = $db->prepare("SELECT * FROM invoice_line_items WHERE invoice_id = ? ORDER BY sort_order");
$stmt->execute([$invoiceId]);
$lineItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Phase 2-3: Get invoice recipients.
// Resolve a blank snapshot email against the contact's live email so the table
// shows where the invoice will actually go (matches the send-path fallback).
$stmt = $db->prepare("
    SELECT ic.id, ic.contact_id,
           COALESCE(NULLIF(ic.email_address, ''), c.email) AS email_address,
           ic.contact_role,
           ic.invoice_sent_at, ic.invoice_opened_at, ic.bounced,
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

// Build portal URL — refresh token if missing or expired
$_tokenExpired = !empty($invoice['token_expires_at']) && strtotime($invoice['token_expires_at']) < time();
if (empty($invoice['access_token']) || $_tokenExpired) {
    $invoice['access_token'] = generateAccessToken();
    $db->prepare("UPDATE invoices SET access_token = ?, token_expires_at = DATE_ADD(NOW(), INTERVAL 90 DAY) WHERE id = ?")
       ->execute([$invoice['access_token'], $invoiceId]);
}
$invoicePortalUrl = 'https://mowology.ca/customer/invoice.php?token=' . urlencode($invoice['access_token']);

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

    if ($action === 'delete_invoice') {
        if (!userHasPermission('billing.edit')) {
            $message = 'You do not have permission to delete invoices.';
            $messageType = 'danger';
        } elseif (!in_array($invoice['status'], ['draft', 'cancelled'])) {
            $message = 'Only draft or cancelled invoices can be deleted.';
            $messageType = 'danger';
        } else {
            try {
                $db->beginTransaction();
                $db->prepare("DELETE FROM invoice_line_items WHERE invoice_id = ?")->execute([$invoiceId]);
                $db->prepare("DELETE FROM invoice_contacts   WHERE invoice_id = ?")->execute([$invoiceId]);
                $db->prepare("DELETE FROM invoices           WHERE id = ?")->execute([$invoiceId]);
                $db->commit();
                header('Location: index.php?deleted=1');
                exit;
            } catch (Throwable $e) {
                $db->rollBack();
                error_log('Invoice delete error: ' . $e->getMessage());
                $message = 'Error deleting invoice. Please try again.';
                $messageType = 'danger';
            }
        }
    }

    if ($action === 'cancel_invoice') {
        if (!userHasPermission('billing.edit')) {
            $message = 'You do not have permission to cancel invoices.';
            $messageType = 'danger';
        } elseif ($invoice['status'] === 'paid') {
            $message = 'Paid invoices cannot be cancelled. Issue a credit note instead.';
            $messageType = 'danger';
        } elseif ($invoice['status'] === 'cancelled') {
            $message = 'Invoice is already cancelled.';
            $messageType = 'warning';
        } else {
            $reason = trim($_POST['cancellation_reason'] ?? '');
            if (empty($reason)) {
                $message = 'A reason is required to cancel this invoice.';
                $messageType = 'danger';
            } else {
                try {
                    $oldStatus = $invoice['status'];
                    $db->prepare("UPDATE invoices SET status = 'cancelled' WHERE id = ?")
                       ->execute([$invoiceId]);
                    trackFieldChange('invoice', $invoiceId, 'status', $oldStatus, 'cancelled', $user['id']);
                    logActivityExtended($user['id'], 'Invoice cancelled',
                        "Cancelled (was {$oldStatus}). Reason: {$reason}",
                        null, null, null, $invoiceId);
                    $invoice['status'] = 'cancelled';
                    $message     = 'Invoice cancelled. You can now delete it if needed.';
                    $messageType = 'success';
                } catch (Throwable $e) {
                    error_log('Invoice cancel error: ' . $e->getMessage());
                    $message     = 'Error cancelling invoice. Please try again.';
                    $messageType = 'danger';
                }
            }
        }
    }

    if ($action === 'send') {
        // Load recipients FIRST — status only updates if emails actually go out.
        // Fall back to the contact's live email when the invoice_contacts snapshot
        // is blank (e.g. recipient added before the contact had an email, or via a
        // path that didn't capture it) so a valid contact is never silently skipped.
        $stmt = $db->prepare("
            SELECT ic.id, ic.contact_id,
                   COALESCE(NULLIF(ic.email_address, ''), c.email) AS email_address,
                   ic.contact_role,
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

        // Fallback: manually-created invoices may have no invoice_contacts rows at all.
        // Rather than fail with "No valid recipients", send to the invoice's own Bill To
        // contact and persist it as a recipient row so tracking/resends work afterward.
        // When a company is linked and has a billing_email, use that — not the primary
        // contact's personal email — so property-managed / strata invoices route to the
        // management company (e.g. BCS4428@opml.ca) rather than the on-site rep.
        $hasEmail = false;
        foreach ($recipients as $r) { if (!empty($r['email_address'])) { $hasEmail = true; break; } }
        if (!$hasEmail) {
            $useCompanyBilling = !empty($invoice['company_id']) && !empty($invoice['billing_email']);
            $fallbackEmail     = $useCompanyBilling ? $invoice['billing_email'] : ($invoice['contact_email'] ?? '');
            $fallbackContactId = $useCompanyBilling ? null : ($invoice['contact_id'] ?? null);
            $fallbackRole      = $useCompanyBilling ? 'billing_contact' : 'primary_recipient';
            $fallbackFirst     = $useCompanyBilling ? ($invoice['company_name'] ?? '') : ($invoice['contact_first'] ?? '');
            $fallbackLast      = $useCompanyBilling ? '' : ($invoice['contact_last'] ?? '');

            if (!empty($fallbackEmail)) {
                $newRid = null;
                try {
                    $db->prepare("
                        INSERT INTO invoice_contacts (invoice_id, contact_id, contact_role, email_address)
                        VALUES (?, ?, ?, ?)
                    ")->execute([$invoiceId, $fallbackContactId, $fallbackRole, $fallbackEmail]);
                    $newRid = (int)$db->lastInsertId();
                } catch (Throwable $e) {
                    error_log("Invoice {$invoiceId} recipient fallback insert failed: " . $e->getMessage());
                }
                $recipients[] = [
                    'id'            => $newRid ?: 0,
                    'contact_id'    => $fallbackContactId,
                    'email_address' => $fallbackEmail,
                    'contact_role'  => $fallbackRole,
                    'first_name'    => $fallbackFirst,
                    'last_name'     => $fallbackLast,
                    'receive_sms'   => 0,
                    'sms_phone'     => null,
                ];
            }
        }

        // Generate PDF once (used for all recipients).
        // We always regenerate on send so the PDF reflects the latest invoice state
        // (line items, totals, bill-to) — caching stale PDFs confuses customers.
        $attachPath = null;
        require_once dirname(__DIR__) . '/includes/pdf_bootstrap.php';
        require_once dirname(__DIR__) . '/includes/PdfGenerator.php';

        $pdfGen    = new PdfGenerator();
        $pdfResult = $pdfGen->generateInvoicePdf($invoiceId);
        if (!empty($pdfResult['success']) && !empty($pdfResult['path']) && file_exists($pdfResult['path'])) {
            $attachPath = $pdfResult['path'];
        } else {
            // Fall back to any cached copy so the email still has an attachment
            $cached = $pdfGen->getPdfPath('invoice', $invoiceId);
            if ($cached && file_exists($cached)) {
                $attachPath = $cached;
            }
            error_log("Invoice send: PDF generation failed for invoice {$invoiceId}: " . ($pdfResult['error'] ?? 'unknown') . ($attachPath ? ' — using cached copy' : ' — sending WITHOUT attachment'));
        }

        // Ensure the invoice has a valid (non-expired) access_token
        $tokenExpired = !empty($invoice['token_expires_at']) && strtotime($invoice['token_expires_at']) < time();
        if (empty($invoice['access_token']) || $tokenExpired) {
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
        $invoiceViewUrl   = 'https://mowology.ca/customer/invoice.php?token=' . urlencode($invoice['access_token']);
        $invoicePdfUrl    = 'https://mowology.ca/customer/api/invoice-pdf.php?token=' . urlencode($invoice['access_token']);
        $invoicePrintUrl  = $invoicePdfUrl . '&inline=1';

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

            require_once APP_ROOT . '/Services/Messaging/EmailWrapper.php';

            $firstName   = !empty($recipient['first_name']) ? $recipient['first_name'] : 'there';
            $companyInfo = EmailWrapper::getCompanyInfo();

            $tplVars = [
                '{{customer_first_name}}' => $firstName,
                '{{customer_name}}'       => $recipientName,
                '{{invoice_number}}'      => $invoice['invoice_number'],
                '{{amount_due}}'          => formatCurrency($invoice['balance_due']),
                '{{due_date}}'            => formatDate($invoice['due_date']),
                '{{company_name}}'        => $companyInfo['company_name'],
                '{{company_phone}}'       => $companyInfo['company_phone'],
            ];

            $tpl          = loadEmailTemplate('invoice_sent', $tplVars);
            $emailSubject = $tpl['subject'];

            // Append billing summary table to the template body
            $billSummary  = '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:460px;margin:0 0 20px;font-size:14px;font-family:\'Helvetica Neue\',Arial,sans-serif;">';
            $billSummary .= '<tr><td style="padding:6px 0;color:#4a6b5d;width:120px;">Invoice #</td><td style="padding:6px 0;color:#0D3B2E;font-weight:700;">' . htmlspecialchars($invoice['invoice_number']) . '</td></tr>';
            $billSummary .= '<tr><td style="padding:6px 0;color:#4a6b5d;">Amount Due</td><td style="padding:6px 0;color:#0D3B2E;font-size:18px;font-weight:700;">' . formatCurrency($invoice['balance_due']) . ' CAD</td></tr>';
            $billSummary .= '<tr><td style="padding:6px 0;color:#4a6b5d;">Due Date</td><td style="padding:6px 0;color:#0D3B2E;">' . formatDate($invoice['due_date']) . '</td></tr>';
            if (!empty($billToLines)) {
                $billSummary .= '<tr><td style="padding:6px 0;color:#4a6b5d;vertical-align:top;">Bill To</td><td style="padding:6px 0;color:#0D3B2E;">' . implode('<br>', array_map('htmlspecialchars', $billToLines)) . '</td></tr>';
            }
            $billSummary .= '</table>';

            $emailBody = EmailWrapper::wrap(
                $billSummary . $tpl['body_html'],
                'View &amp; Pay Invoice Online',
                $invoiceViewUrl ?: null,
                $companyInfo
            );

            // Append email open tracking pixel (per-recipient)
            $trackPixelUrl = 'https://mowology.ca/crm/api/track-invoice-open.php?rid=' . (int)$recipient['id'];
            $emailBody = str_replace(
                '</body>',
                '<img src="' . $trackPixelUrl . '" width="1" height="1" alt="" style="display:block;height:1px;width:1px;border:0;" /></body>',
                $emailBody
            );

            // Send email
            $emailResult = sendCrmEmail($recipient['email_address'], $emailSubject, $emailBody, $attachPath);
            if ($emailResult) {
                $sentTo[] = $recipientName;

                // Update invoice_contacts with send timestamp and persist the
                // resolved email back into the snapshot so the recipients table
                // reflects exactly where it went (self-heals blank rows).
                $updateStmt = $db->prepare("
                    UPDATE invoice_contacts
                    SET invoice_sent_at = NOW(), email_address = ?
                    WHERE id = ?
                ");
                $updateStmt->execute([$recipient['email_address'], $recipient['id']]);

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
            // Distinguish a first send from a manual resend.
            // Resend = the invoice already has a sent_at timestamp.
            // First-send: stamp sent_at + flip status to 'sent'.
            // Resend:     bump resend_count + last_resent_at, leave
            //             sent_at and status alone so the original
            //             timeline is preserved.
            $isResend = !empty($invoice['sent_at']);

            if ($isResend) {
                // If status somehow regressed to draft (e.g. line items edited after send),
                // restore it to 'sent' on resend so the list is accurate.
                $resendSql = ($invoice['status'] === 'draft')
                    ? "SET status = 'sent', resend_count = COALESCE(resend_count, 0) + 1, last_resent_at = NOW()"
                    : "SET resend_count = COALESCE(resend_count, 0) + 1, last_resent_at = NOW()";
                $db->prepare("UPDATE invoices {$resendSql} WHERE id = ?")->execute([$invoiceId]);
                if ($invoice['status'] === 'draft') {
                    trackFieldChange('invoice', $invoiceId, 'status', 'draft', 'sent', $user['id']);
                    $invoice['status'] = 'sent';
                }
                // Refresh in-memory copy so the Engagement panel renders
                // the new counter + timestamp without an extra round trip.
                $invoice['resend_count']   = (int)($invoice['resend_count'] ?? 0) + 1;
                $invoice['last_resent_at'] = date('Y-m-d H:i:s');
            } else {
                $oldStatus = $invoice['status'] ?? 'draft';
                $db->prepare("
                    UPDATE invoices
                    SET status = 'sent', sent_at = NOW()
                    WHERE id = ? AND status IN ('draft', 'sent')
                ")->execute([$invoiceId]);
                trackFieldChange('invoice', $invoiceId, 'status', $oldStatus, 'sent', $user['id']);
                $invoice['status'] = 'sent';
                $autopayJustSent   = true;   // first send → trigger autopay charge below
            }

            $recipientList = implode(', ', $sentTo);
            $verb          = $isResend ? 'resent' : 'sent';
            $actionLabel   = $isResend ? 'Invoice resent' : 'Invoice sent';
            $details       = "Invoice {$verb} to {$recipientList}{$attachNote}";
            if (!empty($smsSentTo)) {
                $details .= "; SMS sent to: " . implode(', ', $smsSentTo);
            }
            logActivityExtended($user['id'], $actionLabel, $details, null, null, null, $invoiceId);

            $messageVerb = $isResend ? 'resent' : 'sent';
            $message     = "Invoice {$messageVerb} successfully to " . count($sentTo) . " recipient(s)";
            if (!empty($smsSentTo)) {
                $message .= " and SMS sent to " . count($smsSentTo) . " contact(s)";
            }
            $messageType = 'success';
        } else {
            // Distinguish "no recipients at all" from "recipients exist but email delivery failed"
            $hasAnyRecipientWithEmail = false;
            foreach ($recipients as $r) { if (!empty($r['email_address'])) { $hasAnyRecipientWithEmail = true; break; } }
            if ($hasAnyRecipientWithEmail) {
                $message = 'Email delivery failed for all recipients. Use "Mark as Sent" below to update the status manually.';
            } else {
                $message = 'No valid recipients found. Please add recipients to this invoice first, or use "Mark as Sent" to update the status without emailing.';
            }
            $messageType = 'warning';
        }
    }

    // Mark invoice as sent without emailing (manual status override)
    if ($action === 'mark_sent' && $invoice['status'] === 'draft') {
        $oldStatus = $invoice['status'];
        $db->prepare("UPDATE invoices SET status = 'sent', sent_at = COALESCE(sent_at, NOW()) WHERE id = ?")
           ->execute([$invoiceId]);
        trackFieldChange('invoice', $invoiceId, 'status', $oldStatus, 'sent', $user['id']);
        logActivityExtended($user['id'], 'Invoice marked sent', 'Status manually set to sent (no email)', null, null, null, $invoiceId);
        $invoice['status'] = 'sent';
        $autopayJustSent   = true;   // manual mark-sent → trigger autopay charge below
        $message     = 'Invoice marked as sent.';
        $messageType = 'success';
    }

    // ── Autopay: when an invoice first transitions to 'sent', immediately
    //    attempt an off-session charge if the bill-to is enrolled. Mirrors the
    //    schedule auto-invoice path (app/Modules/Schedule/Api/invoice-create-send.php).
    //    AutopayService self-guards on live-mode, payable status, enrollment,
    //    saved payment method, and balance > 0. The is_file() guard keeps this a
    //    no-op anywhere the service is not deployed (e.g. local dev).
    if (!empty($autopayJustSent)) {
        $autopayServicePath = APP_ROOT . '/Services/Payments/AutopayService.php';
        if (is_file($autopayServicePath)) {
            try {
                require_once $autopayServicePath;
                $autopay = new AutopayService($db);
                if ($autopay->isAutopayEligible($invoiceId)) {
                    $apResult = $autopay->attemptCharge($invoiceId);
                    error_log('[Autopay] view.php send invoice ' . $invoiceId . ' → '
                        . ($apResult['status'] ?? '?') . ': ' . ($apResult['message'] ?? ''));
                }
            } catch (Throwable $e) {
                error_log('[Autopay] view.php charge trigger failed for invoice ' . $invoiceId . ': ' . $e->getMessage());
            }
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
        // 0.5¢ tolerance handles floating-point rounding (e.g. $100.00 - $100.00 = $0.000001)
        $newStatus  = $newBalance <= 0.005 ? 'paid' : 'partial';

        $params = [$paymentAmount, $newBalance, $newStatus, $paymentMethod, $paymentReference];
        if ($paidAtParam) $params[] = $paidAtParam;
        $params[] = $invoiceId;

        $db->beginTransaction();
        try {
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

            // Track field changes for payment
            trackFieldChange('invoice', $invoiceId, 'status', $invoice['status'], $newStatus, $user['id']);
            trackFieldChange('invoice', $invoiceId, 'amount_paid', $invoice['amount_paid'], (string)($invoice['amount_paid'] + $paymentAmount), $user['id']);
            trackFieldChange('invoice', $invoiceId, 'balance_due', $invoice['balance_due'], (string)$newBalance, $user['id']);

            $methodLabel = ['e_transfer'=>'e-Transfer','cash'=>'Cash','cheque'=>'Cheque','credit_card'=>'Credit Card','other'=>'Other'][$paymentMethod] ?? ucfirst($paymentMethod);
            $detail = "Payment of " . formatCurrency($paymentAmount) . " via {$methodLabel}";
            if ($paymentReference) $detail .= " (Ref: {$paymentReference})";
            logActivityExtended($user['id'], 'Payment recorded', $detail, null, null, null, $invoiceId);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            error_log("mark_paid error for invoice {$invoiceId}: " . $e->getMessage());
            $message = 'Payment could not be recorded due to a system error. Please try again.';
            $messageType = 'error';
            goto end_mark_paid;
        }

        // ── Send receipt email (non-blocking — payment success must never depend on this) ──
        if (!empty($invoice['contact_email'])) {
            try {
                require_once APP_ROOT . '/Services/Messaging/EmailWrapper.php';

                // Ensure access token exists for CTA link
                if (empty($invoice['access_token'])) {
                    $receiptToken = bin2hex(random_bytes(32));
                    $db->prepare("UPDATE invoices SET access_token = ?, token_expires_at = DATE_ADD(NOW(), INTERVAL 90 DAY) WHERE id = ?")
                       ->execute([$receiptToken, $invoiceId]);
                    $invoice['access_token'] = $receiptToken;
                }

                $receiptCompany = EmailWrapper::getCompanyInfo();
                $paidOn         = $paidAtParam
                    ? date('F j, Y', strtotime($paidAtParam))
                    : date('F j, Y');
                $receiptFirst   = $invoice['contact_first'] ?: 'there';
                $receiptName    = trim(($invoice['contact_first'] ?? '') . ' ' . ($invoice['contact_last'] ?? '')) ?: 'Valued Customer';

                $receiptVars = [
                    '{{customer_first_name}}' => $receiptFirst,
                    '{{customer_name}}'       => $receiptName,
                    '{{invoice_number}}'      => $invoice['invoice_number'],
                    '{{amount_paid}}'         => formatCurrency($paymentAmount),
                    '{{payment_date}}'        => $paidOn,
                    '{{company_name}}'        => $receiptCompany['company_name'],
                    '{{company_phone}}'       => $receiptCompany['company_phone'],
                ];

                $receiptTpl  = loadEmailTemplate('receipt_sent', $receiptVars);
                $receiptUrl  = 'https://mowology.ca/customer/invoice.php?token=' . urlencode($invoice['access_token']);
                $receiptBody = EmailWrapper::wrap(
                    $receiptTpl['body_html'],
                    'View Your Receipt',
                    $receiptUrl,
                    $receiptCompany
                );
                sendEmail($invoice['contact_email'], $receiptTpl['subject'], $receiptBody);
            } catch (Throwable $receiptEx) {
                error_log("Receipt email failed for invoice {$invoiceId}: " . $receiptEx->getMessage());
            }
        }

        $invoice['status']      = $newStatus;
        $invoice['balance_due'] = $newBalance;
        $message     = "Payment of " . formatCurrency($paymentAmount) . " recorded successfully.";
        $messageType = 'success';
        end_mark_paid:
    }
}

$csrfToken = generateCSRFToken();

// Check for success messages
if (!$message && isset($_GET['created'])) {
    $message = 'Invoice created successfully!';
    $messageType = 'success';
}
if (!$message && isset($_GET['pdf_generated'])) {
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
                  <!-- Copy customer portal link -->
                  <button type="button" class="btn btn-outline-secondary" id="mw-copy-link-btn"
                          onclick="mwCopyInvoiceLink(this)"
                          title="Copy customer portal link to clipboard">
                      <i data-feather="link" class="mr-1"></i> Copy Link
                  </button>

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

                  <?php if ($invoice['status'] !== 'paid' && userHasPermission('billing.edit')): ?>
                      <a href="edit.php?id=<?php echo $invoiceId; ?>" class="btn btn-outline-primary" title="Edit this invoice">
                          <i data-feather="edit" class="mr-1"></i> Edit
                      </a>
                  <?php endif; ?>

                  <?php if (in_array($invoice['status'], ['draft', 'cancelled']) && userHasPermission('billing.edit')): ?>
                      <form method="POST" class="d-inline"
                            onsubmit="return confirm('Permanently delete <?php echo htmlspecialchars($invoice['invoice_number']); ?>? This cannot be undone.');">
                          <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                          <input type="hidden" name="action" value="delete_invoice">
                          <button type="submit" class="btn btn-outline-danger" title="Delete this invoice">
                              <i data-feather="trash-2" class="mr-1"></i> Delete
                          </button>
                      </form>
                  <?php endif; ?>

                  <?php if (in_array($invoice['status'], ['sent', 'viewed', 'overdue', 'partial']) && userHasPermission('billing.edit')): ?>
                      <button type="button" class="btn btn-outline-warning" onclick="openCancelModal()" title="Cancel this invoice (CRA-compliant — keeps the audit trail)">
                          <i data-feather="x-circle" class="mr-1"></i> Cancel Invoice
                      </button>
                  <?php endif; ?>

                  <?php if ($invoice['status'] !== 'paid' && $invoice['status'] !== 'cancelled'): ?>
                      <form method="POST" class="d-inline">
                          <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                          <input type="hidden" name="action" value="send">
                          <button type="submit" class="btn btn-<?php echo $invoice['status'] === 'draft' ? 'primary' : 'secondary'; ?>">
                              <i data-feather="send" class="mr-1"></i> <?php echo $invoice['status'] === 'draft' ? 'Send to Customer' : 'Resend'; ?>
                          </button>
                      </form>
                  <?php endif; ?>

                  <?php if ($invoice['status'] === 'draft'): ?>
                      <form method="POST" class="d-inline">
                          <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                          <input type="hidden" name="action" value="mark_sent">
                          <button type="submit" class="btn btn-outline-secondary"
                                  title="Update status to Sent without sending an email"
                                  onclick="return confirm('Mark this invoice as Sent without emailing the customer?');">
                              <i data-feather="check" class="mr-1"></i> Mark as Sent
                          </button>
                      </form>
                  <?php endif; ?>

                  <?php if (in_array($invoice['status'], ['sent', 'viewed', 'partial', 'overdue'])): ?>
                      <button type="button" class="btn btn-primary" onclick="openStripeModal()">
                          <i data-feather="credit-card" class="mr-1"></i> Pay Online
                      </button>
                      <button type="button" class="btn btn-success" onclick="openPaymentModal()">
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
                              $displayCompany  = $invoice['company_name'] ?? '';
                              // Priority: manual bill_to_name override →
                              // company_name → contact full name.
                              $billToHeading   = !empty($invoice['bill_to_name'])
                                  ? $invoice['bill_to_name']
                                  : ($displayCompany ?: $contactFullName);
                          ?>
                          <?php
                              $clientContactId = (int)($invoice['contact_id'] ?? 0);
                              $clientCompanyId = (int)($invoice['company_id'] ?? $invoice['_plan_company_id'] ?? 0);
                              // When a company is shown (directly or via plan), link to the company page.
                              $clientUrl = $displayCompany
                                  ? ($clientCompanyId ? '/crm/companies/view.php?id=' . $clientCompanyId : '')
                                  : ($clientContactId ? '/crm/clients_appstack.php?action=view_contact&id=' . $clientContactId : '');
                          ?>
                          <?php if ($billToHeading): ?>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label"><?php echo !empty($invoice['bill_to_name']) ? 'Billed To' : ($displayCompany ? 'Company' : 'Bill To'); ?></span>
                              <span class="mw-detail-value" style="font-weight:600;">
                                  <?php if ($clientUrl): ?>
                                      <a href="<?php echo $clientUrl; ?>" style="color:var(--mw-green);text-decoration:none;font-weight:600;" title="View client"><?php echo htmlspecialchars($billToHeading); ?></a>
                                  <?php else: ?>
                                      <?php echo htmlspecialchars($billToHeading); ?>
                                  <?php endif; ?>
                              </span>
                          </div>
                          <?php endif; ?>
                          <?php
                              $showContactLine = $contactFullName !== '' && strcasecmp($billToHeading, $contactFullName) !== 0;
                          ?>
                          <?php if ($showContactLine): ?>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Attn</span>
                              <span class="mw-detail-value">
                                  <?php if ($clientUrl): ?>
                                      <a href="<?php echo $clientUrl; ?>" style="color:var(--mw-green);text-decoration:none;" title="View client"><?php echo htmlspecialchars($contactFullName); ?></a>
                                  <?php else: ?>
                                      <?php echo htmlspecialchars($contactFullName); ?>
                                  <?php endif; ?>
                              </span>
                          </div>
                          <?php elseif ($contactFullName === '' && empty($invoice['bill_to_name'])): ?>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Contact</span>
                              <span class="mw-detail-value">N/A</span>
                          </div>
                          <?php endif; ?>
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
                              <?php
                                  // When a company is on the bill, prefer the company billing_email
                                  // (the accounts address) over the site contact's personal email.
                                  $displayEmail = !empty($invoice['company_name'])
                                      ? ($invoice['billing_email'] ?: $invoice['contact_email'] ?: 'N/A')
                                      : ($invoice['contact_email'] ?: $invoice['billing_email'] ?: 'N/A');
                              ?>
                              <span class="mw-detail-value"><?php echo htmlspecialchars($displayEmail); ?></span>
                          </div>
                          <div class="mw-detail-row">
                              <span class="mw-detail-label">Phone</span>
                              <span class="mw-detail-value"><?php echo formatPhone($invoice['contact_phone'] ?: $invoice['billing_phone'] ?: ''); ?></span>
                          </div>
                          <?php if ($svcAddrFull && $svcAddrFull !== $billAddrFull): ?>
                              <div class="mw-detail-row">
                                  <span class="mw-detail-label">Service Address</span>
                                  <span class="mw-detail-value"><?php echo htmlspecialchars($svcAddrFull); ?></span>
                              </div>
                          <?php endif; ?>
                      </div>
                  </div>

                  <!-- Invoice Recipients with Tracking -->
                  <?php if (!empty($invoiceRecipients)): ?>
                      <div class="card">
                          <div class="card-header">
                              <h5 class="card-title mb-0">Invoice Recipients</h5>
                          </div>
                          <div class="card-body p-0">
                              <table class="table table-sm table-bordered mb-0">
                                  <thead class="table-light">
                                      <tr>
                                          <th style="width: 22%;">Contact</th>
                                          <th style="width: 15%;">Role</th>
                                          <th style="width: 25%;">Email</th>
                                          <th style="width: 8%;">SMS</th>
                                          <th style="width: 15%;">Sent</th>
                                          <th style="width: 15%;">Opened</th>
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
                                                  <?php if (!empty($recipient['invoice_sent_at'])): ?>
                                                      <small><?php echo formatDateTime($recipient['invoice_sent_at'], 'M j, g:i A'); ?></small>
                                                  <?php else: ?>
                                                      <span class="text-muted">Pending</span>
                                                  <?php endif; ?>
                                              </td>
                                              <td>
                                                  <?php if (!empty($recipient['invoice_opened_at'])): ?>
                                                      <span class="mw-tracking-badge mw-tracking-opened">Opened</span>
                                                      <br><small class="text-muted"><?php echo formatDateTime($recipient['invoice_opened_at'], 'M j, g:i A'); ?></small>
                                                  <?php elseif (!empty($recipient['invoice_sent_at'])): ?>
                                                      <span class="text-muted">Not opened</span>
                                                  <?php else: ?>
                                                      <span class="text-muted">—</span>
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
                                          <td>
                                              <?php echo htmlspecialchars($item['description'] ?: 'Services rendered'); ?>
                                              <?php if (!empty($item['service_date'])): ?>
                                                  <br><span class="mw-service-date">Service date: <?php echo date('M j, Y', strtotime($item['service_date'])); ?></span>
                                              <?php endif; ?>
                                          </td>
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

                  <!-- Engagement Tracking -->
                  <?php if ($invoice['status'] !== 'draft'): ?>
                  <div class="card">
                      <div class="card-header">
                          <h5 class="card-title mb-0"><i data-feather="activity" style="width:16px;height:16px;margin-right:4px;vertical-align:middle;"></i> Engagement</h5>
                      </div>
                      <div class="card-body">
                          <div class="mw-tracking-stats">
                              <div class="mw-tracking-stat">
                                  <div class="mw-tracking-stat-value"><?php echo (int)($invoice['view_count'] ?? 0); ?></div>
                                  <div class="mw-tracking-stat-label">Portal Views</div>
                              </div>
                              <div class="mw-tracking-stat">
                                  <div class="mw-tracking-stat-value">
                                      <?php if (!empty($invoice['email_opened_at'])): ?>
                                          <span style="color: var(--mw-green);">Yes</span>
                                      <?php else: ?>
                                          <span class="text-muted">No</span>
                                      <?php endif; ?>
                                  </div>
                                  <div class="mw-tracking-stat-label">Email Opened</div>
                              </div>
                              <div class="mw-tracking-stat" title="Times the crew has manually clicked Resend">
                                  <div class="mw-tracking-stat-value"><?php echo (int)($invoice['resend_count'] ?? 0); ?></div>
                                  <div class="mw-tracking-stat-label">Resends</div>
                              </div>
                              <div class="mw-tracking-stat" title="Automated overdue reminders sent by the cron">
                                  <div class="mw-tracking-stat-value"><?php echo (int)($invoice['reminder_count'] ?? 0); ?></div>
                                  <div class="mw-tracking-stat-label">Reminders</div>
                              </div>
                          </div>

                          <div class="mw-tracking-timeline">
                              <?php if (!empty($invoice['created_at'])): ?>
                              <div class="mw-timeline-item">
                                  <div class="mw-timeline-dot mw-dot-created"></div>
                                  <div class="mw-timeline-content">
                                      <div class="mw-timeline-label">Invoice created</div>
                                      <div class="mw-timeline-time"><?php echo formatDateTime($invoice['created_at'], 'M j, Y g:i A'); ?></div>
                                  </div>
                              </div>
                              <?php endif; ?>

                              <?php if (!empty($invoice['sent_at'])): ?>
                              <div class="mw-timeline-item">
                                  <div class="mw-timeline-dot mw-dot-sent"></div>
                                  <div class="mw-timeline-content">
                                      <div class="mw-timeline-label">Sent to customer</div>
                                      <div class="mw-timeline-time"><?php echo formatDateTime($invoice['sent_at'], 'M j, Y g:i A'); ?></div>
                                  </div>
                              </div>
                              <?php endif; ?>

                              <?php if (!empty($invoice['email_opened_at'])): ?>
                              <div class="mw-timeline-item">
                                  <div class="mw-timeline-dot mw-dot-opened"></div>
                                  <div class="mw-timeline-content">
                                      <div class="mw-timeline-label">Email opened</div>
                                      <div class="mw-timeline-time"><?php echo formatDateTime($invoice['email_opened_at'], 'M j, Y g:i A'); ?></div>
                                  </div>
                              </div>
                              <?php endif; ?>

                              <?php if (!empty($invoice['viewed_at'])): ?>
                              <div class="mw-timeline-item">
                                  <div class="mw-timeline-dot mw-dot-viewed"></div>
                                  <div class="mw-timeline-content">
                                      <div class="mw-timeline-label">Viewed in portal<?php echo ((int)($invoice['view_count'] ?? 0)) > 1 ? ' (' . $invoice['view_count'] . ' times)' : ''; ?></div>
                                      <div class="mw-timeline-time">
                                          First: <?php echo formatDateTime($invoice['viewed_at'], 'M j, Y g:i A'); ?>
                                          <?php if (!empty($invoice['last_viewed_at']) && $invoice['last_viewed_at'] !== $invoice['viewed_at']): ?>
                                              <br>Last: <?php echo formatDateTime($invoice['last_viewed_at'], 'M j, Y g:i A'); ?>
                                          <?php endif; ?>
                                      </div>
                                  </div>
                              </div>
                              <?php endif; ?>

                              <?php if ((int)($invoice['resend_count'] ?? 0) > 0 && !empty($invoice['last_resent_at'])): ?>
                              <div class="mw-timeline-item">
                                  <div class="mw-timeline-dot mw-dot-sent"></div>
                                  <div class="mw-timeline-content">
                                      <div class="mw-timeline-label">
                                          Resent by crew
                                          (<?php echo (int)$invoice['resend_count']; ?> time<?php echo $invoice['resend_count'] == 1 ? '' : 's'; ?>)
                                      </div>
                                      <div class="mw-timeline-time">Last: <?php echo formatDateTime($invoice['last_resent_at'], 'M j, Y g:i A'); ?></div>
                                  </div>
                              </div>
                              <?php endif; ?>

                              <?php if (!empty($invoice['last_reminder_sent_at'])): ?>
                              <div class="mw-timeline-item">
                                  <div class="mw-timeline-dot mw-dot-reminder"></div>
                                  <div class="mw-timeline-content">
                                      <div class="mw-timeline-label">Auto-reminder sent (<?php echo (int)$invoice['reminder_count']; ?> total)</div>
                                      <div class="mw-timeline-time"><?php echo formatDateTime($invoice['last_reminder_sent_at'], 'M j, Y g:i A'); ?></div>
                                  </div>
                              </div>
                              <?php endif; ?>

                              <?php if (!empty($invoice['paid_at'])): ?>
                              <div class="mw-timeline-item">
                                  <div class="mw-timeline-dot mw-dot-paid"></div>
                                  <div class="mw-timeline-content">
                                      <div class="mw-timeline-label">Payment received</div>
                                      <div class="mw-timeline-time"><?php echo formatDateTime($invoice['paid_at'], 'M j, Y g:i A'); ?></div>
                                  </div>
                              </div>
                              <?php endif; ?>
                          </div>
                      </div>
                  </div>
                  <?php endif; ?>

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
          <!-- Manual Record Payment Modal v2 — Executive Dark               -->
          <!-- ═══════════════════════════════════════════════════════════════ -->
          <?php
            $pmBalanceFmt  = h(formatCurrency($invoice['balance_due']));
            $pmBalanceRaw  = number_format(floatval($invoice['balance_due']), 2, '.', '');
            $pmClientLabel = h(trim(($invoice['company_name'] ?: '') . ' ' . ($invoice['contact_first'] ?? '') . ' ' . ($invoice['contact_last'] ?? '')));
          ?>
          <div id="paymentModal" class="mw-pm-overlay-v2" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="paymentModalTitle">
              <div class="mw-pm-shell">

                  <!-- Top: invoice ref + close -->
                  <div class="mw-pm-top">
                      <div class="mw-pm-inv-ref">
                          <span class="mw-pm-inv-id"><?php echo h($invoice['invoice_number']); ?></span>
                          <span class="mw-pm-client"><?php echo $pmClientLabel; ?></span>
                      </div>
                      <button type="button" class="mw-pm-close" id="pmCloseBtn" aria-label="Close">&times;</button>
                  </div>

                  <!-- Hero balance -->
                  <div class="mw-pm-hero">
                      <div class="mw-pm-balance-label">Balance Due</div>
                      <div class="mw-pm-balance-amount" id="pmHeroAmount"><?php echo $pmBalanceFmt; ?></div>
                  </div>

                  <!-- Form -->
                  <form id="paymentForm" method="POST">
                      <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                      <input type="hidden" name="action" value="mark_paid">
                      <input type="hidden" id="vm-pay-method-hidden" name="payment_method" value="e_transfer">

                      <div class="mw-pm-body">

                          <!-- Amount + Date -->
                          <div class="mw-pm-fields-row">
                              <div class="mw-pm-field">
                                  <label for="vm-pay-amount">Amount</label>
                                  <div class="mw-pm-input-wrap">
                                      <span class="mw-pm-prefix">$</span>
                                      <input type="number" id="vm-pay-amount" name="payment_amount"
                                             step="0.01" min="0.01"
                                             value="<?php echo $pmBalanceRaw; ?>">
                                  </div>
                              </div>
                              <div class="mw-pm-field">
                                  <label for="vm-pay-date">Payment Date</label>
                                  <div class="mw-pm-input-wrap">
                                      <input type="date" id="vm-pay-date" name="payment_date"
                                             value="<?php echo date('Y-m-d'); ?>">
                                  </div>
                              </div>
                          </div>

                          <!-- Payment method pills -->
                          <div>
                              <div class="mw-pm-method-label">Payment Method</div>
                              <div class="mw-pm-method-grid" id="pmMethodGrid">

                                  <label class="mw-pm-pill active" data-method="e_transfer">
                                      <input type="radio" name="_pm_pill" value="e_transfer" checked>
                                      <svg class="mw-pm-pill-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                          <polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>
                                      </svg>
                                      <span class="mw-pm-pill-label">e-Transfer</span>
                                  </label>

                                  <label class="mw-pm-pill" data-method="cash">
                                      <input type="radio" name="_pm_pill" value="cash">
                                      <svg class="mw-pm-pill-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                          <line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                                      </svg>
                                      <span class="mw-pm-pill-label">Cash</span>
                                  </label>

                                  <label class="mw-pm-pill" data-method="cheque">
                                      <input type="radio" name="_pm_pill" value="cheque">
                                      <svg class="mw-pm-pill-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
                                      </svg>
                                      <span class="mw-pm-pill-label">Cheque</span>
                                  </label>

                                  <label class="mw-pm-pill" data-method="credit_card">
                                      <input type="radio" name="_pm_pill" value="credit_card">
                                      <svg class="mw-pm-pill-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                          <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/>
                                      </svg>
                                      <span class="mw-pm-pill-label">Card</span>
                                  </label>

                                  <label class="mw-pm-pill" data-method="other">
                                      <input type="radio" name="_pm_pill" value="other">
                                      <svg class="mw-pm-pill-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                          <circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>
                                      </svg>
                                      <span class="mw-pm-pill-label">Other</span>
                                  </label>

                              </div>
                          </div>

                          <!-- Reference field (contextual reveal) -->
                          <div class="mw-pm-ref-wrap visible" id="vmRefWrap">
                              <div class="mw-pm-field">
                                  <label id="vm-ref-label" for="vm-pay-ref">e-Transfer Confirmation #</label>
                                  <div class="mw-pm-input-wrap">
                                      <input type="text" id="vm-pay-ref" name="payment_reference"
                                             placeholder="e.g., 123456789">
                                  </div>
                              </div>
                          </div>

                          <!-- Partial payment notice -->
                          <div class="mw-pm-partial-notice" id="vmPartialNotice">
                              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                              Partial payment — remaining balance stays open
                          </div>

                      </div>

                      <div class="mw-pm-footer">
                          <button type="button" class="mw-pm-cancel" id="pmCancelBtn">Cancel</button>
                          <button type="submit" class="mw-pm-submit" id="paymentModalTitle">
                              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                              Record Payment
                          </button>
                      </div>
                  </form>

              </div>
          </div>

          <script>
          (function () {
              var balanceFull = <?php echo floatval($invoice['balance_due']); ?>;
              var modal       = document.getElementById('paymentModal');
              var amtInput    = document.getElementById('vm-pay-amount');
              var heroAmt     = document.getElementById('pmHeroAmount');
              var refWrap     = document.getElementById('vmRefWrap');
              var refLabel    = document.getElementById('vm-ref-label');
              var refInput    = document.getElementById('vm-pay-ref');
              var partNotice  = document.getElementById('vmPartialNotice');
              var hiddenMethod = document.getElementById('vm-pay-method-hidden');

              var refConfig = {
                  e_transfer : { label: 'e-Transfer Confirmation #', ph: 'e.g., 123456789', show: true  },
                  cheque     : { label: 'Cheque Number',              ph: 'e.g., 1042',      show: true  },
                  cash       : { label: 'Receipt / Notes',            ph: '',                show: false },
                  credit_card: { label: 'Authorization Code',         ph: 'e.g., auth code', show: true  },
                  other      : { label: 'Reference / Notes',          ph: '',                show: false },
              };

              // Method pill selection
              document.getElementById('pmMethodGrid').addEventListener('click', function (e) {
                  var pill = e.target.closest('.mw-pm-pill');
                  if (!pill) return;
                  document.querySelectorAll('.mw-pm-pill').forEach(function(p){ p.classList.remove('active'); });
                  pill.classList.add('active');
                  pill.querySelector('input[type="radio"]').checked = true;
                  var method = pill.dataset.method;
                  hiddenMethod.value = method;
                  var cfg = refConfig[method] || refConfig.other;
                  refLabel.textContent = cfg.label;
                  refInput.placeholder = cfg.ph;
                  if (cfg.show) {
                      refWrap.classList.add('visible');
                  } else {
                      refWrap.classList.remove('visible');
                      refInput.value = '';
                  }
              });

              // Amount change — update hero + partial notice
              amtInput.addEventListener('input', function () {
                  var val = parseFloat(this.value) || 0;
                  var fmt = '$' + val.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                  heroAmt.textContent = fmt;
                  if (val > 0 && val < balanceFull - 0.005) {
                      heroAmt.classList.add('mw-pm-partial-mode');
                      partNotice.classList.add('visible');
                  } else {
                      heroAmt.classList.remove('mw-pm-partial-mode');
                      partNotice.classList.remove('visible');
                  }
              });

              // Open / close
              window.openPaymentModal = function () {
                  modal.style.display = 'flex';
                  amtInput.focus();
              };
              function closePaymentModal() { modal.style.display = 'none'; }
              document.getElementById('pmCloseBtn').addEventListener('click', closePaymentModal);
              document.getElementById('pmCancelBtn').addEventListener('click', closePaymentModal);
              modal.addEventListener('click', function (e) { if (e.target === this) closePaymentModal(); });
          })();
          </script>

<?php if ($isPayable): ?>
<script>
var _mwInvoicePortalUrl = <?php echo json_encode($invoicePortalUrl); ?>;
function mwCopyInvoiceLink(btn) {
    navigator.clipboard.writeText(_mwInvoicePortalUrl).then(function () {
        btn.innerHTML = '<i data-feather="check" class="mr-1"></i> Copied!';
        if (typeof feather !== 'undefined') feather.replace();
        setTimeout(function () {
            btn.innerHTML = '<i data-feather="link" class="mr-1"></i> Copy Link';
            if (typeof feather !== 'undefined') feather.replace();
        }, 2000);
    });
}

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

<!-- Cancel Invoice Modal -->
<?php if (in_array($invoice['status'], ['sent', 'viewed', 'overdue', 'partial'])): ?>
<div id="cancelModal" class="mw-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="cancelModalTitle">
    <div class="mw-modal" style="max-width:480px;">
        <div class="mw-modal-header">
            <h5 class="mb-0" id="cancelModalTitle">Cancel Invoice <?php echo htmlspecialchars($invoice['invoice_number']); ?></h5>
            <button type="button" class="mw-modal-close" onclick="closeCancelModal()" aria-label="Close">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            <input type="hidden" name="action" value="cancel_invoice">
            <div class="mw-modal-body">
                <div class="alert alert-warning" style="font-size:13px;margin-bottom:16px;">
                    <strong>CRA note:</strong> Cancelling keeps a full audit trail. Once cancelled you can delete the invoice if it was raised in error. Paid invoices cannot be cancelled.
                </div>
                <div class="mw-form-group">
                    <label class="form-label" for="cancellation_reason">Reason for cancellation <span style="color:#dc2626;">*</span></label>
                    <textarea id="cancellation_reason" name="cancellation_reason" class="form-control" rows="3"
                              placeholder="e.g. Raised in error — duplicate of INV-2026-0090" required></textarea>
                </div>
            </div>
            <div class="mw-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeCancelModal()">Keep Invoice</button>
                <button type="submit" class="btn btn-danger">Cancel Invoice</button>
            </div>
        </form>
    </div>
</div>
<script>
function openCancelModal()  { document.getElementById('cancelModal').style.display = 'flex'; document.getElementById('cancellation_reason').focus(); }
function closeCancelModal() { document.getElementById('cancelModal').style.display = 'none'; }
document.getElementById('cancelModal').addEventListener('click', function(e) { if (e.target === this) closeCancelModal(); });
</script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
