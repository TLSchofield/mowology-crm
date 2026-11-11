<?php
/**
 * StripeErrorRules — what a Stripe error means for "could this customer have been charged?".
 *
 * Pure (class names, HTTP status and Stripe error codes in; no SDK needed) so it is unit-tested
 * here, where the Stripe library isn't installed. From Fable's invoicing review, 2026-10-13:
 *   - the customer pay page forgot a stored PaymentIntent on ANY error (a network blip included)
 *     and made a new one — a second charge if the first was still processing;
 *   - autopay retried an unknown-outcome error three days later with a new idempotency key.
 * Stripe PHP 19.3 on production: ApiConnectionException, UnknownApiErrorException,
 * IdempotencyException, RateLimitException (an InvalidRequestException), CardException…
 *
 * No namespace / no autoloader: require_once and call statically.
 */
declare(strict_types=1);

final class StripeErrorRules
{
    /** A stored PaymentIntent may be forgotten (and a new one made) only when Stripe says it doesn't exist. */
    public static function canForgetIntent(?string $stripeCode): bool
    {
        return $stripeCode === 'resource_missing';
    }

    /**
     * After a failed charge request: is it unknown whether Stripe charged the card?
     * True for a lost connection, Stripe's own 5xx, an unknown error, or a clash on the
     * idempotency key (the first request may still be running). False for a refusal
     * Stripe answered clearly (card declined, bad request, rate limit, auth).
     */
    public static function chargeOutcomeUnknown(string $exceptionClass, ?int $httpStatus): bool
    {
        $short = substr(strrchr('\\' . ltrim($exceptionClass, '\\'), '\\'), 1);
        if (in_array($short, ['ApiConnectionException', 'UnknownApiErrorException', 'IdempotencyException'], true)) return true;
        return $httpStatus === null || $httpStatus >= 500;
    }
}
