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
    public function candidates(string $purchaseAt, ?int $userId, int $windowHours = self::WINDOW_HOURS): array
    {
        $from = strtotime($purchaseAt);
        if ($from === false) {
            return [];
        }
        $endOfDay = strtotime(date('Y-m-d 23:59:59', $from));
        $to = min($endOfDay, $from + max(1, $windowHours) * 3600);
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

    // ─────────────────────────────────────────────────────────────────────────
    // Which job a receipt was for — evidence in order (2026-10-07, expense #412)
    // ─────────────────────────────────────────────────────────────────────────

    /** A receipt photo this close to a client property was taken at that job. */
    public const PHOTO_RADIUS_M = 150;
    /** No printed time: only the truck's next client stop within this of the photo counts. */
    public const NEXT_STOP_HOURS = 3;
    /** Work hours. A time outside them is said out loud, never used silently. */
    public const DAY_START = '06:00';
    public const DAY_END   = '21:00';

    /**
     * Which evidence to use, strongest first. Pure.
     *   printed — the printed purchase time → where the truck went next that day (12 h);
     *   photo   — no printed time, photo taken the SAME day: the photo's GPS at a client
     *             property, else the truck's next client stop within NEXT_STOP_HOURS of it;
     *   none    — no printed time and the photo was taken another day: no trail guess
     *             (never "the start of the day" — the old 05:00 — and never another day).
     * @return array{tier: string, at: ?string, window: int, note: string}
     */
    public static function evidencePlan(?string $date, ?string $printedTime, ?string $createdAt): array
    {
        $none = ['tier' => 'none', 'at' => null, 'window' => 0, 'note' => ''];
        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', substr($date, 0, 10))) return $none;
        $date = substr($date, 0, 10);
        if ($printedTime && preg_match('/^(\d{1,2}):(\d{2})/', $printedTime, $m)) {
            $hm = sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
            return ['tier' => 'printed', 'at' => $date . ' ' . $hm . ':00', 'window' => self::WINDOW_HOURS,
                    'note' => self::inHours($hm) ? '' : "printed time {$hm} is outside " . self::DAY_START . '–' . self::DAY_END . ' — check it'];
        }
        if ($createdAt && substr($createdAt, 0, 10) === $date && strtotime($createdAt) !== false) {
            $hm = date('H:i', strtotime($createdAt));
            return ['tier' => 'photo', 'at' => date('Y-m-d H:i:s', strtotime($createdAt)), 'window' => self::NEXT_STOP_HOURS,
                    'note' => self::inHours($hm) ? '' : "photo taken at {$hm}, outside " . self::DAY_START . '–' . self::DAY_END . ' — check it'];
        }
        return $none;
    }

    public static function inHours(string $hm): bool
    {
        $hm = substr($hm, 0, 5);
        return $hm >= self::DAY_START && $hm <= self::DAY_END;
    }

    /**
     * Jobs a receipt was probably for, by evidencePlan(). $e is the expenses row (id, created_by,
     * created_at, receipt_lat, receipt_lng). A printed time stored in receipt_facts (migration
     * 1227) beats the one passed in. Same shape as candidates().
     */
    public function forReceipt(array $e, ?string $date, ?string $printedTime): array
    {
        if (!empty($e['id'])) {
            try {
                require_once __DIR__ . '/ReceiptFactsService.php';
                $f = (new ReceiptFactsService($this->db))->forExpense((int)$e['id']);
                if (!empty($f['time_first'])) $printedTime = $f['time_first'];
            } catch (Throwable $ex) { /* facts are a bonus */ }
        }
        $plan = self::evidencePlan($date, $printedTime, $e['created_at'] ?? null);
        $userId = (int)($e['created_by'] ?? 0) ?: null;
        $flag = function (array $list, string $lead) use ($plan): array {
            foreach ($list as &$c) {
                $c['why'] = $lead . $c['why'];
                if ($plan['note'] !== '') $c['why'] .= ' — ' . $plan['note'];
                $arr = substr((string)$c['arrived_at'], 11, 5);
                if (!self::inHours($arr)) $c['why'] .= " — arrival {$arr} is outside " . self::DAY_START . '–' . self::DAY_END . ', check it';
            }
            unset($c);
            return $list;
        };
        if ($plan['tier'] === 'printed') {
            return $flag($this->candidates($plan['at'], $userId, $plan['window']), 'Printed time ' . substr($plan['at'], 11, 5) . ': ');
        }
        if ($plan['tier'] !== 'photo') return [];

        $lat = is_numeric($e['receipt_lat'] ?? null) && (float)$e['receipt_lat'] != 0.0 ? (float)$e['receipt_lat'] : null;
        $lng = is_numeric($e['receipt_lng'] ?? null) && (float)$e['receipt_lng'] != 0.0 ? (float)$e['receipt_lng'] : null;
        if ($lat !== null && $lng !== null) {
            $job = $this->jobAtPhoto($lat, $lng, substr((string)$date, 0, 10));
            if ($job) {
                return [[
                    'plan_id' => $job['plan_id'], 'property_id' => $job['property_id'], 'job' => $job['job'],
                    'arrived_at' => $plan['at'], 'minutes_after' => 0, 'sources' => ['photo'],
                    'why' => 'Receipt photographed at this property (' . (int)round($job['meters']) . ' m) at ' . substr($plan['at'], 11, 5)
                           . ($job['visit_today'] ? ', with a visit there that day' : '') . "; the purchase time isn't printed"
                           . ($plan['note'] !== '' ? ' — ' . $plan['note'] : ''),
                ]];
            }
        }
        return $flag($this->candidates($plan['at'], $userId, $plan['window']),
                     'No printed time; next client stop within ' . self::NEXT_STOP_HOURS . ' h of the photo (' . substr($plan['at'], 11, 5) . '): ');
    }

    /** The client property the photo was taken at (within PHOTO_RADIUS_M); a plan with a visit that day first. */
    private function jobAtPhoto(float $lat, float $lng, string $date): ?array
    {
        $dLat = 0.002;
        $dLng = 0.002 / max(0.2, cos(deg2rad($lat)));
        $s = $this->db->prepare("
            SELECT p.id AS property_id, p.latitude, p.longitude, p.address,
                   (SELECT jv.plan_id FROM job_visits jv JOIN job_plans jp2 ON jp2.id = jv.plan_id
                     WHERE jp2.property_id = p.id AND (jv.scheduled_date = ? OR DATE(jv.started_at) = ?) LIMIT 1) AS visit_plan_id,
                   (SELECT jp.id FROM job_plans jp WHERE jp.property_id = p.id ORDER BY jp.status = 'active' DESC, jp.id DESC LIMIT 1) AS plan_id
            FROM properties p
            WHERE p.latitude BETWEEN ? AND ? AND p.longitude BETWEEN ? AND ?
        ");
        $s->execute([$date, $date, $lat - $dLat, $lat + $dLat, $lng - $dLng, $lng + $dLng]);
        $best = null;
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!is_numeric($r['latitude'] ?? null) || !is_numeric($r['longitude'] ?? null)) continue;
            $planId = (int)($r['visit_plan_id'] ?: $r['plan_id']);
            if (!$planId) continue;
            $m = self::meters($lat, $lng, (float)$r['latitude'], (float)$r['longitude']);
            if ($m > self::PHOTO_RADIUS_M) continue;
            $rank = [$r['visit_plan_id'] ? 0 : 1, $m];
            if ($best === null || $rank < $best['_rank']) {
                $best = ['plan_id' => $planId, 'property_id' => (int)$r['property_id'], 'address' => (string)($r['address'] ?? ''),
                         'meters' => $m, 'visit_today' => (bool)$r['visit_plan_id'], '_rank' => $rank];
            }
        }
        if (!$best) return null;
        $t = $this->db->prepare("SELECT COALESCE(title, service_type) FROM job_plans WHERE id = ?");
        $t->execute([$best['plan_id']]);
        $best['job'] = trim(((string)$t->fetchColumn() ?: 'Job') . ' — ' . $best['address']);
        unset($best['_rank']);
        return $best;
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
