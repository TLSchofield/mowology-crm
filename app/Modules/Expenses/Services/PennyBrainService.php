<?php
/**
 * PennyBrainService — what Penny has learned, counted for her 3D "brain" on the dashboard.
 *
 * Every unit is something real, learned from the owner's corrections and approvals:
 *   trusted vendors   — 5 receipts in a row approved unchanged (PennyBadgeService)
 *   badges            — earned, never claimed (PennyBadgeService)
 *   stores by GPS     — vendor_locations learned from receipts (migration 1124)
 *   vendor categories — vendors whose category she learned (vendor_parse_profiles)
 *   reading lessons   — receipt_parse_lessons: a field the reader got wrong, corrected
 *                       by the owner and now remembered (vendor, total, item name…)
 * Counted from her start line (ops_settings penny_brain_baseline, set the first time the
 * brain is shown — 2026-10-05): what the receipt reader knew before Penny doesn't count,
 * so she starts at shape 1 and grows only with what she learns from then on.
 * The total picks her shape: one of 500, from a tetrahedron to a folded geodesic brain
 * (public/crm/js/penny-brain.js builds the shapes; one new shape per thing learned).
 *
 * Per-item strength (2026-10-07, HeadBrain::TIERS): every vendor she has filed a receipt
 * for and every bank payee she has suggested an account for gets its own triangle,
 * coloured by its strength:
 *   Receipt vendors — key vendor:<id> (or vendor:name:<name>); strength from her receipt
 *                     decisions for that vendor (approved unchanged = +1, edited = drops
 *                     a tier) — the same decisions as PennyBadgeService's 5-in-a-row trust.
 *   Bank payees     — key payee:<BankImportService::descriptionKey>; strength from
 *                     bank_line_reviews (her suggestion kept = +1, account changed = drops
 *                     a tier) and, while she has never been corrected on that payee, at
 *                     least the OWNER's confirmations on its learned rule
 *                     (transaction_rules.owner_confirmations, migration 1220) — but only
 *                     for a rule that is active and owner-confirmed (2+). learned_count is
 *                     NOT used (2026-10-07): it counted imports nobody had looked at, which
 *                     is how "TD ON Line Loans System · White · 28" and "Wave Pyrl · White ·
 *                     28" showed as strong while their rules were sending loan and payroll
 *                     lines to Credit Card Payable. A payee whose rule is switched off or
 *                     not yet owner-confirmed, with no owner decision on Penny's card, is
 *                     "still learning": listed apart (learned()['learning']), no triangle.
 *                     Display names come from bank_payee_names (TLNK → TransLink) when taught.
 * A payee that is also a receipt vendor (Chevron) gets two triangles, one per skill.
 * These replace the old "vendors trusted" count; the other counts stay as they were.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/PennyBadgeService.php';
require_once dirname(__DIR__, 3) . '/Services/HeadBrain.php';
require_once dirname(__DIR__, 2) . '/Accounting/Services/BankImportService.php';

class PennyBrainService
{
    public const SHAPES = 500;
    /** Owner confirmations that make a learned bank rule act on its own (BankRuleLearning::CONFIRMATIONS). */
    public const OWNER_CONFIRMED = 2;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return array{units: int, parts: array<int, array{key: string, label: string, n: int}>} */
    public function learned(): array
    {
        $pb = new PennyBadgeService($this->db);
        $badges = count($pb->badges()['earned']);
        $raw = [
            'badges'   => $badges,
            'stores'   => $this->count("SELECT COUNT(*) FROM vendor_locations WHERE source = 'learned'"),
            'category' => $this->count("SELECT COUNT(*) FROM vendor_parse_profiles WHERE learned_accounting_category IS NOT NULL AND learned_accounting_category <> ''"),
            'lessons'  => $this->count("SELECT COUNT(*) FROM receipt_parse_lessons"),
        ];
        $base = $this->baseline($raw);
        $brain = self::combine(self::sinceBaseline($raw, $base['counts'])) + ['since' => $base['since']];
        $payees = $this->payeeItems();
        $learning = array_values(array_filter($payees, fn($p) => !empty($p['learning'])));
        $learned = array_values(array_filter($payees, fn($p) => empty($p['learning'])));
        return HeadBrain::withItems($brain, array_merge($this->vendorItems(), $learned)) + ['learning' => $learning];
    }

    /** One item per receipt vendor, from her real decisions (oldest first). */
    private function vendorItems(): array
    {
        try {
            $rows = $this->db->query("
                SELECT e.vendor_id, COALESCE(v.name, e.vendor_name_raw) AS vendor, s.status, s.decided_at
                FROM expense_suggestions s
                JOIN expenses e ON e.id = s.expense_id
                LEFT JOIN vendors v ON v.id = e.vendor_id
                WHERE s.source = 'live' AND s.status IN ('accepted', 'edited') AND e.status IN ('approved', 'forwarded')
                ORDER BY s.decided_at DESC, s.id DESC
                LIMIT 3000
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        return self::vendorItemsFrom(array_reverse($rows));
    }

    /** One item per bank payee she has suggested for (or that BankRuleLearning has learned). */
    private function payeeItems(): array
    {
        $reviews = [];
        $rules = [];
        try {
            if ($this->db->query("SHOW TABLES LIKE 'bank_line_reviews'")->rowCount() > 0) {
                foreach ($this->db->query("
                    SELECT t.description, r.suggested_account_id, r.final_account_id, r.outcome, r.decided_at
                    FROM bank_line_reviews r
                    JOIN accounting_transactions t ON t.id = r.transaction_id
                    ORDER BY r.decided_at ASC, r.id ASC
                ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $r['key'] = BankImportService::descriptionKey((string)$r['description']);
                    $reviews[] = $r;
                }
            }
        } catch (Throwable $e) { /* not migrated */ }
        try {
            // Owner decisions only (migration 1220). Without the column, no rule counts — only her card's reviews.
            foreach ($this->db->query("
                SELECT condition_value AS k,
                       MAX(CASE WHEN is_active = 1 THEN owner_confirmations ELSE 0 END) AS active_owner,
                       MAX(owner_confirmations) AS owner
                FROM transaction_rules
                WHERE source = 'learned' AND condition_field = 'description'
                GROUP BY condition_value
            ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rules[(string)$r['k']] = ['active_owner' => (int)$r['active_owner'], 'owner' => (int)$r['owner']];
            }
        } catch (Throwable $e) { /* not migrated: no rule counts */ }
        $names = [];
        try {
            $names = $this->db->query("SELECT payee_key, display_name FROM bank_payee_names")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Throwable $e) { /* migration 1211 not run */ }
        return self::payeeItemsFrom($reviews, $rules, $names);
    }

    /** Her start line: the counts on the day the brain was first shown (saved then). */
    private function baseline(array $raw): array
    {
        try {
            $v = $this->db->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'penny_brain_baseline'")->fetchColumn();
            $b = $v ? json_decode((string)$v, true) : null;
            if (is_array($b) && isset($b['counts'])) return $b;
            $b = ['since' => date('Y-m-d'), 'counts' => $raw];
            $this->db->prepare("
                INSERT INTO ops_settings (setting_key, setting_value, description)
                VALUES ('penny_brain_baseline', ?, 'Penny brain start line: what was already learned before her brain started counting')
                ON DUPLICATE KEY UPDATE setting_key = setting_key
            ")->execute([json_encode($b)]);
            return $b;
        } catch (Throwable $e) {
            return ['since' => null, 'counts' => []];
        }
    }

    /** A count that is 0 when the table/column isn't there yet. */
    private function count(string $sql): int
    {
        try {
            return (int)$this->db->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** What's new since the start line (never below zero — a lost streak isn't unlearning). */
    public static function sinceBaseline(array $raw, array $base): array
    {
        $out = [];
        foreach ($raw as $k => $v) $out[$k] = max(0, (int)$v - (int)($base[$k] ?? 0));
        return $out;
    }

    public static function combine(array $n): array
    {
        $labels = [
            'trusted'  => ['vendor trusted', 'vendors trusted'],
            'badges'   => ['badge', 'badges'],
            'stores'   => ['store found by GPS', 'stores found by GPS'],
            'category' => ['vendor category learned', 'vendor categories learned'],
            'lessons'  => ['reading lesson remembered', 'reading lessons remembered'],
        ];
        $parts = [];
        $units = 0;
        foreach ($labels as $k => [$one, $many]) {
            $c = max(0, (int)($n[$k] ?? 0));
            $units += $c;
            if ($c > 0) $parts[] = ['key' => $k, 'label' => $c . ' ' . ($c === 1 ? $one : $many), 'n' => $c];
        }
        return ['units' => $units, 'parts' => $parts];
    }

    /**
     * Pure: receipt decisions oldest first [vendor_id, vendor, status accepted|edited, decided_at]
     * → one item per vendor (approved unchanged = kept, edited = corrected).
     */
    public static function vendorItemsFrom(array $rows): array
    {
        $by = [];
        foreach ($rows as $r) {
            $name = trim((string)($r['vendor'] ?? ''));
            if ($name === '') continue;
            $key = !empty($r['vendor_id']) ? 'vendor:' . (int)$r['vendor_id'] : 'vendor:name:' . mb_strtolower($name);
            $by[$key]['label'] = $name;
            $by[$key]['events'][] = ($r['status'] ?? '') === 'accepted';
            $by[$key]['at'] = $r['decided_at'] ?? null;
        }
        $out = [];
        foreach ($by as $key => $v) {
            $h = HeadBrain::strengthFromHistory($v['events']);
            $out[] = HeadBrain::item($key, $v['label'], $h['strength'],
                ['group' => 'Receipt vendors', 'streak' => $h['streak'], 'corrected_recently' => $h['corrected_recently'], 'at' => $v['at']]);
        }
        return $out;
    }

    /**
     * Pure: bank-line reviews oldest first [key, suggested_account_id, final_account_id, outcome,
     * decided_at, description] + learned rules [key => [active_owner, owner]] (owner
     * confirmations on the active rule / on any rule for that payee) + taught display names
     * [key => name] → one item per payee.
     * Her suggestion kept = +1; a different account = a correction (drops a tier); a line she
     * had no suggestion for is "first seen"; lines left as they were ('kept') teach nothing.
     * Never corrected on a payee → at least the owner's confirmations on its ACTIVE rule
     * (OWNER_CONFIRMED+). A payee known only from a rule that is off or not yet confirmed
     * comes back with learning = true (1 owner confirmation) or not at all (none).
     * Each item keeps the bank's own wording as `raw` when the display name differs.
     */
    public static function payeeItemsFrom(array $reviews, array $rules, array $names = []): array
    {
        $by = [];
        foreach ($reviews as $r) {
            $key = (string)($r['key'] ?? '');
            if (strlen($key) < 4 || ($r['outcome'] ?? '') === 'kept') continue;
            $sug = (int)($r['suggested_account_id'] ?? 0);
            $fin = (int)($r['final_account_id'] ?? 0);
            if ($sug > 0) $by[$key]['events'][] = $sug === $fin;
            elseif (empty($by[$key]['events'])) $by[$key]['events'] = [true];   // taught her: first seen
            $by[$key]['at'] = $r['decided_at'] ?? null;
            if (!empty($r['description'])) $by[$key]['raw'] = self::payeeLabel((string)$r['description']);
        }
        foreach ($rules as $key => $r) {
            if (strlen((string)$key) >= 4) $by[(string)$key] ??= ['events' => [], 'at' => null];
        }
        $out = [];
        foreach ($by as $key => $v) {
            $key = (string)$key;
            $rule = is_array($rules[$key] ?? null) ? $rules[$key] : ['active_owner' => 0, 'owner' => 0];
            $activeOwner = (int)($rule['active_owner'] ?? 0);
            $trusted = $activeOwner >= self::OWNER_CONFIRMED;
            $events = $v['events'] ?? [];
            $learning = false;
            if ($events) {
                $h = HeadBrain::strengthFromHistory($events);
                $corrected = in_array(false, $events, true);
                $strength = (!$corrected && $trusted) ? max($h['strength'], $activeOwner) : $h['strength'];
                $streak = $corrected ? $h['streak'] : $strength;
                $recent = $h['corrected_recently'];
            } elseif ($trusted) {
                $strength = $streak = $activeOwner;
                $recent = false;
            } else {
                $strength = $streak = (int)($rule['owner'] ?? 0);
                $recent = false;
                $learning = true;
            }
            if ($strength < 1) continue;
            $raw = $v['raw'] ?? self::payeeLabel($key);
            $label = trim((string)($names[$key] ?? '')) ?: $raw;
            $extra = ['group' => 'Bank payees', 'streak' => $streak, 'corrected_recently' => $recent, 'at' => $v['at'] ?? null];
            if (strcasecmp($label, $raw) !== 0) $extra['raw'] = $raw;
            $item = HeadBrain::item('payee:' . $key, $label, $strength, $extra);
            if ($learning) $item['learning'] = true;
            $out[] = $item;
        }
        return $out;
    }

    /**
     * A readable payee name from a bank description or rule key:
     * "PRE-AUTHORIZED DEBIT TELUS MOBILITY 0045" → "Telus Mobility"; "TD LOANS 4471" → "TD Loans";
     * "INSURANCE CORPORATION OF BC" → "Insurance Corporation of BC".
     */
    public static function payeeLabel(string $text): string
    {
        $s = strtolower(trim($text));
        $s = preg_replace('/[^a-z0-9&\s]/', ' ', $s);
        $s = preg_replace('/\b[a-z]*\d[a-z0-9]*\b/', ' ', $s);              // reference numbers
        $s = preg_replace('/\b(bcca|abca|onca|north vancouver|vancouver|burnaby|surrey|richmond|langley|coquitlam)\b/', ' ', $s);
        $s = trim(preg_replace('/\s+/', ' ', $s));
        $generic = '(point of sale|pre ?authori[sz]ed|preauth|debit|payment|misc|bill|interac|purchase|pad|pap|memo)';
        $t = trim(preg_replace('/^(' . $generic . '\s+)+/', '', $s));
        $t = trim(preg_replace('/(\s+' . $generic . ')+$/', '', $t));
        if ($t === '') $t = $s;
        $words = array_map(function ($w) {
            if (in_array($w, ['of', 'and', 'the', 'de', 'for'], true)) return $w;
            return strlen($w) <= 2 ? strtoupper($w) : ucfirst($w);
        }, explode(' ', $t));
        return ucfirst(implode(' ', $words));
    }

    /** Shape number (1-based) for this many things learned: one new shape per thing, up to SHAPES. */
    public static function shapeNumber(int $units): int
    {
        return max(1, min(self::SHAPES, $units + 1));
    }
}
