<?php
/**
 * UnscheduledWorkRules — Otto: "the crew worked somewhere with nothing scheduled".
 *
 * Pure functions only (unit tested). UnscheduledWorkService loads the rows.
 *
 * The case it exists for: Monday 2026-10-05 the crew spent the morning at 2448 Larch St
 * doing a yew hedge reduction. Nothing was scheduled there, so no visit, no time and no
 * invoice existed until the owner invoiced it by hand.
 *
 * Evidence, strongest first (owner, 2026-10-07: "Truck stops are a good indicator"):
 *   truck   TripSegmentService stops (Trackimo) at a client property, ≥ MIN_TRUCK_MIN. A truck
 *           stop alone is enough to flag.
 *   phone   crew_location_history fixes clustered at a property (≤ RADIUS_M of its pin, or
 *           inside its job_geofences polygon). Corroborates a truck stop; flags on its own only
 *           when the truck wasn't there (≥ MIN_CREW_MIN with ≥ MIN_CREW_FIXES fixes).
 *   clock   time_clock_entries clock-in / clock-out coordinates, and job timer start / end
 *           coordinates — single points, folded into the phone dwell of that person.
 *
 * Never flagged: points inside a named place (ops_places: dump, supplier, yard, fuel), the
 * office (ops_settings office_latitude / office_longitude) or a crew member's home
 * (users.home_lat / home_lng / home_radius_meters).
 *
 * A property with a visit that day (scheduled, started or done — a SKIPPED visit doesn't count)
 * is not "unscheduled"; a truck stop near several properties goes to the one that WAS scheduled
 * (a truck parks on the street; the pin is a guess).
 *
 * Extra work beyond the scheduled visit (2026-10-07: the Larch hedge job showed up as "scheduled"
 * because Tim ran a timer on that day's 30-min lawn cut): when the stay is ≥ EXTRA_MIN longer than
 * the planned length of that day's visit(s) AND ≥ EXTRA_X × it, it is flagged as kind 'extra'. The
 * plan length is the reference; the visit timers only when no plan length is set (a timer left on
 * the lawn cut all morning would otherwise hide the hedge work).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class UnscheduledWorkRules
{
    public const MIN_TRUCK_MIN   = 30;
    public const MIN_CREW_MIN    = 30;
    public const MIN_CREW_FIXES  = 3;
    /** Kept in the evidence (not flagged) from this long — "they were there 15 min". */
    public const KEEP_DWELL_MIN  = 10;
    public const RADIUS_M        = 120;
    /** Fixes at the same property further apart than this are two dwells. */
    public const CREW_GAP_SECONDS = 1200;
    public const OFFICE_RADIUS_M = 150;
    public const HOME_RADIUS_M   = 150;
    public const LOOKBACK_DAYS   = 14;
    /** Shown in the dry run (with distance, included or not) — every client property this close to a site. */
    public const NEARBY_M        = 250;
    /** A crew dwell overlapping a truck stop in time and this close to it is part of the same site. */
    public const SITE_JOIN_M     = 250;
    public const SITE_JOIN_SECONDS = 300;
    /** Bump when a rule changes, so a stale OPcache on production shows in the dry run (build vs build_on_disk). */
    public const BUILD = '2026-10-08c';
    /** Extra work beyond a scheduled visit: the stay beats the plan by this much… */
    public const EXTRA_MIN       = 60;
    /** …and is at least this many times the plan (or the timer, when the plan has no length). */
    public const EXTRA_X         = 2.0;

    // ─────────────────────────────────────────────────────────────────────────
    // Geometry
    // ─────────────────────────────────────────────────────────────────────────

    public static function meters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $r * asin(min(1, sqrt($a)));
    }

    /** job_geofences.polygon_json ([[lat,lng], ...]) → ring, or [] when unreadable. */
    public static function ring(?string $json): array
    {
        $pts = json_decode((string)$json, true);
        if (!is_array($pts)) return [];
        $out = [];
        foreach ($pts as $p) {
            if (is_array($p) && isset($p[0], $p[1]) && is_numeric($p[0]) && is_numeric($p[1])) $out[] = [(float)$p[0], (float)$p[1]];
        }
        return count($out) >= 3 ? $out : [];
    }

    /** Ray casting; ring is [[lat, lng], ...]. */
    public static function inPolygon(float $lat, float $lng, array $ring): bool
    {
        $in = false;
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$yi, $xi] = $ring[$i];
            [$yj, $xj] = $ring[$j];
            if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) $in = !$in;
        }
        return $in;
    }

    /**
     * Every client property this point could be: inside a geofence polygon first, then pins within
     * RADIUS_M nearest first. Ids only; empty = nowhere.
     * @param array $properties [{id, latitude, longitude}]
     * @param array $fences     [{property_id, ring, lat_min, lat_max, lng_min, lng_max}]
     * @return list<int>
     */
    public static function propertiesAt(float $lat, float $lng, array $properties, array $fences): array
    {
        $ids = [];
        foreach ($fences as $f) {
            if ($lat < $f['lat_min'] || $lat > $f['lat_max'] || $lng < $f['lng_min'] || $lng > $f['lng_max']) continue;
            if ($f['ring'] && self::inPolygon($lat, $lng, $f['ring'])) $ids[(int)$f['property_id']] = -1.0;
        }
        foreach ($properties as $p) {
            $plat = (float)($p['latitude'] ?? 0);
            $plng = (float)($p['longitude'] ?? 0);
            if ($plat == 0.0 && $plng == 0.0) continue; // failed geocode (property 22 trap)
            $m = self::meters($lat, $lng, $plat, $plng);
            if ($m <= self::RADIUS_M && !isset($ids[(int)$p['id']])) $ids[(int)$p['id']] = $m;
        }
        asort($ids);
        return array_map('intval', array_keys($ids));
    }

    /**
     * Name of the exclusion zone this point is in (home / office / dump…), or null.
     * @param array $zones [{lat, lng, radius_m, name, kind}]
     */
    public static function excludedBy(float $lat, float $lng, array $zones): ?string
    {
        foreach ($zones as $z) {
            if (self::meters($lat, $lng, (float)$z['lat'], (float)$z['lng']) <= (float)$z['radius_m']) return (string)$z['name'];
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Dwells
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Truck stops that could be client work. From TripSegmentService::segments(): stops labelled to a
     * named place are not (dump, supplier, yard, fuel); the rest get every property they could be.
     * With $pings (the day's trail the segments index into), a property counts when it is within
     * RADIUS_M of ANY ping of the stop, not only of the stop's average point — a truck parked on a
     * corner is near both lots (2505 W 8th + 2448 Larch, 2026-10-05). Properties are nearest-first.
     * Each stop also lists every property within NEARBY_M ('nearby': id, m_centroid, m_nearest, included)
     * so the dry run shows why a neighbour was or wasn't part of it.
     * @return list<array{start: int, end: int, minutes: float, lat: float, lng: float, props: list<int>, label: string, nearby: list<array>}>
     */
    public static function truckDwells(array $segments, array $properties, array $fences, array $zones, array $pings = []): array
    {
        $out = [];
        foreach ($segments as $s) {
            if (($s['type'] ?? '') !== 'stop') continue;
            $label = (array)($s['label'] ?? []);
            if (($label['type'] ?? '') === 'place') continue;
            $min = ($s['end'] - $s['start']) / 60;
            if ($min < self::KEEP_DWELL_MIN) continue;
            $lat = (float)$s['lat'];
            $lng = (float)$s['lng'];
            if (self::excludedBy($lat, $lng, $zones) !== null) continue;
            $points = [[$lat, $lng]];
            if ($pings && isset($s['i0'], $s['i1'])) {
                for ($i = (int)$s['i0']; $i <= (int)$s['i1'] && $i < count($pings); $i++) $points[] = [(float)$pings[$i]['lat'], (float)$pings[$i]['lng']];
            }
            $in = [];
            foreach ($points as [$y, $x]) foreach (self::propertiesAt($y, $x, $properties, $fences) as $p) $in[(int)$p] = true;
            $nearby = self::nearby($lat, $lng, $points, $properties, $in);
            // Nearest first (by the closest ping); a fence-only match keeps its place after the pins.
            $props = array_column(array_filter($nearby, fn($n) => $n['included']), 'id');
            foreach (array_keys($in) as $p) if (!in_array($p, $props, true)) $props[] = $p;
            if (!$props && ($label['type'] ?? '') === 'property' && !empty($label['id'])) $props = [(int)$label['id']];
            if (!$props) continue;
            $out[] = ['start' => (int)$s['start'], 'end' => (int)$s['end'], 'minutes' => round($min, 1),
                      'lat' => $lat, 'lng' => $lng, 'props' => array_map('intval', $props), 'label' => (string)($label['name'] ?? ''), 'nearby' => $nearby];
        }
        return $out;
    }

    /**
     * Client properties within NEARBY_M of a stop, included first then nearest: {id, m_centroid, m_nearest, included}.
     * $included: [property_id => true] the ones that made it in (radius / fence of any point).
     */
    public static function nearby(float $lat, float $lng, array $points, array $properties, array $included): array
    {
        $out = [];
        foreach ($properties as $p) {
            $plat = (float)($p['latitude'] ?? 0);
            $plng = (float)($p['longitude'] ?? 0);
            if ($plat == 0.0 && $plng == 0.0) continue;
            $mc = self::meters($lat, $lng, $plat, $plng);
            $mn = $mc;
            foreach ($points as [$y, $x]) $mn = min($mn, self::meters($y, $x, $plat, $plng));
            if ($mn > self::NEARBY_M && !isset($included[(int)$p['id']])) continue;
            $out[] = ['id' => (int)$p['id'], 'm_centroid' => (int)round($mc), 'm_nearest' => (int)round($mn), 'included' => isset($included[(int)$p['id']])];
        }
        usort($out, fn($a, $b) => [$b['included'], $a['m_nearest']] <=> [$a['included'], $b['m_nearest']]);
        return $out;
    }

    /**
     * One person's fixes (phone, clock punches, timer start/stop) clustered into dwells at a property.
     * A fix with no property inside the gap is jitter and is skipped; a fix at another property, or a
     * gap longer than CREW_GAP_SECONDS, ends the dwell. 'all_props' is every property any fix of the dwell
     * could be (site membership); 'props' the ones every fix agrees on; 'track' where each fix puts the person.
     * @param list<array{lat: float, lng: float, t: int, src: string}> $fixes
     * @return list<array{start: int, end: int, minutes: float, fixes: int, sources: array, props: list<int>, all_props: list<int>,
     *   lat: float, lng: float, track: list<array{0: int, 1: int}>}>
     */
    public static function crewDwells(array $fixes, array $properties, array $fences, array $zones): array
    {
        usort($fixes, fn($a, $b) => $a['t'] <=> $b['t']);
        $out = [];
        $cur = null;
        $close = function () use (&$cur, &$out) {
            if ($cur && $cur['fixes'] >= 2) {
                $cur['minutes'] = round(($cur['end'] - $cur['start']) / 60, 1);
                $cur['lat'] = $cur['_lat'] / $cur['fixes'];
                $cur['lng'] = $cur['_lng'] / $cur['fixes'];
                $cur['all_props'] = array_keys($cur['_all']);
                unset($cur['_lat'], $cur['_lng'], $cur['_all']);
                if ($cur['minutes'] >= self::KEEP_DWELL_MIN) $out[] = $cur;
            }
            $cur = null;
        };
        foreach ($fixes as $f) {
            if (self::excludedBy((float)$f['lat'], (float)$f['lng'], $zones) !== null) { $close(); continue; }
            $props = self::propertiesAt((float)$f['lat'], (float)$f['lng'], $properties, $fences);
            if ($cur && $f['t'] - $cur['end'] > self::CREW_GAP_SECONDS) $close();
            if (!$props) continue;
            if ($cur && !in_array($cur['props'][0], $props, true)) $close();
            if (!$cur) {
                $cur = ['start' => (int)$f['t'], 'end' => (int)$f['t'], 'minutes' => 0.0, 'fixes' => 0, 'sources' => [], 'props' => $props,
                        'track' => [], '_lat' => 0.0, '_lng' => 0.0, '_all' => []];
            }
            $cur['end'] = (int)$f['t'];
            $cur['fixes']++;
            $cur['_lat'] += (float)$f['lat'];
            $cur['_lng'] += (float)$f['lng'];
            foreach ($props as $p) $cur['_all'][(int)$p] = true;
            $cur['track'][] = [(int)$f['t'], (int)$props[0]];   // where this fix puts the person (nearest / inside a fence)
            $cur['sources'][$f['src']] = ($cur['sources'][$f['src']] ?? 0) + 1;
            // Keep only properties every fix agrees on (first one stays the anchor).
            $keep = array_values(array_intersect($cur['props'], $props));
            if ($keep) $cur['props'] = $keep;
        }
        $close();
        return $out;
    }

    /** The property a dwell goes to on its own: one that had a visit that day if it could be that, else the nearest. */
    public static function attribute(array $props, array $scheduledIds): int
    {
        foreach ($props as $p) if (isset($scheduledIds[(int)$p])) return (int)$p;
        return (int)$props[0];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Candidates
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * One row per SITE per day where the truck or the crew stayed, decided against the day's visits.
     *
     * A site is every truck stop and crew dwell that shares a client property (a stop on the corner of
     * Larch and W 8th is BOTH 2448 Larch and 2505 W 8th — 2026-10-05). Scheduled visits at any property
     * of the site explain part of its window (their timer minutes, else plan length; a timer more than
     * EXTRA_X × the plan counts as left running → plan length), placed in time where the crew's phone
     * fixes put people. Only the unexplained remainder can be flagged, at the property the crew fixes
     * favour during it (else the nearest unscheduled one):
     *   remainder at an unscheduled property, ≥ MIN_TRUCK_MIN / MIN_CREW_MIN  → kind 'unscheduled'
     *   remainder at a scheduled property, ≥ EXTRA_MIN and the stay ≥ EXTRA_X × explained → kind 'extra'
     *   otherwise ignored ('scheduled' / 'too_short' / 'not_work').
     *
     * @param array $truck    truckDwells()
     * @param array $crew     [user_id => crewDwells()]
     * @param array $scheduledIds [property_id => list of that day's visits] (or => true: scheduled, length
     *                            unknown): {visit_id, plan_id, plan_number, service_type, status, planned_min, timer_min}
     * @param array $notWork  [property_id => true] the owner said time here isn't work (twice)
     * @return list<array> sorted flagged first, then longest
     */
    public static function candidates(string $date, array $truck, array $crew, array $scheduledIds, array $notWork = []): array
    {
        // Every dwell, then union-find on shared properties.
        $items = [];
        foreach ($truck as $d) $items[] = ['src' => 'truck', 'd' => $d, 'props' => array_map('intval', $d['props'])];
        foreach ($crew as $uid => $dwells) foreach ($dwells as $d) $items[] = ['src' => 'crew', 'd' => $d + ['user_id' => (int)$uid], 'props' => array_map('intval', $d['all_props'] ?? $d['props'])];
        $parent = array_keys($items);
        $find = function (int $i) use (&$parent, &$find): int { return $parent[$i] === $i ? $i : ($parent[$i] = $find($parent[$i])); };
        $owner = [];
        foreach ($items as $i => $it) {
            foreach ($it['props'] as $p) {
                if (isset($owner[$p])) $parent[$find($i)] = $find($owner[$p]);
                else $owner[$p] = $i;
            }
        }
        // A crew dwell during a truck stop and near it is the same job, even when their lots differ
        // (phone on 2505 W 8th, truck pinned to 2448 Larch).
        foreach ($items as $i => $a) {
            if ($a['src'] !== 'truck') continue;
            foreach ($items as $j => $b) {
                if ($b['src'] !== 'crew' || !isset($b['d']['lat'])) continue;
                $overlap = min($a['d']['end'], $b['d']['end']) - max($a['d']['start'], $b['d']['start']);
                if ($overlap >= self::SITE_JOIN_SECONDS && self::meters($a['d']['lat'], $a['d']['lng'], $b['d']['lat'], $b['d']['lng']) <= self::SITE_JOIN_M) {
                    $parent[$find($j)] = $find($i);
                }
            }
        }
        $sites = [];
        foreach ($items as $i => $it) $sites[$find($i)][] = $it;

        $out = [];
        foreach ($sites as $members) {
            $c = self::site($date, $members, $scheduledIds, $notWork);
            if ($c) $out[] = $c;
        }
        usort($out, fn($a, $b) => [$b['flag'], $b['minutes']] <=> [$a['flag'], $a['minutes']]);
        return $out;
    }

    /** One site → one candidate (see candidates()). */
    private static function site(string $date, array $members, array $scheduledIds, array $notWork): ?array
    {
        $truckD = array_values(array_map(fn($m) => $m['d'], array_filter($members, fn($m) => $m['src'] === 'truck')));
        $crewD = array_values(array_map(fn($m) => $m['d'], array_filter($members, fn($m) => $m['src'] === 'crew')));
        // Site properties, nearest first: truck stops' order, then crew dwells'.
        $props = [];
        foreach ($truckD as $d) foreach ($d['props'] as $p) $props[(int)$p] = true;
        foreach ($crewD as $d) foreach (($d['all_props'] ?? $d['props']) as $p) $props[(int)$p] = true;
        $props = array_keys($props);
        $nearby = [];
        foreach ($truckD as $d) foreach ((array)($d['nearby'] ?? []) as $n) {
            if (!isset($nearby[$n['id']]) || $n['m_nearest'] < $nearby[$n['id']]['m_nearest']) $nearby[$n['id']] = $n;
        }
        foreach ($nearby as $id => $n) $nearby[$id]['included'] = in_array((int)$id, $props, true);

        $truckMin = array_sum(array_column($truckD, 'minutes'));
        $crewWin = self::window($crewD);
        $crewMin = $crewWin ? ($crewWin[1] - $crewWin[0]) / 60 : 0;
        $people = array_values(array_unique(array_map(fn($d) => (int)$d['user_id'], $crewD)));
        $fixes = array_sum(array_column($crewD, 'fixes'));
        $sources = [];
        foreach ($crewD as $d) foreach ($d['sources'] as $k => $n) $sources[$k] = ($sources[$k] ?? 0) + $n;
        // The truck sets the window when it was there; the crew fills in when it wasn't.
        if ($truckD) { $start = min(array_column($truckD, 'start')); $end = max(array_column($truckD, 'end')); }
        elseif ($crewWin) { [$start, $end] = $crewWin; }
        else return null;
        $stay = (int)round(($end - $start) / 60);
        $hasTruck = $truckMin >= self::MIN_TRUCK_MIN;
        $hasCrew = $crewMin >= self::MIN_CREW_MIN && $fixes >= self::MIN_CREW_FIXES;
        $confidence = $hasTruck && $crewD ? 'high'
            : ($hasTruck ? 'medium' : ($hasCrew && (count($people) > 1 || isset($sources['clock']) || isset($sources['timer'])) ? 'medium' : 'low'));

        // Where the crew phones put people: [t => property], from each dwell's track.
        $track = [];
        foreach ($crewD as $d) foreach ((array)($d['track'] ?? []) as [$t, $p]) if (in_array((int)$p, $props, true)) $track[] = [(int)$t, (int)$p];
        usort($track, fn($a, $b) => $a[0] <=> $b[0]);

        // Scheduled visits at any site property, placed where the crew was.
        $visits = [];
        $unknown = null;   // a scheduled property whose visit length is unknown → it explains everything
        foreach ($props as $p) {
            if (!isset($scheduledIds[$p])) continue;
            $list = is_array($scheduledIds[$p]) ? array_values($scheduledIds[$p]) : [];
            $known = false;
            foreach ($list as $v) {
                $m = self::visitMinutes($v);
                if ($m === null) continue;
                $known = true;
                $first = null;
                foreach ($track as [$t, $tp]) if ($tp === $p && $t >= $start && $t <= $end) { $first = $t; break; }
                $visits[] = $v + ['property_id' => $p, 'explains' => $m[0], 'explains_basis' => $m[1], 'first_seen' => $first];
            }
            if (!$known && $unknown === null) $unknown = $p;
        }
        usort($visits, fn($a, $b) => [$a['first_seen'] ?? $start, $a['property_id']] <=> [$b['first_seen'] ?? $start, $b['property_id']]);
        $cursor = $start;
        $explained = [];
        foreach ($visits as $v) {
            $s = max($cursor, min($end, $v['first_seen'] ?? $start));
            $e = min($end, $s + $v['explains'] * 60);
            $explained[] = $v + ['from' => $s, 'to' => $e];
            $cursor = max($cursor, $e);
        }
        $explainedMin = (int)round(array_sum(array_map(fn($x) => $x['to'] - $x['from'], $explained)) / 60);
        // The remainder: the window minus the explained intervals.
        $gaps = [];
        $t0 = $start;
        foreach ($explained as $x) {
            if ($x['from'] > $t0) $gaps[] = [$t0, $x['from']];
            $t0 = max($t0, $x['to']);
        }
        if ($end > $t0) $gaps[] = [$t0, $end];
        $remainder = $unknown !== null ? 0 : max(0, $stay - $explainedMin);
        // A few minutes before the crew is first seen at the visit is not "the remainder".
        $big = array_values(array_filter($gaps, fn($g) => $g[1] - $g[0] >= self::KEEP_DWELL_MIN * 60)) ?: $gaps;
        $remStart = $big ? $big[0][0] : $start;
        $remEnd = $big ? $big[count($big) - 1][1] : $end;

        // Who the remainder belongs to: the crew fixes during it, else the nearest unscheduled property.
        $votes = [];
        foreach ($track as [$t, $p]) {
            foreach ($gaps as [$a, $b]) if ($t >= $a && $t <= $b) { $votes[$p] = ($votes[$p] ?? 0) + 1; break; }
        }
        arsort($votes);
        $unscheduled = array_values(array_filter($props, fn($p) => !isset($scheduledIds[$p])));
        $pid = $votes ? (int)array_key_first($votes) : ($unscheduled[0] ?? ($explained ? (int)$explained[count($explained) - 1]['property_id'] : $props[0]));
        if ($unknown !== null) $pid = $unknown;

        $atScheduled = isset($scheduledIds[$pid]);
        $kind = $atScheduled ? 'extra' : 'unscheduled';
        $ignored = null;
        if ($unknown !== null) {
            $ignored = 'scheduled';
        } elseif ($atScheduled) {
            if (!($remainder >= self::EXTRA_MIN && $stay >= self::EXTRA_X * max(1, $explainedMin))) $ignored = 'scheduled';
        } elseif ($explained) {
            // Part explained by a neighbour's visit: the rest must still be a real stay on its own.
            if ($remainder < min(self::MIN_TRUCK_MIN, self::MIN_CREW_MIN)) $ignored = 'scheduled';
        }
        if ($ignored === null && isset($notWork[$pid])) $ignored = 'not_work';
        if ($ignored === null && !$hasTruck && !$hasCrew) $ignored = 'too_short';
        if ($ignored === 'scheduled' && $explained && !$atScheduled) $pid = (int)$explained[0]['property_id'];

        $ref = $explained ? ['minutes' => $explainedMin, 'basis' => self::basisOf($explained)] : null;
        $flagStart = $explained ? $remStart : $start;
        $flagEnd = $explained ? $remEnd : $end;
        return [
            'property_id' => $pid, 'date' => $date,
            'kind' => $kind, 'start' => (int)$flagStart, 'end' => (int)$flagEnd,
            'minutes' => $explained ? $remainder : $stay,
            'site_start' => (int)$start, 'site_end' => (int)$end, 'site_minutes' => $stay, 'site_props' => $props,
            'explained' => array_map(fn($x) => [
                'visit_id' => $x['visit_id'] ?? null, 'property_id' => $x['property_id'], 'service_type' => (string)($x['service_type'] ?? ''),
                'status' => (string)($x['status'] ?? ''), 'plan_number' => (string)($x['plan_number'] ?? ''),
                'minutes' => (int)round(($x['to'] - $x['from']) / 60), 'basis' => $x['explains_basis'], 'from' => (int)$x['from'], 'to' => (int)$x['to'],
            ], $explained),
            'scheduled_visits' => $atScheduled && is_array($scheduledIds[$pid]) ? array_values($scheduledIds[$pid]) : [],
            'planned_min' => $ref['minutes'] ?? null, 'planned_basis' => $ref['basis'] ?? null,
            'extra_min' => $ref !== null ? $remainder : null,
            'truck_min' => (int)round($truckMin), 'crew_min' => (int)round($crewMin),
            'people' => $people, 'fixes' => (int)$fixes, 'sources' => $sources,
            'truck_stops' => array_map(fn($d) => ['from' => $d['start'], 'to' => $d['end'], 'minutes' => $d['minutes'], 'label' => $d['label']], $truckD),
            'crew' => array_map(fn($d) => ['user_id' => (int)$d['user_id'], 'from' => $d['start'], 'to' => $d['end'], 'minutes' => $d['minutes'], 'fixes' => $d['fixes'], 'sources' => $d['sources']], $crewD),
            'crew_votes' => $votes,
            'nearby' => array_values($nearby),
            'basis' => $hasTruck ? ($crewD ? 'truck+crew' : 'truck') : 'crew',
            'confidence' => $confidence,
            'flag' => $ignored === null,
            'ignored' => $ignored,
        ];
    }

    /**
     * How long one scheduled visit explains: its timers' on-site minutes, else its plan length. A timer
     * more than EXTRA_X × the plan length counts as left running (the plan length is used).
     * @return array{0: int, 1: string}|null [minutes, 'timer'|'plan'], null when neither is known
     */
    public static function visitMinutes(array $v): ?array
    {
        $timer = isset($v['timer_min']) && $v['timer_min'] !== null ? (int)$v['timer_min'] : 0;
        $plan = isset($v['planned_min']) && $v['planned_min'] !== null ? (int)$v['planned_min'] : 0;
        if ($timer > 0 && ($plan <= 0 || $timer <= self::EXTRA_X * $plan)) return [$timer, 'timer'];
        if ($plan > 0) return [$plan, 'plan'];
        return null;
    }

    private static function basisOf(array $explained): string
    {
        $b = array_values(array_unique(array_column($explained, 'explains_basis')));
        return count($b) === 1 ? $b[0] : 'mixed';
    }

    /**
     * What that day's visits were meant to take: the plan lengths added up, or (no plan length on any)
     * the timers' on-site minutes. Null when neither is known.
     * @return array{minutes: int, basis: string}|null
     */
    public static function reference(array $visits): ?array
    {
        $sum = 0;
        $bases = [];
        foreach ($visits as $v) {
            $m = self::visitMinutes($v);
            if ($m === null) continue;
            $sum += $m[0];
            $bases[$m[1]] = true;
        }
        if (!$bases) return null;
        return ['minutes' => $sum, 'basis' => count($bases) === 1 ? (string)array_key_first($bases) : 'mixed'];
    }

    public static function isExtra(int $stayMin, int $refMin): bool
    {
        return $refMin > 0 && $stayMin - $refMin >= self::EXTRA_MIN && $stayMin >= self::EXTRA_X * $refMin;
    }

    /** [start, end] over dwells, or null. */
    private static function window(array $dwells): ?array
    {
        if (!$dwells) return null;
        return [min(array_column($dwells, 'start')), max(array_column($dwells, 'end'))];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Words
    // ─────────────────────────────────────────────────────────────────────────

    /** "Crew at 2448 Larch St, Mon 8:17–12:02 (3 h 45 min) — the lawn cut is planned at 30 min → 3 h 15 min extra." */
    public static function extraText(array $c, string $street): string
    {
        $n = count($c['scheduled_visits']);
        $what = $n > 1 ? 'the ' . $n . ' visits' : 'the ' . (strtolower(trim((string)($c['scheduled_visits'][0]['service_type'] ?? ''))) ?: 'visit');
        $is = $n > 1 ? 'are' : 'is';
        $ref = $c['planned_basis'] === 'timer' ? "{$what} {$is} timed at " : "{$what} {$is} planned at ";
        $s = $c['site_start'] ?? $c['start'];
        $e = $c['site_end'] ?? $c['end'];
        return 'Crew at ' . $street . ', ' . date('D', $s) . ' ' . date('g:i', $s) . '–' . date('g:i', $e)
            . ' (' . self::hours((int)($c['site_minutes'] ?? $c['minutes'])) . ') — ' . $ref . self::hours((int)$c['planned_min']) . ' → ' . self::hours((int)$c['extra_min']) . ' extra.';
    }

    /**
     * One stop, two neighbours: "Truck parked Mon 8:17–12:02 by 2505 W 8th Ave + 2448 Larch St: Alexandra Bee's
     * hedge trimming (scheduled, ~70 min) then ~2 h 35 min unexplained at 2448 Larch St."
     * @param array $names [property_id => ['street' => …, 'client' => …]]
     */
    public static function siteText(array $c, array $names): string
    {
        $st = fn(int $p) => $names[$p]['street'] ?? ('property #' . $p);
        $who = $c['truck_stops'] ? 'Truck parked ' : 'Crew phones ';
        $parts = [];
        foreach ($c['explained'] as $x) {
            $client = trim((string)($names[$x['property_id']]['client'] ?? ''));
            $parts[] = ($client !== '' ? $client . "'s " : $st((int)$x['property_id']) . ' ') . (strtolower(trim($x['service_type'])) ?: 'visit')
                . ' (scheduled, ~' . self::hours((int)$x['minutes']) . ')';
        }
        $shown = array_values(array_unique(array_merge([(int)$c['property_id']], array_map(fn($x) => (int)$x['property_id'], $c['explained']))));
        $sites = implode(' + ', array_map($st, $shown));
        return $who . date('D', $c['site_start']) . ' ' . date('g:i', $c['site_start']) . '–' . date('g:i', $c['site_end']) . ' by ' . $sites . ': '
            . implode(', ', $parts) . ' then ~' . self::hours((int)$c['minutes']) . ' unexplained at ' . $st((int)$c['property_id']) . '.';
    }

    /** "Crew at 2448 Larch St, Mon 8:10–11:40 (3 h 30 min), nothing scheduled." */
    public static function text(array $c, string $street, array $names = []): string
    {
        $elsewhere = array_filter((array)($c['explained'] ?? []), fn($x) => (int)$x['property_id'] !== (int)$c['property_id']);
        if ($elsewhere) return self::siteText($c, $names + [(int)$c['property_id'] => ['street' => $street]]);
        if (($c['kind'] ?? '') === 'extra') return self::extraText($c, $street);
        $who = $c['basis'] === 'crew' ? 'Crew phones at ' : ($c['basis'] === 'truck' ? 'Truck at ' : 'Crew at ');
        return $who . $street . ', ' . date('D', $c['start']) . ' ' . date('g:i', $c['start']) . '–' . date('g:i', $c['end'])
            . ' (' . self::hours($c['minutes']) . '), nothing scheduled.';
    }

    /** "Truck 8:10–11:40 · Nigel's phone 8:14–11:32 (212 fixes) · clock-in here 8:06". */
    public static function evidenceLine(array $c, array $names = []): string
    {
        $parts = [];
        foreach ($c['truck_stops'] as $s) $parts[] = 'Truck ' . date('g:i', $s['from']) . '–' . date('g:i', $s['to']);
        foreach ($c['crew'] as $d) {
            $n = $names[$d['user_id']] ?? ('#' . $d['user_id']);
            $src = [];
            foreach (['phone' => ['phone fix', 'phone fixes'], 'clock' => ['clock punch', 'clock punches'], 'timer' => ['timer start/stop', 'timer starts/stops']] as $k => $w) {
                if (!empty($d['sources'][$k])) $src[] = $d['sources'][$k] . ' ' . $w[$d['sources'][$k] === 1 ? 0 : 1];
            }
            $parts[] = $n . ' ' . date('g:i', $d['from']) . '–' . date('g:i', $d['to']) . ($src ? ' (' . implode(', ', $src) . ')' : '');
        }
        if (!$c['truck_stops']) $parts[] = 'no truck stop here';
        return implode(' · ', $parts);
    }

    /** "empty calendar stop #812 (no visit on it — made Oct 1 8:02 am; left behind when its visit was moved or rolled over)". */
    public static function emptyStopLine(array $st): string
    {
        $made = !empty($st['created_at']) ? 'made ' . date('M j g:i a', strtotime((string)$st['created_at'])) . '; ' : '';
        return 'empty calendar stop #' . (int)$st['id'] . ' (no visit on it — ' . $made . 'left behind when its visit was moved or rolled over)';
    }

    /** "Tim's timer 8:20–11:30 (190 min) on visit #5 scheduled Oct 6" / "… with no visit (PLN-2026-0068)". */
    public static function strayTimerLine(array $t): string
    {
        $when = date('g:i', $t['start']) . '–' . ($t['end'] ? date('g:i', $t['end']) : 'still running');
        $on = $t['visit_id'] ? 'on visit #' . $t['visit_id'] . ($t['visit_date'] ? ' scheduled ' . date('M j', strtotime((string)$t['visit_date'])) : '')
            : 'with no visit' . ($t['plan_number'] !== '' ? ' (' . $t['plan_number'] . ')' : '');
        return ($t['who'] ?? ('#' . $t['user_id'])) . "'s timer " . $when . ($t['minutes'] !== null ? ' (' . $t['minutes'] . ' min)' : '') . ' ' . $on;
    }

    public static function hours(int $minutes): string
    {
        $minutes = max(0, $minutes);
        if ($minutes < 60) return $minutes . ' min';
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $h . ' h' . ($m ? ' ' . $m . ' min' : '');
    }
}
