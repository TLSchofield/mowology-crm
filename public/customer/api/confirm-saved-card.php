<?php
/**
 * Confirm a PaymentIntent using the contact's saved card (server-side).
 * POST /customer/api/confirm-saved-card.php
 *
 * Avoids Stripe.js confirmCardPayment() which is incompatible with
 * automatic_payment_methods PaymentIntents and can detach saved cards.
 *
 * Request:  { token: string }
 * Response: { status: 'succeeded' }
 *           { status: 'requires_action', next_action_client_secret: string }
 *           { error: string }
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;
}

require_once dirname(__DIR__, 2) . '/app_config/config.php';
require_once dirname(__DIR__, 2) . '/app_config/secrets.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$token = trim($input['token'] ?? '');

if (empty($token)) {
    http_response_code(400); echo json_encode(['error' => 'Missing token']); exit;
}

$db   = getDB();
$stmt = $db->prepare("
    SELECT i.id AS invoice_id, i.stripe_payment_intent_id, i.balance_due, i.status,
           ct.stripe_customer_id, ct.stripe_payment_method_id
    FROM invoices i
    JOIN contacts ct ON i.contact_id = ct.id
    WHERE i.access_token = ?
      AND (i.token_expires_at IS NULL OR i.token_expires_at > NOW())
    LIMIT 1
");
$stmt->execute([$token]);
$row = $stmt->fetch(\PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404); echo json_encode(['error' => 'Invalid or expired link']); exit;
}

if (empty($row['stripe_payment_method_id'])) {
    http_response_code(422); echo json_encode(['error' => 'No saved card on file']); exit;
}

if (empty($row['stripe_payment_intent_id'])) {
    http_response_code(422); echo json_encode(['error' => 'No payment intent found — please refresh and try again']); exit;
}

try {
    $intent = \Stripe\PaymentIntent::retrieve($row['stripe_payment_intent_id']);

    // Already succeeded — idempotent
    if ($intent->status === 'succeeded') {
        echo json_encode(['status' => 'succeeded']); exit;
    }

    if (!in_array($intent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
        http_response_code(422);
        echo json_encode(['error' => 'Payment cannot be confirmed in its current state: ' . $intent->status]);
        exit;
    }

    // Confirm server-side using the saved PM
    $confirmed = \Stripe\PaymentIntent::update($row['stripe_payment_intent_id'], [
        'payment_method' => $row['stripe_payment_method_id'],
    ]);
    $confirmed = \Stripe\PaymentIntent::confirm($row['stripe_payment_intent_id'], [
        'return_url' => 'https://mowology.ca/customer/invoice.php?token=' . urlencode($token) . '&payment=success',
    ]);

    if ($confirmed->status === 'succeeded') {
        echo json_encode(['status' => 'succeeded']);
    } elseif ($confirmed->status === 'requires_action') {
        // 3DS — send client_secret so JS can handle the challenge
        echo json_encode([
            'status'                    => 'requires_action',
            'next_action_client_secret' => $confirmed->client_secret,
        ]);
    } else {
        echo json_encode(['error' => 'Payment could not be confirmed. Status: ' . $confirmed->status]);
    }

} catch (\Stripe\Exception\CardException $e) {
    http_response_code(402);
    echo json_encode(['error' => $e->getError()->message ?? 'Your card was declined.']);
} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log('[confirm-saved-card] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Payment service temporarily unavailable. Please try again.']);
}
