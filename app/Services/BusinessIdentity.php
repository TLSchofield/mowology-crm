<?php
/**
 * BusinessIdentity — who this business is, in ONE place (Ittai founding businesses, 2026-10-22).
 *
 * The code was written for Mowology and named it in ~135 files: the email sender, AI prompts ("bookkeeper for
 * Mowology… Vancouver… owner Tim"), SMS templates, the phone number in texts. Every one of those now asks this
 * service, which reads the business's OWN settings:
 *   business_settings (company_name, phone, email, website, address — the Business Settings page),
 *   business_identity (entered/operating/legal name, jurisdiction — onboarding's company lookup),
 *   the owner's user row (preferred_name, phone — onboarding's "About you"),
 *   ops_settings business_trade / business_city / owner_user_id (optional), and the install's MAIL_FROM_ADDRESS.
 * Blank stays blank: a new business never inherits Mowology's details. For Mowology the answers are what the
 * code used to hard-code (its settings already hold them).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class BusinessIdentity
{
    private static ?array $cache = null;

    /**
     * @return array{name:string, display_name:string, legal_name:string, phone:string, email:string, website:string,
     *   address:string, city:string, province:string, trade:string, owner_name:string, owner_user_id:int,
     *   mail_from:string, mail_from_name:string, reply_to:string, etransfer_email:string, staff:int}
     */
    public static function get(?PDO $db = null): array
    {
        if (self::$cache !== null) return self::$cache;
        if ($db === null && function_exists('getDB')) {
            try { $db = getDB(); } catch (Throwable $e) { $db = null; }
        }
        $s = $i = $ops = $owner = [];
        $staff = 0;
        if ($db) {
            $s = self::row($db, 'SELECT company_name, company_phone, company_email, company_website, company_address FROM business_settings ORDER BY id LIMIT 1');
            $i = self::row($db, 'SELECT entered_name, operating_name, legal_name, jurisdiction FROM business_identity ORDER BY id LIMIT 1');
            try {
                $ops = $db->query("SELECT setting_key, setting_value FROM ops_settings WHERE setting_key IN ('business_trade', 'business_city', 'owner_user_id', 'charlie_owner_user_id', 'etransfer_email')")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            } catch (Throwable $e) { $ops = []; }
            $ownerId = (int)($ops['owner_user_id'] ?? $ops['charlie_owner_user_id'] ?? 0);
            $owner = $ownerId > 0
                ? self::row($db, 'SELECT id, first_name, preferred_name, phone FROM users WHERE id = ?', [$ownerId])
                : self::row($db, "SELECT id, first_name, preferred_name, phone FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1");
            try { $staff = (int)$db->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn(); } catch (Throwable $e) { $staff = 0; }
        }
        return self::$cache = self::from($s, $i, $ops, $owner, $staff, defined('MAIL_FROM_ADDRESS') ? (string)MAIL_FROM_ADDRESS : '');
    }

    /** Pure: build the identity from the rows (tests, and the cache above). */
    public static function from(array $s, array $i, array $ops, array $owner, int $staff = 0, string $mailFrom = ''): array
    {
        $t = fn($v) => trim((string)($v ?? ''));
        $display = $t($s['company_name'] ?? '');
        $short = $t($i['operating_name'] ?? '') ?: $t($i['entered_name'] ?? '') ?: $display;
        $website = $t($s['company_website'] ?? '');
        $email = $t($s['company_email'] ?? '');
        $ownerName = $t($owner['preferred_name'] ?? '') ?: ucfirst(strtolower($t($owner['first_name'] ?? '')));
        if ($mailFrom === '') {
            $host = strtolower((string)preg_replace('#^(https?://)?(www\.)?#i', '', $website));
            $host = (string)preg_replace('#[/:].*$#', '', $host);
            $mailFrom = preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host) ? 'no-reply@' . $host : 'no-reply@ittai.ca';
        }
        return [
            'name'            => $short,                         // "Mowology" — how people say it
            'display_name'    => $display ?: $short,             // "Mowology Landscaping" — invoices, headers
            'legal_name'      => $t($i['legal_name'] ?? '') ?: ($display ?: $short),
            'phone'           => self::phone($t($s['company_phone'] ?? '')),
            'email'           => $email,
            'website'         => $website,
            'address'         => $t($s['company_address'] ?? ''),
            'city'            => $t($ops['business_city'] ?? ''),
            'province'        => strtoupper($t($i['jurisdiction'] ?? '')),
            'trade'           => $t($ops['business_trade'] ?? ''),
            'owner_name'      => $ownerName,
            'owner_user_id'   => (int)($owner['id'] ?? 0),
            'mail_from'       => $mailFrom,
            'mail_from_name'  => $short ?: 'Ittai',
            'reply_to'        => $email,
            'etransfer_email' => $t($ops['etransfer_email'] ?? '') ?: $email,
            'staff'           => $staff,
        ];
    }

    /**
     * One sentence for an AI prompt: "Mowology, a 3-person landscaping business in Vancouver, BC (owner: Timmo)".
     * Only what's known is said; nothing is invented.
     */
    public static function describe(?array $b = null): string
    {
        $b = $b ?? self::get();
        $name = $b['name'] !== '' ? $b['name'] : 'this business';
        $what = trim(($b['staff'] > 0 && $b['staff'] <= 50 ? $b['staff'] . '-person ' : '') . ($b['trade'] !== '' ? $b['trade'] . ' ' : '') . 'business');
        $where = trim($b['city'] . ($b['city'] !== '' && $b['province'] !== '' ? ', ' : '') . $b['province']);
        $s = $name . ', a ' . $what . ($where !== '' ? ' in ' . $where : '');
        return $s . ($b['owner_name'] !== '' ? ' (owner: ' . $b['owner_name'] . ')' : '');
    }

    /** "778 846 9273" → "(778) 846-9273"; anything that isn't a 10-digit number is kept as entered. */
    public static function phone(string $raw): string
    {
        $d = preg_replace('/\D+/', '', $raw);
        if (strlen((string)$d) === 11 && $d[0] === '1') $d = substr($d, 1);
        return strlen((string)$d) === 10 ? '(' . substr($d, 0, 3) . ') ' . substr($d, 3, 3) . '-' . substr($d, 6) : $raw;
    }

    public static function reset(): void
    {
        self::$cache = null;
    }

    private static function row(PDO $db, string $sql, array $p = []): array
    {
        try {
            $st = $db->prepare($sql);
            $st->execute($p);
            return $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
