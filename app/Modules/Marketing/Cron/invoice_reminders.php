<?php
/**
 * Invoice Payment Reminders Cron
 *
 * Sends automated email + SMS reminders for unpaid invoices at three stages:
 *   1. Approaching due (3 days before due_date)
 *   2. Due today (on due_date)
 *   3. Overdue (7 days past due_date, then every 7 days up to max 3 reminders)
 *
 * Rules:
 *   - Max 3 reminders total per invoice
 *   - Minimum 3-day cooldown between reminders
 *   - Only invoices with status in (sent, viewed, overdue, partial)
 *   - SMS requires receive_sms consent on contact
 *   - SMS has no URLs (carrier gateway compliance)
 *
 * Cron: run once daily, e.g. at 9am (after invoice_overdue marks overdue at 8am)
 *   0 9 * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Marketing/Cron/invoice_reminders.php
 *
 * Also accessible via POST to /crm/cron/invoice_reminders.php (admin-only shim).
 */

declare(strict_types=1);

// Bootstrap
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once CRM_INCLUDES . '/functions.php';
require_once CRM_INCLUDES . '/messaging.php';

$db  = getDB();
$log = [];
$startMs    = (int) round(microtime(true) * 1000);
$fromWeb    = (PHP_SAPI !== 'cli');
$cronStatus = 'success';
$cronError  = null;
$emailsSent = 0;
$smsSent    = 0;

function remLog(string $msg): void {
    global $log;
    $ts = date('Y-m-d H:i:s');
    $log[] = "[$ts] $msg";
    if (PHP_SAPI === 'cli') { echo "[$ts] $msg\n"; }
}

remLog("=== Invoice Reminders Cron started ===");

$MAX_REMINDERS   = 3;
$COOLDOWN_DAYS   = 3;
$APPROACHING_DAYS = 3; // days before due_date to send first reminder

try {
    // Find invoices eligible for a reminder:
    //  - Status in (sent, viewed, overdue, partial)
    //  - Has a balance_due > 0
    //  - reminder_count < MAX_REMINDERS
    //  - No reminder sent within cooldown period
    //  - Due date is within approaching window, today, or past
    $stmt = $db->prepare("
        SELECT
            i.id, i.invoice_number, i.balance_due, i.due_date, i.status,
            i.reminder_count, i.last_reminder_sent_at, i.access_token,
            i.contact_id, i.company_id,
            COALESCE(ct.first_name, '') as contact_first,
            COALESCE(ct.last_name, '')  as contact_last,
            COALESCE(ct.email, '')      as contact_email,
            COALESCE(NULLIF(ct.mobile,''), NULLIF(ct.phone,'')) as contact_phone,
            ct.receive_sms,
            c.company_name
        FROM invoices i
        LEFT JOIN contacts  ct ON i.contact_id = ct.id
        LEFT JOIN companies c  ON i.company_id = c.id
        WHERE i.status IN ('sent', 'viewed', 'overdue', 'partial')
          AND i.balance_due > 0.01
          AND (i.reminder_count IS NULL OR i.reminder_count < ?)
          AND (i.last_reminder_sent_at IS NULL OR i.last_reminder_sent_at < DATE_SUB(NOW(), INTERVAL ? DAY))
          AND i.due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
        ORDER BY i.due_date ASC
    ");
    $stmt->execute([$MAX_REMINDERS, $COOLDOWN_DAYS, $APPROACHING_DAYS]);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    remLog("Found " . count($invoices) . " invoice(s) eligible for reminders");

    $emailsSent = 0;
    $smsSent    = 0;

    foreach ($invoices as $inv) {
        $invoiceId     = (int)$inv['id'];
        $reminderCount = (int)($inv['reminder_count'] ?? 0);
        $dueDate       = $inv['due_date'];
        $today         = date('Y-m-d');

        // Determine reminder type for messaging
        if ($dueDate > $today) {
            $reminderType = 'approaching';
        } elseif ($dueDate === $today) {
            $reminderType = 'due_today';
        } else {
            $reminderType = 'overdue';
        }

        $contactName = trim($inv['contact_first'] . ' ' . $inv['contact_last']) ?: ($inv['company_name'] ?: 'Valued Customer');
        $firstName   = $inv['contact_first'] ?: 'there';

        // ── Send email reminder ──────────────────────────────────────────
        if (!empty($inv['contact_email'])) {
            require_once APP_ROOT . '/Services/Messaging/EmailWrapper.php';
            $companyInfo = EmailWrapper::getCompanyInfo();

            // Build subject and body based on reminder type
            $amount = '$' . number_format((float)$inv['balance_due'], 2);

            if ($reminderType === 'approaching') {
                $subject  = "Reminder: Invoice {$inv['invoice_number']} due soon";
                $bodyText = "Hi {$firstName},<br><br>"
                          . "This is a friendly reminder that invoice <strong>{$inv['invoice_number']}</strong> "
                          . "for <strong>{$amount} CAD</strong> is due on <strong>" . date('F j, Y', strtotime($dueDate)) . "</strong>.<br><br>"
                          . "You can view and pay your invoice online anytime.";
            } elseif ($reminderType === 'due_today') {
                $subject  = "Invoice {$inv['invoice_number']} is due today";
                $bodyText = "Hi {$firstName},<br><br>"
                          . "Just a heads-up that invoice <strong>{$inv['invoice_number']}</strong> "
                          . "for <strong>{$amount} CAD</strong> is due today.<br><br>"
                          . "You can view and pay your invoice online.";
            } else {
                $daysPast = (int)((strtotime($today) - strtotime($dueDate)) / 86400);
                $subject  = "Payment reminder: Invoice {$inv['invoice_number']} is overdue";
                $bodyText = "Hi {$firstName},<br><br>"
                          . "Invoice <strong>{$inv['invoice_number']}</strong> for <strong>{$amount} CAD</strong> "
                          . "was due on " . date('F j, Y', strtotime($dueDate)) . " ({$daysPast} days ago).<br><br>"
                          . "Please take a moment to review and pay your invoice online. "
                          . "If you've already sent payment, please disregard this reminder.";
            }

            // Build the portal link
            $viewUrl = '';
            if (!empty($inv['access_token'])) {
                $viewUrl = 'https://mowology.ca/customer/invoice.php?token=' . urlencode($inv['access_token']);
            }

            $emailBody = EmailWrapper::wrap(
                $bodyText,
                'View &amp; Pay Invoice',
                $viewUrl ?: null,
                $companyInfo
            );

            $result = sendCrmEmail($inv['contact_email'], $subject, $emailBody);
            if ($result) {
                $emailsSent++;
                remLog("  Email sent to {$contactName} for {$inv['invoice_number']} ({$reminderType})");
            } else {
                remLog("  Email FAILED for {$contactName} ({$inv['invoice_number']})");
            }
        }

        // ── Send SMS reminder (no URLs!) ─────────────────────────────────
        if ($inv['receive_sms'] && !empty($inv['contact_phone'])) {
            $amount = '$' . number_format((float)$inv['balance_due'], 2);

            if ($reminderType === 'overdue') {
                $smsMsg = "Mowology: Invoice {$inv['invoice_number']} ({$amount}) is overdue. Check your email to pay. Questions? (778) 846-9273.";
            } else {
                $smsMsg = "Mowology: Reminder - Invoice {$inv['invoice_number']} ({$amount}) due soon. Check your email to pay. (778) 846-9273.";
            }

            // Ensure under 160 chars
            if (strlen($smsMsg) > 160) {
                $smsMsg = "Mowology: Invoice {$inv['invoice_number']} reminder ({$amount} due). Check email to pay. (778) 846-9273.";
            }

            $smsResult = sendSms($inv['contact_phone'], $smsMsg, 'Mowology');
            if ($smsResult['success']) {
                $smsSent++;
                remLog("  SMS sent to {$contactName}");
            } else {
                remLog("  SMS FAILED for {$contactName}");
            }
        }

        // ── Update reminder tracking on invoice ──────────────────────────
        $db->prepare("
            UPDATE invoices
            SET reminder_count          = reminder_count + 1,
                first_reminder_sent_at  = COALESCE(first_reminder_sent_at, NOW()),
                last_reminder_sent_at   = NOW()
            WHERE id = ?
        ")->execute([$invoiceId]);

        // Activity log
        $logDetail = ucfirst($reminderType) . " payment reminder sent to {$contactName}";
        $db->prepare("
            INSERT INTO activity_log (user_id, action, details, invoice_id, created_at)
            VALUES (NULL, 'Payment reminder sent', ?, ?, NOW())
        ")->execute([$logDetail, $invoiceId]);
    }

    remLog("Summary: {$emailsSent} email(s), {$smsSent} SMS sent");

} catch (Throwable $e) {
    $cronStatus = 'error';
    $cronError  = $e->getMessage();
    remLog("ERROR: " . $e->getMessage());
    error_log('[invoice_reminders_cron] ' . $e->getMessage());
}

remLog("=== Done ===");

$durationMs = (int) round(microtime(true) * 1000) - $startMs;
recordCronRun(
    'invoice_reminders',
    $cronStatus,
    "Sent {$emailsSent} email(s), {$smsSent} SMS",
    $durationMs,
    $cronError,
    $fromWeb
);

// If running via web shim, return JSON
if ($fromWeb) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'log' => $log]);
}
