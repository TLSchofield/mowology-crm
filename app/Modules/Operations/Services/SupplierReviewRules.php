<?php
declare(strict_types=1);

/**
 * SupplierReviewRules — the quarterly supplier review (operations plugin, vendor-review: total cost,
 * risk / concentration, performance; verdict Proceed / Negotiate / Pass), 2026-11-03. The plugin has no
 * scale, so the numbers are ours: 90 days of receipt spend by vendor and category, CONCENTRATION share,
 * STALE_LIST_DAYS for a supplier's newest price list. Fuel and dump fees are shown but never "Negotiate"
 * (no alternative). Verdict words are Otto's suggestion only. Pure and static.
 */
final class SupplierReviewRules
{
    public const WINDOW_DAYS = 90;
    public const CONCENTRATION = 0.60;
    public const STALE_LIST_DAYS = 90;
    public const TOP = 5;
    public const NO_ALTERNATIVE = ['fuel', 'dump', 'disposal', 'landfill', 'utilities', 'insurance', 'tax', 'payroll', 'bank'];

    public static function quarterKey(string $today): string
    {
        return substr($today, 0, 4) . 'Q' . (int)ceil((int)substr($today, 5, 2) / 3);
    }

    /** Is this category one with no realistic alternative supplier? */
    public static function fixedCategory(string $category): bool
    {
        $c = strtolower($category);
        foreach (self::NO_ALTERNATIVE as $w) if (strpos($c, $w) !== false) return true;
        return false;
    }

    /**
     * @param array $spend rows {vendor, category, spend, n} for the window
     * @param array $alternatives category => number of vendors in the directory for that category
     * @param array $lists vendor => newest price-list date (Y-m-d)
     * @return array{top: array, concentration: array, stale: array, total: float}
     */
    public static function build(array $spend, array $alternatives, array $lists, string $today): array
    {
        $byVendor = []; $byCat = []; $vendorCat = [];
        $total = 0.0;
        foreach ($spend as $r) {
            $v = trim((string)($r['vendor'] ?? '')) ?: 'unknown';
            $c = trim((string)($r['category'] ?? '')) ?: 'uncategorised';
            $s = (float)($r['spend'] ?? 0);
            $total += $s;
            $byVendor[$v] = ($byVendor[$v] ?? 0) + $s;
            $byCat[$c] = ($byCat[$c] ?? 0) + $s;
            $vendorCat[$c][$v] = ($vendorCat[$c][$v] ?? 0) + $s;
        }
        arsort($byVendor);
        $top = [];
        foreach (array_slice($byVendor, 0, self::TOP, true) as $v => $s) {
            $top[] = ['vendor' => $v, 'spend' => round($s, 2), 'share' => $total > 0 ? round($s / $total, 2) : null];
        }
        $conc = [];
        foreach ($vendorCat as $c => $vs) {
            if (self::fixedCategory($c) || ($byCat[$c] ?? 0) <= 0) continue;
            arsort($vs);
            $v = array_key_first($vs);
            $share = $vs[$v] / $byCat[$c];
            if ($share > self::CONCENTRATION && (int)($alternatives[$c] ?? 0) >= 2) {
                $conc[] = ['category' => $c, 'vendor' => $v, 'share' => round($share, 2), 'spend' => round($vs[$v], 2), 'alternatives' => (int)$alternatives[$c] - 1, 'verdict' => 'Negotiate'];
            }
        }
        $stale = [];
        foreach ($lists as $v => $d) {
            if ($d === null || $d === '') continue;
            $age = (int)floor((strtotime($today) - strtotime((string)$d)) / 86400);
            if ($age > self::STALE_LIST_DAYS) $stale[] = ['vendor' => (string)$v, 'days' => $age];
        }
        $fixed = 0.0;
        foreach ($byCat as $c => $v) if (self::fixedCategory((string)$c)) $fixed += $v;
        return ['top' => $top, 'concentration' => $conc, 'stale' => $stale, 'total' => round($total, 2), 'fixed_share' => $total > 0 ? round($fixed / $total, 2) : null];
    }

    /** One brief item per quarter; null when there is nothing to review (no spend). */
    public static function briefItem(array $r, string $today): ?array
    {
        if ($r['total'] <= 0) return null;
        $parts = [];
        foreach ($r['top'] as $t) $parts[] = $t['vendor'] . ' $' . number_format($t['spend'], 0) . ($t['share'] !== null ? ' (' . (int)round($t['share'] * 100) . '%)' : '');
        $text = 'Quarterly supplier review — last ' . self::WINDOW_DAYS . ' days $' . number_format($r['total'], 0) . ' on receipts: ' . implode(', ', $parts) . '.';
        foreach ($r['concentration'] as $c) {
            $text .= ' ' . $c['vendor'] . ' has ' . (int)round($c['share'] * 100) . '% of ' . $c['category'] . ' with ' . $c['alternatives'] . ' other supplier' . ($c['alternatives'] === 1 ? '' : 's') . ' in the directory — Negotiate.';
        }
        foreach ($r['stale'] as $s) $text .= ' ' . $s['vendor'] . "'s newest price list is {$s['days']} days old.";
        if (!$r['concentration'] && !$r['stale']) {
            $fixed = $r['fixed_share'] ?? null;
            $text .= ' No supplier holds over ' . (int)round(self::CONCENTRATION * 100) . '% of a category that has an alternative in the directory'
                   . ($fixed !== null && $fixed >= 0.5 ? ', and ' . (int)round($fixed * 100) . '% of the spend is fuel, dump fees and the like with no alternative' : '') . ' — Proceed.';
        }
        return [
            'key'      => 'otto:supplier-review:' . self::quarterKey($today),
            'kind'     => 'supplier_review',
            'text'     => $text,
            'url'      => '/crm/expenses_appstack.php',
            'priority' => $r['concentration'] ? 2 : 3,
            'value'    => $r['total'],
            'since'    => $today,
        ];
    }
}
