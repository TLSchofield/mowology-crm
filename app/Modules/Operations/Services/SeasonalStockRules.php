<?php
declare(strict_types=1);

/**
 * SeasonalStockRules — the dated seasonal watch list (small-business plugin, inventory-planner:
 * "the owner names the season; it is never inferred", decide-by = season start − lead time, "an undated
 * 'seasonal, check later' is how the first hot week gets missed"), 2026-11-03.
 *
 * Otto never counts stock. He sees receipts only, so the rule is about purchases:
 *   from decide-by:        nothing matching bought in LOOKBACK_DAYS → "Order X by <season start>"
 *   from season start:     still nothing → "season started, no X on any receipt — bought elsewhere? tell Penny"
 *   bought in time:        quiet.
 * The watch list lives in ops_settings otto_seasonal_stock (JSON); seeded with ice melt / salt when the
 * business runs snow routes. Pure and static.
 */
final class SeasonalStockRules
{
    public const LOOKBACK_DAYS = 120;
    public const DEFAULT_LEAD_DAYS = 14;

    /** The seed when snow routes exist. */
    public static function seed(): array
    {
        return [[
            'item'         => 'ice melt / salt',
            'pattern'      => 'salt|ice ?melt|de-?icer|icemelt',
            'season_start' => '11-01',
            'lead_days'    => self::DEFAULT_LEAD_DAYS,
            'needs'        => 'snow_routes',
        ]];
    }

    /** The season start date on or after (today − 60 days): this season's, not next year's, from Nov to Jan. */
    public static function seasonStart(string $mmdd, string $today): string
    {
        $y = (int)substr($today, 0, 4);
        $thisYear = sprintf('%d-%s', $y, $mmdd);
        // If this year's start is more than 60 days behind us, the watch is for next year's season.
        if (strtotime($thisYear) < strtotime($today . ' -60 days')) return sprintf('%d-%s', $y + 1, $mmdd);
        return $thisYear;
    }

    public static function decideBy(string $seasonStart, int $leadDays): string
    {
        return date('Y-m-d', strtotime($seasonStart . ' -' . max(0, $leadDays) . ' days'));
    }

    /**
     * @param array  $watch      items {item, pattern, season_start, lead_days, needs}
     * @param array  $lastBought item index => last matching receipt date (Y-m-d) within LOOKBACK_DAYS, or null
     * @param array  $needsMet   needs key => bool (e.g. snow_routes => true, with a count in $needsCount)
     */
    public static function items(array $watch, array $lastBought, array $needsMet, array $needsCount, string $today): array
    {
        $out = [];
        foreach ($watch as $i => $w) {
            $needs = (string)($w['needs'] ?? '');
            if ($needs !== '' && empty($needsMet[$needs])) continue;
            $start = self::seasonStart((string)$w['season_start'], $today);
            $decide = self::decideBy($start, (int)($w['lead_days'] ?? self::DEFAULT_LEAD_DAYS));
            if ($today < $decide) continue;
            $bought = $lastBought[$i] ?? null;
            if ($bought !== null && $bought >= date('Y-m-d', strtotime($start . ' -' . self::LOOKBACK_DAYS . ' days'))) continue;
            $item = (string)$w['item'];
            $n = (int)($needsCount[$needs] ?? 0);
            $why = $needs === 'snow_routes' && $n > 0 ? " ({$n} snow routes)" : '';
            $started = $today >= $start;
            $text = $started
                ? 'Snow season started ' . date('M j', strtotime($start)) . " — no {$item} on any receipt" . ($bought ? ' since ' . date('M j', strtotime($bought)) : '') . "{$why}. Bought elsewhere? Give Penny the receipt, or order it."
                : "Order {$item} by " . date('M j', strtotime($start)) . " — season starts then, nothing on a receipt" . ($bought ? ' since ' . date('M j', strtotime($bought)) : ' in ' . self::LOOKBACK_DAYS . ' days') . "{$why}.";
            $out[] = [
                'key'      => 'otto:season-stock:' . preg_replace('/[^a-z0-9]+/', '-', strtolower($item)) . ':' . substr($start, 0, 4),
                'kind'     => 'season_stock',
                'text'     => $text . ' (Otto sees receipts only, never a stock count.)',
                'url'      => '/crm/expenses_appstack.php',
                'priority' => $started ? 1 : 2,
                'value'    => null,
                'since'    => $decide,
            ];
        }
        return $out;
    }
}
