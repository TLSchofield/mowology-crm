<?php
/**
 * CompanyProfileService — the company's own answers to a supplier's form (owner, 2026-10-10).
 *
 * Profile values come from the company's own records (business_settings, ops_settings, the
 * current business licence) — never hard-coded (tenant identity sourcing). matchField() is the
 * shared knowledge: which form field asks for what ("gst_number", "GST#", "Business No." → gst).
 * Fields that ask for bank details, credit references, guarantees, signatures or personal ID
 * are never answered — they come back as 'yours' for the owner to fill.
 *
 * Global-namespace service: require_once the file. Pure rules are static + tested.
 */
declare(strict_types=1);

class CompanyProfileService
{
    /** Never filled by Otto (pattern on the normalised field name). */
    private const OWNER_ONLY = '/\b(bank|banking|transit|institution|cheque|chequing|routing|swift|iban|credit|reference|references|guarant\w*|surety|sin|social|insurance number|birth|dob|driver|passport|signature|signed|sign|initials?|date|title of signer|position)\b|account (no|number|num)\b/';

    /** Field → profile key, first match wins (order matters: "contact email" is an email). */
    private const RULES = [
        ['/\b(newsletter|opt ?in|optin|mailing|subscribe|specials|promotions?|marketing)\b/', 'newsletter'],
        ['/\b(gst|hst|pst|bn|business (no|number|num)|tax (id|no|number)|registration (no|number))\b|gst ?(no|number|#)/', 'gst'],
        ['/\blicen[cs]e\b/', 'licence_no'],
        ['/\be ?mail\b/', 'email'],
        ['/\b(web ?site|website|web|url|site)\b/', 'website'],
        ['/\bfax\b/', 'fax'],
        ['/\b(cell|mobile)\b/', 'cell'],
        ['/\b(phone|tel|telephone)\b/', 'phone'],
        ['/\b(postal|post ?code|zip)\b/', 'postal'],
        ['/\b(province|prov|state)\b/', 'province'],
        ['/\b(city|town|municipality)\b/', 'city'],
        ['/\b(address|street|addr)\b/', 'street'],
        ['/\b(contact|attention|attn|applicant|your name|contact person|name of contact|owner name)\b/', 'contact'],
        ['/\b(company|business|legal|firm|trade name|account name|customer name|name of business)\b/', 'company'],
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PURE (unit-tested)
    // ══════════════════════════════════════════════════════════════════════

    public static function normalise(string $field): string
    {
        $s = strtolower((string)preg_replace('/([a-z])([A-Z])/', '$1 $2', $field));   // camelCase → words
        $s = str_replace('#', ' # ', $s);
        $s = (string)preg_replace('/[^a-z0-9#]+/', ' ', $s);
        return trim((string)preg_replace('/\s+/', ' ', $s));
    }

    /** 'yours' (owner only), a profile key, or null (unknown). */
    public static function matchField(string $field): ?string
    {
        $n = self::normalise($field);
        if ($n === '') return null;
        if (preg_match(self::OWNER_ONLY, $n)) return 'yours';
        foreach (self::RULES as [$re, $key]) {
            if (preg_match($re, $n)) return $key;
        }
        return null;
    }

    /**
     * "2845 West 15th Ave Vancouver V6K 3A1" / "2845 W 15th Ave, Vancouver, BC V6K 3A1"
     * → [street, city, province, postal].
     */
    public static function parseAddress(string $address, string $defaultProvince = ''): array
    {
        $a = trim((string)preg_replace('/\s+/', ' ', $address));
        $postal = '';
        if (preg_match('/\b([A-Z]\d[A-Z])\s?(\d[A-Z]\d)\b/i', $a, $m)) {
            $postal = strtoupper($m[1] . ' ' . $m[2]);
            $a = trim(str_replace($m[0], '', $a), " ,");
        }
        $prov = $defaultProvince;
        if (preg_match('/(?:,|\s)\s*(BC|AB|SK|MB|ON|QC|NB|NS|PE|NL|YT|NT|NU)\s*$/i', $a, $m)) {
            $prov = strtoupper($m[1]);
            $a = trim(substr($a, 0, -strlen($m[0])), " ,");
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $a)), 'strlen'));
        if (count($parts) >= 2) {
            return [$parts[0], $parts[count($parts) - 1], $prov, $postal];
        }
        // No commas: the street ends at its last street-type word (and a trailing direction).
        if (preg_match('/^(.*\b(?:ave|avenue|st|street|rd|road|dr|drive|blvd|boulevard|way|cres|crescent|pl|place|hwy|highway|lane|ln|court|ct|terrace|trail|parkway|pkwy)\.?(?:\s+(?:n|s|e|w|north|south|east|west))?)\s+(.+)$/i', $a, $m)) {
            return [trim($m[1]), trim($m[2]), $prov, $postal];
        }
        return [$a, '', $prov, $postal];
    }

    /** "(778) 846-9273" from "778 846 9273" / "7788469273"; anything else unchanged. */
    public static function phone(string $p): string
    {
        $d = preg_replace('/\D/', '', $p);
        if (strlen((string)$d) === 11 && $d[0] === '1') $d = substr((string)$d, 1);
        return strlen((string)$d) === 10 ? '(' . substr($d, 0, 3) . ') ' . substr($d, 3, 3) . '-' . substr($d, 6) : trim($p);
    }

    /**
     * Answers for a form's fields.
     * @param array<string, array{type:string}> $fields  PdfFormFiller::fields()
     * @return array{answers: array<string, string|bool>, blanks: string[], yours: string[]}
     */
    public static function answer(array $fields, array $profile): array
    {
        $answers = [];
        $blanks = [];
        $yours = [];
        foreach ($fields as $name => $f) {
            $type = $f['type'] ?? 'text';
            if (!in_array($type, ['text', 'checkbox'], true)) {
                if ($type === 'signature') $yours[] = $name; else $blanks[] = $name;
                continue;
            }
            $key = self::matchField((string)$name);
            if ($key === 'yours') { $yours[] = $name; continue; }
            if ($type === 'checkbox') {
                if ($key === 'newsletter') $answers[$name] = true; else $blanks[] = $name;
                continue;
            }
            if ($key === null || $key === 'newsletter') { $blanks[] = $name; continue; }
            $n = self::normalise((string)$name);
            if (in_array($key, ['city', 'province'], true) && preg_match('/\bcity\b/', $n) && preg_match('/\b(prov|province|state)\b/', $n)) $key = 'city_prov';
            $v = trim((string)($profile[$key] ?? ''));
            if ($v === '') { $blanks[] = $name; continue; }
            $answers[$name] = $v;
        }
        return ['answers' => $answers, 'blanks' => $blanks, 'yours' => $yours];
    }

    // ══════════════════════════════════════════════════════════════════════
    // DB
    // ══════════════════════════════════════════════════════════════════════

    private function setting(string $key): string
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([$key]);
            return trim((string)($s->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            return '';
        }
    }

    /** The company's answers, from its own records. */
    public function profile(): array
    {
        $b = [];
        try {
            $b = $this->db->query("SELECT * FROM business_settings ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { /* empty profile */ }
        $trade = trim((string)($b['company_name'] ?? ''));
        $legal = $this->setting('company_legal_name') ?: $trade;
        [$street, $city, $prov, $postal] = self::parseAddress((string)($b['company_address'] ?? ''), $this->setting('company_province'));
        $phone = self::phone((string)($b['company_phone'] ?? ''));
        $short = trim((string)preg_replace('/\b(landscaping|landscapes?|lawns?|ltd\.?|inc\.?|&)\b/i', '', $trade)) ?: $trade;
        $company = $legal;
        if ($trade !== '' && $legal !== $trade && stripos($legal, $short) !== false) $company = $legal . ' (' . $short . ')';
        elseif ($trade !== '' && $legal !== $trade) $company = $legal . ' (' . $trade . ')';
        $licence = trim((string)($b['business_license'] ?? ''));
        return [
            'company' => $company, 'legal_name' => $legal, 'trade_name' => $trade,
            'street' => $street, 'city' => $city, 'province' => $prov, 'postal' => $postal,
            'city_prov' => trim($city . ($prov !== '' ? ', ' . $prov : '')),
            'phone' => $phone, 'cell' => $phone, 'fax' => '',
            'email' => trim((string)($b['company_email'] ?? '')),
            'website' => trim((string)($b['company_website'] ?? '')),
            'gst' => trim((string)($b['gst_registration'] ?? '')),
            'contact' => $this->setting('company_contact_name'),
            'licence_no' => $licence,
        ];
    }
}
