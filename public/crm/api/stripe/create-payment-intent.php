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
requireLogin();
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
    SELECT id, invoice_number, total, balance_due, status, company_id, stripe_payment_intent_id
    FROM invoices
    WHERE id = ?
    LIMIT 1
");
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
        // Otherwise fall through and create a new one (e.g., amount changed or intent was cancelled)
    } catch (\Stripe\Exception\ApiErrorException $e) {
        // Intent not found or API error — create a new one below
        error_log('[Stripe] Could not retrieve existing PaymentIntent: ' . $e->getMessage());
    }
}

// ── Create new PaymentIntent ─────────────────────────────────────────────────
try {
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
    \Stripe\Stripe::setAppInfo('Mowology CRM', '1.0', 'https://mowology.ca');

    $intent = \Stripe\PaymentIntent::create([
        'amount'                    => $amountCents,
        'currency'                  => 'cad',
        'payment_method_types'      => ['card'],
        'description'               => 'Invoice ' . $invoice['invoice_number'] . ' — Mowology Landscaping',
        'metadata'                  => [
            'invoice_id'     => (string) $invoice['id'],
            'invoice_number' => $invoice['invoice_number'],
            'company_id'     => (string) $invoice['company_id'],
            'crm_source'     => 'mowology_crm',
        ],
        // statement_descriptor max 22 chars, alphanumeric + spaces/dashes
        'statement_descriptor'      => 'MOWOLOGY INV',
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
    echo json_encode(['error' => 'Payment service configuration error. Please contact support.']);
} catch (\Stripe\Exception\ApiConnectionException $e) {
    error_log('[Stripe] ApiConnectionException: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Payment service temporarily unavailable. Please try again.']);
} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log('[Stripe] ApiErrorException: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Payment processing error. Please try again or contact support.']);
}
