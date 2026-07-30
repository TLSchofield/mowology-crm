<?php
/**
 * Bulk Resend Invoices API
 * POST (JSON): { invoice_ids: [int,...], csrf_token: string }
 * Returns:     { success: bool, results: [{id, invoice_number, success, sent_to?, error?}] }
 *
 * Max 50 invoices per call. CSRF verified before session_write_close().
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/messaging.php';

requireLogin();
$user = getCurrentUser();
requirePermission('billing.view');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];

// CSRF must be verified BEFORE session_write_close()
if (!verifyCSRFToken($data['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

session_write_close();

$invoiceIds = array_values(array_filter(
    array_map('intval', $data['invoice_ids'] ?? []),
    function ($id) { return $id > 0; }
));

if (empty($invoiceIds)) {
    echo json_encode(['success' => false, 'error' => 'No invoices selected']);
    exit;
}
if (count($invoiceIds) > 50) {
    echo json_encode(['success' => false, 'error' => 'Maximum 50 invoices per batch']);
    exit;
}

require_once dirname(__DIR__) . '/includes/pdf_bootstrap.php';
require_once dirname(__DIR__) . '/includes/PdfGenerator.php';
require_once APP_ROOT . '/Services/Messaging/EmailWrapper.php';

$db      = getDB();
$results = [];

foreach ($invoiceIds as $invoiceId) {
    $results[] = _bulkResendOne($db, $user, $invoiceId);
}

echo json_encode(['success' => true, 'results' => $results]);
exit;

// ─────────────────────────────────────────────────────────────────────────────

function _bulkResendOne(PDO $db, array $user, int $invoiceId): array {
    try {
        $stmt = $db->prepare("
            SELECT i.*,
                   COALESCE(NULLIF(c.company_name,''), CONCAT(ct.first_name,' ',ct.last_name)) AS display_name,
                   ct.first_name AS contact_first,
                   ct.last_name  AS contact_last,
                   ct.email      AS contact_email
            FROM invoices i
            LEFT JOIN companies c  ON i.company_id  = c.id
            LEFT JOIN contacts  ct ON i.contact_id  = ct.id
            WHERE i.id = ?
        ");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            return ['id' => $invoiceId, 'success' => false, 'error' => 'Not found'];
        }

        $num = $invoice['invoice_number'];

        if ($invoice['status'] === 'cancelled') {
            return ['id' => $invoiceId, 'invoice_number' => $num, 'success' => false, 'error' => 'Invoice is cancelled'];
        }

        // Refresh token if expired
        $tokenExpired = !empty($invoice['token_expires_at']) && strtotime($invoice['token_expires_at']) < time();
        if (empty($invoice['access_token']) || $tokenExpired) {
            $tok = generateAccessToken();
            $db->prepare("UPDATE invoices SET access_token = ?, token_expires_at = DATE_ADD(NOW(), INTERVAL 90 DAY) WHERE id = ?")
               ->execute([$tok, $invoiceId]);
            $invoice['access_token'] = $tok;
        }

        // Load recipients
        $stmt = $db->prepare("
            SELECT ic.id, ic.contact_id,
                   COALESCE(NULLIF(ic.email_address,''), c.email) AS email_address,
                   ic.contact_role,
                   c.first_name, c.last_name
            FROM invoice_contacts ic
            LEFT JOIN contacts c ON ic.contact_id = c.id
            WHERE ic.invoice_id = ?
            ORDER BY CASE ic.contact_role WHEN 'primary_recipient' THEN 1 ELSE 2 END, ic.created_at
        ");
        $stmt->execute([$invoiceId]);
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fallback: bill-to contact
        $hasEmail = false;
        foreach ($recipients as $r) { if (!empty($r['email_address'])) { $hasEmail = true; break; } }
        if (!$hasEmail && !empty($invoice['contact_email'])) {
            if (!empty($invoice['contact_id'])) {
                try {
                    $db->prepare("INSERT INTO invoice_contacts (invoice_id, contact_id, contact_role, email_address) VALUES (?, ?, 'primary_recipient', ?)")
                       ->execute([$invoiceId, (int)$invoice['contact_id'], $invoice['contact_email']]);
                    $newId = (int)$db->lastInsertId();
                } catch (Throwable $e) { $newId = 0; }
            }
            $recipients[] = [
                'id'           => $newId ?? 0,
                'contact_id'   => $invoice['contact_id'],
                'email_address'=> $invoice['contact_email'],
                'contact_role' => 'primary_recipient',
                'first_name'   => $invoice['contact_first'] ?? '',
                'last_name'    => $invoice['contact_last']  ?? '',
            ];
        }

        $validRecipients = array_filter($recipients, function ($r) { return !empty($r['email_address']); });
        if (empty($validRecipients)) {
            return ['id' => $invoiceId, 'invoice_number' => $num, 'success' => false, 'error' => 'No recipients'];
        }

        // Generate PDF
        $attachPath = null;
        $pdfGen    = new PdfGenerator();
        $pdfResult = $pdfGen->generateInvoicePdf($invoiceId);
        if (!empty($pdfResult['success']) && !empty($pdfResult['path']) && file_exists($pdfResult['path'])) {
            $attachPath = $pdfResult['path'];
        } else {
            $cached = $pdfGen->getPdfPath('invoice', $invoiceId);
            if ($cached && file_exists($cached)) {
                $attachPath = $cached;
            }
        }

        $invoiceViewUrl = 'https://mowology.ca/customer/invoice.php?token=' . urlencode($invoice['access_token']);
        $companyInfo    = EmailWrapper::getCompanyInfo();

        $sentTo = [];
        foreach ($validRecipients as $recipient) {
            $recipientName = trim(($recipient['first_name'] ?? '') . ' ' . ($recipient['last_name'] ?? ''))
                ?: ($invoice['display_name'] ?: 'Customer');

            $tplVars = [
                '{{customer_first_name}}' => $recipient['first_name'] ?: 'there',
                '{{customer_name}}'       => $recipientName,
                '{{invoice_number}}'      => $invoice['invoice_number'],
                '{{amount_due}}'          => formatCurrency($invoice['balance_due']),
                '{{due_date}}'            => formatDate($invoice['due_date']),
                '{{company_name}}'        => $companyInfo['company_name'],
                '{{company_phone}}'       => $companyInfo['company_phone'],
            ];

            $tpl          = loadEmailTemplate('invoice_sent', $tplVars);
            $emailSubject = $tpl['subject'];

            $billSummary  = '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:460px;margin:0 0 20px;font-size:14px;font-family:\'Helvetica Neue\',Arial,sans-serif;">';
            $billSummary .= '<tr><td style="padding:6px 0;color:#4a6b5d;width:120px;">Invoice #</td><td style="padding:6px 0;color:#0D3B2E;font-weight:700;">' . htmlspecialchars($invoice['invoice_number']) . '</td></tr>';
            $billSummary .= '<tr><td style="padding:6px 0;color:#4a6b5d;">Amount Due</td><td style="padding:6px 0;color:#0D3B2E;font-size:18px;font-weight:700;">' . formatCurrency($invoice['balance_due']) . ' CAD</td></tr>';
            $billSummary .= '<tr><td style="padding:6px 0;color:#4a6b5d;">Due Date</td><td style="padding:6px 0;color:#0D3B2E;">' . formatDate($invoice['due_date']) . '</td></tr>';
            $billSummary .= '</table>';

            $emailBody = EmailWrapper::wrap(
                $billSummary . $tpl['body_html'] . EmailWrapper::paymentInstructionsHtml(),
                'View &amp; Pay Invoice Online',
                $invoiceViewUrl,
                $companyInfo
            );

            if (!empty($recipient['id'])) {
                $trackUrl  = 'https://mowology.ca/crm/api/track-invoice-open.php?rid=' . (int)$recipient['id'];
                $emailBody = str_replace('</body>', '<img src="' . $trackUrl . '" width="1" height="1" alt="" style="display:block;height:1px;width:1px;border:0;" /></body>', $emailBody);
            }

            $ok = sendCrmEmail($recipient['email_address'], $emailSubject, $emailBody, $attachPath);
            if ($ok) {
                $sentTo[] = $recipientName;
                if (!empty($recipient['id'])) {
                    $db->prepare("UPDATE invoice_contacts SET invoice_sent_at = NOW(), email_address = ? WHERE id = ?")
                       ->execute([$recipient['email_address'], $recipient['id']]);
                }
            }
        }

        if (empty($sentTo)) {
            return ['id' => $invoiceId, 'invoice_number' => $num, 'success' => false, 'error' => 'Email delivery failed'];
        }

        // Update invoice counters
        $isResend = !empty($invoice['sent_at']);
        if ($isResend) {
            $db->prepare("UPDATE invoices SET resend_count = COALESCE(resend_count,0)+1, last_resent_at = NOW() WHERE id = ?")
               ->execute([$invoiceId]);
        } else {
            $db->prepare("UPDATE invoices SET status = 'sent', sent_at = NOW() WHERE id = ?")
               ->execute([$invoiceId]);

            // Autopay: first-send transition to 'sent' — attempt an off-session
            // charge if the bill-to is enrolled. See AutopayService::triggerOnSend().
            $autopayServicePath = APP_ROOT . '/Services/Payments/AutopayService.php';
            if (is_file($autopayServicePath)) {
                require_once $autopayServicePath;
                AutopayService::triggerOnSend($db, $invoiceId, 'bulk-resend.php');
            }
        }

        $verb        = $isResend ? 'resent' : 'sent';
        $attachNote  = $attachPath ? ' (with PDF attached)' : '';
        $recipientList = implode(', ', $sentTo);
        logActivityExtended($user['id'], 'Invoice ' . $verb,
            "Invoice {$verb} to {$recipientList}{$attachNote}",
            null, null, null, $invoiceId);

        return ['id' => $invoiceId, 'invoice_number' => $num, 'success' => true, 'sent_to' => $recipientList];

    } catch (Throwable $e) {
        error_log("bulk-resend invoice {$invoiceId}: " . $e->getMessage());
        return ['id' => $invoiceId, 'success' => false, 'error' => 'Server error'];
    }
}
