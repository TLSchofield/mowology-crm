<?php
/**
 * TripSegmentService — Otto: the truck's day as stops and drives, and the overhead runs in it.
 *
 * Source: vehicle_location_pings (Trackimo, ~every 2 min). recorded_at is written by
 * trackimo_poll.php with date() under America/Vancouver, so it is LOCAL time — compare it
 * with local strings, never append 'Z'.
 *
 *   stop   = consecutive pings slower than STOP_SPEED_KPH, within CLUSTER_M of the first one,
 *            spanning ≥ STOP_MIN_SECONDS
 *   drive  = the pings between two stops (minutes, haversine km)
 *   label  = a named place (ops_places, its own radius) → a client property (≤ PROPERTY_RADIUS_M)
 *            → unnamed
 *   run    = the stops between two anchors (a client property or the yard) that include a dump or
 *            supplier. Each leg's time and km are split evenly between its non-anchor ends, so a
 *            combined dump + supplier run charges each place its own share. Stops under
 *            UNNAMED_MIN_SECONDS that aren't a place (lights, a coffee) are folded into the drive.
 *
 * Who was in the truck (crewVerdict): if one person is clocked in, it's them. If more, each
 * clocked-in person's phone (crew_location_history) while the truck was away decides: near a
 * place the truck stopped at → in the truck; near the property it left → stayed behind. With no
 * phone data the run defaults to one man when it left a client property (someone stays on site),
 * and the owner can flip it with the per-run toggle.
 *
 * Everything static is pure and unit tested; the instance methods only load rows.
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once dirname(__DIR__, 2) . '/Expenses/Services/ReceiptTrailService.php';

class TripSegmentService
{
    public const STOP_SPEED_KPH      = 3.0;
    public const STOP_MIN_SECONDS    = 240;
    public const CLUSTER_M           = 100;
    public const PROPERTY_RADIUS_M   = 120;
    public const UNNAMED_MIN_SECONDS = 300;
    /** Two stops at the same label this close together are one stop (repositioning at the dump). */
    public const MERGE_GAP_SECONDS   = 300;
    public const OVERHEAD_KINDS      = ['dump', 'supplier'];
    /** Phone within this of the property the truck left = stayed behind. */
    public const STAYED_M            = 200;
    /** Phone within this of a stop the truck made away from the property = in the truck. */
    public const WITH_TRUCK_M        = 300;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Migration 1216 has run. */
    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'ops_places'")->rowCount() > 0
                && $this->db->query("SHOW TABLES LIKE 'ops_trip_runs'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Loaders
    // ─────────────────────────────────────────────────────────────────────────

    /** The truck's pings for a local date, as {lat, lng, speed_kph, t}. */
    public function pings(string $date): array
    {
        $s = $this->db->prepare("
            SELECT lat, lng, speed_kph, recorded_at
            FROM vehicle_location_pings
            WHERE recorded_at >= ? AND recorded_at <= ?
            ORDER BY recorded_at ASC
        ");
        $s->execute([$date . ' 00:00:00', $date . ' 23:59:59']);
        return self::normalisePings($s->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Active named places. */
    public function places(): array
    {
        try {
            return $this->db->query("SELECT id, name, kind, lat, lng, radius_m, vendor_match FROM ops_places WHERE active = 1")
                            ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Properties with coordinates inside the box the day's pings cover (plus a margin). */
    public function propertiesNear(array $pings): array
    {
        if (!$pings) return [];
        $lats = array_column($pings, 'lat');
        $lngs = array_column($pings, 'lng');
        $m = 0.003;
        $s = $this->db->prepare("
            SELECT p.id, p.latitude, p.longitude, p.address, p.property_name
            FROM properties p
            WHERE p.latitude BETWEEN ? AND ? AND p.longitude BETWEEN ? AND ?
        ");
        $s->execute([min($lats) - $m, max($lats) + $m, min($lngs) - $m, max($lngs) + $m]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** User ids clocked in at any point in [from, to] (local datetimes). */
    public function clockedIn(string $from, string $to): array
    {
        try {
            $s = $this->db->prepare("
                SELECT DISTINCT user_id FROM time_clock_entries
                WHERE clock_in <= ? AND (clock_out IS NULL OR clock_out >= ?)
                  AND status IN ('active', 'completed', 'edited')
            ");
            $s->execute([$to, $from]);
            return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            error_log('TripSegment clockedIn: ' . $e->getMessage());
            return [];
        }
    }

    /** Each user's phone pings in [from, to], office pings excluded: [uid => [{lat, lng, t}]]. */
    public function phonePings(array $userIds, string $from, string $to): array
    {
        $out = [];
        if (!$userIds) return $out;
        try {
            $in = implode(',', array_fill(0, count($userIds), '?'));
            $s = $this->db->prepare("
                SELECT crew_id, latitude, longitude, `timestamp` AS t
                FROM crew_location_history
                WHERE crew_id IN ({$in}) AND `timestamp` BETWEEN ? AND ? AND COALESCE(is_office, 0) = 0
                ORDER BY `timestamp`
            ");
            $s->execute(array_merge(array_values($userIds), [$from, $to]));
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['crew_id']][] = ['lat' => (float)$r['latitude'], 'lng' => (float)$r['longitude'], 't' => (int)strtotime((string)$r['t'])];
            }
        } catch (Throwable $e) {
            error_log('TripSegment phonePings: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * The whole day: segments, runs and unnamed stops, with who was in the truck for each run.
     * @param array $overrides [trip_key => 1|0] the owner's one-man / two-man toggles
     * @return array{date: string, pings: int, first: ?string, last: ?string, segments: array, runs: array, unnamed: array}
     */
    public function day(string $date, array $overrides = []): array
    {
        $pings = $this->pings($date);
        $places = $this->places();
        $props = $this->propertiesNear($pings);
        $segments = self::segments($pings, $props, $places);
        $runs = self::runs($segments, $date);
        foreach ($runs as &$run) {
            $from = date('Y-m-d H:i:s', $run['left_at']);
            $to = date('Y-m-d H:i:s', $run['returned_at'] ?? end($pings)['t']);
            $clocked = $this->clockedIn($from, $to);
            $phones = count($clocked) > 1 ? $this->phonePings($clocked, $from, $to) : [];
            $ov = $overrides[$run['trip_key']] ?? null;
            $run['crew'] = self::crewVerdict($clocked, $phones, $run['from'], $run['away_points'], $ov === null ? null : (int)$ov);
        }
        unset($run);
        return [
            'date'     => $date,
            'pings'    => count($pings),
            'first'    => $pings ? date('H:i', $pings[0]['t']) : null,
            'last'     => $pings ? date('H:i', end($pings)['t']) : null,
            'segments' => $segments,
            'runs'     => $runs,
            'unnamed'  => self::unnamedStops($segments),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure helpers (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** DB rows → [{lat, lng, speed_kph, t}] sorted by time; recorded_at read as local time. */
    public static function normalisePings(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $t = isset($r['t']) ? (int)$r['t'] : strtotime((string)($r['recorded_at'] ?? ''));
            if (!$t || !is_numeric($r['lat'] ?? null) || !is_numeric($r['lng'] ?? null)) continue;
            $out[] = [
                'lat' => (float)$r['lat'], 'lng' => (float)$r['lng'],
                'speed_kph' => isset($r['speed_kph']) && $r['speed_kph'] !== null && $r['speed_kph'] !== '' ? (float)$r['speed_kph'] : null,
                't' => (int)$t,
            ];
        }
        usort($out, fn($a, $b) => $a['t'] <=> $b['t']);
        return $out;
    }

    public static function meters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return ReceiptTrailService::meters($lat1, $lng1, $lat2, $lng2);
    }

    /**
     * Stops in the trail. A ping with no speed counts as slow (a parked tracker often omits it);
     * the cluster radius still keeps a moving truck out.
     * @return list<array{lat: float, lng: float, start: int, end: int, i0: int, i1: int}>
     */
    public static function detectStops(array $pings): array
    {
        $slow = fn(array $p) => $p['speed_kph'] === null || $p['speed_kph'] < self::STOP_SPEED_KPH;
        $stops = [];
        $n = count($pings);
        $i = 0;
        while ($i < $n) {
            if (!$slow($pings[$i])) { $i++; continue; }
            $j = $i;
            $sLat = 0.0; $sLng = 0.0;
            while ($j < $n && $slow($pings[$j])
                && self::meters($pings[$i]['lat'], $pings[$i]['lng'], $pings[$j]['lat'], $pings[$j]['lng']) <= self::CLUSTER_M) {
                $sLat += $pings[$j]['lat'];
                $sLng += $pings[$j]['lng'];
                $j++;
            }
            $k = $j - $i;
            if ($k >= 2 && $pings[$j - 1]['t'] - $pings[$i]['t'] >= self::STOP_MIN_SECONDS) {
                $stops[] = ['lat' => $sLat / $k, 'lng' => $sLng / $k, 'start' => $pings[$i]['t'], 'end' => $pings[$j - 1]['t'], 'i0' => $i, 'i1' => $j - 1];
                $i = $j;
            } else {
                $i++;
            }
        }
        return $stops;
    }

    /**
     * What a stop is: a named place (inside its radius, nearest wins), else a client property within
     * PROPERTY_RADIUS_M, else unnamed.
     * @return array{type: string, id: ?int, name: string, kind: ?string}
     */
    public static function label(float $lat, float $lng, array $properties, array $places): array
    {
        $best = null;
        foreach ($places as $pl) {
            $m = self::meters($lat, $lng, (float)$pl['lat'], (float)$pl['lng']);
            if ($m <= (float)($pl['radius_m'] ?? 150) && ($best === null || $m < $best[0])) $best = [$m, $pl];
        }
        if ($best) {
            return ['type' => 'place', 'id' => (int)$best[1]['id'], 'name' => (string)$best[1]['name'], 'kind' => (string)$best[1]['kind']];
        }
        $p = ReceiptTrailService::nearestWithin($properties, $lat, $lng, self::PROPERTY_RADIUS_M);
        if ($p) {
            $name = trim((string)($p['property_name'] ?? '')) ?: trim((string)($p['name'] ?? '')) ?: trim((string)($p['address'] ?? ''));
            return ['type' => 'property', 'id' => (int)$p['id'], 'name' => $name !== '' ? $name : 'Property #' . (int)$p['id'], 'kind' => null];
        }
        return ['type' => 'unnamed', 'id' => null, 'name' => 'Unnamed stop', 'kind' => null];
    }

    /** Haversine km along pings[i0..i1]. */
    public static function km(array $pings, int $i0, int $i1): float
    {
        $m = 0.0;
        for ($i = max(0, $i0); $i < $i1 && $i + 1 < count($pings); $i++) {
            $m += self::meters($pings[$i]['lat'], $pings[$i]['lng'], $pings[$i + 1]['lat'], $pings[$i + 1]['lng']);
        }
        return $m / 1000;
    }

    /**
     * The day as alternating stops and drives, labelled. Adjacent stops with the same label and
     * a short gap are merged.
     * @return list<array> stop: {type:'stop', start, end, minutes, lat, lng, label, i0, i1}
     *                     drive: {type:'drive', start, end, minutes, km, i0, i1}
     */
    public static function segments(array $pings, array $properties, array $places): array
    {
        $stops = [];
        foreach (self::detectStops($pings) as $s) {
            $s['label'] = self::label($s['lat'], $s['lng'], $properties, $places);
            $prev = $stops ? $stops[count($stops) - 1] : null;
            if ($prev && $s['label']['type'] !== 'unnamed' && $prev['label']['type'] === $s['label']['type']
                && $prev['label']['id'] === $s['label']['id'] && $s['start'] - $prev['end'] <= self::MERGE_GAP_SECONDS) {
                $stops[count($stops) - 1]['end'] = $s['end'];
                $stops[count($stops) - 1]['i1'] = $s['i1'];
                continue;
            }
            $stops[] = $s;
        }
        $out = [];
        foreach ($stops as $k => $s) {
            if ($k > 0) {
                $p = $stops[$k - 1];
                $out[] = ['type' => 'drive', 'start' => $p['end'], 'end' => $s['start'], 'minutes' => round(($s['start'] - $p['end']) / 60, 1),
                          'km' => round(self::km($pings, $p['i1'], $s['i0']), 2), 'i0' => $p['i1'], 'i1' => $s['i0']];
            }
            $out[] = ['type' => 'stop', 'start' => $s['start'], 'end' => $s['end'], 'minutes' => round(($s['end'] - $s['start']) / 60, 1),
                      'lat' => round($s['lat'], 6), 'lng' => round($s['lng'], 6), 'label' => $s['label'], 'i0' => $s['i0'], 'i1' => $s['i1']];
        }
        // Still driving at the last ping: an open drive from the last stop.
        if ($stops && ($last = $stops[count($stops) - 1])['i1'] < count($pings) - 1) {
            $n = count($pings) - 1;
            $out[] = ['type' => 'drive', 'start' => $last['end'], 'end' => $pings[$n]['t'], 'minutes' => round(($pings[$n]['t'] - $last['end']) / 60, 1),
                      'km' => round(self::km($pings, $last['i1'], $n), 2), 'i0' => $last['i1'], 'i1' => $n, 'open' => true];
        }
        return $out;
    }

    private static function isAnchor(array $stop): bool
    {
        return $stop['label']['type'] === 'property' || ($stop['label']['type'] === 'place' && $stop['label']['kind'] === 'yard');
    }

    /** A stop that is its own leg end (not folded into the drive). */
    private static function isEndpoint(array $stop): bool
    {
        return $stop['label']['type'] === 'place' || ($stop['end'] - $stop['start']) >= self::UNNAMED_MIN_SECONDS;
    }

    /**
     * Overhead runs: the stops between two anchors (client property / yard) that include a dump or
     * supplier. A run still away at the last ping is returned with returned_at = null (open).
     * @return list<array{trip_key: string, left_at: int, returned_at: ?int, from: ?array, to: ?array,
     *   legs: list<array>, away_points: list<array>, drive_min: float, km: float, minutes: float}>
     *   legs: one per named place stop — {place_id, name, kind, arrived_at, departed_at, onsite_min, drive_min, km}
     */
    public static function runs(array $segments, string $date = ''): array
    {
        $stops = array_values(array_filter($segments, fn($s) => $s['type'] === 'stop'));
        $drives = array_values(array_filter($segments, fn($s) => $s['type'] === 'drive'));
        $openDrive = null;
        foreach ($drives as $d) if (!empty($d['open'])) $openDrive = $d;

        // Group stops between anchors.
        $groups = [];
        $cur = ['from' => null, 'mid' => []];
        foreach ($stops as $s) {
            if (self::isAnchor($s)) {
                if ($cur['mid'] || $cur['from'] !== null) {
                    $cur['to'] = $s;
                    $groups[] = $cur;
                }
                $cur = ['from' => $s, 'mid' => []];
            } else {
                $cur['mid'][] = $s;
            }
        }
        if ($cur['mid']) {
            $cur['to'] = null;
            $groups[] = $cur;
        }

        $runs = [];
        foreach ($groups as $g) {
            $hasOverhead = false;
            foreach ($g['mid'] as $s) {
                if ($s['label']['type'] === 'place' && in_array($s['label']['kind'], self::OVERHEAD_KINDS, true)) $hasOverhead = true;
            }
            if (!$hasOverhead) continue;

            $from = $g['from'];
            $to = $g['to'];
            $ends = array_values(array_filter($g['mid'], fn($s) => self::isEndpoint($s)));
            // Leg chain: from → e1 → … → en → to (or → last ping when still away).
            $chain = array_merge($from ? [['a' => true, 's' => $from]] : [], array_map(fn($s) => ['a' => false, 's' => $s], $ends),
                $to ? [['a' => true, 's' => $to]] : []);
            $share = [];   // index in $ends => [min, km]
            $kmAll = 0.0; $minAll = 0.0;
            for ($k = 0; $k + 1 < count($chain); $k++) {
                [$m, $km] = self::legBetween($segments, $chain[$k]['s']['end'], $chain[$k + 1]['s']['start']);
                $kmAll += $km; $minAll += $m;
                $owners = [];
                foreach ([$k, $k + 1] as $c) if (!$chain[$c]['a']) $owners[] = $c - ($from ? 1 : 0);
                foreach ($owners as $o) {
                    $share[$o][0] = ($share[$o][0] ?? 0) + $m / count($owners);
                    $share[$o][1] = ($share[$o][1] ?? 0) + $km / count($owners);
                }
            }
            if (!$to && $ends) {   // still away: everything since the last stop belongs to it
                $o = count($ends) - 1;
                $until = $openDrive['end'] ?? end($g['mid'])['end'];
                [$m, $km] = self::legBetween($segments, $ends[$o]['end'], $until);
                $share[$o][0] = ($share[$o][0] ?? 0) + $m;
                $share[$o][1] = ($share[$o][1] ?? 0) + $km;
                $kmAll += $km; $minAll += $m;
            }

            $legs = [];
            $away = [];
            foreach ($ends as $o => $s) {
                $away[] = ['lat' => $s['lat'], 'lng' => $s['lng']];
                $legs[] = [
                    'place_id'    => $s['label']['type'] === 'place' ? $s['label']['id'] : null,
                    'name'        => $s['label']['name'],
                    'kind'        => $s['label']['type'] === 'place' ? $s['label']['kind'] : 'unnamed',
                    'lat'         => $s['lat'], 'lng' => $s['lng'],
                    'arrived_at'  => $s['start'],
                    'departed_at' => $s['end'],
                    'onsite_min'  => round(($s['end'] - $s['start']) / 60, 1),
                    'drive_min'   => round($share[$o][0] ?? 0, 1),
                    'km'          => round($share[$o][1] ?? 0, 2),
                ];
            }
            $leftAt = $from ? $from['end'] : $g['mid'][0]['start'];
            $back = $to ? $to['start'] : null;
            $runs[] = [
                'trip_key'    => ($date !== '' ? $date : date('Y-m-d', $leftAt)) . '@' . date('H:i', $leftAt),
                'left_at'     => $leftAt,
                'returned_at' => $back,
                'from'        => $from && $from['label']['type'] === 'property' ? ['property_id' => $from['label']['id'], 'name' => $from['label']['name'], 'lat' => $from['lat'], 'lng' => $from['lng']] : null,
                'to'          => $to && $to['label']['type'] === 'property' ? ['property_id' => $to['label']['id'], 'name' => $to['label']['name'], 'lat' => $to['lat'], 'lng' => $to['lng']] : null,
                'to_yard'     => $to !== null && $to['label']['type'] === 'place',
                'legs'        => $legs,
                'away_points' => $away,
                'drive_min'   => round($minAll, 1),
                'km'          => round($kmAll, 2),
                'minutes'     => round((($back ?? ($openDrive['end'] ?? end($g['mid'])['end'])) - $leftAt) / 60, 1),
            ];
        }
        return $runs;
    }

    /**
     * Minutes and km of everything between two times: the drives, plus short stops folded in.
     * @return array{0: float, 1: float}
     */
    private static function legBetween(array $segments, int $from, int $to): array
    {
        $m = ($to - $from) / 60;
        $km = 0.0;
        foreach ($segments as $s) {
            if ($s['type'] === 'drive' && $s['start'] >= $from && $s['end'] <= $to) $km += $s['km'];
        }
        return [$m, $km];
    }

    /** Unnamed stops long enough to be somewhere (≥ UNNAMED_MIN_SECONDS): Tim names them once. */
    public static function unnamedStops(array $segments): array
    {
        $out = [];
        foreach ($segments as $s) {
            if ($s['type'] === 'stop' && $s['label']['type'] === 'unnamed' && ($s['end'] - $s['start']) >= self::UNNAMED_MIN_SECONDS) {
                $out[] = ['lat' => $s['lat'], 'lng' => $s['lng'], 'start' => $s['start'], 'end' => $s['end'], 'minutes' => $s['minutes']];
            }
        }
        return $out;
    }

    /**
     * Who was in the truck.
     * @param int[]      $clocked  user ids clocked in during the run
     * @param array      $phones   [uid => [{lat, lng, t}]] while the truck was away
     * @param array|null $home     the property it left {lat, lng}, or null
     * @param array      $away     stops the truck made away from it [{lat, lng}]
     * @param int|null   $override owner toggle: 1 one man, 0 two man
     * @return array{one_man: ?bool, driver_id: ?int, in_truck: int[], stayed: int[], unknown: int[], basis: string, crew_count: ?int}
     */
    public static function crewVerdict(array $clocked, array $phones, ?array $home, array $away, ?int $override = null): array
    {
        $clocked = array_values(array_unique(array_map('intval', $clocked)));
        $in = []; $stayed = []; $unknown = [];
        foreach ($clocked as $uid) {
            $pts = $phones[$uid] ?? [];
            $near = 0; $with = 0;
            foreach ($pts as $p) {
                foreach ($away as $a) {
                    if (self::meters($p['lat'], $p['lng'], (float)$a['lat'], (float)$a['lng']) <= self::WITH_TRUCK_M) { $with++; continue 2; }
                }
                if ($home && self::meters($p['lat'], $p['lng'], (float)$home['lat'], (float)$home['lng']) <= self::STAYED_M) $near++;
            }
            if ($with > 0) $in[] = $uid;
            elseif ($near > 0) $stayed[] = $uid;
            else $unknown[] = $uid;
        }
        $v = ['one_man' => null, 'driver_id' => null, 'in_truck' => $in, 'stayed' => $stayed, 'unknown' => $unknown, 'basis' => 'none', 'crew_count' => null];

        if (count($clocked) === 1) {
            $v = array_merge($v, ['one_man' => true, 'driver_id' => $clocked[0], 'in_truck' => $clocked, 'stayed' => [], 'unknown' => [], 'basis' => 'clock', 'crew_count' => 1]);
        } elseif (count($in) >= 2) {
            $v = array_merge($v, ['one_man' => false, 'driver_id' => $in[0], 'basis' => 'phone', 'crew_count' => count($in)]);
        } elseif (count($in) === 1) {
            $v = array_merge($v, ['one_man' => true, 'driver_id' => $in[0], 'basis' => $unknown ? 'default' : 'phone', 'crew_count' => 1]);
        } elseif ($clocked && count($unknown) === 1 && $stayed) {
            // Everyone else's phone is at the property: the one we can't see was driving.
            $v = array_merge($v, ['one_man' => true, 'driver_id' => $unknown[0], 'in_truck' => $unknown, 'unknown' => [], 'basis' => 'phone', 'crew_count' => 1]);
        } elseif ($clocked && $unknown && $home !== null) {
            // No phone tells us; the run left a client property, so someone stayed on site.
            $v = array_merge($v, ['one_man' => true, 'basis' => 'default', 'crew_count' => 1]);
        }

        if ($override !== null) {
            $v['one_man'] = $override === 1;
            $v['basis'] = 'manual';
            $v['crew_count'] = $override === 1 ? 1 : max(2, (int)$v['crew_count']);
            if ($override === 1 && $v['driver_id'] === null && count($in) + count($unknown) === 1) {
                $v['driver_id'] = $in[0] ?? $unknown[0];
            }
        }
        return $v;
    }
}
