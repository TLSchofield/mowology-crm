<?php
/**
 * Create Stripe PaymentIntent
 * POST /crm/api/stripe/create-payment-intent.php
 *
 * Accepts:  { invoice_id: int }
 * Returns:  { client_secret: string, publishable_key: string, amount: int, currency: string }
 *
 * Security:
 *  - CRM login required (internal staff use only — not customer-facing)
 *  - Invoice must exist and be in an unpaid status
 *  - Amount comes from the database, never from the client
 *  - CSRF is handled via X-Requested-With header check + CRM session validation
 */

declare(strict_types=1);

// ── Auth ─────────────────────────────────────────────────────────────────────
require_once dirname(__DIR__, 3) . '/loginAuth/auth.php';
require_once APP_ROOT . '/Services/Messaging/IdentityMail.php';
require_once APP_ROOT . '/Core/ApiResponse.php'; // generic errors to the user, details to the log
requireLogin();
session_write_close();
requirePermission('billing.view');

// ── Bootstrap ────────────────────────────────────────────────────────────────
require_once dirname(__DIR__, 3) . '/app_config/secrets.php';
require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

header('Content-Type: application/json');

// Only allow POST from XHR
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Input validation ─────────────────────────────────────────────────────────
$input     = json_decode(file_get_contents('php://input'), true);
$invoiceId = isset($input['invoice_id']) ? (int) $input['invoice_id'] : 0;

if ($invoiceId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid invoice_id']);
    exit;
}

// ── Load invoice from DB (source of truth for amount) ────────────────────────
$db   = getDB();
$stmt = $db->prepare("
    SELECT id, invoice_number, total, balance_due, status, company_id, stripe_payment_intent_id, access_token
    FROM invoices
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$invoiceId]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

// One payment request per invoice at a time — the SAME lock as the customer page (customer/api/invoice-payment-intent.php),
// so two clicks here, or here and the client's page at once, can't each make an intent (Codex payment review 2026-10-16).
if ($invoice && !empty($invoice['access_token'])) {
    $piLock = 'mw_inv_pay_' . md5((string)$invoice['access_token']);
    if ((int)$db->query("SELECT GET_LOCK(" . $db->quote($piLock) . ", 15)")->fetchColumn() !== 1) {
        http_response_code(409);
        echo json_encode(['error' => 'A payment for this invoice is being set up already — try again in a moment.']);
        exit;
    }
    register_shutdown_function(function () use ($db, $piLock) {
        try { $db->query("SELECT RELEASE_LOCK(" . $db->quote($piLock) . ")"); } catch (\Throwable $e) { /* lock went with the connection */ }
    });
}
// Read the invoice AGAIN under the lock: the customer page may have just replaced its intent (Codex re-review).
$stmt->execute([$invoiceId]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    http_response_code(404);
    echo json_encode(['error' => 'Invoice not found']);
    exit;
}

// Prevent payment on already-paid or cancelled invoices
$payableStatuses = ['sent', 'viewed', 'partial', 'overdue'];
if (!in_array($invoice['status'], $payableStatuses, true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Invoice is not in a payable status (status: ' . $invoice['status'] . ')']);
    exit;
}

$balanceDue = (float) $invoice['balance_due'];
if ($balanceDue <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'Invoice has no outstanding balance']);
    exit;
}

// Stripe amounts are integers in the smallest currency unit (cents for CAD)
$amountCents = (int) round($balanceDue * 100);

// ── Reuse existing PaymentIntent if one was already created ──────────────────
// This prevents duplicate intents if the customer opens the payment page twice.
if (!empty($invoice['stripe_payment_intent_id'])) {
    try {
        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
        $existingIntent = \Stripe\PaymentIntent::retrieve($invoice['stripe_payment_intent_id']);

        // Guard (Fable review 2026-10-12): an intent that already succeeded or is still
        // processing means the money is (or is about to be) taken. Falling through used to
        // null the id and create a second intent — a double charge while the webhook lagged.
        // Same guard as public/customer/api/invoice-payment-intent.php.
        if (in_array($existingIntent->status, ['succeeded', 'processing'], true)) {
            http_response_code(409);
            echo json_encode([
                'already_paid' => true,
                'status'       => $existingIntent->status,
                'error'        => $existingIntent->status === 'processing'
                    ? 'A payment for this invoice is already processing. Wait for it to finish before taking another.'
                    : 'This invoice has already been paid by card. The payment will show on the invoice shortly.',
            ]);
            exit;
        }

        // Reuse if still valid and amount matches
        if (
            in_array($existingIntent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)
            && $existingIntent->amount === $amountCents
        ) {
            error_log(sprintf(
                '[Stripe] Reusing PaymentIntent %s for invoice %s',
                $existingIntent->id,
                $invoice['invoice_number']
            ));
            echo json_encode([
                'client_secret'    => $existingIntent->client_secret,
                'publishable_key'  => STRIPE_PUBLISHABLE_KEY,
                'amount_cents'     => $amountCents,
                'currency'         => $existingIntent->currency,
                'invoice_number'   => $invoice['invoice_number'],
            ]);
            exit;
        }
        // Otherwise fall through and create a new one (e.g., amount changed or intent was cancelled).
        // An open intent for the OLD amount is cancelled first, so it can't still be charged (Codex payment review 2026-10-16).
        if (in_array($existingIntent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
            try { $existingIntent->cancel(); }
            catch (\Throwable $e) {
                // Couldn't cancel: it may still be chargeable — keep its id and make NO second one (Codex re-review).
                error_log('[Stripe] Could not cancel stale PaymentIntent ' . $existingIntent->id . ': ' . $e->getMessage());
                http_response_code(503);
                echo json_encode(['error' => 'Could not replace the earlier payment request — try again in a moment.']);
                exit;
            }
        }
        // Clear the stale ID so we don't try to reuse it again
        $db->prepare("UPDATE invoices SET stripe_payment_intent_id = NULL WHERE id = ?")->execute([$invoiceId]);
    } catch (\Stripe\Exception\ApiErrorException $e) {
        error_log('[Stripe] Could not retrieve existing PaymentIntent: ' . $e->getMessage());
        // Only a missing intent is safe to forget. Any other error (network, rate limit)
        // leaves us unable to tell whether the old intent was paid — stop rather than
        // risk a second charge.
        if ($e->getStripeCode() !== 'resource_missing') {
            http_response_code(502);
            echo json_encode(['error' => 'Could not check the earlier card payment for this invoice. Try again in a minute.']);
            exit;
        }
        $db->prepare("UPDATE invoices SET stripe_payment_intent_id = NULL WHERE id = ?")->execute([$invoiceId]);
    }
}

// ── Create new PaymentIntent ─────────────────────────────────────────────────
try {
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
    \Stripe\Stripe::setAppInfo(_idFromName() . ' CRM', '1.0', _idSiteUrl());

    $intent = \Stripe\PaymentIntent::create([
        'amount'                      => $amountCents,
        'currency'                    => 'cad',
        'automatic_payment_methods'   => ['enabled' => true],
        'description'                 => 'Invoice ' . $invoice['invoice_number'] . ' — ' . _idCompanyInfo()['company_name'],
        'metadata'                    => [
            'invoice_id'     => (string) $invoice['id'],
            'invoice_number' => $invoice['invoice_number'],
            'company_id'     => (string) ($invoice['company_id'] ?? ''),
            'crm_source'     => 'mowology_crm',
        ],
        'statement_descriptor_suffix' => (substr(preg_replace('/[^A-Za-z0-9 ]/', '', _idFromName()), 0, 22) ?: 'Invoice'),
    ]);

    // Persist the PaymentIntent ID so we can reuse it and match it in the webhook
    $updateStmt = $db->prepare("
        UPDATE invoices SET stripe_payment_intent_id = ? WHERE id = ?
    ");
    $updateStmt->execute([$intent->id, $invoiceId]);

    // Also insert a pending stripe_payments record (idempotent via UNIQUE KEY)
    $insertStmt = $db->prepare("
        INSERT IGNORE INTO stripe_payments
            (invoice_id, payment_intent_id, amount_cents, currency, status)
        VALUES (?, ?, ?, 'cad', 'created')
    ");
    $insertStmt->execute([$invoiceId, $intent->id, $amountCents]);

    error_log(sprintf(
        '[Stripe] Created PaymentIntent %s for invoice %s (%.2f CAD)',
        $intent->id,
        $invoice['invoice_number'],
        $balanceDue
    ));

    echo json_encode([
        'client_secret'   => $intent->client_secret,
        'publishable_key' => STRIPE_PUBLISHABLE_KEY,
        'amount_cents'    => $amountCents,
        'currency'        => $intent->currency,
        'invoice_number'  => $invoice['invoice_number'],
    ]);

} catch (\Stripe\Exception\AuthenticationException $e) {
    error_log('[Stripe] AuthenticationException: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Payment service configuration error: ' . ApiResponse::message($e, 'create-payment-intent')]);
} catch (\Stripe\Exception\ApiConnectionException $e) {
    error_log('[Stripe] ApiConnectionException: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Payment service temporarily unavailable. Please try again.']);
} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log('[Stripe] ApiErrorException: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Stripe error: ' . ApiResponse::message($e, 'create-payment-intent')]);
}
