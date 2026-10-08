<?php
/**
 * PropertyBorderService — Otto's "a border for every client property" (migration 1285).
 *
 *   audit()        read-only counts + lists: active properties, with / without pin, drawn / default
 *                  / no border, pins far from where crews work, overlapping borders. Measuring the
 *                  work sites reads GPS, so it is time-budgeted and resumable (offset / next_offset).
 *   plan()         dry run of the default borders apply() would create (read-only).
 *   apply()        creates those defaults (job_geofences, zone_type 'arrival_border', plan_id NULL —
 *                  the same row geofenceSaveZone() writes for a drawn border, plus border_source) and
 *                  stores what was measured in otto_property_sites. Never touches a property that
 *                  already has ANY arrival border; re-checked in the INSERT itself.
 *   items()        Otto's suggestions (no_pin, pin_off, default_border, border_overlap) — from the
 *                  stored tables only; never reads GPS. Safe on every page render.
 *   movePin() / keepDefault()   the one-click answers (OttoActionService).
 *
 * "Active client property": an active plan, or a non-cancelled visit in the last 12 months.
 * Where crews work: crew phone fixes (crew_location_history) inside the job timers of the last
 * VISITS_PER_PROPERTY timed visits, plus each timer's start / stop fix; the truck's parked pings
 * (vehicle_location_pings) fill in when a timer has fewer than 5 phone fixes.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/PropertyBorderRules.php';
require_once __DIR__ . '/PropertyReadinessService.php';

class PropertyBorderService
{
    public const KINDS = ['no_pin', 'pin_off', 'default_border', 'border_overlap'];
    /** Seconds a web call may spend measuring before it hands back a next_offset. */
    public const BUDGET_SECONDS = 20.0;

    protected PDO $db;
    private string $today;
    private ?bool $hasSource = null;

    public function __construct(PDO $db, ?string $today = null)
    {
        $this->db = $db;
        $this->today = $today ?? date('Y-m-d');
    }

    /** Migration 1285 has run (the sites table and job_geofences.border_source). */
    public function ready(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM otto_property_sites LIMIT 1");
            return $this->hasSourceColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    private function hasSourceColumn(): bool
    {
        if ($this->hasSource !== null) return $this->hasSource;
        try {
            $this->db->query("SELECT border_source FROM job_geofences LIMIT 1");
            return $this->hasSource = true;
        } catch (Throwable $e) {
            return $this->hasSource = false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Facts
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<int, array{id:int, address:string, city:?string, province:?string, postal_code:?string, latitude:mixed, longitude:mixed}> keyed by id */
    public function activeProperties(): array
    {
        $since = date('Y-m-d', strtotime($this->today . ' -' . PropertyBorderRules::LOOKBACK_MONTHS . ' months'));
        $s = $this->db->prepare("
            SELECT p.id, p.address, p.city, p.province, p.postal_code, p.latitude, p.longitude
            FROM properties p
            WHERE p.id IN (SELECT jp.property_id FROM job_plans jp WHERE jp.status = 'active')
               OR p.id IN (SELECT jp.property_id FROM job_plans jp JOIN job_visits v ON v.plan_id = jp.id
                           WHERE v.scheduled_date >= ? AND v.status <> 'cancelled')
            ORDER BY p.id
        ");
        $s->execute([$since]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = $r;
        return $out;
    }

    /**
     * Arrival borders by property: counts by kind and the rings (for overlaps).
     * @return array<int, array{drawn:int, default:int, kept:int, rings:array, sources:array}>
     */
    public function borders(): array
    {
        $cols = $this->hasSourceColumn() ? 'id, property_id, polygon_json, border_source' : 'id, property_id, polygon_json';
        try {
            $rows = $this->db->query("SELECT {$cols} FROM job_geofences WHERE zone_type = 'arrival_border'")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $pid = (int)$r['property_id'];
            $out[$pid] ??= ['drawn' => 0, 'default' => 0, 'kept' => 0, 'rings' => [], 'sources' => []];
            $src = $r['border_source'] ?? null;
            if ($src === null || $src === '') $out[$pid]['drawn']++;
            elseif ($src === 'kept') $out[$pid]['kept']++;
            else $out[$pid]['default']++;
            $out[$pid]['sources'][] = $src;
            $ring = self::ring((string)$r['polygon_json']);
            if (count($ring) >= 3) $out[$pid]['rings'][] = $ring;
        }
        return $out;
    }

    /**
     * Crew / truck fixes during the timers of the property's last timed visits.
     * @return array<int, array{lat: float, lng: float, visit_id: int, src: string}>
     */
    public function visitFixes(int $propertyId): array
    {
        $since = date('Y-m-d', strtotime($this->today . ' -' . PropertyBorderRules::LOOKBACK_MONTHS . ' months')) . ' 00:00:00';
        try {
            $s = $this->db->prepare("
                SELECT jte.visit_id, jte.user_id, jte.start_time, jte.end_time, jte.start_lat, jte.start_lng, jte.end_lat, jte.end_lng
                FROM job_time_entries jte
                JOIN job_visits v ON v.id = jte.visit_id
                JOIN job_plans jp ON jp.id = v.plan_id
                WHERE jp.property_id = ? AND jte.start_time >= ? AND jte.status <> 'void' AND jte.end_time IS NOT NULL
                ORDER BY jte.start_time DESC
                LIMIT 40
            ");
            $s->execute([$propertyId, $since]);
            $timers = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Otto borders timers: ' . $e->getMessage());
            return [];
        }
        $visits = [];
        $out = [];
        foreach ($timers as $t) {
            $vid = (int)$t['visit_id'];
            if (!isset($visits[$vid]) && count($visits) >= PropertyBorderRules::VISITS_PER_PROPERTY) continue;
            $visits[$vid] = true;
            foreach (['start', 'end'] as $w) {
                if (is_numeric($t["{$w}_lat"] ?? null) && is_numeric($t["{$w}_lng"] ?? null)) {
                    $out[] = ['lat' => (float)$t["{$w}_lat"], 'lng' => (float)$t["{$w}_lng"], 'visit_id' => $vid, 'src' => 'timer'];
                }
            }
            $phone = 0;
            try {
                $p = $this->db->prepare("
                    SELECT latitude, longitude FROM crew_location_history
                    WHERE crew_id = ? AND `timestamp` BETWEEN ? AND ?
                      AND latitude IS NOT NULL AND longitude IS NOT NULL
                      AND (accuracy_meters IS NULL OR accuracy_meters <= 50)
                    LIMIT 400
                ");
                $p->execute([(int)$t['user_id'], (string)$t['start_time'], (string)$t['end_time']]);
                foreach ($p->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out[] = ['lat' => (float)$r['latitude'], 'lng' => (float)$r['longitude'], 'visit_id' => $vid, 'src' => 'phone'];
                    $phone++;
                }
            } catch (Throwable $e) { /* no phone history on this schema */ }
            if ($phone >= 5) continue;
            try {
                $p = $this->db->prepare("
                    SELECT lat, lng FROM vehicle_location_pings
                    WHERE recorded_at BETWEEN ? AND ? AND (speed_kph IS NULL OR speed_kph < 3)
                    LIMIT 100
                ");
                $p->execute([(string)$t['start_time'], (string)$t['end_time']]);
                foreach ($p->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out[] = ['lat' => (float)$r['lat'], 'lng' => (float)$r['lng'], 'visit_id' => $vid, 'src' => 'truck'];
                }
            } catch (Throwable $e) { /* no truck tracking */ }
        }
        return $out;
    }

    /** One property measured: the site fixes, its centre and the pin offset. */
    public function measure(array $prop): array
    {
        $pin = PropertyBorderRules::pinOf($prop);
        $site = PropertyBorderRules::siteFixes($this->visitFixes((int)$prop['id']), $pin);
        return $site + ['pin' => $pin, 'offset_m' => PropertyBorderRules::pinOffset($pin, $site['center']), 'fixes' => count($site['points'])];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Audit (read-only) / dry run / apply
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param int $offset  first property (by id order) to measure in this call
     * @return array audit counts + lists, plus measured: n, next_offset: ?int, proposed: [...]
     */
    public function audit(int $offset = 0, ?float $budget = null): array
    {
        $props = $this->activeProperties();
        $borders = $this->borders();
        [$sites, $proposed, $next] = $this->walk($props, $borders, $offset, $budget ?? self::BUDGET_SECONDS);
        $rings = [];
        foreach ($borders as $pid => $b) foreach ($b['rings'] as $r) $rings[] = ['property_id' => $pid, 'ring' => $r];
        $pairs = PropertyBorderRules::overlaps($rings);
        $a = PropertyBorderRules::audit(array_values($props), $borders, $sites, $pairs);
        $a['overlap_list'] = array_map(fn($p) => ['a' => $p[0], 'a_address' => (string)($props[$p[0]]['address'] ?? ('#' . $p[0])),
                                                   'b' => $p[1], 'b_address' => (string)($props[$p[1]]['address'] ?? ('#' . $p[1]))], $pairs);
        $a['walked'] = count($sites);   // properties whose GPS was read in this call ('measured' = of those, with enough fixes for a centre)
        $a['walked_from'] = $offset;
        $a['next_offset'] = $next;
        $a['would_create'] = ['hull' => count(array_filter($proposed, fn($p) => $p['source'] === 'default_hull')),
                              'square' => count(array_filter($proposed, fn($p) => $p['source'] === 'default_square'))];
        $a['proposed'] = $proposed;
        $a['ready'] = $this->ready();
        return $a;
    }

    /**
     * The audit's counts from what is stored (no GPS read) — for the review page. Pin offsets come from
     * otto_property_sites rows measured against the current pin only.
     */
    public function summary(): array
    {
        $props = $this->activeProperties();
        $borders = $this->borders();
        $sites = [];
        foreach ($this->sites() as $pid => $r) {
            $pin = isset($props[$pid]) ? PropertyBorderRules::pinOf($props[$pid]) : null;
            $same = $pin && $r['pin_lat'] !== null && abs((float)$r['pin_lat'] - $pin[0]) < 0.00001 && abs((float)$r['pin_lng'] - $pin[1]) < 0.00001;
            $sites[$pid] = ['center' => $r['work_lat'] !== null ? [(float)$r['work_lat'], (float)$r['work_lng']] : null,
                            'offset_m' => $same && $r['offset_m'] !== null ? (int)$r['offset_m'] : null,
                            'fixes' => (int)$r['fixes'], 'visits' => (int)$r['visits'], 'computed_at' => $r['computed_at']];
        }
        $rings = [];
        foreach ($borders as $pid => $b) foreach ($b['rings'] as $r) $rings[] = ['property_id' => $pid, 'ring' => $r];
        $pairs = PropertyBorderRules::overlaps($rings);
        $a = PropertyBorderRules::audit(array_values($props), $borders, $sites, $pairs);
        $a['overlap_list'] = array_map(fn($p) => ['a' => $p[0], 'a_address' => (string)($props[$p[0]]['address'] ?? ('#' . $p[0])),
                                                   'b' => $p[1], 'b_address' => (string)($props[$p[1]]['address'] ?? ('#' . $p[1]))], $pairs);
        $times = array_filter(array_column($sites, 'computed_at'));
        $a['measured_at'] = $times ? max($times) : null;
        $a['ready'] = $this->ready();
        return $a;
    }

    /** Dry run: the defaults apply() would create for properties from $offset on. Read-only. */
    public function plan(int $offset = 0, ?float $budget = null): array
    {
        $props = $this->activeProperties();
        [, $proposed, $next] = $this->walk($props, $this->borders(), $offset, $budget ?? self::BUDGET_SECONDS);
        return ['proposed' => $proposed, 'next_offset' => $next];
    }

    /**
     * Create the default borders (only where no arrival border exists) and store the measured sites.
     * @param int[]|null $onlyIds limit to these properties (the dry run's ticks); null = all
     * @return array{ok: bool, created: int, skipped: int, measured: int, next_offset: ?int, message: string}
     */
    public function apply(int $actorId, ?array $onlyIds = null, int $offset = 0, ?float $budget = null): array
    {
        if (!$this->ready()) return ['ok' => false, 'created' => 0, 'skipped' => 0, 'measured' => 0, 'next_offset' => null, 'message' => 'Run migration 1285 first.'];
        $props = $this->activeProperties();
        if ($onlyIds !== null) $props = array_intersect_key($props, array_flip(array_map('intval', $onlyIds)));
        $borders = $this->borders();
        [$sites, $proposed, $next] = $this->walk($props, $borders, $offset, $budget ?? self::BUDGET_SECONDS);
        $this->storeSites($sites, $borders);
        $created = 0;
        $skipped = 0;
        foreach ($proposed as $p) {
            if ($this->insertDefault((int)$p['property_id'], $p, $actorId)) $created++;
            else $skipped++;
        }
        $this->clearStripCache();
        return ['ok' => true, 'created' => $created, 'skipped' => $skipped, 'measured' => count($sites), 'next_offset' => $next,
                'message' => $created . ' default border' . ($created === 1 ? '' : 's') . ' created' . ($next !== null ? ' — more to go, run it again.' : '.')];
    }

    /** Measure and store the sites only (refreshes "pin is X m off") — no borders created. */
    public function refreshSites(int $offset = 0, ?float $budget = null): array
    {
        if (!$this->ready()) return ['ok' => false, 'measured' => 0, 'next_offset' => null, 'message' => 'Run migration 1285 first.'];
        $borders = $this->borders();
        [$sites, , $next] = $this->walk($this->activeProperties(), $borders, $offset, $budget ?? self::BUDGET_SECONDS);
        $this->storeSites($sites, $borders);
        $this->clearStripCache();
        return ['ok' => true, 'measured' => count($sites), 'next_offset' => $next,
                'message' => count($sites) . ' properties measured' . ($next !== null ? ' — more to go, run it again.' : '.')];
    }

    /**
     * Walk properties from $offset (id order) within the time budget.
     * @return array{0: array, 1: array, 2: ?int} [sites by property, proposed defaults, next offset or null]
     */
    private function walk(array $props, array $borders, int $offset, float $budget): array
    {
        $t0 = microtime(true);
        $sites = [];
        $proposed = [];
        $list = array_values($props);
        $n = count($list);
        for ($i = max(0, $offset); $i < $n; $i++) {
            if ($i > $offset && microtime(true) - $t0 > $budget) return [$sites, $proposed, $i];
            $prop = $list[$i];
            $pid = (int)$prop['id'];
            $m = $this->measure($prop);
            $sites[$pid] = ['center' => $m['center'], 'offset_m' => $m['offset_m'], 'fixes' => $m['fixes'], 'visits' => $m['visits'], 'pin' => $m['pin']];
            $hasBorder = isset($borders[$pid]) && ($borders[$pid]['drawn'] + $borders[$pid]['default'] + $borders[$pid]['kept']) > 0;
            $plan = PropertyBorderRules::planBorder($m['pin'], $m, $hasBorder);
            if ($plan) {
                $proposed[] = ['property_id' => $pid, 'address' => (string)$prop['address']] + $plan;
            }
        }
        return [$sites, $proposed, null];
    }

    private function insertDefault(int $propertyId, array $p, int $actorId): bool
    {
        $ring = $p['ring'];
        $closed = $ring;
        $closed[] = $ring[0];
        [$a, $b, $c, $d] = PropertyBorderRules::bbox($ring);
        $label = $p['source'] === 'default_square' ? 'Default border — draw me' : 'Default border (crew GPS)';
        $notes = $p['source'] === 'default_square'
            ? 'Otto: a ±' . PropertyBorderRules::SQUARE_HALF_M . ' m square round the pin — not enough crew GPS yet. Draw the real border.'
            : 'Otto: hull of ' . (int)$p['fixes'] . ' crew/truck fixes over ' . (int)$p['visits'] . ' visits, +' . PropertyBorderRules::BUFFER_M . ' m, capped ' . PropertyBorderRules::CAP_M . ' m.';
        // NOT EXISTS in the INSERT itself: a border drawn since the dry run is never doubled or replaced.
        $s = $this->db->prepare("
            INSERT INTO job_geofences
                (plan_id, property_id, zone_type, border_source, polygon_json, vertex_count,
                 bbox_lat_min, bbox_lat_max, bbox_lng_min, bbox_lng_max, area_sqm, label, notes, drawn_by)
            SELECT NULL, ?, 'arrival_border', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL
            FROM properties pr
            WHERE pr.id = ?
              AND NOT EXISTS (SELECT 1 FROM job_geofences g WHERE g.property_id = ? AND g.zone_type = 'arrival_border')
        ");
        $s->execute([$propertyId, $p['source'], json_encode($closed), count($ring), $a, $b, $c, $d,
                     round(PropertyBorderRules::area($ring), 2), $label, $notes, $propertyId, $propertyId]);
        return $s->rowCount() > 0;
    }

    /** Remember what was measured (Otto's cheap items read this, never GPS). */
    public function storeSites(array $sites, ?array $borders = null): void
    {
        if (!$sites) return;
        $borders ??= $this->borders();
        $rings = [];
        foreach ($borders as $pid => $b) foreach ($b['rings'] as $r) $rings[] = ['property_id' => $pid, 'ring' => $r];
        $over = [];
        foreach (PropertyBorderRules::overlaps($rings) as [$x, $y]) {
            $over[$x][] = $y;
            $over[$y][] = $x;
        }
        $now = date('Y-m-d H:i:s');
        $s = $this->db->prepare("
            REPLACE INTO otto_property_sites (property_id, pin_lat, pin_lng, work_lat, work_lng, fixes, visits, offset_m, overlaps_json, computed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($sites as $pid => $st) {
            $s->execute([(int)$pid, $st['pin'][0] ?? null, $st['pin'][1] ?? null, $st['center'][0] ?? null, $st['center'][1] ?? null,
                         (int)$st['fixes'], (int)$st['visits'], $st['offset_m'], isset($over[$pid]) ? json_encode(array_values(array_unique($over[$pid]))) : null, $now]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Otto's items (cheap: stored tables only)
    // ─────────────────────────────────────────────────────────────────────────

    /** @param int[]|null $onlyProperties restrict to these (the schedule strip's day) */
    public function items(?array $onlyProperties = null): array
    {
        if (!$this->ready()) return [];
        try {
            $props = $this->activeProperties();
        } catch (Throwable $e) {
            error_log('Otto borders: ' . $e->getMessage());
            return [];
        }
        if ($onlyProperties !== null) $props = array_intersect_key($props, array_flip(array_map('intval', $onlyProperties)));
        if (!$props) return [];
        $decided = $this->decided();
        $next = $this->nextVisits();
        $borders = $this->borders();
        $sites = $this->sites();
        $out = [];
        $defaults = [];
        foreach ($props as $pid => $p) {
            $pin = PropertyBorderRules::pinOf($p);
            $addr = (string)$p['address'];
            if (!$pin) {
                if (!isset($decided['no_pin'][$pid])) {
                    $out[] = $this->item('no_pin', $pid, $addr, 2, ['next' => $next[$pid] ?? null],
                        '/crm/map_appstack.php?geocode=' . $pid, 'Find it on the map — the geocoder fills the pin, or drop it by hand.',
                        ['geocode_address' => PropertyReadinessService::geocodeAddress($p)]);
                }
                continue;
            }
            $st = $sites[$pid] ?? null;
            // A row measured against another pin is stale (the pin was moved since).
            if ($st && $st['offset_m'] !== null && PropertyBorderRules::pinIsOff((int)$st['offset_m']) && !isset($decided['pin_off'][$pid])
                && $st['pin_lat'] !== null && abs((float)$st['pin_lat'] - $pin[0]) < 0.00001 && abs((float)$st['pin_lng'] - $pin[1]) < 0.00001) {
                $out[] = $this->item('pin_off', $pid, $addr, 3, ['distance_m' => (int)$st['offset_m']],
                    '/crm/jobs/zone-editor.php?property_id=' . $pid,
                    'Median of ' . (int)$st['fixes'] . ' fixes over ' . (int)$st['visits'] . ' visits. Moving the pin is what auto-arrival and the map use.',
                    ['lat' => (float)$st['work_lat'], 'lng' => (float)$st['work_lng'], 'distance_m' => (int)$st['offset_m']]);
            }
            $b = $borders[$pid] ?? null;
            if ($b && $b['drawn'] === 0 && $b['default'] > 0 && !isset($decided['default_border'][$pid])) {
                $src = in_array('default_square', $b['sources'], true) ? 'default_square' : 'default_hull';
                $defaults[] = $this->item('default_border', $pid, $addr, $src === 'default_square' ? 3 : 4, ['source' => $src],
                    '/crm/jobs/zone-editor.php?property_id=' . $pid,
                    $src === 'default_square' ? 'Auto-arrival still works on the 150 m radius; the square only adds to it.' : 'Drawn from where crews actually stood. Edit it in the zone editor and it becomes yours.',
                    ['source' => $src]) + ['_next' => $next[$pid] ?? '9999-12-31'];
            }
            foreach (json_decode((string)($st['overlaps_json'] ?? ''), true) ?: [] as $other) {
                $other = (int)$other;
                if ($other < $pid && isset($props[$other])) continue;   // one item per pair
                if (isset($decided['border_overlap'][$pid])) continue;
                $out[] = $this->item('border_overlap', $pid, $addr, 3, ['other' => (string)($props[$other]['address'] ?? ('property #' . $other))],
                    '/crm/jobs/zone-editor.php?property_id=' . $pid, 'Auto-arrival picks whichever visit is due; redraw one so they only touch.', ['other_id' => $other]);
            }
        }
        // Soonest visit first. Priority 3-4 sinks them below everything else on the card (top 8 shown);
        // the review page lists them all; Charlie's brief leaves them out.
        usort($defaults, fn($x, $y) => [$x['_next'], $x['subject_id']] <=> [$y['_next'], $y['subject_id']]);
        foreach ($defaults as $d) {
            unset($d['_next']);
            $out[] = $d;
        }
        return $out;
    }

    private function item(string $kind, int $pid, string $address, int $priority, array $x, string $url, string $detail, array $propose): array
    {
        return [
            'key' => 'otto:' . $kind . ':' . $pid, 'kind' => $kind, 'subject_type' => 'property', 'subject_id' => $pid,
            'for_date' => $this->today, 'user_id' => null, 'priority' => $priority,
            'text' => PropertyBorderRules::text($kind, $address, $x), 'detail' => $detail, 'url' => $url,
            'value' => $x['distance_m'] ?? null, 'since' => $this->today,
            'propose' => $propose + ['property_id' => $pid],
        ];
    }

    /** [kind => [property_id => true]] for border items the owner answered in the last year. */
    private function decided(): array
    {
        $out = array_fill_keys(self::KINDS, []);
        try {
            $in = implode(',', array_fill(0, count(self::KINDS), '?'));
            $s = $this->db->prepare("
                SELECT kind, subject_id FROM otto_suggestions
                WHERE kind IN ({$in}) AND subject_type = 'property' AND status IN ('accepted', 'edited', 'dismissed') AND for_date >= ?
            ");
            $s->execute(array_merge(self::KINDS, [date('Y-m-d', strtotime($this->today . ' -365 days'))]));
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['kind']][(int)$r['subject_id']] = true;
        } catch (Throwable $e) { /* no suggestions table yet */ }
        return $out;
    }

    /** [property_id => next scheduled date] from today on. */
    private function nextVisits(): array
    {
        try {
            $s = $this->db->prepare("
                SELECT jp.property_id, MIN(v.scheduled_date) AS d FROM job_visits v JOIN job_plans jp ON jp.id = v.plan_id
                WHERE v.scheduled_date >= ? AND v.status = 'scheduled' GROUP BY jp.property_id
            ");
            $s->execute([$this->today]);
            $out = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['property_id']] = (string)$r['d'];
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return array<int, array> otto_property_sites rows keyed by property */
    public function sites(): array
    {
        try {
            $out = [];
            foreach ($this->db->query("SELECT * FROM otto_property_sites")->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['property_id']] = $r;
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // One-click answers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Move the pin to where crews work (PropertyReadinessService::savePin — the geocode-save write).
     * A default SQUARE drawn round the old pin is re-centred; a hull or a drawn border is left alone.
     */
    public function movePin(int $propertyId, float $lat, float $lng): array
    {
        $r = (new PropertyReadinessService($this->db))->savePin($propertyId, $lat, $lng);
        if (!$r['ok']) return $r;
        $moved = false;
        if ($this->hasSourceColumn()) {
            $ring = PropertyBorderRules::square($lat, $lng);
            $closed = $ring;
            $closed[] = $ring[0];
            [$a, $b, $c, $d] = PropertyBorderRules::bbox($ring);
            $s = $this->db->prepare("
                UPDATE job_geofences SET polygon_json = ?, vertex_count = 4, bbox_lat_min = ?, bbox_lat_max = ?, bbox_lng_min = ?, bbox_lng_max = ?
                WHERE property_id = ? AND zone_type = 'arrival_border' AND border_source = 'default_square'
            ");
            $s->execute([json_encode($closed), $a, $b, $c, $d, $propertyId]);
            $moved = $s->rowCount() > 0;
        }
        try {
            $this->db->prepare("UPDATE otto_property_sites SET pin_lat = ?, pin_lng = ?, offset_m = NULL WHERE property_id = ?")->execute([$lat, $lng, $propertyId]);
        } catch (Throwable $e) { /* not migrated */ }
        $this->clearStripCache();
        return ['ok' => true, 'square_moved' => $moved];
    }

    /** The owner says a default border is fine: it stops asking, and it is no longer counted as default. */
    public function keepDefault(int $propertyId): bool
    {
        if (!$this->hasSourceColumn()) return false;
        $s = $this->db->prepare("UPDATE job_geofences SET border_source = 'kept' WHERE property_id = ? AND zone_type = 'arrival_border' AND border_source IN ('default_hull', 'default_square')");
        $s->execute([$propertyId]);
        $this->clearStripCache();
        return $s->rowCount() > 0;
    }

    /** Properties still missing a pin, for the map's geocode-all tool. */
    public function missingPins(): array
    {
        $out = [];
        foreach ($this->activeProperties() as $pid => $p) {
            if (PropertyBorderRules::pinOf($p)) continue;
            $out[] = ['id' => $pid, 'address' => (string)$p['address'], 'geocode_address' => PropertyReadinessService::geocodeAddress($p)];
        }
        return $out;
    }

    private function clearStripCache(): void
    {
        try {
            $this->db->exec("DELETE FROM otto_day_strip");
        } catch (Throwable $e) { /* migration 1286 not run */ }
    }

    public static function ring(string $json): array
    {
        $r = json_decode($json, true);
        if (!is_array($r)) return [];
        $out = [];
        foreach ($r as $p) {
            if (is_array($p) && count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1])) $out[] = [(float)$p[0], (float)$p[1]];
        }
        // Stored rings are closed; work with them open.
        if (count($out) > 3 && $out[0] === $out[count($out) - 1]) array_pop($out);
        return $out;
    }
}
