<?php
/**
 * UnscheduledWorkService — Otto: days the crew worked at a client property with nothing scheduled.
 *
 * Loads the evidence, hands it to UnscheduledWorkRules (pure, tested), and applies the owner's
 * answer. See UnscheduledWorkRules for what counts.
 *
 *   evidence(date)   truck stops + crew dwells for one day, property-attributed. Days that have
 *                    ended are cached in otto_unscheduled_days (migration 1265) — the crew GPS
 *                    for a day is thousands of rows and the answer does not change once the
 *                    day's data is in. Whether a property was SCHEDULED is never cached: adding
 *                    the visit makes the item go away on the next look.
 *   review(from,to)  every candidate in the range with its evidence (the dry run and the review
 *                    page). Read-only: no cache writes.
 *   items()          Otto's suggestions (kind 'unscheduled') for the last 14 ended days.
 *   apply()          Add the visit (to an existing plan, or as a one-off job) as a COMPLETED visit
 *                    with the observed times — then optionally open a draft invoice for it, or
 *                    link it to the invoice the owner already made by hand. Or "not work".
 *
 * Adding the visit completes it quietly: no job-complete email, no Google review request, no push.
 * It is a record of work already done days ago (VisitLifecycleService::updateVisitStatus would
 * email the customer as if the crew had just left).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/UnscheduledWorkRules.php';
require_once __DIR__ . '/TripSegmentService.php';

class UnscheduledWorkService
{
    /** Bump when the shape of a cached day changes; older rows are recomputed. */
    public const CACHE_VERSION = 1;
    /** Uncached days filled per call from the card's API (the first run needs a few calls). */
    public const FILL_PER_CALL = 3;
    /** Phone fixes closer together than this are thinned (5 s native cadence → 1 a minute). */
    public const THIN_SECONDS = 60;

    private PDO $db;
    private string $today;

    public function __construct(PDO $db, ?string $today = null)
    {
        $this->db = $db;
        $this->today = $today ?? date('Y-m-d');
    }

    public function cacheReady(): bool
    {
        try {
            return $this->db->query("SELECT 1 FROM otto_unscheduled_days LIMIT 1") !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Evidence for one day
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array{date: string, truck: array, crew: array, meta: array}
     *   meta: truck_pings, phone_fixes [uid => n], clock_fixes, timer_fixes, users [uid => name], cached
     */
    public function evidence(string $date, bool $useCache = true, bool $writeCache = false): array
    {
        if ($useCache && ($c = $this->cached($date)) !== null) return $c + ['cached' => true];

        $seg = new TripSegmentService($this->db);
        $pings = $this->truckPings($seg, $date);
        $fixes = $this->crewFixes($date);
        $all = $pings;
        foreach ($fixes as $list) foreach ($list as $f) $all[] = $f;
        $properties = $this->propertiesNear($all);
        $fences = $this->fences($all);
        $places = $this->places($seg);
        $zones = array_merge($this->placeZones($places), $this->officeZones(), $this->homeZones());

        $segments = $pings ? TripSegmentService::segments($pings, $properties, $places) : [];
        $truck = UnscheduledWorkRules::truckDwells($segments, $properties, $fences, $zones);
        $crew = [];
        $meta = ['truck_pings' => count($pings), 'phone_fixes' => [], 'clock_fixes' => 0, 'timer_fixes' => 0];
        foreach ($fixes as $uid => $list) {
            foreach ($list as $f) {
                if ($f['src'] === 'clock') $meta['clock_fixes']++;
                elseif ($f['src'] === 'timer') $meta['timer_fixes']++;
                else $meta['phone_fixes'][$uid] = ($meta['phone_fixes'][$uid] ?? 0) + 1;
            }
            $d = UnscheduledWorkRules::crewDwells($list, $properties, $fences, $zones);
            if ($d) $crew[$uid] = $d;
        }
        $meta['zones'] = count($zones);
        // Every truck stop of the day with its label and nearest client pin — so a dry run that
        // misses a job shows why (parked 180 m away, labelled as a supplier, …).
        $meta['stops'] = [];
        foreach ($segments as $sg) {
            if ($sg['type'] !== 'stop' || $sg['end'] - $sg['start'] < UnscheduledWorkRules::KEEP_DWELL_MIN * 60) continue;
            $near = null;
            foreach ($properties as $p) {
                $m = UnscheduledWorkRules::meters((float)$sg['lat'], (float)$sg['lng'], (float)$p['latitude'], (float)$p['longitude']);
                if ($near === null || $m < $near[1]) $near = [(int)$p['id'], $m];
            }
            $meta['stops'][] = ['from' => (int)$sg['start'], 'to' => (int)$sg['end'], 'label' => $sg['label']['type'] . ': ' . $sg['label']['name'],
                'excluded_by' => UnscheduledWorkRules::excludedBy((float)$sg['lat'], (float)$sg['lng'], $zones),
                'nearest_property' => $near ? $near[0] : null, 'nearest_m' => $near ? (int)round($near[1]) : null];
        }
        $out = ['date' => $date, 'v' => self::CACHE_VERSION, 'truck' => $truck, 'crew' => $crew, 'meta' => $meta];
        if ($writeCache && $date < $this->today) $this->store($date, $out);
        return $out + ['cached' => false];
    }

    private function truckPings(TripSegmentService $seg, string $date): array
    {
        try {
            return $seg->pings($date);
        } catch (Throwable $e) {
            error_log('Otto unscheduled truck: ' . $e->getMessage());
            return [];
        }
    }

    /** [uid => [{lat, lng, t, src: phone|clock|timer}]] — office pings excluded, phone thinned to 1/min. */
    public function crewFixes(string $date): array
    {
        $from = $date . ' 00:00:00';
        $to = $date . ' 23:59:59';
        $out = [];
        try {
            $s = $this->db->prepare("
                SELECT crew_id, latitude, longitude, `timestamp` AS t
                FROM crew_location_history
                WHERE `timestamp` BETWEEN ? AND ? AND COALESCE(is_office, 0) = 0
                  AND latitude IS NOT NULL AND longitude IS NOT NULL
                ORDER BY crew_id, `timestamp`
            ");
            $s->execute([$from, $to]);
            $last = [];
            while ($r = $s->fetch(PDO::FETCH_ASSOC)) {
                $uid = (int)$r['crew_id'];
                $t = (int)strtotime((string)$r['t']);
                if (isset($last[$uid]) && $t - $last[$uid] < self::THIN_SECONDS) continue;
                $last[$uid] = $t;
                $out[$uid][] = ['lat' => (float)$r['latitude'], 'lng' => (float)$r['longitude'], 't' => $t, 'src' => 'phone'];
            }
        } catch (Throwable $e) {
            error_log('Otto unscheduled phones: ' . $e->getMessage());
        }
        try {
            $s = $this->db->prepare("
                SELECT user_id, clock_in, clock_in_lat, clock_in_lng, clock_out, clock_out_lat, clock_out_lng
                FROM time_clock_entries
                WHERE clock_in BETWEEN ? AND ? AND status <> 'void'
            ");
            $s->execute([$from, $to]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                foreach (['in', 'out'] as $w) {
                    if (is_numeric($r["clock_{$w}_lat"] ?? null) && is_numeric($r["clock_{$w}_lng"] ?? null) && !empty($r["clock_{$w}"])) {
                        $out[(int)$r['user_id']][] = ['lat' => (float)$r["clock_{$w}_lat"], 'lng' => (float)$r["clock_{$w}_lng"], 't' => (int)strtotime((string)$r["clock_{$w}"]), 'src' => 'clock'];
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('Otto unscheduled clock: ' . $e->getMessage());
        }
        try {
            $s = $this->db->prepare("
                SELECT user_id, start_time, start_lat, start_lng, end_time, end_lat, end_lng
                FROM job_time_entries
                WHERE start_time BETWEEN ? AND ? AND status <> 'void'
            ");
            $s->execute([$from, $to]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                foreach (['start', 'end'] as $w) {
                    if (is_numeric($r["{$w}_lat"] ?? null) && is_numeric($r["{$w}_lng"] ?? null) && !empty($r["{$w}_time"])) {
                        $out[(int)$r['user_id']][] = ['lat' => (float)$r["{$w}_lat"], 'lng' => (float)$r["{$w}_lng"], 't' => (int)strtotime((string)$r["{$w}_time"]), 'src' => 'timer'];
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('Otto unscheduled timers: ' . $e->getMessage());
        }
        return $out;
    }

    /** Properties with real coordinates inside the box the day's points cover. */
    private function propertiesNear(array $points): array
    {
        if (!$points) return [];
        [$a, $b, $c, $d] = self::bbox($points, 0.003);
        $s = $this->db->prepare("
            SELECT id, latitude, longitude, address, property_name FROM properties
            WHERE latitude BETWEEN ? AND ? AND longitude BETWEEN ? AND ?
              AND NOT (latitude = 0 AND longitude = 0)
        ");
        $s->execute([$a, $b, $c, $d]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fences(array $points): array
    {
        if (!$points) return [];
        [$a, $b, $c, $d] = self::bbox($points, 0.003);
        try {
            $s = $this->db->prepare("
                SELECT property_id, polygon_json, bbox_lat_min, bbox_lat_max, bbox_lng_min, bbox_lng_max
                FROM job_geofences
                WHERE bbox_lat_max >= ? AND bbox_lat_min <= ? AND bbox_lng_max >= ? AND bbox_lng_min <= ?
            ");
            $s->execute([$a, $b, $c, $d]);
            $out = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = ['property_id' => (int)$r['property_id'], 'ring' => UnscheduledWorkRules::ring($r['polygon_json']),
                          'lat_min' => (float)$r['bbox_lat_min'], 'lat_max' => (float)$r['bbox_lat_max'],
                          'lng_min' => (float)$r['bbox_lng_min'], 'lng_max' => (float)$r['bbox_lng_max']];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    private function places(TripSegmentService $seg): array
    {
        return $seg->places();
    }

    private function placeZones(array $places): array
    {
        return array_map(fn($p) => ['lat' => (float)$p['lat'], 'lng' => (float)$p['lng'], 'radius_m' => (float)($p['radius_m'] ?: 150),
            'name' => (string)$p['name'], 'kind' => (string)$p['kind']], $places);
    }

    private function officeZones(): array
    {
        try {
            $rows = $this->db->query("SELECT setting_key, setting_value FROM ops_settings WHERE setting_key IN ('office_latitude', 'office_longitude')")
                             ->fetchAll(PDO::FETCH_KEY_PAIR);
            if (is_numeric($rows['office_latitude'] ?? null) && is_numeric($rows['office_longitude'] ?? null)) {
                return [['lat' => (float)$rows['office_latitude'], 'lng' => (float)$rows['office_longitude'],
                         'radius_m' => UnscheduledWorkRules::OFFICE_RADIUS_M, 'name' => 'the office', 'kind' => 'office']];
            }
        } catch (Throwable $e) { /* no office set */ }
        return [];
    }

    private function homeZones(): array
    {
        try {
            $rows = $this->db->query("SELECT id, full_name, home_lat, home_lng, home_radius_meters FROM users WHERE home_lat IS NOT NULL AND home_lng IS NOT NULL")
                             ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        return array_map(fn($u) => ['lat' => (float)$u['home_lat'], 'lng' => (float)$u['home_lng'],
            'radius_m' => max(UnscheduledWorkRules::HOME_RADIUS_M, (int)($u['home_radius_meters'] ?? 0)),
            'name' => 'home (' . strtok(trim((string)$u['full_name']), ' ') . ')', 'kind' => 'home'], $rows);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // What was scheduled
    // ─────────────────────────────────────────────────────────────────────────

    /** [property_id => true] — a visit scheduled, started or completed that day, or a live calendar stop. */
    public function scheduledIds(string $date): array
    {
        $ids = [];
        try {
            $s = $this->db->prepare("
                SELECT DISTINCT jp.property_id
                FROM job_visits v JOIN job_plans jp ON jp.id = v.plan_id
                WHERE (v.scheduled_date = ? AND v.status IN ('scheduled', 'in_progress', 'completed'))
                   OR (v.completed_at >= ? AND v.completed_at <= ? AND v.status = 'completed')
            ");
            $s->execute([$date, $date . ' 00:00:00', $date . ' 23:59:59']);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[(int)$id] = true;
            $s = $this->db->prepare("SELECT DISTINCT property_id FROM calendar_stops WHERE stop_date = ? AND status <> 'skipped'");
            $s->execute([$date]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[(int)$id] = true;
        } catch (Throwable $e) {
            error_log('Otto unscheduled visits: ' . $e->getMessage());
        }
        return $ids;
    }

    /** [property_id => true] — the owner said "not work" here twice. */
    public function notWork(): array
    {
        $out = [];
        try {
            $s = $this->db->prepare("SELECT scope_key, value_json FROM otto_lessons WHERE scope = 'unscheduled'");
            $s->execute();
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $v = json_decode((string)$r['value_json'], true) ?: [];
                if (preg_match('/^property:(\d+)$/', (string)$r['scope_key'], $m) && (int)($v['not_work'] ?? 0) >= 2) $out[(int)$m[1]] = true;
            }
        } catch (Throwable $e) { /* no lessons yet */ }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Review (dry run) and Otto's items
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Every candidate between two dates with the evidence, flagged first. Read-only unless
     * $writeCache (the review page stores ended days it had to compute; the dry run never does).
     * @return array{days: list<array>, flagged: int}
     */
    public function review(string $from, string $to, bool $useCache = true, bool $writeCache = false): array
    {
        $notWork = $this->notWork();
        $days = [];
        $flagged = 0;
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $ev = $this->evidence($d, $useCache, $writeCache);
            $cands = UnscheduledWorkRules::candidates($d, $ev['truck'], $ev['crew'], $this->scheduledIds($d), $notWork);
            $cands = $this->describe($cands);
            $flagged += count(array_filter($cands, fn($c) => $c['flag']));
            $days[] = ['date' => $d, 'sources' => $ev['meta'] + ['cached' => $ev['cached']], 'candidates' => $cands];
        }
        return ['days' => $days, 'flagged' => $flagged];
    }

    /**
     * Otto's suggestions for the last LOOKBACK_DAYS ended days. Only cached days are read;
     * with $fill, up to FILL_PER_CALL missing days are computed and cached first.
     */
    public function items(bool $fill = false): array
    {
        if (!$this->cacheReady()) return [];
        $notWork = $this->notWork();
        $filled = 0;
        $out = [];
        for ($i = 1; $i <= UnscheduledWorkRules::LOOKBACK_DAYS; $i++) { // newest first: yesterday is filled first
            $d = date('Y-m-d', strtotime($this->today . " -{$i} days"));
            $ev = $this->cached($d);
            if ($ev === null) {
                if ($fill && $filled < self::FILL_PER_CALL) {
                    $ev = $this->evidence($d, false, true);
                    $filled++;
                } else {
                    // An early or old-version row still beats nothing (and keeps its open item from expiring).
                    $ev = $this->cached($d, false);
                    if ($ev === null) continue;
                }
            }
            $cands = UnscheduledWorkRules::candidates($d, $ev['truck'], $ev['crew'], $this->scheduledIds($d), $notWork);
            foreach ($this->describe(array_values(array_filter($cands, fn($c) => $c['flag']))) as $c) $out[] = $this->item($c);
        }
        return $out;
    }

    private function item(array $c): array
    {
        $street = self::street((string)$c['address']);
        $crewId = null;
        foreach ($c['crew_people'] as $p) if (!$p['truck']) { $crewId = $p['id']; break; }
        return [
            'key' => 'otto:unsched:' . $c['property_id'] . ':' . $c['date'], 'kind' => 'unscheduled',
            'subject_type' => 'property', 'subject_id' => (int)$c['property_id'], 'for_date' => $c['date'],
            'user_id' => $crewId, 'priority' => $c['confidence'] === 'low' ? 3 : 2,
            'text' => UnscheduledWorkRules::text($c, $street),
            'detail' => $c['evidence'] . ($c['client'] !== '' ? ' · ' . $c['client'] : ''),
            'url' => '/crm/properties/view.php?id=' . (int)$c['property_id'],
            'value' => $c['minutes'], 'since' => $c['date'],
            'propose' => [
                'date' => $c['date'], 'start' => date('H:i', $c['start']), 'end' => date('H:i', $c['end']), 'minutes' => $c['minutes'],
                'crew_id' => $crewId, 'people' => count(array_filter($c['crew_people'], fn($p) => !$p['truck'])) ?: null,
                'confidence' => $c['confidence'], 'basis' => $c['basis'],
                'plans' => $c['plans'], 'invoices' => $c['invoices'], 'street' => $street,
            ],
        ];
    }

    /** Adds address, client, names, plans, nearby visits and invoices to candidates. */
    private function describe(array $cands): array
    {
        if (!$cands) return [];
        $names = $this->userNames();
        foreach ($cands as &$c) {
            $pid = (int)$c['property_id'];
            $p = $this->property($pid);
            $c['address'] = (string)($p['address'] ?? ('Property #' . $pid));
            $c['client'] = trim((string)($p['client'] ?? ''));
            $c['crew_people'] = array_map(fn($uid) => ['id' => $uid, 'name' => $names[$uid]['name'] ?? ('#' . $uid), 'truck' => !empty($names[$uid]['truck'])], $c['people']);
            $c['evidence'] = UnscheduledWorkRules::evidenceLine($c, array_map(fn($n) => $n['name'], $names));
            $c['plans'] = $this->plans($pid);
            $c['visits_near'] = $this->visitsNear($pid, $c['date']);
            $c['invoices'] = $this->invoicesAfter($pid, $c['date']);
            $c['window'] = date('H:i', $c['start']) . '–' . date('H:i', $c['end']);
        }
        unset($c);
        return $cands;
    }

    private array $memo = [];

    private function userNames(): array
    {
        if (isset($this->memo['users'])) return $this->memo['users'];
        $out = [];
        try {
            foreach ($this->db->query("SELECT id, full_name, device_type FROM users")->fetchAll(PDO::FETCH_ASSOC) as $u) {
                $out[(int)$u['id']] = ['name' => (string)strtok(trim((string)$u['full_name']) ?: ('#' . $u['id']), ' '), 'truck' => ($u['device_type'] ?? '') === 'truck'];
            }
        } catch (Throwable $e) { /* names are a nicety */ }
        return $this->memo['users'] = $out;
    }

    private function property(int $id): array
    {
        if (isset($this->memo['p' . $id])) return $this->memo['p' . $id];
        try {
            $s = $this->db->prepare("
                SELECT p.address, TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) AS client
                FROM properties p LEFT JOIN contacts c ON c.id = p.site_contact_id
                WHERE p.id = ?
            ");
            $s->execute([$id]);
            $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $r = [];
        }
        return $this->memo['p' . $id] = $r;
    }

    /** Active plans on the property (recurring first) — where "Add the visit" can go. */
    public function plans(int $propertyId): array
    {
        try {
            $s = $this->db->prepare("
                SELECT id, plan_number, title, service_type, is_recurring, estimated_duration_minutes
                FROM job_plans WHERE property_id = ? AND status = 'active'
                ORDER BY is_recurring DESC, id DESC LIMIT 6
            ");
            $s->execute([$propertyId]);
            return array_map(fn($r) => ['id' => (int)$r['id'], 'number' => (string)$r['plan_number'], 'title' => (string)$r['title'],
                'service_type' => (string)$r['service_type'], 'recurring' => (int)$r['is_recurring'] === 1], $s->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Visits on this property 3 days either side — "it was due Tuesday" explains a lot. */
    private function visitsNear(int $propertyId, string $date): array
    {
        try {
            $s = $this->db->prepare("
                SELECT v.id, v.scheduled_date, v.status, jp.plan_number
                FROM job_visits v JOIN job_plans jp ON jp.id = v.plan_id
                WHERE jp.property_id = ? AND v.scheduled_date BETWEEN ? AND ? AND v.status <> 'cancelled'
                ORDER BY v.scheduled_date
            ");
            $s->execute([$propertyId, date('Y-m-d', strtotime($date . ' -3 days')), date('Y-m-d', strtotime($date . ' +3 days'))]);
            return array_map(fn($r) => ['id' => (int)$r['id'], 'date' => (string)$r['scheduled_date'], 'status' => (string)$r['status'], 'plan' => (string)$r['plan_number']],
                $s->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Invoices for the property dated that day to 21 days after — the owner may have billed it by hand. */
    private function invoicesAfter(int $propertyId, string $date): array
    {
        try {
            $s = $this->db->prepare("
                SELECT id, invoice_number, issue_date, total, status
                FROM invoices
                WHERE property_id = ? AND issue_date BETWEEN ? AND ? AND status <> 'void'
                ORDER BY issue_date LIMIT 5
            ");
            $s->execute([$propertyId, $date, date('Y-m-d', strtotime($date . ' +21 days'))]);
            return array_map(fn($r) => ['id' => (int)$r['id'], 'number' => (string)$r['invoice_number'], 'date' => (string)$r['issue_date'],
                'total' => $r['total'] !== null ? (float)$r['total'] : null, 'status' => (string)$r['status']], $s->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cache
    // ─────────────────────────────────────────────────────────────────────────

    private function cached(string $date, bool $strict = true): ?array
    {
        if ($date >= $this->today || !$this->cacheReady()) return null;
        try {
            $s = $this->db->prepare("SELECT evidence_json, computed_at FROM otto_unscheduled_days WHERE day = ?");
            $s->execute([$date]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            // Computed before the next morning = late offline uploads may be missing.
            if ($strict && strtotime((string)$r['computed_at']) < strtotime($date . ' +1 day 06:00')) return null;
            $v = json_decode((string)$r['evidence_json'], true);
            if (!is_array($v) || !isset($v['truck'], $v['crew']) || ($strict && (int)($v['v'] ?? 0) !== self::CACHE_VERSION)) return null;
            return $v;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function store(string $date, array $ev): void
    {
        try {
            $this->db->prepare("
                INSERT INTO otto_unscheduled_days (day, evidence_json, computed_at) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE evidence_json = VALUES(evidence_json), computed_at = VALUES(computed_at)
            ")->execute([$date, json_encode($ev), date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            error_log('Otto unscheduled cache: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The owner's answer
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param array $sug    the otto_suggestions row
     * @param array $in     choice: add_visit (plan_id) | oneoff (service_type, title) | not_work
     *                      then: none | invoice | link (invoice_id)
     * @return array{ok: bool, message: string, status?: string, outcome?: array, redirect?: string}
     */
    public function apply(array $sug, array $in, int $actorId): array
    {
        $p = json_decode((string)$sug['suggestion_json'], true) ?: [];
        $pid = (int)$sug['subject_id'];
        $date = (string)$sug['for_date'];
        $choice = (string)($in['choice'] ?? '');
        if ($choice === 'not_work') {
            $n = $this->learnNotWork($pid);
            return ['ok' => true, 'status' => 'dismissed', 'outcome' => ['choice' => 'not_work', 'times' => $n],
                'message' => $n >= 2 ? "Got it. That's twice for this property — I'll stop flagging time there." : 'Got it. Not work.'];
        }
        if (!in_array($choice, ['add_visit', 'oneoff'], true)) return ['ok' => false, 'message' => 'Add the visit, or not work?'];
        $start = self::hm((string)($in['start'] ?? ($p['start'] ?? '')));
        $end = self::hm((string)($in['end'] ?? ($p['end'] ?? '')));
        if (!$start || !$end || $end <= $start) return ['ok' => false, 'message' => 'The end time has to be after the start.'];
        $crewId = (int)($in['crew_id'] ?? ($p['crew_id'] ?? 0)) ?: $actorId;

        $this->loadPlanFunctions();
        if ($choice === 'add_visit') {
            $planId = (int)($in['plan_id'] ?? 0);
            $ok = false;
            foreach ($this->plans($pid) as $pl) if ($pl['id'] === $planId) $ok = true;
            if (!$ok) return ['ok' => false, 'message' => 'Pick one of the active plans on this property.'];
            $r = addAdHocVisit($planId, $date, $crewId, $actorId);
            if (empty($r['success'])) return ['ok' => false, 'message' => implode(' ', $r['errors'] ?? ['Could not add the visit.'])];
            $visitId = (int)$r['visit_id'];
        } else {
            $service = trim((string)($in['service_type'] ?? '')) ?: 'Other';
            $title = trim((string)($in['title'] ?? '')) ?: $service;
            $r = createJobPlan([
                'property_id' => $pid, 'title' => mb_substr($title, 0, 200), 'service_type' => mb_substr($service, 0, 100),
                'description' => 'Added from Otto: the crew was here on ' . date('D M j', strtotime($date)) . ' with nothing scheduled.',
                'plan_start_date' => $date, 'default_crew_id' => $crewId, 'crew_ids' => [$crewId],
                'pricing_model' => 'per_visit', 'is_recurring' => 0,
                'estimated_duration_minutes' => self::minutes($start, $end),
            ], $actorId);
            if (empty($r['success'])) return ['ok' => false, 'message' => implode(' ', $r['errors'] ?? ['Could not create the job.'])];
            $planId = (int)$r['plan_id'];
            $s = $this->db->prepare("SELECT id FROM job_visits WHERE plan_id = ? AND status <> 'cancelled' ORDER BY id LIMIT 1");
            $s->execute([$planId]);
            $visitId = (int)$s->fetchColumn();
            if ($visitId <= 0) return ['ok' => false, 'message' => 'The job was made (plan #' . $planId . ') but no visit came with it. Add it from the job page.'];
        }
        $done = $this->completeQuietly($visitId, $date, $start, $end, isset($p['people']) ? (int)$p['people'] : null, (string)($p['basis'] ?? ''), $actorId);
        if (!$done) return ['ok' => false, 'message' => 'That visit was already started or done — nothing changed. Open it to check.'];

        $outcome = ['choice' => $choice, 'plan_id' => $planId, 'visit_id' => $visitId, 'start' => $start, 'end' => $end,
            'suggested' => ['start' => $p['start'] ?? null, 'end' => $p['end'] ?? null]];
        $then = (string)($in['then'] ?? 'none');
        $msg = 'Visit added and marked done, ' . date('g:i', strtotime($start)) . '–' . date('g:i a', strtotime($end)) . '.';
        $redirect = null;
        if ($then === 'link') {
            $invId = (int)($in['invoice_id'] ?? 0);
            $ok = false;
            foreach ((array)($p['invoices'] ?? []) as $inv) if ((int)$inv['id'] === $invId) $ok = true;
            if (!$ok) return ['ok' => true, 'status' => 'edited', 'outcome' => $outcome, 'message' => $msg . ' I could not link that invoice — link it from the visit.'];
            $this->db->prepare("UPDATE job_visits SET invoice_id = ?, is_invoiced = 1 WHERE id = ? AND invoice_id IS NULL")->execute([$invId, $visitId]);
            $outcome['invoice_id'] = $invId;
            $msg .= ' Linked to the invoice you already sent.';
        } elseif ($then === 'invoice') {
            $redirect = '/crm/invoices/create.php?visit_id=' . $visitId;
            $msg .= ' Opening a draft invoice…';
        }
        $kept = $start === ($p['start'] ?? null) && $end === ($p['end'] ?? null);
        return ['ok' => true, 'status' => $kept ? 'accepted' : 'edited', 'outcome' => $outcome, 'message' => $msg, 'redirect' => $redirect, 'visit_id' => $visitId];
    }

    /**
     * Marks a just-added visit completed with the observed times, and its stop — without the
     * customer email / review request / push that a live completion sends. Only a 'scheduled'
     * visit is touched (addAdHocVisit returns an existing visit if one was already there).
     */
    public function completeQuietly(int $visitId, string $date, string $start, string $end, ?int $people, string $basis, int $actorId = 0): bool
    {
        $minutes = self::minutes($start, $end);
        $s = $this->db->prepare("
            UPDATE job_visits
            SET status = 'completed', status_changed_at = ?, started_at = ?, completed_at = ?,
                scheduled_time_start = ?, scheduled_time_end = ?, actual_duration_minutes = ?, actual_crew_count = ?,
                completion_notes = ?
            WHERE id = ? AND status = 'scheduled'
        ");
        $s->execute([
            date('Y-m-d H:i:s'), $date . ' ' . $start . ':00', $date . ' ' . $end . ':00', $start . ':00', $end . ':00', $minutes, $people,
            'Added from Otto after the fact: ' . ($basis === 'crew' ? 'crew GPS' : 'truck GPS' . ($basis === 'truck+crew' ? ' + crew GPS' : '')) . ' had the crew here with nothing scheduled.',
            $visitId,
        ]);
        if ($s->rowCount() === 0) return false;
        $st = $this->db->prepare("SELECT stop_id FROM job_visits WHERE id = ?");
        $st->execute([$visitId]);
        $stopId = (int)$st->fetchColumn();
        if ($stopId > 0) {
            $this->db->prepare("
                UPDATE calendar_stops SET estimated_arrival = COALESCE(estimated_arrival, ?), estimated_departure = COALESCE(estimated_departure, ?)
                WHERE id = ?
            ")->execute([$start . ':00', $end . ':00', $stopId]);
            if (class_exists('VisitLifecycleService')) VisitLifecycleService::propagateStopStatus($stopId);
        }
        // Cost snapshot (labour / drive) — no messages in it.
        try {
            $f = dirname(__DIR__, 2) . '/Jobs/Services/VisitCompletionService.php';
            if (!class_exists('VisitCompletionService') && is_file($f)) require_once $f;
            if (class_exists('VisitCompletionService')) VisitCompletionService::capture($visitId, $actorId);
        } catch (Throwable $e) {
            error_log('Otto unscheduled capture: ' . $e->getMessage());
        }
        return true;
    }

    private function learnNotWork(int $propertyId): int
    {
        $n = 0;
        try {
            $s = $this->db->prepare("SELECT value_json FROM otto_lessons WHERE scope = 'unscheduled' AND scope_key = ?");
            $s->execute(['property:' . $propertyId]);
            $v = json_decode((string)$s->fetchColumn(), true) ?: [];
            $n = (int)($v['not_work'] ?? 0) + 1;
            $v['not_work'] = $n;
            $v['last'] = date('Y-m-d');
            $this->db->prepare("
                INSERT INTO otto_lessons (scope, scope_key, value_json) VALUES ('unscheduled', ?, ?)
                ON DUPLICATE KEY UPDATE value_json = VALUES(value_json)
            ")->execute(['property:' . $propertyId, json_encode($v)]);
        } catch (Throwable $e) {
            error_log('Otto unscheduled lesson: ' . $e->getMessage());
        }
        return $n;
    }

    private function loadPlanFunctions(): void
    {
        if (!function_exists('addAdHocVisit')) require_once APP_ROOT . '/Modules/Jobs/Services/PlanFunctions.php';
    }

    // ── Small helpers ───────────────────────────────────────────────────────

    public static function bbox(array $points, float $margin): array
    {
        $lats = array_column($points, 'lat');
        $lngs = array_column($points, 'lng');
        return [min($lats) - $margin, max($lats) + $margin, min($lngs) - $margin, max($lngs) + $margin];
    }

    /** "8:10" / "08:10:00" → "08:10", else null. */
    public static function hm(string $t): ?string
    {
        return preg_match('/^(\d{1,2}):(\d{2})/', trim($t), $m) && (int)$m[1] < 24 && (int)$m[2] < 60 ? sprintf('%02d:%02d', $m[1], $m[2]) : null;
    }

    public static function minutes(string $start, string $end): int
    {
        return (int)round((strtotime('2000-01-01 ' . $end) - strtotime('2000-01-01 ' . $start)) / 60);
    }

    public static function street(string $address): string
    {
        $a = trim(explode(',', $address)[0] ?? '');
        return $a !== '' ? $a : 'the property';
    }
}
