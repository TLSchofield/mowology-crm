<?php
/**
 * Customer Invoice — Create/Reuse Stripe PaymentIntent
 * POST /customer/api/invoice-payment-intent.php
 *
 * No CRM login required — authenticated via invoice access_token.
 * Accepts:  { token: string }
 * Returns:  { client_secret: string, publishable_key: string, amount_cents: int, currency: string }
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Only allow POST from XHR
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once dirname(__DIR__, 2) . '/app_config/config.php';
require_once dirname(__DIR__, 2) . '/app_config/secrets.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// ── Input validation ──────────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);
$token = trim($input['token'] ?? '');

if (empty($token)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing token']);
    exit;
}

// ── Load invoice by access token ──────────────────────────────────────────────
$db   = getDB();
$stmt = $db->prepare("
    SELECT id, invoice_number, total, total_amount, balance_due, status,
           company_id, stripe_payment_intent_id
    FROM invoices
    WHERE access_token = ?
      AND (token_expires_at IS NULL OR token_expires_at > NOW())
    LIMIT 1
");
$stmt->execute([$token]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    http_response_code(404);
    echo json_encode(['error' => 'Invoice not found or link has expired']);
    exit;
}

// Only allow payment on payable statuses
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

// ── Reuse existing PaymentIntent if still valid ───────────────────────────────
if (!empty($invoice['stripe_payment_intent_id'])) {
    try {
        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
        $existing = \Stripe\PaymentIntent::retrieve($invoice['stripe_payment_intent_id']);

        if (
            in_array($existing->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)
            && $existing->amount === $amountCents
        ) {
            echo json_encode([
                'client_secret'   => $existing->client_secret,
                'publishable_key' => STRIPE_PUBLISHABLE_KEY,
                'amount_cents'    => $amountCents,
                'currency'        => $existing->currency,
            ]);
            exit;
        }
    } catch (\Stripe\Exception\ApiErrorException $e) {
        error_log('[Stripe Customer] Could not retrieve PaymentIntent: ' . $e->getMessage());
    }
}

// ── Create new PaymentIntent ──────────────────────────────────────────────────
try {
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
    \Stripe\Stripe::setAppInfo('Mowology CRM', '1.0', 'https://mowology.ca');

    $intent = \Stripe\PaymentIntent::create([
        'amount'               => $amountCents,
        'currency'             => 'cad',
        'payment_method_types' => ['card'],
        'description'          => 'Invoice ' . $invoice['invoice_number'] . ' — Mowology Landscaping',
        'metadata'             => [
            'invoice_id'     => (string) $invoice['id'],
            'invoice_number' => $invoice['invoice_number'],
            'source'         => 'customer_portal',
        ],
        'statement_descriptor' => 'MOWOLOGY INV',
    ]);

    // Persist PaymentIntent ID
    $db->prepare("UPDATE invoices SET stripe_payment_intent_id = ? WHERE id = ?")->execute([$intent->id, $invoice['id']]);

    // Record in stripe_payments (idempotent)
    $db->prepare("
        INSERT IGNORE INTO stripe_payments (invoice_id, payment_intent_id, amount_cents, currency, status)
        VALUES (?, ?, ?, 'cad', 'created')
    ")->execute([$invoice['id'], $intent->id, $amountCents]);

    echo json_encode([
        'client_secret'   => $intent->client_secret,
        'publishable_key' => STRIPE_PUBLISHABLE_KEY,
        'amount_cents'    => $amountCents,
        'currency'        => $intent->currency,
    ]);

} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log('[Stripe Customer] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Payment service temporarily unavailable. Please try again or call us at (778) 846-9273.']);
}
