<?php
declare(strict_types=1);

/** Pure naming and status rules shared by every invoice PaymentIntent creator. */
class PaymentIntentGuard
{
    public static function lockName(int $invoiceId): string
    {
        return 'invoice_pi_' . $invoiceId;
    }

    public static function idempotencyKey(int $invoiceId, int $amountCents, int $earlierPayments): string
    {
        return 'inv-' . $invoiceId . '-' . $amountCents . '-' . $earlierPayments;
    }

    public static function blocksCreate(string $status): bool
    {
        return in_array($status, ['succeeded', 'processing'], true);
    }
}
