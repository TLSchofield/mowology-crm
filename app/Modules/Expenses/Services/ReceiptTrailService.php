<?php
/**
 * ReceiptTrailService — which job a purchase was for, from where the crew actually went.
 *
 * Materials are almost never carried for two days (Tim, 2026-10-05): a bag of mulch
 * bought at 08:05 goes to the first property the truck stops at afterwards, that same
 * day. The planned schedule is a guess; the trail is what happened. Three signals,
 * from the purchase to the end of that day:
 *   - visits actually started (job_visits.started_at)          — strongest
 *   - where the truck stopped (vehicle_location_pings, Trackimo)
 *   - where the purchaser's phone stopped (crew_location_history, office pings excluded)
 * A stop is ≥ STOP_MIN_SECONDS within STOP_RADIUS_M; it's matched to the nearest
 * property within PROPERTY_RADIUS_M, and the property to its job plan.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class ReceiptTrailService
{
    public const STOP_RADIUS_M     = 120;
    public const STOP_MIN_SECONDS  = 300;
    public const PROPERTY_RADIUS_M = 200;
    /** How long after the purchase the materials could plausibly be used. */
    public const WINDOW_HOURS      = 12;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Jobs the purchase was probably for, earliest arrival first.
     *
     * @param string   $purchaseAt 'Y-m-d H:i[:s]' — the printed time, or the start of the day
     * @param int|null $userId     who bought it (their phone trail)
     * @return list<array{plan_id: int, property_id: int, job: string, arrived_at: string, minutes_after: int, sources: string[], why: string}>
     */
    public function candidates(string $purchaseAt, ?int $userId): array
    {
        $from = strtotime($purchaseAt);
        if ($from === false) {
            return [];
        }
        $endOfDay = strtotime(date('Y-m-d 23:59:59', $from));
        $to = min($endOfDay, $from + self::WINDOW_HOURS * 3600);
        $fromS = date('Y-m-d H:i:s', $from);
        $toS = date('Y-m-d H:i:s', $to);

        $hits = [];   // plan_id => candidate
        $add = function (int $planId, int $propertyId, string $job, int $arrived, string $source) use (&$hits, $from) {
            if (!isset($hits[$planId]) || $arrived < $hits[$planId]['_t']) {
                $hits[$planId] = ($hits[$planId] ?? []) + ['plan_id' => $planId, 'property_id' => $propertyId, 'job' => $job, 'sources' => []];
                $hits[$planId]['_t'] = $arrived;
            }
            if (!in_array($source, $hits[$planId]['sources'], true)) {
                $hits[$planId]['sources'][] = $source;
            }
        };

        // 1. Visits actually started after the purchase.
        try {
            $v = $this->db->prepare("
                SELECT jv.plan_id, jp.property_id, jv.started_at,
                       CONCAT(COALESCE(jp.title, jp.service_type, 'Job'), ' — ', COALESCE(p.address, '')) AS job
                FROM job_visits jv
                JOIN job_plans jp ON jp.id = jv.plan_id
                LEFT JOIN properties p ON p.id = jp.property_id
                WHERE jv.started_at BETWEEN ? AND ?
                ORDER BY jv.started_at
                LIMIT 12
            ");
            $v->execute([$fromS, $toS]);
            foreach ($v->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $add((int)$r['plan_id'], (int)$r['property_id'], (string)$r['job'], (int)strtotime($r['started_at']), 'visit');
            }
        } catch (Throwable $e) {
            error_log('ReceiptTrail visits: ' . $e->getMessage());
        }

        // 2. Where the truck stopped. 3. Where the purchaser's phone stopped.
        $trails = [
            'truck' => "SELECT lat, lng, recorded_at AS t FROM vehicle_location_pings WHERE recorded_at BETWEEN ? AND ? ORDER BY recorded_at",
        ];
        $params = ['truck' => [$fromS, $toS]];
        if ($userId) {
            $trails['crew'] = "SELECT latitude AS lat, longitude AS lng, `timestamp` AS t FROM crew_location_history
                               WHERE crew_id = ? AND `timestamp` BETWEEN ? AND ? AND COALESCE(is_office, 0) = 0 ORDER BY `timestamp`";
            $params['crew'] = [$userId, $fromS, $toS];
        }
        foreach ($trails as $source => $sql) {
            try {
                $s = $this->db->prepare($sql);
                $s->execute($params[$source]);
                $points = array_map(fn($r) => ['lat' => (float)$r['lat'], 'lng' => (float)$r['lng'], 't' => (int)strtotime($r['t'])],
                    $s->fetchAll(PDO::FETCH_ASSOC));
                foreach (self::detectStops($points) as $stop) {
                    $job = $this->jobAt($stop['lat'], $stop['lng']);
                    if ($job) {
                        $add($job['plan_id'], $job['property_id'], $job['job'], $stop['start'], $source);
                    }
                }
            } catch (Throwable $e) {
                error_log("ReceiptTrail {$source}: " . $e->getMessage());
            }
        }

        $out = array_values($hits);
        usort($out, fn($a, $b) => $a['_t'] <=> $b['_t']);
        foreach ($out as &$c) {
            $c['arrived_at'] = date('Y-m-d H:i:s', $c['_t']);
            $c['minutes_after'] = (int)round(($c['_t'] - $from) / 60);
            $c['why'] = self::reason($c['sources'], $c['_t'], $c['minutes_after']);
            unset($c['_t']);
        }
        unset($c);
        return array_slice($out, 0, 4);
    }

    /** The job plan at the nearest property within PROPERTY_RADIUS_M, if any. */
    private function jobAt(float $lat, float $lng): ?array
    {
        $dLat = 0.0025;
        $dLng = 0.0025 / max(0.2, cos(deg2rad($lat)));
        $s = $this->db->prepare("
            SELECT p.id AS property_id, p.latitude, p.longitude, p.address,
                   (SELECT jp.id FROM job_plans jp WHERE jp.property_id = p.id ORDER BY jp.status = 'active' DESC, jp.id DESC LIMIT 1) AS plan_id,
                   (SELECT COALESCE(jp.title, jp.service_type) FROM job_plans jp WHERE jp.property_id = p.id ORDER BY jp.status = 'active' DESC, jp.id DESC LIMIT 1) AS title
            FROM properties p
            WHERE p.latitude BETWEEN ? AND ? AND p.longitude BETWEEN ? AND ?
        ");
        $s->execute([$lat - $dLat, $lat + $dLat, $lng - $dLng, $lng + $dLng]);
        $best = self::nearestWithin($s->fetchAll(PDO::FETCH_ASSOC), $lat, $lng, self::PROPERTY_RADIUS_M);
        if (!$best || empty($best['plan_id'])) {
            return null;
        }
        return [
            'plan_id'     => (int)$best['plan_id'],
            'property_id' => (int)$best['property_id'],
            'job'         => trim(($best['title'] ?: 'Job') . ' — ' . ($best['address'] ?? '')),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure helpers (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    public static function meters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $r * asin(min(1, sqrt($a)));
    }

    /**
     * Places the trail stayed: consecutive points within STOP_RADIUS_M of the first
     * point of the group, spanning ≥ STOP_MIN_SECONDS. Points sorted by time.
     *
     * @param list<array{lat: float, lng: float, t: int}> $points
     * @return list<array{lat: float, lng: float, start: int, end: int}>
     */
    public static function detectStops(array $points, float $radius = self::STOP_RADIUS_M, int $minSeconds = self::STOP_MIN_SECONDS): array
    {
        $stops = [];
        $n = count($points);
        $i = 0;
        while ($i < $n) {
            $j = $i;
            $sumLat = 0.0;
            $sumLng = 0.0;
            while ($j < $n && self::meters($points[$i]['lat'], $points[$i]['lng'], $points[$j]['lat'], $points[$j]['lng']) <= $radius) {
                $sumLat += $points[$j]['lat'];
                $sumLng += $points[$j]['lng'];
                $j++;
            }
            $span = $points[$j - 1]['t'] - $points[$i]['t'];
            if ($j - $i >= 2 && $span >= $minSeconds) {
                $k = $j - $i;
                $stops[] = ['lat' => $sumLat / $k, 'lng' => $sumLng / $k, 'start' => $points[$i]['t'], 'end' => $points[$j - 1]['t']];
                $i = $j;
            } else {
                $i++;
            }
        }
        return $stops;
    }

    /** Nearest row (with latitude/longitude) within $maxMeters, with 'meters' added. */
    public static function nearestWithin(array $rows, float $lat, float $lng, float $maxMeters): ?array
    {
        $best = null;
        foreach ($rows as $r) {
            if (!is_numeric($r['latitude'] ?? null) || !is_numeric($r['longitude'] ?? null)) continue;
            $m = self::meters($lat, $lng, (float)$r['latitude'], (float)$r['longitude']);
            if ($m <= $maxMeters && ($best === null || $m < $best['meters'])) {
                $best = $r + ['meters' => $m];
            }
        }
        return $best;
    }

    public static function reason(array $sources, int $t, int $minutesAfter): string
    {
        $when = date('H:i', $t) . ', ' . ($minutesAfter < 60 ? $minutesAfter . ' min' : round($minutesAfter / 60, 1) . ' h') . ' after the purchase';
        if (in_array('visit', $sources, true)) {
            $lead = 'Crew started this visit at ' . $when;
        } elseif (in_array('truck', $sources, true)) {
            $lead = 'Truck stopped here at ' . $when;
        } else {
            $lead = 'Your phone stopped here at ' . $when;
        }
        $also = array_diff($sources, [in_array('visit', $sources, true) ? 'visit' : (in_array('truck', $sources, true) ? 'truck' : 'crew')]);
        $names = ['visit' => 'visit start', 'truck' => 'truck GPS', 'crew' => 'phone GPS'];
        return $lead . ($also ? ' (also ' . implode(', ', array_map(fn($s) => $names[$s], $also)) . ')' : '');
    }
}
