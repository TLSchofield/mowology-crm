<?php
/**
 * One-time backfill: reads stripe_payments records that have a stripe_customer_id
 * and writes card details back to contacts from the Stripe PaymentMethod.
 * Run once, then delete.
 */
declare(strict_types=1);
header('Content-Type: application/json');

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

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

// vendor/ lives inside PUBLIC_ROOT on production (same level as app_config/)
$vendorAutoload = PUBLIC_ROOT . '/vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    // Local dev: vendor is one level up from public/
    $vendorAutoload = dirname(PUBLIC_ROOT) . '/vendor/autoload.php';
}
require_once $vendorAutoload;
require_once PUBLIC_ROOT . '/app_config/secrets.php';

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

$db = getDB();
$results = [];

// Get all contacts that have a stripe_customer_id but no card details yet
// Also include contacts whose invoices have a stripe_payment_intent_id
$rows = $db->query("
    SELECT DISTINCT
        ct.id AS contact_id,
        ct.stripe_customer_id,
        ct.stripe_card_last4,
        i.stripe_payment_intent_id
    FROM contacts ct
    LEFT JOIN invoices i ON i.contact_id = ct.id AND i.stripe_payment_intent_id IS NOT NULL
    WHERE (ct.stripe_card_last4 IS NULL OR ct.stripe_card_last4 = '')
      AND (ct.stripe_customer_id IS NOT NULL OR i.stripe_payment_intent_id IS NOT NULL)
    ORDER BY ct.id
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    $contactId = (int) $row['contact_id'];
    $custId    = $row['stripe_customer_id'];
    $piId      = $row['stripe_payment_intent_id'];

    $brand = null; $last4 = null; $expiry = null; $pmId = null;

    try {
        // Strategy 1: Look up via PaymentIntent
        if ($piId) {
            $pi = \Stripe\PaymentIntent::retrieve($piId);
            $pmId  = $pi->payment_method ?? null;
            $custId = $custId ?: (is_string($pi->customer) ? $pi->customer : ($pi->customer->id ?? null));
        }

        // Strategy 2: Look up default payment method on Stripe Customer
        if (!$pmId && $custId) {
            $cust = \Stripe\Customer::retrieve($custId);
            $pmId = $cust->invoice_settings->default_payment_method ?? null;
            if (!$pmId) {
                // List payment methods
                $pms = \Stripe\PaymentMethod::all(['customer' => $custId, 'type' => 'card', 'limit' => 1]);
                $pmId = $pms->data[0]->id ?? null;
            }
        }

        if (!$pmId) {
            $results[] = ['contact_id' => $contactId, 'status' => 'no_payment_method'];
            continue;
        }

        $pm = \Stripe\PaymentMethod::retrieve($pmId);
        if (empty($pm->card)) {
            $results[] = ['contact_id' => $contactId, 'status' => 'no_card_on_pm'];
            continue;
        }

        $brand  = $pm->card->brand ?? null;
        $last4  = $pm->card->last4 ?? null;
        $expM   = $pm->card->exp_month ?? null;
        $expY   = $pm->card->exp_year  ?? null;
        $expiry = ($expM && $expY) ? sprintf('%02d/%d', $expM, $expY) : null;

        $db->prepare("
            UPDATE contacts SET
                stripe_customer_id = COALESCE(stripe_customer_id, ?),
                stripe_card_brand  = ?,
                stripe_card_last4  = ?,
                stripe_card_exp    = ?
            WHERE id = ?
        ")->execute([$custId, $brand, $last4, $expiry, $contactId]);

        $results[] = [
            'contact_id' => $contactId,
            'pi'         => $piId,
            'cust'       => $custId,
            'status'     => 'updated',
            'brand'      => $brand,
            'last4'      => $last4,
            'exp'        => $expiry,
        ];

    } catch (\Stripe\Exception\ApiErrorException $e) {
        $results[] = ['contact_id' => $contactId, 'status' => 'stripe_error', 'msg' => $e->getMessage()];
    }
}

echo json_encode(['backfill' => 'stripe_cards', 'processed' => count($rows), 'results' => $results, 'done' => true], JSON_PRETTY_PRINT);
