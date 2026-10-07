<?php
declare(strict_types=1);

/**
 * A synthetic truck trail shaped like 2026-10-07: overnight at the yard, Oakridge job,
 * green waste to the Vancouver Transfer Station, a 4-minute hold-up, 2 yards of mulch at
 * Lawnboy (position made up — the real one is learned from the trail), back to Oakridge.
 * Pings every 2 minutes, local time.
 */
final class TripTrailFixture
{
    public const YARD     = [49.2543, -123.1262];
    public const OAKRIDGE = [49.2305, -123.1229];
    public const DUMP     = [49.2073, -123.1130];
    public const HOLDUP   = [49.2108, -123.1029];
    public const LAWNBOY  = [49.1985, -123.0905];

    /** @param string|null $cutAt 'H:i' — stop the trail here (a run still in progress) */
    public static function pings(string $date = '2026-10-07', ?string $cutAt = null): array
    {
        $plan = [
            ['stay', self::YARD, '06:30', '08:30'],
            ['go', self::YARD, self::OAKRIDGE, '08:30', '08:56'],
            ['stay', self::OAKRIDGE, '08:56', '09:36'],
            ['go', self::OAKRIDGE, self::DUMP, '09:36', '09:48'],
            ['stay', self::DUMP, '09:48', '10:00'],
            ['go', self::DUMP, self::HOLDUP, '10:00', '10:06'],
            ['stay', self::HOLDUP, '10:06', '10:10'],
            ['go', self::HOLDUP, self::LAWNBOY, '10:10', '10:24'],
            ['stay', self::LAWNBOY, '10:24', '10:44'],
            ['go', self::LAWNBOY, self::OAKRIDGE, '10:44', '11:00'],
            ['stay', self::OAKRIDGE, '11:00', '12:30'],
        ];
        $out = [];
        $seen = [];
        $cut = $cutAt !== null ? strtotime("$date $cutAt") : PHP_INT_MAX;
        foreach ($plan as $p) {
            if ($p[0] === 'stay') {
                [, $at, $a, $b] = $p;
                for ($t = strtotime("$date $a"); $t <= strtotime("$date $b"); $t += 120) {
                    $out[$t] = ['lat' => $at[0], 'lng' => $at[1], 'speed_kph' => 0.0, 't' => $t];
                }
            } else {
                [, $from, $to, $a, $b] = $p;
                $t0 = strtotime("$date $a"); $t1 = strtotime("$date $b");
                for ($t = $t0 + 120; $t < $t1; $t += 120) {
                    $f = ($t - $t0) / ($t1 - $t0);
                    $out[$t] = ['lat' => $from[0] + ($to[0] - $from[0]) * $f, 'lng' => $from[1] + ($to[1] - $from[1]) * $f, 'speed_kph' => 38.0, 't' => $t];
                }
            }
        }
        ksort($out);
        return array_values(array_filter($out, fn($p) => $p['t'] <= $cut));
    }

    public static function properties(): array
    {
        return [
            ['id' => 41, 'latitude' => self::OAKRIDGE[0], 'longitude' => self::OAKRIDGE[1], 'address' => '5800 Oak St', 'name' => 'Oakridge', 'property_name' => 'Oakridge strata'],
            ['id' => 42, 'latitude' => 49.2400, 'longitude' => -123.1500, 'address' => '1 Elsewhere Rd', 'name' => '', 'property_name' => ''],
        ];
    }

    public static function places(bool $withLawnboy = false): array
    {
        $p = [
            ['id' => 1, 'name' => 'Vancouver Transfer Station', 'kind' => 'dump', 'lat' => self::DUMP[0], 'lng' => self::DUMP[1], 'radius_m' => 150, 'vendor_match' => 'transfer station|city of vancouver'],
            ['id' => 2, 'name' => 'Yard', 'kind' => 'yard', 'lat' => self::YARD[0], 'lng' => self::YARD[1], 'radius_m' => 150, 'vendor_match' => null],
        ];
        if ($withLawnboy) {
            $p[] = ['id' => 3, 'name' => 'Lawnboy', 'kind' => 'supplier', 'lat' => self::LAWNBOY[0], 'lng' => self::LAWNBOY[1], 'radius_m' => 150, 'vendor_match' => 'lawnboy'];
        }
        return $p;
    }
}
