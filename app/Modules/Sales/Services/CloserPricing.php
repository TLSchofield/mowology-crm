<?php
/**
 * CloserPricing — Sam the Closer's price for one visit. Pure: no database, no model.
 *
 *   visit price = max(minimum, ((site minutes + drive minutes added) × hourly cost
 *                               + materials + disposal) / (1 − target margin))
 *
 * "Hourly cost" is what one person-hour COSTS the business, never a charge-out rate:
 * dividing a rate that already holds profit by (1 − margin) would charge the margin twice.
 * Drive minutes are truck minutes, so they are multiplied by the crew size.
 *
 * The Closer only SHOWS this price beside the quote's own price. It never writes a price
 * and never changes a rate (CloserRateCard has no write path for the agent).
 *
 * No namespace / no autoloader in production: require_once and call statically.
 */
class CloserPricing
{
    /**
     * The services the Closer knows. unit = which lot measurement drives the minutes;
     * per_visit = done every mowing visit (else seasonal, spread over the season's visits);
     * disposal = carries a disposal charge when it happens.
     */
    public const SERVICES = [
        'mow'      => ['label' => 'Mow, trim, blow', 'unit' => 'lawn',  'per_visit' => true,  'disposal' => false],
        'edge'     => ['label' => 'Edging',          'unit' => 'edge',  'per_visit' => true,  'disposal' => false],
        'cleanup'  => ['label' => 'Seasonal cleanup', 'unit' => 'lawn', 'per_visit' => false, 'disposal' => true],
        'aeration' => ['label' => 'Aeration',        'unit' => 'lawn',  'per_visit' => false, 'disposal' => false],
        'overseed' => ['label' => 'Overseeding',     'unit' => 'lawn',  'per_visit' => false, 'disposal' => false],
        'hedge'    => ['label' => 'Hedge trimming',  'unit' => 'hedge', 'per_visit' => false, 'disposal' => true],
        'beds'     => ['label' => 'Bed work',        'unit' => 'lawn',  'per_visit' => false, 'disposal' => true],
    ];

    /** Tim's three tiers, as product_bundles.tier values (good / better / best). */
    public const TIERS = [
        'good'   => ['label' => 'Basic',     'default' => ['mow']],
        'better' => ['label' => 'Standard',  'default' => ['mow', 'edge', 'cleanup']],
        'best'   => ['label' => 'Full care', 'default' => ['mow', 'edge', 'cleanup', 'aeration', 'overseed', 'hedge', 'beds']],
    ];

    /** How often each seasonal service happens in a season, and visits per season. Tim's to change. */
    public const DEFAULT_SEASON = ['visits' => 28, 'cleanup' => 2, 'aeration' => 1, 'overseed' => 1, 'hedge' => 2, 'beds' => 4];

    /** Map free text (a line item's service, a plan's service_type, a product name) to a service key. */
    public static function serviceKey(string $text): ?string
    {
        $t = strtolower($text);
        if ($t === '') return null;
        $map = [
            'aeration' => ['aerat'],
            'overseed' => ['overseed', 'over-seed', 'seeding'],
            'hedge'    => ['hedge', 'shrub', 'prun'],
            'cleanup'  => ['clean-up', 'cleanup', 'clean up', 'leaf', 'leaves', 'spring', 'fall clean'],
            'beds'     => ['bed', 'weed', 'mulch', 'garden'],
            'edge'     => ['edging', 'edge'],
            'mow'      => ['mow', 'lawn', 'grass', 'cut', 'maintenance'],
        ];
        foreach ($map as $key => $needles) {
            foreach ($needles as $n) {
                if (strpos($t, $n) !== false) return $key;
            }
        }
        return null;
    }

    /**
     * Price one visit.
     *
     * @param array $in   site_minutes (person-minutes), drive_minutes (truck minutes), crew (≥1),
     *                    materials ($), disposal ($), minimum ($)
     * @param array $card hourly_cost ($/person-hour), target_margin (0–1), margin_floor (0–1)
     */
    public static function price(array $in, array $card): array
    {
        $hourly = (float)($card['hourly_cost'] ?? 0);
        $margin = (float)($card['target_margin'] ?? 0);
        if ($hourly <= 0) {
            throw new InvalidArgumentException('Hourly cost is not set');
        }
        if ($margin < 0 || $margin >= 0.95) {
            throw new InvalidArgumentException('Target margin must be between 0% and 95%');
        }
        $crew     = max(1, (int)($in['crew'] ?? 1));
        $site     = max(0.0, (float)($in['site_minutes'] ?? 0));
        $drive    = max(0.0, (float)($in['drive_minutes'] ?? 0));
        $labourMn = $site + $drive * $crew;
        $parts = [
            'site'      => round($site / 60 * $hourly, 2),
            'drive'     => round($drive * $crew / 60 * $hourly, 2),
            'materials' => round(max(0.0, (float)($in['materials'] ?? 0)), 2),
            'disposal'  => round(max(0.0, (float)($in['disposal'] ?? 0)), 2),
        ];
        $cost    = round(array_sum($parts), 2);
        $raw     = $cost / (1 - $margin);
        $minimum = max(0.0, (float)($in['minimum'] ?? 0));
        $minApplied = $minimum > $raw;
        $price   = (float)ceil(round(max($minimum, $raw), 2));
        $floor   = isset($card['margin_floor']) ? (float)$card['margin_floor'] : null;
        $mPct    = self::marginAt($price, $cost);
        return [
            'price'           => $price,
            'cost'            => $cost,
            'margin_pct'      => $mPct,
            'minimum_applied' => $minApplied,
            'labour_minutes'  => round($labourMn, 1),
            'parts'           => $parts,
            'below_floor'     => $floor !== null && $mPct !== null && $mPct < $floor * 100,
        ];
    }

    /** Margin (%) earned at a price, given the cost. Null when there is no price. */
    public static function marginAt(float $price, float $cost): ?float
    {
        if ($price <= 0) return null;
        return round(($price - $cost) / $price * 100, 1);
    }

    /** True when charging $price for something that costs $cost earns less than the floor (0–1). */
    public static function belowFloor(float $price, float $cost, float $floor): bool
    {
        $m = self::marginAt($price, $cost);
        return $m === null || $m < $floor * 100;
    }

    /**
     * Fold a tier's services into ONE visit: per-visit services count in full, seasonal ones
     * are spread over the season's visits (a fall cleanup that happens twice in 28 visits adds
     * 2/28 of itself to each visit).
     *
     * @param array $services  service key => ['minutes' => float|null, 'materials' => $, 'disposal' => $]
     * @param array $season    DEFAULT_SEASON shape
     * @return array ['site_minutes', 'materials', 'disposal', 'missing' => [keys with no minutes]]
     */
    public static function foldVisit(array $services, array $season): array
    {
        $visits = max(1, (int)($season['visits'] ?? self::DEFAULT_SEASON['visits']));
        $out = ['site_minutes' => 0.0, 'materials' => 0.0, 'disposal' => 0.0, 'missing' => []];
        foreach ($services as $key => $s) {
            if (!isset(self::SERVICES[$key])) continue;
            if (!isset($s['minutes']) || $s['minutes'] === null) {
                $out['missing'][] = $key;
                continue;
            }
            $share = self::SERVICES[$key]['per_visit']
                ? 1.0
                : max(0.0, (float)($season[$key] ?? self::DEFAULT_SEASON[$key] ?? 0)) / $visits;
            $out['site_minutes'] += (float)$s['minutes'] * $share;
            $out['materials']    += (float)($s['materials'] ?? 0) * $share;
            $out['disposal']     += (float)($s['disposal'] ?? 0) * $share;
        }
        $out['site_minutes'] = round($out['site_minutes'], 1);
        $out['materials']    = round($out['materials'], 2);
        $out['disposal']     = round($out['disposal'], 2);
        return $out;
    }

    /**
     * The three tiers for one lot, each priced as one visit.
     *
     * @param array $tierServices tier => [service keys]  (from product_bundles, else TIERS defaults)
     * @param array $perService   service key => ['minutes', 'materials', 'disposal']
     * @param array $visit        drive_minutes, crew, minimum
     */
    public static function tiers(array $tierServices, array $perService, array $visit, array $season, array $card): array
    {
        $out = [];
        foreach (self::TIERS as $tier => $def) {
            $keys = $tierServices[$tier] ?? $def['default'];
            $pick = [];
            foreach ($keys as $k) {
                $pick[$k] = $perService[$k] ?? ['minutes' => null];
            }
            $fold = self::foldVisit($pick, $season);
            $row = ['tier' => $tier, 'label' => $def['label'], 'services' => array_values($keys), 'missing' => $fold['missing']];
            if (!isset($pick['mow']) || in_array('mow', $fold['missing'], true)) {
                $row['price'] = null; // no visit to hang the tier on
            } else {
                $row += self::price([
                    'site_minutes'  => $fold['site_minutes'],
                    'drive_minutes' => $visit['drive_minutes'] ?? 0,
                    'crew'          => $visit['crew'] ?? 1,
                    'materials'     => $fold['materials'],
                    'disposal'      => $fold['disposal'],
                    'minimum'       => $visit['minimum'] ?? 0,
                ], $card);
            }
            $out[] = $row;
        }
        return $out;
    }
}
