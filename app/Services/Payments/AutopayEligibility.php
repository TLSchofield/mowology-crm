<?php
/**
 * AutopayEligibility — the one rule for "may this invoice be auto-charged right now?".
 *
 * Pure (no database, no Stripe) so it is unit-tested. AutopayService calls it twice:
 * once for the cheap pre-check (isAutopayEligible) and once again inside the
 * transaction that holds the invoice row lock (attemptCharge), so two callers that
 * both passed the pre-check cannot both insert a 'pending' attempt and both charge
 * (Fable review 2026-10-12).
 *
 * $data is AutopayService::loadInvoiceData() output (invoice_status, balance_due,
 * stripe_payment_method_id, autopay_override, autopay_enabled).
 */

declare(strict_types=1);

final class AutopayEligibility
{
    public const PAYABLE_STATUSES = ['sent', 'viewed', 'partial', 'overdue'];

    /**
     * Why this invoice must NOT be charged, or null when it may be.
     *
     * @param array|null $data            invoice + payment-source row, null when not found
     * @param bool       $hasOpenAttempt  an autopay_attempts row is 'pending' or 'succeeded'
     */
    public static function blockReason(?array $data, bool $hasOpenAttempt): ?string
    {
        if (!$data) {
            return 'Invoice not found';
        }
        $status = (string) ($data['invoice_status'] ?? '');
        if (!in_array($status, self::PAYABLE_STATUSES, true)) {
            return 'Invoice not in payable status: ' . $status;
        }
        if (empty($data['stripe_payment_method_id'])) {
            return 'No payment method on file';
        }
        if (!self::autopayOn($data)) {
            return 'Autopay not enabled for this invoice';
        }
        if ((float) ($data['balance_due'] ?? 0) <= 0) {
            return 'No balance due';
        }
        if ($hasOpenAttempt) {
            return 'A charge for this invoice is already pending or succeeded';
        }
        return null;
    }

    /** Plan override wins over the payer's default. */
    public static function autopayOn(array $data): bool
    {
        if (array_key_exists('autopay_override', $data) && $data['autopay_override'] !== null) {
            return (bool) $data['autopay_override'];
        }
        return (bool) ($data['autopay_enabled'] ?? false);
    }

    /** Stripe idempotency key for one attempt: a retried request can never charge twice. */
    public static function idempotencyKey(int $invoiceId, int $attemptNumber): string
    {
        return "autopay-{$invoiceId}-{$attemptNumber}";
    }
}
