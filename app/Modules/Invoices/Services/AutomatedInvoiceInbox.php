<?php
/**
 * Which invoice addresses belong to an automated invoicing system.
 *
 * An accounts-payable platform (Condoworks / LeapAP and the like) already holds the
 * invoice once it is received, and rejects any mail without a PDF or image attached.
 * A link-only payment reminder to it bounces and teaches nobody anything, so
 * reminders go to people only (Tim, 2026-10-30).
 *
 * Matches on the address's domain, subdomains included, so app.condoworks.co is
 * caught by condoworks.co. Deliberately NOT matching on the local part: ap@ and
 * invoices@ are often real people. More domains can be added without a deploy via
 * the ops_settings key `automated_invoice_domains` (comma separated).
 */

declare(strict_types=1);

class AutomatedInvoiceInbox
{
    public const SETTING_KEY = 'automated_invoice_domains';

    /** @var string[] */
    private const DEFAULT_DOMAINS = ['condoworks.co', 'condoworks.com', 'leapap.com', 'leapap.ca'];

    /**
     * @param string[] $extraDomains from the settings key, any case, optional leading @
     */
    public static function isAutomated(string $email, array $extraDomains = []): bool
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }
        $domain = strtolower(trim(substr($email, $at + 1), " \t>."));
        if ($domain === '') {
            return false;
        }
        foreach (array_merge(self::DEFAULT_DOMAINS, self::parseDomains(implode(',', $extraDomains))) as $d) {
            if ($domain === $d || substr($domain, -strlen($d) - 1) === '.' . $d) {
                return true;
            }
        }
        return false;
    }

    /** @return string[] */
    public static function parseDomains(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY) as $d) {
            $d = ltrim($d, '@.');
            if ($d !== '' && strpos($d, '.') !== false) {
                $out[] = $d;
            }
        }
        return $out;
    }

    /** Extra domains from ops_settings. Never throws; the built-in list still applies. @return string[] */
    public static function extraDomains(?PDO $db): array
    {
        if (!$db) {
            return [];
        }
        try {
            $s = $db->prepare('SELECT setting_value FROM ops_settings WHERE setting_key = ?');
            $s->execute([self::SETTING_KEY]);
            return self::parseDomains((string)$s->fetchColumn());
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Drop automated inboxes from a recipient list (rows with 'email_address').
     *
     * @param array<int,array> $recipients
     * @return array<int,array>
     */
    public static function withoutAutomated(array $recipients, array $extraDomains = []): array
    {
        return array_values(array_filter($recipients, static function ($r) use ($extraDomains) {
            return !self::isAutomated((string)($r['email_address'] ?? ''), $extraDomains);
        }));
    }
}
