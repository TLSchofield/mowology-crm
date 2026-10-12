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
 * Global-namespace, no autoloader: require_once, then `new RouteProofService($db)`.
 * Pure rules are static and unit-tested (tests/Unit/Tracking/RouteProofServiceTest.php).
 */
declare(strict_types=1);

class RouteProofService
{
    public const DEFAULT_RADIUS_M = 150;

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

    // ── DB ──────────────────────────────────────────────────────────────────

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
     * @param int[] $visitIds
     * @return array<int, list<array>>
     */
    public function forVisits(array $visitIds): array
    {
        $visitIds = array_values(array_unique(array_filter(array_map('intval', $visitIds))));
        if (!$visitIds) return [];
        $in = implode(',', array_fill(0, count($visitIds), '?'));

        $sites = [];
        $s = $this->db->prepare("
            SELECT jv.id AS visit_id, p.id AS property_id, p.latitude, p.longitude
            FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id JOIN properties p ON p.id = jp.property_id
            WHERE jv.id IN ($in)
        ");
        $s->execute($visitIds);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sites[(int)$r['visit_id']] = ['lat' => $r['latitude'], 'lng' => $r['longitude'], 'polygon' => $this->border((int)$r['property_id'])];
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
        $out = [];
        foreach ($byVisit as $vid => $fixes) {
            $kept = self::onSite($fixes, $sites[$vid] ?? [], $radius);
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
