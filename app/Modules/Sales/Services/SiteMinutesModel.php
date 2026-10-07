<?php
/**
 * SiteMinutesModel — how many person-minutes a service takes on a lot.
 *
 *   minutes = fixed + per_unit × units + per_obstacle × obstacles
 *   units   = lawn sq ft / 1000 (mow, cleanup, aeration, overseed, beds),
 *             edging ft / 100 (edge), hedge ft / 100 (hedge)
 *
 * Which numbers are used, per service (choose()):
 *   1. 'fit'    — least squares on Tim's own timed visits, once there are CALIBRATION_MIN
 *                 good timed + measured visits for that service (recalibrated weekly);
 *   2. 'manual' — the minutes Tim typed for that service;
 *   3. 'implied'— what the current price rule (QuoteCalculator) implies at the target margin.
 *                 This is the seed Tim starts from; it is labelled so nobody mistakes it for data;
 *   4. nothing  — the Closer says "needs minutes" and prices nothing for that service.
 *
 * Timer rows are cleaned before fitting (visitMinutes()): drive and purchase entries are
 * dropped, the same person's overlapping entries are merged, and visits whose time was
 * apportioned across a cluster of lots are left out — an allocation is not a measurement.
 *
 * Pure: no database. CloserService loads the rows.
 */
class SiteMinutesModel
{
    public const CALIBRATION_MIN = 15;
    /** A timed visit outside this range is a forgotten or fat-fingered timer. */
    public const MIN_MINUTES = 5;
    public const MAX_MINUTES = 600;

    /** Units of work for a service on a lot. Null when the lot has no measurement for it. */
    public static function units(string $service, array $lot): ?float
    {
        $unit = CloserPricing::SERVICES[$service]['unit'] ?? null;
        $v = 0.0;
        if ($unit === 'lawn')  $v = (float)($lot['lawn_sqft'] ?? 0) / 1000;
        if ($unit === 'edge')  $v = (float)($lot['edge_ft'] ?? 0) / 100;
        if ($unit === 'hedge') $v = (float)($lot['hedge_ft'] ?? 0) / 100;
        return $v > 0 ? $v : null;
    }

    /** Predicted person-minutes, or null when the model or the lot measurement is missing. */
    public static function predict(?array $model, string $service, array $lot): ?float
    {
        if (!$model) return null;
        $u = self::units($service, $lot);
        if ($u === null) return null;
        $m = (float)($model['fixed_minutes'] ?? 0)
           + (float)($model['per_unit_minutes'] ?? 0) * $u
           + (float)($model['per_obstacle_minutes'] ?? 0) * max(0, (int)($lot['obstacles'] ?? 0));
        return $m > 0 ? round($m, 1) : null;
    }

    /** Pick the model a service uses: fit (enough data) → manual → implied → none. */
    public static function choose(?array $fit, ?array $manual, ?array $implied, int $min = self::CALIBRATION_MIN): ?array
    {
        if ($fit && (int)($fit['n'] ?? 0) >= $min) return $fit + ['source' => 'fit'];
        if ($manual && ((float)($manual['fixed_minutes'] ?? 0) > 0 || (float)($manual['per_unit_minutes'] ?? 0) > 0)) {
            return $manual + ['source' => 'manual'];
        }
        if ($implied) return $implied + ['source' => 'implied'];
        return null;
    }

    /**
     * The seed: minutes the current price rule pays for at the target margin.
     * price × (1 − margin) = cost → (cost − materials) / hourly × 60 = minutes. Expressed as
     * a per-unit rate so it scales with the lot like a real model.
     */
    public static function impliedFromPrice(float $rulePrice, float $units, float $materials, float $hourly, float $margin): ?array
    {
        if ($rulePrice <= 0 || $units <= 0 || $hourly <= 0) return null;
        $minutes = ($rulePrice * (1 - $margin) - $materials) / $hourly * 60;
        if ($minutes <= 0) return null;
        return ['fixed_minutes' => 0.0, 'per_unit_minutes' => round($minutes / $units, 3), 'per_obstacle_minutes' => 0.0, 'n' => 0];
    }

    /**
     * Clean one visit's timer entries into person-minutes and wall-minutes.
     *
     * @param array $entries rows: user_id, start_time, end_time|null, duration_minutes,
     *                       time_type ('job'|'drive'|'purchase'|null), cluster_session_id, time_source
     * @return array|null    ['person_minutes', 'wall_minutes', 'crew'] or null when unusable
     */
    public static function visitMinutes(array $entries): ?array
    {
        $byUser = [];
        $all = [];
        foreach ($entries as $e) {
            $type = $e['time_type'] ?? 'job';
            if ($type !== null && $type !== '' && $type !== 'job') continue;
            $src = (string)($e['time_source'] ?? '');
            if (!empty($e['cluster_session_id']) || strpos($src, 'apportioned') !== false || $src === 'photo_inferred') {
                return null; // split across a cluster of lots: an allocation, not a measurement
            }
            $start = strtotime((string)($e['start_time'] ?? ''));
            if (!$start) continue;
            $end = !empty($e['end_time']) ? strtotime((string)$e['end_time']) : false;
            if (!$end) $end = $start + (int)($e['duration_minutes'] ?? 0) * 60;
            if ($end <= $start) continue;
            $byUser[(int)($e['user_id'] ?? 0)][] = [$start, $end];
            $all[] = [$start, $end];
        }
        if (!$all) return null;
        $person = 0.0;
        foreach ($byUser as $iv) {
            $person += self::unionSeconds($iv);
        }
        $wall = self::unionSeconds($all);
        return [
            'person_minutes' => round($person / 60, 1),
            'wall_minutes'   => round($wall / 60, 1),
            'crew'           => $wall > 0 ? max(1, (int)round($person / $wall)) : 1,
        ];
    }

    private static function unionSeconds(array $intervals): float
    {
        usort($intervals, function ($a, $b) { return $a[0] <=> $b[0]; });
        $total = 0;
        $cur = null;
        foreach ($intervals as $iv) {
            if ($cur === null) { $cur = $iv; continue; }
            if ($iv[0] <= $cur[1]) { $cur[1] = max($cur[1], $iv[1]); continue; }
            $total += $cur[1] - $cur[0];
            $cur = $iv;
        }
        if ($cur !== null) $total += $cur[1] - $cur[0];
        return (float)$total;
    }

    /** A cleaned visit is good enough to learn from. */
    public static function usable(?float $units, ?array $minutes): bool
    {
        return $units !== null && $units > 0 && $minutes !== null
            && $minutes['person_minutes'] >= self::MIN_MINUTES && $minutes['person_minutes'] <= self::MAX_MINUTES;
    }

    /**
     * Fit fixed + per_unit (+ per_obstacle when obstacles vary) by least squares.
     *
     * @param array $rows [['units' => float, 'obstacles' => int, 'minutes' => float], ...]
     * @return array ['fixed_minutes', 'per_unit_minutes', 'per_obstacle_minutes', 'n', 'mae_pct']
     */
    public static function fit(array $rows): array
    {
        $n = count($rows);
        $base = ['fixed_minutes' => 0.0, 'per_unit_minutes' => 0.0, 'per_obstacle_minutes' => 0.0, 'n' => $n, 'mae_pct' => null];
        if ($n === 0) return $base;

        $obs = array_map(function ($r) { return (int)($r['obstacles'] ?? 0); }, $rows);
        $useObs = $n >= self::CALIBRATION_MIN && count(array_unique($obs)) > 1;
        $X = [];
        $y = [];
        foreach ($rows as $r) {
            $row = [1.0, (float)$r['units']];
            if ($useObs) $row[] = (float)($r['obstacles'] ?? 0);
            $X[] = $row;
            $y[] = (float)$r['minutes'];
        }
        $beta = $n >= 3 ? self::leastSquares($X, $y) : null;
        if ($beta === null || $beta[1] <= 0 || $beta[0] < 0) {
            // Too few or degenerate: median minutes per unit, through the origin.
            $per = array_map(function ($r) { return (float)$r['minutes'] / max(0.001, (float)$r['units']); }, $rows);
            sort($per);
            $mid = intdiv(count($per), 2);
            $median = count($per) % 2 ? $per[$mid] : ($per[$mid - 1] + $per[$mid]) / 2;
            $beta = [0.0, $median, 0.0];
        }
        $model = $base;
        $model['fixed_minutes']        = round(max(0.0, $beta[0]), 2);
        $model['per_unit_minutes']     = round(max(0.0, $beta[1]), 3);
        $model['per_obstacle_minutes'] = round(max(0.0, $beta[2] ?? 0.0), 2);
        $err = 0.0;
        foreach ($rows as $r) {
            $p = $model['fixed_minutes'] + $model['per_unit_minutes'] * (float)$r['units'] + $model['per_obstacle_minutes'] * (int)($r['obstacles'] ?? 0);
            $err += abs($p - (float)$r['minutes']) / max(1.0, (float)$r['minutes']);
        }
        $model['mae_pct'] = round($err / $n * 100, 1);
        return $model;
    }

    /** Solve (XᵀX)β = Xᵀy by Gaussian elimination. Null when singular. */
    public static function leastSquares(array $X, array $y): ?array
    {
        $k = count($X[0]);
        $A = array_fill(0, $k, array_fill(0, $k + 1, 0.0));
        foreach ($X as $i => $row) {
            for ($a = 0; $a < $k; $a++) {
                for ($b = 0; $b < $k; $b++) $A[$a][$b] += $row[$a] * $row[$b];
                $A[$a][$k] += $row[$a] * $y[$i];
            }
        }
        for ($c = 0; $c < $k; $c++) {
            $p = $c;
            for ($r = $c + 1; $r < $k; $r++) if (abs($A[$r][$c]) > abs($A[$p][$c])) $p = $r;
            if (abs($A[$p][$c]) < 1e-9) return null;
            [$A[$c], $A[$p]] = [$A[$p], $A[$c]];
            for ($r = 0; $r < $k; $r++) {
                if ($r === $c) continue;
                $f = $A[$r][$c] / $A[$c][$c];
                for ($j = $c; $j <= $k; $j++) $A[$r][$j] -= $f * $A[$c][$j];
            }
        }
        $beta = [];
        for ($c = 0; $c < $k; $c++) $beta[] = $A[$c][$k] / $A[$c][$c];
        return $beta;
    }
}
