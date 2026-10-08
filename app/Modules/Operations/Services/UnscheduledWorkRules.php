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
 * (users.home_lat / home_lng / home_radius_meters). Never flagged: a property with a visit
 * that day — and a truck stop near several properties goes to the one that WAS scheduled
 * (a truck parks on the street; the pin is a guess).
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
     * @return list<array{start: int, end: int, minutes: float, lat: float, lng: float, props: list<int>, label: string}>
     */
    public static function truckDwells(array $segments, array $properties, array $fences, array $zones): array
    {
        $out = [];
        foreach ($segments as $s) {
            if (($s['type'] ?? '') !== 'stop') continue;
            $label = (array)($s['label'] ?? []);
            if (($label['type'] ?? '') === 'place') continue;
            $min = ($s['end'] - $s['start']) / 60;
            if ($min < self::KEEP_DWELL_MIN) continue;
            if (self::excludedBy((float)$s['lat'], (float)$s['lng'], $zones) !== null) continue;
            $props = self::propertiesAt((float)$s['lat'], (float)$s['lng'], $properties, $fences);
            if (!$props && ($label['type'] ?? '') === 'property' && !empty($label['id'])) $props = [(int)$label['id']];
            if (!$props) continue;
            $out[] = ['start' => (int)$s['start'], 'end' => (int)$s['end'], 'minutes' => round($min, 1),
                      'lat' => (float)$s['lat'], 'lng' => (float)$s['lng'], 'props' => $props, 'label' => (string)($label['name'] ?? '')];
        }
        return $out;
    }

    /**
     * One person's fixes (phone, clock punches, timer start/stop) clustered into dwells at a property.
     * A fix with no property inside the gap is jitter and is skipped; a fix at another property, or a
     * gap longer than CREW_GAP_SECONDS, ends the dwell.
     * @param list<array{lat: float, lng: float, t: int, src: string}> $fixes
     * @return list<array{start: int, end: int, minutes: float, fixes: int, sources: array, props: list<int>}>
     */
    public static function crewDwells(array $fixes, array $properties, array $fences, array $zones): array
    {
        usort($fixes, fn($a, $b) => $a['t'] <=> $b['t']);
        $out = [];
        $cur = null;
        $close = function () use (&$cur, &$out) {
            if ($cur && $cur['fixes'] >= 2) {
                $cur['minutes'] = round(($cur['end'] - $cur['start']) / 60, 1);
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
                $cur = ['start' => (int)$f['t'], 'end' => (int)$f['t'], 'minutes' => 0.0, 'fixes' => 0, 'sources' => [], 'props' => $props];
            }
            $cur['end'] = (int)$f['t'];
            $cur['fixes']++;
            $cur['sources'][$f['src']] = ($cur['sources'][$f['src']] ?? 0) + 1;
            // Keep only properties every fix agrees on (first one stays the anchor).
            $keep = array_values(array_intersect($cur['props'], $props));
            if ($keep) $cur['props'] = $keep;
        }
        $close();
        return $out;
    }

    /** The property a dwell goes to: one that had a visit that day if it could be that, else the nearest. */
    public static function attribute(array $props, array $scheduledIds): int
    {
        foreach ($props as $p) if (isset($scheduledIds[(int)$p])) return (int)$p;
        return (int)$props[0];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Candidates
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * One row per property per day where the truck or the crew stayed, decided against the day's visits.
     * @param array $truck    truckDwells()
     * @param array $crew     [user_id => crewDwells()]
     * @param array $scheduledIds [property_id => true] properties with a visit that day
     * @param array $notWork  [property_id => true] the owner said time here isn't work (twice)
     * @return list<array> sorted flagged first, then longest
     */
    public static function candidates(string $date, array $truck, array $crew, array $scheduledIds, array $notWork = []): array
    {
        $by = [];
        $touch = function (int $pid) use (&$by) {
            $by[$pid] ??= ['property_id' => $pid, 'date' => '', 'truck' => [], 'crew' => [], 'start' => PHP_INT_MAX, 'end' => 0];
        };
        foreach ($truck as $d) {
            $pid = self::attribute($d['props'], $scheduledIds);
            $touch($pid);
            $by[$pid]['truck'][] = $d;
            $by[$pid]['start'] = min($by[$pid]['start'], $d['start']);
            $by[$pid]['end'] = max($by[$pid]['end'], $d['end']);
        }
        foreach ($crew as $uid => $dwells) {
            foreach ($dwells as $d) {
                $pid = self::attribute($d['props'], $scheduledIds);
                $touch($pid);
                $by[$pid]['crew'][] = $d + ['user_id' => (int)$uid];
            }
        }
        $out = [];
        foreach ($by as $pid => $c) {
            $truckMin = array_sum(array_column($c['truck'], 'minutes'));
            $crewWin = self::window($c['crew']);
            $crewMin = $crewWin ? ($crewWin[1] - $crewWin[0]) / 60 : 0;
            $people = array_values(array_unique(array_map(fn($d) => (int)$d['user_id'], $c['crew'])));
            $fixes = array_sum(array_column($c['crew'], 'fixes'));
            $sources = [];
            foreach ($c['crew'] as $d) foreach ($d['sources'] as $k => $n) $sources[$k] = ($sources[$k] ?? 0) + $n;
            // The truck sets the window when it was there; the crew fills in when it wasn't.
            if ($c['truck']) { $start = $c['start']; $end = $c['end']; }
            elseif ($crewWin) { [$start, $end] = $crewWin; }
            else continue;
            $hasTruck = $truckMin >= self::MIN_TRUCK_MIN;
            $hasCrew = $crewMin >= self::MIN_CREW_MIN && $fixes >= self::MIN_CREW_FIXES;
            $confidence = $hasTruck && $c['crew'] ? 'high'
                : ($hasTruck ? 'medium' : ($hasCrew && (count($people) > 1 || isset($sources['clock']) || isset($sources['timer'])) ? 'medium' : 'low'));
            $ignored = null;
            if (isset($scheduledIds[$pid])) $ignored = 'scheduled';
            elseif (isset($notWork[$pid])) $ignored = 'not_work';
            elseif (!$hasTruck && !$hasCrew) $ignored = 'too_short';
            $out[] = [
                'property_id' => (int)$pid, 'date' => $date,
                'start' => (int)$start, 'end' => (int)$end, 'minutes' => (int)round(($end - $start) / 60),
                'truck_min' => (int)round($truckMin), 'crew_min' => (int)round($crewMin),
                'people' => $people, 'fixes' => (int)$fixes, 'sources' => $sources,
                'truck_stops' => array_map(fn($d) => ['from' => $d['start'], 'to' => $d['end'], 'minutes' => $d['minutes'], 'label' => $d['label']], $c['truck']),
                'crew' => array_map(fn($d) => ['user_id' => (int)$d['user_id'], 'from' => $d['start'], 'to' => $d['end'], 'minutes' => $d['minutes'], 'fixes' => $d['fixes'], 'sources' => $d['sources']], $c['crew']),
                'basis' => $hasTruck ? ($c['crew'] ? 'truck+crew' : 'truck') : 'crew',
                'confidence' => $confidence,
                'flag' => $ignored === null,
                'ignored' => $ignored,
            ];
        }
        usort($out, fn($a, $b) => [$b['flag'], $b['minutes']] <=> [$a['flag'], $a['minutes']]);
        return $out;
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

    /** "Crew at 2448 Larch St, Mon 8:10–11:40 (3 h 30 min), nothing scheduled." */
    public static function text(array $c, string $street): string
    {
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

    public static function hours(int $minutes): string
    {
        $minutes = max(0, $minutes);
        if ($minutes < 60) return $minutes . ' min';
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $h . ' h' . ($m ? ' ' . $m . ' min' : '');
    }
}
