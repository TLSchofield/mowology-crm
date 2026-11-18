<?php
/**
 * CmsFormSubmitRules — limits for the public CMS form endpoint (CMS/Api/cms-form-submit.php).
 *
 * Fable review 2026-10-12 (LOW, public form abuse): no rate limit, unbounded fields, and the IP
 * logged from X-Forwarded-For / CF-Connecting-IP (spoofable — the site is not behind a proxy that
 * sets them). Now: REMOTE_ADDR only, 3 submissions per IP per 24 h (same as privacy-submit),
 * every field clipped.
 *
 * Pure. No namespace / no autoloader.
 */
final class CmsFormSubmitRules
{
    public const PER_IP_PER_DAY = 3;

    public const MAX = [
        'name' => 100, 'email' => 254, 'phone' => 40, 'message' => 5000,
        'form_id' => 64, 'source_url' => 500,
    ];

    /** The client address we trust: the socket peer, never a forwarded header. */
    public static function clientIp(array $server): string
    {
        $ip = (string)($server['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    /** Trimmed, tag-stripped (except email/source_url), clipped to MAX. */
    public static function fields(array $post): array
    {
        $out = [];
        foreach (self::MAX as $k => $max) {
            $v = $post[$k] ?? '';
            $v = is_string($v) ? trim($v) : '';
            if ($k !== 'email' && $k !== 'source_url') $v = trim(strip_tags($v));
            $out[$k] = mb_substr($v, 0, $max, 'UTF-8');
        }
        if ($out['form_id'] === '') $out['form_id'] = 'cms-contact';
        return $out;
    }

    public static function overLimit(int $recentFromIp): bool
    {
        return $recentFromIp >= self::PER_IP_PER_DAY;
    }
}
