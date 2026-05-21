<?php
/**
 * Save a Stripe payment method to a company record (company-level autopay card).
 *
 * POST params:
 *   company_id         — int
 *   payment_method_id  — Stripe PaymentMethod ID (pm_xxx) from Stripe.js
 *   csrf_token         — CSRF token
 *
 * On success: creates/retrieves Stripe Customer for the company, attaches the
 * payment method, saves card details to companies table, returns JSON success.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
requirePermission('clients.edit');

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    // Handle card removal
    if (($_GET['action'] ?? '') === 'remove') {
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }
        $companyId = (int) ($_POST['company_id'] ?? 0);
        if (!$companyId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing company_id']);
            exit;
        }
        $db = getDB();
        $db->prepare("
            UPDATE companies
            SET stripe_payment_method_id = NULL,
                stripe_card_brand        = NULL,
                stripe_card_last4        = NULL,
                stripe_card_exp          = NULL,
                autopay_enabled          = 0,
                updated_at               = NOW()
            WHERE id = ?
        ")->execute([$companyId]);
        echo json_encode(['success' => true]);
        exit;
    }

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $companyId       = (int) ($_POST['company_id'] ?? 0);
    $paymentMethodId = trim($_POST['payment_method_id'] ?? '');

    if (!$companyId || !$paymentMethodId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit;
    }

    if (!str_starts_with($paymentMethodId, 'pm_')) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid payment method ID']);
        exit;
    }

    $db      = getDB();
    $company = getCompanyById($companyId);
    if (!$company) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Company not found']);
        exit;
    }

    require_once APP_ROOT . '/Core/paths.php';
    require_once PUBLIC_ROOT . '/vendor/autoload.php';
    require_once PUBLIC_ROOT . '/app_config/secrets.php';

    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
    \Stripe\Stripe::setAppInfo('Mowology CRM', '1.0', 'https://mowology.ca');

    // Create or retrieve Stripe Customer for this company
    $stripeCustomerId = $company['stripe_customer_id'] ?? null;

    if (empty($stripeCustomerId)) {
        $billingEmail = $company['billing_email'] ?? $company['primary_email'] ?? null;
        $customer = \Stripe\Customer::create([
            'name'        => $company['company_name'],
            'email'       => $billingEmail ?: null,
            'description' => 'Company ID ' . $companyId . ' — Mowology CRM',
            'metadata'    => [
                'company_id'   => (string) $companyId,
                'company_name' => $company['company_name'],
                'source'       => 'mowology_crm',
            ],
        ]);
        $stripeCustomerId = $customer->id;
    }

    // Attach payment method to customer (idempotent if already attached)
    try {
        $pm = \Stripe\PaymentMethod::retrieve($paymentMethodId);
        if ($pm->customer !== $stripeCustomerId) {
            $pm->attach(['customer' => $stripeCustomerId]);
        }
    } catch (\Stripe\Exception\InvalidRequestException $e) {
        // Already attached to this customer — retrieve fresh copy
        $pm = \Stripe\PaymentMethod::retrieve($paymentMethodId);
    }

    // Set as default payment method on the customer
    \Stripe\Customer::update($stripeCustomerId, [
        'invoice_settings' => ['default_payment_method' => $paymentMethodId],
    ]);

    // Extract card details
    $cardBrand = $pm->card->brand     ?? null;
    $cardLast4 = $pm->card->last4     ?? null;
    $cardExp   = $pm->card->exp_month && $pm->card->exp_year
        ? sprintf('%02d/%s', $pm->card->exp_month, substr((string) $pm->card->exp_year, -2))
        : null;

    // Save to companies table
    $db->prepare("
        UPDATE companies
        SET stripe_customer_id       = ?,
            stripe_payment_method_id = ?,
            stripe_card_brand        = ?,
            stripe_card_last4        = ?,
            stripe_card_exp          = ?,
            autopay_enabled          = 1,
            autopay_enrolled_at      = NOW(),
            updated_at               = NOW()
        WHERE id = ?
    ")->execute([
        $stripeCustomerId,
        $paymentMethodId,
        $cardBrand,
        $cardLast4,
        $cardExp,
        $companyId,
    ]);

    echo json_encode([
        'success'    => true,
        'card_brand' => $cardBrand,
        'card_last4' => $cardLast4,
        'card_exp'   => $cardExp,
    ]);

} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log('[api-save-company-card] Stripe error for company ' . ($companyId ?? '?') . ': ' . $e->getMessage());
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Payment processor error: ' . $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('[api-save-company-card] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
