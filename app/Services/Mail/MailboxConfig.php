<?php
/**
 * MailboxConfig — the one place that knows which mailboxes the CRM reads, where they live
 * and which secrets.php constants unlock them. Every inbox reader asks this instead of
 * hard-coding a host / user / password constant.
 *
 *   key       host               user                     password constant        purposes
 *   office    mail.mowology.ca   SMTP_USER (office@)      SMTP_PASS                sales, receipts, etransfer, yardi
 *   receipts  mail.mowology.ca   receipts@mowology.ca     RECEIPTS_IMAP_PASS       receipts
 *   info      mail.mowology.ca   info@mowology.ca         ETRANSFER_IMAP_PASS      etransfer
 *   icloud    imap.mail.me.com   ICLOUD_IMAP_USER         ICLOUD_IMAP_PASS         router
 *
 * Tim's iCloud box (mowology@icloud.com, 2026-10-07) is read ONCE per run by
 * IcloudInboxRouter, which classifies each message and hands it to the same storage the
 * per-purpose readers use (sales history, payment notices, receipt intake, leads, vendor
 * history) — so it carries the single purpose 'router' rather than being read four times.
 * It needs an Apple app-specific password; until both constants exist it reports
 * "not configured" and nothing reads it.
 *
 * Every mailbox is opened read-only (ImapReader: OP_READONLY + FT_PEEK). Pure: pass a
 * constants array to test; with none it reads the real defined constants.
 *
 * No namespace / no autoloader in production: require_once and call statically.
 */
class MailboxConfig
{
    public const ICLOUD_HOST = 'imap.mail.me.com';
    public const OFFICE_HOST = 'mail.mowology.ca';
    public const PORT = 993;

    /** Constant names each mailbox needs — read from secrets.php, never stored anywhere else. */
    private const NAMES = ['SMTP_USER', 'SMTP_PASS', 'RECEIPTS_IMAP_PASS', 'ETRANSFER_IMAP_PASS', 'ICLOUD_IMAP_USER', 'ICLOUD_IMAP_PASS'];

    /** The real constants (only the names above). */
    public static function constants(): array
    {
        $out = [];
        foreach (self::NAMES as $n) {
            if (defined($n)) $out[$n] = (string)constant($n);
        }
        return $out;
    }

    /**
     * Every mailbox, configured or not.
     * @return array<int, array{key: string, label: string, host: string, port: int, user: string, pass: string,
     *               personal: bool, purposes: string[], configured: bool, missing: string}>
     */
    public static function all(?array $c = null): array
    {
        $c = $c ?? self::constants();
        $get = fn(string $n): string => trim((string)($c[$n] ?? ''));
        $office = $get('SMTP_USER') !== '' ? $get('SMTP_USER') : 'office@mowology.ca';
        $defs = [
            ['key' => 'office', 'label' => 'office@', 'host' => self::OFFICE_HOST, 'user' => $office,
             'pass' => $get('SMTP_PASS'), 'need' => ['SMTP_USER', 'SMTP_PASS'], 'personal' => false,
             'purposes' => ['sales', 'receipts', 'etransfer', 'yardi']],
            ['key' => 'receipts', 'label' => 'receipts@', 'host' => self::OFFICE_HOST, 'user' => 'receipts@mowology.ca',
             'pass' => $get('RECEIPTS_IMAP_PASS'), 'need' => ['RECEIPTS_IMAP_PASS'], 'personal' => false,
             'purposes' => ['receipts']],
            ['key' => 'info', 'label' => 'info@', 'host' => self::OFFICE_HOST, 'user' => 'info@mowology.ca',
             'pass' => $get('ETRANSFER_IMAP_PASS'), 'need' => ['ETRANSFER_IMAP_PASS'], 'personal' => false,
             'purposes' => ['etransfer']],
            ['key' => 'icloud', 'label' => 'icloud', 'host' => self::ICLOUD_HOST, 'user' => strtolower($get('ICLOUD_IMAP_USER')),
             'pass' => $get('ICLOUD_IMAP_PASS'), 'need' => ['ICLOUD_IMAP_USER', 'ICLOUD_IMAP_PASS'], 'personal' => true,
             'purposes' => ['router']],
        ];
        $out = [];
        foreach ($defs as $d) {
            $missing = array_values(array_filter($d['need'], fn($n) => $get($n) === ''));
            $out[] = [
                'key' => $d['key'], 'label' => $d['label'], 'host' => $d['host'], 'port' => self::PORT,
                'user' => $d['user'], 'pass' => $d['pass'], 'personal' => $d['personal'], 'purposes' => $d['purposes'],
                'configured' => !$missing,
                'missing' => $missing ? implode(' / ', $missing) . ' not set in secrets.php' : '',
            ];
        }
        return $out;
    }

    /** Configured mailboxes that serve one purpose, in table order. */
    public static function forPurpose(string $purpose, ?array $c = null): array
    {
        return array_values(array_filter(self::all($c), fn($m) => $m['configured'] && in_array($purpose, $m['purposes'], true)));
    }

    /** One mailbox by key (configured or not), or null. */
    public static function get(string $key, ?array $c = null): ?array
    {
        foreach (self::all($c) as $m) if ($m['key'] === $key) return $m;
        return null;
    }

    /** The iCloud mailbox when both constants exist, else null (everything iCloud stays inert). */
    public static function icloud(?array $c = null): ?array
    {
        $m = self::get('icloud', $c);
        return $m && $m['configured'] ? $m : null;
    }

    /** For admin screens: every mailbox without its password. */
    public static function status(?array $c = null): array
    {
        return array_map(fn($m) => array_diff_key($m, ['pass' => 1]), self::all($c));
    }
}
