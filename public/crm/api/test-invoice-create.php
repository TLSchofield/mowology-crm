<?php
/**
 * Admin tool — create a $1 test invoice on Tim Schofield's contact (#22)
 * with payment_flow_version=2 so we can pay it end-to-end on the new
 * /customer/invoice-v2.php page without touching any real customer invoice.
 *
 * GET  /crm/api/test-invoice-create.php          → renders a tiny form
 * POST /crm/api/test-invoice-create.php          → creates the invoice
 *                                                   + returns the v2 URL
 *
 * Hard-coded constraints (safety):
 *   - Contact MUST be #22 (Tim Schofield). This script will refuse to run
 *     against any other contact, so an admin clicking around can't
 *     accidentally bill a customer.
 *   - Amount is locked to $1.00.
 *   - status is set to 'sent' immediately (no email actually sent).
 *
 * Once we've validated v2 end-to-end, the actual production wiring lives
 * in InvoiceService::sendInvoice() (Phase 1b/4) and this file gets removed.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/loginAuth/auth.php';
requireLogin();
requirePermission('database.manage'); // same gate as migration runners

$user = getCurrentUser();

// ── Hard-coded test parameters ─────────────────────────────────────────────
const TEST_CONTACT_ID = 22;
const TEST_AMOUNT     = 1.00;

// Pull the upward search to find paths.php so APP_ROOT is set; needed by
// InvoiceService when it reaches into Stripe constants.
$__dir = __DIR__;
for ($__i = 0; $__i < 6; $__i++) {
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
    $__dir = dirname($__dir);
}
unset($__dir, $__i);

// Stripe SDK lives in public/vendor; load both composer's autoload AND Stripe's
// own init.php so this works regardless of which is dropped on the server.
$__vendor = dirname(__DIR__, 2) . '/vendor';
if (is_file($__vendor . '/autoload.php'))           require_once $__vendor . '/autoload.php';
if (is_file($__vendor . '/stripe/stripe-php/init.php')) require_once $__vendor . '/stripe/stripe-php/init.php';
unset($__vendor);

if (!class_exists('\Stripe\StripeClient')) {
    http_response_code(500);
    exit('Stripe SDK not installed. Expected at public/vendor/stripe/stripe-php — confirm composer install ran.');
}

require_once APP_ROOT . '/Modules/Invoices/Services/InvoiceService.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$db = getDB();

// Verify the test contact exists and is who we think it is — refuse otherwise.
$ct = $db->prepare("SELECT id, first_name, last_name, email, stripe_customer_id FROM contacts WHERE id = ?");
$ct->execute([TEST_CONTACT_ID]);
$contact = $ct->fetch(PDO::FETCH_ASSOC);
if (!$contact) {
    http_response_code(404);
    exit('Test contact #' . TEST_CONTACT_ID . ' not found. Refusing to create test invoice.');
}
$expectedFirst = 'Tim';
$expectedLast  = 'Schofield';
if ($contact['first_name'] !== $expectedFirst || $contact['last_name'] !== $expectedLast) {
    http_response_code(409);
    exit('Test contact #' . TEST_CONTACT_ID . ' is not Tim Schofield (' .
         htmlspecialchars($contact['first_name'] . ' ' . $contact['last_name']) .
         '). Refusing to create test invoice.');
}

$result = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    try {
        $svc = new InvoiceService($db);

        // ── Insert the invoice (transactional — keeps Stripe out of the txn) ──
        $db->beginTransaction();
        try {
            $invoiceNumber = generateInvoiceNumber();
            $accessToken   = bin2hex(random_bytes(32));
            $today         = date('Y-m-d');
            $dueDate       = date('Y-m-d', strtotime('+30 days'));

            $db->prepare("
                INSERT INTO invoices (
                    invoice_number, contact_id, property_id,
                    invoice_date, issue_date, due_date,
                    subtotal, tax_rate, tax_amount,
                    total_amount, total, balance_due,
                    notes, access_token, token_expires_at,
                    status, created_by, sent_at
                ) VALUES (
                    ?, ?, NULL,
                    ?, ?, ?,
                    ?, 0.00, 0.00,
                    ?, ?, ?,
                    ?, ?, DATE_ADD(NOW(), INTERVAL 90 DAY),
                    'sent', ?, NOW()
                )
            ")->execute([
                $invoiceNumber, TEST_CONTACT_ID,
                $today, $today, $dueDate,
                TEST_AMOUNT,
                TEST_AMOUNT, TEST_AMOUNT, TEST_AMOUNT,
                'TEST INVOICE — created via /crm/api/test-invoice-create.php for v2 payment flow validation. Safe to pay; refund afterwards.',
                $accessToken,
                (int) $user['id'],
            ]);
            $invoiceId = (int) $db->lastInsertId();

            $db->prepare("
                INSERT INTO invoice_line_items (invoice_id, description, quantity, unit_price, line_total)
                VALUES (?, ?, 1, ?, ?)
            ")->execute([$invoiceId, 'V2 payment flow test charge', TEST_AMOUNT, TEST_AMOUNT]);

            $db->prepare("
                INSERT INTO invoice_contacts (invoice_id, contact_id, contact_role, email_address, invoice_sent_at)
                VALUES (?, ?, 'primary_recipient', ?, NOW())
            ")->execute([$invoiceId, TEST_CONTACT_ID, $contact['email']]);

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        // ── Now flip to v2 + create the PaymentIntent (OUTSIDE transaction) ──
        $clientSecret = $svc->enableV2PaymentFlow($invoiceId);
        $svc->ensureAccessToken($invoiceId); // already set above; idempotent

        $result = [
            'invoice_id'      => $invoiceId,
            'invoice_number'  => $invoiceNumber,
            'amount'          => TEST_AMOUNT,
            'access_token'    => $accessToken,
            'client_secret'   => substr($clientSecret, 0, 24) . '…',
            'v2_url'          => 'https://mowology.ca/customer/invoice-v2.php?token=' . $accessToken,
            'v1_url'          => 'https://mowology.ca/customer/invoice.php?token=' . $accessToken,
        ];
    } catch (Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
        error_log('test-invoice-create: ' . $error);
    }
}

$csrf = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>V2 Test Invoice Creator</title>
<style>
body{font-family:ui-monospace,monospace;background:#f5f5f5;padding:2rem;max-width:760px;margin:0 auto;color:#0D3B2E}
h1{margin-top:0}
.box{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:1.25rem;margin-bottom:1rem}
.ok{border-left:4px solid #2D8659;background:#F0FDF4}
.err{border-left:4px solid #DC2626;background:#FEF2F2}
.warn{border-left:4px solid #F59E0B;background:#FFFBEB}
.btn{background:#2D8659;color:#fff;border:0;padding:.6rem 1.2rem;border-radius:6px;font-family:inherit;font-size:14px;cursor:pointer}
.btn:hover{background:#1A5F4A}
a{color:#2D8659;word-break:break-all}
code{background:#f0f0f0;padding:1px 4px;border-radius:3px}
pre{background:#1a1a1a;color:#a0e8a0;padding:.75rem;border-radius:6px;overflow:auto;font-size:12px}
</style>
</head>
<body>

<h1>V2 Payment-Flow Test Invoice</h1>

<div class="box warn">
<strong>Safety constraints:</strong> hard-coded to contact #<?php echo TEST_CONTACT_ID; ?>
(<?php echo htmlspecialchars($contact['first_name'] . ' ' . $contact['last_name']); ?>),
amount <code>$<?php echo number_format(TEST_AMOUNT, 2); ?></code>,
<code>payment_flow_version = 2</code>. Refuses to run against any other contact.
</div>

<?php if ($result): ?>
<div class="box ok">
<strong>Test invoice created.</strong> Pay it on the v2 page below; refund the
charge in Stripe Dashboard once you've confirmed it works.<br><br>

<table cellpadding="3">
<tr><td>Invoice number:</td><td><strong><?php echo htmlspecialchars($result['invoice_number']); ?></strong></td></tr>
<tr><td>Invoice id:</td><td>#<?php echo (int) $result['invoice_id']; ?></td></tr>
<tr><td>Amount:</td><td>$<?php echo number_format($result['amount'], 2); ?> CAD</td></tr>
<tr><td>client_secret:</td><td><code><?php echo htmlspecialchars($result['client_secret']); ?></code></td></tr>
</table>

<p>
<strong>V2 payment URL (the new flow):</strong><br>
<a href="<?php echo htmlspecialchars($result['v2_url']); ?>" target="_blank"><?php echo htmlspecialchars($result['v2_url']); ?></a>
</p>

<p style="font-size:12px;color:#666">
V1 URL for reference (would 302 to v2 because the flag is set):<br>
<a href="<?php echo htmlspecialchars($result['v1_url']); ?>" target="_blank"><?php echo htmlspecialchars($result['v1_url']); ?></a>
</p>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="box err">
<strong>Error:</strong>
<pre><?php echo htmlspecialchars($error); ?></pre>
</div>
<?php endif; ?>

<form method="post">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
<button type="submit" class="btn">Create test invoice ($<?php echo number_format(TEST_AMOUNT, 2); ?>)</button>
</form>

</body>
</html>
