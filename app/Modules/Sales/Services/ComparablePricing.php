<?php
declare(strict_types=1);

/**
 * ComparablePricing — a price from the jobs most like this one, with the arithmetic shown.
 * Harvested from Anthropic's small-business plugin (proposal-builder pricing method, 2026-11-03):
 *   take the 2–3 accepted quotes closest in size for the same service (or the same service and site class), show each one,
 *   price per unit = median of their unit prices, suggested = unit price × this job's size; say which jobs it came from;
 *   flag when the suggestion is more than RATE_CARD_TOLERANCE off the rate card's figure (either way), never choose for the owner;
 *   never invent a rate, quantity or lead time — with fewer than 2 comparables say so and fall back to the rate card alone.
 * Pure and static.
 */
final class ComparablePricing
{
    public const MAX_COMPARABLES = 3;
    public const RATE_CARD_TOLERANCE = 0.15;

    /**
     * @param array $accepted rows {quote_number, who, accepted_at, size (float, same unit as $size), amount (float, before tax)}
     * @param float $size this job's size in the service's unit (minutes, m², visits…) — 0 when unknown
     * @param ?float $rateCard the rate card's price for this job, when there is one
     * @return array{suggested:?float, unit_price:?float, comparables:array, arithmetic:string, flag:?string, basis:string}
     */
    public static function suggest(array $accepted, float $size, ?float $rateCard, string $unit = 'unit'): array
    {
        $rows = array_values(array_filter($accepted, static fn($r) => (float)($r['size'] ?? 0) > 0 && (float)($r['amount'] ?? 0) > 0));
        if ($size <= 0 || count($rows) < 2) {
            $why = $size <= 0 ? 'this job\'s size is not known yet' : 'fewer than 2 accepted jobs like it';
            return ['suggested' => $rateCard, 'unit_price' => null, 'comparables' => [], 'basis' => 'rate_card',
                    'arithmetic' => 'No comparables used (' . $why . ')' . ($rateCard !== null ? '; the rate card says $' . number_format($rateCard, 2) : '; no rate card figure either — nothing is invented'), 'flag' => null];
        }
        usort($rows, static fn($a, $b) => (abs((float)$a['size'] - $size) <=> abs((float)$b['size'] - $size)) ?: strcmp((string)$b['accepted_at'], (string)$a['accepted_at']));   // nearest in size, then newest
        $use = array_slice($rows, 0, self::MAX_COMPARABLES);
        $units = array_map(static fn($r) => (float)$r['amount'] / (float)$r['size'], $use);
        sort($units);
        $n = count($units);
        $unit_price = $n % 2 ? $units[intdiv($n, 2)] : ($units[$n / 2 - 1] + $units[$n / 2]) / 2;
        $suggested = round($unit_price * $size, 2);
        $parts = array_map(static fn($r) => $r['quote_number'] . ' (' . ($r['who'] ?? 'client') . ', ' . date('M Y', strtotime((string)$r['accepted_at'])) . '): $' . number_format((float)$r['amount'], 0) . ' for ' . rtrim(rtrim(number_format((float)$r['size'], 1), '0'), '.') . " $unit = $" . number_format((float)$r['amount'] / (float)$r['size'], 2) . "/$unit", $use);
        $arith = implode('; ', $parts) . '. Median $' . number_format($unit_price, 2) . "/$unit × " . rtrim(rtrim(number_format($size, 1), '0'), '.') . " $unit = $" . number_format($suggested, 2) . '.';
        $flag = null;
        if ($rateCard !== null && $rateCard > 0 && abs($suggested - $rateCard) / $rateCard > self::RATE_CARD_TOLERANCE) {
            $pct = round(($suggested - $rateCard) / $rateCard * 100);
            $flag = 'Comparables land ' . ($pct > 0 ? '+' : '') . $pct . '% off the rate card ($' . number_format($rateCard, 2) . ') — the card or the jobs are out of date; your call.';
        }
        return ['suggested' => $suggested, 'unit_price' => round($unit_price, 4), 'comparables' => $use, 'basis' => 'comparables', 'arithmetic' => $arith, 'flag' => $flag];
    }
}
