<?php
/**
 * Contract Monthly Billing Cron
 * ─────────────────────────────
 * Runs on the 1st of each month at 6 AM.
 *
 * For every active contract where:
 *   billing_cycle  = 'monthly'
 *   invoice_timing = 'upfront'  (or any timing — the 1st-of-month fire IS the trigger)
 *   status         = 'active'
 *
 * It will:
 *   1. Skip if an invoice was already generated for this contract this month (idempotent)
 *   2. Create an invoice: subtotal = billing_amount, 5% GST, due = last day of current month
 *   3. Insert a single line item describing the monthly service period
 *   4. Insert invoice_contacts for the contract's primary contact
 *   5. Send the invoice email using the 'invoice_sent' template + EmailWrapper
 *   6. Send an SMS notification if the contact has SMS consent
 *   7. Mark invoice status = 'sent'
 *   8. Log the action to activity_log
 *
 * cPanel cron:
 *   0 6 1 * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Contracts/Cron/contract_billing.php
 *
 * Can also be triggered manually by an admin via HTTP POST (same auth as other crons).
 */
declare(strict_types=1);

// ── Bootstrap: upward path search ───────────────────────────────────────────
if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once PUBLIC_ROOT . '/crm/includes/functions.php';
    requireLogin();
    $user = getCurrentUser();
    if (($user['role'] ?? '') !== 'admin') { http_response_code(403); exit; }
    header('Content-Type: application/json; charset=utf-8');
} else {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once PUBLIC_ROOT . '/crm/includes/functions.php';
}

require_once APP_ROOT . '/Services/Messaging/EmailWrapper.php';

$startMs    = (int)(microtime(true) * 1000);
$today      = date('Y-m-d');
$monthLabel = date('F Y');           // e.g. "April 2026"
$dueDate    = date('Y-m-t');         // last day of current month (Y-m-t = last day)
$taxRate    = 0.05;

$db       = getDB();
$created  = [];
$skipped  = [];
$errors   = [];

// ── 1. Check migration 1007 has been applied ─────────────────────────────────
try {
    $colCheck = $db->query("SHOW COLUMNS FROM invoices LIKE 'contract_id'")->fetchAll();
    if (empty($colCheck)) {
        $msg = 'Migration 1007 not applied — invoices.contract_id column missing. Run /crm/api/run-migration-1007.php first.';
        error_log("[contract_billing] {$msg}");
        if ($isCli) { echo $msg . PHP_EOL; exit(1); }
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    }
} catch (Throwable $e) {
    error_log("[contract_billing] Migration check failed: " . $e->getMessage());
}

// ── 2. Fetch active monthly contracts with contact + property info ────────────
try {
    $stmt = $db->prepare("
        SELECT c.id          AS contract_id,
               c.contract_number,
               c.title,
               c.billing_amount,
               c.billing_cycle,
               c.invoice_timing,
               c.contact_id,
               c.property_id,
               c.company_id,
               con.first_name,
               con.last_name,
               con.email        AS contact_email,
               con.mobile       AS contact_mobile,
               con.receive_sms,
               p.address        AS service_address,
               p.city           AS service_city,
               p.province       AS service_province,
               p.postal_code    AS service_postal
        FROM contracts c
        JOIN contacts   con ON con.id = c.contact_id
        LEFT JOIN properties p  ON p.id  = c.property_id
        WHERE c.status        = 'active'
          AND c.billing_cycle = 'monthly'
        ORDER BY c.id ASC
    ");
    $stmt->execute();
    $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $msg = 'Failed to query contracts: ' . $e->getMessage();
    error_log("[contract_billing] {$msg}");
    if ($isCli) { echo $msg . PHP_EOL; exit(1); }
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

// ── 3. Process each contract ──────────────────────────────────────────────────
foreach ($contracts as $ctr) {
    $contractId = (int)$ctr['contract_id'];
    $contactId  = (int)$ctr['contact_id'];

    try {
        // ── 3a. Idempotency: skip if already invoiced this month ──────────────
        $existCheck = $db->prepare("
            SELECT id FROM invoices
            WHERE contract_id = ?
              AND YEAR(invoice_date)  = YEAR(CURDATE())
              AND MONTH(invoice_date) = MONTH(CURDATE())
            LIMIT 1
        ");
        $existCheck->execute([$contractId]);
        if ($existCheck->fetchColumn()) {
            $skipped[] = $ctr['contract_number'] . ' (already invoiced this month)';
            continue;
        }

        // ── 3b. Skip if contact has no email ─────────────────────────────────
        if (empty($ctr['contact_email'])) {
            $skipped[] = $ctr['contract_number'] . ' (no email on contact)';
            continue;
        }

        $db->beginTransaction();

        // ── 3c. Create invoice ────────────────────────────────────────────────
        $subtotal    = round((float)$ctr['billing_amount'], 2);
        $taxAmount   = round($subtotal * $taxRate, 2);
        $total       = round($subtotal + $taxAmount, 2);

        $invoiceNumber = generateInvoiceNumber();
        $accessToken   = generateAccessToken();

        $db->prepare("
            INSERT INTO invoices (
                invoice_number, contract_id, company_id, contact_id, property_id,
                invoice_date, issue_date, due_date,
                subtotal, tax_rate, tax_amount,
                total_amount, total, balance_due,
                notes, access_token, token_expires_at,
                service_address, service_city, service_province, service_postal_code,
                status, created_by
            ) VALUES (
                ?, ?, ?, ?, ?,
                CURDATE(), CURDATE(), ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, DATE_ADD(NOW(), INTERVAL 90 DAY),
                ?, ?, ?, ?,
                'draft', 0
            )
        ")->execute([
            $invoiceNumber,
            $contractId,
            $ctr['company_id'] ?: null,
            $contactId,
            $ctr['property_id'] ?: null,
            $dueDate,
            $subtotal, $taxRate, $taxAmount,
            $total, $total, $total,
            "Monthly service — {$monthLabel}",
            $accessToken,
            $ctr['service_address'] ?? '',
            $ctr['service_city']    ?? '',
            $ctr['service_province'] ?? 'BC',
            $ctr['service_postal']  ?? '',
        ]);

        $invoiceId = (int)$db->lastInsertId();

        // ── 3d. Line item ─────────────────────────────────────────────────────
        $lineDesc = trim($ctr['title'] ?? '') ?: 'Monthly landscaping service';
        $lineDesc .= " — {$monthLabel}";

        $db->prepare("
            INSERT INTO invoice_line_items
                (invoice_id, description, quantity, unit_price, line_total)
            VALUES (?, ?, 1, ?, ?)
        ")->execute([$invoiceId, $lineDesc, $subtotal, $subtotal]);

        // ── 3e. Invoice contact ───────────────────────────────────────────────
        $db->prepare("
            INSERT INTO invoice_contacts
                (invoice_id, contact_id, contact_role, email_address)
            VALUES (?, ?, 'primary_recipient', ?)
        ")->execute([$invoiceId, $contactId, $ctr['contact_email']]);

        $db->commit();

        // ── 3f. Send email ────────────────────────────────────────────────────
        $recipientName  = trim($ctr['first_name'] . ' ' . $ctr['last_name']) ?: 'Valued Customer';
        $firstName      = $ctr['first_name'] ?: 'there';
        $companyInfo    = EmailWrapper::getCompanyInfo();
        $invoiceViewUrl = 'https://mowology.ca/customer/invoice.php?token=' . urlencode($accessToken);

        $tplVars = [
            '{{customer_first_name}}' => $firstName,
            '{{customer_name}}'       => $recipientName,
            '{{invoice_number}}'      => $invoiceNumber,
            '{{amount_due}}'          => formatCurrency($total),
            '{{due_date}}'            => formatDate($dueDate),
            '{{company_name}}'        => $companyInfo['company_name'],
            '{{company_phone}}'       => $companyInfo['company_phone'],
        ];

        $tpl = loadEmailTemplate('invoice_sent', $tplVars);

        $billSummary  = '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:460px;margin:0 0 20px;font-size:14px;font-family:\'Helvetica Neue\',Arial,sans-serif;">';
        $billSummary .= '<tr><td style="padding:6px 0;color:#4a6b5d;width:120px;">Invoice #</td><td style="padding:6px 0;color:#0D3B2E;font-weight:700;">' . htmlspecialchars($invoiceNumber) . '</td></tr>';
        $billSummary .= '<tr><td style="padding:6px 0;color:#4a6b5d;">Period</td><td style="padding:6px 0;color:#0D3B2E;">' . htmlspecialchars($monthLabel) . '</td></tr>';
        $billSummary .= '<tr><td style="padding:6px 0;color:#4a6b5d;">Amount Due</td><td style="padding:6px 0;color:#0D3B2E;font-size:18px;font-weight:700;">' . formatCurrency($total) . ' CAD</td></tr>';
        $billSummary .= '<tr><td style="padding:6px 0;color:#4a6b5d;">Due Date</td><td style="padding:6px 0;color:#0D3B2E;">' . formatDate($dueDate) . '</td></tr>';
        $billSummary .= '<tr><td style="padding:6px 0;color:#4a6b5d;vertical-align:top;">Bill To</td><td style="padding:6px 0;color:#0D3B2E;">' . htmlspecialchars($recipientName) . '</td></tr>';
        $billSummary .= '</table>';

        $emailBody = EmailWrapper::wrap(
            $billSummary . $tpl['body_html'],
            'View &amp; Pay Invoice Online',
            $invoiceViewUrl,
            $companyInfo
        );

        $emailSent = sendCrmEmail($ctr['contact_email'], $tpl['subject'], $emailBody);

        if ($emailSent) {
            // Mark sent
            $db->prepare("
                UPDATE invoices SET status = 'sent', sent_at = NOW() WHERE id = ?
            ")->execute([$invoiceId]);

            $db->prepare("
                UPDATE invoice_contacts SET invoice_sent_at = NOW()
                WHERE invoice_id = ? AND contact_id = ?
            ")->execute([$invoiceId, $contactId]);

            // ── 3g. SMS ───────────────────────────────────────────────────────
            if (!empty($ctr['receive_sms']) && !empty($ctr['contact_mobile'])) {
                sendInvoiceNotificationSms(
                    $ctr['contact_mobile'],
                    $invoiceNumber,
                    $total
                );
            }

            // ── 3h. Activity log ──────────────────────────────────────────────
            logActivityExtended(
                0,
                'contract_invoice_generated',
                json_encode([
                    'invoice_id'      => $invoiceId,
                    'invoice_number'  => $invoiceNumber,
                    'contract_id'     => $contractId,
                    'contract_number' => $ctr['contract_number'],
                    'amount'          => $total,
                    'period'          => $monthLabel,
                    'sent_to'         => $ctr['contact_email'],
                ]),
                $ctr['company_id'] ?: null,
                null, null,
                $invoiceId
            );

            $created[] = "{$ctr['contract_number']} → {$invoiceNumber} ({$ctr['contact_email']})";
        } else {
            // Email failed — invoice exists as draft; log it so it can be resent manually
            error_log("[contract_billing] Email failed for invoice {$invoiceNumber} (contract {$ctr['contract_number']}, contact {$ctr['contact_email']})");
            $errors[] = "{$ctr['contract_number']}: invoice {$invoiceNumber} created but email failed — resend manually";
        }

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            try { $db->rollBack(); } catch (Throwable $rb) {}
        }
        $msg = "Contract {$ctr['contract_number']}: " . $e->getMessage();
        error_log("[contract_billing] {$msg}");
        $errors[] = $msg;
    }
}

// ── 4. Report ─────────────────────────────────────────────────────────────────
$elapsedMs = (int)(microtime(true) * 1000) - $startMs;

$report = [
    'success'    => true,
    'run_date'   => $today,
    'period'     => $monthLabel,
    'created'    => count($created),
    'skipped'    => count($skipped),
    'errors'     => count($errors),
    'elapsed_ms' => $elapsedMs,
    'detail'     => [
        'created' => $created,
        'skipped' => $skipped,
        'errors'  => $errors,
    ],
];

if ($isCli) {
    echo "Contract billing complete [{$monthLabel}]\n";
    echo "  Created : " . count($created) . "\n";
    echo "  Skipped : " . count($skipped) . "\n";
    echo "  Errors  : " . count($errors) . "\n";
    if ($errors) {
        foreach ($errors as $err) { echo "  ERROR: {$err}\n"; }
    }
    if ($created) {
        foreach ($created as $c) { echo "  OK: {$c}\n"; }
    }
    exit(count($errors) > 0 ? 1 : 0);
} else {
    echo json_encode($report, JSON_PRETTY_PRINT);
}
