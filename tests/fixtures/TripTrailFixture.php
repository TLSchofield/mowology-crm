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

    /** The real 2026-10-07 unnamed stop (Lawn Boy, per Tim) and the real dump stop window. */
    public const LAWNBOY_REAL = [49.2069, -123.1176];

    /**
     * The real 2026-10-07 trail as Otto saw it: Oakridge 08:56–09:36, Vancouver Transfer Station
     * 09:51–10:00, unnamed 10:15–10:24 at 49.2069,-123.1176, Oakridge 10:35–11:16, unnamed again
     * 11:29–11:41 at 49.2070,-123.1177 (the truck was still there at the last ping).
     */
    public static function real1007(string $date = '2026-10-07'): array
    {
        $plan = [
            ['stay', self::YARD, '06:30', '08:30'],
            ['go', self::YARD, self::OAKRIDGE, '08:30', '08:56'],
            ['stay', self::OAKRIDGE, '08:56', '09:36'],
            ['go', self::OAKRIDGE, self::DUMP, '09:36', '09:51'],
            ['stay', self::DUMP, '09:51', '10:00'],
            ['go', self::DUMP, self::LAWNBOY_REAL, '10:00', '10:15'],
            ['stay', self::LAWNBOY_REAL, '10:15', '10:24'],
            ['go', self::LAWNBOY_REAL, self::OAKRIDGE, '10:24', '10:35'],
            ['stay', self::OAKRIDGE, '10:35', '11:16'],
            ['go', self::OAKRIDGE, [49.2070, -123.1177], '11:16', '11:29'],
            ['stay', [49.2070, -123.1177], '11:29', '11:41'],
        ];
        return self::build($plan, $date);
    }

    /**
     * The 2026-10-07 trail through the afternoon: the dump, then Lawn Boy three times from Oakridge —
     * 10:15–10:24, 11:29–11:34 and 12:32–12:42 (what Tim asked Charlie about).
     */
    public static function real1007Full(string $date = '2026-10-07'): array
    {
        $plan = [
            ['stay', self::YARD, '06:30', '08:30'],
            ['go', self::YARD, self::OAKRIDGE, '08:30', '08:56'],
            ['stay', self::OAKRIDGE, '08:56', '09:36'],
            ['go', self::OAKRIDGE, self::DUMP, '09:36', '09:51'],
            ['stay', self::DUMP, '09:51', '10:00'],
            ['go', self::DUMP, self::LAWNBOY_REAL, '10:00', '10:15'],
            ['stay', self::LAWNBOY_REAL, '10:15', '10:24'],
            ['go', self::LAWNBOY_REAL, self::OAKRIDGE, '10:24', '10:35'],
            ['stay', self::OAKRIDGE, '10:35', '11:16'],
            ['go', self::OAKRIDGE, [49.2070, -123.1177], '11:16', '11:29'],
            ['stay', [49.2070, -123.1177], '11:29', '11:34'],
            ['go', [49.2070, -123.1177], self::OAKRIDGE, '11:34', '11:45'],
            ['stay', self::OAKRIDGE, '11:45', '12:20'],
            ['go', self::OAKRIDGE, self::LAWNBOY_REAL, '12:20', '12:32'],
            ['stay', self::LAWNBOY_REAL, '12:32', '12:42'],
            ['go', self::LAWNBOY_REAL, self::OAKRIDGE, '12:42', '12:55'],
            ['stay', self::OAKRIDGE, '12:55', '13:30'],
        ];
        return self::build($plan, $date);
    }

    /** Pings every minute for a plan (one-minute spacing so odd-minute stop edges land exactly). */
    private static function build(array $plan, string $date): array
    {
        $out = [];
        foreach ($plan as $p) {
            if ($p[0] === 'stay') {
                [, $at, $a, $b] = $p;
                for ($t = strtotime("$date $a"); $t <= strtotime("$date $b"); $t += 60) {
                    $out[$t] = ['lat' => $at[0], 'lng' => $at[1], 'speed_kph' => 0.0, 't' => $t];
                }
            } else {
                [, $from, $to, $a, $b] = $p;
                $t0 = strtotime("$date $a"); $t1 = strtotime("$date $b");
                for ($t = $t0 + 60; $t < $t1; $t += 60) {
                    $f = ($t - $t0) / ($t1 - $t0);
                    $out[$t] = ['lat' => $from[0] + ($to[0] - $from[0]) * $f, 'lng' => $from[1] + ($to[1] - $from[1]) * $f, 'speed_kph' => 38.0, 't' => $t];
                }
            }
        }
        ksort($out);
        return array_values($out);
    }

    /** Expense #411 as stored: the dump's scale ticket, filed by Nigel at 11:21 (truck between stops). */
    public static function ticket411(): array
    {
        return [
            'id' => 411, 'expense_date' => '2026-10-07', 'vendor_id' => 12, 'vendor' => 'City of Vancouver Vancouver Landfill',
            'vendor_name_raw' => 'City of Vancouver Vancouver Landfill', 'accounting_category' => 'Disposal/Dump', 'gbp' => null,
            'total' => '27.00', 'created_at' => '2026-10-07 11:21:00', 'created_by' => 6, 'receipt_lat' => null, 'receipt_lng' => null,
            'raw_ocr_json' => "CITY OF VANCOUVER\nVancouver Landfill\nDate: 10/07/26\nTime In: 09:49 AM\nTime Out: 10:01 AM\nTruck ID: PF8865\nNet 0.43 t\nTotal 25.71",
        ];
    }

    /** A synthetic Lawn Boy slip printed at 10:18 (none was filed on the real day yet). */
    public static function lawnBoySlip(int $id = 412, string $date = '2026-10-07', string $time = '10:18'): array
    {
        return [
            'id' => $id, 'expense_date' => $date, 'vendor_id' => 31, 'vendor' => 'Lawn Boy', 'vendor_name_raw' => 'LAWN BOY LANDSCAPE SUPPLY',
            'accounting_category' => 'Materials', 'gbp' => 'Garden center/nursery', 'total' => '128.10',
            'created_at' => $date . ' 16:40:00', 'created_by' => 6, 'receipt_lat' => null, 'receipt_lng' => null,
            'raw_ocr_json' => "LAWN BOY\nInvoice 88120\n" . date('m/d/Y', strtotime($date)) . ' ' . $time . "\n2 YD BLACK MULCH\nGRASS SEED 10KG\nTOTAL 128.10",
        ];
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
