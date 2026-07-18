<?php
/**
 * InvoiceFromVisitService — Post-job-completion invoice flow.
 *
 * Extracted verbatim from public/crm/api/schedule-invoice.php so the same
 * preview / create / send logic can be reused by:
 *   - the web schedule sheet  (session-auth controller: schedule-invoice.php)
 *   - the mobile iOS app       (JWT-auth controller: app/Modules/Schedule/Api/invoice.php)
 *
 * Responsibilities:
 *  - preview()          → visit/client summary for the sheet header
 *  - createFromVisit()  → create a draft invoice from a completed visit + extras + notes
 *  - send()             → email a draft invoice to its recipients (+ optional SMS)
 *  - computeExtrasAmount() → single source of the timed-extras dollar calc
 *
 * NOT responsible for:
 *  - Auth (callers authenticate via session or JWT before calling)
 *  - PDF rendering   → PdfGenerator (loaded lazily inside send())
 *  - Email/SMS send  → messaging.php helpers (sendCrmEmail / sendInvoiceNotificationSms)
 *
 * Depends on functions provided by crm/includes/functions.php:
 *  generateInvoiceNumber(), generateAccessToken(), formatCurrency(), formatDate(),
 *  loadEmailTemplate(), sendCrmEmail(), sendInvoiceNotificationSms()
 */
declare(strict_types=1);

class InvoiceFromVisitService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * True if $userId is an admin/manager, or is the crew member assigned to
     * $assignedCrewId. Callers use this so any crew member may only preview,
     * create, or send an invoice for a visit they were actually assigned to —
     * both the session-auth desktop caller and the JWT mobile caller only
     * call requireLogin()/requireJwt() with no role gate, so this is the one
     * place that actually enforces per-visit ownership.
     */
    private function isOwnerOrAdmin(int $userId, ?int $assignedCrewId): bool
    {
        $roleStmt = $this->db->prepare("SELECT role FROM users WHERE id = ?");
        $roleStmt->execute([$userId]);
        $role = (string)($roleStmt->fetchColumn() ?: '');
        if (in_array($role, ['admin', 'manager'], true)) {
            return true;
        }
        return $assignedCrewId !== null && $assignedCrewId === $userId;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // EXTRAS CALC — single source of truth
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Timed-extras dollar value. Billable time always rounds UP to the next
     * 5-minute block: blocks = ceil(minutes / 5); amount = blocks * rate.
     *
     * @return array{blocks:int, amount:float, rate:float}
     */
    public function computeExtrasAmount(int $minutes): array
    {
        $rate = $this->extrasRatePerBlock();
        $blocks = $minutes > 0 ? (int)ceil($minutes / 5) : 0;
        return [
            'blocks' => $blocks,
            'amount' => round($blocks * $rate, 2),
            'rate'   => $rate,
        ];
    }

    /** Configured extras rate per 5-minute block (default $5.00). */
    public function extrasRatePerBlock(): float
    {
        try {
            $row = $this->db->query(
                "SELECT setting_value FROM ops_settings WHERE setting_key = 'extras_rate_per_5min' LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
            return round(floatval($row['setting_value'] ?? 5.00), 2);
        } catch (Throwable $e) {
            return 5.00;
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PREVIEW
    // ══════════════════════════════════════════════════════════════════════════

    public function preview(int $visitId, int $userId): array
    {
        if (!$visitId) return ['success' => false, 'error' => 'visit_id required'];

        $stmt = $this->db->prepare("
            SELECT jv.id AS visit_id, jv.actual_amount, jv.plan_id, jv.scheduled_date,
                   jv.extras_minutes, jv.invoice_id AS existing_invoice_id, jv.assigned_crew_id,
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

        if (!$visit) return ['success' => false, 'error' => 'Visit not found or not completed'];

        if (!$this->isOwnerOrAdmin($userId, isset($visit['assigned_crew_id']) ? (int)$visit['assigned_crew_id'] : null)) {
            return ['success' => false, 'error' => 'You are not assigned to this visit'];
        }

        $amount = floatval($visit['actual_amount'] ?: $visit['price_per_visit'] ?: $visit['estimated_amount']);
        $addr   = trim(($visit['address'] ?? '') . ($visit['city'] ? ', ' . $visit['city'] : ''));

        return [
            'success'          => true,
            'visit_id'         => $visitId,
            'contact_name'     => trim($visit['first_name'] . ' ' . $visit['last_name']) ?: null,
            'contact_email'    => $visit['contact_email'] ?? null,
            'amount'           => round($amount, 2),
            'plan_title'       => $visit['title'] ?? '',
            'scheduled_date'   => $visit['scheduled_date'] ?? '',
            'service_address'  => $addr,
            'extras_minutes'   => (int)($visit['extras_minutes'] ?? 0),
            'extras_rate'      => $this->extrasRatePerBlock(),
            'existing_invoice' => !empty($visit['existing_invoice_id']) ? (int)$visit['existing_invoice_id'] : null,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // CREATE
    // ══════════════════════════════════════════════════════════════════════════

    public function createFromVisit(int $visitId, int $extrasMinutes, string $notes, int $userId): array
    {
        $extrasMinutes = max(0, $extrasMinutes);
        $notes         = trim($notes);

        if (!$visitId) return ['success' => false, 'error' => 'visit_id required'];

        // Extras
        $extras       = $this->computeExtrasAmount($extrasMinutes);
        $extrasAmount = $extras['amount'];

        // Load visit + related data
        $stmt = $this->db->prepare("
            SELECT jv.id AS visit_id, jv.actual_amount, jv.plan_id, jv.scheduled_date,
                   jv.invoice_id AS existing_invoice_id, jv.visit_number, jv.assigned_crew_id,
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

        if (!$visit) return ['success' => false, 'error' => 'Visit not found or not completed'];

        if (!$this->isOwnerOrAdmin($userId, isset($visit['assigned_crew_id']) ? (int)$visit['assigned_crew_id'] : null)) {
            return ['success' => false, 'error' => 'You are not assigned to this visit'];
        }

        if (!empty($visit['existing_invoice_id'])) {
            return [
                'success'    => false,
                'error'      => 'Invoice already exists',
                'invoice_id' => (int)$visit['existing_invoice_id'],
            ];
        }

        // Contract-billed plans are invoiced automatically through the contract —
        // never via a per-visit invoice (mobile app / API path).
        if (!empty($visit['contract_id'])) {
            require_once APP_ROOT . '/Modules/Contracts/Services/ContractService.php';
            $contractSvc = new ContractService($this->db);
            if ($contractSvc->isPlanContractBilled((int)$visit['plan_id'])) {
                return [
                    'success' => false,
                    'error'   => 'This visit is billed under an active contract, not per-visit. '
                               . 'No invoice was created — billing happens automatically through the contract.',
                    'code'    => 'CONTRACT_BILLED',
                ];
            }
        }

        // Plan line items
        $lineItems = [];
        if ($visit['plan_id']) {
            try {
                $pliStmt = $this->db->prepare("
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
        $bsRow    = $this->db->query("SELECT gst_rate, gst_registration FROM business_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $taxRate  = round(floatval($bsRow['gst_rate'] ?? 5.00) / 100, 4);
        $gstNum   = trim($bsRow['gst_registration'] ?? '');

        $fullSubtotal = round($baseSubtotal + $extrasAmount, 2);
        $taxAmount    = round($fullSubtotal * $taxRate, 2);
        $total        = round($fullSubtotal + $taxAmount, 2);
        $today        = date('Y-m-d');
        $dueDate      = date('Y-m-d', strtotime('+30 days'));
        $contactName  = trim($visit['first_name'] . ' ' . $visit['last_name']) ?: null;

        $this->db->beginTransaction();

        try {
            $invoiceNumber = generateInvoiceNumber();
            $accessToken   = generateAccessToken();

            $svcDesc = trim(($visit['title'] ?? '') . ($visit['scheduled_date'] ? ' — ' . date('M j, Y', strtotime($visit['scheduled_date'])) : ''));

            $this->db->prepare("
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
                $userId,
            ]);
            $invoiceId = (int)$this->db->lastInsertId();

            // Back-link the source visit immediately at creation — NOT only at send().
            // The day schedule keys "Create Invoice" vs the invoice link off
            // job_visits.invoice_id. If we waited for send() and the draft was instead
            // sent from the desktop (view.php, which does no back-link), the visit kept
            // invoice_id = NULL and the schedule kept asking to invoice an already-
            // invoiced stop. Setting it here makes the link independent of how/where
            // the draft is later sent.
            $this->db->prepare("UPDATE job_visits SET is_invoiced = 1, invoice_id = ? WHERE id = ?")
               ->execute([$invoiceId, $visitId]);

            // Line items
            $liStmt = $this->db->prepare("
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
                $this->db->prepare("UPDATE job_visits SET extras_minutes = ?, extras_amount = ? WHERE id = ?")
                   ->execute([$extrasMinutes, $extrasAmount, $visitId]);
            }

            // Invoice recipient
            if ($visit['contact_id'] && $visit['contact_email']) {
                $this->db->prepare("
                    INSERT INTO invoice_contacts (invoice_id, contact_id, contact_role, email_address)
                    VALUES (?, ?, 'primary_recipient', ?)
                ")->execute([$invoiceId, $visit['contact_id'], $visit['contact_email']]);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { try { $this->db->rollBack(); } catch (Throwable $re) {} }
            throw $e;
        }

        return [
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
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SEND
    // ══════════════════════════════════════════════════════════════════════════

    public function send(int $invoiceId, int $userId): array
    {
        if (!$invoiceId) return ['success' => false, 'error' => 'invoice_id required'];

        require_once CRM_INCLUDES . '/pdf_bootstrap.php';
        require_once CRM_INCLUDES . '/PdfGenerator.php';
        require_once APP_ROOT . '/Services/Messaging/EmailWrapper.php';

        $invStmt = $this->db->prepare("
            SELECT i.*,
                   COALESCE(con.first_name, '') AS contact_first,
                   COALESCE(con.last_name, '')  AS contact_last,
                   con.email AS contact_email,
                   jv.assigned_crew_id
            FROM invoices i
            LEFT JOIN contacts con ON i.contact_id = con.id
            LEFT JOIN job_visits jv ON jv.id = i.visit_id
            WHERE i.id = ? AND i.status IN ('draft','pending')
        ");
        $invStmt->execute([$invoiceId]);
        $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) return ['success' => false, 'error' => 'Invoice not found or already sent'];

        // Invoices not created from a visit (visit_id NULL) have no per-visit owner —
        // only invoices created via createFromVisit() are subject to this check.
        if ($invoice['visit_id'] !== null
            && !$this->isOwnerOrAdmin($userId, isset($invoice['assigned_crew_id']) ? (int)$invoice['assigned_crew_id'] : null)
        ) {
            return ['success' => false, 'error' => 'You are not assigned to this visit'];
        }

        // Recipients
        $recStmt = $this->db->prepare("
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

        if (!$recipients) return ['success' => false, 'error' => 'No recipients found for this invoice'];

        // Generate PDF
        $pdfGen     = new PdfGenerator();
        $pdfResult  = $pdfGen->generateInvoicePdf($invoiceId);
        $attachPath = (!empty($pdfResult['success']) && !empty($pdfResult['path']) && file_exists($pdfResult['path']))
            ? $pdfResult['path'] : null;

        // Ensure valid access token
        $accessToken = $invoice['access_token'] ?? '';
        if (empty($accessToken) || (!empty($invoice['token_expires_at']) && strtotime($invoice['token_expires_at']) < time())) {
            $accessToken = generateAccessToken();
            $this->db->prepare("UPDATE invoices SET access_token = ?, token_expires_at = DATE_ADD(NOW(), INTERVAL 90 DAY) WHERE id = ?")
               ->execute([$accessToken, $invoiceId]);
        }

        $invoiceViewUrl = 'https://mowology.ca/customer/invoice.php?token=' . urlencode($accessToken);
        $companyInfo    = EmailWrapper::getCompanyInfo();
        $sentTo         = [];

        foreach ($recipients as $recipient) {
          try {
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

            // Append email open tracking pixel (per-recipient). Only when this is a
            // real invoice_contacts row — the no-rows fallback recipient has no id.
            if (!empty($recipient['id'])) {
                $trackPixelUrl = 'https://mowology.ca/crm/api/track-invoice-open.php?rid=' . (int)$recipient['id'];
                $body = str_replace(
                    '</body>',
                    '<img src="' . $trackPixelUrl . '" width="1" height="1" alt="" style="display:block;height:1px;width:1px;border:0;" /></body>',
                    $body
                );
            }

            $emailOk = sendCrmEmail(
                $recipient['email_address'],
                $tpl['subject'],
                $body,
                $attachPath ?: null
            );

            // Only record recipients whose email actually went out — a failed
            // delivery must never be counted as "sent".
            if (!$emailOk) {
                error_log("InvoiceFromVisitService::send: email delivery failed for invoice {$invoiceId} to {$recipient['email_address']}");
                continue;
            }

            $sentTo[] = $recipient['email_address'];

            // Record the per-recipient send timestamp so the Recipients table
            // shows the send time (not "Pending") and persist the resolved email.
            // Mirrors the desktop send handler in crm/invoices/view.php.
            if (!empty($recipient['id'])) {
                $this->db->prepare("
                    UPDATE invoice_contacts
                    SET invoice_sent_at = NOW(), email_address = ?
                    WHERE id = ?
                ")->execute([$recipient['email_address'], $recipient['id']]);
            }

            if (!empty($recipient['sms_phone']) && !empty($recipient['receive_sms'])) {
                $smsText = 'Mowology: Invoice #' . $invoice['invoice_number'] . ' for $' . number_format(floatval($invoice['balance_due']), 2) . ' is ready. Check your email for the payment link. Questions? (778) 846-9273.';
                if (strlen($smsText) <= 160) {
                    sendInvoiceNotificationSms($recipient['sms_phone'], $smsText);
                }
            }
          } catch (Throwable $e) {
            // One recipient erroring must not abort the others or bubble a 500 to
            // the caller after other emails have already gone out (that would
            // invite a duplicate manual resend). Log and move on.
            error_log("InvoiceFromVisitService::send: recipient {$recipient['email_address']} errored for invoice {$invoiceId}: " . $e->getMessage());
          }
        }

        // Nothing went out → safe to report failure: the invoice stays a draft and
        // NO customer email was sent, so the caller can retry cleanly with no risk
        // of a duplicate email.
        if (!$sentTo) {
            return ['success' => false, 'error' => 'Email delivery failed. Please try again, or open the invoice to resend.'];
        }

        // At least one email went out, so the invoice MUST be marked sent —
        // otherwise a retry would email the customer a second time. The status
        // guard (draft/pending) makes a concurrent double-send a no-op. Bookkeeping
        // failures here are logged, never surfaced as an error: the customer already
        // has the email, and returning an error would prompt a duplicate resend.
        // NOTE: invoices has no sent_by column on production (referencing it once
        // threw "Unknown column 'sent_by'" and stuck the invoice as a draft).
        try {
            $this->db->prepare("UPDATE invoices SET status = 'sent', sent_at = NOW() WHERE id = ? AND status IN ('draft','pending')")
               ->execute([$invoiceId]);

            // Mark the source visit invoiced. Join invoices.visit_id -> job_visits.id;
            // if the invoice has no visit_id the join matches nothing (safe no-op).
            $this->db->prepare("
                UPDATE job_visits jv
                JOIN invoices i ON i.visit_id = jv.id
                SET jv.is_invoiced = 1, jv.invoice_id = i.id
                WHERE i.id = ?
            ")->execute([$invoiceId]);
        } catch (Throwable $e) {
            error_log("InvoiceFromVisitService::send: post-send bookkeeping failed for invoice {$invoiceId} (email already sent): " . $e->getMessage());
        }

        return [
            'success'        => true,
            'invoice_number' => $invoice['invoice_number'],
            'sent_to'        => $sentTo,
        ];
    }
}
