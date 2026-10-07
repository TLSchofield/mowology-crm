<?php
/**
 * ListingsService — where Mowology is listed, and whether each listing says the same thing.
 *
 * Google and AI search build their picture of a local business from the agreement between its
 * listings (name, address, phone — "NAP" — plus website and hours). This tracks each directory
 * by hand (most have no API): status (not claimed / claimed / verified), profile URL, review
 * count and rating, when Tim last checked, and what the listing shows, compared with the master
 * record.
 *
 * The master NAP is read from business_settings (tenant identity is never hardcoded) plus
 * business hours from ops_settings 'business_hours' and categories from ops_settings
 * 'listings_categories'.
 *
 * Table marketing_listings (migration 1201). No namespace / no autoloader in production.
 */
class ListingsService
{
    public const STATUSES = ['unknown' => 'Not checked', 'not_claimed' => 'Not claimed', 'claimed' => 'Claimed', 'verified' => 'Verified'];
    /** Re-check a listing after this many days. */
    public const STALE_DAYS = 90;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'marketing_listings'")->fetchColumn() !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return array{name: string, address: string, phone: string, website: string, email: string, hours: string, categories: string} */
    public function master(): array
    {
        $b = [];
        try {
            $b = $this->db->query("SELECT * FROM business_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}
        return [
            'name'       => trim((string)($b['company_name'] ?? '')),
            'address'    => trim(preg_replace('/\s*\n\s*/', ', ', (string)($b['company_address'] ?? ''))),
            'phone'      => trim((string)($b['company_phone'] ?? '')),
            'website'    => trim((string)($b['company_website'] ?? '')),
            'email'      => trim((string)($b['company_email'] ?? '')),
            'hours'      => self::hoursText($this->setting('business_hours', '')),
            'categories' => $this->setting('listings_categories', ''),
        ];
    }

    /** @return array<int, array> listings with their NAP verdict against the master */
    public function all(): array
    {
        $master = $this->master();
        $rows = $this->db->query("SELECT * FROM marketing_listings ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['nap'] = self::compare($master, [
                'name' => $r['listed_name'] ?? '', 'address' => $r['listed_address'] ?? '',
                'phone' => $r['listed_phone'] ?? '', 'website' => $r['listed_website'] ?? '',
            ]);
            $r['stale'] = empty($r['last_checked']) || strtotime((string)$r['last_checked']) < strtotime('-' . self::STALE_DAYS . ' days');
        }
        return $rows;
    }

    /** Tim's edit of one listing. Unknown fields are ignored; checked today unless told otherwise. */
    public function save(int $id, array $in, int $userId): array
    {
        $status = (string)($in['status'] ?? 'unknown');
        if (!isset(self::STATUSES[$status])) return ['ok' => false, 'error' => 'Unknown status'];
        $url = trim((string)($in['profile_url'] ?? ''));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) return ['ok' => false, 'error' => 'The profile link must start with https://'];
        $rating = trim((string)($in['rating'] ?? ''));
        $rating = $rating === '' ? null : max(0.0, min(5.0, round((float)$rating, 1)));
        $count = trim((string)($in['review_count'] ?? ''));
        $count = $count === '' ? null : max(0, (int)$count);
        $fields = [
            'listed_name' => mb_substr(trim((string)($in['listed_name'] ?? '')), 0, 200),
            'listed_address' => mb_substr(trim((string)($in['listed_address'] ?? '')), 0, 300),
            'listed_phone' => mb_substr(trim((string)($in['listed_phone'] ?? '')), 0, 40),
            'listed_website' => mb_substr(trim((string)($in['listed_website'] ?? '')), 0, 300),
        ];
        $nap = self::compare($this->master(), ['name' => $fields['listed_name'], 'address' => $fields['listed_address'],
                                               'phone' => $fields['listed_phone'], 'website' => $fields['listed_website']]);
        $this->db->prepare("
            UPDATE marketing_listings
            SET status = ?, profile_url = ?, review_count = ?, rating = ?, listed_name = ?, listed_address = ?, listed_phone = ?,
                listed_website = ?, nap_status = ?, nap_issues = ?, notes = ?, last_checked = ?, updated_by = ?
            WHERE id = ?
        ")->execute([
            $status, $url !== '' ? mb_substr($url, 0, 500) : null, $count, $rating,
            $fields['listed_name'] ?: null, $fields['listed_address'] ?: null, $fields['listed_phone'] ?: null, $fields['listed_website'] ?: null,
            $nap['status'], $nap['issues'] ? mb_substr(implode('; ', $nap['issues']), 0, 255) : null,
            mb_substr(trim((string)($in['notes'] ?? '')), 0, 2000) ?: null,
            date('Y-m-d'), $userId ?: null, $id,
        ]);
        return ['ok' => true, 'nap' => $nap];
    }

    public function saveCategories(string $categories): void
    {
        $this->db->prepare("
            INSERT INTO ops_settings (setting_key, setting_value, description) VALUES ('listings_categories', ?, 'Listings: business categories for the master NAP record')
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ")->execute([mb_substr(trim($categories), 0, 500)]);
    }

    /** @return array{total: int, verified: int, claimed: int, not_claimed: int, unknown: int, mismatches: int, stale: int} */
    public function counts(): array
    {
        $c = ['total' => 0, 'verified' => 0, 'claimed' => 0, 'not_claimed' => 0, 'unknown' => 0, 'mismatches' => 0, 'stale' => 0];
        foreach ($this->all() as $r) {
            $c['total']++;
            $c[$r['status']] = ($c[$r['status']] ?? 0) + 1;
            if ($r['nap']['status'] === 'mismatch') $c['mismatches']++;
            if ($r['stale']) $c['stale']++;
        }
        return $c;
    }

    // ── NAP comparison (pure) ────────────────────────────────────────────────

    /**
     * Compare what a listing shows with the master record. Blank listing fields are "not
     * entered yet", not a mismatch.
     * @return array{status: string, fields: array<string, string>, issues: string[]}
     *   status: unchecked (nothing entered) | match | mismatch; fields[x]: match|differs|blank
     */
    public static function compare(array $master, array $listing): array
    {
        $fields = [];
        $issues = [];
        $checks = [
            'name' => [fn($s) => self::normName($s), 'Name'],
            'address' => [fn($s) => self::normAddress($s), 'Address'],
            'phone' => [fn($s) => self::normPhone($s), 'Phone'],
            'website' => [fn($s) => self::normWebsite($s), 'Website'],
        ];
        foreach ($checks as $k => [$norm, $label]) {
            $l = trim((string)($listing[$k] ?? ''));
            if ($l === '') { $fields[$k] = 'blank'; continue; }
            $same = $norm($l) === $norm((string)($master[$k] ?? ''));
            $fields[$k] = $same ? 'match' : 'differs';
            if (!$same) $issues[] = $label . ' differs: "' . $l . '"';
        }
        $entered = array_filter($fields, fn($v) => $v !== 'blank');
        $status = !$entered ? 'unchecked' : ($issues ? 'mismatch' : 'match');
        return ['status' => $status, 'fields' => $fields, 'issues' => $issues];
    }

    /** "Mowology Lawns & Landscapes Ltd." and "mowology lawns and landscapes" are the same name. */
    public static function normName(string $s): string
    {
        $s = strtolower(str_replace('&', ' and ', $s));
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
        $s = preg_replace('/\b(ltd|limited|inc|incorporated|corp|corporation|co)\b/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    public static function normPhone(string $s): string
    {
        $d = preg_replace('/\D+/', '', $s);
        return strlen($d) === 11 && $d[0] === '1' ? substr($d, 1) : $d;
    }

    public static function normWebsite(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('#^https?://#', '', $s);
        $s = preg_replace('#^www\.#', '', $s);
        return rtrim($s, '/');
    }

    /** Case, punctuation, street-type and direction abbreviations, postal-code spacing. */
    public static function normAddress(string $s): string
    {
        $s = strtolower($s);
        $s = preg_replace('/\b([a-z]\d[a-z])\s*(\d[a-z]\d)\b/', '$1$2', $s);
        $s = preg_replace('/[^a-z0-9 #]+/', ' ', $s);
        $map = ['street' => 'st', 'avenue' => 'ave', 'av' => 'ave', 'road' => 'rd', 'drive' => 'dr', 'boulevard' => 'blvd',
                'place' => 'pl', 'crescent' => 'cres', 'court' => 'ct', 'highway' => 'hwy', 'lane' => 'ln',
                'west' => 'w', 'east' => 'e', 'north' => 'n', 'south' => 's', 'suite' => 'unit', 'ste' => 'unit', '#' => 'unit',
                'british columbia' => 'bc', 'canada' => ''];
        $s = ' ' . preg_replace('/\s+/', ' ', $s) . ' ';
        foreach ($map as $from => $to) {
            $s = str_replace(' ' . $from . ' ', ' ' . $to . ' ', $s);
        }
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /** ops_settings business_hours JSON → "Mon–Fri 08:00–17:00". */
    public static function hoursText(string $json): string
    {
        $h = json_decode($json, true)['hours'] ?? null;
        if (!is_array($h)) return '';
        $days = ['monday' => 'Mon', 'tuesday' => 'Tue', 'wednesday' => 'Wed', 'thursday' => 'Thu', 'friday' => 'Fri', 'saturday' => 'Sat', 'sunday' => 'Sun'];
        $groups = [];
        foreach ($days as $k => $abbr) {
            $d = $h[$k] ?? null;
            $span = $d && !empty($d['open']) ? ($d['start'] ?? '') . '–' . ($d['end'] ?? '') : 'closed';
            $last = count($groups) - 1;
            if ($last >= 0 && $groups[$last]['span'] === $span) {
                $groups[$last]['to'] = $abbr;
            } else {
                $groups[] = ['from' => $abbr, 'to' => $abbr, 'span' => $span];
            }
        }
        $out = [];
        foreach ($groups as $g) {
            if ($g['span'] === 'closed') continue;
            $out[] = ($g['from'] === $g['to'] ? $g['from'] : $g['from'] . '–' . $g['to']) . ' ' . $g['span'];
        }
        return implode(', ', $out);
    }

    private function setting(string $key, string $default): string
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([$key]);
            $v = $s->fetchColumn();
            return $v === false || $v === null ? $default : (string)$v;
        } catch (Throwable $e) {
            return $default;
        }
    }
}
