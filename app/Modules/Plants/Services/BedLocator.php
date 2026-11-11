<?php
declare(strict_types=1);

/**
 * BedLocator — which measured bed a photo was taken in or pointed at (plant ID, 2026-10-15).
 *
 * Pure geometry on the property's drawn outlines (property_measurements.polygon_coords, a JSON
 * list of {lat, lng}). Distances use a flat local projection around the photo — fine at garden scale.
 *   inside  — the point is inside exactly one bed and the fix is good (≤ GOOD_FIX_M): sure.
 *   facing  — the camera's compass heading runs into a bed within MAX_RAY_M: sure when the fix is good.
 *   near    — the nearest bed edge is within max(NEAR_M, the fix's accuracy up to GOOD_FIX_M): a guess.
 * Anything less: no bed, the crew picks one (or draws it).
 */
class BedLocator
{
    public const GOOD_FIX_M = 15.0;
    public const NEAR_M = 5.0;
    public const MAX_RAY_M = 40.0;
    private const M_PER_DEG_LAT = 111320.0;

    /** @return list<array{lat: float, lng: float}> the outline, or [] when it isn't one */
    public static function ring($coords): array
    {
        $a = is_array($coords) ? $coords : json_decode((string)$coords, true);
        if (!is_array($a)) return [];
        $out = [];
        foreach ($a as $p) {
            if (isset($p['lat'], $p['lng']) && is_numeric($p['lat']) && is_numeric($p['lng'])) $out[] = ['lat' => (float)$p['lat'], 'lng' => (float)$p['lng']];
        }
        return count($out) >= 3 ? $out : [];
    }

    /** The outline in metres around ($lat, $lng): x east, y north. */
    private static function local(float $lat, float $lng, array $ring): array
    {
        $kx = self::M_PER_DEG_LAT * cos(deg2rad($lat));
        return array_map(fn($p) => [($p['lng'] - $lng) * $kx, ($p['lat'] - $lat) * self::M_PER_DEG_LAT], $ring);
    }

    public static function inside(float $lat, float $lng, array $ring): bool
    {
        $pts = self::local($lat, $lng, $ring);
        $in = false;
        $n = count($pts);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = $pts[$i];
            [$xj, $yj] = $pts[$j];
            if ((($yi > 0) !== ($yj > 0)) && (0 < ($xj - $xi) * (0 - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) $in = !$in;
        }
        return $in;
    }

    /** Metres from the point to the outline's nearest edge (0 inside). */
    public static function distance(float $lat, float $lng, array $ring): float
    {
        if (self::inside($lat, $lng, $ring)) return 0.0;
        $pts = self::local($lat, $lng, $ring);
        $best = INF;
        $n = count($pts);
        for ($i = 0; $i < $n; $i++) {
            [$ax, $ay] = $pts[$i];
            [$bx, $by] = $pts[($i + 1) % $n];
            $dx = $bx - $ax; $dy = $by - $ay;
            $len2 = $dx * $dx + $dy * $dy;
            $t = $len2 > 0 ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $len2)) : 0.0;
            $best = min($best, hypot($ax + $t * $dx, $ay + $t * $dy));
        }
        return $best;
    }

    /** Metres along the compass heading (0 = north, 90 = east) to where the ray first meets the outline; null if it doesn't. */
    public static function rayHit(float $lat, float $lng, float $heading, array $ring): ?float
    {
        $pts = self::local($lat, $lng, $ring);
        $ux = sin(deg2rad($heading)); $uy = cos(deg2rad($heading));
        $best = null;
        $n = count($pts);
        for ($i = 0; $i < $n; $i++) {
            [$ax, $ay] = $pts[$i];
            [$bx, $by] = $pts[($i + 1) % $n];
            $ex = $bx - $ax; $ey = $by - $ay;
            $den = $ux * $ey - $uy * $ex;
            if (abs($den) < 1e-12) continue;
            $t = ($ax * $ey - $ay * $ex) / $den;          // along the ray
            $s = ($ax * $uy - $ay * $ux) / $den;          // along the edge
            if ($t >= 0 && $s >= 0 && $s <= 1 && ($best === null || $t < $best)) $best = $t;
        }
        return $best;
    }

    /**
     * @param list<array{id: int, name: string, ring: array}> $beds
     * @return array{id: ?int, name: ?string, how: ?string, sure: bool, candidates: list<array{id: int, name: string, m: float}>}
     */
    public static function locate(?float $lat, ?float $lng, ?float $accuracy, ?float $heading, array $beds): array
    {
        $none = ['id' => null, 'name' => null, 'how' => null, 'sure' => false, 'candidates' => []];
        if ($lat === null || $lng === null || !$beds) return $none;
        $good = $accuracy === null || $accuracy <= self::GOOD_FIX_M;
        $cands = [];
        $inside = [];
        foreach ($beds as $b) {
            if (!$b['ring']) continue;
            $d = self::distance($lat, $lng, $b['ring']);
            $cands[] = ['id' => (int)$b['id'], 'name' => (string)$b['name'], 'm' => round($d, 1)];
            if ($d === 0.0) $inside[] = $b;
        }
        usort($cands, fn($a, $b) => $a['m'] <=> $b['m']);
        $out = ['candidates' => array_slice($cands, 0, 5)] + $none;
        $pick = function (array $b, string $how, bool $sure) use ($out) {
            return ['id' => (int)$b['id'], 'name' => (string)$b['name'], 'how' => $how, 'sure' => $sure, 'candidates' => $out['candidates']];
        };
        if (count($inside) === 1) return $pick($inside[0], 'inside', $good);
        if ($heading !== null) {
            $hit = null; $hitM = null;
            foreach ($beds as $b) {
                if (!$b['ring']) continue;
                $m = self::rayHit($lat, $lng, $heading, $b['ring']);
                if ($m !== null && $m <= self::MAX_RAY_M && ($hitM === null || $m < $hitM)) { $hit = $b; $hitM = $m; }
            }
            if ($hit) return $pick($hit, 'facing', $good && count($inside) === 0);
        }
        $reach = max(self::NEAR_M, min((float)($accuracy ?? 0), self::GOOD_FIX_M));
        if ($cands && $cands[0]['m'] <= $reach) {
            foreach ($beds as $b) if ((int)$b['id'] === $cands[0]['id']) return $pick($b, 'near', false);
        }
        return $out;
    }
}
