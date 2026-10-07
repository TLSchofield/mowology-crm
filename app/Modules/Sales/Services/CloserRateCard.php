<?php
/**
 * CloserRateCard — the numbers the Closer prices with, read from the CRM's own settings.
 * READ-ONLY on purpose: the Closer never changes a rate, he reports drift. Tim edits these
 * (CloserSettingsService, admin only, from the rate-card form).
 *
 *   hourly cost    ops_settings.closer_hourly_cost — what one person-hour COSTS. Seeded by
 *                  migration 1141 from the closest cost_factors labour row (burdened rate),
 *                  because no cost factor is a fully loaded cost/hour. suggestedLoaded() shows
 *                  labour + kit + overhead per billable hour beside it for Tim to adopt.
 *   target margin  overhead_settings.profit_margin (the existing 35%) — not duplicated.
 *   margin floor   ops_settings.closer_margin_floor_pct (seeded target − 10 → 25%).
 *   minimum visit  max(ops_settings.closer_min_visit, the product's own min_price).
 *   truck          closer_truck_kmh (40), closer_detour_factor (1.4) — the Might-E.
 *   season         closer_season_plan JSON: visits per season + how often seasonal work happens.
 *   depot          closer_depot_lat/lng (a yard Tim sets, or geocoded from business_settings).
 */
class CloserRateCard
{
    public const KEYS = [
        'closer_hourly_cost', 'closer_margin_floor_pct', 'closer_min_visit', 'closer_truck_kmh',
        'closer_detour_factor', 'closer_calibration_min', 'closer_season_plan', 'closer_depot_lat', 'closer_depot_lng',
    ];
    public const DEFAULT_TARGET_MARGIN_PCT = 35.0;
    public const FLOOR_BELOW_TARGET = 10.0;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** The resolved rate card, with where every number came from. */
    public function load(): array
    {
        return self::resolve($this->ops(), $this->overhead(), $this->costFactors(), $this->overheadItems());
    }

    /**
     * Pure: build the card from raw rows.
     *
     * @param array $ops          setting_key => ['value' => string|null, 'description' => string|null]
     * @param array $overhead     overhead_settings key => value
     * @param array $costFactors  active cost_factors rows
     * @param array $overheadItems active overhead_items rows (amount, frequency)
     */
    public static function resolve(array $ops, array $overhead, array $costFactors, array $overheadItems): array
    {
        $num = function (string $k) use ($ops): ?float {
            $v = $ops[$k]['value'] ?? null;
            return ($v === null || $v === '' || !is_numeric($v)) ? null : (float)$v;
        };
        $targetPct = isset($overhead['profit_margin']) && is_numeric($overhead['profit_margin'])
            ? (float)$overhead['profit_margin'] : self::DEFAULT_TARGET_MARGIN_PCT;
        $floorPct = $num('closer_margin_floor_pct') ?? max(0.0, $targetPct - self::FLOOR_BELOW_TARGET);
        $hourly = $num('closer_hourly_cost');
        $season = json_decode((string)($ops['closer_season_plan']['value'] ?? ''), true);
        $season = is_array($season) ? array_merge(CloserPricing::DEFAULT_SEASON, $season) : CloserPricing::DEFAULT_SEASON;
        $lat = $num('closer_depot_lat');
        $lng = $num('closer_depot_lng');
        $flags = [];
        if ($hourly === null) $flags[] = 'Hourly cost is not set';
        $hourlySource = (string)($ops['closer_hourly_cost']['description'] ?? '');
        if ($hourly !== null && stripos($hourlySource, 'seeded') !== false) {
            $flags[] = 'Hourly cost is still the seed from one labour cost factor — it leaves out equipment and overhead';
        }
        return [
            'hourly_cost'      => $hourly,
            'hourly_source'    => $hourlySource,
            'suggested_loaded' => self::suggestedLoaded($costFactors, $overheadItems, $overhead),
            'target_margin'    => $targetPct / 100,
            'target_margin_pct'=> $targetPct,
            'margin_floor'     => $floorPct / 100,
            'margin_floor_pct' => $floorPct,
            'min_visit'        => $num('closer_min_visit') ?? 0.0,
            'kmh'              => $num('closer_truck_kmh') ?? DriveMinutesAdded::DEFAULT_KMH,
            'detour'           => $num('closer_detour_factor') ?? DriveMinutesAdded::DEFAULT_DETOUR,
            'calibration_min'  => (int)($num('closer_calibration_min') ?? SiteMinutesModel::CALIBRATION_MIN),
            'season'           => $season,
            'depot'            => ($lat !== null && $lng !== null && ($lat != 0 || $lng != 0)) ? ['lat' => $lat, 'lng' => $lng] : null,
            'depot_source'     => (string)($ops['closer_depot_lat']['description'] ?? ''),
            'flags'            => $flags,
        ];
    }

    /**
     * What a fully loaded person-hour would cost from the CRM's own numbers: the labour factor
     * the seed used + per-hour mowing kit and truck + monthly overhead over billable hours.
     * Shown to Tim as a suggestion, never applied.
     */
    public static function suggestedLoaded(array $costFactors, array $overheadItems, array $overhead): array
    {
        $labour = self::closestLabourFactor($costFactors);
        $kit = 0.0;
        $kitNames = [];
        foreach ($costFactors as $f) {
            if (($f['factor_type'] ?? '') !== 'equipment') continue;
            if (stripos((string)($f['unit'] ?? ''), 'hour') === false) continue;
            $name = (string)($f['factor_name'] ?? '');
            if (!preg_match('/walk|trimmer|blower|truck|might|ev\b/i', $name) || preg_match('/dump|riding/i', $name)) continue;
            $kit += (float)($f['rate'] ?? 0);
            $kitNames[] = $name;
        }
        $monthly = 0.0;
        foreach ($overheadItems as $i) {
            $amt = (float)($i['amount'] ?? 0);
            switch (strtolower((string)($i['frequency'] ?? 'monthly'))) {
                case 'weekly':    $monthly += $amt * 52 / 12; break;
                case 'quarterly': $monthly += $amt / 3; break;
                case 'annual': case 'annually': case 'yearly': $monthly += $amt / 12; break;
                default:          $monthly += $amt;
            }
        }
        $hours = (float)($overhead['estimated_billable_hours'] ?? 160);
        $ohHour = $hours > 0 ? $monthly / $hours : 0.0;
        $labourRate = $labour ? (float)(($labour['rate_with_burden'] ?? null) ?: ($labour['rate'] ?? 0)) : 0.0;
        return [
            'total'        => round($labourRate + $kit + $ohHour, 2),
            'labour'       => round($labourRate, 2),
            'labour_name'  => $labour['factor_name'] ?? null,
            'kit'          => round($kit, 2),
            'kit_names'    => $kitNames,
            'overhead'     => round($ohHour, 2),
        ];
    }

    /** The labour factor the seed uses: Owner/Manager if present, else the highest burdened labour rate. */
    public static function closestLabourFactor(array $costFactors): ?array
    {
        $best = null;
        foreach ($costFactors as $f) {
            if (($f['factor_type'] ?? '') !== 'labor') continue;
            if (stripos((string)($f['unit'] ?? 'hour'), 'hour') === false) continue;
            $rate = (float)(($f['rate_with_burden'] ?? null) ?: ($f['rate'] ?? 0));
            $owner = stripos((string)($f['factor_name'] ?? ''), 'owner') !== false;
            $score = ($owner ? 1e6 : 0) + $rate;
            if ($best === null || $score > $best[0]) $best = [$score, $f];
        }
        return $best[1] ?? null;
    }

    private function ops(): array
    {
        $out = [];
        try {
            $in = implode(',', array_fill(0, count(self::KEYS), '?'));
            $st = $this->db->prepare("SELECT setting_key, setting_value, description FROM ops_settings WHERE setting_key IN ({$in})");
            $st->execute(self::KEYS);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$r['setting_key']] = ['value' => $r['setting_value'], 'description' => $r['description'] ?? ''];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    private function overhead(): array
    {
        try {
            return $this->db->query("SELECT setting_key, setting_value FROM overhead_settings")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private function costFactors(): array
    {
        try {
            return $this->db->query("SELECT * FROM cost_factors WHERE active = 1")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private function overheadItems(): array
    {
        try {
            return $this->db->query("SELECT amount, frequency FROM overhead_items WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
