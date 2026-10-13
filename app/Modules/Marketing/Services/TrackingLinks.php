<?php
/**
 * TrackingLinks — signed open/click tracking for campaign emails (pure).
 *
 * Fable review 2026-10-12: track-click.php redirected to any http(s) URL (an open redirect on
 * mowology.ca) and anyone could post opens/clicks for any send id, feeding Mia's send-time
 * learning. Now every tracked link carries an HMAC over (send id, url): signed → redirect to the
 * URL that was in our email and count it; unsigned → count nothing and redirect only to a
 * mowology.ca host. Unsubscribe links are never tracked (CaslRules::isUnsubscribeLink()).
 *
 * The key is UNSUBSCRIBE_SECRET with a "track|" domain prefix, so no new secret is needed.
 * No namespace / no autoloader.
 */
final class TrackingLinks
{
    public const HOME = 'https://mowology.ca';

    public static function secret(): string
    {
        if (defined('TRACKING_SECRET') && strlen((string)TRACKING_SECRET) >= 32) return 'track|' . TRACKING_SECRET;
        if (defined('UNSUBSCRIBE_SECRET') && strlen((string)UNSUBSCRIBE_SECRET) >= 32) return 'track|' . UNSUBSCRIBE_SECRET;
        return '';
    }

    public static function sign(int $sid, string $url, ?string $secret = null): string
    {
        $secret = $secret ?? self::secret();
        if ($secret === '' || $sid <= 0) return '';
        return substr(hash_hmac('sha256', $sid . '|' . $url, $secret), 0, 24);
    }

    public static function verify(int $sid, string $url, string $sig, ?string $secret = null): bool
    {
        $expect = self::sign($sid, $url, $secret);
        return $expect !== '' && $sig !== '' && hash_equals($expect, $sig);
    }

    public static function encodeUrl(string $url): string
    {
        return rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
    }

    public static function decodeUrl(string $raw): string
    {
        if ($raw === '' || preg_match('#^https?://#i', $raw)) return $raw;
        $d = base64_decode(strtr($raw, '-_', '+/') . str_repeat('=', (4 - strlen($raw) % 4) % 4), true);
        return $d === false ? '' : $d;
    }

    public static function clickUrl(int $sid, string $url, ?string $secret = null): string
    {
        return 'https://mowology.ca/crm/api/track-click.php?sid=' . $sid . '&url=' . self::encodeUrl($url)
            . '&s=' . self::sign($sid, $url, $secret);
    }

    public static function openUrl(int $sid, ?string $secret = null): string
    {
        return 'https://mowology.ca/crm/api/track-open.php?sid=' . $sid . '&s=' . self::sign($sid, 'open', $secret);
    }

    /**
     * Invoice email open pixel (invoice_contacts.id). Signed like the campaign pixel: an unsigned
     * hit returns the image but records nothing (Fable review 2026-10-12 — the ids are sequential,
     * so anyone could mark every invoice "opened").
     */
    public static function invoiceOpenUrl(int $rid, ?string $secret = null): string
    {
        return 'https://mowology.ca/crm/api/track-invoice-open.php?rid=' . $rid . '&s=' . self::sign($rid, 'invoice-open', $secret);
    }

    public static function isOwnHost(string $url): bool
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if (!preg_match('/^[a-z0-9.-]+$/', $host)) return false;
        return $host === 'mowology.ca' || (strlen($host) > 12 && substr($host, -12) === '.mowology.ca');
    }

    /** Where a click may go: a well-formed http(s) URL, and off-site only when signed. */
    public static function redirectTarget(string $url, bool $signed): string
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return self::HOME;
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || (string)parse_url($url, PHP_URL_HOST) === '') return self::HOME;
        if (!$signed && !self::isOwnHost($url)) return self::HOME;
        return $url;
    }
}
