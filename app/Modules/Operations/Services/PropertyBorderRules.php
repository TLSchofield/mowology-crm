<?php
/**
 * PropertyBorderRules — pure geometry and judgement for Otto's "a border for every client" work.
 * No database. PropertyBorderService loads the facts and stores the answers.
 *
 *   Where crews really work    the coordinate-wise median of the crew phone fixes (and, when the
 *                              phones are thin, the truck's parked pings) recorded while a timer
 *                              ran on one of the property's visits.
 *   Pin far off                that median more than PIN_OFF_M from the pin → propose moving it.
 *   Default border             with enough fixes: their convex hull, buffered BUFFER_M and capped
 *                              CAP_M from the centre ('default_hull'); otherwise a square of
 *                              ±SQUARE_HALF_M around the pin ('default_square', "draw me").
 *                              Never planned where any arrival border already exists.
 *   Overlap                    two properties' arrival borders share ground.
 *
 * Rings are [[lat, lng], ...], open (no closing duplicate), as job_geofences stores them
 * before geofenceSaveZone closes them.
 */
class PropertyBorderRules
{
    /** Bump with any change below; the audit shows it so a half-refreshed OPcache is visible. */
    public const BUILD = '2026-10-08a';

    public const PIN_OFF_M = 60;
    public const FIX_RADIUS_M = 200;     // fixes further than this from the pin are the drive there, not the site
    public const MIN_FIXES = 12;
    public const MIN_VISITS = 2;
    public const BUFFER_M = 10;
    public const CAP_M = 80;             // no vertex further than this from the centre of the work
    public const MIN_AREA_SQM = 600;     // a hull smaller than this (a crew that never moved) gets the square too
    public const SQUARE_HALF_M = 25;
    public const LOOKBACK_MONTHS = 12;
    public const VISITS_PER_PROPERTY = 8;

    public const SOURCES_DEFAULT = ['default_hull', 'default_square'];

    private const M_PER_DEG = 111320.0;

    // ── Distances and projection ────────────────────────────────────────────

    public static function meters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $r * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** [lat, lng] → [x, y] metres east / north of $ref. */
    public static function toXY(array $p, array $ref): array
    {
        return [((float)$p[1] - $ref[1]) * self::M_PER_DEG * cos(deg2rad($ref[0])), ((float)$p[0] - $ref[0]) * self::M_PER_DEG];
    }

    public static function toLatLng(array $xy, array $ref): array
    {
        return [round($ref[0] + $xy[1] / self::M_PER_DEG, 7), round($ref[1] + $xy[0] / (self::M_PER_DEG * cos(deg2rad($ref[0]))), 7)];
    }

    /** Area in m² of an open or closed ring (same projection as geofencePolygonArea, to < 1 %). */
    public static function area(array $ring): float
    {
        $n = count($ring);
        if ($n < 3) return 0.0;
        $ref = [(float)$ring[0][0], (float)$ring[0][1]];
        $s = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $a = self::toXY($ring[$i], $ref);
            $b = self::toXY($ring[($i + 1) % $n], $ref);
            $s += $a[0] * $b[1] - $b[0] * $a[1];
        }
        return abs($s / 2);
    }

    /** [latMin, latMax, lngMin, lngMax]. */
    public static function bbox(array $ring): array
    {
        $lats = array_map(fn($p) => (float)$p[0], $ring);
        $lngs = array_map(fn($p) => (float)$p[1], $ring);
        return [min($lats), max($lats), min($lngs), max($lngs)];
    }

    // ── Where the crews work ────────────────────────────────────────────────

    /** Coordinate-wise median of [[lat, lng], ...]; null when empty. */
    public static function median(array $points): ?array
    {
        if (!$points) return null;
        $lats = array_map(fn($p) => (float)$p[0], $points);
        $lngs = array_map(fn($p) => (float)$p[1], $points);
        sort($lats);
        sort($lngs);
        $mid = function (array $v): float {
            $n = count($v);
            return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
        };
        return [round($mid($lats), 7), round($mid($lngs), 7)];
    }

    /**
     * The fixes that describe the site: within FIX_RADIUS_M of the pin (when there is one), then
     * without the stragglers more than 3× the median spread from the median (min 30 m).
     * @param array $fixes [{lat, lng, visit_id, src}]
     * @return array{points: array, visits: int, center: ?array}
     */
    public static function siteFixes(array $fixes, ?array $pin): array
    {
        $pts = [];
        foreach ($fixes as $f) {
            $lat = (float)($f['lat'] ?? 0);
            $lng = (float)($f['lng'] ?? 0);
            if ($lat == 0.0 || $lng == 0.0) continue;
            if ($pin && self::meters($lat, $lng, $pin[0], $pin[1]) > self::FIX_RADIUS_M) continue;
            $pts[] = ['p' => [$lat, $lng], 'v' => (int)($f['visit_id'] ?? 0)];
        }
        if (!$pts) return ['points' => [], 'visits' => 0, 'center' => null];
        $c = self::median(array_column($pts, 'p'));
        $d = array_map(fn($x) => self::meters($x['p'][0], $x['p'][1], $c[0], $c[1]), $pts);
        $sorted = $d;
        sort($sorted);
        $spread = $sorted[intdiv(count($sorted), 2)];
        $keep = max(30.0, 3 * $spread);
        $out = [];
        $visits = [];
        foreach ($pts as $i => $x) {
            if ($d[$i] > $keep) continue;
            $out[] = $x['p'];
            $visits[$x['v']] = true;
        }
        return ['points' => $out, 'visits' => count($visits), 'center' => self::median($out)];
    }

    public static function enoughFixes(array $site): bool
    {
        return count($site['points']) >= self::MIN_FIXES && $site['visits'] >= self::MIN_VISITS;
    }

    /** Pin → centre of the work in metres, or null when either is missing. */
    public static function pinOffset(?array $pin, ?array $center): ?int
    {
        if (!$pin || !$center) return null;
        return (int)round(self::meters($pin[0], $pin[1], $center[0], $center[1]));
    }

    public static function pinIsOff(?int $offset): bool
    {
        return $offset !== null && $offset > self::PIN_OFF_M;
    }

    // ── Polygons ────────────────────────────────────────────────────────────

    /** Convex hull (Andrew's monotone chain), counter-clockwise, open ring. */
    public static function convexHull(array $points): array
    {
        $uniq = [];
        foreach ($points as $p) $uniq[sprintf('%.7f,%.7f', $p[0], $p[1])] = [(float)$p[0], (float)$p[1]];
        $pts = array_values($uniq);
        if (count($pts) < 3) return $pts;
        $ref = $pts[0];
        $xy = array_map(fn($p) => self::toXY($p, $ref), $pts);
        usort($xy, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $cross = fn($o, $a, $b) => ($a[0] - $o[0]) * ($b[1] - $o[1]) - ($a[1] - $o[1]) * ($b[0] - $o[0]);
        $lower = [];
        foreach ($xy as $p) {
            while (count($lower) >= 2 && $cross($lower[count($lower) - 2], $lower[count($lower) - 1], $p) <= 0) array_pop($lower);
            $lower[] = $p;
        }
        $upper = [];
        foreach (array_reverse($xy) as $p) {
            while (count($upper) >= 2 && $cross($upper[count($upper) - 2], $upper[count($upper) - 1], $p) <= 0) array_pop($upper);
            $upper[] = $p;
        }
        array_pop($lower);
        array_pop($upper);
        return array_map(fn($p) => self::toLatLng($p, $ref), array_merge($lower, $upper));
    }

    /** The hull grown by $m metres (every vertex replaced by 8 points round it, re-hulled). */
    public static function buffer(array $ring, float $m): array
    {
        if (!$ring) return [];
        $ref = [(float)$ring[0][0], (float)$ring[0][1]];
        $pts = [];
        foreach ($ring as $p) {
            $c = self::toXY($p, $ref);
            for ($k = 0; $k < 8; $k++) {
                $a = M_PI / 4 * $k;
                $pts[] = self::toLatLng([$c[0] + $m * cos($a), $c[1] + $m * sin($a)], $ref);
            }
        }
        return self::convexHull($pts);
    }

    /** Pull any vertex further than $maxM from $center back onto that circle. */
    public static function cap(array $ring, array $center, float $maxM): array
    {
        return array_map(function ($p) use ($center, $maxM) {
            $xy = self::toXY($p, $center);
            $d = sqrt($xy[0] ** 2 + $xy[1] ** 2);
            if ($d <= $maxM || $d == 0.0) return [(float)$p[0], (float)$p[1]];
            return self::toLatLng([$xy[0] * $maxM / $d, $xy[1] * $maxM / $d], $center);
        }, $ring);
    }

    /** ±$halfM square round a point, counter-clockwise from the south-west corner. */
    public static function square(float $lat, float $lng, float $halfM = self::SQUARE_HALF_M): array
    {
        $c = [$lat, $lng];
        return [
            self::toLatLng([-$halfM, -$halfM], $c),
            self::toLatLng([$halfM, -$halfM], $c),
            self::toLatLng([$halfM, $halfM], $c),
            self::toLatLng([-$halfM, $halfM], $c),
        ];
    }

    /**
     * The border Otto would give a property with no arrival border yet, or null.
     * @param array|null $pin     [lat, lng] — null: no border without a pin
     * @param array      $site    siteFixes() result
     * @param bool       $hasBorder any arrival border already (drawn or default) — never replaced
     * @return array{source: string, ring: array, center: array, fixes: int, visits: int, area_sqm: int}|null
     */
    public static function planBorder(?array $pin, array $site, bool $hasBorder): ?array
    {
        if ($hasBorder || !$pin) return null;
        if (self::enoughFixes($site)) {
            $center = $site['center'];
            $ring = self::cap(self::buffer(self::convexHull($site['points']), self::BUFFER_M), $center, self::CAP_M);
            if (count($ring) >= 3 && self::area($ring) >= self::MIN_AREA_SQM) {
                return ['source' => 'default_hull', 'ring' => $ring, 'center' => $center,
                        'fixes' => count($site['points']), 'visits' => $site['visits'], 'area_sqm' => (int)round(self::area($ring))];
            }
            // The crew barely moved: the work square, centred where they were.
            $ring = self::convexHull(array_merge($ring, self::square($center[0], $center[1], self::SQUARE_HALF_M)));
            return ['source' => 'default_hull', 'ring' => $ring, 'center' => $center,
                    'fixes' => count($site['points']), 'visits' => $site['visits'], 'area_sqm' => (int)round(self::area($ring))];
        }
        $ring = self::square($pin[0], $pin[1]);
        return ['source' => 'default_square', 'ring' => $ring, 'center' => $pin,
                'fixes' => count($site['points']), 'visits' => $site['visits'], 'area_sqm' => (int)round(self::area($ring))];
    }

    /** Do two rings share any ground? (bbox, then an edge crossing, then one inside the other.) */
    public static function overlap(array $a, array $b): bool
    {
        if (count($a) < 3 || count($b) < 3) return false;
        [$a1, $a2, $a3, $a4] = self::bbox($a);
        [$b1, $b2, $b3, $b4] = self::bbox($b);
        if ($a2 < $b1 || $b2 < $a1 || $a4 < $b3 || $b4 < $a3) return false;
        $na = count($a);
        $nb = count($b);
        for ($i = 0; $i < $na; $i++) {
            for ($j = 0; $j < $nb; $j++) {
                if (self::segmentsCross($a[$i], $a[($i + 1) % $na], $b[$j], $b[($j + 1) % $nb])) return true;
            }
        }
        return self::inside($a[0], $b) || self::inside($b[0], $a);
    }

    public static function inside(array $p, array $ring): bool
    {
        $in = false;
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = (float)$ring[$i][0]; $yi = (float)$ring[$i][1];
            $xj = (float)$ring[$j][0]; $yj = (float)$ring[$j][1];
            if ((($yi > $p[1]) !== ($yj > $p[1])) && ($p[0] < ($xj - $xi) * ($p[1] - $yi) / ($yj - $yi) + $xi)) $in = !$in;
        }
        return $in;
    }

    private static function segmentsCross(array $p1, array $p2, array $p3, array $p4): bool
    {
        $d = fn($a, $b, $c) => ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
        $d1 = $d($p3, $p4, $p1);
        $d2 = $d($p3, $p4, $p2);
        $d3 = $d($p1, $p2, $p3);
        $d4 = $d($p1, $p2, $p4);
        return (($d1 > 0 && $d2 < 0) || ($d1 < 0 && $d2 > 0)) && (($d3 > 0 && $d4 < 0) || ($d3 < 0 && $d4 > 0));
    }

    /**
     * Pairs of properties whose arrival borders overlap.
     * @param array $borders [{property_id, ring}]
     * @return array<int, array{0: int, 1: int}>
     */
    public static function overlaps(array $borders): array
    {
        $out = [];
        $n = count($borders);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $pa = (int)$borders[$i]['property_id'];
                $pb = (int)$borders[$j]['property_id'];
                if ($pa === $pb) continue;
                if (self::overlap($borders[$i]['ring'], $borders[$j]['ring'])) $out[min($pa, $pb) . '-' . max($pa, $pb)] = [min($pa, $pb), max($pa, $pb)];
            }
        }
        return array_values($out);
    }

    // ── The audit ───────────────────────────────────────────────────────────

    /**
     * @param array $props   [{id, address, lat, lng}] active client properties
     * @param array $borders [property_id => {drawn: int, default: int, kept: int}] arrival borders by kind
     *                       (kept = an Otto default the owner said is fine — counted on its own)
     * @param array $sites   [property_id => {offset_m: ?int, center: ?array, fixes: int, visits: int}]
     * @param array $overlaps pairs from overlaps()
     */
    public static function audit(array $props, array $borders, array $sites, array $overlaps): array
    {
        $out = ['active' => count($props), 'with_pin' => 0, 'without_pin' => 0, 'with_drawn_border' => 0,
                'with_default_border' => 0, 'with_kept_border' => 0, 'without_border' => 0, 'measured' => 0, 'pin_off' => 0, 'overlaps' => count($overlaps),
                'no_pin' => [], 'pin_off_list' => [], 'no_border' => [], 'overlap_list' => $overlaps, 'build' => self::BUILD];
        foreach ($props as $p) {
            $pid = (int)$p['id'];
            $pin = self::pinOf($p);
            if ($pin) $out['with_pin']++;
            else {
                $out['without_pin']++;
                $out['no_pin'][] = ['id' => $pid, 'address' => (string)$p['address']];
            }
            $b = ($borders[$pid] ?? []) + ['drawn' => 0, 'default' => 0, 'kept' => 0];
            if ($b['drawn'] > 0) $out['with_drawn_border']++;
            elseif ($b['default'] > 0) $out['with_default_border']++;
            elseif ($b['kept'] > 0) $out['with_kept_border']++;
            else {
                $out['without_border']++;
                if ($pin) $out['no_border'][] = ['id' => $pid, 'address' => (string)$p['address']];
            }
            $s = $sites[$pid] ?? null;
            if ($s && $s['center']) $out['measured']++;
            if ($s && self::pinIsOff($s['offset_m'])) {
                $out['pin_off']++;
                $out['pin_off_list'][] = ['id' => $pid, 'address' => (string)$p['address'], 'distance_m' => (int)$s['offset_m'],
                                          'work' => $s['center'], 'fixes' => (int)$s['fixes'], 'visits' => (int)$s['visits']];
            }
        }
        usort($out['pin_off_list'], fn($a, $b) => $b['distance_m'] <=> $a['distance_m']);
        return $out;
    }

    /** [lat, lng] or null for NULL / 0,0 / out-of-range coordinates. */
    public static function pinOf(array $p): ?array
    {
        $lat = isset($p['latitude']) ? (float)$p['latitude'] : (float)($p['lat'] ?? 0);
        $lng = isset($p['longitude']) ? (float)$p['longitude'] : (float)($p['lng'] ?? 0);
        if ($lat == 0.0 || $lng == 0.0 || abs($lat) > 90 || abs($lng) > 180) return null;
        return [$lat, $lng];
    }

    // ── Otto's words ────────────────────────────────────────────────────────

    public static function street(string $address): string
    {
        $a = trim(explode(',', $address)[0] ?? '');
        return $a !== '' ? $a : 'A client property';
    }

    public static function text(string $kind, string $address, array $x = []): string
    {
        $st = self::street($address);
        switch ($kind) {
            case 'no_pin':
                return $st . ' has no pin — crews can\'t be seen there' . (!empty($x['next']) ? ' (next visit ' . date('D M j', strtotime($x['next'])) . ')' : '') . '.';
            case 'pin_off':
                return 'Pin for ' . $st . ' is ' . (int)$x['distance_m'] . ' m from where crews work → move it.';
            case 'default_border':
                return $st . ' has a default border' . (($x['source'] ?? '') === 'default_square' ? ' (a square round the pin)' : ' (from where crews worked)') . ' — draw it.';
            case 'border_overlap':
                return 'The borders of ' . $st . ' and ' . self::street((string)($x['other'] ?? '')) . ' overlap — a crew there counts at both.';
        }
        return $st;
    }
}
