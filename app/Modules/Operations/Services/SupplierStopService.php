<?php
/**
 * SupplierStopService — Otto knows when the crew went to the yard (plan
 * docs/plans/2026-10-13-otto-supplier-stops.md, migration 1359).
 *
 *   locations  every supplier / fuel location with a point: vendor_locations (typed in, geocoded,
 *              or learned from 2+ receipts) and Otto's places (ops_places kind supplier / fuel).
 *              Dumps and the yard are not supplier stops (the trip cron prices those).
 *   geocode    vendor_locations rows with an address and no point are geocoded ONCE by the nightly
 *              trip cron (geocodePending) — never at page load. Google needs a server key
 *              (GOOGLE_GEOCODING_KEY); the CRM's Maps key is browser-only, so OpenStreetMap's
 *              Nominatim answers today. Only a building-level point is saved; a street-level one
 *              is noted (geo_note) and left for a person, because a point 300 m off would name the
 *              wrong stops.
 *   stop       one phone or truck at least MIN_SECONDS within RADIUS_M of a location. A short trip
 *              out of the circle (MAX_EXCURSION_S — walking to the far bin) doesn't split it; a pass
 *              with one or two points never lasts 3 minutes. Phone fixes coarser than
 *              MAX_ACCURACY_M and mock fixes are ignored. The same stop seen by several phones and
 *              the truck is one stop (seen_by phone | truck | both).
 *   receipt    each of that supplier's receipts that day goes to ONE stop: its printed time first
 *              (within PRINTED_SLACK_S of the stop), else its photo time — the latest stop that
 *              began before the photo (a slip can't be photographed before you get there) — else,
 *              photographed another day, the earliest of that day's stops still without a receipt.
 *   missing    a stop with no receipt by 6 pm the next day is one item on Otto's card. "Nothing was
 *              bought" closes it (receipt_status 'none').
 *
 * Privacy: crew GPS is used only for business purchases and shown only to the office (admins /
 * managers). Stops are private to the tenant; supplier points are shareable knowledge.
 * Times are LOCAL (America/Vancouver) strings, as everywhere in GPS.
 * Everything static is pure and unit tested. No namespace / no autoloader: require_once and `new`.
 */
require_once __DIR__ . '/TripSegmentService.php';
require_once __DIR__ . '/StopEvidenceService.php';

class SupplierStopService
{
    public const RADIUS_M = 80;
    public const MIN_SECONDS = 180;
    /** Two fixes inside the circle further apart than this are two visits (phone silence, or left). */
    public const MAX_GAP_S = 300;
    /** Out of the circle for longer than this, then back = a second stop. */
    public const MAX_EXCURSION_S = 120;
    public const MAX_ACCURACY_M = 60;
    /** Stops at the same place by different phones / the truck this close in time are one stop. */
    public const MERGE_S = 300;
    public const PHOTO_SLACK_S = 120;
    public const PRINTED_SLACK_S = 900;
    /** "No receipt yet" waits until this hour the day after the stop. */
    public const MISSING_HOUR = 18;
    public const MISSING_DAYS = 7;
    public const GEOCODE_BATCH = 15;
    public const KINDS = ['supplier', 'fuel'];
    /** Longer than this is parking, not buying (the truck parked by the Chevron for 9 h, 2026-09-12). */
    public const MAX_MINUTES = ['fuel' => 30, 'supplier' => 120];
    /** A truck-only stop with nobody on the clock counts only inside these hours (parked-truck GPS jitter). */
    public const DAY_HOURS = [6, 20];

    private PDO $db;
    private ?array $locations = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Migration 1359 has run. */
    public function ready(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM supplier_stops LIMIT 0");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** The nearest location within $radius of a point: [location, metres] or null. */
    public static function nearest(float $lat, float $lng, array $locations, float $radius = self::RADIUS_M): ?array
    {
        $best = null;
        foreach ($locations as $l) {
            $m = TripSegmentService::meters($lat, $lng, (float)$l['lat'], (float)$l['lng']);
            if ($m <= $radius && ($best === null || $m < $best[1])) $best = [$l, $m];
        }
        return $best;
    }

    /**
     * Stops in ONE source's track (one phone, or one truck).
     * @param list<array{lat: float, lng: float, t: int, acc?: ?float}> $points time-ordered
     * @return list<array{key: string, start: int, end: int, nearest_m: int, points: int}>
     */
    public static function findStops(array $points, array $locations): array
    {
        $out = [];
        $cur = null;
        $close = function () use (&$cur, &$out) {
            if ($cur && $cur['end'] - $cur['start'] >= self::MIN_SECONDS && $cur['points'] >= 2) {
                unset($cur['out_since']);
                $out[] = $cur;
            }
            $cur = null;
        };
        foreach ($points as $p) {
            if (isset($p['acc']) && $p['acc'] !== null && (float)$p['acc'] > self::MAX_ACCURACY_M) continue;
            $hit = $locations ? self::nearest((float)$p['lat'], (float)$p['lng'], $locations) : null;
            $t = (int)$p['t'];
            if ($cur && $t - $cur['end'] > self::MAX_GAP_S) $close();
            if (!$hit) {
                if ($cur && $cur['out_since'] === null) $cur['out_since'] = $t;
                if ($cur && $t - $cur['out_since'] > self::MAX_EXCURSION_S) $close();
                continue;
            }
            $key = (string)$hit[0]['key'];
            if ($cur && $cur['key'] !== $key) $close();
            if (!$cur) {
                $cur = ['key' => $key, 'start' => $t, 'end' => $t, 'nearest_m' => (int)round($hit[1]), 'points' => 1, 'out_since' => null];
                continue;
            }
            $cur['end'] = $t;
            $cur['points']++;
            $cur['nearest_m'] = min($cur['nearest_m'], (int)round($hit[1]));
            $cur['out_since'] = null;
        }
        $close();
        return $out;
    }

    /**
     * One stop per place visit: the same location seen by several sources at overlapping times.
     * @param list<array> $stops findStops() rows, each with 'source' => ['user', id] | ['device', id]
     * @return list<array{key, start, end, nearest_m, points, user_ids: int[], device_ids: string[]}>
     */
    public static function merge(array $stops): array
    {
        usort($stops, fn($a, $b) => [$a['start'], $a['key']] <=> [$b['start'], $b['key']]);
        $out = [];
        foreach ($stops as $s) {
            $hit = null;
            foreach ($out as $i => $o) {
                if ($o['key'] === $s['key'] && $s['start'] <= $o['end'] + self::MERGE_S && $s['end'] >= $o['start'] - self::MERGE_S) { $hit = $i; break; }
            }
            [$kind, $id] = $s['source'] ?? ['user', 0];
            if ($hit === null) {
                $out[] = ['key' => $s['key'], 'start' => $s['start'], 'end' => $s['end'], 'nearest_m' => $s['nearest_m'], 'points' => $s['points'],
                          'user_ids' => $kind === 'user' ? [(int)$id] : [], 'device_ids' => $kind === 'device' ? [(string)$id] : []];
                continue;
            }
            $o = &$out[$hit];
            $o['start'] = min($o['start'], $s['start']);
            $o['end'] = max($o['end'], $s['end']);
            $o['nearest_m'] = min($o['nearest_m'], $s['nearest_m']);
            $o['points'] += $s['points'];
            if ($kind === 'user' && !in_array((int)$id, $o['user_ids'], true)) $o['user_ids'][] = (int)$id;
            if ($kind === 'device' && !in_array((string)$id, $o['device_ids'], true)) $o['device_ids'][] = (string)$id;
            unset($o);
        }
        return $out;
    }

    /** phone | truck | both. Truck = a Trackimo device or a truck-tablet login. */
    public static function seenBy(array $stop, array $truckUserIds): string
    {
        $phones = array_diff($stop['user_ids'] ?? [], $truckUserIds);
        $truck = !empty($stop['device_ids']) || array_intersect($stop['user_ids'] ?? [], $truckUserIds);
        if ($phones && $truck) return 'both';
        return $truck ? 'truck' : 'phone';
    }

    /** Is this receipt from this location's supplier? Same vendor, or the same name. */
    public static function receiptMatches(array $location, array $receipt): bool
    {
        if (!empty($location['vendor_id']) && !empty($receipt['vendor_id']) && (int)$location['vendor_id'] === (int)$receipt['vendor_id']) return true;
        $a = StopEvidenceService::squash((string)($location['name'] ?? ''));
        $b = StopEvidenceService::squash((string)($receipt['name'] ?? ''));
        if (strlen($a) < 5 || strlen($b) < 5) return false;
        return strpos($a, $b) !== false || strpos($b, $a) !== false;
    }

    /**
     * Each receipt to the one stop it belongs to (see the class comment for the order).
     * @param list<array{location_key, start, end}> $stops that day's stops, time-ordered
     * @param array<string, array>         $locations by key
     * @param list<array>                  $receipts StopEvidenceService::receiptFacts() rows
     * @return array<int, list<array{receipt_id: int, basis: string}>> [stop index => receipts]
     */
    public static function assignReceipts(array $stops, array $locations, array $receipts, string $date): array
    {
        $out = [];
        $later = [];
        foreach ($receipts as $rc) {
            $cands = [];
            foreach ($stops as $i => $s) {
                $loc = $locations[$s['location_key'] ?? $s['key'] ?? ''] ?? null;
                if ($loc && self::receiptMatches($loc, $rc)) $cands[$i] = $s;
            }
            if (!$cands) continue;
            $times = ($rc['in'] ?? null) !== null ? [$rc['in']] : ($rc['times'] ?? []);
            $best = null;
            foreach ($times as $t) {
                foreach ($cands as $i => $s) {
                    $d = StopEvidenceService::outside((int)$t, (int)$s['start'], (int)$s['end'], self::PRINTED_SLACK_S);
                    if ($d !== null && ($best === null || $d < $best[1])) $best = [$i, $d];
                }
            }
            if ($best) { $out[$best[0]][] = ['receipt_id' => (int)$rc['id'], 'basis' => 'printed']; continue; }
            $photo = $rc['created_at'] ?? null;
            if ($photo !== null && date('Y-m-d', (int)$photo) === $date) {
                $pick = null;
                foreach ($cands as $i => $s) if ($s['start'] <= $photo + self::PHOTO_SLACK_S) $pick = $i;   // the latest one begun
                if ($pick === null) $pick = array_key_first($cands);                                       // clock skew: the first
                $out[$pick][] = ['receipt_id' => (int)$rc['id'], 'basis' => 'photo'];
                continue;
            }
            $later[] = [$rc, array_keys($cands)];
        }
        // Photographed another day (No 006 shot next morning): the earliest stop still without a receipt.
        foreach ($later as [$rc, $idx]) {
            $pick = null;
            foreach ($idx as $i) if (empty($out[$i])) { $pick = $i; break; }
            $pick ??= end($idx);
            $out[$pick][] = ['receipt_id' => (int)$rc['id'], 'basis' => 'date'];
        }
        ksort($out);
        return $out;
    }

    /** "LAWNBOY Oct 7 11:27–11:34 · Nigel, truck" */
    public static function line(array $row): string
    {
        $who = trim((string)($row['people'] ?? ''));
        $truck = in_array($row['seen_by'] ?? '', ['truck', 'both'], true);
        $parts = array_values(array_filter([$who, $truck ? 'truck' : '']));
        return trim((string)$row['place_name']) . ' ' . date('M j', strtotime((string)$row['arrived_at'])) . ' '
            . date('G:i', strtotime((string)$row['arrived_at'])) . '–' . date('G:i', strtotime((string)$row['left_at']))
            . ($parts ? ' · ' . implode(', ', $parts) : '');
    }

    /** May "no receipt yet" be said for a stop on $stopDate at $now? (6 pm the next day, within a week.) */
    public static function missingDue(string $stopDate, int $now): bool
    {
        $due = strtotime($stopDate . ' 00:00:00 +1 day') + self::MISSING_HOUR * 3600;
        return $now >= $due && $now < strtotime($stopDate . ' 00:00:00') + (self::MISSING_DAYS + 1) * 86400;
    }

    /**
     * Is this a purchase stop, or parking? Too long for its kind = parking; the truck alone with
     * nobody on the clock counts only in the daytime (overnight GPS drift around a parked truck).
     */
    public static function plausible(string $kind, int $start, int $end, string $seenBy, bool $anyoneOnClock): bool
    {
        if (($end - $start) / 60 > (self::MAX_MINUTES[$kind] ?? 120)) return false;
        if ($seenBy === 'truck' && !$anyoneOnClock) {
            $h = (int)date('G', $start);
            if ($h < self::DAY_HOURS[0] || $h >= self::DAY_HOURS[1]) return false;
        }
        return true;
    }

    /** What to ask the geocoder for a vendor location, or null when there is no street address. */
    public static function geocodeQuery(array $row): ?string
    {
        $a = trim(preg_replace('/\s+/', ' ', (string)($row['address'] ?? '')));
        if ($a === '' || !preg_match('/\d/', $a)) return null;
        $a = preg_replace('/\bVan\.?(?=\s|,|$)/i', 'Vancouver', $a);
        $city = trim((string)($row['city'] ?? ''));
        if ($city !== '' && stripos($a, $city) === false) $a .= ', ' . $city;
        if (!preg_match('/\b(BC|B\.C\.|British Columbia)\b/i', $a)) $a .= ', BC';
        return $a;
    }

    /** "5190 Ladner Trunk Rd., Ladner" → "5190 ladner": the street number and name, for same-address checks. */
    public static function streetKey(?string $address): string
    {
        $a = strtolower(trim((string)$address));
        if (!preg_match('/^\s*#?(?:\d+[a-z]?\s*[-–]\s*)?(\d+)\s+(.*)$/', $a, $m)) return '';
        foreach (preg_split('/[\s,.]+/', $m[2]) as $w) {
            if ($w === '' || in_array($w, ['n', 's', 'e', 'w', 'ne', 'nw', 'se', 'sw', 'north', 'south', 'east', 'west'], true)) continue;
            return $m[1] . ' ' . $w;
        }
        return '';
    }

    /** Kind of a location for stops, or null when it is not one (dump, yard, unknown). */
    public static function locationKind(?string $category, ?string $gbp, string $name): ?string
    {
        $k = StopEvidenceService::kindFor($category, $gbp, $name);
        return in_array($k, self::KINDS, true) ? $k : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Locations + geocoding
    // ─────────────────────────────────────────────────────────────────────────

    /** Every supplier / fuel location with a point, keyed 'vl:<id>' / 'op:<id>'. */
    public function locations(): array
    {
        if ($this->locations !== null) return $this->locations;
        $out = [];
        try {
            $learned = $this->db->query("SHOW COLUMNS FROM vendor_locations LIKE 'receipts_seen'")->rowCount() > 0;
            $exact = $this->db->query("SHOW COLUMNS FROM vendor_locations LIKE 'geo_exact'")->rowCount() > 0;
            $rows = $this->db->query("
                SELECT vl.id, vl.vendor_id, vl.lat, vl.lng, v.name, v.default_accounting_category AS category, v.default_gbp_category AS gbp"
                . ($learned ? ", vl.source, vl.receipts_seen" : ", NULL AS source, 0 AS receipts_seen")
                . ($exact ? ", vl.geo_exact" : ", NULL AS geo_exact") . "
                FROM vendor_locations vl JOIN vendors v ON v.id = vl.vendor_id
                WHERE vl.lat IS NOT NULL AND vl.lng IS NOT NULL AND COALESCE(v.is_active, 1) = 1
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                if (($r['source'] ?? null) === 'learned' && (int)$r['receipts_seen'] < 2) continue;   // one photo isn't a store
                if ($r['geo_exact'] !== null && (int)$r['geo_exact'] === 0) continue;
                $kind = self::locationKind($r['category'], $r['gbp'], (string)$r['name']);
                if ($kind === null) continue;
                $out['vl:' . $r['id']] = ['key' => 'vl:' . $r['id'], 'vendor_id' => (int)$r['vendor_id'], 'name' => (string)$r['name'],
                                          'kind' => $kind, 'lat' => (float)$r['lat'], 'lng' => (float)$r['lng']];
            }
        } catch (Throwable $e) {
            error_log('SupplierStop locations (vendor): ' . $e->getMessage());
        }
        try {
            $vid = $this->db->query("SHOW COLUMNS FROM ops_places LIKE 'vendor_id'")->rowCount() > 0 ? 'vendor_id' : 'NULL';
            $rows = $this->db->query("SELECT id, name, kind, lat, lng, {$vid} AS vendor_id FROM ops_places WHERE active = 1 AND kind IN ('supplier', 'fuel')")
                             ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $dup = false;
                foreach ($out as $l) {
                    $same = ($r['vendor_id'] && (int)$r['vendor_id'] === $l['vendor_id'])
                        || StopEvidenceService::squash((string)$r['name']) === StopEvidenceService::squash($l['name']);
                    if ($same && TripSegmentService::meters((float)$r['lat'], (float)$r['lng'], $l['lat'], $l['lng']) <= self::RADIUS_M) { $dup = true; break; }
                }
                if ($dup) continue;
                $out['op:' . $r['id']] = ['key' => 'op:' . $r['id'], 'vendor_id' => $r['vendor_id'] ? (int)$r['vendor_id'] : null,
                                          'name' => (string)$r['name'], 'kind' => (string)$r['kind'], 'lat' => (float)$r['lat'], 'lng' => (float)$r['lng']];
            }
        } catch (Throwable $e) {
            error_log('SupplierStop locations (places): ' . $e->getMessage());
        }
        return $this->locations = $out;
    }

    /**
     * Geocode vendor locations that have no point and were never tried. Saves only a building-level
     * point; anything else is noted (geo_note) and left for a person. Never call at page load.
     * @param callable|null $geocode string address → ?array{lat, lng, by, exact} (tests pass a fake)
     * @return list<array{id: int, vendor: string, query: ?string, result: string, lat?: float, lng?: float, by?: string}>
     */
    public function geocodePending(?callable $geocode = null, int $limit = self::GEOCODE_BATCH): array
    {
        if (!$this->ready()) return [];
        if ($geocode === null) {
            require_once __DIR__ . '/SupplierDirectoryService.php';
            $geocode = [SupplierDirectoryService::class, 'googleGeocode'];
        }
        $rows = $this->db->query("
            SELECT vl.id, vl.vendor_id, vl.address, vl.city, v.name FROM vendor_locations vl LEFT JOIN vendors v ON v.id = vl.vendor_id
            WHERE (vl.lat IS NULL OR vl.lng IS NULL) AND vl.geocoded_at IS NULL
            ORDER BY vl.id LIMIT " . max(1, min(50, $limit))
        )->fetchAll(PDO::FETCH_ASSOC);
        // Another vendor already at this street address (a misread receipt: "Home Depot" at Dunbar
        // Lumber's Ladner address) — a point would name that store's stops wrongly. Left for a person.
        $taken = [];
        foreach ($this->db->query("SELECT vl.vendor_id, vl.address, v.name FROM vendor_locations vl LEFT JOIN vendors v ON v.id = vl.vendor_id
                                   WHERE vl.lat IS NOT NULL AND vl.address IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $k = self::streetKey($t['address']);
            if ($k !== '') $taken[$k][(int)$t['vendor_id']] = (string)$t['name'];
        }
        $out = [];
        $upd = $this->db->prepare("UPDATE vendor_locations SET lat = ?, lng = ?, geo_source = ?, geo_exact = ?, geocoded_at = ?, geo_note = ? WHERE id = ?");
        foreach ($rows as $r) {
            $q = self::geocodeQuery($r);
            $now = date('Y-m-d H:i:s');
            $res = ['id' => (int)$r['id'], 'vendor' => (string)$r['name'], 'query' => $q];
            if ($q === null) {
                $upd->execute([null, null, null, null, $now, 'No street address on file', (int)$r['id']]);
                $out[] = $res + ['result' => 'no address'];
                continue;
            }
            $other = array_diff_key($taken[self::streetKey($r['address'])] ?? [], [(int)$r['vendor_id'] => true]);
            if ($other) {
                $note = 'Same street address as ' . implode(', ', $other) . ' — check which store this is';
                $upd->execute([null, null, null, null, $now, mb_substr($note, 0, 255), (int)$r['id']]);
                $out[] = $res + ['result' => 'same address as ' . implode(', ', $other)];
                continue;
            }
            $p = null;
            try { $p = $geocode($q); } catch (Throwable $e) { error_log('SupplierStop geocode #' . $r['id'] . ': ' . $e->getMessage()); }
            if (!$p) {
                $upd->execute([null, null, null, null, $now, 'The geocoder did not find this address', (int)$r['id']]);
                $out[] = $res + ['result' => 'not found'];
                continue;
            }
            $by = ($p['by'] ?? '') === 'osm' ? 'nominatim' : (string)($p['by'] ?? 'google');
            if (empty($p['exact'])) {
                $note = sprintf('Only the street was found (%s: %.5f, %.5f) — not saved; set the pin by hand', $by, (float)$p['lat'], (float)$p['lng']);
                $upd->execute([null, null, $by, 0, $now, mb_substr($note, 0, 255), (int)$r['id']]);
                $out[] = $res + ['result' => 'street only', 'lat' => (float)$p['lat'], 'lng' => (float)$p['lng'], 'by' => $by];
                continue;
            }
            $upd->execute([round((float)$p['lat'], 7), round((float)$p['lng'], 7), $by, 1, $now, null, (int)$r['id']]);
            $out[] = $res + ['result' => 'saved', 'lat' => (float)$p['lat'], 'lng' => (float)$p['lng'], 'by' => $by];
        }
        $this->locations = null;
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Stops for a day
    // ─────────────────────────────────────────────────────────────────────────

    /** Truck-tablet logins (users.device_type = 'truck'). */
    public function truckUserIds(): array
    {
        try {
            return array_map('intval', $this->db->query("SELECT id FROM users WHERE device_type = 'truck'")->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** That day's tracks: [['user', id] | ['device', id], points]. */
    private function tracks(string $date): array
    {
        $out = [];
        try {
            $mock = $this->db->query("SHOW COLUMNS FROM crew_location_history LIKE 'is_mock'")->rowCount() > 0 ? 'AND COALESCE(is_mock, 0) = 0' : '';
            $s = $this->db->prepare("
                SELECT crew_id, latitude, longitude, accuracy_meters, `timestamp` AS t
                FROM crew_location_history
                WHERE `timestamp` BETWEEN ? AND ? {$mock}
                ORDER BY crew_id, `timestamp`
            ");
            $s->execute([$date . ' 00:00:00', $date . ' 23:59:59']);
            $by = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $by[(int)$r['crew_id']][] = ['lat' => (float)$r['latitude'], 'lng' => (float)$r['longitude'], 't' => (int)strtotime((string)$r['t']),
                                             'acc' => $r['accuracy_meters'] !== null ? (float)$r['accuracy_meters'] : null];
            }
            foreach ($by as $uid => $pts) $out[] = [['user', $uid], $pts];
        } catch (Throwable $e) {
            error_log('SupplierStop phone tracks: ' . $e->getMessage());
        }
        try {
            $s = $this->db->prepare("SELECT device_id, lat, lng, recorded_at FROM vehicle_location_pings WHERE recorded_at BETWEEN ? AND ? ORDER BY device_id, recorded_at");
            $s->execute([$date . ' 00:00:00', $date . ' 23:59:59']);
            $by = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $by[(string)$r['device_id']][] = ['lat' => (float)$r['lat'], 'lng' => (float)$r['lng'], 't' => (int)strtotime((string)$r['recorded_at'])];
            }
            foreach ($by as $dev => $pts) $out[] = [['device', $dev], $pts];
        } catch (Throwable $e) {
            error_log('SupplierStop truck tracks: ' . $e->getMessage());
        }
        return $out;
    }

    /** First names of people (not truck logins); with no phone, who was on the clock. */
    private function people(array $stop, array $truckUserIds): string
    {
        $ids = array_values(array_diff($stop['user_ids'], $truckUserIds));
        $guess = false;
        if (!$ids) {
            $seg = new TripSegmentService($this->db);
            $ids = array_values(array_diff($seg->clockedIn(date('Y-m-d H:i:s', $stop['start']), date('Y-m-d H:i:s', $stop['end'])), $truckUserIds));
            $guess = true;
        }
        if (!$ids) return '';
        $in = implode(',', array_fill(0, count($ids), '?'));
        $s = $this->db->prepare("SELECT full_name FROM users WHERE id IN ({$in}) ORDER BY id");
        $s->execute($ids);
        $names = array_map(fn($n) => (string)strtok(ucwords(strtolower(trim((string)$n))), ' '), $s->fetchAll(PDO::FETCH_COLUMN));
        $txt = implode(' + ', $names);
        return $guess && $txt !== '' ? $txt . ' (on the clock)' : $txt;
    }

    /**
     * The day's stops, computed from GPS (no writes).
     * @return list<array> rows shaped like supplier_stops (+ start/end/user_id list), time-ordered
     */
    public function build(string $date): array
    {
        $locs = $this->locations();
        if (!$locs) return [];
        $found = [];
        foreach ($this->tracks($date) as [$src, $pts]) {
            foreach (self::findStops($pts, array_values($locs)) as $s) $found[] = $s + ['source' => $src];
        }
        $truck = $this->truckUserIds();
        $rows = [];
        foreach (self::merge($found) as $s) {
            $loc = $locs[$s['key']];
            $seen = self::seenBy($s, $truck);
            $people = $this->people($s, $truck);
            if (!self::plausible($loc['kind'], $s['start'], $s['end'], $seen, $people !== '')) continue;
            $rows[] = [
                'stop_date' => $date, 'location_key' => $s['key'], 'vendor_id' => $loc['vendor_id'], 'place_name' => mb_substr($loc['name'], 0, 160),
                'kind' => $loc['kind'], 'arrived_at' => date('Y-m-d H:i:s', $s['start']), 'left_at' => date('Y-m-d H:i:s', $s['end']),
                'minutes' => (int)round(($s['end'] - $s['start']) / 60), 'nearest_m' => $s['nearest_m'], 'points' => $s['points'],
                'seen_by' => $seen, 'user_ids' => implode(',', $s['user_ids']) ?: null,
                'people' => mb_substr($people, 0, 160) ?: null, 'device_ids' => implode(',', $s['device_ids']) ?: null,
                'start' => $s['start'], 'end' => $s['end'],
            ];
        }
        usort($rows, fn($a, $b) => $a['start'] <=> $b['start']);
        return $rows;
    }

    /** Stored stops for a day (with start/end), time-ordered. */
    public function stored(string $date): array
    {
        $s = $this->db->prepare("SELECT * FROM supplier_stops WHERE stop_date = ? ORDER BY arrived_at, id");
        $s->execute([$date]);
        return array_map(fn($r) => $r + ['start' => strtotime((string)$r['arrived_at']), 'end' => strtotime((string)$r['left_at'])],
                         $s->fetchAll(PDO::FETCH_ASSOC));
    }

    /** The day's stops: stored when the cron has built the day, else computed live (not stored). */
    public function stopsFor(string $date): array
    {
        if ($this->ready()) {
            $rows = $this->stored($date);
            if ($rows) return $rows;
        }
        return $this->build($date);
    }

    /**
     * The day's stops with their receipts: [stop row + receipts => list{receipt_id, basis}].
     * Receipts are matched live (they arrive after the cron).
     */
    public function withReceipts(string $date, ?array $stops = null): array
    {
        $stops ??= $this->stopsFor($date);
        if (!$stops) return [];
        $ev = new StopEvidenceService($this->db);
        $map = self::assignReceipts($stops, $this->locations(), $ev->receipts($date), $date);
        foreach ($stops as $i => &$s) $s['receipts'] = $map[$i] ?? [];
        unset($s);
        return $stops;
    }

    /**
     * Build and store a day. Ids are kept when the same stop (same place, arriving within MERGE_S) is
     * found again, so Otto's card and a decision ("nothing was bought") stay attached.
     * @return array{stops: int, with_receipt: int, removed: int}
     */
    public function run(string $date): array
    {
        if (!$this->ready()) return ['stops' => 0, 'with_receipt' => 0, 'removed' => 0];
        $rows = $this->withReceipts($date, $this->build($date));
        $old = $this->stored($date);
        $kept = [];
        $withReceipt = 0;
        $ins = $this->db->prepare("
            INSERT INTO supplier_stops (stop_date, location_key, vendor_id, place_name, kind, arrived_at, left_at, minutes, nearest_m, points,
                                        seen_by, user_ids, people, device_ids, expense_ids, receipt_basis, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $upd = $this->db->prepare("
            UPDATE supplier_stops SET vendor_id = ?, place_name = ?, kind = ?, arrived_at = ?, left_at = ?, minutes = ?, nearest_m = ?, points = ?,
                                      seen_by = ?, user_ids = ?, people = ?, device_ids = ?, expense_ids = ?, receipt_basis = ?, updated_at = ?
            WHERE id = ?
        ");
        foreach ($rows as $r) {
            $ids = implode(',', array_column($r['receipts'], 'receipt_id')) ?: null;
            $basis = $r['receipts'] ? $r['receipts'][0]['basis'] : null;
            if ($ids) $withReceipt++;
            $match = null;
            foreach ($old as $o) {
                if (!isset($kept[(int)$o['id']]) && $o['location_key'] === $r['location_key'] && abs($o['start'] - $r['start']) <= self::MERGE_S) { $match = $o; break; }
            }
            $vals = [$r['vendor_id'], $r['place_name'], $r['kind'], $r['arrived_at'], $r['left_at'], $r['minutes'], $r['nearest_m'], $r['points'],
                     $r['seen_by'], $r['user_ids'], $r['people'], $r['device_ids'], $ids, $basis];
            if ($match) {
                $upd->execute(array_merge($vals, [date('Y-m-d H:i:s'), (int)$match['id']]));
                $kept[(int)$match['id']] = true;
            } else {
                $ins->execute(array_merge([$date, $r['location_key']], $vals, [date('Y-m-d H:i:s')]));
                $kept[(int)$this->db->lastInsertId()] = true;
            }
        }
        $gone = array_values(array_filter(array_map(fn($o) => (int)$o['id'], $old), fn($id) => !isset($kept[$id])));
        if ($gone) {
            $in = implode(',', array_fill(0, count($gone), '?'));
            $this->db->prepare("DELETE FROM supplier_stops WHERE id IN ({$in})")->execute($gone);
        }
        return ['stops' => count($rows), 'with_receipt' => $withReceipt, 'removed' => count($gone)];
    }

    /** The stop a receipt belongs to (that day's stops, matched live), or null. */
    public function stopForExpense(int $expenseId): ?array
    {
        $s = $this->db->prepare("SELECT expense_date FROM expenses WHERE id = ?");
        $s->execute([$expenseId]);
        $date = (string)$s->fetchColumn();
        if ($date === '') return null;
        foreach ($this->withReceipts($date) as $stop) {
            foreach ($stop['receipts'] as $rc) {
                if ($rc['receipt_id'] === $expenseId) return $stop + ['basis' => $rc['basis'], 'line' => self::line($stop)];
            }
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Otto's card: a stop with no receipt by the next evening
    // ─────────────────────────────────────────────────────────────────────────

    /** Items for OpsDeskService::current() (kind supplier_receipt; server-sent buttons). */
    public function ottoItems(string $today, ?int $now = null): array
    {
        if (!$this->ready()) return [];
        $now ??= time();
        $out = [];
        try {
            $s = $this->db->prepare("SELECT DISTINCT stop_date FROM supplier_stops WHERE stop_date >= ? AND stop_date < ? AND receipt_status IS NULL ORDER BY stop_date");
            $s->execute([date('Y-m-d', strtotime($today . ' -' . self::MISSING_DAYS . ' days')), $today]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $date) {
                if (!self::missingDue((string)$date, $now)) continue;
                foreach ($this->withReceipts((string)$date, $this->stored((string)$date)) as $st) {
                    if ($st['receipts'] || $st['receipt_status'] !== null) continue;
                    $when = date('M j', $st['start']) . ' ' . date('g:i a', $st['start']);
                    $who = trim((string)$st['people']);
                    $seen = ['phone' => 'phone GPS', 'truck' => 'truck GPS', 'both' => 'phone + truck GPS'][$st['seen_by']] ?? 'GPS';
                    $out[] = [
                        'kind' => 'supplier_receipt', 'subject_type' => 'supplier_stop', 'subject_id' => (int)$st['id'],
                        'for_date' => (string)$date, 'priority' => 3, 'key' => 'otto:supplier_receipt:' . (int)$st['id'],
                        'text' => $st['place_name'] . ', ' . $when . ' — no receipt yet.',
                        'detail' => ($who !== '' ? $who . ' ' : 'Someone ') . 'stopped there ' . max(1, (int)$st['minutes']) . ' min ('
                            . $seen . '). Photograph the slip, or tell Otto nothing was bought.',
                        'url' => '/crm/expenses_appstack.php', 'since' => (string)$date, 'value' => (int)$st['minutes'],
                        'propose' => ['buttons' => [
                            ['label' => 'Nothing was bought', 'choice' => 'none', 'main' => false],
                            ['label' => "I'll find the receipt", 'choice' => 'dismiss', 'main' => true],
                        ]],
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log('SupplierStop otto items: ' . $e->getMessage());
        }
        return $out;
    }

    /** "Nothing was bought" — closes the missing-receipt item for good. */
    public function markNone(int $stopId, ?int $userId): bool
    {
        $s = $this->db->prepare("UPDATE supplier_stops SET receipt_status = 'none', decided_by = ?, decided_at = ? WHERE id = ? AND receipt_status IS NULL");
        $s->execute([$userId, date('Y-m-d H:i:s'), $stopId]);
        return $s->rowCount() > 0;
    }
}
