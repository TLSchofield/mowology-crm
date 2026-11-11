<?php
/**
 * ClueService — the clues check: facts hidden in incoming money and mail, turned into
 * one-click suggestions for Tim.
 *
 * Real cases nobody spotted (2026-10-06/07):
 *   - an e-Transfer from "STRATA PLAN BCS-2106" paid Alexandra Bee's invoice; her building was
 *     set up as a single-family home but is strata BCS 2106;
 *   - Marianna Pandy signs "Strata Manager, Quay Pacific Property Management Ltd." — her contact
 *     had no role and no employer; Monica Nicule signs "Managing Agent for Property and Strata,
 *     MacDonald Commercial Real Estate Services";
 *   - Jodi Peacock is the accountant at Vancouver Management, not the quote signer.
 *
 * Detectors (pure, unit tested):
 *   strataPlans()     BC strata plan numbers (BCS, LMS, VR, VAS, EPS, NW, NES, KAS, BCP, LMP, NWS…)
 *   detectRole()      a job title in a signature, and the company on the same or next line
 *   contactDetails()  phone numbers and email addresses in a signature
 *   matchCompany()    fuzzy company-name match against companies.company_name
 * Proposals (pure): strataProposal(), roleProposal(), detailsProposal(), quoteSignerProposal().
 *
 * Payer ≠ billing contact ("alaninglis pays for Maureen Kirkbride") is NOT a detector here:
 * Penny's EtransferInboxService::learnSender() / paysFor() (etransfer_sender_payers, migration
 * 1132) already learns it every time Tim records a transfer.
 *
 * Runner: scan() (daily cron, Comms/Cron/clues_daily.php) reads e-Transfers, bank lines with an
 * invoice allocation and inbound customer mail, and stores suggestions in clue_suggestions —
 * INSERT IGNORE on (kind, subject, proposed hash), so nothing is suggested twice and a dismissed
 * clue never returns. Owners: strata clues are Penny's from money, Yui's from mail; role,
 * employer, details and quote-signer clues are Yui's.
 *
 * NOTHING changes a record without Tim's click: decide() (POST /crm/api/clues.php, admin + CSRF)
 * performs exactly the stored ops, guarded by their before-values, and counts the decision per
 * pattern (clue_patterns) so a pattern Tim keeps rejecting goes quiet.
 *
 * No namespace / no autoloader in production: require_once and `new`. PHP 7.4 / MySQL 5.7 safe.
 */
require_once dirname(__DIR__, 2) . '/Sales/Services/SalesInboxService.php';

class ClueService
{
    public const HEAD_PENNY = 'penny';
    public const HEAD_YUI = 'yui';
    public const FIRST_RUN_DAYS = 90;
    /** Later runs look back this far before the last run (allocations and matches arrive late). */
    public const OVERLAP_DAYS = 7;
    public const CARD_URL = '/crm/dashboard_appstack.php#mw-clues';
    public const NOT_TEST = 'ZZTEST';
    public const OFFICE_PHONE_DIGITS = '7788469273';
    public const OUR_DOMAINS = ['mowology.ca'];

    /** BC strata plan prefixes, longest first so NWS wins over NW. */
    public const PLAN_PREFIXES = ['BCS', 'BCP', 'LMS', 'LMP', 'VAS', 'VIS', 'EPS', 'NES', 'NWS', 'KAS', 'KAP', 'VR', 'NW'];

    /** contacts.contact_role ENUM (ContactService::updateContactRoleAndEmployer). */
    public const ROLES = ['property_manager', 'strata_rep', 'owner', 'billing_contact', 'site_supervisor', 'other'];
    public const ROLE_LABELS = [
        'property_manager' => 'property manager', 'strata_rep' => 'strata rep', 'owner' => 'owner',
        'billing_contact' => 'billing contact', 'site_supervisor' => 'site supervisor', 'other' => 'other',
    ];

    /**
     * Titles: [label, regex, role, billing]. In order — the first title on the earliest line wins
     * (Treasurer before Strata Council: "Treasurer, Strata Council" is the treasurer).
     */
    public const TITLES = [
        ['Strata Manager',   '/\bstrata\s+manager\b/i',                                                          'property_manager', false],
        ['Property Manager', '/\b(?:senior\s+|assistant\s+|associate\s+)?property\s+manager\b/i',                  'property_manager', false],
        ['Managing Agent',   '/\bmanaging\s+agent\b/i',                                                          'property_manager', false],
        ['Accounts Payable', '/\baccounts\s+payable\b/i',                                                        'billing_contact',  true],
        ['Accountant',       '/\baccountant\b/i',                                                                'billing_contact',  true],
        ['Bookkeeper',       '/\bbook-?keeper\b/i',                                                              'billing_contact',  true],
        ['Council President','/\b(?:council\s+president|president,?\s+(?:of\s+)?(?:the\s+)?strata\s+council)\b/i', 'strata_rep',       false],
        ['Treasurer',        '/\btreasurer\b/i',                                                                 'strata_rep',       false],
        ['Strata Council',   '/\bstrata\s+council\b/i',                                                          'strata_rep',       false],
        ['Site Supervisor',  '/\bsite\s+supervisor\b/i',                                                         'site_supervisor',  false],
        ['Owner',            '/^(?:co-?|business\s+|property\s+)?owner\b(?!s)/i',                                'owner',            false],
    ];

    /** Columns Apply may write — nothing else, whatever the stored JSON says. */
    public const WRITABLE = [
        'properties' => ['property_type', 'billing_entity_name', 'quote_contact_id'],
        'contacts'   => ['contact_role', 'employer_company_id', 'phone', 'mobile', 'email'],
        'companies'  => ['billing_contact_id', 'quote_contact_id'],
    ];

    private PDO $db;
    private ?string $now;
    /** @var array<string, bool> */
    private array $cols = [];

    public function __construct(PDO $db, ?string $now = null)
    {
        $this->db = $db;
        $this->now = $now;
    }

    private function now(): string { return $this->now ?? date('Y-m-d H:i:s'); }

    // ═════════════════════════════════════════════════════════════════════════
    // Pure detectors (unit tested)
    // ═════════════════════════════════════════════════════════════════════════

    private static function prefixAlt(): string
    {
        return implode('|', self::PLAN_PREFIXES);
    }

    /**
     * Every strata plan named in a text: "STRATA PLAN BCS-2106", "VR738 Laburnum Heights",
     * "VR15-40", "LMS 1234", "Owners Strata Plan EPS567", "SP NW 2345".
     * With a "strata plan / strata corp / owners / SP" lead-in any case is accepted; a bare
     * plan must be written in capitals (BCS 2106) and stand alone (no letters or digits glued
     * on either side), so postal codes (V6K 1Z3), street directions (NW 2nd) and invoice
     * numbers (INV-2026-0042) never match.
     * @return array<int, array{plan: string, prefix: string, number: string, match: string}>
     */
    public static function strataPlans(string $text, bool $anyCase = false): array
    {
        $p = self::prefixAlt();
        $num = '(\d{1,6}(?:-\d{1,4})?)(?![A-Za-z0-9])';
        $res = [
            '/(?<![A-Za-z0-9])(?:(?:the\s+)?owners?,?\s+(?:of\s+)?)?(?:strata\s+(?:plan|corp(?:oration)?)|SP)\s*(?:no\.?|number|#)?\s*(' . $p . ')\s?[-#]?\s?' . $num . '/i',
            '/(?<![A-Za-z0-9])(' . $p . ')\s?[-#]?\s?' . $num . '/' . ($anyCase ? 'i' : ''),
        ];
        $out = [];
        foreach ($res as $re) {
            if (!preg_match_all($re, $text, $mm, PREG_SET_ORDER)) continue;
            foreach ($mm as $m) {
                $plan = strtoupper($m[1]) . ' ' . $m[2];
                if (isset($out[$plan])) continue;
                $out[$plan] = ['plan' => $plan, 'prefix' => strtoupper($m[1]), 'number' => $m[2], 'match' => trim($m[0])];
            }
        }
        return array_values($out);
    }

    /** "BCS 2106" → "BCS2106", for comparing two spellings of the same plan. */
    public static function planKey(string $plan): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $plan) ?? '');
    }

    /**
     * A job title in a signature, with the company on the same line ("Strata Manager, Quay
     * Pacific Property Management Ltd.") or the next one. Lines that read like a sentence
     * ("I am the accountant for…", "Our accountant will pay") are ignored.
     * @return array{title: string, role: string, billing: bool, company: ?string, line: string, pattern: string}|null
     */
    public static function detectRole(string $signature, string $contactName = ''): ?array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $signature) ?: []), fn($l) => $l !== ''));
        $lines = array_slice($lines, 0, 10);
        foreach ($lines as $i => $line) {
            if (mb_strlen($line) > 120) continue;
            foreach (self::TITLES as [$label, $re, $role, $billing]) {
                if (!preg_match($re, $line, $m, PREG_OFFSET_CAPTURE)) continue;
                $before = substr($line, 0, $m[0][1]);
                $after = substr($line, $m[0][1] + strlen($m[0][0]));
                if (!self::titlePrefixOk($before) || !self::titleSuffixOk($after)) continue 2;
                $company = self::companyFrom($after, $contactName);
                if ($company === null && isset($lines[$i + 1]) && !self::lineHasTitle($lines[$i + 1])) {
                    $company = self::cleanCompany($lines[$i + 1], $contactName);
                }
                return ['title' => $label, 'role' => $role, 'billing' => $billing, 'company' => $company,
                        'line' => $line, 'pattern' => 'title:' . strtolower($label)];
            }
        }
        return null;
    }

    private static function lineHasTitle(string $line): bool
    {
        foreach (self::TITLES as $t) if (preg_match($t[1], $line)) return true;
        return false;
    }

    /** Before the title: a name or a few capitalised words ("Senior", "Marianna Pandy |"), never a sentence. */
    private static function titlePrefixOk(string $before): bool
    {
        $b = trim($before, " \t,|-–—:/");
        if ($b === '') return true;
        if (mb_strlen($b) > 40) return false;
        $small = ['and', 'of', 'the', '&'];
        foreach (preg_split('/[\s,|\/]+/u', $b) ?: [] as $w) {
            if ($w === '' || in_array(strtolower($w), $small, true)) continue;
            if (!preg_match('/^[\p{Lu}\d#(]/u', $w)) return false;
        }
        return !preg_match('/^(I|I\'m|We|Our|My|Your|Please|The)\b/', $b);
    }

    /** After the title: nothing, a separator, or "at / for / with / of" — not the rest of a sentence. */
    private static function titleSuffixOk(string $after): bool
    {
        return (bool)preg_match('/^\s*(?:$|[,|\-–—:\/(]|(?:at|for|with|of|in|and|&)\b)/u', $after);
    }

    private static function companyFrom(string $after, string $contactName): ?string
    {
        $a = trim($after);
        $a = ltrim($a, " \t,|-–—:/(");
        $a = preg_replace('/^(?:at|with|of|in)\s+/i', '', $a) ?? $a;
        if (preg_match('/^(?:for|and|&)\b/i', $a)) {
            // "Managing Agent for Property and Strata, MacDonald Commercial…" — the firm follows the comma.
            $comma = strpos($a, ',');
            $a = $comma === false ? '' : substr($a, $comma + 1);
        }
        $a = trim(explode(' | ', $a)[0]);
        return $a === '' ? null : self::cleanCompany($a, $contactName);
    }

    /** A plausible company name, or null (phone, email, web address, street address, a sentence…). */
    public static function cleanCompany(string $s, string $contactName = ''): ?string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s, " \t,|-–—:");
        $len = mb_strlen($s);
        if ($len < 3 || $len > 80 || !preg_match('/\p{L}/u', $s)) return null;
        if (preg_match('/@|https?:|www\.|\.(?:ca|com|net|org)\b/i', $s)) return null;
        if (preg_match('/\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]?\d{4}/', $s)) return null;          // a phone number
        $legal = (bool)preg_match('/\b(?:ltd|limited|inc|corp|corporation|llp|co)\b\.?/i', $s);
        if (preg_match('/^(?:#|suite\b|unit\b|ste\b|p\.?o\.?\s*box)/i', $s)) return null;
        if (preg_match('/^\d/', $s) && !$legal) return null;                        // a street address
        if (!preg_match('/^[\p{Lu}\d]/u', $s)) return null;
        if (preg_match('/[.!?]$/', $s) && !$legal) return null;                     // a sentence
        if (preg_match('/^(?:T|P|C|M|F|O|D|Tel|Phone|Cell|Mobile|Fax|Office|Direct|Email|E)\s*[:.]/i', $s)) return null;
        if ($contactName !== '' && self::normCompany($s) === self::normCompany($contactName)) return null;
        if (self::lineHasTitle($s) && !$legal) return null;
        return $s;
    }

    /**
     * Phones and emails in a signature. Fax lines and our own office number are left out.
     * @return array{phones: array<int, array{number: string, digits: string, mobile: bool}>, emails: string[]}
     */
    public static function contactDetails(string $signature): array
    {
        $phones = [];
        $emails = [];
        foreach (preg_split('/\R/', $signature) ?: [] as $line) {
            if (preg_match_all('/(?<!\d)(?:\+?1[\s.\-]?)?\(?(\d{3})\)?[\s.\-]?(\d{3})[\s.\-]?(\d{4})(?!\d)/', $line, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($mm as $m) {
                    $lead = substr($line, max(0, $m[0][1] - 12), min(12, $m[0][1]));
                    if (preg_match('/\b(?:f|fax)\b\s*[:.]?\s*$/i', $lead)) continue;
                    $digits = $m[1][0] . $m[2][0] . $m[3][0];
                    if ($digits === self::OFFICE_PHONE_DIGITS || isset($phones[$digits])) continue;
                    $phones[$digits] = ['number' => $m[1][0] . '-' . $m[2][0] . '-' . $m[3][0], 'digits' => $digits,
                                        'mobile' => (bool)preg_match('/\b(?:c|m|cell|mobile)\b\s*[:.]?\s*$/i', $lead)];
                }
            }
            if (preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $line, $em)) {
                foreach ($em[0] as $e) {
                    $e = strtolower($e);
                    $d = substr(strrchr($e, '@') ?: '', 1);
                    if (!in_array($d, self::OUR_DOMAINS, true) && !in_array($e, $emails, true)) $emails[] = $e;
                }
            }
        }
        return ['phones' => array_values($phones), 'emails' => $emails];
    }

    public static function digits(?string $phone): string
    {
        $d = preg_replace('/\D/', '', (string)$phone) ?? '';
        return strlen($d) === 11 && $d[0] === '1' ? substr($d, 1) : $d;
    }

    /** Lowercase, "&" → "and", no punctuation, no legal suffixes: "Quay Pacific Property Management Ltd." → "quay pacific property management". */
    public static function normCompany(string $name): string
    {
        $n = strtolower(str_replace('&', ' and ', $name));
        $n = preg_replace('/[^a-z0-9 ]+/', ' ', $n) ?? '';
        $words = array_filter(explode(' ', $n), fn($w) => $w !== '' && !in_array($w, ['ltd', 'limited', 'inc', 'incorporated', 'corp', 'corporation', 'co', 'company', 'llc', 'llp', 'the'], true));
        return implode(' ', $words);
    }

    /**
     * Best company for a signature's firm name, or null. Same name ignoring case, punctuation and
     * Ltd/Inc → 100; one name inside the other (two words or more) → 90; else close spelling ≥ 80.
     * @param array<int, array{id: int, company_name: string}> $companies
     * @return array{id: int, name: string, score: int}|null
     */
    public static function matchCompany(string $name, array $companies): ?array
    {
        $n = self::normCompany($name);
        if ($n === '') return null;
        $best = null;
        foreach ($companies as $c) {
            $cn = self::normCompany((string)$c['company_name']);
            if ($cn === '') continue;
            $score = 0;
            if ($cn === $n) {
                $score = 100;
            } else {
                [$short, $long] = strlen($cn) < strlen($n) ? [$cn, $n] : [$n, $cn];
                if (substr_count($short, ' ') >= 1 && strlen($short) >= 8 && strpos(' ' . $long . ' ', ' ' . $short . ' ') !== false) {
                    $score = 90;
                } else {
                    similar_text($n, $cn, $pct);
                    $a = explode(' ', $n);
                    $b = explode(' ', $cn);
                    $jac = count(array_intersect($a, $b)) / max(1, count(array_unique(array_merge($a, $b))));
                    if ($pct >= 88 || ($pct >= 80 && $jac >= 0.5)) $score = (int)floor($pct);
                }
            }
            if ($score >= 80 && ($best === null || $score > $best['score'])) {
                $best = ['id' => (int)$c['id'], 'name' => (string)$c['company_name'], 'score' => $score];
            }
        }
        return $best;
    }

    /** "2505 West 8th Avenue" → "2505 W 8th". */
    public static function shortAddress(string $address): string
    {
        $a = trim(explode(',', $address)[0]);
        $a = preg_replace(['/\bWest\b/i', '/\bEast\b/i', '/\bNorth\b/i', '/\bSouth\b/i'], ['W', 'E', 'N', 'S'], $a) ?? $a;
        $short = preg_replace('/\s+(?:Avenue|Ave|Street|St|Road|Rd|Drive|Dr|Boulevard|Blvd|Crescent|Cres|Place|Pl|Court|Ct|Way|Lane)\.?$/i', '', $a) ?? $a;
        return preg_match('/\s/', trim($short)) ? trim($short) : $a;
    }

    /** A pattern Tim has turned down at least 3 times and accepted less than half as often goes quiet. */
    public static function patternMuted(int $accepted, int $dismissed): bool
    {
        return $dismissed >= 3 && $accepted * 2 < $dismissed;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Pure proposals (unit tested) — ops are exactly what Apply will do
    // ═════════════════════════════════════════════════════════════════════════

    private static function same($a, $b): bool
    {
        return trim((string)$a) === trim((string)$b);
    }

    /**
     * A plan named by a payment or an email vs the property on file.
     * @param array{id: int, address: string, property_type: ?string, billing_entity_name: ?string} $property
     * @return array{kind: string, ops: array, summary_tail: string, plan: string, was: ?string}|null
     */
    public static function strataProposal(array $property, string $plan): ?array
    {
        $type = (string)($property['property_type'] ?? '');
        $billing = trim((string)($property['billing_entity_name'] ?? ''));
        $billPlans = $billing !== '' ? self::strataPlans(strtoupper($billing), true) : [];
        $set = [];
        $kind = 'strata_plan';
        $was = null;
        if ($billing === '') {
            $set['billing_entity_name'] = $plan;
        } elseif ($billPlans && self::planKey($billPlans[0]['plan']) !== self::planKey($plan)) {
            $kind = 'strata_mismatch';
            $was = $billPlans[0]['plan'];
            $set['billing_entity_name'] = $plan;
        }
        if ($type !== 'strata') $set = ['property_type' => 'strata'] + $set;
        if (!$set) return null;
        $before = [];
        foreach ($set as $k => $v) $before[$k] = $property[$k] ?? null;
        $addr = self::shortAddress((string)$property['address']);
        if ($kind === 'strata_mismatch') {
            $tail = $addr . ' is billed as ' . $was . ' — switch it to ' . $plan . '?';
        } elseif (isset($set['property_type']) && isset($set['billing_entity_name'])) {
            $tail = 'make ' . $addr . ' a strata called ' . $plan . '?';
        } elseif (isset($set['property_type'])) {
            $tail = 'make ' . $addr . ' a strata?';
        } else {
            $tail = 'bill ' . $addr . ' as ' . $plan . '?';
        }
        return ['kind' => $kind, 'plan' => $plan, 'was' => $was, 'summary_tail' => $tail,
                'ops' => [['op' => 'update', 'table' => 'properties', 'id' => (int)$property['id'], 'set' => $set, 'before' => $before]]];
    }

    /**
     * A signature's title (and firm) vs the contact on file.
     * @param array{id: int, first_name: string, last_name: string, contact_role: ?string, employer_company_id: ?int} $contact
     * @param array $role         detectRole()
     * @param array|null $match   matchCompany() for $role['company']
     * @param array|null $matchedCompany  the matched companies row (billing_contact_id) when $match
     * @return array{ops: array, pattern: string, summary: string}|null
     */
    public static function roleProposal(array $contact, array $role, ?array $match, ?array $matchedCompany = null): ?array
    {
        $cid = (int)$contact['id'];
        $name = trim($contact['first_name'] . ' ' . $contact['last_name']);
        $set = [];
        if (!self::same($contact['contact_role'] ?? '', $role['role'])) $set['contact_role'] = $role['role'];
        $ops = [];
        $firm = null;
        $pattern = $role['pattern'];
        $company = $role['company'] ?? null;
        if ($match) {
            $firm = $match['name'];
            if ((int)($contact['employer_company_id'] ?? 0) !== $match['id']) $set['employer_company_id'] = $match['id'];
            if ($role['billing'] && $matchedCompany && (int)($matchedCompany['billing_contact_id'] ?? 0) === 0) {
                $ops[] = ['op' => 'update', 'table' => 'companies', 'id' => $match['id'], 'set' => ['billing_contact_id' => $cid], 'before' => ['billing_contact_id' => null]];
            }
        } elseif ($company !== null) {
            $firm = $company;
            $type = self::strataPlans($company, true) ? 'strata'
                : ($role['role'] === 'property_manager' || preg_match('/property|management|realty|real estate|strata/i', $company) ? 'property_manager' : 'business');
            array_unshift($ops, ['op' => 'create_company', 'name' => $company, 'type' => $type]);
            $set['employer_company_id'] = '@company';
            $pattern .= '|company:new';
        }
        if (!$set && !$ops) return null;
        $before = [];
        foreach ($set as $k => $v) $before[$k] = $contact[$k] ?? null;
        if ($set) {
            // The contact update goes after a create_company, so '@company' can be filled in.
            $ops[] = ['op' => 'update', 'table' => 'contacts', 'id' => $cid, 'set' => $set, 'before' => $before];
        }
        $label = self::ROLE_LABELS[$role['role']] ?? $role['role'];
        $at = $firm !== null ? ' at ' . $firm : '';
        if ($role['billing']) {
            $summary = $name . ' is the ' . strtolower($role['title']) . $at . ' — billing contact, not the quote signer. Set as billing contact'
                . ($match === null && $company !== null ? ' and add ' . $company . ' as a company' : '') . '?';
        } else {
            $what = [];
            if (isset($set['contact_role'])) $what[] = 'set as ' . $label;
            if (isset($set['employer_company_id'])) $what[] = $match ? 'link to ' . $firm : 'add ' . $firm . ' as a company';
            if (!$what) $what[] = 'set as ' . $label . $at;
            $summary = $name . ' signs “' . self::clip($role['line'], 90) . '” — ' . implode(' and ', $what) . '?';
        }
        return ['ops' => $ops, 'pattern' => $pattern, 'summary' => $summary];
    }

    /**
     * Phones / an email in the signature that the contact doesn't have — only into empty fields,
     * and only a number labelled C: / Cell / Mobile into mobile.
     * @param array{id: int, first_name: string, last_name: string, email: ?string, phone: ?string, mobile: ?string} $contact
     * @return array{ops: array, pattern: string, summary: string}|null
     */
    public static function detailsProposal(array $contact, array $details): ?array
    {
        $have = array_filter([self::digits($contact['phone'] ?? ''), self::digits($contact['mobile'] ?? '')]);
        $set = [];
        $found = [];
        foreach ($details['phones'] as $p) {
            if (in_array($p['digits'], $have, true)) continue;
            // Only a number labelled cell / mobile goes in mobile (texts go there — never a landline).
            $field = $p['mobile'] ? 'mobile' : 'phone';
            if (trim((string)($contact[$field] ?? '')) !== '' || isset($set[$field])) continue;
            $set[$field] = $p['number'];
            $found[] = $p['number'];
        }
        $email = strtolower(trim((string)($contact['email'] ?? '')));
        if ($email === '' && $details['emails']) {
            $set['email'] = $details['emails'][0];
            $found[] = $details['emails'][0];
        }
        if (!$set) return null;
        $before = [];
        foreach ($set as $k => $v) $before[$k] = $contact[$k] ?? null;
        $name = trim($contact['first_name'] . ' ' . $contact['last_name']);
        $pattern = 'details:' . implode('+', array_values(array_unique(array_map(fn($k) => $k === 'email' ? 'email' : 'phone', array_keys($set)))));
        return ['pattern' => $pattern,
                'summary' => $name . '’s signature has ' . implode(' and ', $found) . ' — add to the contact?',
                'ops' => [['op' => 'update', 'table' => 'contacts', 'id' => (int)$contact['id'], 'set' => $set, 'before' => $before]]];
    }

    /**
     * An accountant / AP / bookkeeper who is the quote contact somewhere. Building or firm
     * "send quotes to" → clear it (Apply). An open quote addressed to them → a flag (Got it).
     * @param array<int, array{id: int, address: string}> $properties  properties.quote_contact_id = them
     * @param array<int, array{id: int, company_name: string}> $companies  companies.quote_contact_id = them
     * @param array<int, array{id: int, quote_number: string}> $openQuotes  open quotes addressed to them
     * @return array{ops: array, pattern: string, summary: string, quotes: array}|null
     */
    public static function quoteSignerProposal(array $contact, array $role, array $properties, array $companies, array $openQuotes): ?array
    {
        if (empty($role['billing']) || (!$properties && !$companies && !$openQuotes)) return null;
        $cid = (int)$contact['id'];
        $name = trim($contact['first_name'] . ' ' . $contact['last_name']);
        $ops = [];
        $where = [];
        foreach ($properties as $p) {
            $ops[] = ['op' => 'update', 'table' => 'properties', 'id' => (int)$p['id'], 'set' => ['quote_contact_id' => null], 'before' => ['quote_contact_id' => $cid]];
            $where[] = self::shortAddress((string)$p['address']);
        }
        foreach ($companies as $c) {
            $ops[] = ['op' => 'update', 'table' => 'companies', 'id' => (int)$c['id'], 'set' => ['quote_contact_id' => null], 'before' => ['quote_contact_id' => $cid]];
            $where[] = (string)$c['company_name'];
        }
        $nums = array_map(fn($q) => (string)$q['quote_number'], $openQuotes);
        $who = $name . ' (' . strtolower($role['title']) . ')';
        if ($ops) {
            $summary = $who . ' gets the quotes for ' . implode(', ', array_slice($where, 0, 3)) . (count($where) > 3 ? '…' : '')
                . ' — billing contact, not the quote signer. Stop sending quotes there?'
                . ($nums ? ' Also the Send-to on ' . implode(', ', array_slice($nums, 0, 3)) . '.' : '');
        } else {
            $summary = $who . ' is the Send-to on ' . implode(', ', array_slice($nums, 0, 3)) . (count($nums) > 3 ? '…' : '')
                . ' — billing contact, not the quote signer. Pick the property manager or council instead.';
        }
        return ['ops' => $ops, 'pattern' => 'quote_signer:' . strtolower($role['title']), 'summary' => $summary, 'quotes' => $openQuotes];
    }

    /** Stable hash of what Apply would do (before-values left out, so a re-scan is the same clue). */
    public static function opsHash(array $ops): string
    {
        $canon = array_map(function ($op) {
            unset($op['before']);
            if (isset($op['set'])) ksort($op['set']);
            ksort($op);
            return $op;
        }, $ops);
        return sha1(json_encode($canon));
    }

    /** Has the record already got what the clue suggests, or changed under it? (Then don't show it.) */
    public static function isStale(array $ops, array $current): bool
    {
        $updates = array_filter($ops, fn($o) => ($o['op'] ?? '') === 'update');
        if (!$updates) return false;
        $done = true;
        foreach ($updates as $o) {
            $row = $current[$o['table'] . ':' . (int)$o['id']] ?? null;
            if ($row === null) return true;                          // the record is gone
            foreach ($o['before'] ?? [] as $k => $v) {
                if (!self::same($row[$k] ?? null, $v)) return true;   // changed since
            }
            foreach ($o['set'] as $k => $v) {
                if ($v === '@company' || !self::same($row[$k] ?? null, $v)) $done = false;
            }
        }
        return $done;
    }

    /** Head's brief + its open clues (priority 2), the shared brief contract. */
    public static function mergeBrief(array $brief, array $clueItems, int $max = 40): array
    {
        if (!$clueItems) return $brief;
        $items = array_merge((array)($brief['items'] ?? []), $clueItems);
        $brief['items'] = array_slice($items, 0, $max);
        $brief['count'] = (int)($brief['count'] ?? 0) + count($clueItems);
        return $brief;
    }

    /** The brief item for one clue row. */
    public static function briefItem(array $row): array
    {
        return ['key' => 'clue:' . (int)$row['id'], 'kind' => 'clue:' . $row['kind'], 'priority' => 2,
                'text' => (string)$row['summary'], 'url' => self::CARD_URL, 'value' => null,
                'since' => $row['source_at'] ? substr((string)$row['source_at'], 0, 10) : null];
    }

    private static function clip(string $s, int $n): string
    {
        return mb_strlen($s) > $n ? rtrim(mb_substr($s, 0, $n - 1)) . '…' : $s;
    }

    private static function money($v): string
    {
        return '$' . number_format((float)$v, 2);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Database
    // ═════════════════════════════════════════════════════════════════════════

    /** Migration 1206 has run. */
    public function ready(): bool
    {
        return $this->hasTable('clue_suggestions');
    }

    public function hasTable(string $t): bool
    {
        try {
            // Literal, not a placeholder: MySQL won't take SHOW TABLES LIKE ? as a native prepared statement.
            return $this->db->query('SHOW TABLES LIKE ' . $this->db->quote(preg_replace('/[^a-z0-9_]/', '', $t)))->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function hasColumn(string $table, string $col): bool
    {
        $k = $table . '.' . $col;
        if (!isset($this->cols[$k])) {
            try {
                $this->db->query('SELECT `' . preg_replace('/[^a-z0-9_]/', '', $col) . '` FROM `' . preg_replace('/[^a-z0-9_]/', '', $table) . '` LIMIT 0');
                $this->cols[$k] = true;
            } catch (Throwable $e) {
                $this->cols[$k] = false;
            }
        }
        return $this->cols[$k];
    }

    /** @return array<string, array{accepted: int, dismissed: int}> */
    private function patternStats(): array
    {
        $out = [];
        try {
            foreach ($this->db->query('SELECT pattern, accepted, dismissed FROM clue_patterns')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$r['pattern']] = ['accepted' => (int)$r['accepted'], 'dismissed' => (int)$r['dismissed']];
            }
        } catch (Throwable $e) { /* none yet */ }
        return $out;
    }

    private function muted(string $pattern, array $stats): bool
    {
        foreach (explode('|', $pattern) as $p) {
            if (isset($stats[$p]) && self::patternMuted($stats[$p]['accepted'], $stats[$p]['dismissed'])) return true;
        }
        return false;
    }

    // ── Runner ──────────────────────────────────────────────────────────────

    /**
     * Read the last 90 days (first run) or since the last run (minus an overlap), store new
     * suggestions. Read-only on every CRM table; writes only clue_suggestions / clue_scan_state.
     * @return array{since: string, etransfer: int, bank: int, email: int, found: int, new: int}
     */
    public function scan(): array
    {
        $since = null;
        try {
            $since = $this->db->query("SELECT last_run FROM clue_scan_state WHERE source = 'all'")->fetchColumn() ?: null;
        } catch (Throwable $e) { /* first run */ }
        $since = $since
            ? date('Y-m-d H:i:s', strtotime((string)$since) - self::OVERLAP_DAYS * 86400)
            : date('Y-m-d H:i:s', strtotime($this->now()) - self::FIRST_RUN_DAYS * 86400);

        $stats = $this->patternStats();
        $found = [];
        $counts = ['since' => $since, 'etransfer' => 0, 'bank' => 0, 'email' => 0, 'found' => 0, 'new' => 0];

        foreach (['moneyClues', 'mailClues'] as $step) {
            try {
                foreach ($this->{$step}($since, $counts) as $c) $found[] = $c;
            } catch (Throwable $e) {
                error_log('Clues ' . $step . ': ' . $e->getMessage());
            }
        }

        $ins = $this->db->prepare("
            INSERT IGNORE INTO clue_suggestions
              (kind, owner, subject_type, subject_id, contact_id, pattern, summary, evidence, source, source_ref, source_at, proposed_change, proposed_hash)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($found as $c) {
            if ($this->muted($c['pattern'], $stats)) continue;
            $counts['found']++;
            $ins->execute([
                $c['kind'], $c['owner'], $c['subject_type'], (int)$c['subject_id'], $c['contact_id'] ?: null,
                mb_substr($c['pattern'], 0, 60), mb_substr($c['summary'], 0, 500), $c['evidence'], $c['source'],
                (string)$c['source_ref'], $c['source_at'], json_encode(['ops' => $c['ops']] + (isset($c['quotes']) ? ['quotes' => $c['quotes']] : [])),
                self::opsHash($c['ops'] ?: [['op' => 'flag', 'kind' => $c['kind'], 'quotes' => array_column($c['quotes'] ?? [], 'id')]]),
            ]);
            $counts['new'] += $ins->rowCount();
        }
        $this->db->prepare("REPLACE INTO clue_scan_state (source, last_run) VALUES ('all', ?)")->execute([$this->now()]);
        return $counts;
    }

    /** properties by id: id, address, property_type, billing_entity_name. */
    private function properties(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $bill = $this->hasColumn('properties', 'billing_entity_name') ? 'billing_entity_name' : 'NULL AS billing_entity_name';
        $out = [];
        foreach ($this->db->query('SELECT id, address, property_type, ' . $bill . ' FROM properties WHERE id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['id']] = $r;
        }
        return $out;
    }

    /** contact id => their one property (site contact, property_contacts or quote contact), when there is exactly one. */
    private function soleProperty(array $contactIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $contactIds))));
        if (!$ids) return [];
        $in = implode(',', $ids);   // ints only
        $pairs = $this->db->query("SELECT site_contact_id, id FROM properties WHERE site_contact_id IN ({$in}) AND COALESCE(status, 'active') = 'active'")->fetchAll(PDO::FETCH_NUM);
        if ($this->hasTable('property_contacts')) {
            $pairs = array_merge($pairs, $this->db->query("SELECT contact_id, property_id FROM property_contacts WHERE contact_id IN ({$in})")->fetchAll(PDO::FETCH_NUM));
        }
        if ($this->hasColumn('properties', 'quote_contact_id')) {
            $pairs = array_merge($pairs, $this->db->query("SELECT quote_contact_id, id FROM properties WHERE quote_contact_id IN ({$in})")->fetchAll(PDO::FETCH_NUM));
        }
        $by = [];
        foreach ($pairs as $p) $by[(int)$p[0]][(int)$p[1]] = true;
        $out = [];
        foreach ($by as $cid => $props) if (count($props) === 1) $out[$cid] = (int)array_keys($props)[0];
        return $out;
    }

    /** Strata plans in e-Transfer sender names / memos and bank lines that paid an invoice → Penny. */
    private function moneyClues(string $since, array &$counts): array
    {
        $rows = [];
        if ($this->hasTable('etransfer_notifications')) {
            $s = $this->db->prepare("
                SELECT n.id, n.sender_name, n.memo, n.amount, COALESCE(n.email_date, n.created_at) AS seen_at,
                       i.id AS invoice_id, i.invoice_number, i.property_id, i.contact_id
                FROM etransfer_notifications n
                JOIN invoices i ON i.id = n.matched_invoice_id
                WHERE COALESCE(n.status, '') <> 'dismissed' AND COALESCE(n.email_date, n.created_at) >= ?
                ORDER BY seen_at DESC LIMIT 2000
            ");
            $s->execute([$since]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $counts['etransfer']++;
                $rows[] = ['source' => 'etransfer', 'ref' => $r['id'], 'text' => trim($r['sender_name'] . ' ' . $r['memo']),
                           'label' => 'e-Transfer from “' . trim((string)$r['sender_name']) . '”'] + $r;
            }
        }
        if ($this->hasTable('bank_import_rows') && $this->hasTable('invoice_payment_allocations')) {
            $s = $this->db->prepare("
                SELECT bir.id, bir.description, bir.amount, bir.transaction_date AS seen_at,
                       i.id AS invoice_id, i.invoice_number, i.property_id, i.contact_id
                FROM bank_import_rows bir
                JOIN invoice_payment_allocations ipa ON ipa.transaction_id = bir.transaction_id
                JOIN invoices i ON i.id = ipa.invoice_id
                WHERE bir.type = 'income' AND bir.transaction_date >= ?
                ORDER BY bir.transaction_date DESC LIMIT 2000
            ");
            $s->execute([substr($since, 0, 10)]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $counts['bank']++;
                $rows[] = ['source' => 'bank', 'ref' => $r['id'], 'text' => (string)$r['description'],
                           'label' => 'Bank deposit “' . self::clip(trim((string)$r['description']), 60) . '”'] + $r;
            }
        }
        $rows = array_values(array_filter($rows, fn($r) => self::strataPlans($r['text']) !== []));
        if (!$rows) return [];
        $sole = $this->soleProperty(array_map(fn($r) => (int)$r['property_id'] > 0 ? 0 : (int)$r['contact_id'], $rows));
        $props = $this->properties(array_merge(array_column($rows, 'property_id'), array_values($sole)));
        $out = [];
        foreach ($rows as $r) {
            $pid = (int)$r['property_id'] ?: ($sole[(int)$r['contact_id']] ?? 0);
            $p = $props[$pid] ?? null;
            if (!$p || stripos((string)$p['address'], self::NOT_TEST) !== false) continue;
            $plan = self::strataPlans($r['text'])[0];
            $prop = self::strataProposal($p, $plan['plan']);
            if (!$prop) continue;
            $out[] = [
                'kind' => $prop['kind'], 'owner' => self::HEAD_PENNY, 'subject_type' => 'property', 'subject_id' => $pid,
                'contact_id' => (int)$r['contact_id'] ?: null, 'pattern' => 'strata:' . $r['source'],
                'summary' => 'Payment came from Strata Plan ' . $plan['plan'] . ' — ' . $prop['summary_tail'],
                'evidence' => $r['label'] . ' · ' . self::money($r['amount']) . ' · ' . date('M j, Y', strtotime((string)$r['seen_at']))
                    . ' · paid ' . $r['invoice_number'],
                'source' => $r['source'], 'source_ref' => $r['ref'], 'source_at' => date('Y-m-d H:i:s', strtotime((string)$r['seen_at'])),
                'ops' => $prop['ops'],
            ];
        }
        return $out;
    }

    /** Inbound customer mail → strata plans (Yui), and per sender the newest signature → role / details / quote signer. */
    private function mailClues(string $since, array &$counts): array
    {
        if (!$this->hasTable('sales_messages')) return [];
        $sig = $this->hasColumn('sales_messages', 'signature') ? 'm.signature' : 'NULL';
        $s = $this->db->prepare("
            SELECT m.id, m.contact_id, m.subject, m.snippet, {$sig} AS signature, m.sent_at
            FROM sales_messages m
            WHERE m.direction = 'inbound' AND m.channel = 'email' AND m.contact_id IS NOT NULL AND m.sent_at >= ?
            ORDER BY m.sent_at DESC, m.id DESC
            LIMIT 3000
        ");
        $s->execute([$since]);
        $msgs = $s->fetchAll(PDO::FETCH_ASSOC);
        $counts['email'] = count($msgs);
        if (!$msgs) return [];

        $contacts = $this->contacts(array_column($msgs, 'contact_id'));
        $out = [];

        // Strata plans named in the mail (subject, text, signature).
        $strata = [];
        foreach ($msgs as $m) {
            $c = $contacts[(int)$m['contact_id']] ?? null;
            if (!$c || isset($strata[(int)$m['contact_id']])) continue;
            $plans = self::strataPlans(trim($m['subject'] . "\n" . $m['snippet'] . "\n" . $m['signature']));
            if ($plans) $strata[(int)$m['contact_id']] = ['plan' => $plans[0], 'm' => $m];
        }
        if ($strata) {
            $sole = $this->soleProperty(array_keys($strata));
            $props = $this->properties(array_values($sole));
            foreach ($strata as $cid => $x) {
                $p = $props[$sole[$cid] ?? 0] ?? null;
                if (!$p) continue;
                $prop = self::strataProposal($p, $x['plan']['plan']);
                if (!$prop) continue;
                $c = $contacts[$cid];
                $out[] = [
                    'kind' => $prop['kind'], 'owner' => self::HEAD_YUI, 'subject_type' => 'property', 'subject_id' => (int)$p['id'],
                    'contact_id' => $cid, 'pattern' => 'strata:email',
                    'summary' => $c['name'] . '’s email names Strata Plan ' . $x['plan']['plan'] . ' — ' . $prop['summary_tail'],
                    'evidence' => 'Email from ' . $c['name'] . ' · ' . date('M j, Y', strtotime((string)$x['m']['sent_at'])) . ' · “' . self::clip((string)$x['plan']['match'], 60) . '”',
                    'source' => 'email', 'source_ref' => $x['m']['id'], 'source_at' => $x['m']['sent_at'], 'ops' => $prop['ops'],
                ];
            }
        }

        // Signatures: each sender's newest message with one.
        $sigs = [];
        foreach ($msgs as $m) {
            $cid = (int)$m['contact_id'];
            if (isset($sigs[$cid]) || !isset($contacts[$cid])) continue;
            $text = trim((string)$m['signature']);
            if ($text === '') {
                // Older mail has no signature column: read the snippet's tail, unless it was cut off.
                $snip = (string)$m['snippet'];
                if ($snip === '' || mb_substr($snip, -1) === '…') continue;
                $text = SalesInboxService::signature($snip);
            }
            if ($text !== '') $sigs[$cid] = ['text' => $text, 'm' => $m];
        }
        if (!$sigs) return $out;

        $companies = $this->db->query("SELECT id, company_name, billing_contact_id FROM companies WHERE company_name IS NOT NULL AND company_name <> ''")->fetchAll(PDO::FETCH_ASSOC);
        $companyById = [];
        foreach ($companies as $co) $companyById[(int)$co['id']] = $co;

        foreach ($sigs as $cid => $x) {
            $c = $contacts[$cid];
            $m = $x['m'];
            $ev = 'Email from ' . $c['name'] . ' · ' . date('M j, Y', strtotime((string)$m['sent_at']))
                . ($m['subject'] ? ' · “' . self::clip((string)$m['subject'], 50) . '”' : '') . ' — signature: ' . str_replace("\n", ' / ', self::clip($x['text'], 160));
            $base = ['owner' => self::HEAD_YUI, 'subject_type' => 'contact', 'subject_id' => $cid, 'contact_id' => $cid,
                     'evidence' => $ev, 'source' => 'email', 'source_ref' => $m['id'], 'source_at' => $m['sent_at']];

            $role = self::detectRole($x['text'], $c['name']);
            if ($role && $this->hasColumn('contacts', 'contact_role')) {
                $match = $role['company'] !== null ? self::matchCompany($role['company'], $companies) : null;
                $prop = self::roleProposal($c, $role, $match, $match ? ($companyById[$match['id']] ?? null) : null);
                if ($prop) $out[] = ['kind' => 'role', 'pattern' => $prop['pattern'], 'summary' => $prop['summary'], 'ops' => $prop['ops']] + $base;
                if ($role['billing']) {
                    [$qp, $qc, $qq] = $this->quoteRecipientOf($cid);
                    $q = self::quoteSignerProposal($c, $role, $qp, $qc, $qq);
                    if ($q) $out[] = ['kind' => 'quote_signer', 'pattern' => $q['pattern'], 'summary' => $q['summary'], 'ops' => $q['ops'], 'quotes' => $q['quotes']] + $base;
                }
            }
            $det = self::detailsProposal($c, self::contactDetails($x['text']));
            if ($det) $out[] = ['kind' => 'details', 'pattern' => $det['pattern'], 'summary' => $det['summary'], 'ops' => $det['ops']] + $base;
        }
        return $out;
    }

    /** id => contact (name, email, phone, mobile, role, employer). Test records left out. */
    private function contacts(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $role = $this->hasColumn('contacts', 'contact_role') ? 'contact_role' : 'NULL AS contact_role';
        $emp = $this->hasColumn('contacts', 'employer_company_id') ? 'employer_company_id' : 'NULL AS employer_company_id';
        $mob = $this->hasColumn('contacts', 'mobile') ? 'mobile' : 'NULL AS mobile';
        $out = [];
        foreach ($this->db->query("SELECT id, first_name, last_name, email, phone, {$mob}, {$role}, {$emp}
                                   FROM contacts WHERE id IN (" . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['name'] = trim($r['first_name'] . ' ' . $r['last_name']);
            if (stripos($r['name'], self::NOT_TEST) !== false) continue;
            $out[(int)$r['id']] = $r;
        }
        return $out;
    }

    /** Where this person is the quote recipient: [properties, companies, open quotes]. */
    private function quoteRecipientOf(int $cid): array
    {
        $p = $c = $q = [];
        try {
            if ($this->hasColumn('properties', 'quote_contact_id')) {
                $s = $this->db->prepare("SELECT id, address FROM properties WHERE quote_contact_id = ? ORDER BY id LIMIT 20");
                $s->execute([$cid]);
                $p = $s->fetchAll(PDO::FETCH_ASSOC);
            }
            if ($this->hasColumn('companies', 'quote_contact_id')) {
                $s = $this->db->prepare("SELECT id, company_name FROM companies WHERE quote_contact_id = ? ORDER BY id LIMIT 20");
                $s->execute([$cid]);
                $c = $s->fetchAll(PDO::FETCH_ASSOC);
            }
            $s = $this->db->prepare("SELECT id, quote_number FROM quotes WHERE contact_id = ? AND status IN ('draft', 'sent', 'viewed') ORDER BY id LIMIT 20");
            $s->execute([$cid]);
            $q = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Clues quote recipient: ' . $e->getMessage());
        }
        return [$p, $c, $q];
    }

    // ── Reading open clues ─────────────────────────────────────────────────

    /**
     * Open clues still worth showing (not already done by hand, record not changed under them).
     * @param string|null $owner  'penny' | 'yui' | null for both
     */
    public function open(?string $owner = null, ?int $contactId = null, int $limit = 30): array
    {
        if (!$this->ready()) return [];
        $where = ["status = 'open'"];
        $args = [];
        if ($owner !== null) { $where[] = 'owner = ?'; $args[] = $owner; }
        if ($contactId !== null) { $where[] = 'contact_id = ?'; $args[] = $contactId; }
        $s = $this->db->prepare('SELECT * FROM clue_suggestions WHERE ' . implode(' AND ', $where) . ' ORDER BY source_at DESC, id DESC LIMIT ' . (int)max(1, min(200, $limit * 3)));
        $s->execute($args);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];
        $current = $this->currentRows($rows);
        $out = [];
        foreach ($rows as $r) {
            $change = json_decode((string)$r['proposed_change'], true) ?: ['ops' => []];
            if (self::isStale($change['ops'] ?? [], $current)) continue;
            $r['change'] = $change;
            $out[] = $r;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /** "table:id" => current values of every column the clues touch. */
    private function currentRows(array $rows): array
    {
        $want = [];
        foreach ($rows as $r) {
            $ch = json_decode((string)$r['proposed_change'], true) ?: [];
            foreach ((array)($ch['ops'] ?? []) as $o) {
                if (($o['op'] ?? '') !== 'update' || !isset(self::WRITABLE[$o['table'] ?? ''])) continue;
                $want[$o['table']][(int)$o['id']] = true;
            }
        }
        $out = [];
        foreach ($want as $table => $ids) {
            $cols = array_values(array_filter(self::WRITABLE[$table], fn($c) => $this->hasColumn($table, $c)));
            if (!$cols) continue;
            foreach ($this->db->query('SELECT id, `' . implode('`, `', $cols) . "` FROM `{$table}` WHERE id IN (" . implode(',', array_map('intval', array_keys($ids))) . ')')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[$table . ':' . (int)$row['id']] = $row;
            }
        }
        return $out;
    }

    /** Brief items for Penny or Yui (priority 2). Cheap and read-only; empty before migration 1206. */
    public function briefItems(string $owner, int $max = 10): array
    {
        try {
            return array_map([self::class, 'briefItem'], $this->open($owner, null, $max));
        } catch (Throwable $e) {
            error_log('Clues brief: ' . $e->getMessage());
            return [];
        }
    }

    /** For the card: what Tim sees per row. */
    public static function forCard(array $r): array
    {
        $ops = (array)($r['change']['ops'] ?? []);
        $quotes = (array)($r['change']['quotes'] ?? []);
        $url = $r['subject_type'] === 'property' ? '/crm/properties/view.php?id=' . (int)$r['subject_id']
            : '/crm/clients_appstack.php?action=view_contact&id=' . (int)$r['subject_id'];
        if (!$ops && $quotes) $url = '/crm/quotes/view.php?id=' . (int)$quotes[0]['id'];
        return ['id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'owner' => (string)$r['owner'],
                'summary' => (string)$r['summary'], 'evidence' => (string)$r['evidence'], 'source' => (string)$r['source'],
                'at' => $r['source_at'], 'url' => $url, 'apply' => $ops ? 'Apply' : 'Got it'];
    }

    // ── Tim's decision ─────────────────────────────────────────────────────

    /**
     * Apply (exactly the stored ops, each guarded by its before-values) or dismiss ("Not right").
     * Either way the decision is recorded and the pattern counted.
     * @return array{ok: bool, message: string, contact_id?: ?int, kind?: string, summary?: string}
     */
    public function decide(int $id, string $decision, int $userId): array
    {
        if (!in_array($decision, ['apply', 'dismiss'], true)) return ['ok' => false, 'message' => 'Unknown decision'];
        $this->db->beginTransaction();
        try {
            $s = $this->db->prepare('SELECT * FROM clue_suggestions WHERE id = ? FOR UPDATE');
            $s->execute([$id]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if (!$r || $r['status'] !== 'open') {
                $this->db->rollBack();
                return ['ok' => false, 'message' => 'That clue was already decided — refresh.'];
            }
            if ($decision === 'apply') {
                $err = $this->applyOps((array)((json_decode((string)$r['proposed_change'], true) ?: [])['ops'] ?? []));
                if ($err !== null) {
                    $this->db->rollBack();
                    return ['ok' => false, 'message' => $err];
                }
            }
            $this->db->prepare('UPDATE clue_suggestions SET status = ?, decided_by = ?, decided_at = ? WHERE id = ?')
                ->execute([$decision === 'apply' ? 'accepted' : 'dismissed', $userId, $this->now(), $id]);
            $col = $decision === 'apply' ? 'accepted' : 'dismissed';
            $up = $this->db->prepare("INSERT INTO clue_patterns (pattern, {$col}) VALUES (?, 1) ON DUPLICATE KEY UPDATE {$col} = {$col} + 1");
            foreach (array_unique(explode('|', (string)$r['pattern'])) as $p) if ($p !== '') $up->execute([mb_substr($p, 0, 60)]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('Clues decide #' . $id . ': ' . $e->getMessage());
            return ['ok' => false, 'message' => 'That didn\'t save — nothing was changed. Try again.'];
        }
        return ['ok' => true, 'contact_id' => $r['contact_id'] !== null ? (int)$r['contact_id'] : null, 'kind' => (string)$r['kind'],
                'summary' => (string)$r['summary'],
                'message' => $decision === 'apply' ? 'Done — saved.' : 'Got it — I won\'t suggest that again.'];
    }

    /** Runs inside decide()'s transaction. Returns an error message, or null when every op went through. */
    private function applyOps(array $ops): ?string
    {
        $newCompany = null;
        foreach ($ops as $o) {
            $op = (string)($o['op'] ?? '');
            if ($op === 'create_company') {
                $name = trim((string)($o['name'] ?? ''));
                $type = in_array($o['type'] ?? '', ['strata', 'property_manager', 'business'], true) ? $o['type'] : 'business';
                if ($name === '' || mb_strlen($name) > 120) return 'That company name doesn\'t look right.';
                $s = $this->db->prepare('SELECT id FROM companies WHERE company_name = ? ORDER BY id LIMIT 1');
                $s->execute([$name]);
                $newCompany = (int)$s->fetchColumn();
                if ($newCompany === 0) {
                    $this->db->prepare("INSERT INTO companies (company_name, company_type, account_status) VALUES (?, ?, 'active')")->execute([$name, $type]);
                    $newCompany = (int)$this->db->lastInsertId();
                }
                continue;
            }
            if ($op !== 'update') return 'Unknown change.';
            $table = (string)($o['table'] ?? '');
            $set = (array)($o['set'] ?? []);
            if (!isset(self::WRITABLE[$table]) || !$set || array_diff(array_keys($set), self::WRITABLE[$table])) return 'That change isn\'t allowed.';
            $id = (int)($o['id'] ?? 0);
            $before = (array)($o['before'] ?? []);
            $cols = array_unique(array_merge(array_keys($set), array_keys($before)));
            if (array_diff($cols, self::WRITABLE[$table])) return 'That change isn\'t allowed.';
            $s = $this->db->prepare('SELECT `' . implode('`, `', $cols) . "` FROM `{$table}` WHERE id = ? FOR UPDATE");
            $s->execute([$id]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) return 'That record is gone.';
            foreach ($before as $k => $v) {
                if (!self::same($row[$k] ?? null, $v)) return 'That record changed since I spotted this — nothing was changed. It will drop off the list.';
            }
            $vals = [];
            foreach ($set as $k => $v) {
                if ($v === '@company') {
                    if (!$newCompany) return 'The company wasn\'t created.';
                    $v = $newCompany;
                }
                if ($table === 'properties' && $k === 'property_type' && $v !== 'strata') return 'That change isn\'t allowed.';
                if ($table === 'contacts' && $k === 'contact_role' && !in_array($v, self::ROLES, true)) return 'That role isn\'t allowed.';
                $vals[] = $v;
            }
            $this->db->prepare("UPDATE `{$table}` SET `" . implode('` = ?, `', array_keys($set)) . '` = ? WHERE id = ?')
                ->execute(array_merge($vals, [$id]));
        }
        return null;
    }
}
