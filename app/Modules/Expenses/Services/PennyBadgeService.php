<?php
/**
 * PennyBadgeService — the badges under Penny's photo on the dashboard.
 *
 * Every badge is earned from the owner's real decisions on her suggestions
 * (expense_suggestions, source 'live'), never from her own claims or the backtest:
 *   vendor trust  — her last 5 receipts from one vendor approved unchanged
 *   fuel pro      — her last 10 fuel calls: category and truck/equipment tag kept
 *   job finder    — 5 job picks the owner kept
 *   tax ace       — her last 20 receipts: total, GST and PST all kept
 *   in a row      — 10 receipts in a row approved unchanged
 *   found to bill — "did you forget to invoice?" answered "yes"
 * A badge is lost again when she slips (the streaks are her most recent decisions),
 * and the closest unearned one is shown dimmed with its progress.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class PennyBadgeService
{
    public const VENDOR_RUN = 5;
    public const FUEL_RUN = 10;
    public const JOBS_KEPT = 5;
    public const TAX_RUN = 20;
    public const STREAK = 10;
    /** At most this many vendor badges, so the row stays one glance. */
    public const MAX_VENDOR_BADGES = 3;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return array{earned: array, next: ?array} */
    public function badges(): array
    {
        return self::compute($this->decisions(), $this->foundToBill());
    }

    /** How well she knows each vendor (see vendorStrength). */
    public function vendors(int $limit = 8): array
    {
        return array_slice(self::vendorStrength($this->decisions()), 0, max(1, $limit));
    }

    /** Her real decisions, newest first (only receipts that were really approved). */
    private function decisions(): array
    {
        $rows = $this->db->query("
            SELECT s.status, s.outcome_json, COALESCE(v.name, e.vendor_name_raw) AS vendor, e.accounting_category
            FROM expense_suggestions s
            JOIN expenses e ON e.id = s.expense_id
            LEFT JOIN vendors v ON v.id = e.vendor_id
            WHERE s.source = 'live' AND s.status IN ('accepted', 'edited') AND e.status IN ('approved', 'forwarded')
            ORDER BY s.decided_at DESC, s.id DESC
            LIMIT 500
        ")->fetchAll(PDO::FETCH_ASSOC);

        $decisions = [];
        foreach ($rows as $r) {
            $decisions[] = [
                'all_ok'   => $r['status'] === 'accepted',
                'vendor'   => (string)$r['vendor'],
                'category' => (string)$r['accounting_category'],
                'outcome'  => json_decode((string)$r['outcome_json'], true) ?: [],
            ];
        }
        return $decisions;
    }

    private function foundToBill(): float
    {
        try {
            if ($this->db->query("SHOW TABLES LIKE 'penny_questions'")->rowCount() === 0) return 0.0;
            return round((float)$this->db->query("SELECT COALESCE(SUM(amount), 0) FROM penny_questions WHERE answer = 'invoice'")->fetchColumn(), 2);
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Per vendor, from her decisions (newest first): receipts seen, how many you approved
     * unchanged, her current run of unchanged ones (toward VENDOR_RUN = trusted). Vendors
     * she has seen most come first.
     * @return array<int, array{vendor: string, seen: int, right: int, pct: int, run: int, need: int, trusted: bool}>
     */
    public static function vendorStrength(array $decisions): array
    {
        $by = [];
        foreach ($decisions as $d) {
            $v = trim((string)$d['vendor']);
            if ($v === '') continue;
            $k = strtolower($v);
            $by[$k] ??= ['vendor' => $v, 'seen' => 0, 'right' => 0, 'run' => 0, 'broken' => false];
            $by[$k]['seen']++;
            if ($d['all_ok']) $by[$k]['right']++;
            if (!$by[$k]['broken']) {
                if ($d['all_ok']) $by[$k]['run']++; else $by[$k]['broken'] = true;
            }
        }
        $out = [];
        foreach ($by as $v) {
            $out[] = ['vendor' => $v['vendor'], 'seen' => $v['seen'], 'right' => $v['right'],
                      'pct' => (int)round($v['right'] / $v['seen'] * 100), 'run' => min(self::VENDOR_RUN, $v['run']),
                      'need' => self::VENDOR_RUN, 'trusted' => $v['run'] >= self::VENDOR_RUN];
        }
        usort($out, fn($a, $b) => [$b['seen'], $b['run']] <=> [$a['seen'], $a['run']]);
        return $out;
    }

    /**
     * @param array $decisions newest first: [all_ok, vendor, category, outcome[field => [accepted, suggested, final]]]
     * @return array{earned: array, next: ?array}  badge: [key, icon, label, title, have, need]
     */
    public static function compute(array $decisions, float $foundToBill = 0.0): array
    {
        $kept = static fn(array $d, string $f): bool => !isset($d['outcome'][$f]) || !empty($d['outcome'][$f]['accepted']);
        $run = static function (array $list, callable $ok): int {
            $n = 0;
            foreach ($list as $d) {
                if (!$ok($d)) break;
                $n++;
            }
            return $n;
        };
        $badges = [];

        // Vendor trust: per vendor, newest first.
        $byVendor = [];
        foreach ($decisions as $d) {
            if ($d['vendor'] !== '') $byVendor[$d['vendor']][] = $d;
        }
        $vendorBest = null;
        $vendorEarned = 0;
        foreach ($byVendor as $name => $list) {
            $n = min(self::VENDOR_RUN, $run($list, fn($d) => $d['all_ok']));
            $b = ['key' => 'vendor:' . $name, 'icon' => '🏬', 'label' => $name,
                  'title' => "Trusted with {$name}: her last " . self::VENDOR_RUN . " receipts from them approved unchanged",
                  'have' => $n, 'need' => self::VENDOR_RUN];
            if ($n >= self::VENDOR_RUN) {
                if ($vendorEarned++ < self::MAX_VENDOR_BADGES) $badges[] = $b;
            } elseif ($vendorBest === null || $n > $vendorBest['have']) {
                $b['title'] = "Trust with {$name}: {$n} of " . self::VENDOR_RUN . ' approved unchanged in a row';
                $vendorBest = $b;
            }
        }

        $fuel = array_values(array_filter($decisions, fn($d) => $d['category'] === 'Fuel'));
        $badges[] = ['key' => 'fuel', 'icon' => '⛽', 'label' => 'Fuel pro',
            'title' => 'Diesel → truck, gas → equipment: her last ' . self::FUEL_RUN . ' fuel receipts kept as she called them',
            'have' => min(self::FUEL_RUN, $run($fuel, fn($d) => $kept($d, 'accounting_category') && $kept($d, 'asset_tag'))), 'need' => self::FUEL_RUN];

        $jobs = count(array_filter($decisions, fn($d) => !empty($d['outcome']['job']['suggested']) && !empty($d['outcome']['job']['accepted'])));
        $badges[] = ['key' => 'jobs', 'icon' => '📍', 'label' => 'Job finder',
            'title' => 'Matched receipts to the right job ' . self::JOBS_KEPT . ' times (from where the truck went)',
            'have' => min(self::JOBS_KEPT, $jobs), 'need' => self::JOBS_KEPT];

        $badges[] = ['key' => 'tax', 'icon' => '🧾', 'label' => 'Tax ace',
            'title' => 'Total, GST and PST right on her last ' . self::TAX_RUN . ' receipts',
            'have' => min(self::TAX_RUN, $run($decisions, fn($d) => $kept($d, 'total') && $kept($d, 'gst') && $kept($d, 'pst'))), 'need' => self::TAX_RUN];

        $badges[] = ['key' => 'streak', 'icon' => '🔥', 'label' => self::STREAK . ' in a row',
            'title' => self::STREAK . ' receipts in a row approved without a change',
            'have' => min(self::STREAK, $run($decisions, fn($d) => $d['all_ok'])), 'need' => self::STREAK];

        if ($foundToBill > 0) {
            $badges[] = ['key' => 'found', 'icon' => '💰', 'label' => '$' . number_format($foundToBill, 0) . ' found to bill',
                'title' => 'Materials you had not invoiced, caught by her questions', 'have' => 1, 'need' => 1];
        }
        if ($vendorBest) $badges[] = $vendorBest;

        $earned = array_values(array_filter($badges, fn($b) => $b['have'] >= $b['need']));
        $open = array_values(array_filter($badges, fn($b) => $b['have'] < $b['need']));
        usort($open, fn($a, $b) => ($b['have'] / $b['need']) <=> ($a['have'] / $a['need']));
        return ['earned' => $earned, 'next' => $open[0] ?? null];
    }
}
