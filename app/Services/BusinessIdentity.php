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
    /** Per database connection (spl_object_id), so a worker serving several businesses never mixes them (Codex 2026-10-23). */
    private static array $cache = [];
    /** Set by use(): wins over the cache (tests; a worker that has just switched business). */
    private static ?array $use = null;

    /** Use exactly this identity until reset() (tests, and a worker that switches business). */
    public static function use(?array $identity): void
    {
        self::$use = $identity;
    }

    /**
     * @return array{name:string, display_name:string, legal_name:string, phone:string, email:string, website:string,
     *   address:string, city:string, province:string, trade:string, owner_name:string, owner_user_id:int,
     *   mail_from:string, mail_from_name:string, reply_to:string, etransfer_email:string, staff:int}
     */
    public static function get(?PDO $db = null): array
    {
        if (self::$use !== null) return self::$use;
        if ($db === null && function_exists('getDB')) {
            try { $db = getDB(); } catch (Throwable $e) { $db = null; }
        }
        $key = $db ? spl_object_id($db) : 0;
        if (isset(self::$cache[$key])) return self::$cache[$key];
        $s = $i = $ops = $owner = [];
        $staff = 0;
        if ($db) {
            $s = self::row($db, 'SELECT company_name, company_phone, company_email, company_website, company_address FROM business_settings ORDER BY id LIMIT 1');
            $i = self::row($db, 'SELECT entered_name, operating_name, legal_name, jurisdiction FROM business_identity ORDER BY id LIMIT 1');
            try {
                $ops = $db->query("SELECT setting_key, setting_value FROM ops_settings WHERE setting_key IN ('business_trade', 'business_city', 'owner_user_id', 'charlie_owner_user_id', 'etransfer_email', 'mail_verified_domains')")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            } catch (Throwable $e) { $ops = []; }
            $ownerId = (int)($ops['owner_user_id'] ?? $ops['charlie_owner_user_id'] ?? 0);
            $owner = $ownerId > 0
                ? self::row($db, 'SELECT id, first_name, preferred_name, phone FROM users WHERE id = ?', [$ownerId])
                : self::row($db, "SELECT id, first_name, preferred_name, phone FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1");
            try { $staff = (int)$db->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn(); } catch (Throwable $e) { $staff = 0; }
        }
        $appUrl = defined('APP_URL') ? (string)APP_URL : (PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST']) ? 'https://' . (string)$_SERVER['HTTP_HOST'] : '');
        return self::$cache[$key] = self::from($s, $i, $ops, $owner, $staff, defined('MAIL_FROM_ADDRESS') ? (string)MAIL_FROM_ADDRESS : '', $appUrl);
    }

    /** Pure: build the identity from the rows (tests, and the cache above). */
    public static function from(array $s, array $i, array $ops, array $owner, int $staff = 0, string $mailFrom = '', string $appUrl = ''): array
    {
        // Every field is one clean line: no control characters (header injection — Codex 2026-10-23), capped length.
        $t = fn($v, int $max = 120) => mb_substr(trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string)($v ?? '')))), 0, $max);
        $mail = fn($v) => filter_var($t($v, 190), FILTER_VALIDATE_EMAIL) ?: '';
        $display = $t($s['company_name'] ?? '');
        $short = $t($i['operating_name'] ?? '') ?: $t($i['entered_name'] ?? '') ?: $display;
        $website = $t($s['company_website'] ?? '');
        $email = $mail($s['company_email'] ?? '');
        $ownerName = $t($owner['preferred_name'] ?? '') ?: ucfirst(strtolower($t($owner['first_name'] ?? '')));
        // The sender must be a domain this server is allowed to send for (SPF/DKIM), never just the business's
        // website (Codex 2026-10-23): MAIL_FROM_ADDRESS, else the website's domain only if it is listed in
        // ops_settings mail_verified_domains, else Ittai's own address. The business's email is the Reply-To.
        $mailFrom = $mail($mailFrom);
        if ($mailFrom === '') {
            $host = strtolower((string)preg_replace('#^(https?://)?(www\.)?#i', '', $website));
            $host = (string)preg_replace('#[/:].*$#', '', $host);
            $verified = array_filter(array_map(fn($d) => strtolower(trim($d)), explode(',', (string)($ops['mail_verified_domains'] ?? ''))));
            $mailFrom = ($host !== '' && in_array($host, $verified, true)) ? 'no-reply@' . $host : 'no-reply@ittai.ca';
        }
        $site = $website === '' ? '' : (preg_match('#^https?://#i', $website) ? rtrim($website, '/') : 'https://' . rtrim($website, '/'));
        $appUrl = preg_match('#^https://[a-z0-9.-]+(:\d+)?$#i', rtrim($appUrl, '/')) ? rtrim($appUrl, '/') : $site;
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
            'etransfer_email' => $mail($ops['etransfer_email'] ?? '') ?: $email,
            // Links to the CRM and client portal: the install's own address, never a blank host (Codex 2026-10-23).
            'app_url'         => $appUrl,
            // For texts: no dots, @ or slashes (carriers drop anything that looks like a link), at most 30 characters.
            'sms_name'        => mb_substr(trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace('#[.@/:\\\\]#u', ' ', $short))), 0, 30),
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
        // Only letters, numbers, spaces and simple punctuation reach a prompt: the business types these fields, and
        // they are data, never instructions (Codex 2026-10-23).
        $safe = fn($v) => trim((string)preg_replace('/[^\p{L}\p{N} &\',.\-()]/u', '', (string)$v));
        [$name, $what, $where] = [$safe($name), $safe($what), $safe($where)];
        $b['owner_name'] = $safe($b['owner_name']);
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
        self::$cache = [];
        self::$use = null;
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
