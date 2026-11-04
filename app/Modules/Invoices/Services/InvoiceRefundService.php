<?php
/**
 * InvoiceRefundService — refund a Stripe-paid invoice from the invoice page.
 *
 * Flow (the Stripe call is deliberately OUTSIDE any DB transaction, like
 * InvoiceService::ensurePaymentIntent — it can take seconds):
 *   1. Reserve: lock the invoice, validate, refuse if another refund is in
 *      flight, insert a 'pending' invoice_refunds row. Commit.
 *   2. Stripe: refunds->create() with an idempotency key tied to that row, so a
 *      retry of the same row can never refund twice.
 *   3. Finalise: mark the row succeeded, add to invoices.amount_refunded and
 *      bring the cash-basis ledger income row down to paid minus refunded.
 *
 * amount_paid is never reduced: a refunded invoice stays "paid" with a zero
 * balance, so reminders never fire. If step 3 fails after Stripe succeeded, the
 * money HAS been returned — we throw a RuntimeException saying so and the admin
 * must not retry.
 *
 * Stripe's processing fee is not returned by Stripe on refund; nothing is
 * booked for it here.
 */
declare(strict_types=1);

class InvoiceRefundService
{
    /** A 'pending' row older than this no longer blocks a new refund attempt. */
    private const PENDING_WINDOW_MINUTES = 10;

    private PDO $db;
    /** @var callable|null Optional Stripe client factory; injected for testing. */
    private $stripeClientFactory = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Inject a factory returning a \Stripe\StripeClient (or a fake with ->refunds->create()). */
    public function setStripeClientFactory(callable $factory): void
    {
        $this->stripeClientFactory = $factory;
    }

    private function stripe(): object
    {
        if ($this->stripeClientFactory) {
            return ($this->stripeClientFactory)();
        }
        if (!defined('STRIPE_SECRET_KEY') || empty(STRIPE_SECRET_KEY)) {
            throw new RuntimeException('STRIPE_SECRET_KEY is not configured');
        }
        // The invoice page does not load Composer's autoloader (the webhook and customer
        // payment endpoints do, themselves). Load it here so the Stripe SDK is available.
        if (!class_exists('\Stripe\StripeClient')) {
            $autoload = (defined('PUBLIC_ROOT') ? PUBLIC_ROOT : dirname(__DIR__, 4)) . '/vendor/autoload.php';
            if (is_file($autoload)) {
                require_once $autoload;
            }
        }
        if (!class_exists('\Stripe\StripeClient')) {
            throw new RuntimeException('The Stripe library is not installed on this server.');
        }
        return new \Stripe\StripeClient(STRIPE_SECRET_KEY);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  PURE RULES (unit-tested without a database)
    // ══════════════════════════════════════════════════════════════════════

    /** Most that can still be refunded: what Stripe took, minus what was already refunded. */
    public static function refundable(float $amountPaid, float $amountRefunded, ?float $stripeCharged = null): float
    {
        $ceiling = $stripeCharged !== null ? min($amountPaid, $stripeCharged) : $amountPaid;
        return round(max(0.0, $ceiling - $amountRefunded), 2);
    }

    /**
     * Validate a requested refund and return the Stripe amount in cents.
     * @throws InvalidArgumentException when the amount is not positive or exceeds refundable
     */
    public static function amountToCents(float $requested, float $refundable): int
    {
        $requested = round($requested, 2);
        if ($requested <= 0) {
            throw new InvalidArgumentException('Enter a refund amount greater than zero.');
        }
        if ($requested > $refundable + 0.005) {
            throw new InvalidArgumentException(
                'Refund cannot exceed the refundable amount of $' . number_format($refundable, 2) . '.'
            );
        }
        return (int) round($requested * 100);
    }

    /**
     * Ledger income figures for an invoice: [amount, gst]. Mirrors
     * AccountingService::syncFromInvoices: income is paid minus refunded, and GST is
     * the invoice's tax in proportion to what was kept (net over the invoice total).
     */
    public static function netIncome(float $amountPaid, float $amountRefunded, float $taxAmount, float $total): array
    {
        $net = round(max(0.0, $amountPaid - max(0.0, $amountRefunded)), 2);
        $gst = $total > 0 ? round($taxAmount * min(1.0, $net / $total), 2) : round($taxAmount, 2);
        return [$net, $net > 0 ? $gst : 0.0];
    }

    // ══════════════════════════════════════════════════════════════════════
    //  REFUND
    // ══════════════════════════════════════════════════════════════════════

    /**
     * @return array{invoice_id:int,invoice_number:string,refund_id:int,stripe_refund_id:string,amount:float,total_refunded:float}
     * @throws InvalidArgumentException bad input (nothing was sent to Stripe)
     * @throws RuntimeException         refused or failed; message is safe to show the admin
     */
    public function refund(int $invoiceId, float $amount, string $reason, int $userId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required to refund a payment.');
        }
        $reason = mb_substr($reason, 0, 500);

        // ── 1. Reserve ──────────────────────────────────────────────────
        $this->db->beginTransaction();
        try {
            $inv = $this->lockInvoice($invoiceId);
            if (!$inv) {
                throw new RuntimeException("Invoice #{$invoiceId} not found.");
            }
            $paymentIntent = trim((string) ($inv['stripe_payment_intent_id'] ?? ''));
            if (($inv['payment_method'] ?? '') !== 'stripe' || $paymentIntent === '') {
                throw new RuntimeException('This invoice was not paid through Stripe, so it cannot be refunded here.');
            }

            $charged    = $this->stripeCharged($paymentIntent);
            $refundable = self::refundable((float) $inv['amount_paid'], (float) $inv['amount_refunded'], $charged);
            $cents      = self::amountToCents($amount, $refundable);

            $inFlight = $this->db->prepare("
                SELECT COUNT(*) FROM invoice_refunds
                WHERE invoice_id = ? AND status = 'pending'
                  AND created_at > (NOW() - INTERVAL " . self::PENDING_WINDOW_MINUTES . " MINUTE)
            ");
            $inFlight->execute([$invoiceId]);
            if ((int) $inFlight->fetchColumn() > 0) {
                throw new RuntimeException('A refund for this invoice is already in progress. Wait a minute and check the invoice.');
            }

            $this->db->prepare("
                INSERT INTO invoice_refunds (invoice_id, amount, reason, status, created_by)
                VALUES (?, ?, ?, 'pending', ?)
            ")->execute([$invoiceId, $cents / 100, $reason, $userId ?: null]);
            $refundRowId = (int) $this->db->lastInsertId();

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }

        // ── 2. Stripe (no DB transaction open) ──────────────────────────
        try {
            $stripeRefund = $this->stripe()->refunds->create(
                [
                    'payment_intent' => $paymentIntent,
                    'amount'         => $cents,
                    'metadata'       => [
                        'invoice_id'     => (string) $invoiceId,
                        'invoice_number' => (string) $inv['invoice_number'],
                        'refund_row'     => (string) $refundRowId,
                    ],
                ],
                ['idempotency_key' => 'mw-refund-' . $refundRowId]
            );
        } catch (Throwable $e) {
            $this->markFailed($refundRowId, $e->getMessage());
            error_log('invoice refund Stripe error, invoice ' . $invoiceId . ': ' . $e->getMessage());
            throw new RuntimeException('Stripe could not process the refund: ' . $e->getMessage());
        }
        $stripeRefundId = (string) ($stripeRefund->id ?? '');

        // ── 3. Finalise ─────────────────────────────────────────────────
        try {
            $this->db->beginTransaction();
            $this->db->prepare("
                UPDATE invoice_refunds SET stripe_refund_id = ?, status = 'succeeded' WHERE id = ?
            ")->execute([$stripeRefundId !== '' ? $stripeRefundId : null, $refundRowId]);
            $this->db->prepare("
                UPDATE invoices SET amount_refunded = amount_refunded + ? WHERE id = ?
            ")->execute([$cents / 100, $invoiceId]);
            $this->syncIncomeRow($invoiceId);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('invoice refund SUCCEEDED AT STRIPE but DB update failed, invoice ' . $invoiceId
                . ' refund row ' . $refundRowId . ' stripe ' . $stripeRefundId . ': ' . $e->getMessage());
            throw new RuntimeException(
                'The refund WAS issued by Stripe (' . $stripeRefundId . ') but the CRM could not record it. '
                . 'Do not retry. Tell the developer: invoice ' . $inv['invoice_number'] . ', refund row ' . $refundRowId . '.'
            );
        }

        $total = $this->db->prepare("SELECT amount_refunded FROM invoices WHERE id = ?");
        $total->execute([$invoiceId]);

        return [
            'invoice_id'       => $invoiceId,
            'invoice_number'   => (string) $inv['invoice_number'],
            'refund_id'        => $refundRowId,
            'stripe_refund_id' => $stripeRefundId,
            'amount'           => $cents / 100,
            'total_refunded'   => round((float) $total->fetchColumn(), 2),
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    //  INTERNALS
    // ══════════════════════════════════════════════════════════════════════

    private function lockInvoice(int $invoiceId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, invoice_number, total, tax_amount, amount_paid, amount_refunded,
                   payment_method, stripe_payment_intent_id
            FROM invoices WHERE id = ? FOR UPDATE
        ");
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** What Stripe actually charged for this PaymentIntent, or null if we have no record. */
    private function stripeCharged(string $paymentIntent): ?float
    {
        $stmt = $this->db->prepare("
            SELECT amount_cents FROM stripe_payments
            WHERE payment_intent_id = ? AND status = 'succeeded' LIMIT 1
        ");
        $stmt->execute([$paymentIntent]);
        $cents = $stmt->fetchColumn();
        return $cents === false || $cents === null ? null : round(((int) $cents) / 100, 2);
    }

    private function markFailed(int $refundRowId, string $message): void
    {
        try {
            $this->db->prepare("
                UPDATE invoice_refunds SET status = 'failed', error_message = ? WHERE id = ?
            ")->execute([mb_substr($message, 0, 500), $refundRowId]);
        } catch (Throwable $e) {
            error_log('invoice refund: could not mark row ' . $refundRowId . ' failed: ' . $e->getMessage());
        }
    }

    /**
     * Bring the cash-basis ledger income row for this invoice to paid minus refunded
     * (GST scaled to match). A fully refunded invoice has its income row removed.
     * Updates an existing row only: AccountingService::syncFromInvoices creates
     * missing rows using the same net figures.
     */
    private function syncIncomeRow(int $invoiceId): void
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(amount_paid, 0) AS paid, COALESCE(amount_refunded, 0) AS refunded,
                   COALESCE(tax_amount, 0) AS tax, COALESCE(total, 0) AS total
            FROM invoices WHERE id = ?
        ");
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;

        [$amount, $gst] = self::netIncome((float) $row['paid'], (float) $row['refunded'], (float) $row['tax'], (float) $row['total']);

        if ($amount <= 0) {
            $this->db->prepare("
                DELETE FROM accounting_transactions WHERE reference_type = 'invoice' AND reference_id = ?
            ")->execute([$invoiceId]);
            return;
        }
        $this->db->prepare("
            UPDATE accounting_transactions
            SET amount = ?, gst_amount = ?, updated_at = CURRENT_TIMESTAMP
            WHERE reference_type = 'invoice' AND reference_id = ?
        ")->execute([$amount, $gst, $invoiceId]);
    }
}
