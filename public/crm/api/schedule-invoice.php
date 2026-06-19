<?php
/**
 * Schedule Invoice API
 *
 * Handles the post-job-completion invoice flow from the mobile schedule sheet.
 *
 * POST actions:
 *   preview — return visit/client data for the sheet header
 *   create  — create a draft invoice from a completed visit + extras + notes
 *   send    — send a draft invoice by id
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/messaging.php';

requireLogin();
$user = getCurrentUser();

header('Content-Type: application/json; charset=utf-8');
session_write_close();

$db    = getDB();
$input = json_decode(file_get_contents('php://input'), true) ?? [];

function siOut(array $data): void {
    echo json_encode($data);
    exit;
}

$action = $input['action'] ?? '';

try {

    // ─── PREVIEW ─────────────────────────────────────────────────────────────────
    if ($action === 'preview') {
        $visitId = (int)($input['visit_id'] ?? 0);
        if (!$visitId) siOut(['success' => false, 'error' => 'visit_id required']);

        $stmt = $db->prepare("
            SELECT jv.id AS visit_id, jv.actual_amount, jv.plan_id, jv.scheduled_date,
                   jv.extras_minutes, jv.invoice_id AS existing_invoice_id,
                   jp.title, jp.price_per_visit, jp.estimated_amount,
                   p.address, p.city,
                   COALESCE(con.first_name, '') AS first_name,
                   COALESCE(con.last_name, '')  AS last_name,
                   con.email                    AS contact_email
            FROM job_visits jv
            JOIN job_plans jp   ON jv.plan_id = jp.id
            LEFT JOIN properties p ON jp.property_id = p.id
            LEFT JOIN contacts con ON p.site_contact_id = con.id
            WHERE jv.id = ? AND jv.status = 'completed'
        ");
        $stmt->execute([$visitId]);
        $visit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$visit) siOut(['success' => false, 'error' => 'Visit not found or not completed']);

        $amount = floatval($visit['actual_amount'] ?: $visit['price_per_visit'] ?: $visit['estimated_amount']);
        $addr   = trim(($visit['address'] ?? '') . ($visit['city'] ? ', ' . $visit['city'] : ''));

        siOut([
            'success'          => true,
            'visit_id'         => $visitId,
            'contact_name'     => trim($visit['first_name'] . ' ' . $visit['last_name']) ?: null,
            'contact_email'    => $visit['contact_email'] ?? null,
            'amount'           => round($amount, 2),
            'plan_title'       => $visit['title'] ?? '',
            'scheduled_date'   => $visit['scheduled_date'] ?? '',
            'service_address'  => $addr,
            'existing_invoice' => !empty($visit['existing_invoice_id']) ? (int)$visit['existing_invoice_id'] : null,
        ]);
    }

    // ─── CREATE ──────────────────────────────────────────────────────────────────
    elseif ($action === 'create') {
        $visitId       = (int)($input['visit_id'] ?? 0);
        $extrasMinutes = max(0, (int)($input['extras_minutes'] ?? 0));
        $notes         = trim((string)($input['notes'] ?? ''));

        if (!$visitId) siOut(['success' => false, 'error' => 'visit_id required']);

        // Load the extras rate
        $rRow = $db->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'extras_rate_per_5min' LIMIT 1")
                   ->fetch(PDO::FETCH_ASSOC);
        $ratePerBlock = round(floatval($rRow['setting_value'] ?? 5.00), 2);
        $extrasBlocks = $extrasMinutes > 0 ? (int)ceil($extrasMinutes / 5) : 0;
        $extrasAmount = round($extrasBlocks * $ratePerBlock, 2);

        // Load visit + related data
        $stmt = $db->prepare("
            SELECT jv.id AS visit_id, jv.actual_amount, jv.plan_id, jv.scheduled_date,
                   jv.invoice_id AS existing_invoice_id, jv.visit_number,
                   jp.title, jp.price_per_visit, jp.estimated_amount,
                   jp.property_id, jp.company_id, jp.plan_number, jp.contract_id,
                   p.address, p.city, p.province, p.postal_code, p.site_contact_id,
                   COALESCE(con.first_name, '') AS first_name,
                   COALESCE(con.last_name, '')  AS last_name,
                   con.id    AS contact_id,
                   con.email AS contact_email,
                   con.mobile AS contact_mobile,
                   con.receive_sms
            FROM job_visits jv
            JOIN job_plans jp   ON jv.plan_id = jp.id
            LEFT JOIN properties p ON jp.property_id = p.id
            LEFT JOIN contacts con ON p.site_contact_id = con.id
            WHERE jv.id = ? AND jv.status = 'completed'
        ");
        $stmt->execute([$visitId]);
        $visit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$visit) siOut(['success' => false, 'error' => 'Visit not found or not completed']);

        if (!empty($visit['existing_invoice_id'])) {
            siOut([
                'success'    => false,
                'error'      => 'Invoice already exists',
                'invoice_id' => (int)$visit['existing_invoice_id'],
            ]);
        }

        // Plan line items
        $lineItems = [];
        if ($visit['plan_id']) {
            try {
                $pliStmt = $db->prepare("
                    SELECT description, quantity, unit_price, line_total, sort_order
                    FROM plan_line_items WHERE plan_id = ? ORDER BY sort_order, id
                ");
                $pliStmt->execute([$visit['plan_id']]);
                $lineItems = $pliStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {}
        }

        // Base subtotal
        $visitAmount = floatval($visit['actual_amount'] ?: $visit['price_per_visit'] ?: $visit['estimated_amount']);
        $baseSubtotal = $lineItems
            ? array_sum(array_column($lineItems, 'line_total'))
            : $visitAmount;

        // GST
        $bsRow    = $db->query("SELECT gst_rate, gst_registration FROM business_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $taxRate  = round(floatval($bsRow['gst_rate'] ?? 5.00) / 100, 4);
        $gstNum   = trim($bsRow['gst_registration'] ?? '');

        $fullSubtotal = round($baseSubtotal + $extrasAmount, 2);
        $taxAmount    = round($fullSubtotal * $taxRate, 2);
        $total        = round($fullSubtotal + $taxAmount, 2);
        $today        = date('Y-m-d');
        $dueDate      = date('Y-m-d', strtotime('+30 days'));
        $contactName  = trim($visit['first_name'] . ' ' . $visit['last_name']) ?: null;

        $db->beginTransaction();

        $invoiceNumber = generateInvoiceNumber();
        $accessToken   = generateAccessToken();

        $svcDesc = trim(($visit['title'] ?? '') . ($visit['scheduled_date'] ? ' — ' . date('M j, Y', strtotime($visit['scheduled_date'])) : ''));

        $db->prepare("
            INSERT INTO invoices (
                invoice_number, company_id, contact_id, property_id,
                plan_id, visit_id, contract_id,
                invoice_date, issue_date, due_date,
                subtotal, tax_rate, tax_amount, gst_number,
                total_amount, total, balance_due,
                notes, access_token, token_expires_at,
                service_address, service_city, service_province, service_postal_code,
                bill_to_name, status, created_by
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, DATE_ADD(NOW(), INTERVAL 90 DAY),
                ?, ?, ?, ?,
                ?, 'draft', ?
            )
        ")->execute([
            $invoiceNumber,
            $visit['company_id'] ?: null,
            $visit['contact_id']  ?: null,
            $visit['property_id'] ?: null,
            $visit['plan_id']     ?: null,
            $visitId,
            $visit['contract_id'] ?: null,
            $today, $today, $dueDate,
            $fullSubtotal, $taxRate, $taxAmount,
            $gstNum ?: null,
            $total, $total, $total,
            $notes ?: null,
            $accessToken,
            $visit['address']   ?? '',
            $visit['city']      ?? '',
            $visit['province']  ?? 'BC',
            $visit['postal_code'] ?? '',
            // bill_to_name left NULL on purpose: the PDF/view compose the payer
            // heading at render time ("{billing_entity} C/O {management firm}" for
            // PM-managed strata). Storing the on-site contact name here wrongly
            // forced the Bill To to the property manager person.
            null,
            $user['id'],
        ]);
        $invoiceId = (int)$db->lastInsertId();

        // Line items
        $liStmt = $db->prepare("
            INSERT INTO invoice_line_items
                (invoice_id, description, quantity, unit_price, line_total, visit_id, service_date, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $previewLines = [];

        if ($lineItems) {
            foreach ($lineItems as $i => $pli) {
                $liStmt->execute([
                    $invoiceId,
                    $pli['description'] ?: 'Service',
                    $pli['quantity'],
                    $pli['unit_price'],
                    $pli['line_total'],
                    $visitId,
                    $visit['scheduled_date'],
                    (int)($pli['sort_order'] ?? $i),
                ]);
                $previewLines[] = [
                    'description' => $pli['description'] ?: 'Service',
                    'amount'      => round(floatval($pli['line_total']), 2),
                ];
            }
        } else {
            $liStmt->execute([
                $invoiceId,
                $svcDesc ?: 'Services',
                1, $baseSubtotal, $baseSubtotal,
                $visitId, $visit['scheduled_date'], 0,
            ]);
            $previewLines[] = [
                'description' => $svcDesc ?: 'Services',
                'amount'      => round($baseSubtotal, 2),
            ];
        }

        // Extras line item
        if ($extrasAmount > 0 && $extrasMinutes > 0) {
            $liStmt->execute([
                $invoiceId,
                'Additional services (' . $extrasMinutes . ' min)',
                1, $extrasAmount, $extrasAmount,
                $visitId, $visit['scheduled_date'], 999,
            ]);
            $previewLines[] = [
                'description' => 'Additional services (' . $extrasMinutes . ' min)',
                'amount'      => $extrasAmount,
                'is_extras'   => true,
            ];

            // Write extras back to the visit record
            $db->prepare("UPDATE job_visits SET extras_minutes = ?, extras_amount = ? WHERE id = ?")
               ->execute([$extrasMinutes, $extrasAmount, $visitId]);
        }

        // Invoice recipient
        if ($visit['contact_id'] && $visit['contact_email']) {
            $db->prepare("
                INSERT INTO invoice_contacts (invoice_id, contact_id, contact_role, email_address)
                VALUES (?, ?, 'primary_recipient', ?)
            ")->execute([$invoiceId, $visit['contact_id'], $visit['contact_email']]);
        }

        $db->commit();

        siOut([
            'success'        => true,
            'invoice_id'     => $invoiceId,
            'invoice_number' => $invoiceNumber,
            'contact_name'   => $contactName,
            'contact_email'  => $visit['contact_email'] ?? null,
            'line_items'     => $previewLines,
            'subtotal'       => round($fullSubtotal, 2),
            'tax_rate'       => round($taxRate * 100, 1),
            'tax_amount'     => $taxAmount,
            'total'          => $total,
        ]);
    }

    // ─── SEND ────────────────────────────────────────────────────────────────────
    elseif ($action === 'send') {
        $invoiceId = (int)($input['invoice_id'] ?? 0);
        if (!$invoiceId) siOut(['success' => false, 'error' => 'invoice_id required']);

        require_once dirname(__DIR__) . '/includes/pdf_bootstrap.php';
        require_once dirname(__DIR__) . '/includes/PdfGenerator.php';
        require_once APP_ROOT . '/Services/Messaging/EmailWrapper.php';

        $invStmt = $db->prepare("
            SELECT i.*,
                   COALESCE(con.first_name, '') AS contact_first,
                   COALESCE(con.last_name, '')  AS contact_last,
                   con.email AS contact_email
            FROM invoices i
            LEFT JOIN contacts con ON i.contact_id = con.id
            WHERE i.id = ? AND i.status IN ('draft','pending')
        ");
        $invStmt->execute([$invoiceId]);
        $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) siOut(['success' => false, 'error' => 'Invoice not found or already sent']);

        // Recipients
        $recStmt = $db->prepare("
            SELECT ic.*, con.first_name, con.last_name, con.receive_sms,
                   COALESCE(NULLIF(con.mobile,''), NULLIF(con.phone,'')) AS sms_phone
            FROM invoice_contacts ic
            LEFT JOIN contacts con ON ic.contact_id = con.id
            WHERE ic.invoice_id = ? AND TRIM(ic.email_address) != ''
        ");
        $recStmt->execute([$invoiceId]);
        $recipients = $recStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fallback to invoice contact if no rows
        if (!$recipients && !empty($invoice['contact_email'])) {
            $recipients = [[
                'email_address' => $invoice['contact_email'],
                'first_name'    => $invoice['contact_first'],
                'last_name'     => $invoice['contact_last'],
                'receive_sms'   => 0,
                'sms_phone'     => null,
            ]];
        }

        if (!$recipients) siOut(['success' => false, 'error' => 'No recipients found for this invoice']);

        // Generate PDF
        $pdfGen     = new PdfGenerator();
        $pdfResult  = $pdfGen->generateInvoicePdf($invoiceId);
        $attachPath = (!empty($pdfResult['success']) && !empty($pdfResult['path']) && file_exists($pdfResult['path']))
            ? $pdfResult['path'] : null;

        // Ensure valid access token
        $accessToken = $invoice['access_token'] ?? '';
        if (empty($accessToken) || (!empty($invoice['token_expires_at']) && strtotime($invoice['token_expires_at']) < time())) {
            $accessToken = generateAccessToken();
            $db->prepare("UPDATE invoices SET access_token = ?, token_expires_at = DATE_ADD(NOW(), INTERVAL 90 DAY) WHERE id = ?")
               ->execute([$accessToken, $invoiceId]);
        }

        $invoiceViewUrl = 'https://mowology.ca/customer/invoice.php?token=' . urlencode($accessToken);
        $companyInfo    = EmailWrapper::getCompanyInfo();
        $sentTo         = [];

        foreach ($recipients as $recipient) {
            $firstName     = !empty($recipient['first_name']) ? $recipient['first_name'] : 'there';
            $recipientName = trim(($recipient['first_name'] ?? '') . ' ' . ($recipient['last_name'] ?? '')) ?: 'Valued Customer';

            $tplVars = [
                '{{customer_first_name}}' => $firstName,
                '{{customer_name}}'       => $recipientName,
                '{{invoice_number}}'      => $invoice['invoice_number'],
                '{{amount_due}}'          => formatCurrency($invoice['balance_due']),
                '{{due_date}}'            => formatDate($invoice['due_date']),
                '{{company_name}}'        => $companyInfo['company_name'],
                '{{company_phone}}'       => $companyInfo['company_phone'],
            ];

            $tpl = loadEmailTemplate('invoice_sent', $tplVars);

            $billSummary  = '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:460px;margin:0 0 20px;font-size:14px;font-family:\'Helvetica Neue\',Arial,sans-serif;">';
            $billSummary .= '<tr><td style="padding:6px 0;color:#4a6b5d;width:120px;">Invoice #</td><td style="padding:6px 0;color:#0D3B2E;font-weight:700;">' . htmlspecialchars($invoice['invoice_number']) . '</td></tr>';
            $billSummary .= '<tr><td style="padding:6px 0;color:#4a6b5d;">Amount Due</td><td style="padding:6px 0;color:#0D3B2E;font-size:18px;font-weight:700;">' . formatCurrency($invoice['balance_due']) . ' CAD</td></tr>';
            $billSummary .= '<tr><td style="padding:6px 0;color:#4a6b5d;">Due Date</td><td style="padding:6px 0;color:#0D3B2E;">' . formatDate($invoice['due_date']) . '</td></tr>';
            $billSummary .= '</table>';

            // Build the branded email body the same way the desktop path does:
            // EmailWrapper::wrap() wraps the bill summary + template body + payment
            // instructions in the Mowology shell and renders the View & Pay button.
            // (loadEmailTemplate returns 'body_html', not 'body'.)
            $body = EmailWrapper::wrap(
                $billSummary . ($tpl['body_html'] ?? '') . EmailWrapper::paymentInstructionsHtml(),
                'View &amp; Pay Invoice Online',
                $invoiceViewUrl ?: null,
                $companyInfo
            );

            sendCrmEmail(
                $recipient['email_address'],
                $tpl['subject'],
                $body,
                $attachPath ?: null
            );

            $sentTo[] = $recipient['email_address'];

            if (!empty($recipient['sms_phone']) && !empty($recipient['receive_sms'])) {
                $smsText = 'Mowology: Invoice #' . $invoice['invoice_number'] . ' for $' . number_format(floatval($invoice['balance_due']), 2) . ' is ready. Check your email for the payment link. Questions? (778) 846-9273.';
                if (strlen($smsText) <= 160) {
                    sendInvoiceNotificationSms($recipient['sms_phone'], $smsText);
                }
            }
        }

        $db->prepare("UPDATE invoices SET status = 'sent', sent_at = NOW(), sent_by = ? WHERE id = ?")
           ->execute([$user['id'], $invoiceId]);

        // Mark the source visit invoiced. Join invoices.visit_id -> job_visits.id;
        // if the invoice has no visit_id the join matches nothing (safe no-op).
        // NOTE: job_visits has no `visit_id` column — the previous query referenced
        // it in the WHERE clause and threw "Unknown column 'visit_id'", surfacing as
        // a generic "Server error" to the crew after the email had already gone out.
        $db->prepare("
            UPDATE job_visits jv
            JOIN invoices i ON i.visit_id = jv.id
            SET jv.is_invoiced = 1, jv.invoice_id = i.id
            WHERE i.id = ?
        ")->execute([$invoiceId]);

        siOut([
            'success'        => true,
            'invoice_number' => $invoice['invoice_number'],
            'sent_to'        => $sentTo,
        ]);
    }

    else {
        siOut(['success' => false, 'error' => 'Unknown action']);
    }

} catch (Throwable $e) {
    error_log('schedule-invoice.php [' . $action . '] error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (isset($db) && $db->inTransaction()) { try { $db->rollBack(); } catch (Throwable $re) {} }
    siOut(['success' => false, 'error' => 'Server error. Please try again.']);
}
