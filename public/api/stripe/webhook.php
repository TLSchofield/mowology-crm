<?php
/**
 * Stripe Webhook Handler
 * POST /api/stripe/webhook.php
 *
 * Receives Stripe events and updates the CRM accordingly.
 *
 * Security:
 *  - Signature verified with STRIPE_WEBHOOK_SECRET before ANY processing
 *  - No CRM session required (Stripe calls this directly)
 *  - Idempotent: duplicate events for the same PaymentIntent are safely ignored
 *  - No output other than HTTP status codes (never reveal internal errors to Stripe)
 *
 * Handled events:
 *  - payment_intent.succeeded  → mark invoice paid, store payment record
 *  - payment_intent.payment_failed → log failure, update stripe_payments record
 */

declare(strict_types=1);

// ── Bootstrap (no session, no auth — this is a server-to-server endpoint) ────
// We need secrets + DB but NOT the CRM session/auth system.
// Use a minimal bootstrap path.
$appConfigDir = dirname(__DIR__, 2) . '/app_config';
require_once $appConfigDir . '/secrets.php';

// Load the DB helper without pulling in the full auth stack.
// The app config file defines getDB() / Database class.
require_once $appConfigDir . '/config.php';

// Stripe SDK via Composer autoload
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// ── Raw payload MUST be read before any framework processing ─────────────────
$payload   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// ── Verify signature ─────────────────────────────────────────────────────────
try {
    $event = \Stripe\Webhook::constructEvent(
        $payload,
        $sigHeader,
        STRIPE_WEBHOOK_SECRET
    );
} catch (\UnexpectedValueException $e) {
    // Invalid payload
    error_log('[Stripe Webhook] Invalid payload: ' . $e->getMessage());
    http_response_code(400);
    exit;
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    // Invalid signature — reject immediately
    error_log('[Stripe Webhook] Invalid signature: ' . $e->getMessage());
    http_response_code(400);
    exit;
}

error_log(sprintf('[Stripe Webhook] Received event: %s  id: %s', $event->type, $event->id));

// ── Route events ─────────────────────────────────────────────────────────────
$ok = true;
switch ($event->type) {

    case 'payment_intent.succeeded':
        $ok = handlePaymentSucceeded($event->data->object);
        break;

    case 'payment_intent.payment_failed':
        handlePaymentFailed($event->data->object);
        break;

    case 'setup_intent.succeeded':
        handleSetupIntentSucceeded($event->data->object);
        break;

    default:
        // Acknowledge receipt of events we don't handle — Stripe expects 200
        error_log('[Stripe Webhook] Unhandled event type: ' . $event->type);
        break;
}

// If a handler hit a transient DB error it returns false — respond 5xx so Stripe
// RETRIES. Never swallow a failed "mark invoice paid" with a 200: that is exactly
// what left invoices unpaid and allowed customers to be charged multiple times.
if ($ok === false) {
    error_log('[Stripe Webhook] Handler reported failure for event ' . $event->id . ' — responding 500 so Stripe retries');
    http_response_code(500);
    exit;
}

http_response_code(200);
exit;

// ── Handlers ─────────────────────────────────────────────────────────────────

/**
 * Handle payment_intent.succeeded
 * Marks the linked invoice as paid, records the payment audit row.
 */
function handlePaymentSucceeded(\Stripe\PaymentIntent $intent): bool
{
    $paymentIntentId = $intent->id;
    $amountCents     = (int) $intent->amount_received;
    $currency        = strtolower($intent->currency);
    $invoiceId       = (int) ($intent->metadata['invoice_id'] ?? 0);

    error_log(sprintf(
        '[Stripe Webhook] payment_intent.succeeded — pi: %s  invoice_id: %d  amount_cents: %d',
        $paymentIntentId, $invoiceId, $amountCents
    ));

    if ($invoiceId <= 0) {
        error_log('[Stripe Webhook] ERROR: No invoice_id in PaymentIntent metadata for ' . $paymentIntentId);
        return true; // nothing we can do — retrying won't help, ack with 200
    }

    $db = getDB();

    // ── Idempotency check ─────────────────────────────────────────────────────
    $checkStmt = $db->prepare("
        SELECT id, status FROM stripe_payments WHERE payment_intent_id = ? LIMIT 1
    ");
    $checkStmt->execute([$paymentIntentId]);
    $existingPayment = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingPayment && $existingPayment['status'] === 'succeeded') {
        error_log('[Stripe Webhook] Duplicate event — already processed: ' . $paymentIntentId);
        return true; // Already handled, safe to return 200
    }

    // ── Load invoice ──────────────────────────────────────────────────────────
    $invoiceStmt = $db->prepare("
        SELECT id, status, balance_due, total FROM invoices WHERE id = ? LIMIT 1
    ");
    $invoiceStmt->execute([$invoiceId]);
    $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        error_log('[Stripe Webhook] ERROR: Invoice not found: ' . $invoiceId);
        return true; // unknown invoice — retrying won't help, ack with 200
    }

    if ($invoice['status'] === 'paid') {
        error_log('[Stripe Webhook] Invoice already marked paid — updating stripe_payments record only: ' . $invoiceId);
    }

    // Resolve charge ID and receipt URL from the latest charge
    $chargeId   = null;
    $receiptUrl = null;
    if (!empty($intent->latest_charge)) {
        $chargeId = is_string($intent->latest_charge)
            ? $intent->latest_charge
            : ($intent->latest_charge->id ?? null);
        $receiptUrl = is_object($intent->latest_charge)
            ? ($intent->latest_charge->receipt_url ?? null)
            : null;
    }

    // ── Extract card details (for saving on file) ─────────────────────────────
    // Stripe SDK key must be set here for PaymentMethod retrieval
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

    $paymentMethodId = $intent->payment_method ?? null;
    $cardBrand   = null;
    $cardLast4   = null;
    $cardExpiry  = null;
    $stripeCustomerIdFromIntent = is_string($intent->customer) ? $intent->customer : ($intent->customer->id ?? null);

    // Always fetch card details when there's a payment method + customer
    // (setup_future_usage is cleared by Stripe after payment succeeds, so we
    //  instead check whether the stripe_payments record was created with a
    //  customer_id, which means the customer went through our save-card flow)
    if ($paymentMethodId) {
        try {
            $pm = \Stripe\PaymentMethod::retrieve($paymentMethodId);
            if (!empty($pm->card)) {
                $cardBrand  = $pm->card->brand  ?? null;
                $cardLast4  = $pm->card->last4  ?? null;
                $expMonth   = $pm->card->exp_month ?? null;
                $expYear    = $pm->card->exp_year  ?? null;
                if ($expMonth && $expYear) {
                    $cardExpiry = sprintf('%02d/%d', $expMonth, $expYear);
                }
            } else {
                // Non-card PM type (e.g. link, us_bank_account) — skip card save.
                // PIs should now use payment_method_types=['card'] so this is rare.
                error_log(sprintf('[Stripe Webhook] PM %s is type "%s" — skipping card save for contact.', $paymentMethodId, $pm->type ?? 'unknown'));
            }
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log('[Stripe Webhook] Could not retrieve PaymentMethod: ' . $e->getMessage());
        }
    }

    $paidAmount  = $amountCents / 100.0;
    $balanceDue  = max(0, (float) $invoice['balance_due'] - $paidAmount);
    $newStatus   = $balanceDue <= 0.005 ? 'paid' : 'partial'; // tolerance for float rounding

    $contactId    = (int) ($intent->metadata['contact_id'] ?? 0);
    $wantsAutopay = ($intent->metadata['enable_autopay'] ?? '0') === '1';

    // Stripe-side calls (attach PM, set default payment method) are deferred until
    // AFTER the DB commit. If they ran inside the transaction, an API hiccup would
    // roll back the invoice-paid update while we still returned 200 to Stripe —
    // leaving the invoice unpaid and letting the customer be charged again.
    $setDefaultPm = false;

    $db->beginTransaction();
    try {
        // ── Update invoice ────────────────────────────────────────────────────
        if ($invoice['status'] !== 'paid') {
            $updateInvoice = $db->prepare("
                UPDATE invoices SET
                    status                    = ?,
                    amount_paid               = amount_paid + ?,
                    balance_due               = ?,
                    paid_at                   = NOW(),
                    payment_method            = 'stripe',
                    payment_reference         = ?,
                    stripe_payment_intent_id  = ?,
                    stripe_charge_id          = COALESCE(stripe_charge_id, ?)
                WHERE id = ?
            ");
            $updateInvoice->execute([
                $newStatus,
                $paidAmount,
                $balanceDue,
                $paymentIntentId,
                $paymentIntentId,
                $chargeId,          // ch_XXXXXXXX — used for deterministic bank import matching
                $invoiceId,
            ]);
        }

        // ── Upsert stripe_payments audit record ───────────────────────────────
        if ($existingPayment) {
            $upsertStmt = $db->prepare("
                UPDATE stripe_payments SET
                    status               = 'succeeded',
                    stripe_charge_id     = ?,
                    stripe_receipt_url   = ?,
                    raw_event_type       = 'payment_intent.succeeded',
                    webhook_received_at  = NOW()
                WHERE payment_intent_id = ?
            ");
            $upsertStmt->execute([$chargeId, $receiptUrl, $paymentIntentId]);
        } else {
            $insertStmt = $db->prepare("
                INSERT INTO stripe_payments
                    (invoice_id, payment_intent_id, amount_cents, currency,
                     status, stripe_charge_id, stripe_receipt_url,
                     raw_event_type, webhook_received_at)
                VALUES (?, ?, ?, ?, 'succeeded', ?, ?, 'payment_intent.succeeded', NOW())
            ");
            $insertStmt->execute([
                $invoiceId, $paymentIntentId, $amountCents, $currency,
                $chargeId, $receiptUrl,
            ]);
        }

        // ── Save card details to contact (if applicable) ──────────────────────
        if ($contactId > 0 && $cardLast4 && $stripeCustomerIdFromIntent) {
            // Persist card details whenever a Stripe customer is attached
            // (covers both save_card=true and subsequent payments with saved card)
            $updateContact = $db->prepare("
                UPDATE contacts SET
                    stripe_customer_id       = COALESCE(stripe_customer_id, ?),
                    stripe_payment_method_id = COALESCE(?, stripe_payment_method_id),
                    stripe_card_brand        = ?,
                    stripe_card_last4        = ?,
                    stripe_card_exp          = ?,
                    autopay_enabled          = IF(? = 1, 1, autopay_enabled),
                    autopay_enrolled_at      = IF(? = 1 AND autopay_enrolled_at IS NULL, NOW(), autopay_enrolled_at)
                WHERE id = ?
            ");
            $updateContact->execute([
                $stripeCustomerIdFromIntent,
                $paymentMethodId,
                $cardBrand,
                $cardLast4,
                $cardExpiry,
                (int) $wantsAutopay,
                (int) $wantsAutopay,
                $contactId,
            ]);
            error_log(sprintf(
                '[Stripe Webhook] Saved card on file for contact %d: %s ••••%s exp %s autopay=%s',
                $contactId, $cardBrand ?? 'unknown', $cardLast4, $cardExpiry ?? '', $wantsAutopay ? 'enabled' : 'no'
            ));

            // Defer the Stripe-side default-payment-method calls until after commit
            // (see $setDefaultPm note above) so an API error can't roll back the DB.
            if ($paymentMethodId) {
                $setDefaultPm = true;
            }
        }

        // ── Activity log ──────────────────────────────────────────────────────
        // logActivityExtended requires a user_id — use 0/NULL for system actions
        $logStmt = $db->prepare("
            INSERT INTO activity_log (user_id, action, details, invoice_id, created_at)
            VALUES (NULL, 'Stripe payment received', ?, ?, NOW())
        ");
        $logStmt->execute([
            sprintf(
                'Online payment of $%.2f CAD received via Stripe (PaymentIntent: %s). Invoice status: %s.',
                $paidAmount,
                $paymentIntentId,
                $newStatus
            ),
            $invoiceId,
        ]);

        $db->commit();

        error_log(sprintf(
            '[Stripe Webhook] Invoice %d marked %s. Paid: $%.2f, Balance: $%.2f',
            $invoiceId, $newStatus, $paidAmount, $balanceDue
        ));

    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[Stripe Webhook] DB error in handlePaymentSucceeded: ' . $e->getMessage());
        return false; // signal caller to respond 5xx so Stripe retries — invoice NOT yet paid
    }

    // ── Post-commit: external Stripe-side calls (non-fatal, can never roll back DB) ──
    // Sets the saved card as the customer's default so future invoices offer one-click
    // pay. Failures here only affect that convenience, never the recorded payment.
    if ($setDefaultPm && $paymentMethodId && $stripeCustomerIdFromIntent) {
        try {
            // Attach first (idempotent) — saved-card PIs don't guarantee attachment.
            \Stripe\PaymentMethod::attach($paymentMethodId, ['customer' => $stripeCustomerIdFromIntent]);
            \Stripe\Customer::update($stripeCustomerIdFromIntent, [
                'invoice_settings' => ['default_payment_method' => $paymentMethodId],
            ]);
            error_log(sprintf(
                '[Stripe Webhook] Set default_payment_method %s on customer %s',
                $paymentMethodId, $stripeCustomerIdFromIntent
            ));
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log('[Stripe Webhook] Could not set default_payment_method: ' . $e->getMessage());
        }
    }

    return true;
}

/**
 * Handle payment_intent.payment_failed
 * Records the failure in the audit table for visibility.
 */
function handlePaymentFailed(\Stripe\PaymentIntent $intent): void
{
    $paymentIntentId = $intent->id;
    $invoiceId       = (int) ($intent->metadata['invoice_id'] ?? 0);
    $lastError       = $intent->last_payment_error;
    $failureCode     = $lastError->code    ?? null;
    $failureMessage  = $lastError->message ?? null;

    error_log(sprintf(
        '[Stripe Webhook] payment_intent.payment_failed — pi: %s  invoice_id: %d  code: %s',
        $paymentIntentId, $invoiceId, $failureCode ?? 'unknown'
    ));

    if ($invoiceId <= 0) {
        return;
    }

    $db = getDB();

    // Upsert the stripe_payments record with failure info
    $stmt = $db->prepare("
        INSERT INTO stripe_payments
            (invoice_id, payment_intent_id, amount_cents, currency,
             status, failure_code, failure_message,
             raw_event_type, webhook_received_at)
        VALUES (?, ?, ?, 'cad', 'failed', ?, ?, 'payment_intent.payment_failed', NOW())
        ON DUPLICATE KEY UPDATE
            status              = 'failed',
            failure_code        = VALUES(failure_code),
            failure_message     = VALUES(failure_message),
            raw_event_type      = VALUES(raw_event_type),
            webhook_received_at = NOW()
    ");
    $stmt->execute([
        $invoiceId,
        $paymentIntentId,
        (int) $intent->amount,
        $failureCode,
        $failureMessage,
    ]);

    // Log to activity
    $logStmt = $db->prepare("
        INSERT INTO activity_log (user_id, action, details, invoice_id, created_at)
        VALUES (NULL, 'Stripe payment failed', ?, ?, NOW())
    ");
    $logStmt->execute([
        sprintf(
            'Stripe payment failed (PaymentIntent: %s). Code: %s. Message: %s',
            $paymentIntentId,
            $failureCode ?? 'unknown',
            $failureMessage ?? 'No message'
        ),
        $invoiceId,
    ]);
}

/**
 * Handle setup_intent.succeeded
 * Fired when a customer completes the explicit autopay enrollment flow
 * (SetupIntent, no charge). Saves the PaymentMethod and marks the contact
 * as autopay-enrolled.
 */
function handleSetupIntentSucceeded(\Stripe\SetupIntent $intent): void
{
    $contactId = (int) ($intent->metadata['contact_id'] ?? 0);
    if ($contactId <= 0) {
        error_log('[Stripe Webhook] setup_intent.succeeded — no contact_id in metadata');
        return;
    }

    $pmId = is_string($intent->payment_method)
        ? $intent->payment_method
        : ($intent->payment_method->id ?? null);

    if (!$pmId) {
        error_log('[Stripe Webhook] setup_intent.succeeded — no payment_method on intent');
        return;
    }

    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

    $cardBrand  = null;
    $cardLast4  = null;
    $cardExpiry = null;

    try {
        $pm = \Stripe\PaymentMethod::retrieve($pmId);
        if (!empty($pm->card)) {
            $cardBrand = $pm->card->brand  ?? null;
            $cardLast4 = $pm->card->last4  ?? null;
            $expMonth  = $pm->card->exp_month ?? null;
            $expYear   = $pm->card->exp_year  ?? null;
            if ($expMonth && $expYear) {
                $cardExpiry = sprintf('%02d/%d', $expMonth, $expYear);
            }
        }
    } catch (\Stripe\Exception\ApiErrorException $e) {
        error_log('[Stripe Webhook] setup_intent.succeeded — could not retrieve PM: ' . $e->getMessage());
    }

    $stripeCustomerId = is_string($intent->customer) ? $intent->customer : ($intent->customer->id ?? null);

    $db = getDB();
    $stmt = $db->prepare("
        UPDATE contacts SET
            stripe_customer_id       = COALESCE(stripe_customer_id, ?),
            stripe_payment_method_id = ?,
            stripe_card_brand        = COALESCE(?, stripe_card_brand),
            stripe_card_last4        = COALESCE(?, stripe_card_last4),
            stripe_card_exp          = COALESCE(?, stripe_card_exp),
            autopay_enabled          = 1,
            autopay_enrolled_at      = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $stripeCustomerId,
        $pmId,
        $cardBrand,
        $cardLast4,
        $cardExpiry,
        $contactId,
    ]);

    error_log(sprintf(
        '[Stripe Webhook] Autopay enrolled for contact %d via SetupIntent %s — PM: %s',
        $contactId, $intent->id, $pmId
    ));

    $db->prepare("
        INSERT INTO activity_log (user_id, action, details, created_at)
        VALUES (NULL, 'Autopay enrolled', ?, NOW())
    ")->execute([
        sprintf('Contact %d enrolled in autopay via SetupIntent %s.', $contactId, $intent->id),
    ]);
}
