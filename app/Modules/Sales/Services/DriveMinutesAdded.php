<?php
/**
 * DriveMinutesAdded — the EXTRA truck minutes one more stop adds, not the drive from home.
 *
 * For every existing route day (calendar_stops for one crew on one date, in route order,
 * starting and ending at the depot when we know it), the cheapest place to slot the new
 * lot in costs  d(a,x) + d(x,b) − d(a,b)  over each pair of neighbouring stops a→b.
 * The Closer takes the cheapest day. A client next door to three others adds almost nothing;
 * a lone lot across town adds the full detour.
 *
 * With no route day at all, the stop is a round trip from the depot. With no depot either,
 * the answer is null and the Closer says so.
 *
 * Distances are straight-line × a detour factor, at the Might-E's speed (the same 1.4 and
 * 40 km/h the schedule's route-engine.js uses). Pure: no database.
 */
class DriveMinutesAdded
{
    public const DEFAULT_KMH = 40.0;
    public const DEFAULT_DETOUR = 1.4;

    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    public static function minutes(float $km, float $kmh = self::DEFAULT_KMH, float $detour = self::DEFAULT_DETOUR): float
    {
        return $kmh > 0 ? $km * $detour / $kmh * 60 : 0.0;
    }

    /**
     * @param float      $lat, $lng  the new lot
     * @param array      $days       [['date' => 'Y-m-d', 'crew_id' => int|null, 'stops' => [['lat','lng','property_id'], …]], …]
     *                               stops in route order
     * @param array|null $depot      ['lat' => float, 'lng' => float] or null
     * @return array|null ['minutes', 'basis' => 'route'|'round_trip', 'date', 'crew_id', 'neighbours', 'nearest_km']
     */
    public static function added(float $lat, float $lng, array $days, ?array $depot, float $kmh = self::DEFAULT_KMH, float $detour = self::DEFAULT_DETOUR): ?array
    {
        $best = null;
        foreach ($days as $day) {
            $pts = [];
            if ($depot) $pts[] = [(float)$depot['lat'], (float)$depot['lng']];
            foreach ($day['stops'] ?? [] as $s) {
                if (empty($s['lat']) || empty($s['lng'])) continue;
                $pts[] = [(float)$s['lat'], (float)$s['lng']];
            }
            if ($depot) $pts[] = [(float)$depot['lat'], (float)$depot['lng']];
            $stopCount = count($pts) - ($depot ? 2 : 0);
            if ($stopCount < 1) continue;

            $cheapest = INF;
            if (count($pts) === 1) {
                // One stop, no depot: drive there and back from it.
                $cheapest = 2 * self::haversineKm($pts[0][0], $pts[0][1], $lat, $lng);
            } else {
                for ($i = 0; $i < count($pts) - 1; $i++) {
                    [$a, $b] = [$pts[$i], $pts[$i + 1]];
                    $delta = self::haversineKm($a[0], $a[1], $lat, $lng)
                           + self::haversineKm($lat, $lng, $b[0], $b[1])
                           - self::haversineKm($a[0], $a[1], $b[0], $b[1]);
                    $cheapest = min($cheapest, $delta);
                }
                if (!$depot) {
                    // Open route: the new stop may also go first or last.
                    $cheapest = min($cheapest,
                        self::haversineKm($pts[0][0], $pts[0][1], $lat, $lng),
                        self::haversineKm($pts[count($pts) - 1][0], $pts[count($pts) - 1][1], $lat, $lng));
                }
            }
            $neighbours = 0;
            $nearest = INF;
            foreach ($day['stops'] ?? [] as $s) {
                if (empty($s['lat']) || empty($s['lng'])) continue;
                $d = self::haversineKm((float)$s['lat'], (float)$s['lng'], $lat, $lng);
                $nearest = min($nearest, $d);
                if ($d <= 0.5) $neighbours++;
            }
            $mins = self::minutes(max(0.0, $cheapest), $kmh, $detour);
            if ($best === null || $mins < $best['minutes']) {
                $best = [
                    'minutes'    => round($mins, 1),
                    'basis'      => 'route',
                    'date'       => $day['date'] ?? null,
                    'crew_id'    => $day['crew_id'] ?? null,
                    'neighbours' => $neighbours,
                    'nearest_km' => is_finite($nearest) ? round($nearest, 2) : null,
                ];
            }
        }
        if ($best !== null) return $best;
        if ($depot) {
            $km = self::haversineKm((float)$depot['lat'], (float)$depot['lng'], $lat, $lng);
            return ['minutes' => round(self::minutes(2 * $km, $kmh, $detour), 1), 'basis' => 'round_trip',
                    'date' => null, 'crew_id' => null, 'neighbours' => 0, 'nearest_km' => round($km, 2)];
        }
        return null;
    }
}
