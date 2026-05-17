<?php
/**
 * Customer Autopay Setup API
 * POST /customer/api/autopay-setup.php
 *
 * No CRM login required — authenticated via invoice access_token.
 *
 * Actions:
 *   create_setup_intent   — returns a SetupIntent client_secret for new-card enrollment
 *   enable_existing_card  — marks autopay_enabled=1 when contact already has a card on file
 *
 * Request: { token: string, action: string }
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

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$token  = trim($input['token']  ?? '');
$action = trim($input['action'] ?? '');

if (empty($token) || empty($action)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing token or action']);
    exit;
}

// ── Resolve contact via invoice access_token ──────────────────────────────────
$db   = getDB();
$stmt = $db->prepare("
    SELECT
        i.id           AS invoice_id,
        ct.id          AS contact_id,
        ct.first_name,
        ct.last_name,
        ct.email,
        ct.stripe_customer_id,
        ct.stripe_payment_method_id,
        ct.stripe_card_last4,
        ct.autopay_enabled
    FROM invoices i
    JOIN contacts ct ON i.contact_id = ct.id
    WHERE i.access_token = ?
      AND (i.token_expires_at IS NULL OR i.token_expires_at > NOW())
    LIMIT 1
");
$stmt->execute([$token]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'Invalid or expired link']);
    exit;
}

$contactId        = (int) $row['contact_id'];
$stripeCustomerId = $row['stripe_customer_id'];

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
\Stripe\Stripe::setAppInfo('Mowology CRM', '1.0', 'https://mowology.ca');

// ── Ensure Stripe Customer exists ─────────────────────────────────────────────
if (!$stripeCustomerId) {
    try {
        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $customer = \Stripe\Customer::create([
            'email'    => $row['email'] ?? null,
            'name'     => $name ?: null,
            'metadata' => [
                'contact_id' => (string) $contactId,
                'source'     => 'autopay_enrollment',
            ],
        ]);
        $stripeCustomerId = $customer->id;
        $db->prepare("UPDATE contacts SET stripe_customer_id = ? WHERE id = ?")
           ->execute([$stripeCustomerId, $contactId]);
    } catch (\Stripe\Exception\ApiErrorException $e) {
        error_log('[Autopay Setup] Could not create Stripe customer: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Payment service temporarily unavailable. Please call (778) 846-9273.']);
        exit;
    }
}

// ── Route by action ───────────────────────────────────────────────────────────

if ($action === 'create_setup_intent') {
    // New-card flow: create a SetupIntent so the customer can save their card.
    // The webhook (setup_intent.succeeded) will persist the PM and enable autopay.
    try {
        $setupIntent = \Stripe\SetupIntent::create([
            'customer'             => $stripeCustomerId,
            'payment_method_types' => ['card'],
            'usage'                => 'off_session',
            'metadata'             => [
                'contact_id' => (string) $contactId,
                'source'     => 'autopay_enrollment',
            ],
        ]);

        echo json_encode([
            'client_secret'    => $setupIntent->client_secret,
            'publishable_key'  => STRIPE_PUBLISHABLE_KEY,
        ]);

    } catch (\Stripe\Exception\ApiErrorException $e) {
        error_log('[Autopay Setup] SetupIntent creation failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Could not initialise card setup. Please try again.']);
    }

} elseif ($action === 'enable_existing_card') {
    // Saved-card flow: contact has a card on file — just record consent.
    if (empty($row['stripe_payment_method_id'])) {
        http_response_code(422);
        echo json_encode(['error' => 'No payment method found. Please add a new card.']);
        exit;
    }

    $db->prepare("
        UPDATE contacts
        SET autopay_enabled = 1, autopay_enrolled_at = NOW()
        WHERE id = ?
    ")->execute([$contactId]);

    $db->prepare("
        INSERT INTO activity_log (user_id, action, details, created_at)
        VALUES (NULL, 'Autopay enrolled', ?, NOW())
    ")->execute([
        sprintf('Contact %d enrolled in autopay using existing card on file.', $contactId),
    ]);

    echo json_encode(['success' => true]);

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
}
