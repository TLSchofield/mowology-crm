<?php
declare(strict_types=1);

/**
 * OverheadSuggestionService — Penny's suggestions for the Cost Factors overhead page.
 *
 * Penny only SUGGESTS. Nothing here writes to overhead_items / overhead_settings: the page's Accept
 * button calls the existing save-overhead-item / save-overhead actions (products.edit, CSRF), so there
 * is one write path and an owner click before any quote price moves.
 *
 * Truth sources (same ones the Owner Freedom dashboard trusts):
 *   Overhead spend : accounting_transactions on opex accounts (6xxx), through AccountingService::countedSql()
 *                    so a bank line and its matching receipt are never counted twice.
 *   Revenue        : invoices, before GST, draft/cancelled/void excluded.
 *   Jobs           : completed job_visits.
 *   Billable hours : job_time_entries (completed/edited) — job minutes, not shift minutes.
 * The averaging window is the months the ledger has actually existed (CRM cutover from Jobber was
 * 2026-02-25), never a flat 12, so a young ledger does not understate monthly spend.
 */
class OverheadSuggestionService
{
    /** Same multipliers as FREQ_MULTIPLIER in public/crm/products/cost-factors.php. */
    public const FREQ_PER_MONTH = ['weekly' => 4.333, 'monthly' => 1.0, 'quarterly' => 1 / 3, 'annual' => 1 / 12];

    /** Opex ledger account → overhead_items.category. An opex account not listed is reported as unclassified, never guessed. */
    public const ACCOUNT_CATEGORY = [
        '6100' => 'vehicle',     // Fuel & Vehicle
        '6110' => 'insurance',   // Vehicle Insurance
        '6120' => 'vehicle',     // Vehicle Maintenance
        '6200' => 'vehicle',     // Equipment Maintenance
        '6250' => 'variable',    // PPE & Safety Supplies (consumed with the work)
        '6300' => 'insurance',   // Business Insurance
        '6400' => 'admin',       // Marketing & Advertising
        '6500' => 'admin',       // Office & Administration (also licenses/permits)
        '6600' => 'admin',       // Professional Services
        '6700' => 'admin',       // Utilities & Phone
        '6800' => 'admin',       // Bank Charges & Fees
        '6850' => 'admin',       // Meals & Entertainment
        // 6900 Miscellaneous is deliberately NOT mapped: it has no obvious home, so it is reported as unclassified.
    ];

    /** Opex accounts that are never overhead for pricing (labour is costed from time entries; header is not an account). */
    private const SKIP_NAME_PATTERN = '/payroll|wage|salar|owner|draw|dividend|shareholder|personal/i';

    /** A suggestion is only worth the owner's click if it moves the figure by this much. */
    public const MIN_CHANGE_PCT = 10.0;
    public const MIN_CHANGE_DOLLARS = 5.0;

    /** A vendor must show up in this many separate months to count as recurring overhead. */
    public const MIN_MONTHS_ACTIVE = 3;

    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ═══ Pure helpers (unit-tested) ═══════════════════════════════════════════════════════════════════

    public static function toMonthly(float $amount, string $frequency): float
    {
        return $amount * (self::FREQ_PER_MONTH[$frequency] ?? 1.0);
    }

    /** Inverse of toMonthly(): the per-period amount that gives this monthly figure. */
    public static function fromMonthly(float $monthly, string $frequency): float
    {
        $mult = self::FREQ_PER_MONTH[$frequency] ?? 1.0;
        return $mult > 0 ? $monthly / $mult : $monthly;
    }

    /** Whole months between two dates, at least 1 and at most $cap. */
    public static function monthsObserved(string $firstDate, string $today, int $cap = 12): int
    {
        $a = new DateTime($firstDate);
        $b = new DateTime($today);
        if ($b < $a) return 1;
        $months = ((int)$b->format('Y') - (int)$a->format('Y')) * 12 + ((int)$b->format('n') - (int)$a->format('n')) + 1;
        return max(1, min($cap, $months));
    }

    /** overhead_items.category for an opex account, or null when it is not mapped (or is labour/owner pay). */
    public static function categoryForAccount(string $code, string $name): ?string
    {
        if (preg_match(self::SKIP_NAME_PATTERN, $name)) return null;
        return self::ACCOUNT_CATEGORY[$code] ?? null;
    }

    private static function norm(string $s): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $s));
    }

    /**
     * Every active overhead item whose name matches $name: one normalised name contains the other, or they share
     * a word of 4+ letters. Several hits means the spend is ambiguous; the caller must not pick one.
     */
    public static function itemHits(string $name, array $items): array
    {
        $v = trim(self::norm($name));
        if ($v === '') return [];
        $vWords = array_filter(explode(' ', $v), static function ($w) { return strlen($w) >= 4; });
        $hits = [];
        foreach ($items as $it) {
            if (empty($it['is_active'])) continue;
            $n = trim(self::norm((string)$it['item_name']));
            if ($n === '') continue;
            $hit = strpos($n, $v) !== false || strpos($v, $n) !== false;
            if (!$hit) {
                foreach (explode(' ', $n) as $w) {
                    if (strlen($w) >= 4 && in_array($w, $vWords, true)) { $hit = true; break; }
                }
            }
            if ($hit) $hits[] = $it;
        }
        return $hits;
    }

    /** True when $suggested differs from $current enough to be worth the owner's attention. */
    public static function worthSuggesting(float $current, float $suggested): bool
    {
        $diff = abs($suggested - $current);
        if ($diff < self::MIN_CHANGE_DOLLARS) return false;
        if ($current <= 0) return true;
        return ($diff / $current) * 100 >= self::MIN_CHANGE_PCT;
    }

    /** Items paid once a quarter or a year can only be checked against a full year of ledger. */
    private static function isPeriodic(array $item): bool
    {
        return in_array((string)($item['frequency'] ?? 'monthly'), ['quarterly', 'annual'], true);
    }

    /** Months a ledger row's vendor has been billing within the window (its own start, not the ledger's). */
    private static function spanMonths(array $row, int $monthsObserved): int
    {
        return max(1, min($monthsObserved, (int)($row['span_months'] ?? $monthsObserved)));
    }

    /**
     * Turn grouped ledger spend into item suggestions. Only an UNAMBIGUOUS match produces something to accept:
     * a ledger row (matched by vendor name, else by its account name) that lands on exactly one item. Everything
     * murkier is reported as a note for the owner (never a one-click change): spend that fits several items,
     * recurring vendors that are on no item, and spend posted with no vendor that fits nothing.
     *
     * @param array $ledger  rows: category, vendor, account_name, total, posts, months_active, span_months (optional)
     * @param array $items   overhead_items rows (id, category, item_name, amount, frequency, is_active)
     * @return array ['items', 'ambiguous', 'new_vendors', 'unitemised', 'unmatched_items']
     */
    public static function suggestItems(array $ledger, array $items, int $monthsObserved): array
    {
        $monthsObserved = max(1, $monthsObserved);
        $fullYear = $monthsObserved >= 12;
        $perItem = [];
        $supported = [];
        $ambiguous = [];
        $newVendors = [];
        $unitemised = [];

        foreach ($ledger as $r) {
            $pseudo    = strpos((string)$r['vendor'], '(no vendor)') === 0;
            $recurring = (int)$r['months_active'] >= self::MIN_MONTHS_ACTIVE;
            $monthly   = (float)$r['total'] / self::spanMonths($r, $monthsObserved);
            $hits = $pseudo ? [] : self::itemHits((string)$r['vendor'], $items);
            $viaAccount = !$hits;
            if (!$hits) $hits = self::itemHits((string)($r['account_name'] ?? ''), $items);
            if (count($hits) > 1) {   // several fit: if exactly one sits in the spend's own category, that is the one
                $same = array_values(array_filter($hits, static function ($h) use ($r) { return ($h['category'] ?? '') === $r['category']; }));
                if (count($same) === 1) $hits = $same;
            }

            if (count($hits) === 1 && (self::isPeriodic($hits[0]) || $recurring)) {
                $it = $hits[0];
                $id = (int)$it['id'];
                if (!isset($perItem[$id])) $perItem[$id] = ['item' => $it, 'total' => 0.0, 'monthly' => 0.0, 'posts' => 0, 'vendors' => []];
                $perItem[$id]['total']    += (float)$r['total'];
                $perItem[$id]['monthly']  += $monthly;
                $perItem[$id]['posts']    += (int)$r['posts'];
                $perItem[$id]['vendors'][] = (string)$r['vendor'];
                $supported[$id] = true;
            } elseif (count($hits) > 1) {
                foreach ($hits as $h) if ($recurring || self::isPeriodic($h)) $supported[(int)$h['id']] = true;
                if ($recurring) {
                    // Spend that is ambiguous because of its ACCOUNT is one finding per account, however many vendors paid into it.
                    $key = $viaAccount ? 'a:' . ($r['account_name'] ?? $r['vendor']) : 'v:' . $r['vendor'];
                    if (!isset($ambiguous[$key])) {
                        $ambiguous[$key] = ['label' => $viaAccount ? (string)($r['account_name'] ?? $r['vendor']) : (string)$r['vendor'],
                                            'monthly' => 0.0, 'vendors' => [],
                                            'candidates' => array_values(array_map(static function ($h) { return (string)$h['item_name']; }, $hits))];
                    }
                    $ambiguous[$key]['monthly'] = round($ambiguous[$key]['monthly'] + $monthly, 2);
                    if (!$pseudo && $viaAccount) $ambiguous[$key]['vendors'][] = (string)$r['vendor'];
                }
            } elseif (!$hits && $recurring) {
                if ($pseudo) {
                    $unitemised[] = ['label' => (string)($r['account_name'] ?? $r['vendor']), 'monthly' => round($monthly, 2)];
                } elseif ($monthly >= self::MIN_CHANGE_DOLLARS) {
                    $newVendors[] = ['vendor' => (string)$r['vendor'], 'category' => (string)$r['category'], 'monthly' => round($monthly, 2)];
                }
            }
        }

        $out = [];
        foreach ($perItem as $id => $g) {
            $item     = $g['item'];
            $freq     = (string)($item['frequency'] ?: 'monthly');
            $periodic = self::isPeriodic($item);
            if ($periodic && !$fullYear) continue;   // supported by a posting, but a partial year can't confirm the amount
            $currMonthly = self::toMonthly((float)$item['amount'], $freq);
            $sugMonthly  = round($periodic ? $g['total'] / $monthsObserved : $g['monthly'], 2);
            if (!self::worthSuggesting($currMonthly, $sugMonthly)) continue;
            $out[] = [
                'kind'              => 'update',
                'item_id'           => $id,
                'category'          => (string)$item['category'],
                'item_name'         => (string)$item['item_name'],
                'frequency'         => $freq,
                'current_amount'    => round((float)$item['amount'], 2),
                'current_monthly'   => round($currMonthly, 2),
                'suggested_monthly' => $sugMonthly,
                'suggested_amount'  => round(self::fromMonthly($sugMonthly, $freq), 2),
                'source'            => sprintf('%d posting%s from %s, $%s total in the last %d month%s of ledger',
                                          $g['posts'], $g['posts'] === 1 ? '' : 's', implode(', ', array_unique($g['vendors'])),
                                          number_format($g['total'], 2), $monthsObserved, $monthsObserved === 1 ? '' : 's'),
            ];
        }

        // Active items with no ledger spend behind them. A periodic item can't be judged on under a full year.
        $unmatched = [];
        foreach ($items as $it) {
            if (empty($it['is_active']) || isset($supported[(int)$it['id']])) continue;
            if (self::isPeriodic($it) && !$fullYear) continue;
            $unmatched[] = ['item_id' => (int)$it['id'], 'item_name' => (string)$it['item_name'], 'category' => (string)$it['category'],
                            'current_monthly' => round(self::toMonthly((float)$it['amount'], (string)($it['frequency'] ?: 'monthly')), 2)];
        }

        $byMonthly = static function ($x, $y) { return $y['monthly'] <=> $x['monthly']; };
        usort($newVendors, $byMonthly);
        $ambiguous = array_values($ambiguous);
        usort($ambiguous, $byMonthly);
        usort($unitemised, $byMonthly);
        return ['items' => $out, 'ambiguous' => $ambiguous, 'new_vendors' => $newVendors, 'unitemised' => $unitemised, 'unmatched_items' => $unmatched];
    }

    /**
     * Suggested monthly settings from observed totals.
     *
     * @param array $obs      revenue, completed_visits, job_minutes — totals across the window
     * @param array $current  overhead_settings key => value
     */
    public static function suggestSettings(array $obs, array $current, int $monthsObserved): array
    {
        $monthsObserved = max(1, $monthsObserved);
        $rows = [
            'estimated_monthly_revenue' => [round((float)$obs['revenue'] / $monthsObserved, 0),
                'invoiced revenue before GST, drafts and voids excluded'],
            'estimated_jobs_per_month'  => [round((float)$obs['completed_visits'] / $monthsObserved, 0),
                'completed visits'],
            'estimated_billable_hours'  => [round((float)$obs['job_minutes'] / 60 / $monthsObserved, 0),
                'time logged on jobs (crew + owner), not shift time'],
        ];
        $out = [];
        foreach ($rows as $key => [$suggested, $basis]) {
            if ($suggested <= 0) continue;   // no data is not a suggestion
            $cur = isset($current[$key]) ? (float)$current[$key] : 0.0;
            if (!self::worthSuggesting($cur, $suggested)) continue;
            $out[] = [
                'key' => $key, 'current' => $cur, 'suggested' => $suggested,
                'source' => sprintf('%s, averaged over %d month%s of data', $basis, $monthsObserved, $monthsObserved === 1 ? '' : 's'),
            ];
        }
        return $out;
    }

    // ═══ Loaders ══════════════════════════════════════════════════════════════════════════════════════

    /** Everything the Overhead tab needs to show "Penny suggests" chips. */
    public function suggestions(int $months = 12, ?string $today = null): array
    {
        $today = $today ?: date('Y-m-d');
        $first = $this->scalar("SELECT MIN(transaction_date) FROM accounting_transactions WHERE type = 'expense'");
        $observed = $first ? self::monthsObserved((string)$first, $today, $months) : 1;
        $from = date('Y-m-01', strtotime($today . ' -' . ($observed - 1) . ' months'));   // first day of the oldest observed month

        [$ledger, $unclassified] = $this->loadLedger($from, $today);
        $items = $this->loadItems();
        $items_result = self::suggestItems($ledger, $items, $observed);

        $current = [];
        try {
            foreach ($this->db->query("SELECT setting_key, setting_value FROM overhead_settings")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $current[$r['setting_key']] = $r['setting_value'];
            }
        } catch (Throwable $e) { /* page shows its own defaults */ }

        return [
            'window'          => ['from' => $from, 'to' => $today, 'months' => $observed],
            'items'           => $items_result['items'],
            'ambiguous'       => $items_result['ambiguous'],
            'new_vendors'     => $items_result['new_vendors'],
            'unitemised'      => $items_result['unitemised'],
            'unmatched_items' => $items_result['unmatched_items'],
            'unclassified'    => $unclassified,
            'settings'        => self::suggestSettings($this->loadObserved($from, $today), $current, $observed),
        ];
    }

    private function loadLedger(string $from, string $to): array
    {
        require_once __DIR__ . '/AccountingService.php';
        $st = $this->db->prepare("
            SELECT coa.code, coa.name AS account_name,
                   COALESCE(NULLIF(TRIM(v.name), ''), CONCAT('(no vendor) ', coa.name)) AS vendor,
                   SUM(t.amount) AS total, COUNT(*) AS posts, MIN(t.transaction_date) AS first_date,
                   COUNT(DISTINCT DATE_FORMAT(t.transaction_date, '%Y-%m')) AS months_active
            FROM accounting_transactions t
            JOIN chart_of_accounts coa ON coa.id = t.account_id
            LEFT JOIN vendors v ON v.id = t.vendor_id
            WHERE t.type = 'expense' AND coa.code LIKE '6%' AND coa.code <> '6000'
              AND t.transaction_date BETWEEN ? AND ?
              AND t.status IN ('cleared', 'reconciled')
              AND " . AccountingService::countedSql('t') . "
            GROUP BY coa.code, coa.name, vendor");
        $st->execute([$from, $to]);

        $ledger = [];
        $unclassified = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cat = self::categoryForAccount((string)$r['code'], (string)$r['account_name']);
            if ($cat === null) {
                if (!preg_match(self::SKIP_NAME_PATTERN, (string)$r['account_name'])) {
                    $key = $r['code'];
                    if (!isset($unclassified[$key])) $unclassified[$key] = ['code' => $r['code'], 'account' => $r['account_name'], 'total' => 0.0];
                    $unclassified[$key]['total'] += (float)$r['total'];
                }
                continue;
            }
            $r['category'] = $cat;
            $r['span_months'] = self::monthsObserved((string)$r['first_date'], $to, 12);   // from the vendor's own first posting
            $ledger[] = $r;
        }
        return [$ledger, array_values($unclassified)];
    }

    private function loadItems(): array
    {
        try {
            return $this->db->query("SELECT id, category, item_name, amount, frequency, is_active FROM overhead_items")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function loadObserved(string $from, string $to): array
    {
        return [
            'revenue'          => (float)$this->scalar("
                SELECT COALESCE(SUM(COALESCE(subtotal, total - COALESCE(tax_amount,0))),0) FROM invoices
                WHERE issue_date BETWEEN ? AND ? AND status NOT IN ('draft','cancelled','void')", [$from, $to]),
            'completed_visits' => (int)$this->scalar("
                SELECT COUNT(*) FROM job_visits WHERE status = 'completed' AND scheduled_date BETWEEN ? AND ?", [$from, $to]),
            'job_minutes'      => (float)$this->scalar("
                SELECT COALESCE(SUM(duration_minutes),0) FROM job_time_entries
                WHERE status IN ('completed','edited') AND DATE(start_time) BETWEEN ? AND ?", [$from, $to]),
        ];
    }

    private function scalar(string $sql, array $params = [])
    {
        try {
            $st = $this->db->prepare($sql);
            $st->execute($params);
            $v = $st->fetchColumn();
            return $v === false ? null : $v;
        } catch (Throwable $e) {
            return null;
        }
    }
}
