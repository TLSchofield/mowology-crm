<?php
/**
 * Customer Invoice — Create/Reuse Stripe PaymentIntent
 * POST /customer/api/invoice-payment-intent.php
 *
 * No CRM login required — authenticated via invoice access_token.
 * Accepts:  { token: string, save_card: bool }
 * Returns:  { client_secret: string, publishable_key: string, amount_cents: int, currency: string,
 *             has_saved_card: bool, card_brand: string, card_last4: string }
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once dirname(__DIR__, 2) . '/app_config/config.php';
require_once dirname(__DIR__, 2) . '/app_config/secrets.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/crm/includes/functions.php';

$input    = json_decode(file_get_contents('php://input'), true);
$token    = trim($input['token'] ?? '');
$saveCard = !empty($input['save_card']); // customer opted in to save card

if (empty($token)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing token']);
    exit;
}

// ── Load invoice + contact ────────────────────────────────────────────────────
$db   = getDB();
$stmt = $db->prepare("
    SELECT i.id, i.invoice_number, i.total, i.total_amount, i.balance_due, i.status,
           i.company_id, i.contact_id, i.stripe_payment_intent_id,
           ct.id          AS ct_id,
           ct.first_name  AS ct_first,
           ct.last_name   AS ct_last,
           ct.email       AS ct_email,
           ct.stripe_customer_id AS ct_stripe_customer_id,
           ct.stripe_card_brand  AS ct_card_brand,
           ct.stripe_card_last4  AS ct_card_last4,
           ct.stripe_card_exp    AS ct_card_exp
    FROM invoices i
    LEFT JOIN contacts ct ON i.contact_id = ct.id
    WHERE i.access_token = ?
      AND (i.token_expires_at IS NULL OR i.token_expires_at > NOW())
    LIMIT 1
");
$stmt->execute([$token]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    http_response_code(404);
    echo json_encode(['error' => 'Invoice not found or link has expired']);
    exit;
}

$payableStatuses = ['sent', 'viewed', 'partial', 'overdue'];
if (!in_array($invoice['status'], $payableStatuses, true)) {
    http_response_code(422);
    echo json_encode(['error' => 'This invoice is not currently payable (status: ' . $invoice['status'] . ')']);
    exit;
}

$balanceDue = (float) $invoice['balance_due'];
if ($balanceDue <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'Invoice has no outstanding balance']);
    exit;
}

$amountCents = (int) round($balanceDue * 100);

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
\Stripe\Stripe::setAppInfo('Mowology CRM', '1.0', 'https://mowology.ca');

// ── Resolve or create Stripe Customer ────────────────────────────────────────
$stripeCustomerId = $invoice['ct_stripe_customer_id'] ?? null;
$contactId        = (int) ($invoice['ct_id'] ?? 0);

if ($contactId && !$stripeCustomerId) {
    // Create a new Stripe Customer for this contact
    try {
        $customerName = trim(($invoice['ct_first'] ?? '') . ' ' . ($invoice['ct_last'] ?? ''));
        $customer = \Stripe\Customer::create([
            'email'    => $invoice['ct_email'] ?? null,
            'name'     => $customerName ?: null,
            'metadata' => [
                'contact_id'     => (string) $contactId,
                'invoice_number' => $invoice['invoice_number'],
                'source'         => 'mowology_crm',
            ],
        ]);
        $stripeCustomerId = $customer->id;

        // Persist immediately so future invoices reuse this customer
        $db->prepare("UPDATE contacts SET stripe_customer_id = ? WHERE id = ?")
           ->execute([$stripeCustomerId, $contactId]);

    } catch (\Stripe\Exception\ApiErrorException $e) {
        writeSystemLog('warning', 'stripe', 'Could not create Stripe Customer', ['contact_id' => $contactId, 'error' => $e->getMessage()]);
        // Non-fatal — proceed without customer linkage
        $stripeCustomerId = null;
    }
}

// ── Check if contact has a saved card already ─────────────────────────────────
$hasSavedCard = !empty($invoice['ct_card_last4']);
$savedCardBrand = $invoice['ct_card_brand'] ?? null;
$savedCardLast4 = $invoice['ct_card_last4'] ?? null;
$savedCardExp   = $invoice['ct_card_exp']   ?? null;

// If they have a saved card, also get the default payment method from Stripe
$defaultPaymentMethodId = null;
if ($hasSavedCard && $stripeCustomerId) {
    try {
        $customer = \Stripe\Customer::retrieve($stripeCustomerId);
        $defaultPaymentMethodId = $customer->invoice_settings->default_payment_method ?? null;
    } catch (\Stripe\Exception\ApiErrorException $e) {
        writeSystemLog('warning', 'stripe', 'Could not retrieve Stripe Customer', ['stripe_customer_id' => $stripeCustomerId, 'error' => $e->getMessage()]);
    }
}

// ── Reuse existing PaymentIntent if still valid ───────────────────────────────
if (!empty($invoice['stripe_payment_intent_id'])) {
    try {
        $existing = \Stripe\PaymentIntent::retrieve($invoice['stripe_payment_intent_id']);

        if (
            in_array($existing->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)
            && $existing->amount === $amountCents
        ) {
            echo json_encode([
                'client_secret'           => $existing->client_secret,
                'publishable_key'         => STRIPE_PUBLISHABLE_KEY,
                'amount_cents'            => $amountCents,
                'currency'                => $existing->currency,
                'has_saved_card'          => $hasSavedCard,
                'saved_card_brand'        => $savedCardBrand,
                'saved_card_last4'        => $savedCardLast4,
                'saved_card_exp'          => $savedCardExp,
                'default_payment_method'  => $defaultPaymentMethodId,
            ]);
            exit;
        }
    } catch (\Stripe\Exception\ApiErrorException $e) {
        writeSystemLog('warning', 'stripe', 'Could not retrieve existing PaymentIntent', ['pi_id' => $invoice['stripe_payment_intent_id'], 'error' => $e->getMessage()]);
    }
}

// ── Create new PaymentIntent ──────────────────────────────────────────────────
try {
    $intentParams = [
        'amount'      => $amountCents,
        'currency'    => 'cad',
        'description' => 'Invoice ' . $invoice['invoice_number'] . ' — Mowology Landscaping',
        'metadata'    => [
            'invoice_id'     => (string) $invoice['id'],
            'invoice_number' => $invoice['invoice_number'],
            'contact_id'     => (string) $contactId,
            'source'         => 'customer_portal',
        ],
        'statement_descriptor'        => 'MOWOLOGY INV',
        'automatic_payment_methods'   => ['enabled' => true],
    ];

    // Link to Stripe Customer if we have one
    if ($stripeCustomerId) {
        $intentParams['customer'] = $stripeCustomerId;
    }

    // If customer opted to save card, enable future usage
    if ($saveCard && $stripeCustomerId) {
        $intentParams['setup_future_usage'] = 'off_session';
    }

    // If they have a saved card and didn't request a new one, pre-attach it
    if ($defaultPaymentMethodId && !$saveCard) {
        $intentParams['payment_method'] = $defaultPaymentMethodId;
    }

    $intent = \Stripe\PaymentIntent::create($intentParams);

    // Persist PaymentIntent ID
    $db->prepare("UPDATE invoices SET stripe_payment_intent_id = ? WHERE id = ?")
       ->execute([$intent->id, $invoice['id']]);

    // Record in stripe_payments (idempotent) — fault-tolerant
    try {
        $db->prepare("
            INSERT IGNORE INTO stripe_payments
                (invoice_id, payment_intent_id, amount_cents, currency, stripe_customer_id, status)
            VALUES (?, ?, ?, 'cad', ?, 'created')
        ")->execute([$invoice['id'], $intent->id, $amountCents, $stripeCustomerId]);
    } catch (PDOException $auditEx) {
        writeSystemLog('warning', 'stripe', 'stripe_payments audit insert failed', ['invoice_id' => $invoice['id'], 'error' => $auditEx->getMessage()]);
    }

    echo json_encode([
        'client_secret'           => $intent->client_secret,
        'publishable_key'         => STRIPE_PUBLISHABLE_KEY,
        'amount_cents'            => $amountCents,
        'currency'                => $intent->currency,
        'has_saved_card'          => $hasSavedCard,
        'saved_card_brand'        => $savedCardBrand,
        'saved_card_last4'        => $savedCardLast4,
        'saved_card_exp'          => $savedCardExp,
        'default_payment_method'  => $defaultPaymentMethodId,
    ]);

} catch (\Throwable $e) {
    writeSystemLog('error', 'stripe', get_class($e) . ': ' . $e->getMessage(), [
        'invoice_id' => $invoice['id'] ?? null,
        'file'       => $e->getFile() . ':' . $e->getLine(),
    ]);
    http_response_code(500);
    echo json_encode(['error' => 'Payment service temporarily unavailable. Please try again or call us at (778) 846-9273.']);
}
