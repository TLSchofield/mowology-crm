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
require_once __DIR__ . '/CityParcelService.php';
require_once __DIR__ . '/SupplierDirectoryService.php';

class OttoAutoPlaceService
{
    public const SWITCH_KEY = 'otto_auto_place';
    public const STATE_KEY = 'otto_auto_place_state';
    public const RETRY_DAYS = 30;
    public const BUFFER_M = 10;
    public const NEW_SINCE = '2026-11-03';
    public const NEW_LIMIT = 100;
    public const LOT_MAPS = ['BC' => 'ParcelMap BC and the City of Vancouver lot map'];
    public const PROVINCE_NAMES = [
        'BC' => 'British Columbia', 'AB' => 'Alberta', 'SK' => 'Saskatchewan', 'MB' => 'Manitoba',
        'ON' => 'Ontario', 'QC' => 'Quebec', 'NB' => 'New Brunswick', 'NS' => 'Nova Scotia',
        'PE' => 'Prince Edward Island', 'NL' => 'Newfoundland and Labrador', 'YT' => 'Yukon',
        'NT' => 'Northwest Territories', 'NU' => 'Nunavut',
    ];

    private PDO $db;
    /** @var callable(string): ?array */
    private $geocode;
    private ParcelLotService $lots;
    private CityParcelService $city;
    private string $today;

    public function __construct(PDO $db, ?callable $geocode = null, ?ParcelLotService $lots = null, ?string $today = null,
                                ?CityParcelService $city = null)
    {
        // A caller that supplies its own province map (tests) gets no live city map unless it supplies one too.
        $this->city = $city ?? new CityParcelService($lots !== null ? static fn(string $url): ?string => null : null);
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
            return self::switchOn($s->fetchColumn());
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Tim 2026-11-03: Otto boundaries every business's new clients; only an explicit '0' turns him off. */
    public static function switchOn($value): bool
    {
        return $value === false || $value === null || $value === '1';
    }

    /**
     * One pass: up to $limit pins, then up to $limit borders.
     * @return array{ran: bool, pinned: int, approximate: int, not_found: int, bordered: int, no_lot: int, message: string}
     */
    public function run(int $limit = 15, bool $force = false, int $newLimit = self::NEW_LIMIT): array
    {
        $r = ['ran' => false, 'new' => 0, 'pinned' => 0, 'approximate' => 0, 'not_found' => 0, 'bordered' => 0, 'no_lot' => 0, 'message' => 'Switched off'];
        if (!$force && !$this->enabled()) return $r;
        if (!$this->lock()) return ['message' => 'Another run is placing pins right now'] + $r;
        try {
            return $this->runLocked($limit, $newLimit, $r);
        } finally {
            $this->unlock();
        }
    }

    private function runLocked(int $limit, int $newLimit, array $r): array
    {
        $r['ran'] = true;
        $removed = $this->removeNearestLotGuesses();   // one-off clean-up; finds nothing once done
        $state = $this->prune($this->state());
        if ($state['new_after_id'] === null) {
            try {
                $s = $this->db->prepare('SELECT COALESCE(MAX(id), 0) FROM properties WHERE created_at < ?');
                $s->execute([self::NEW_SINCE]);
                $state['new_after_id'] = (int)$s->fetchColumn();
            } catch (Throwable $e) {
                $state['new_after_id'] = 0;   // Old test/dev schemas had no created_at column.
            }
            $this->saveState($state);   // Freeze the starting line before any later import can race this run.
        }
        $borders = (new PropertyBorderService($this->db, $this->today))->borders();
        $new = $this->workCandidates($this->newCandidates($state['new_after_id']), $state, $borders, $newLimit, true);
        $old = $this->candidates();
        foreach ($new as $pid => $_) unset($old[$pid]);
        $old = $this->workCandidates($old, $state, $borders, $limit, false);
        $props = $new + $old;
        $r['new'] = count($new);

        foreach ($props as $pid => $p) {
            if (PropertyBorderRules::pinOf($p)) {
                // A person may have pinned it after Otto recorded an approximate/not-found result.
                unset($state['pin_tried'][$pid], $state['pin_reason'][$pid]);
                continue;
            }
            if (self::recentlyTried($state['pin_tried'][$pid] ?? null, $this->today)) continue;
            $address = PropertyReadinessService::geocodeAddress($p);
            if (trim((string)($p['address'] ?? '')) === '' || trim($address) === '') {
                if (isset($new[$pid])) {
                    $state['pin_tried'][$pid] = $this->today;
                    $state['pin_reason'][$pid] = 'no_address';
                }
                continue;
            }
            $hit = ($this->geocode)($address);
            $decision = self::pinDecision($hit);
            if ($decision === 'save' && $this->savePinIfMissing($pid, (float)$hit['lat'], (float)$hit['lng'])) {
                $r['pinned']++;
                $state['pins'][$pid] = ['by' => (string)($hit['by'] ?? ''), 'at' => $this->today];
                $props[$pid]['latitude'] = $hit['lat'];
                $props[$pid]['longitude'] = $hit['lng'];
                unset($state['pin_tried'][$pid], $state['pin_reason'][$pid]);
            } else {
                $r[$decision === 'approximate' ? 'approximate' : 'not_found']++;
                $state['pin_tried'][$pid] = $this->today;
                $state['pin_reason'][$pid] = $decision === 'approximate' ? 'approximate' : 'not_found';
            }
        }

        foreach ($props as $pid => $p) {
            // The pin pass may have just placed this property.
            if (isset($new[$pid])) $p = $props[$pid];
            $pin = PropertyBorderRules::pinOf($p);
            if (!$pin) continue;
            $mode = self::borderMode($borders[$pid]['sources'] ?? null);
            $cityNew = CityParcelService::sourceFor($p) !== null && !isset($state['city_tried'][$pid]);
            if ($mode === null || isset($state['border_refused'][$pid])
                || (!$cityNew && self::recentlyTried($state['border_tried'][$pid] ?? null, $this->today))) continue;
            $lot = $this->findLot($p, $pin);
            if (CityParcelService::sourceFor($p) !== null) $state['city_tried'][$pid] = $this->today;
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
        if ($removed) $r['removed_guesses'] = $removed;
        $newMessage = $r['new'] ? $r['new'] . ' new ' . ($r['new'] === 1 ? 'property' : 'properties') . ' looked at; ' : '';
        $r['message'] = $newMessage . ($removed ? count($removed) . ' guessed border(s) removed; ' : '') . "{$r['pinned']} pinned ({$r['approximate']} only approximate, {$r['not_found']} not found), "
                      . "{$r['bordered']} lot border(s) ({$r['no_lot']} with no usable lot)";
        return $r;
    }

    /**
     * Keep scanning candidates until the limit counts properties that can actually be attempted. An old candidate
     * with no address is never work (it would take a slot every run); a new one is, once, to record "no address".
     */
    private function workCandidates(array $candidates, array $state, array $borders, int $limit, bool $isNew): array
    {
        if ($limit <= 0) return [];
        $out = [];
        foreach ($candidates as $pid => $p) {
            $pin = PropertyBorderRules::pinOf($p);
            $needsPin = !$pin && !self::recentlyTried($state['pin_tried'][$pid] ?? null, $this->today)
                && ($isNew || trim((string)($p['address'] ?? '')) !== '');
            $mode = $pin ? self::borderMode($borders[$pid]['sources'] ?? null) : null;
            $cityNew = $pin && CityParcelService::sourceFor($p) !== null && !isset($state['city_tried'][$pid]);
            $needsBorder = $pin && $mode !== null && !isset($state['border_refused'][$pid])
                && ($cityNew || !self::recentlyTried($state['border_tried'][$pid] ?? null, $this->today));
            if (!$needsPin && !$needsBorder) continue;
            $out[$pid] = $p;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /** One run at a time (cron and a manual web run): MySQL GET_LOCK; other drivers (tests) always get it. */
    private function lock(): bool
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return true;
        try {
            $name = self::lockName((string)$this->db->query('SELECT DATABASE()')->fetchColumn());
            $s = $this->db->prepare('SELECT GET_LOCK(?, 0)');
            $s->execute([$name]);
            return (int)$s->fetchColumn() === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function unlock(): void
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
        try {
            $name = self::lockName((string)$this->db->query('SELECT DATABASE()')->fetchColumn());
            $s = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $s->execute([$name]);
        } catch (Throwable $e) { /* released with the connection anyway */ }
    }

    public static function lockName(string $dbName): string
    {
        return substr('otto_auto_place:' . $dbName, 0, 64);
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

    /**
     * The owner's "Let Otto draw it" on one property (Otto's property card). Ignores the switch and
     * the retry wait — a person asked — but keeps every other rule: a pin is needed, a drawn, kept or
     * crew-GPS border is never replaced. Not locked: one property, and the INSERT re-checks.
     * @return array{ok: bool, message: string, area_sqm?: int, ring?: array}
     */
    public function borderOne(int $propertyId): array
    {
        $s = $this->db->prepare('SELECT id, address, city, province, postal_code, latitude, longitude FROM properties WHERE id = ?');
        $s->execute([$propertyId]);
        $p = $s->fetch(PDO::FETCH_ASSOC);
        if (!$p) return ['ok' => false, 'message' => 'That property no longer exists.'];
        $pin = PropertyBorderRules::pinOf($p);
        if (!$pin) return ['ok' => false, 'message' => 'It needs a map pin first.'];
        $borders = (new PropertyBorderService($this->db, $this->today))->borders();
        $mode = self::borderMode($borders[$propertyId]['sources'] ?? null);
        if ($mode === null) return ['ok' => false, 'message' => 'It already has a border — open the zone editor to change it.'];
        $lot = $this->findLot($p, $pin);
        if (!$lot['ok']) return ['ok' => false, 'message' => self::reasonText((string)$lot['reason'])];
        if (!$this->writeBorder($propertyId, $mode, $lot)) return ['ok' => false, 'message' => 'A border was added meanwhile — reload the page.'];
        $state = $this->state();
        unset($state['border_tried'][$propertyId], $state['border_reason'][$propertyId]);
        $this->saveState($state);
        return ['ok' => true, 'area_sqm' => (int)$lot['area_sqm'], 'ring' => array_slice(self::borderRing($lot['ring']), 0, -1),
                'message' => 'Drawn from the ' . ($lot['map_name'] ?? 'city lot map') . ': ' . ($lot['class'] ?: 'lot') . ($lot['plan'] ? ' ' . $lot['plan'] : '') . ', '
                           . number_format((int)$lot['area_sqm']) . ' m²' . ' — check it on the map.'];
    }

    /**
     * "Not right? Remove it": deletes Otto's lot border for one property (never a drawn, kept or crew-GPS
     * one) and Otto never draws that property again — the owner draws it.
     */
    public function removeLotBorder(int $propertyId): bool
    {
        $s = $this->db->prepare("DELETE FROM job_geofences WHERE property_id = ? AND zone_type = 'arrival_border' AND border_source = 'parcel'");
        $s->execute([$propertyId]);
        $state = $this->state();
        $state['border_refused'][$propertyId] = $this->today;
        $this->saveState($state);
        return $s->rowCount() > 0;
    }

    /**
     * One-off clean-up (2026-10-18): the lot borders the withdrawn "nearest lot" rule drew — their notes
     * say "m outside". They may be the neighbour's lot. Returns the property ids; each goes back to
     * "no border" (retried only through the building from its address).
     * @return int[]
     */
    public function removeNearestLotGuesses(): array
    {
        $s = $this->db->query("SELECT property_id FROM job_geofences WHERE zone_type = 'arrival_border' AND border_source = 'parcel' AND notes LIKE '%m outside%'");
        $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) return [];
        $this->db->exec("DELETE FROM job_geofences WHERE zone_type = 'arrival_border' AND border_source = 'parcel' AND notes LIKE '%m outside%'");
        $state = $this->state();
        foreach ($ids as $id) unset($state['border_tried'][$id]);
        $this->saveState($state);
        return $ids;
    }

    public static function reasonText(string $reason): string
    {
        if (strpos($reason, 'no_source:') === 0) {
            $code = substr($reason, strlen('no_source:'));
            return $code === ''
                ? "Otto couldn't tell which province this is in, so he has no lot map to check — draw the border."
                : 'Otto has no public lot map for ' . (self::PROVINCE_NAMES[$code] ?? $code) . ' yet — draw the border.';
        }
        return [
            'unavailable' => "The province's lot map isn't answering right now — try again in a few minutes.",
            'no_lot'      => "The pin isn't on a lot, and the address didn't lead to one — draw it, or move the pin onto the building.",
            'near_edge'   => 'The pin sits on a lot line — draw it, or move the pin onto the building.',
            'too_big'     => 'The lot is over 20,000 m² — too big to use as an arrival border; draw it.',
            'too_small'   => 'The lot is unusually small — draw it.',
            'no_address_match' => "The address didn't match the city's lot map — draw it, or move the pin onto the building.",
        ][$reason] ?? 'Otto could not find the lot — draw it.';
    }

    public static function pinReasonText(string $reason): string
    {
        return [
            'approximate' => 'The address only matched the street or area, not a building — check the address, or pin it by hand.',
            'not_found' => "The address wasn't found on the map — check it, or pin it by hand.",
            'no_address' => 'No address on file — add one.',
        ][$reason] ?? '';
    }

    /** The lot under the pin; for a street pin, under the address's building (if near), the pin staying put. */
    private function findLot(array $p, array $pin): array
    {
        $region = self::regionOf($p);
        if (!isset(self::LOT_MAPS[$region])) return ['ok' => false, 'reason' => 'no_source:' . ($region ?? '')];
        // A city's own lot map first, by address (CityParcelService): the province's map has gaps in
        // Vancouver — 3 of 4 Vancouver Management buildings had no lot in it at all (2026-10-19).
        $byCity = $this->city->lotFor($p, $pin);
        if ($byCity !== null && $byCity['ok']) return $byCity;
        $lot = $this->lots->lotAt($pin[0], $pin[1]);
        if (!$lot['ok'] && in_array($lot['reason'], ['no_lot', 'near_edge'], true)) {
            $at = self::buildingPoint(($this->geocode)(PropertyReadinessService::geocodeAddress($p)), $pin);
            if ($at) {
                $byBuilding = $this->lots->lotAt($at[0], $at[1]);
                if ($byBuilding['ok']) return $byBuilding;
            }
        }
        return $lot;
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

    /** The province named by free-form data, falling back to BC only when the pin is inside BC. */
    public static function regionOf(array $p): ?string
    {
        $value = strtolower((string)preg_replace('/[^a-z]/i', '', (string)($p['province'] ?? '')));
        foreach (self::PROVINCE_NAMES as $code => $name) {
            $full = strtolower((string)preg_replace('/[^a-z]/i', '', $name));
            if ($value === strtolower($code) || ($full !== '' && strpos($value, $full) !== false)) return $code;
        }
        $lat = (float)($p['latitude'] ?? 0);
        $lng = (float)($p['longitude'] ?? 0);
        // Keep the coordinate fallback conservative: Calgary sits at about -114.07 and must not
        // be treated as BC when its province field is blank.
        return $lat >= 48.2 && $lat <= 60.0 && $lng >= -139.1 && $lng <= -114.1 ? 'BC' : null;
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

    public const LEAD_DAYS = 30;

    /** Properties of open leads (a quote request not yet quoted): Otto places them too, not only properties already on a quote. */
    public function leadCandidates(): array
    {
        $out = [];
        try {
            $s = $this->db->prepare("
                SELECT p.id, p.address, p.city, p.province, p.postal_code, p.latitude, p.longitude
                FROM properties p
                WHERE p.id IN (SELECT qr.property_id FROM quote_requests qr
                               WHERE qr.status IN ('new', 'reviewing') AND qr.quote_id IS NULL AND qr.property_id IS NOT NULL AND qr.created_at >= ?)
                ORDER BY p.id");
            $s->execute([date('Y-m-d', strtotime($this->today . ' -' . self::LEAD_DAYS . ' days'))]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(int)$row['id']] = $row;
        } catch (Throwable $e) { /* quote_requests unreadable: no lead candidates */ }
        return $out;
    }

    /**
     * A new lead's property, now (Tim, 2026-11-03): pin it when it has none (an exact building match only, as in run()),
     * then draw its arrival border from the City of Vancouver's own lot map, else the province's (borderOne). Off with the
     * same switch as the 6-hourly run; never moves a pin, never touches a drawn / kept / GPS border.
     * @return array{ok: bool, message: string, pinned?: bool, area_sqm?: int}
     */
    public function placeNewLead(int $propertyId): array
    {
        if (!$this->enabled()) return ['ok' => false, 'message' => 'Otto\'s auto-placement is switched off.'];
        $s = $this->db->prepare('SELECT id, address, city, province, postal_code, latitude, longitude FROM properties WHERE id = ?');
        $s->execute([$propertyId]);
        $p = $s->fetch(PDO::FETCH_ASSOC);
        if (!$p) return ['ok' => false, 'message' => 'That property no longer exists.'];
        $pinned = false;
        if (!PropertyBorderRules::pinOf($p)) {
            $address = PropertyReadinessService::geocodeAddress($p);
            if (trim($address) === '' || trim((string)$p['address']) === '') return ['ok' => false, 'message' => 'No address yet — ask for it.'];
            $hit = ($this->geocode)($address);
            if (self::pinDecision($hit) !== 'save' || !$this->savePinIfMissing($propertyId, (float)$hit['lat'], (float)$hit['lng'])) {
                return ['ok' => false, 'message' => 'The address did not match a building on the map — pin it by hand.'];
            }
            $pinned = true;
        }
        return $this->borderOne($propertyId) + ['pinned' => $pinned];
    }

    private function candidates(): array
    {
        $props = (new PropertyBorderService($this->db, $this->today))->activeProperties();
        foreach ($this->leadCandidates() as $id => $row) $props[$id] ??= $row;
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

    /** Properties created after this database's frozen starting id, whichever path inserted them. */
    public function newCandidates(?int $afterId = null): array
    {
        if ($afterId === null) $afterId = (int)($this->state()['new_after_id'] ?? 0);
        $out = [];
        $s = $this->db->prepare('SELECT id, address, city, province, postal_code, latitude, longitude FROM properties WHERE id > ? ORDER BY id DESC');
        $s->execute([$afterId]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(int)$row['id']] = $row;
        return $out;
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
        $city = !empty($lot['source']);
        $notes = 'Otto: ' . ($lot['class'] ?: 'lot') . ($lot['plan'] ? ' ' . $lot['plan'] : '')
               . ($lot['pid'] ? ($city ? ', site ' : ', PID ') . $lot['pid'] : '')
               . ', ' . (int)$lot['area_sqm'] . ' m², +' . self::BUFFER_M . ' m. ' . ($lot['attribution'] ?? ParcelLotService::ATTRIBUTION);
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

    /** @return array{pins: array, pin_tried: array, pin_reason: array, border_tried: array, border_reason: array, border_refused: array, new_after_id: ?int} */
    private function state(): array
    {
        // city_tried: properties already looked up in a city's own lot map (once each, so a property the
        // province map gave up on is retried as soon as a city map covers it — 2026-10-19).
        $empty = ['pins' => [], 'pin_tried' => [], 'pin_reason' => [], 'border_tried' => [], 'border_reason' => [], 'border_refused' => [], 'city_tried' => [], 'new_after_id' => null];
        try {
            $s = $this->db->prepare('SELECT setting_value FROM ops_settings WHERE setting_key = ?');
            $s->execute([self::STATE_KEY]);
            $j = json_decode((string)$s->fetchColumn(), true);
        } catch (Throwable $e) {
            return $empty;
        }
        if (!is_array($j)) return $empty;
        $out = $empty;
        foreach (array_keys($empty) as $k) {
            if ($k === 'new_after_id') {
                $out[$k] = array_key_exists($k, $j) ? (int)$j[$k] : null;
                continue;
            }
            foreach ((array)($j[$k] ?? []) as $id => $v) $out[$k][(int)$id] = $v;
        }
        return $out;
    }

    /** What Otto last learned about one property's missing pin or lot border. */
    public function status(int $propertyId): array
    {
        $state = $this->state();
        $pin = isset($state['pin_reason'][$propertyId]) ? (string)$state['pin_reason'][$propertyId] : null;
        $border = isset($state['border_reason'][$propertyId]) ? (string)$state['border_reason'][$propertyId] : null;
        $dates = array_filter([$state['pin_tried'][$propertyId] ?? null, $state['border_tried'][$propertyId] ?? null]);
        rsort($dates);
        return [
            'pin_reason' => $pin,
            'pin_text' => $pin !== null ? self::pinReasonText($pin) : null,
            'border_reason' => $border,
            'border_text' => $border !== null ? self::reasonText($border) : null,
            'tried' => $dates ? (string)$dates[0] : null,
        ];
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
