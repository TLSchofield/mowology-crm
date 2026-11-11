<?php
/**
 * UnsubscribeLink — the ONE sign/verify for unsubscribe links (CASL).
 *
 * Fable review 2026-10-12 (LOW): TemplateRenderer signed HMAC(lowercased email) but
 * Marketing/Api/optin.php?action=unsubscribe verified HMAC(email|sid) — so links generated for
 * every email never verified there. Everything now uses this class:
 *   token = HMAC-SHA256(lower(trim(email)), UNSUBSCRIBE_SECRET)   — the format already in sent mail.
 * sid is carried for reporting only; it is not part of the signature (old mail stays valid).
 *
 * No secret → url() still returns a link (that can never verify) and verify() refuses.
 * Pure apart from reading the constant. No namespace / no autoloader.
 */
final class UnsubscribeLink
{
    public const BASE = 'https://mowology.ca/unsubscribe.php';

    public static function secret(): ?string
    {
        return (defined('UNSUBSCRIBE_SECRET') && (string)UNSUBSCRIBE_SECRET !== '') ? (string)UNSUBSCRIBE_SECRET : null;
    }

    public static function normalise(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function token(string $email, ?string $secret = null): string
    {
        $secret = $secret ?? self::secret();
        if ($secret === null || $secret === '') {
            error_log('UnsubscribeLink: UNSUBSCRIBE_SECRET is not defined — link will not verify');
            $secret = bin2hex(random_bytes(32));
        }
        return hash_hmac('sha256', self::normalise($email), $secret);
    }

    public static function verify(string $email, $token, ?string $secret = null): bool
    {
        $secret = $secret ?? self::secret();
        if ($secret === null || $secret === '' || !is_string($token) || $token === '' || self::normalise($email) === '') return false;
        return hash_equals(hash_hmac('sha256', self::normalise($email), $secret), $token);
    }

    public static function url(string $email, int $sendId = 0, ?string $secret = null): string
    {
        return self::BASE . '?' . http_build_query([
            'email' => $email,
            'token' => self::token($email, $secret),
            'sid'   => $sendId,
        ]);
    }
}
