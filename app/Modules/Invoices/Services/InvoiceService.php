<?php
/**
 * InvoiceService — shared invoice lifecycle helpers.
 *
 * Phase 1a scope (this file): the v2 payment-flow plumbing — creating a
 * Stripe PaymentIntent at invoice-send time, persisting the client_secret
 * on the invoice row, and enabling per-invoice opt-in to the v2 customer
 * payment page. Plus access-token generation, which both
 * crm/invoices/view.php (Send) and Contracts/Cron/contract_billing.php
 * already do with identical logic and should share.
 *
 * Phase 1b (separate commit): the email-compose + send-status extraction
 * from those two call sites.
 *
 * Design notes:
 *  - Stripe is injected via setStripeKey() so tests don't need network access
 *    and so we never accidentally create real PaymentIntents during unit tests.
 *  - ensurePaymentIntent() MUST be called outside any DB transaction —
 *    Stripe calls can take seconds and would hold the row lock otherwise.
 *  - All methods are idempotent — safe to call repeatedly. ensurePaymentIntent
 *    reuses an existing card-only PI when the amount still matches.
 */
declare(strict_types=1);

class InvoiceService
{
    private PDO $db;
    /** @var callable|null Optional Stripe API client factory; injected for testing. */
    private $stripeClientFactory = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Inject a Stripe client factory for testing. Production code does not call
     * this — it lets the service create the client lazily from STRIPE_SECRET_KEY.
     *
     * The factory is a callable returning a \Stripe\StripeClient (or a mock).
     */
    public function setStripeClientFactory(callable $factory): void
    {
        $this->stripeClientFactory = $factory;
    }

    /**
     * Lazily build (or return injected) Stripe client. Returns an object whose
     * shape matches \Stripe\StripeClient (->customers, ->paymentIntents) — we
     * type as `object` rather than the concrete class so tests can inject fakes.
     */
    private function stripe(): object
    {
        if ($this->stripeClientFactory) {
            return ($this->stripeClientFactory)();
        }
        if (!defined('STRIPE_SECRET_KEY') || empty(STRIPE_SECRET_KEY)) {
            throw new RuntimeException('STRIPE_SECRET_KEY is not configured');
        }
        return new \Stripe\StripeClient(STRIPE_SECRET_KEY);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  ACCESS TOKEN
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Ensure the invoice has an unexpired access_token. Generates a fresh
     * one (90-day expiry) if missing or expired. Returns the token.
     *
     * Mirrors the existing logic at:
     *  - crm/invoices/view.php (Send action, ~line 165)
     *  - Contracts/Cron/contract_billing.php (~line 216, fresh per row)
     */
    public function ensureAccessToken(int $invoiceId): string
    {
        $row = $this->db->prepare("
            SELECT access_token, token_expires_at FROM invoices WHERE id = ? LIMIT 1
        ");
        $row->execute([$invoiceId]);
        $cur = $row->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            throw new InvalidArgumentException("Invoice #{$invoiceId} not found");
        }

        $expired = !empty($cur['token_expires_at'])
                && strtotime($cur['token_expires_at']) < time();

        if (!empty($cur['access_token']) && !$expired) {
            return $cur['access_token'];
        }

        $token = bin2hex(random_bytes(32));
        $this->db->prepare("
            UPDATE invoices
               SET access_token     = ?,
                   token_expires_at = DATE_ADD(NOW(), INTERVAL 90 DAY)
             WHERE id = ?
        ")->execute([$token, $invoiceId]);

        return $token;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  PAYMENT INTENT (v2 flow)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Create or reuse a Stripe PaymentIntent for this invoice's balance_due.
     * Stores client_secret, pi_created_at, and stripe_payment_intent_id on
     * the invoice row. Returns the client_secret.
     *
     * Reuse rules: if the invoice already has a stripe_payment_intent_id and
     * Stripe still considers it reusable (status in [requires_payment_method,
     * requires_confirmation, requires_action], amount matches, card-only),
     * the existing PI is returned. Otherwise a fresh one is created.
     *
     * MUST be called OUTSIDE any DB transaction — Stripe API calls can be
     * slow and would hold the invoice row lock otherwise.
     *
     * Throws RuntimeException on:
     *  - Invoice not found
     *  - Invoice has zero balance_due (nothing to charge)
     *  - Stripe API failure
     */
    public function ensurePaymentIntent(int $invoiceId): string
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException(
                'ensurePaymentIntent must not be called inside a DB transaction — '
                . 'Stripe API calls can be slow and would hold the row lock.'
            );
        }

        $row = $this->db->prepare("
            SELECT i.id, i.invoice_number, i.balance_due, i.contact_id,
                   i.stripe_payment_intent_id, i.stripe_client_secret,
                   c.first_name, c.last_name, c.email, c.stripe_customer_id
            FROM invoices i
            LEFT JOIN contacts c ON i.contact_id = c.id
            WHERE i.id = ?
            LIMIT 1
        ");
        $row->execute([$invoiceId]);
        $invoice = $row->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            throw new InvalidArgumentException("Invoice #{$invoiceId} not found");
        }

        $balance = (float)$invoice['balance_due'];
        if ($balance <= 0.005) {
            throw new RuntimeException(
                "Invoice #{$invoiceId} has no outstanding balance ({$balance}); "
                . 'cannot create PaymentIntent.'
            );
        }
        $amountCents = (int) round($balance * 100);

        $stripe = $this->stripe();

        // ── Resolve or create Stripe Customer (so receipts go to the right address) ──
        $stripeCustomerId = $invoice['stripe_customer_id'] ?? null;
        if (!$stripeCustomerId && !empty($invoice['contact_id'])) {
            $name = trim(($invoice['first_name'] ?? '') . ' ' . ($invoice['last_name'] ?? ''));
            try {
                $cust = $stripe->customers->create([
                    'email'    => $invoice['email'] ?? null,
                    'name'     => $name ?: null,
                    'metadata' => [
                        'contact_id'     => (string) $invoice['contact_id'],
                        'invoice_number' => $invoice['invoice_number'],
                        'source'         => 'mowology_crm_v2_send',
                    ],
                ]);
                $stripeCustomerId = $cust->id;
                $this->db->prepare("UPDATE contacts SET stripe_customer_id = ? WHERE id = ?")
                         ->execute([$stripeCustomerId, $invoice['contact_id']]);
            } catch (\Stripe\Exception\ApiErrorException $e) {
                // Non-fatal — proceed without customer linkage. The PI still works.
                $this->logStripeWarn('Could not create Stripe Customer at v2 send', [
                    'contact_id' => $invoice['contact_id'],
                    'error'      => $e->getMessage(),
                ]);
                $stripeCustomerId = null;
            }
        }

        // ── Reuse existing PI if still valid + card-only + amount matches ──
        $existingPi = $invoice['stripe_payment_intent_id'] ?? null;
        if ($existingPi) {
            try {
                $pi = $stripe->paymentIntents->retrieve($existingPi);

                $reusableStatuses = ['requires_payment_method', 'requires_confirmation', 'requires_action'];
                $isReusable = in_array($pi->status, $reusableStatuses, true)
                           && $pi->amount === $amountCents;
                $cardOnly   = (count($pi->payment_method_types ?? []) === 1)
                           && (($pi->payment_method_types[0] ?? '') === 'card');

                if ($isReusable && $cardOnly) {
                    // Keep the persisted client_secret in sync (e.g. if the row
                    // had only stripe_payment_intent_id from the v1 flow).
                    $this->db->prepare("
                        UPDATE invoices
                           SET stripe_client_secret = ?,
                               pi_created_at = COALESCE(pi_created_at, NOW())
                         WHERE id = ?
                    ")->execute([$pi->client_secret, $invoiceId]);
                    return $pi->client_secret;
                }
            } catch (\Stripe\Exception\ApiErrorException $e) {
                // PI not retrievable (deleted / wrong-mode / network) — fall through to create a fresh one.
                $this->logStripeWarn('Could not retrieve existing PaymentIntent; creating fresh', [
                    'pi_id' => $existingPi,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // ── Create fresh PI ──
        $params = [
            'amount'               => $amountCents,
            'currency'             => 'cad',
            'description'          => 'Invoice ' . $invoice['invoice_number'] . ' — Mowology Landscaping',
            'payment_method_types' => ['card'],
            'metadata'             => [
                'invoice_id'     => (string) $invoiceId,
                'invoice_number' => $invoice['invoice_number'],
                'contact_id'     => (string) ($invoice['contact_id'] ?? ''),
                'source'         => 'mowology_crm_v2_send',
            ],
        ];
        if ($stripeCustomerId) {
            $params['customer'] = $stripeCustomerId;
        }

        try {
            $pi = $stripe->paymentIntents->create($params);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->logStripeWarn('PaymentIntent create failed at v2 send', [
                'invoice_id' => $invoiceId,
                'error'      => $e->getMessage(),
            ]);
            throw new RuntimeException(
                "Stripe PaymentIntent create failed for invoice #{$invoiceId}: " . $e->getMessage(),
                0, $e
            );
        }

        $this->db->prepare("
            UPDATE invoices
               SET stripe_payment_intent_id = ?,
                   stripe_client_secret     = ?,
                   pi_created_at            = NOW()
             WHERE id = ?
        ")->execute([$pi->id, $pi->client_secret, $invoiceId]);

        return $pi->client_secret;
    }

    /**
     * Mark an invoice as using the v2 payment flow + ensure its PaymentIntent
     * exists. Idempotent. Returns the client_secret.
     *
     * This is the single entry point both Send-action paths call once Phase 1b
     * lands. Existing v1 invoices in the wild are not affected.
     */
    public function enableV2PaymentFlow(int $invoiceId): string
    {
        // Set the flag first so a partial failure still routes the customer
        // to the v2 page (which will lazily fall back if client_secret is null).
        $this->db->prepare("
            UPDATE invoices SET payment_flow_version = 2 WHERE id = ? AND payment_flow_version != 2
        ")->execute([$invoiceId]);

        return $this->ensurePaymentIntent($invoiceId);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Internal helpers
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Wrap writeSystemLog() so the service is testable without that global
     * being defined. Falls back to error_log when the helper is missing.
     */
    private function logStripeWarn(string $message, array $context = []): void
    {
        if (function_exists('writeSystemLog')) {
            writeSystemLog('warning', 'stripe', $message, $context);
            return;
        }
        error_log('[InvoiceService stripe.warning] ' . $message . ' ' . json_encode($context));
    }
}
