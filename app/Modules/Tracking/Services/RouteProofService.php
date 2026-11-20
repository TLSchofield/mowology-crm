<?php
/**
 * RouteProofService — the GPS fixes a CLIENT may see as proof of a visit.
 *
 * A fix stamped with a visit id is not proof of presence: while a timer runs, every fix the
 * crew phone records carries that visit — the fuel run, the lunch stop, the drive to the dump.
 * Showing all of them on the customer portal and visit report published where the crew went
 * on their break (found 2026-10-12). Only fixes ON the property count:
 *   - inside the property's drawn arrival border (job_geofences, zone_type 'arrival_border'), or
 *   - within the time-clock proximity radius (gps_proximity_meters, default 150 m) of the
 *     property pin.
 * Mock (simulated) locations are never proof. A property with no pin shows no fixes.
 *
 * Time window (2026-11-19): salt/snow route stops are 1–15 min and run back to back on
 * neighbouring properties, so "on the property" alone still drew the whole day's trail (every
 * fix near the stop, timer-stamped or not). Client views call forVisits(..., true), which keeps
 * only fixes inside the visit's own time-entry window (± WINDOW_PAD_S), and a visit with no
 * window shows NO map rather than a wrong one. The duration service keeps the unwindowed call.
 *
 * Global-namespace, no autoloader: require_once, then `new RouteProofService($db)`.
 * Pure rules are static and unit-tested (tests/Unit/Tracking/RouteProofServiceTest.php).
 */
declare(strict_types=1);

class RouteProofService
{
    public const DEFAULT_RADIUS_M = 150;
    public const WINDOW_PAD_S = 60;            // GPS fixes arrive up to a minute around the timer's own start/stop
    public const MAX_NO_ENTRY_WINDOW_S = 7200; // started_at→completed_at fallback only when it is a sane length

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Pure rules ──────────────────────────────────────────────────────────

    public static function distanceM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /** Ray casting; $polygon is [[lat, lng], …]. */
    public static function inPolygon(float $lat, float $lng, array $polygon): bool
    {
        $n = count($polygon);
        if ($n < 3) return false;
        $inside = false;
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = (float)$polygon[$i][0]; $yi = (float)$polygon[$i][1];
            $xj = (float)$polygon[$j][0]; $yj = (float)$polygon[$j][1];
            if ((($yi > $lng) !== ($yj > $lng)) && ($lat < ($xj - $xi) * ($lng - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                $inside = !$inside;
            }
        }
        return $inside;
    }

    /**
     * Keep only the fixes that are on the property. $site: ['lat' => ?float, 'lng' => ?float,
     * 'polygon' => ?array]. Each fix: latitude, longitude, (is_mock). PURE.
     */
    public static function onSite(array $fixes, array $site, int $radiusM): array
    {
        $lat = isset($site['lat']) && is_numeric($site['lat']) ? (float)$site['lat'] : 0.0;
        $lng = isset($site['lng']) && is_numeric($site['lng']) ? (float)$site['lng'] : 0.0;
        $poly = !empty($site['polygon']) && is_array($site['polygon']) ? $site['polygon'] : null;
        if (($lat == 0.0 && $lng == 0.0) && $poly === null) {
            return [];   // nowhere to measure from — show nothing rather than everything
        }
        $out = [];
        foreach ($fixes as $f) {
            if (!empty($f['is_mock'])) continue;
            if (!isset($f['latitude'], $f['longitude']) || !is_numeric($f['latitude']) || !is_numeric($f['longitude'])) continue;
            $fl = (float)$f['latitude'];
            $fg = (float)$f['longitude'];
            $inside = ($poly !== null && self::inPolygon($fl, $fg, $poly))
                   || (!($lat == 0.0 && $lng == 0.0) && self::distanceM($fl, $fg, $lat, $lng) <= $radiusM);
            if ($inside) {
                unset($f['is_mock']);
                $out[] = $f;
            }
        }
        return array_values($out);
    }

    /**
     * onSite() for points keyed lat/lng (visit_gps_points, the proof-of-work PDF and its map):
     * the original rows that are on the property, in order. PURE.
     */
    public static function onSiteLatLng(array $points, array $site, int $radiusM): array
    {
        $fixes = [];
        foreach (array_values($points) as $i => $p) {
            $fixes[] = ['latitude' => $p['lat'] ?? null, 'longitude' => $p['lng'] ?? null, 'is_mock' => $p['is_mock'] ?? 0, '_i' => $i];
        }
        $rows = array_values($points);
        return array_map(fn($f) => $rows[$f['_i']], self::onSite($fixes, $site, $radiusM));
    }

    /**
     * The time windows [[fromEpoch, toEpoch], …] a visit's proof may come from. PURE.
     * $entries: [['start' => 'Y-m-d H:i:s', 'end' => ?string], …] (non-void time entries; an open
     * entry runs to completion, else to $now). With no entries, the visit's own started_at →
     * completed_at counts when it is a sane length. Windows are padded and merged.
     */
    public static function windows(array $entries, ?string $startedAt, ?string $completedAt, int $now, int $pad = self::WINDOW_PAD_S): array
    {
        $raw = [];
        $done = $completedAt ? strtotime($completedAt) : false;
        foreach ($entries as $e) {
            $a = !empty($e['start']) ? strtotime((string)$e['start']) : false;
            if ($a === false) continue;
            $b = !empty($e['end']) ? strtotime((string)$e['end']) : false;
            if ($b === false) $b = $done !== false && $done >= $a ? $done : $now;
            if ($b < $a) $b = $a;
            $raw[] = [$a - $pad, $b + $pad];
        }
        if (!$raw && $startedAt && $done !== false) {
            $a = strtotime($startedAt);
            if ($a !== false && $done >= $a && ($done - $a) <= self::MAX_NO_ENTRY_WINDOW_S) {
                $raw[] = [$a - $pad, $done + $pad];
            }
        }
        usort($raw, fn($x, $y) => $x[0] <=> $y[0]);
        $merged = [];
        foreach ($raw as $w) {
            if ($merged && $w[0] <= $merged[count($merged) - 1][1]) {
                $merged[count($merged) - 1][1] = max($merged[count($merged) - 1][1], $w[1]);
            } else {
                $merged[] = $w;
            }
        }
        return $merged;
    }

    /** Keep rows whose $key time falls inside a window. No windows → nothing (hide, don't guess). PURE. */
    public static function inWindows(array $rows, array $windows, string $key = 'timestamp'): array
    {
        if (!$windows) return [];
        $out = [];
        foreach ($rows as $r) {
            $t = isset($r[$key]) ? strtotime((string)$r[$key]) : false;
            if ($t === false) continue;
            foreach ($windows as $w) {
                if ($t >= $w[0] && $t <= $w[1]) { $out[] = $r; break; }
            }
        }
        return $out;
    }

    // ── DB ──────────────────────────────────────────────────────────────────

    /** A visit's property as onSite() measures it: ['lat', 'lng', 'polygon'], or [] when unknown. */
    public function siteForVisit(int $visitId): array
    {
        $s = $this->db->prepare("
            SELECT p.id AS property_id, p.latitude, p.longitude
            FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id JOIN properties p ON p.id = jp.property_id
            WHERE jv.id = ?
        ");
        $s->execute([$visitId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ? ['lat' => $r['latitude'], 'lng' => $r['longitude'], 'polygon' => $this->border((int)$r['property_id'])] : [];
    }

    /** visit_id => windows (see windows()). */
    public function windowsForVisits(array $visitIds, ?int $now = null): array
    {
        $visitIds = array_values(array_unique(array_filter(array_map('intval', $visitIds))));
        if (!$visitIds) return [];
        $now = $now ?? time();
        $in = implode(',', array_fill(0, count($visitIds), '?'));
        $ent = [];
        $s = $this->db->prepare("SELECT visit_id, start_time, end_time FROM job_time_entries WHERE visit_id IN ($in) AND status <> 'void'");
        $s->execute($visitIds);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ent[(int)$r['visit_id']][] = ['start' => $r['start_time'], 'end' => $r['end_time']];
        }
        $out = [];
        $s = $this->db->prepare("SELECT id, started_at, completed_at FROM job_visits WHERE id IN ($in)");
        $s->execute($visitIds);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['id']] = self::windows($ent[(int)$r['id']] ?? [], $r['started_at'], $r['completed_at'], $now);
        }
        return $out;
    }

    public function radiusM(): int
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM time_clock_settings WHERE setting_key = 'gps_proximity_meters'");
            $s->execute();
            $v = (int)$s->fetchColumn();
            return $v > 0 ? $v : self::DEFAULT_RADIUS_M;
        } catch (Throwable $e) {
            return self::DEFAULT_RADIUS_M;
        }
    }

    /**
     * On-property fixes for each visit, oldest first: visit_id => [ {latitude, longitude, timestamp}, … ].
     * Visits with nothing on site are absent.
     *
     * $timeWindow = true (every client-facing view): only fixes inside the visit's time-entry
     * window; a visit with no window is absent, so no map is drawn.
     *
     * @param int[] $visitIds
     * @return array<int, list<array>>
     */
    public function forVisits(array $visitIds, bool $timeWindow = false): array
    {
        $visitIds = array_values(array_unique(array_filter(array_map('intval', $visitIds))));
        if (!$visitIds) return [];
        $in = implode(',', array_fill(0, count($visitIds), '?'));

        $sites = [];
        $s = $this->db->prepare("
            SELECT jv.id AS visit_id, p.id AS property_id, p.latitude, p.longitude,
                   DATE(COALESCE(jv.completed_at, jv.scheduled_date)) AS day
            FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id JOIN properties p ON p.id = jp.property_id
            WHERE jv.id IN ($in)
        ");
        $s->execute($visitIds);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sites[(int)$r['visit_id']] = ['lat' => $r['latitude'], 'lng' => $r['longitude'], 'polygon' => $this->border((int)$r['property_id']),
                                           'day' => $r['day']];
        }

        $s = $this->db->prepare("
            SELECT visit_id, latitude, longitude, timestamp, is_mock
            FROM crew_location_history
            WHERE visit_id IN ($in)
            ORDER BY visit_id ASC, timestamp ASC
        ");
        $s->execute($visitIds);
        $byVisit = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $byVisit[(int)$f['visit_id']][] = $f;
        }

        $radius = $this->radiusM();
        $windows = $timeWindow ? $this->windowsForVisits($visitIds) : [];
        if ($timeWindow) {
            foreach ($byVisit as $vid => $fixes) $byVisit[$vid] = self::inWindows($fixes, $windows[$vid] ?? []);
        }
        $out = [];
        foreach ($byVisit as $vid => $fixes) {
            $kept = self::onSite($fixes, $sites[$vid] ?? [], $radius);
            if ($kept) $out[$vid] = $kept;
        }
        // No timer ran (the truck tablet never starts one), or the visit was recorded afterwards:
        // the fixes ON the property that day are still proof the crew was there — same on-site
        // rule, never the fuel stop or the lunch. Terry Baird's cuts showed "GPS data not
        // available" although the tablet sat on her lawn 13:01–13:13 on 9 Oct (2026-10-13).
        foreach ($sites as $vid => $site) {
            if ($timeWindow && empty($windows[$vid])) continue;
            if (isset($out[$vid]) || empty($site['day']) || !is_numeric($site['lat']) || !is_numeric($site['lng'])) continue;
            $lat = (float)$site['lat']; $lng = (float)$site['lng'];
            $dLat = ($radius + 50) / 111320; $dLng = ($radius + 50) / (111320 * max(0.2, cos(deg2rad($lat))));
            $f = $this->db->prepare("
                SELECT ? AS visit_id, latitude, longitude, timestamp, is_mock
                FROM crew_location_history
                WHERE timestamp >= ? AND timestamp < DATE_ADD(?, INTERVAL 1 DAY)
                  AND latitude BETWEEN ? AND ? AND longitude BETWEEN ? AND ?
                ORDER BY timestamp ASC
                LIMIT 500
            ");
            $f->execute([$vid, $site['day'], $site['day'], $lat - $dLat, $lat + $dLat, $lng - $dLng, $lng + $dLng]);
            $rows = $f->fetchAll(PDO::FETCH_ASSOC);
            if ($timeWindow) $rows = self::inWindows($rows, $windows[$vid]);
            $kept = self::onSite($rows, $site, $radius);
            if ($kept) $out[$vid] = $kept;
        }
        return $out;
    }

    /** The property's drawn arrival border, [[lat, lng], …], or null. */
    private function border(int $propertyId): ?array
    {
        if ($propertyId <= 0) return null;
        try {
            $s = $this->db->prepare("SELECT polygon_json FROM job_geofences WHERE property_id = ? AND zone_type = 'arrival_border' ORDER BY drawn_at DESC LIMIT 1");
            $s->execute([$propertyId]);
            $json = $s->fetchColumn();
            $poly = $json ? json_decode((string)$json, true) : null;
            return is_array($poly) && count($poly) >= 3 ? $poly : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
