<?php
/**
 * EmbedLeadRules — pure rules for the embeddable lead form (Marketing/Api/embed-lead.php).
 *
 * A tenant's own website posts enquiries cross-origin with a public key. The key only names the tenant;
 * the exact-Origin check is the guard. No network, no DB, no namespace / autoloader.
 */
final class EmbedLeadRules
{
    /** Hard cap across all visitors of one tenant, so one leaked key cannot flood a tenant's inbox. */
    public const PER_SITE_PER_DAY = 100;

    public const KEY_PATTERN = '/^[a-f0-9]{24}$/';

    public static function keyLooksValid(string $key): bool
    {
        return (bool)preg_match(self::KEY_PATTERN, $key);
    }

    /**
     * Normalise to scheme://host[:port], lower-case, no path. Returns '' for anything that is not an origin.
     * Plain http is accepted only for localhost (local testing).
     */
    public static function normaliseOrigin(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 255) return '';
        $p = parse_url($value);
        if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) return '';
        $scheme = strtolower($p['scheme']);
        $host = strtolower($p['host']);
        if ($scheme !== 'https' && !($scheme === 'http' && ($host === 'localhost' || $host === '127.0.0.1'))) return '';
        return $scheme . '://' . $host . (isset($p['port']) ? ':' . (int)$p['port'] : '');
    }

    /** @return string[] */
    public static function parseOrigins(?string $csv): array
    {
        $out = [];
        foreach (explode(',', (string)$csv) as $part) {
            $o = self::normaliseOrigin($part);
            if ($o !== '') $out[$o] = true;
        }
        return array_keys($out);
    }

    /** True only when the request's Origin header exactly matches one of the tenant's allowed origins. */
    public static function originAllowed(?string $originHeader, ?string $allowedCsv): bool
    {
        $o = self::normaliseOrigin((string)$originHeader);
        return $o !== '' && in_array($o, self::parseOrigins($allowedCsv), true);
    }

    public static function overSiteLimit(int $recentForSite): bool
    {
        return $recentForSite >= self::PER_SITE_PER_DAY;
    }

    /** Where the alert goes: the tenant's lead address, else its main address. '' = nowhere to send. */
    public static function notifyAddress(array $site): string
    {
        foreach (['lead_notify_email', 'email'] as $k) {
            $v = trim((string)($site[$k] ?? ''));
            if ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL)) return $v;
        }
        return '';
    }

    /** Alert email body. Every visitor-supplied value is escaped. */
    public static function notificationHtml(array $f, int $submissionId, string $siteName): string
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return '<h3>New enquiry — ' . $e($siteName) . '</h3>'
            . '<p><strong>Name:</strong> ' . $e($f['name']) . '</p>'
            . '<p><strong>Email:</strong> ' . $e($f['email']) . '</p>'
            . ($f['phone'] !== '' ? '<p><strong>Phone:</strong> ' . $e($f['phone']) . '</p>' : '')
            . ($f['message'] !== '' ? '<p><strong>Message:</strong><br>' . nl2br($e($f['message'])) . '</p>' : '')
            . '<p><strong>Sent from:</strong> ' . $e($f['source_url']) . '</p>'
            . '<p><em>Enquiry #' . $submissionId . '. Reply to the visitor at the email above.</em></p>';
    }
}
