<?php
/**
 * Invoice Payment Reminders Cron
 *
 * Sends automated email + SMS reminders for unpaid invoices at three stages:
 *   1. Approaching due (3 days before due_date)
 *   2. Due today (on due_date)
 *   3. Overdue after the payer's grace period, then every 7 days up to max 3 reminders
 *
 * Rules:
 *   - Max 3 reminders total per invoice
 *   - 3-day cooldown before/at due; 7-day cooldown once overdue
 *   - AP inbox, possible bank deposit, and stale company-statement certainty gates
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
require_once dirname(__DIR__, 3) . '/Services/Messaging/IdentityMail.php';

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
// Under cron (CLI) nothing else defines getDB()/Database — the web shim gets them from auth.php.
require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Modules/Accounting/Services/PaymentOnTheWay.php';   // a payment on the way pauses chasing

require_once CRM_INCLUDES . '/functions.php';
require_once CRM_INCLUDES . '/messaging.php';

$db  = getDB();
$log = [];

function remLog(string $msg): void {
    global $log;
    $ts = date('Y-m-d H:i:s');
    $log[] = "[$ts] $msg";
    if (PHP_SAPI === 'cli') { echo "[$ts] $msg\n"; }
}

remLog("=== Invoice Reminders Cron started ===");
$cronStart = microtime(true);
$cronError = null;

$MAX_REMINDERS   = 3;
$COOLDOWN_DAYS   = 3;
$OVERDUE_COOLDOWN_DAYS = 7;
$APPROACHING_DAYS = 3; // days before due_date to send first reminder

// Who a reminder goes to is NOT invoices.contact_id — see resolveReminderRecipients().
require_once APP_ROOT . '/Modules/Invoices/Services/InvoiceRouting.php';
require_once APP_ROOT . '/Modules/Invoices/Services/ReminderGate.php';
// Reminders to an accounts-payable inbox (LeapAP, ap@, invoices@) must carry the invoice PDF or that system rejects them (2026-10-28).
require_once APP_ROOT . '/Modules/Invoices/Services/InvoicePdfGate.php';
require_once APP_ROOT . '/Modules/Invoices/Services/InvoiceReminderRules.php';
require_once APP_ROOT . '/Modules/Invoices/Services/PayerTone.php';   // overdue wording by payment history (2026-11-03)
$pdfGate = new InvoicePdfGate($db);
$gate = new ReminderGate($db);

try {
    // Find invoices eligible for a reminder:
    //  - Status in (sent, viewed, overdue, partial)
    //  - Has a balance_due > 0
    //  - reminder_count < MAX_REMINDERS
    //  - Due date is within approaching window, today, or past
    $stmt = $db->prepare("
        SELECT
            i.id, i.invoice_number, i.balance_due, i.due_date, i.status,
            i.reminder_count, i.last_reminder_sent_at, i.access_token,
            i.contact_id, i.company_id, i.property_id, i.issue_date, i.created_at, i.total,
            c.company_name
        FROM invoices i
        LEFT JOIN companies c  ON i.company_id = c.id
        WHERE i.status IN ('sent', 'viewed', 'overdue', 'partial')
          AND i.balance_due > 0.01
          AND (i.reminder_count IS NULL OR i.reminder_count < ?)
          AND i.due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
          AND NOT " . PaymentOnTheWay::sql('i', PaymentOnTheWay::hintsReady($db)) . "
        ORDER BY i.due_date ASC
    ");
    $stmt->execute([$MAX_REMINDERS, $APPROACHING_DAYS]);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    remLog("Found " . count($invoices) . " invoice(s) eligible for reminders");

    $emailsSent = 0;
    $smsSent    = 0;
    $heldCounts = [];
    $shadowHoldCounts = [];
    $overdueByPayer = [];   // payer_key => [{inv, recipients, decision}] — sent once per payer after the loop
    $toneCounts = [];

    foreach ($invoices as $inv) {
        $invoiceId     = (int)$inv['id'];
        $reminderCount = (int)($inv['reminder_count'] ?? 0);
        $dueDate       = $inv['due_date'];
        $today         = date('Y-m-d');

        // ── Who actually gets chased for this money ──────────────────────
        // invoices.contact_id is the contract's counterparty (for a strata, the
        // council rep who signed) — not the party the invoice was billed to.
        $recipients = resolveReminderRecipients($invoiceId);
        if (empty($recipients)) {
            // Don't burn one of the three reminders on an invoice we can't address.
            remLog("  SKIPPED {$inv['invoice_number']} — no billing recipient resolved");
            continue;
        }

        $decision = $gate->decide($inv, $recipients);
        $reminderType = $decision['type'];

        $cooldownDays = $reminderType === 'overdue' ? $OVERDUE_COOLDOWN_DAYS : $COOLDOWN_DAYS;
        if (!empty($inv['last_reminder_sent_at'])) {
            $lastSentDate = substr((string)$inv['last_reminder_sent_at'], 0, 10);
            $daysSinceReminder = (int)floor((strtotime($today) - strtotime($lastSentDate)) / 86400);
            if ($daysSinceReminder < $cooldownDays) {
                remLog("  SKIPPED {$inv['invoice_number']} — {$cooldownDays}-day {$reminderType} cooldown");
                continue;
            }
        }

        $isHold = !in_array($decision['reason'], ['ok', 'approaching', 'due_today'], true);
        if (!$decision['send']) {
            remLog("  HELD {$inv['invoice_number']} — {$decision['reason']}: {$decision['detail']}");
            $gate->recordHold($decision, $invoiceId);
            $heldCounts[$decision['reason']] = ($heldCounts[$decision['reason']] ?? 0) + 1;
            continue;
        }
        if ($isHold && $decision['mode'] === 'shadow') {
            remLog("  WOULD HOLD {$inv['invoice_number']} — {$decision['reason']}: {$decision['detail']}");
            $gate->recordHold($decision, $invoiceId);
            $shadowHoldCounts[$decision['reason']] = ($shadowHoldCounts[$decision['reason']] ?? 0) + 1;
        }
        // Mode on: a mixed list chases the people only, never the AP addresses. Off and shadow
        // keep legacy delivery untouched (shadow only records what "on" would have done).
        if ($decision['mode'] === 'on' && !empty($decision['recipients'])) {
            $recipients = $decision['recipients'];
        }
        // Overdue reminders go per PAYER after this loop: one email listing every overdue invoice, in the payer's
        // tone from twelve months of payment history (PayerTone, 2026-11-03). Approaching / due today stay per invoice.
        if ($reminderType === 'overdue') {
            $overdueByPayer[(string)$decision['payer_key']][] = ['inv' => $inv, 'recipients' => $recipients, 'decision' => $decision];
            continue;
        }

        $amount   = '$' . number_format((float)$inv['balance_due'], 2);
        $viewUrl  = !empty($inv['access_token'])
            ? _idSiteUrl() . '/customer/invoice.php?token=' . urlencode($inv['access_token'])
            : '';
        $sentTo   = [];
        $pdfPath  = false;   // made once per invoice, only if some recipient needs it
        $held     = false;   // an accounts-payable inbox was skipped for want of a PDF

        foreach ($recipients as $rcpt) {
            $contactName = $rcpt['contact_name'] ?: ($inv['company_name'] ?: 'Valued Customer');
            // A PM's accounts inbox has no first name — don't greet it "Hi ,".
            $firstName   = trim(explode(' ', $rcpt['contact_name'])[0] ?? '') ?: 'there';

            // ── Send email reminder ──────────────────────────────────────
            require_once APP_ROOT . '/Services/Messaging/EmailWrapper.php';
            $companyInfo = EmailWrapper::getCompanyInfo();

            $dueLabel = date('F j, Y', strtotime($dueDate));
            $num      = htmlspecialchars($inv['invoice_number'], ENT_QUOTES, 'UTF-8');
            if ($reminderType === 'approaching') {
                $subject  = "Invoice {$inv['invoice_number']}: {$amount} due {$dueLabel}";
                $bodyText = "<p>Hi {$firstName},</p>"
                          . "<p>A reminder that invoice <strong>{$num}</strong> for <strong>{$amount}</strong> is due on <strong>{$dueLabel}</strong>. You can pay it from the button below.</p>"
                          . "<p>If you've already sent it, thank you, and ignore this.</p>";
            } elseif ($reminderType === 'due_today') {
                $subject  = "Invoice {$inv['invoice_number']} is due today";
                $bodyText = "<p>Hi {$firstName},</p>"
                          . "<p>Invoice <strong>{$num}</strong> for <strong>{$amount}</strong> is due today. The button below takes you to it.</p>"
                          . "<p>If it's already on its way, thank you, and ignore this.</p>";
            } else {
                $daysPast = (int)((strtotime($today) - strtotime($dueDate)) / 86400);
                $subject  = "Invoice {$inv['invoice_number']} is {$daysPast} days past due";
                $bodyText = "<p>Hi {$firstName},</p>"
                          . "<p>Invoice <strong>{$num}</strong> for <strong>{$amount}</strong> was due on {$dueLabel}, {$daysPast} days ago.</p>"
                          . "<p>If it slipped through, the button below takes you straight to it. If you've paid another way, reply and tell us how, so we can match it up. Sorry for the chase if so.</p>";
            }

            // An accounts-payable inbox gets the PDF, like the invoice email itself. If it cannot be made, send NOTHING to that inbox
            // (it would only be rejected) and let Charlie's PDF alert tell the owner; the reminder is retried on the next run.
            $attachPdf = null;
            if (InvoiceReminderRules::needsPdf((string)$rcpt['email_address'], (string)($rcpt['contact_role'] ?? ''))) {
                if ($pdfPath === false) $pdfPath = $pdfGate->ensurePdf($invoiceId);
                if ($pdfPath === null) {
                    $pdfGate->recordBlocked($invoiceId, 'reminder');
                    remLog("  HELD {$inv['invoice_number']} for <{$rcpt['email_address']}> — the invoice PDF could not be made");
                    $held = true;
                    continue;
                }
                $attachPdf = $pdfPath;
                $bodyText .= '<p>The invoice PDF is attached.</p>';
            }

            $emailBody = EmailWrapper::wrap(
                $bodyText,
                'Pay the invoice',
                $viewUrl ?: null,
                $companyInfo
            );

            $result = sendCrmEmail($rcpt['email_address'], $subject, $emailBody, $attachPdf);
            if ($result) {
                $emailsSent++;
                $sentTo[] = $contactName . ' <' . $rcpt['email_address'] . '>';
                remLog("  Email sent to {$contactName} <{$rcpt['email_address']}> ({$rcpt['contact_role']}, via {$rcpt['source']}) for {$inv['invoice_number']} ({$reminderType})");
            } else {
                remLog("  Email FAILED for {$contactName} <{$rcpt['email_address']}> ({$inv['invoice_number']})");
            }

            // ── Send SMS reminder (no URLs!) ─────────────────────────────
            if (!empty($rcpt['receive_sms']) && !empty($rcpt['phone'])) {
                if ($reminderType === 'overdue') {
                    $smsMsg = "Mowology: Invoice {$inv['invoice_number']} ({$amount}) is overdue. Check your email to pay. Questions? (778) 846-9273.";
                } else {
                    $smsMsg = "Mowology: Reminder - Invoice {$inv['invoice_number']} ({$amount}) due soon. Check your email to pay. (778) 846-9273.";
                }

                // Ensure under 160 chars
                if (strlen($smsMsg) > 160) {
                    $smsMsg = "Mowology: Invoice {$inv['invoice_number']} reminder ({$amount} due). Check email to pay. (778) 846-9273.";
                }

                $smsResult = sendSms($rcpt['phone'], $smsMsg, '');
                if ($smsResult['success']) {
                    $smsSent++;
                    remLog("  SMS sent to {$contactName}");
                } else {
                    remLog("  SMS FAILED for {$contactName}");
                }
            }
        }

        if (empty($sentTo)) {
            // Every recipient failed — leave the counter alone so the next run retries.
            remLog("  NOT counted for {$inv['invoice_number']} — every recipient failed");
            continue;
        }

        // ── Update reminder tracking on invoice ──────────────────────────
        // A held AP inbox must not lose its reminder to the others' success: the cooldown still starts (nobody is mailed daily),
        // but the count does not advance, so that inbox is tried again after the cooldown.
        $db->prepare("
            UPDATE invoices
            SET reminder_count          = COALESCE(reminder_count, 0) + " . ($held ? '0' : '1') . ",
                first_reminder_sent_at  = COALESCE(first_reminder_sent_at, NOW()),
                last_reminder_sent_at   = NOW()
            WHERE id = ?
        ")->execute([$invoiceId]);

        // Activity log — names every recipient, so "who did we chase?" is answerable.
        $logDetail = ucfirst($reminderType) . ' payment reminder sent to ' . implode(', ', $sentTo);
        $db->prepare("
            INSERT INTO activity_log (user_id, action, details, invoice_id, created_at)
            VALUES (NULL, 'Payment reminder sent', ?, ?, NOW())
        ")->execute([$logDetail, $invoiceId]);
    }

    // ── Overdue: one email per payer, in the payer's tone ─────────────────────────────────────────────
    foreach ($overdueByPayer as $payerKey => $group) {
        $today = date('Y-m-d');
        $toneInfo = $group[0]['decision']['tone'] ?? PayerTone::classify([]);
        $tone = (string)($toneInfo['tone'] ?? PayerTone::NEUTRAL);
        $toneCounts[$tone] = ($toneCounts[$tone] ?? 0) + 1;
        $invoices = [];
        foreach ($group as $g) {
            $invoices[] = $g['inv'] + ['url' => !empty($g['inv']['access_token'])
                ? _idSiteUrl() . '/customer/invoice.php?token=' . urlencode($g['inv']['access_token']) : ''];
        }
        // Recipients: the union across the payer's invoices, one send per address.
        $byEmail = [];
        foreach ($group as $g) foreach ($g['recipients'] as $r) {
            $addr = strtolower(trim((string)($r['email_address'] ?? '')));
            if ($addr !== '' && !isset($byEmail[$addr])) $byEmail[$addr] = $r;
        }
        $sentFor = [];   // invoice id => [names] — counters advance per invoice actually mailed about
        $heldFor = [];   // invoice id => true when an AP inbox could not get its PDF (count must not advance)
        $nums = implode(', ', array_map(static function ($i) { return (string)$i['invoice_number']; }, $invoices));
        remLog("  Payer {$payerKey}: {$tone} tone ({$toneInfo['reason']}) for {$nums}");

        require_once APP_ROOT . '/Services/Messaging/EmailWrapper.php';
        $companyInfo = EmailWrapper::getCompanyInfo();
        foreach ($byEmail as $rcpt) {
            $contactName = $rcpt['contact_name'] ?: ($group[0]['inv']['company_name'] ?: 'Valued Customer');
            $firstName = trim(explode(' ', (string)$rcpt['contact_name'])[0] ?? '') ?: 'there';
            $needsPdf = InvoiceReminderRules::needsPdf((string)$rcpt['email_address'], (string)($rcpt['contact_role'] ?? ''));
            // An AP inbox needs the PDF, and one email carries one PDF: that inbox gets one email per invoice.
            $batches = $needsPdf ? array_map(static function ($i) { return [$i]; }, $invoices) : [$invoices];
            foreach ($batches as $batch) {
                $mail = PayerTone::overdueEmail($tone, $batch, $firstName, $today);
                $bodyText = $mail['html'];
                $attachPdf = null;
                if ($needsPdf) {
                    $invoiceId = (int)$batch[0]['id'];
                    $pdfPath = $pdfGate->ensurePdf($invoiceId);
                    if ($pdfPath === null) {
                        $pdfGate->recordBlocked($invoiceId, 'reminder');
                        remLog("  HELD {$batch[0]['invoice_number']} for <{$rcpt['email_address']}> — the invoice PDF could not be made");
                        $heldFor[$invoiceId] = true;
                        continue;
                    }
                    $attachPdf = $pdfPath;
                    $bodyText .= '<p>The invoice PDF is attached.</p>';
                }
                $viewUrl = count($batch) === 1 ? ($batch[0]['url'] ?: null) : ($batch[0]['url'] ?: null);
                $emailBody = EmailWrapper::wrap($bodyText, count($batch) === 1 ? 'Pay the invoice' : 'View the invoices', $viewUrl, $companyInfo);
                $result = sendCrmEmail($rcpt['email_address'], $mail['subject'], $emailBody, $attachPdf);
                if ($result) {
                    $emailsSent++;
                    foreach ($batch as $i) $sentFor[(int)$i['id']][] = $contactName . ' <' . $rcpt['email_address'] . '>';
                    remLog("  Email sent to {$contactName} <{$rcpt['email_address']}> ({$rcpt['contact_role']}, via {$rcpt['source']}) — {$tone} overdue, " . count($batch) . ' invoice(s)');
                } else {
                    remLog("  Email FAILED for {$contactName} <{$rcpt['email_address']}> ({$tone} overdue)");
                }
            }
            if (!empty($rcpt['receive_sms']) && !empty($rcpt['phone'])) {
                $smsResult = sendSms($rcpt['phone'], PayerTone::overdueSms($invoices), '');
                if ($smsResult['success']) { $smsSent++; remLog("  SMS sent to {$contactName}"); }
                else remLog("  SMS FAILED for {$contactName}");
            }
        }
        foreach ($invoices as $i) {
            $invoiceId = (int)$i['id'];
            if (empty($sentFor[$invoiceId])) {
                remLog("  NOT counted for {$i['invoice_number']} — nobody was mailed about it");
                continue;
            }
            $db->prepare("
                UPDATE invoices
                SET reminder_count          = COALESCE(reminder_count, 0) + " . (!empty($heldFor[$invoiceId]) ? '0' : '1') . ",
                    first_reminder_sent_at  = COALESCE(first_reminder_sent_at, NOW()),
                    last_reminder_sent_at   = NOW()
                WHERE id = ?
            ")->execute([$invoiceId]);
            $logDetail = ucfirst($tone) . ' overdue reminder sent to ' . implode(', ', array_unique($sentFor[$invoiceId]))
                       . (count($invoices) > 1 ? ' (one email for ' . count($invoices) . ' invoices)' : '');
            $db->prepare("
                INSERT INTO activity_log (user_id, action, details, invoice_id, created_at)
                VALUES (NULL, 'Payment reminder sent', ?, ?, NOW())
            ")->execute([$logDetail, $invoiceId]);
        }
    }

    remLog("Summary: {$emailsSent} email(s), {$smsSent} SMS sent; held " . json_encode($heldCounts)
        . '; shadow-holds ' . json_encode($shadowHoldCounts) . '; overdue tones ' . json_encode($toneCounts));

} catch (Throwable $e) {
    remLog("ERROR: " . $e->getMessage());
    error_log('[invoice_reminders_cron] ' . $e->getMessage());
    $cronError = $e->getMessage();
}

remLog("=== Done ===");

// Every run is recorded in cron_runs (Ada, 2026-10-15: this cron never was, so its runs couldn't be checked).
if (function_exists('recordCronRun')) {
    try {
        recordCronRun('invoice_reminders', $cronError === null ? 'success' : 'error',
            ($emailsSent ?? 0) . ' sent, held ' . json_encode($heldCounts ?? [])
            . ', shadow-holds ' . json_encode($shadowHoldCounts ?? [])
            . ', ' . ($smsSent ?? 0) . ' SMS' . ($cronError === null ? '' : ' before it failed'),
            (int)round((microtime(true) - $cronStart) * 1000), $cronError, PHP_SAPI !== 'cli');
    } catch (Throwable $e) {
        error_log('[invoice_reminders_cron] could not record the run — ' . $e->getMessage());
    }
}

// If running via web shim, return JSON
if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'log' => $log]);
}
