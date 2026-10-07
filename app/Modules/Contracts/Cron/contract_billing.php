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
 *   5. Send the invoice email using the 'invoice_sent' template + EmailWrapper,
 *      WITH the invoice PDF (ContractInvoiceSender + InvoicePdfGate). No PDF → the
 *      email is NOT sent, the invoice stays 'draft', Charlie alerts the owner, and
 *      the invoice is retried at the start of the next run (step 0).
 *   6. Send an SMS notification if the contact has SMS consent
 *   7. Mark invoice status = 'sent'
 *   8. Log the action to activity_log
 *   0. (every run, first) Retry contract invoices held for a missing PDF.
 *
 * cPanel cron:
 *   0 6 1 * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Contracts/Cron/contract_billing.php
 * Optional daily retry of held invoices (creates nothing new):
 *   15 7 * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Contracts/Cron/contract_billing.php retry-only
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
// Under cron (CLI) nothing else defines getDB()/Database — the web shim gets them from auth.php.
require_once APP_ROOT . '/Core/config.php';

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
require_once APP_ROOT . '/Services/Messaging/MessagingService.php'; // defines loadEmailTemplate() used below
require_once APP_ROOT . '/Services/Pdf/pdf_bootstrap.php';
require_once APP_ROOT . '/Services/Pdf/PdfGenerator.php';
require_once APP_ROOT . '/Modules/Invoices/Services/InvoiceRouting.php'; // resolveManagementBillingRecipient()
require_once APP_ROOT . '/Modules/Invoices/Services/InvoicePdfGate.php';
require_once APP_ROOT . '/Modules/Contracts/Services/ContractInvoiceSender.php';

$startMs    = (int)(microtime(true) * 1000);
$today      = date('Y-m-d');
$monthLabel = date('F Y');           // e.g. "April 2026"
$dueDate    = date('Y-m-t');         // last day of current month (Y-m-t = last day)
$taxRate    = 0.05;

$db       = getDB();
$created  = [];
$skipped  = [];
$errors   = [];
$held     = [];   // not sent: the invoice PDF could not be made (retried next run)
$retried  = [];   // held invoices from earlier runs, sent now

// retry-only: just re-send invoices held for a missing PDF; create nothing new.
// Safe to schedule daily (the monthly line still creates the invoices on the 1st):
//   15 7 * * * /usr/local/bin/php .../contract_billing.php retry-only
$retryOnly = $isCli
    ? in_array('retry-only', array_slice($argv ?? [], 1), true)
    : !empty($_POST['retry_only']);

$sender = new ContractInvoiceSender($db, new InvoicePdfGate($db));

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

// Soft-check optional schema additions — run without them if migrations
// haven't been applied yet, so the cron keeps working during partial rollouts.
$hasPropertyBillingEntity = false;
try {
    $hasPropertyBillingEntity = (bool)$db->query("SHOW COLUMNS FROM properties LIKE 'billing_entity_name'")->fetch();
} catch (Throwable $e) { /* column absent — fall through */ }

$hasInvoiceBillToName = false;
try {
    $hasInvoiceBillToName = (bool)$db->query("SHOW COLUMNS FROM invoices LIKE 'bill_to_name'")->fetch();
} catch (Throwable $e) { /* column absent — fall through */ }

// Build the optional SELECT clause dynamically so the query works on
// databases that haven't run migration 1011 yet.
$propertyBillingEntitySelect = $hasPropertyBillingEntity
    ? "NULLIF(p.billing_entity_name, '') AS property_billing_entity,"
    : "NULL AS property_billing_entity,";

// ── 1b. Retry invoices held for a missing PDF on an earlier run ─────────────
// A held invoice is a draft that already exists, so the "already invoiced this
// month" check below would skip it forever — it is retried here instead.
$heldIds = [];
try {
    $heldIds = $sender->heldInvoiceIds();
} catch (Throwable $e) {
    error_log('[contract_billing] held-invoice lookup failed: ' . $e->getMessage());
    $errors[] = 'Held-invoice lookup failed: ' . $e->getMessage();
}
foreach ($heldIds as $heldId) {
    try {
        $res = $sender->send($heldId);
        if ($res['status'] === 'sent') {
            $retried[] = 'retry: ' . $res['message'];
        } elseif ($res['status'] === 'held') {
            $held[]   = $res['message'];
            $errors[] = 'retry: ' . $res['message'];
        } else {
            $errors[] = 'retry: ' . $res['message'];
        }
    } catch (Throwable $e) {
        error_log("[contract_billing] retry of invoice {$heldId} failed: " . $e->getMessage());
        $errors[] = "retry: invoice {$heldId} failed: " . $e->getMessage();
    }
}

// ── 2. Fetch active monthly contracts with contact + property info ────────────
try {
    $stmt = $db->prepare("
        SELECT c.id          AS contract_id,
               c.contract_number,
               c.title,
               c.billing_amount,
               c.billing_cycle,
               c.contact_id,
               c.property_id,
               con.first_name,
               con.last_name,
               con.email        AS contact_email,
               con.mobile       AS contact_mobile,
               con.receive_sms,
               -- Company resolution priority:
               --   1. p.billing_company_id  — explicitly set on the property (most reliable)
               --   2. contact's company_id  — fallback via primary/billing contact join
               COALESCE(cb.id,  co.id)            AS company_id,
               COALESCE(cb.company_name, co.company_name) AS company_name,
               COALESCE(NULLIF(cb.billing_email,''), NULLIF(co.billing_email,'')) AS company_billing_email,
               NULLIF(bc.email, '')                AS billing_contact_email,
               -- Billing entity for the Bill To line.
               -- Priority: property.billing_entity_name → contract.title (when it
               -- looks like a strata/entity, not a service description).
               -- Formatted as entity C/O company_name when a company is set.
               {$propertyBillingEntitySelect}
               c.title          AS contract_title,
               p.address        AS service_address,
               p.city           AS service_city,
               p.province       AS service_province,
               p.postal_code    AS service_postal
        FROM contracts c
        JOIN contacts   con ON con.id = c.contact_id
        LEFT JOIN properties p  ON p.id  = c.property_id
        -- Priority 1: explicit billing company on the property
        LEFT JOIN companies cb ON cb.id = p.billing_company_id
        -- Priority 2: company linked via contact (primary or billing contact)
        LEFT JOIN companies co ON (co.primary_contact_id = con.id
                                 OR co.billing_contact_id = con.id)
        LEFT JOIN contacts  bc ON bc.id = COALESCE(cb.billing_contact_id, co.billing_contact_id)
        WHERE c.status        = 'active'
          AND c.billing_cycle = 'monthly'
          -- A contract with no active service plan has no real work behind a flat
          -- monthly charge — billing it here would duplicate whatever per-visit
          -- invoicing its plan(s) generate once set up. (Root-caused a real
          -- incident: a plan-less contract auto-billed a phantom flat invoice
          -- with no linked plan/visit, on top of a prepayment the client had
          -- already made.)
          AND EXISTS (SELECT 1 FROM job_plans jp WHERE jp.contract_id = c.id AND jp.status = 'active')
        GROUP BY c.id
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
foreach (($retryOnly ? [] : $contracts) as $ctr) {   // retry-only creates nothing new
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

        // ── 3b. Resolve the preferred billing email ──────────────────────────
        // PM-managed properties (properties.property_manager_id set) must bill
        // the management company's accounts contact, honoring its
        // invoice_routing_method — same rule the manual invoice-creation UI
        // uses (InvoiceRouting::resolveManagementBillingRecipient). This cron
        // previously only checked p.billing_company_id / the contact's own
        // company links, which never matches a property_manager_id-based
        // relationship, so PM-managed contracts fell through to the on-site
        // contact's personal email instead of the firm's.
        $billingContactId = $contactId;
        $pmRecipients = !empty($ctr['property_id'])
            ? resolveManagementBillingRecipient((int)$ctr['property_id'])
            : [];

        if (!empty($pmRecipients)) {
            $billingContactId = $pmRecipients[0]['contact_id'] ?: null; // null = contactless (email_address routing)
            $billingEmail     = $pmRecipients[0]['email_address'];
        } else {
            // Priority: companies.billing_email → billing_contact.email → contact.email
            $billingEmail = $ctr['company_billing_email']
                ?: $ctr['billing_contact_email']
                ?: $ctr['contact_email'];
        }

        if (empty($billingEmail)) {
            $skipped[] = $ctr['contract_number'] . ' (no email on contact)';
            continue;
        }

        // ── 3b.2 Bill To heading ──────────────────────────────────────────────
        // Resolved at RENDER time (PdfGenerator / view.php), which composes
        // "{billing_entity_name} C/O {management firm}" for PM-managed strata from
        // live property + property_manager_id data. We deliberately store NULL here
        // rather than a computed name: the old company resolution missed the
        // property_manager_id link, so it stored a partial entity ("VR1450") that
        // overrode the correct render-time heading.
        $billToName = null;

        $db->beginTransaction();

        // ── 3c. Create invoice ────────────────────────────────────────────────
        $subtotal    = round((float)$ctr['billing_amount'], 2);
        $taxAmount   = round($subtotal * $taxRate, 2);
        $total       = round($subtotal + $taxAmount, 2);

        $invoiceNumber = generateInvoiceNumber();
        $accessToken   = generateAccessToken();

        // Build the INSERT dynamically so older databases without
        // invoices.bill_to_name still work. Migration 1010 adds it.
        $insertCols = [
            'invoice_number','contract_id','contact_id','company_id','property_id',
            'invoice_date','issue_date','due_date',
            'subtotal','tax_rate','tax_amount',
            'total_amount','total','balance_due',
            'notes','access_token','token_expires_at',
            'service_address','service_city','service_province','service_postal_code',
            'status','created_by',
        ];
        $insertPlaceholders = [
            '?','?','?','?','?',
            'CURDATE()','CURDATE()','?',
            '?','?','?',
            '?','?','?',
            '?','?',"DATE_ADD(NOW(), INTERVAL 90 DAY)",
            '?','?','?','?',
            "'draft'",'0',
        ];
        $insertParams = [
            $invoiceNumber,
            $contractId,
            $contactId,
            $ctr['company_id'] ?: null,
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
        ];
        if ($hasInvoiceBillToName) {
            $insertCols[]         = 'bill_to_name';
            $insertPlaceholders[] = '?';
            $insertParams[]       = $billToName;
        }
        $sql = "INSERT INTO invoices (" . implode(',', $insertCols) . ") VALUES ("
             . implode(',', $insertPlaceholders) . ")";
        $db->prepare($sql)->execute($insertParams);

        $invoiceId = (int)$db->lastInsertId();

        // ── 3d. Line item ─────────────────────────────────────────────────────
        $lineDesc = trim($ctr['title'] ?? '') ?: 'Monthly landscaping service';
        $lineDesc .= " — {$monthLabel}";

        $db->prepare("
            INSERT INTO invoice_line_items
                (invoice_id, description, quantity, unit_price, line_total)
            VALUES (?, ?, 1, ?, ?)
        ")->execute([$invoiceId, $lineDesc, $subtotal, $subtotal]);

        // ── 3e. Invoice contact — use the resolved billing email/contact so the
        //       view.php recipients table shows where it actually went.
        //       contact_id is nullable (migration 211) for contactless
        //       email_address-routing recipients (e.g. a PM firm's ingestion inbox).
        $db->prepare("
            INSERT INTO invoice_contacts
                (invoice_id, contact_id, contact_role, email_address)
            VALUES (?, ?, 'primary_recipient', ?)
        ")->execute([$invoiceId, $billingContactId, $billingEmail]);

        $db->commit();

        // ── 3f. Send email (PDF required) ─────────────────────────────────────
        // ContractInvoiceSender: no PDF → NOT sent, stays draft, recorded for
        // Charlie + retried at the start of the next run. Sent → status 'sent',
        // autopay, SMS, activity log (same as the old inline code).
        $res = $sender->send($invoiceId);
        if ($res['status'] === 'sent') {
            $created[] = $res['message'];
        } elseif ($res['status'] === 'held') {
            $held[]   = $res['message'];
            $errors[] = "{$ctr['contract_number']}: " . $res['message'];
        } else {
            $errors[] = $res['message'];
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
    'retried'    => count($retried),
    'held'       => count($held),
    'skipped'    => count($skipped),
    'errors'     => count($errors),
    'elapsed_ms' => $elapsedMs,
    'detail'     => [
        'created' => array_merge($created, $retried),
        'held'    => $held,
        'skipped' => $skipped,
        'errors'  => $errors,
    ],
];

if ($isCli) {
    echo "Contract billing complete [{$monthLabel}]\n";
    echo "  Created : " . count($created) . "\n";
    echo "  Retried : " . count($retried) . " (held for PDF earlier, sent now)\n";
    echo "  Held    : " . count($held) . " (NOT sent — invoice PDF could not be made)\n";
    echo "  Skipped : " . count($skipped) . "\n";
    echo "  Errors  : " . count($errors) . "\n";
    if ($errors) {
        foreach ($errors as $err) { echo "  ERROR: {$err}\n"; }
    }
    if ($created || $retried) {
        foreach (array_merge($created, $retried) as $c) { echo "  OK: {$c}\n"; }
    }
    exit(count($errors) > 0 ? 1 : 0);
} else {
    echo json_encode($report, JSON_PRETTY_PRINT);
}
