<?php
/**
 * AutopayEnrollmentPolicy — who may switch autopay on (security review #13, 2026-10-12).
 *
 * An invoice link is a bearer token: a property manager or accountant it was forwarded to could
 * press "enable autopay" and have the client's SAVED card charged for every future invoice. Autopay
 * is now switched on only by the cardholder entering the card in Stripe's own form:
 *   - a SetupIntent confirmed in the browser (autopay.php → webhook setup_intent.succeeded), or
 *   - a payment made with a NEWLY entered card that asked for autopay (webhook payment_intent.succeeded).
 * Paying with the card already on file, or "use my saved card", never switches it on.
 *
 * Pure. Global namespace; require_once it.
 */
class AutopayEnrollmentPolicy
{
    /** May this successful payment switch autopay on? Only when it used a card other than the one on file. */
    public static function mayEnableFromPayment(bool $wantsAutopay, ?string $usedPaymentMethod, ?string $savedPaymentMethod): bool
    {
        if (!$wantsAutopay || $usedPaymentMethod === null || $usedPaymentMethod === '') return false;
        return $savedPaymentMethod === null || $savedPaymentMethod === '' || !hash_equals($savedPaymentMethod, $usedPaymentMethod);
    }

    /** "use the saved card" is never enough on its own. */
    public static function existingCardNeedsConfirmation(): bool
    {
        return true;
    }

    /** Who did it, for the activity log: the link used and the IP (never the token itself). */
    public static function actor(string $via, string $ip, string $userAgent = ''): string
    {
        return sprintf('via %s from IP %s%s', $via, $ip !== '' ? $ip : 'unknown',
                       $userAgent !== '' ? ' (' . mb_substr($userAgent, 0, 120) . ')' : '');
    }
}
