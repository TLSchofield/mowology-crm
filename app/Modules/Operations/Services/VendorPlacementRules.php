<?php
/** Pure rules for learning store positions from receipt capture GPS. */
declare(strict_types=1);

require_once __DIR__ . '/PropertyBorderRules.php';

final class VendorPlacementRules
{
    /**
     * Connected GPS groups with at least two observations, centred on the coordinate median.
     * @return list<array{center: array, points: array, count: int}>
     */
    public static function cluster(array $points, float $radiusM = 120): array
    {
        $valid = [];
        foreach ($points as $p) {
            $lat = isset($p[0]) ? $p[0] : ($p['lat'] ?? null);
            $lng = isset($p[1]) ? $p[1] : ($p['lng'] ?? null);
            if (is_numeric($lat) && is_numeric($lng) && (float)$lat != 0.0 && (float)$lng != 0.0) $valid[] = [(float)$lat, (float)$lng];
        }
        $seen = [];
        $out = [];
        foreach ($valid as $start => $point) {
            if (isset($seen[$start])) continue;
            $queue = [$start];
            $seen[$start] = true;
            $group = [];
            while ($queue) {
                $i = array_shift($queue);
                $group[] = $valid[$i];
                foreach ($valid as $j => $candidate) {
                    if (!isset($seen[$j]) && PropertyBorderRules::meters($valid[$i][0], $valid[$i][1], $candidate[0], $candidate[1]) <= $radiusM) {
                        $seen[$j] = true;
                        $queue[] = $j;
                    }
                }
            }
            if (count($group) >= 2) $out[] = ['center' => PropertyBorderRules::median($group), 'points' => $group, 'count' => count($group)];
        }
        usort($out, static function ($a, $b) { return $b['count'] <=> $a['count']; });
        return $out;
    }
}
