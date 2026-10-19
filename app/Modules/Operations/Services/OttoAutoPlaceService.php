<?php
/**
 * OttoAutoPlaceService — Otto pins client properties and gives them an arrival border by himself
 * (owner, 2026-10-18: "is it possible for otto to geocode?" / "can we do the arrival border
 * automatically? there are city lot maps").
 *
 *   pins     properties with no pin → address lookup (SupplierDirectoryService::googleGeocode:
 *            Google when a server key exists, else OpenStreetMap). Saved ONLY when the match is the
 *            building itself (exact); an approximate (street / area) match is never saved — the
 *            Geocode button stays for a person. Never moves an existing pin (re-checked in the UPDATE).
 *   borders  pinned properties with no arrival border, or only Otto's ±25 m placeholder square →
 *            the lot from ParcelMap BC (ParcelLotService), grown BUFFER_M so a crew parked at the
 *            kerb still counts. border_source 'parcel'. A drawn, crew-GPS or kept border is never
 *            touched (re-checked in the INSERT / UPDATE).
 *
 * Off until ops_settings otto_auto_place = '1'. Runs every 6 hours from generate_visits (owner, 2026-10-18),
 * a few properties a run (OpenStreetMap's policy: ≤ 1 request a second, no bulk). Never at page load.
 * What was tried and what Otto placed is kept in ops_settings otto_auto_place_state (JSON), so a
 * property that failed is retried after RETRY_DAYS, not every run (the province's server being down: next run).
 *
 * Candidates: Otto's active properties (PropertyBorderService::activeProperties) plus properties
 * on a quote from the last 12 months that is not declined / expired.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/PropertyBorderRules.php';
require_once __DIR__ . '/PropertyReadinessService.php';
require_once __DIR__ . '/PropertyBorderService.php';
require_once __DIR__ . '/ParcelLotService.php';
require_once __DIR__ . '/SupplierDirectoryService.php';

class OttoAutoPlaceService
{
    public const SWITCH_KEY = 'otto_auto_place';
    public const STATE_KEY = 'otto_auto_place_state';
    public const RETRY_DAYS = 30;
    public const BUFFER_M = 10;

    private PDO $db;
    /** @var callable(string): ?array */
    private $geocode;
    private ParcelLotService $lots;
    private string $today;

    public function __construct(PDO $db, ?callable $geocode = null, ?ParcelLotService $lots = null, ?string $today = null)
    {
        $this->db = $db;
        $this->geocode = $geocode ?? static fn(string $address): ?array => SupplierDirectoryService::googleGeocode($address);
        $this->lots = $lots ?? new ParcelLotService();
        $this->today = $today ?? date('Y-m-d');
    }

    public function enabled(): bool
    {
        try {
            $s = $this->db->prepare('SELECT setting_value FROM ops_settings WHERE setting_key = ?');
            $s->execute([self::SWITCH_KEY]);
            return (string)$s->fetchColumn() === '1';
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * One pass: up to $limit pins, then up to $limit borders.
     * @return array{ran: bool, pinned: int, approximate: int, not_found: int, bordered: int, no_lot: int, message: string}
     */
    public function run(int $limit = 15, bool $force = false): array
    {
        $r = ['ran' => false, 'pinned' => 0, 'approximate' => 0, 'not_found' => 0, 'bordered' => 0, 'no_lot' => 0, 'message' => 'Switched off'];
        if (!$force && !$this->enabled()) return $r;
        if (!$this->lock()) return ['message' => 'Another run is placing pins right now'] + $r;
        try {
            return $this->runLocked($limit, $r);
        } finally {
            $this->unlock();
        }
    }

    private function runLocked(int $limit, array $r): array
    {
        $r['ran'] = true;
        $state = $this->prune($this->state());
        $props = $this->candidates();

        $n = 0;
        foreach ($props as $pid => $p) {
            if ($n >= $limit) break;
            if (PropertyBorderRules::pinOf($p) || self::recentlyTried($state['pin_tried'][$pid] ?? null, $this->today)) continue;
            $address = PropertyReadinessService::geocodeAddress($p);
            if (trim($address) === '') continue;
            $n++;
            $hit = ($this->geocode)($address);
            $decision = self::pinDecision($hit);
            if ($decision === 'save' && $this->savePinIfMissing($pid, (float)$hit['lat'], (float)$hit['lng'])) {
                $r['pinned']++;
                $state['pins'][$pid] = ['by' => (string)($hit['by'] ?? ''), 'at' => $this->today];
                $props[$pid]['latitude'] = $hit['lat'];
                $props[$pid]['longitude'] = $hit['lng'];
                unset($state['pin_tried'][$pid]);
            } else {
                $r[$decision === 'approximate' ? 'approximate' : 'not_found']++;
                $state['pin_tried'][$pid] = $this->today;
            }
        }

        $borders = (new PropertyBorderService($this->db, $this->today))->borders();
        $n = 0;
        foreach ($props as $pid => $p) {
            if ($n >= $limit) break;
            $pin = PropertyBorderRules::pinOf($p);
            if (!$pin) continue;
            $mode = self::borderMode($borders[$pid]['sources'] ?? null);
            if ($mode === null || self::recentlyTried($state['border_tried'][$pid] ?? null, $this->today)) continue;
            $n++;
            $lot = $this->lots->lotAt($pin[0], $pin[1]);
            if (!$lot['ok'] && in_array($lot['reason'], ['no_lot', 'near_edge', 'ambiguous'], true)) {
                // Pins from the old Geocode button often sit on the street in front of the house:
                // find the building from the address and use the lot under it — the pin stays put.
                $at = self::buildingPoint(($this->geocode)(PropertyReadinessService::geocodeAddress($p)), $pin);
                if ($at) $lot = $this->lots->lotAt($at[0], $at[1]);
            }
            if (!$lot['ok']) {
                $r['no_lot']++;
                // The province's server being down says nothing about the lot: try again next run.
                if ($lot['reason'] !== 'unavailable') $state['border_tried'][$pid] = $this->today;
                $state['border_reason'][$pid] = (string)$lot['reason'];
                continue;
            }
            unset($state['border_reason'][$pid]);
            if ($this->writeBorder($pid, $mode, $lot)) {
                $r['bordered']++;
                unset($state['border_tried'][$pid]);
            }
        }

        $this->saveState($state);
        $r['message'] = "{$r['pinned']} pinned ({$r['approximate']} only approximate, {$r['not_found']} not found), "
                      . "{$r['bordered']} lot border(s) ({$r['no_lot']} with no usable lot)";
        return $r;
    }

    /** One run at a time (cron and a manual web run): MySQL GET_LOCK; other drivers (tests) always get it. */
    private function lock(): bool
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return true;
        try {
            return (int)$this->db->query("SELECT GET_LOCK('otto_auto_place', 0)")->fetchColumn() === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function unlock(): void
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
        try {
            $this->db->query("SELECT RELEASE_LOCK('otto_auto_place')");
        } catch (Throwable $e) { /* released with the connection anyway */ }
    }

    /** Drop retry markers past their retry date — they are only there to space retries out. */
    private function prune(array $state): array
    {
        foreach (['pin_tried', 'border_tried'] as $k) {
            foreach ($state[$k] as $id => $date) {
                if (!self::recentlyTried((string)$date, $this->today)) unset($state[$k][$id]);
            }
        }
        return $state;
    }

    /** Did Otto place this property's pin? ['by' => 'google'|'osm', 'at' => Y-m-d] or null. */
    public function placedPin(int $propertyId): ?array
    {
        return $this->state()['pins'][$propertyId] ?? null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure
    // ─────────────────────────────────────────────────────────────────────────

    /** 'save' | 'approximate' | 'none' */
    public static function pinDecision(?array $hit): string
    {
        if (!$hit || !isset($hit['lat'], $hit['lng']) || !PropertyReadinessService::validPin((float)$hit['lat'], (float)$hit['lng'])) return 'none';
        return !empty($hit['exact']) ? 'save' : 'approximate';
    }

    /**
     * 'insert' when there is no arrival border, 'replace' when the only border is Otto's placeholder
     * square, null when someone drew, kept or Otto measured (crew GPS / lot) one.
     * @param string[]|null $sources border_source of each arrival border (null = drawn)
     */
    public static function borderMode(?array $sources): ?string
    {
        if (!$sources) return 'insert';
        foreach ($sources as $s) {
            if ($s !== 'default_square') return null;
        }
        return 'replace';
    }

    public static function recentlyTried(?string $date, string $today): bool
    {
        return $date !== null && $date !== '' && strtotime($date) > strtotime($today . ' -' . self::RETRY_DAYS . ' days');
    }

    /** Max metres between the pin and the building the address names, for its lot to count as this property's. */
    public const BUILDING_MAX_M = 80;

    /** The building point from an exact address match, if it is near the pin; else null. */
    public static function buildingPoint(?array $hit, array $pin): ?array
    {
        if (self::pinDecision($hit) !== 'save') return null;
        $at = [(float)$hit['lat'], (float)$hit['lng']];
        return PropertyBorderRules::meters($pin[0], $pin[1], $at[0], $at[1]) <= self::BUILDING_MAX_M ? $at : null;
    }

    /**
     * The stored border: closed ring of [lat, lng]. A near-convex lot is grown BUFFER_M (a crew at the
     * kerb counts); buffer() re-hulls, so an L- or U-shaped lot would swallow a neighbour's corner —
     * that lot is stored exactly as surveyed instead (Codex review 2026-10-18).
     */
    public static function borderRing(array $lotRing): array
    {
        $ring = self::nearConvex($lotRing) ? PropertyBorderRules::buffer($lotRing, self::BUFFER_M) : $lotRing;
        $ring[] = $ring[0];
        return $ring;
    }

    public const CONVEX_TOLERANCE = 1.08;

    public static function nearConvex(array $ring): bool
    {
        $area = PropertyBorderRules::area($ring);
        return $area > 0 && PropertyBorderRules::area(PropertyBorderRules::convexHull($ring)) <= $area * self::CONVEX_TOLERANCE;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data
    // ─────────────────────────────────────────────────────────────────────────

    private function candidates(): array
    {
        $props = (new PropertyBorderService($this->db, $this->today))->activeProperties();
        try {
            $since = date('Y-m-d', strtotime($this->today . ' -12 months'));
            $s = $this->db->prepare("
                SELECT p.id, p.address, p.city, p.province, p.postal_code, p.latitude, p.longitude
                FROM properties p
                WHERE p.id IN (SELECT q.property_id FROM quotes q
                               WHERE q.status IN ('draft', 'sent', 'viewed', 'accepted') AND q.created_at >= ?)
                ORDER BY p.id
            ");
            $s->execute([$since]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) $props[(int)$row['id']] ??= $row;
        } catch (Throwable $e) { /* quotes unreadable: active properties only */ }
        ksort($props);
        return $props;
    }

    private function savePinIfMissing(int $propertyId, float $lat, float $lng): bool
    {
        $s = $this->db->prepare("UPDATE properties SET latitude = ?, longitude = ?, geocoded_at = CURRENT_TIMESTAMP
                                 WHERE id = ? AND (latitude IS NULL OR latitude = 0 OR longitude IS NULL OR longitude = 0)");
        $s->execute([$lat, $lng, $propertyId]);
        return $s->rowCount() > 0;
    }

    private function writeBorder(int $propertyId, string $mode, array $lot): bool
    {
        $closed = self::borderRing($lot['ring']);
        $open = array_slice($closed, 0, -1);
        [$a, $b, $c, $d] = PropertyBorderRules::bbox($open);
        $area = round(PropertyBorderRules::area($open), 2);
        $label = 'Lot border (city lot map)';
        $notes = 'Otto: ' . (isset($lot['nearest_m']) ? 'pin is ' . $lot['nearest_m'] . ' m outside — nearest lot, ' : '') . ($lot['class'] ?: 'lot') . ($lot['plan'] ? ' ' . $lot['plan'] : '') . ($lot['pid'] ? ', PID ' . $lot['pid'] : '')
               . ', ' . (int)$lot['area_sqm'] . ' m², +' . self::BUFFER_M . ' m. ' . ParcelLotService::ATTRIBUTION;
        if ($mode === 'replace') {
            $s = $this->db->prepare("
                UPDATE job_geofences SET border_source = 'parcel', polygon_json = ?, vertex_count = ?, bbox_lat_min = ?, bbox_lat_max = ?,
                       bbox_lng_min = ?, bbox_lng_max = ?, area_sqm = ?, label = ?, notes = ?
                WHERE property_id = ? AND zone_type = 'arrival_border' AND border_source = 'default_square'");
            $s->execute([json_encode($closed), count($open), $a, $b, $c, $d, $area, $label, $notes, $propertyId]);
            return $s->rowCount() > 0;
        }
        // NOT EXISTS in the INSERT itself: a border drawn since this run started is never doubled.
        $s = $this->db->prepare("
            INSERT INTO job_geofences
                (plan_id, property_id, zone_type, border_source, polygon_json, vertex_count,
                 bbox_lat_min, bbox_lat_max, bbox_lng_min, bbox_lng_max, area_sqm, label, notes, drawn_by)
            SELECT NULL, ?, 'arrival_border', 'parcel', ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL
            FROM properties pr
            WHERE pr.id = ?
              AND NOT EXISTS (SELECT 1 FROM job_geofences g WHERE g.property_id = ? AND g.zone_type = 'arrival_border')");
        $s->execute([$propertyId, json_encode($closed), count($open), $a, $b, $c, $d, $area, $label, $notes, $propertyId, $propertyId]);
        return $s->rowCount() > 0;
    }

    /** @return array{pins: array, pin_tried: array, border_tried: array, border_reason: array} */
    private function state(): array
    {
        $empty = ['pins' => [], 'pin_tried' => [], 'border_tried' => [], 'border_reason' => []];
        try {
            $s = $this->db->prepare('SELECT setting_value FROM ops_settings WHERE setting_key = ?');
            $s->execute([self::STATE_KEY]);
            $j = json_decode((string)$s->fetchColumn(), true);
        } catch (Throwable $e) {
            return $empty;
        }
        if (!is_array($j)) return $empty;
        $out = $empty;
        foreach ($empty as $k => $_) {
            foreach ((array)($j[$k] ?? []) as $id => $v) $out[$k][(int)$id] = $v;
        }
        return $out;
    }

    private function saveState(array $state): void
    {
        $json = json_encode($state);
        try {
            $u = $this->db->prepare('UPDATE ops_settings SET setting_value = ? WHERE setting_key = ?');
            $u->execute([$json, self::STATE_KEY]);
            if ($u->rowCount() === 0) {
                $chk = $this->db->prepare('SELECT 1 FROM ops_settings WHERE setting_key = ?');
                $chk->execute([self::STATE_KEY]);
                if ($chk->fetchColumn() === false) {
                    $this->db->prepare('INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, ?)')
                        ->execute([self::STATE_KEY, $json, 'Otto auto pins + lot borders: what he placed and what he tried (OttoAutoPlaceService)']);
                }
            }
        } catch (Throwable $e) {
            error_log('Otto auto-place state: ' . get_class($e));
        }
    }
}
