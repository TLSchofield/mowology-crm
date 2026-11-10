<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/Sales/Services/SalesInboxService.php';
require_once dirname(__DIR__, 2) . '/Sales/Services/EmailLeadService.php';
require_once dirname(__DIR__, 2) . '/Sales/Services/UnclaimedReplyService.php';

/**
 * InboundIdentityService — who is an email about, when the sender is not on file (2026-11-09).
 *
 * A paralegal wrote to office@ asking what a numbered company owed; her address was not a contact, so the
 * poll dropped the mail and nobody was told. This reads what the person wrote (subject + new text, with quoted
 * history and the signature cut away, so a law firm's footer is never the account) and looks for the
 * business's own accounts in it:
 *   company  a business account's name, whole words, only when distinctive (a numbered company, or two or more
 *            words that are not generic — see GENERIC_WORDS)
 *   address  a street address of the account (SalesInboxService::addressKey, whole tokens)
 *   domain   the sender's company domain, never free mail
 *   invoice  one of the account's invoice numbers, in the subject or their own words
 * Strength: an invoice number, company + address on one account, or a numbered company on one account = strong (placed on the
 * account's contact); one account by address alone / a plain company name alone / the domain alone = medium (a
 * question for the owner); several accounts tied, or evidence pointing at different accounts = weak (candidates
 * listed, none chosen); nothing = none. Evidence is not proof of who wrote it — nothing here sends anything.
 *
 * identify() and triage() are pure; buildIndex() reads this business's own accounts (no hard-coded names).
 */
class InboundIdentityService
{
    /**
     * Words that name a kind of organisation, not an organisation. A name left with fewer than two other words is
     * not distinctive: "Strata", "Dorset Strata" or "ABC Property Management" would match a line of anyone's text.
     * Legal forms (ltd, inc...), property words (strata, plan, society...) and filler (the, of, and).
     */
    public const GENERIC_WORDS = ['the', 'and', 'of', 'a', 'ltd', 'limited', 'inc', 'incorporated', 'corp', 'corporation', 'co', 'company',
        'llc', 'llp', 'lp', 'bc', 'strata', 'plan', 'property', 'properties', 'management', 'mgmt', 'managers', 'services', 'service',
        'group', 'holdings', 'realty', 'real', 'estate', 'residential', 'society', 'association', 'council', 'owners',
        'enterprises', 'apartments', 'apartment', 'building', 'buildings', 'complex', 'condominium', 'condominiums', 'homes', 'housing',
        'rentals', 'rental', 'resident', 'residents', 'commercial', 'investments', 'ventures', 'developments', 'development'];

    /** Trailing legal forms dropped before a name is matched ("1355183 bc ltd" is written "1355183 B.C." as often as not). */
    private const LEGAL_SUFFIX = ['ltd', 'limited', 'inc', 'incorporated', 'corp', 'corporation', 'co', 'company', 'llc', 'llp', 'lp'];

    /** Lowercase, punctuation gone ("B.C." = "BC"), "&" = "and", single spaces. */
    public static function normaliseCompany(string $s): string
    {
        $t = strtolower($s);
        $t = str_replace('&', ' and ', $t);
        $t = preg_replace("/[.'\x{2019}`]+/u", '', $t) ?? '';
        $t = preg_replace('/[^a-z0-9]+/', ' ', $t) ?? '';
        return trim($t);
    }

    /** A numbered company ("1355183 BC LTD"): starts with a run of five or more digits. */
    private static function isNumbered(string $norm): bool
    {
        return (bool)preg_match('/^\d{5,}\b/', $norm);
    }

    /**
     * The words a name is matched by, or null when the name is not distinctive enough to match on its own.
     * @return array{phrase: string, numbered: bool}|null
     */
    private static function companyPhrase(string $name): ?array
    {
        $norm = self::normaliseCompany($name);
        if ($norm === '') return null;
        $words = explode(' ', $norm);
        while (count($words) > 1 && in_array(end($words), self::LEGAL_SUFFIX, true)) array_pop($words);
        $phrase = implode(' ', $words);
        if (self::isNumbered($norm)) return count($words) >= 2 ? ['phrase' => $phrase, 'numbered' => true] : null;
        $core = array_filter($words, fn($w) => !in_array($w, self::GENERIC_WORDS, true));
        return count($core) >= 2 ? ['phrase' => $phrase, 'numbered' => false] : null;
    }

    /**
     * @param array{from?: string, subject?: string, body?: string} $m
     * @param array<int, array{client_id:int, type:string, name:string, contact_id:?int, addresses:array, domains:array, aliases?:array}> $accounts
     * @return array{strength: string, client_id: ?int, contact_id: ?int, evidence: string[],
     *               candidates: array<int, array{client_id:int, contact_id:?int, name:string, evidence:string[]}>}
     */
    public static function identify(array $m, array $accounts): array
    {
        $none = ['strength' => 'none', 'client_id' => null, 'contact_id' => null, 'evidence' => [], 'candidates' => []];
        $text = trim((string)($m['subject'] ?? '')) . "\n" . SalesInboxService::withoutSignature((string)($m['body'] ?? ''));
        $words = ' ' . self::normaliseCompany($text) . ' ';
        $tokens = ' ' . SalesInboxService::addressKey($text, false) . ' ';
        $addr = SalesInboxService::addresses((string)($m['from'] ?? ''))[0] ?? '';
        $dom = SalesInboxService::domain($addr);
        if ($dom !== '' && (in_array($dom, SalesInboxService::FREE_DOMAINS, true) || in_array($dom, SalesInboxService::OUR_DOMAINS, true))) $dom = '';

        $refs = self::invoiceRefs($text);
        $hits = [];
        foreach ($accounts as $a) {
            $ev = [];
            $numbered = false;
            if (($a['type'] ?? '') === 'business') {
                foreach (array_merge([(string)$a['name']], array_map('strval', (array)($a['aliases'] ?? []))) as $n) {
                    $p = self::companyPhrase($n);
                    if ($p !== null && strpos($words, ' ' . $p['phrase'] . ' ') !== false) { $ev['company'] = true; $numbered = $numbered || $p['numbered']; }
                }
            }
            foreach ((array)($a['addresses'] ?? []) as $ad) {
                $k = SalesInboxService::addressKey((string)$ad);
                if ($k !== '' && strpos($tokens, ' ' . $k . ' ') !== false) { $ev['address'] = true; break; }
            }
            if ($refs && array_intersect($refs, array_map('strtoupper', array_map('strval', (array)($a['invoices'] ?? []))))) $ev['invoice'] = true;
            if ($dom !== '' && in_array($dom, array_map('strtolower', (array)($a['domains'] ?? [])), true)) $ev['domain'] = true;
            if (!$ev) continue;
            $hits[] = ['a' => $a, 'ev' => array_values(array_filter(['invoice', 'company', 'address', 'domain'], fn($e) => isset($ev[$e]))), 'numbered' => $numbered];
        }
        if (!$hits) return $none;

        $cand = array_map(fn($h) => ['client_id' => (int)$h['a']['client_id'], 'contact_id' => isset($h['a']['contact_id']) ? (int)$h['a']['contact_id'] : null,
                                     'name' => (string)$h['a']['name'], 'evidence' => $h['ev']], $hits);
        $weak = ['strength' => 'weak', 'client_id' => null, 'contact_id' => null,
                 'evidence' => array_values(array_unique(array_merge(...array_column($hits, 'ev')))), 'candidates' => $cand];
        $pick = function (int $i, string $strength) use ($hits, $cand): array {
            return ['strength' => $strength, 'client_id' => $cand[$i]['client_id'], 'contact_id' => $cand[$i]['contact_id'],
                    'evidence' => $hits[$i]['ev'], 'candidates' => $cand];
        };

        // Decisive: one of our invoice numbers, or the company and its address together. One such
        // account beats others that only share its address or domain (the same client is often on file twice: a business
        // account holding the invoices and the person's own account holding the property).
        $decisive = array_keys(array_filter($hits, fn($h) => in_array('invoice', $h['ev'], true)
            || (in_array('company', $h['ev'], true) && in_array('address', $h['ev'], true))));
        if (count($decisive) > 1) return $weak;
        if (count($decisive) === 1) return $pick($decisive[0], $cand[$decisive[0]]['contact_id'] !== null ? 'strong' : 'medium');

        // Accounts the words themselves point at (company or address); the domain only says "same firm".
        $named = array_keys(array_filter($hits, fn($h) => array_intersect(['company', 'address'], $h['ev']) !== []));
        if (count($named) > 1) return $weak;
        if (count($named) === 1) {
            $i = $named[0];
            return $pick($i, $hits[$i]['numbered'] && $cand[$i]['contact_id'] !== null ? 'strong' : 'medium');   // a numbered company nothing else contradicts
        }

        // Domain alone. Several records of one person (the same contact on a business and a personal account) are
        // one contact: the business account speaks for them.
        if (count($hits) === 1) return $pick(0, 'medium');
        $contacts = array_unique(array_map(fn($c) => $c['contact_id'], $cand));
        if (count($contacts) === 1 && reset($contacts) !== null) {
            $best = 0;
            foreach ($hits as $i => $h) {
                if (($h['a']['type'] ?? '') === 'business') { $best = $i; break; }
            }
            return $pick($best, 'medium');
        }
        return $weak;
    }

    /** Our invoice numbers in a text, in stored form: "INV-2026-0413", also written "INV# 2026-0413". @return string[] */
    public static function invoiceRefs(string $text): array
    {
        preg_match_all('/\bINV\s*[-#:]*\s*(\d{4})-(\d{3,6})\b/i', $text, $m, PREG_SET_ORDER);
        return array_values(array_unique(array_map(fn($x) => 'INV-' . $x[1] . '-' . $x[2], $m)));
    }

    /**
     * Is this a person writing, or a robot / a pitch? Conservative: unsure = person (a person is a question for
     * the owner; noise is only counted).
     * @return array{kind: string, reason: string}
     */
    public static function triage(string $from, string $subject, string $body, string $rawHeaders = ''): array
    {
        $addr = SalesInboxService::addresses($from)[0] ?? '';
        $new = SalesInboxService::snippet($body);
        if (UnclaimedReplyService::isAutomated($from, $subject, $new)) return ['kind' => 'noise', 'reason' => 'automated reply'];
        if ($addr !== '' && EmailLeadService::isAutomated($addr, $rawHeaders)) return ['kind' => 'noise', 'reason' => 'automated sender'];
        if ($addr !== '' && EmailLeadService::marketingDomain($addr)) return ['kind' => 'noise', 'reason' => 'marketing sender'];
        $pitch = EmailLeadService::pitchSignals($subject, $new, $addr, $rawHeaders);
        $decisive = array_values(array_intersect($pitch, EmailLeadService::PITCH_DECISIVE));
        if ($decisive || count($pitch) >= 2) return ['kind' => 'noise', 'reason' => 'cold pitch (' . implode(', ', $decisive ?: $pitch) . ')'];
        return ['kind' => 'person', 'reason' => $pitch ? 'one soft pitch signal: ' . $pitch[0] : 'reads like a person'];
    }

    /**
     * This business's accounts, for identify(): one row per active client. Set-based, MySQL 5.7.
     * contact_id: the contact on the account's newest invoice, else a property's site contact, else the
     * client's own legacy contact. Names: display_name (+ billing entity names and the linked company's name
     * as aliases). Addresses: billing address and the properties' (cut at the first comma — no city).
     * @return array<int, array{client_id:int, type:string, name:string, contact_id:?int, addresses:string[], domains:string[], aliases:string[]}>
     */
    public static function buildIndex(PDO $db): array
    {
        $acc = [];
        foreach ($db->query("SELECT id, client_type, display_name, billing_address, billing_email, legacy_company_id, legacy_contact_id
                               FROM clients WHERE status = 'active' OR status IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int)$r['id'];
            $acc[$id] = ['client_id' => $id, 'type' => $r['client_type'] === 'individual' ? 'individual' : 'business',
                         'name' => (string)$r['display_name'], 'contact_id' => null, 'addresses' => [], 'domains' => [], 'aliases' => [],
                         '_legacy_contact' => $r['legacy_contact_id'] !== null ? (int)$r['legacy_contact_id'] : null,
                         '_site_contact' => null, '_emails' => [(string)$r['billing_email']]];
            if (trim((string)$r['billing_address']) !== '') $acc[$id]['addresses'][] = self::street((string)$r['billing_address']);
        }
        if (!$acc) return [];

        foreach ($db->query("SELECT cl.id AS client_id, c.company_name, c.billing_email
                               FROM clients cl JOIN companies c ON c.id = cl.legacy_company_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int)$r['client_id'];
            if (!isset($acc[$id])) continue;
            if (trim((string)$r['company_name']) !== '') $acc[$id]['aliases'][] = (string)$r['company_name'];
            $acc[$id]['_emails'][] = (string)$r['billing_email'];
        }
        foreach ($db->query("SELECT client_id, address, billing_entity_name, site_contact_id
                               FROM properties WHERE client_id IS NOT NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int)$r['client_id'];
            if (!isset($acc[$id])) continue;
            if (trim((string)$r['address']) !== '') $acc[$id]['addresses'][] = self::street((string)$r['address']);
            if (trim((string)$r['billing_entity_name']) !== '') $acc[$id]['aliases'][] = (string)$r['billing_entity_name'];
            if ($acc[$id]['_site_contact'] === null && $r['site_contact_id'] !== null) $acc[$id]['_site_contact'] = (int)$r['site_contact_id'];
        }
        // Newest invoice per account: date then id then contact, packed into one sortable string so one GROUP BY finds it.
        $invoiceContact = [];
        foreach ($db->query("SELECT client_id, MAX(CONCAT(COALESCE(DATE_FORMAT(invoice_date, '%Y%m%d'), '00000000'), LPAD(id, 10, '0'), LPAD(contact_id, 10, '0'))) AS k
                               FROM invoices WHERE client_id IS NOT NULL AND contact_id IS NOT NULL GROUP BY client_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $invoiceContact[(int)$r['client_id']] = (int)substr((string)$r['k'], 18);
        }
        foreach ($db->query("SELECT client_id, invoice_number FROM invoices WHERE client_id IS NOT NULL AND invoice_number IS NOT NULL
                               AND status NOT IN ('draft', 'cancelled', 'void')")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($acc[(int)$r['client_id']])) $acc[(int)$r['client_id']]['invoices'][] = (string)$r['invoice_number'];
        }
        $people = [];   // account => contact ids whose email counts as the account's domain
        foreach ($db->query("SELECT client_id, contact_id FROM client_contacts")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $people[(int)$r['client_id']][(int)$r['contact_id']] = true;
        }
        $email = [];
        foreach ($db->query("SELECT id, email FROM contacts WHERE email IS NOT NULL AND email <> ''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $email[(int)$r['id']] = (string)$r['email'];
        }

        foreach ($acc as $id => &$a) {
            $a['contact_id'] = $invoiceContact[$id] ?? $a['_site_contact'] ?? $a['_legacy_contact'];
            foreach (array_filter([$a['contact_id'], $a['_site_contact'], $a['_legacy_contact']]) as $cid) $people[$id][(int)$cid] = true;
            $emails = $a['_emails'];
            foreach (array_keys($people[$id] ?? []) as $cid) $emails[] = $email[$cid] ?? '';
            $domains = [];
            foreach ($emails as $e) {
                $d = SalesInboxService::domain(trim($e));
                if ($d !== '' && !in_array($d, SalesInboxService::FREE_DOMAINS, true) && !in_array($d, SalesInboxService::OUR_DOMAINS, true)) $domains[$d] = true;
            }
            $a['domains'] = array_keys($domains);
            $a['addresses'] = array_values(array_unique($a['addresses']));
            $a['aliases'] = array_values(array_unique($a['aliases']));
            unset($a['_legacy_contact'], $a['_site_contact'], $a['_emails']);
        }
        unset($a);
        return array_values($acc);
    }

    /** A street address without the city after its first comma or line break. */
    private static function street(string $a): string
    {
        return trim((string)preg_split('/[,\r\n]/', $a, 2)[0]);
    }
}
