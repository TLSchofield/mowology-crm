<?php
/**
 * DispatchRules — the pure rules behind Otto the Dispatcher (unit tested).
 *
 * Bylaw hours, BC statutory holidays, the West End by postal code, the Might-E's daily
 * distance against its range, maintenance due from hours, fading battery packs.
 * Nothing here touches the database.
 *
 * No namespace / no autoloader in production: require_once, then call statically.
 */
class DispatchRules
{
    /** Starting straight-line → road distance factor, until odometer days teach a better one. */
    public const ROAD_FACTOR = 1.35;
    public const ROAD_FACTOR_MIN_DAYS = 5;
    /** Might-E: keep this share of the range in reserve unless the register says otherwise (Tim, 2026-10-05). */
    public const RESERVE_PCT = 20;
    /** A pack is fading when its recent runs are under this share of its runtime when new (Tim, 2026-10-05). */
    public const FADING_SHARE = 0.70;
    public const FADING_RUNS = 3;

    // ── Days ────────────────────────────────────────────────────────────────

    /**
     * BC statutory holidays for a year (Employment Standards Act list), as Y-m-d => name.
     * Used for the bylaws' "holiday" — separate from company_holidays (days we take off).
     */
    public static function bcHolidays(int $year): array
    {
        $nth = static function (int $n, int $weekday, int $month) use ($year): string {
            // $weekday: 1 = Monday … 7 = Sunday
            $d = new DateTime(sprintf('%04d-%02d-01', $year, $month));
            $shift = ($weekday - (int)$d->format('N') + 7) % 7;
            $d->modify('+' . ($shift + 7 * ($n - 1)) . ' days');
            return $d->format('Y-m-d');
        };
        $victoria = new DateTime("{$year}-05-24");
        while ($victoria->format('N') !== '1') $victoria->modify('-1 day');
        $easter = self::easterSunday($year);
        $goodFriday = date('Y-m-d', strtotime($easter . ' -2 days'));
        $h = [
            "{$year}-01-01" => "New Year's Day",
            $nth(3, 1, 2)   => 'Family Day',
            $goodFriday     => 'Good Friday',
            $victoria->format('Y-m-d') => 'Victoria Day',
            "{$year}-07-01" => 'Canada Day',
            $nth(1, 1, 8)   => 'British Columbia Day',
            $nth(1, 1, 9)   => 'Labour Day',
            "{$year}-09-30" => 'National Day for Truth and Reconciliation',
            $nth(2, 1, 10)  => 'Thanksgiving',
            "{$year}-11-11" => 'Remembrance Day',
            "{$year}-12-25" => 'Christmas Day',
        ];
        ksort($h);
        return $h;
    }

    /** Easter Sunday (Anonymous Gregorian algorithm), Y-m-d. */
    public static function easterSunday(int $y): string
    {
        $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4;
        $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return sprintf('%04d-%02d-%02d', $y, $month, $day);
    }

    /** weekday | saturday | sunday_holiday */
    public static function dayType(string $date, array $holidays = []): string
    {
        if (isset($holidays[$date])) return 'sunday_holiday';
        $n = (int)date('N', strtotime($date));
        if ($n === 7) return 'sunday_holiday';
        return $n === 6 ? 'saturday' : 'weekday';
    }

    // ── Places ──────────────────────────────────────────────────────────────

    public static function normalizeCity(?string $city): string
    {
        $c = strtolower(trim((string)$city));
        $c = preg_replace('/^(city of|district of|township of)\s+/', '', $c);
        return preg_replace('/\s+/', ' ', $c);
    }

    /** "v6e 1a1" → "V6E" */
    public static function fsa(?string $postal): string
    {
        $p = strtoupper(preg_replace('/\s+/', '', (string)$postal));
        return preg_match('/^[A-Z]\d[A-Z]/', $p) ? substr($p, 0, 3) : '';
    }

    /** Names of the areas whose postal prefixes match. @param array $areas [name, fsa_prefixes] */
    public static function areasFor(string $fsa, array $areas): array
    {
        if ($fsa === '') return [];
        $out = [];
        foreach ($areas as $a) {
            $list = array_filter(array_map(fn($x) => strtoupper(trim($x)), explode(',', (string)$a['fsa_prefixes'])));
            if (in_array($fsa, $list, true)) $out[] = (string)$a['name'];
        }
        return $out;
    }

    // ── Bylaw hours ─────────────────────────────────────────────────────────

    /** Which rule classes a visit's equipment falls under. */
    public static function ruleClasses(array $equipmentClasses): array
    {
        $out = ['power_equipment'];
        if (in_array('blower', $equipmentClasses, true)) $out[] = 'leaf_blower';
        return $out;
    }

    /**
     * The first rule a visit breaks, or null.
     * @param array $rules rows: kind, equipment_class, power_source, day_type, allowed_start, allowed_end, area, status, note
     * @param array $areas the visit's area names (rules with an area only apply inside it)
     * @param ?string $start 'H:i' or null when the visit has no time (then only bans are checked)
     * @return array{rule: array, problem: string}|null
     */
    public static function breach(array $rules, string $dayType, array $ruleClasses, array $areas, ?string $start, ?string $end): ?array
    {
        $found = [];
        foreach ($rules as $r) {
            if ($r['kind'] === 'note') continue;
            if (!empty($r['area']) && !in_array($r['area'], $areas, true)) continue;
            if ($r['day_type'] !== 'any' && $r['day_type'] !== $dayType) continue;
            if ($r['equipment_class'] !== 'all' && !in_array($r['equipment_class'], $ruleClasses, true)) continue;
            if ($r['kind'] === 'ban') {
                $found[] = ['rule' => $r, 'problem' => 'ban'];
                continue;
            }
            if ($start === null || empty($r['allowed_start']) || empty($r['allowed_end'])) continue;
            $from = substr((string)$r['allowed_start'], 0, 5);
            $to = substr((string)$r['allowed_end'], 0, 5);
            if ($start < $from) $found[] = ['rule' => $r, 'problem' => 'early'];
            elseif ($end !== null && $end > $to) $found[] = ['rule' => $r, 'problem' => 'late'];
        }
        if (!$found) return null;
        // Bans first, then verified rules, then the narrowest class (blower before general).
        usort($found, fn($a, $b) => [$a['problem'] !== 'ban', $a['rule']['status'] !== 'verified', $a['rule']['equipment_class'] !== 'leaf_blower']
            <=> [$b['problem'] !== 'ban', $b['rule']['status'] !== 'verified', $b['rule']['equipment_class'] !== 'leaf_blower']);
        return $found[0];
    }

    /**
     * The earliest start at or after $start on this day that breaks no rule, with its end —
     * or null when nothing fits (a ban that day, or the visit cannot finish by allowed_end or
     * by $latestEnd). Used before a visit is MOVED, so a weather move never lands outside
     * bylaw hours (2026-10-12). PURE.
     * @return array{start: string, end: string}|null
     */
    public static function fitSlot(array $rules, string $dayType, array $ruleClasses, array $areas, string $start, int $minutes, string $latestEnd = '23:59'): ?array
    {
        $start = substr($start, 0, 5);
        for ($i = 0; $i < 4; $i++) {
            $end = self::endTime($start, $minutes);
            if ($end < $start || $end > $latestEnd) return null;          // runs past midnight / the band
            $b = self::breach($rules, $dayType, $ruleClasses, $areas, $start, $end);
            if ($b === null) return ['start' => $start, 'end' => $end];
            if ($b['problem'] !== 'early') return null;                     // ban, or would finish too late
            $start = substr((string)$b['rule']['allowed_start'], 0, 5);     // start when it is allowed
        }
        return null;
    }

    /** Sundays and BC statutory holidays: not a day to move ordinary work onto. PURE. */
    public static function isRestDay(string $date): bool
    {
        return self::dayType($date, self::bcHolidays((int)substr($date, 0, 4))) === 'sunday_holiday';
    }

    /** A visit's end time from its start and length, 'H:i'. */
    public static function endTime(string $start, int $minutes): string
    {
        return date('H:i', strtotime('2000-01-01 ' . $start) + max(0, $minutes) * 60);
    }

    /** Words for a breach. */
    public static function breachText(array $b, string $what, string $dayWord, ?string $start): string
    {
        $r = $b['rule'];
        $tool = $r['equipment_class'] === 'leaf_blower' ? 'leaf blowers' : 'power equipment';
        $where = $r['area'] ?: $r['municipality'];
        if ($b['problem'] === 'ban') {
            $s = "{$what} {$dayWord}: {$where} allows no " . ($r['power_source'] === 'gas' ? 'gas ' : '') . "{$tool}"
               . ($r['day_type'] === 'sunday_holiday' ? ' on Sundays or holidays' : '') . '.';
        } else {
            $s = "{$what} {$dayWord}" . ($start ? ' at ' . date('g:i a', strtotime('2000-01-01 ' . $start)) : '')
               . ": {$where} allows {$tool} " . date('g:i a', strtotime('2000-01-01 ' . $r['allowed_start'])) . '–'
               . date('g:i a', strtotime('2000-01-01 ' . $r['allowed_end'])) . ($b['problem'] === 'late' ? ' and the visit runs past that.' : '.');
        }
        if ($r['status'] !== 'verified') $s .= ' (Rule not yet confirmed.)';
        return $s;
    }

    // ── The Might-E ─────────────────────────────────────────────────────────

    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /** Straight-line km through the points in order (base → stops → base), times the road factor. */
    public static function plannedKm(array $points, float $roadFactor): float
    {
        $km = 0.0;
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $km += self::haversineKm((float)$points[$i - 1][0], (float)$points[$i - 1][1], (float)$points[$i][0], (float)$points[$i][1]);
        }
        return round($km * $roadFactor, 1);
    }

    /**
     * Road factor learned from past days: median of odometer km ÷ straight-line km,
     * kept between 1.0 and 2.5; the default until there are enough days.
     * @param array $days [[odometer_km, straight_km], ...]
     */
    public static function roadFactor(array $days): float
    {
        $r = [];
        foreach ($days as [$odo, $line]) {
            if ($odo > 0 && $line > 0.5) $r[] = $odo / $line;
        }
        if (count($r) < self::ROAD_FACTOR_MIN_DAYS) return self::ROAD_FACTOR;
        sort($r);
        $n = count($r);
        $m = $n % 2 ? $r[intdiv($n, 2)] : ($r[$n / 2 - 1] + $r[$n / 2]) / 2;
        return round(max(1.0, min(2.5, $m)), 2);
    }

    /** km the truck may plan for a day: range less the reserve. */
    public static function dayCapKm(int $rangeKm, ?int $reservePct): float
    {
        $res = $reservePct === null ? self::RESERVE_PCT : max(0, min(90, $reservePct));
        return round($rangeKm * (1 - $res / 100), 1);
    }

    // ── Maintenance and packs ───────────────────────────────────────────────

    /**
     * Tasks due on an item.
     * @param array $intervals [task, every_hours, every_days]
     * @param array $since task => [hours since last service, days since last service]
     * @return array<int, array{task: string, why: string}>
     */
    public static function due(array $intervals, array $since): array
    {
        $out = [];
        foreach ($intervals as $iv) {
            [$h, $d] = $since[$iv['task']] ?? [0.0, 0];
            if (!empty($iv['every_hours']) && $h >= (float)$iv['every_hours']) {
                $out[] = ['task' => $iv['task'], 'why' => round($h, 1) . ' h since the last one (every ' . (float)$iv['every_hours'] . ' h)'];
            } elseif (!empty($iv['every_days']) && $d >= (int)$iv['every_days']) {
                $out[] = ['task' => $iv['task'], 'why' => $d . ' days since the last one (every ' . (int)$iv['every_days'] . ' days)'];
            }
        }
        return $out;
    }

    /**
     * The intervals that apply to one item, one per task: an item row beats a model row
     * (catalog_product_id — every unit of that model, migration 1350) beats a class row.
     */
    public static function intervalsFor(array $item, array $all): array
    {
        $byTask = [];
        $rank = [];
        foreach ($all as $iv) {
            if (!empty($iv['equipment_id'])) {
                if ((int)$iv['equipment_id'] !== (int)$item['id']) continue;
                $r = 3;
            } elseif (!empty($iv['catalog_product_id'])) {
                if (empty($item['catalog_product_id']) || (int)$iv['catalog_product_id'] !== (int)$item['catalog_product_id']) continue;
                $r = 2;
            } else {
                if (($iv['equipment_class'] ?? '') !== $item['equipment_class']) continue;
                $r = 1;
            }
            $t = strtolower(trim((string)$iv['task']));
            if (!isset($byTask[$t]) || $r > $rank[$t]) { $byTask[$t] = $iv + ['scope' => [1 => 'class', 2 => 'model', 3 => 'item'][$r]]; $rank[$t] = $r; }
        }
        return array_values($byTask);
    }

    /**
     * Where one interval stands: due (past it), soon (80% of the way) or ok.
     * $hours / $days = used since the last service (or since purchase).
     */
    public static function intervalState(?float $everyH, ?int $everyD, float $hours, int $days): string
    {
        if (($everyH && $hours >= $everyH) || ($everyD && $days >= $everyD)) return 'due';
        if (($everyH && $hours >= 0.8 * $everyH) || ($everyD && $days >= 0.8 * $everyD)) return 'soon';
        return 'ok';
    }

    /**
     * A pack is fading when the median of its last 3 full runs is under 70% of new.
     * @param int[] $runsNewestFirst minutes per charge, full runs only
     * @return array{fading: bool, median: ?float, share: ?float}
     */
    public static function packFading(array $runsNewestFirst, ?int $newMinutes): array
    {
        $last = array_slice(array_map('intval', $runsNewestFirst), 0, self::FADING_RUNS);
        if (!$newMinutes || count($last) < self::FADING_RUNS) return ['fading' => false, 'median' => null, 'share' => null];
        sort($last);
        $med = (float)$last[1];
        $share = $med / $newMinutes;
        return ['fading' => $share < self::FADING_SHARE, 'median' => $med, 'share' => round($share, 2)];
    }
}
