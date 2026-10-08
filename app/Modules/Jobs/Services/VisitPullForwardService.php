<?php
declare(strict_types=1);

require_once __DIR__ . '/CalendarStopTidyService.php';

/**
 * VisitPullForwardService — "you're at a property whose visit is on another day: doing it now?"
 *
 * Crew use their time well and work ahead: Mon Oct 5 at Oakridge Gardens (property 73) they timed
 * visits #2160 (booked Oct 6) and #2159 (Oct 7). GPS auto-arrival only looks at TODAY's visits,
 * so arriving at a property with nothing today was ignored, the work was timed against visits
 * still dated later in the week, the schedule showed it on the wrong day, and empty stops stayed.
 *
 * This service:
 *   offerAt()          — phone at a property (same fence test as auto-arrival: 150 m radius or a
 *                        drawn arrival border) with no OPEN visit there today → an offer, never an
 *                        auto-start. Candidates: that property's scheduled visits within ±7 days,
 *                        overdue first (oldest first), then the next upcoming. Skipped, cancelled
 *                        and weather visits are never offered. Contract plans are ordinary plans.
 *   pullForward()      — move ONE visit to today: re-link it to today's stop (crewing the person
 *                        on site onto it so the existing timer path accepts them), remove the old
 *                        stop if it is left empty, write visit_moves. Idempotent by request key and
 *                        by "already today". The plan is never touched: recurring visits are
 *                        generated from the plan's calendar (VisitGenerationService — weekday +
 *                        interval from plan_start_date, behind a watermark), not from the last
 *                        completion, so a weekly cut done two days early leaves the series alone.
 *   dryRun()           — read-only: what would have been offered at a property on a past day.
 *
 * Global-namespace, no autoloader: require_once this file, new VisitPullForwardService($db).
 */
class VisitPullForwardService
{
    public const WINDOW_DAYS     = 7;
    public const REASON_ON_SITE  = 'pulled_forward_on_site';
    /** Visit statuses that are never offered. */
    public const NEVER_OFFERED   = ['skipped', 'cancelled', 'weather'];

    private PDO $db;
    private string $today;

    public function __construct(PDO $db, ?string $today = null)
    {
        $this->db    = $db;
        $this->today = $today ?? date('Y-m-d');
    }

    // ── Pure rules ─────────────────────────────────────────────────────────────

    /**
     * Filter and order candidate visits for a day. Keeps rows that are open (status 'scheduled',
     * or — for a historical dry run — flagged 'open_as_of'), not on $today, within ±$windowDays.
     * Order: by date ascending, which puts overdue (oldest first) before upcoming (soonest first).
     *
     * @param array<int, array<string, mixed>> $visits rows with id, scheduled_date, status
     * @return array<int, array<string, mixed>> rows annotated with overdue + days_from_today
     */
    public static function rankCandidates(array $visits, string $today, int $windowDays = self::WINDOW_DAYS): array
    {
        $todayTs = strtotime($today . ' 00:00:00');
        $out = [];
        foreach ($visits as $v) {
            $status = (string)($v['status'] ?? '');
            if (in_array($status, self::NEVER_OFFERED, true)) {
                continue;
            }
            if ($status !== 'scheduled' && empty($v['open_as_of'])) {
                continue;
            }
            $date = substr((string)($v['scheduled_date'] ?? ''), 0, 10);
            $ts   = strtotime($date . ' 00:00:00');
            if ($ts === false || $date === $today) {
                continue;
            }
            $days = (int)round(($ts - $todayTs) / 86400);
            if (abs($days) > $windowDays) {
                continue;
            }
            $v['overdue']         = $days < 0;
            $v['days_from_today'] = $days;
            $out[] = $v;
        }
        usort($out, static function (array $a, array $b): int {
            $c = strcmp((string)$a['scheduled_date'], (string)$b['scheduled_date']);
            return $c !== 0 ? $c : ((int)$a['id'] <=> (int)$b['id']);
        });
        return $out;
    }

    /** "booked Wed Oct 7" / "was due Mon Oct 5". */
    public static function whenLabel(string $date, string $today): string
    {
        $label = date('D M j', (int)strtotime($date));
        return $date < $today ? 'was due ' . $label : 'is booked ' . $label;
    }

    /** "Lawn Cut for Oakridge Gardens is booked Wed Oct 7. Doing it now?" */
    public static function headline(array $visit, string $siteName, string $today): string
    {
        $what = trim((string)($visit['service_type'] ?? '')) ?: trim((string)($visit['plan_title'] ?? '')) ?: 'The visit';
        $for  = $siteName !== '' ? ' for ' . $siteName : '';
        return $what . $for . ' ' . self::whenLabel((string)$visit['scheduled_date'], $today) . '. Doing it now?';
    }

    /** Haversine distance in metres. */
    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    // ── Where is the phone? ───────────────────────────────────────────────────

    /**
     * Properties whose fence contains the point: inside a drawn arrival border first, then
     * within $radiusM of the geocoded centre, nearest first.
     *
     * @return array<int, array{property_id:int, distance:float, inside_border:bool}>
     */
    public function propertiesAt(float $lat, float $lng, int $radiusM): array
    {
        $found = [];

        // Radius: bounding-box prefilter in SQL, exact distance in PHP.
        $dLat = $radiusM / 111320.0;
        $dLng = $radiusM / (111320.0 * max(0.01, cos(deg2rad($lat))));
        $stmt = $this->db->prepare("
            SELECT id, latitude, longitude FROM properties
            WHERE latitude BETWEEN ? AND ? AND longitude BETWEEN ? AND ?
        ");
        $stmt->execute([$lat - $dLat, $lat + $dLat, $lng - $dLng, $lng + $dLng]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $d = self::distanceMeters($lat, $lng, (float)$p['latitude'], (float)$p['longitude']);
            if ($d <= $radiusM) {
                $found[(int)$p['id']] = ['property_id' => (int)$p['id'], 'distance' => $d, 'inside_border' => false];
            }
        }

        // Drawn arrival borders (large multi-address sites, or no geocode).
        if (function_exists('geofencePointInPolygon')) {
            try {
                $g = $this->db->prepare("
                    SELECT property_id, polygon_json, bbox_lat_min, bbox_lat_max, bbox_lng_min, bbox_lng_max
                    FROM job_geofences
                    WHERE zone_type = 'arrival_border'
                      AND bbox_lat_min <= ? AND bbox_lat_max >= ? AND bbox_lng_min <= ? AND bbox_lng_max >= ?
                ");
                $g->execute([$lat, $lat, $lng, $lng]);
                foreach ($g->fetchAll(PDO::FETCH_ASSOC) as $b) {
                    $poly = json_decode((string)$b['polygon_json'], true) ?: [];
                    $bbox = ['lat_min' => (float)$b['bbox_lat_min'], 'lat_max' => (float)$b['bbox_lat_max'],
                             'lng_min' => (float)$b['bbox_lng_min'], 'lng_max' => (float)$b['bbox_lng_max']];
                    if (geofencePointInPolygon($lat, $lng, $poly, $bbox)) {
                        $pid = (int)$b['property_id'];
                        $found[$pid] = ['property_id' => $pid, 'distance' => 0.0, 'inside_border' => true];
                    }
                }
            } catch (PDOException $e) {
                // No geofence table on this schema — radius only.
            }
        }

        $list = array_values($found);
        usort($list, static function (array $a, array $b): int {
            if ($a['inside_border'] !== $b['inside_border']) {
                return $a['inside_border'] ? -1 : 1;
            }
            return $a['distance'] <=> $b['distance'];
        });
        return $list;
    }

    // ── What could be done here? ──────────────────────────────────────────────

    /**
     * The offer for one property on $asOf (default today). Null property → null.
     *
     * Live (today): suppressed while the property has an OPEN visit today — auto-arrival and the
     * normal Start button own that. Once today's visit is done, the next one is offered, which is
     * exactly the Oakridge case (finish Monday's cut, then do Tuesday's and Wednesday's).
     *
     * Historical ($asOf before today, dry run only): a visit counts as open on $asOf if it is still
     * scheduled, or it was started/completed on or after $asOf (it was open that morning).
     *
     * @return array<string, mixed>|null
     */
    public function offerForProperty(int $propertyId, ?string $asOf = null): ?array
    {
        $day        = $asOf ?? $this->today;
        $historical = $day < $this->today;

        $p = $this->db->prepare("SELECT id, address, property_name FROM properties WHERE id = ?");
        $p->execute([$propertyId]);
        $property = $p->fetch(PDO::FETCH_ASSOC);
        if (!$property) {
            return null;
        }
        $site = trim((string)($property['property_name'] ?? '')) ?: trim((string)($property['address'] ?? ''));

        $from = date('Y-m-d', (int)strtotime($day . ' -' . self::WINDOW_DAYS . ' days'));
        $to   = date('Y-m-d', (int)strtotime($day . ' +' . self::WINDOW_DAYS . ' days'));

        $v = $this->db->prepare("
            SELECT jv.id, jv.visit_number, jv.plan_id, jv.stop_id, jv.scheduled_date, jv.status,
                   jv.started_at, jv.completed_at, jv.assigned_crew_id,
                   jp.title AS plan_title, jp.service_type, jp.plan_number, jp.is_recurring
            FROM job_visits jv
            JOIN job_plans jp ON jp.id = jv.plan_id
            WHERE jp.property_id = ?
              AND jp.status = 'active'
              AND jv.scheduled_date BETWEEN ? AND ?
            ORDER BY jv.scheduled_date, jv.id
        ");
        $v->execute([$propertyId, $from, $to]);
        $rows = $v->fetchAll(PDO::FETCH_ASSOC);

        $todayVisits = [];
        $pool = [];
        foreach ($rows as $r) {
            $status = (string)$r['status'];
            $date   = substr((string)$r['scheduled_date'], 0, 10);
            if ($historical && in_array($status, ['in_progress', 'completed'], true)) {
                $touched = substr((string)($r['started_at'] ?: $r['completed_at'] ?: ''), 0, 10);
                $r['open_as_of'] = $touched !== '' && $touched >= $day;
            }
            if ($date === $day) {
                $todayVisits[] = ['id' => (int)$r['id'], 'visit_number' => $r['visit_number'], 'status' => $status];
                continue;
            }
            $pool[] = $r;
        }

        $openToday = array_values(array_filter($todayVisits, static function (array $t): bool {
            return in_array($t['status'], ['scheduled', 'in_progress'], true);
        }));

        $ranked = self::rankCandidates($pool, $day);
        $result = [
            'property_id'   => (int)$property['id'],
            'site_name'     => $site,
            'address'       => (string)($property['address'] ?? ''),
            'date'          => $day,
            'today_visits'  => $todayVisits,
            'suppressed'    => null,
            'primary'       => null,
            'others'        => [],
            'plans_without_visit' => [],
            'headline'      => null,
        ];
        if (!$historical && $openToday) {
            $result['suppressed'] = 'open_visit_today';
            return $result;
        }
        if (!$ranked) {
            $result['plans_without_visit'] = $this->activePlans($propertyId, []);
            return $result;
        }

        $byPlan = [];
        foreach ($ranked as $c) {
            $pid = (int)$c['plan_id'];
            if (!isset($byPlan[$pid])) {
                $byPlan[$pid] = $this->shape($c, $day);
            }
        }
        $primary = $this->shape($ranked[0], $day);
        unset($byPlan[(int)$primary['plan_id']]);

        $result['primary']  = $primary;
        $result['others']   = array_values($byPlan);
        $result['headline'] = self::headline($ranked[0], $site, $day);
        $result['plans_without_visit'] = $this->activePlans($propertyId, array_merge([(int)$primary['plan_id']], array_keys($byPlan)));
        return $result;
    }

    /**
     * Offer at a GPS fix: first fenced property with something to offer.
     * Caller (endpoint) has already applied the accuracy / live-timer / dwell guards.
     *
     * @return array{offer: ?array, reason: string}
     */
    public function offerAt(float $lat, float $lng, int $radiusM): array
    {
        $props = $this->propertiesAt($lat, $lng, $radiusM);
        if (!$props) {
            return ['offer' => null, 'reason' => 'not_at_a_property'];
        }
        $reason = 'nothing_to_offer';
        foreach ($props as $p) {
            $offer = $this->offerForProperty($p['property_id']);
            if (!$offer) {
                continue;
            }
            if ($offer['suppressed']) {
                // An open visit here today: auto-arrival / Start own it. Stop looking.
                return ['offer' => null, 'reason' => $offer['suppressed']];
            }
            // Only a booked visit on another day is worth interrupting someone for; a property
            // with plans but nothing booked this week is left to the "Add a job" flow.
            if ($offer['primary']) {
                $offer['distance_meters'] = (int)round($p['distance']);
                $offer['inside_border']   = $p['inside_border'];
                return ['offer' => $offer, 'reason' => 'offer'];
            }
        }
        return ['offer' => null, 'reason' => $reason];
    }

    /** Read-only: everything in the window at a property on $date, with the offer that day. */
    public function dryRun(int $propertyId, string $date): array
    {
        $offer = $this->offerForProperty($propertyId, $date);
        if (!$offer) {
            return ['success' => false, 'error' => 'Property not found'];
        }
        $from = date('Y-m-d', (int)strtotime($date . ' -' . self::WINDOW_DAYS . ' days'));
        $to   = date('Y-m-d', (int)strtotime($date . ' +' . self::WINDOW_DAYS . ' days'));
        $s = $this->db->prepare("
            SELECT jv.id, jv.visit_number, jv.plan_id, jv.scheduled_date, jv.status, jv.started_at,
                   jv.completed_at, jp.title AS plan_title, jp.status AS plan_status
            FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id
            WHERE jp.property_id = ? AND jv.scheduled_date BETWEEN ? AND ?
            ORDER BY jv.scheduled_date, jv.id
        ");
        $s->execute([$propertyId, $from, $to]);
        return [
            'success'  => true,
            'read_only'=> true,
            'note'     => $date < $this->today
                ? 'Historical: a visit counts as open that day if it is still scheduled or was started/completed on or after it. Live offers are held back while an open visit is booked for today.'
                : 'Live rules.',
            'offer'    => $offer,
            'window'   => $s->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    // ── Doing it ──────────────────────────────────────────────────────────────

    /**
     * Move one visit to today because the crew is on site doing it.
     *
     * @return array{success:bool, error?:string, status?:int, visit_id?:int, from_date?:string,
     *               to_date?:string, stop_id?:int, old_stop_deleted?:bool, already?:bool}
     */
    public function pullForward(int $visitId, int $userId, string $requestKey = '', ?int $expectPropertyId = null,
                                string $reason = self::REASON_ON_SITE): array
    {
        $requestKey = substr(trim($requestKey), 0, 64);
        if ($requestKey !== '' && ($prior = $this->priorMove($requestKey)) !== null) {
            return $prior;
        }

        $q = $this->db->prepare("
            SELECT jv.id, jv.plan_id, jv.stop_id, jv.scheduled_date, jv.status, jv.assigned_crew_id,
                   jv.visit_number, jp.property_id, jp.default_crew_id, jp.status AS plan_status
            FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id
            WHERE jv.id = ?
        ");
        $q->execute([$visitId]);
        $visit = $q->fetch(PDO::FETCH_ASSOC);
        if (!$visit) {
            return ['success' => false, 'status' => 404, 'error' => 'Visit not found.'];
        }
        if ($expectPropertyId !== null && $expectPropertyId > 0 && (int)$visit['property_id'] !== $expectPropertyId) {
            return ['success' => false, 'status' => 409, 'error' => 'That visit is for a different property.'];
        }
        $fromDate = substr((string)$visit['scheduled_date'], 0, 10);
        $status   = (string)$visit['status'];

        // Already today (a replay without a key, or a double tap) — nothing to do.
        if ($fromDate === $this->today && in_array($status, ['scheduled', 'in_progress', 'completed'], true)) {
            return ['success' => true, 'already' => true, 'visit_id' => $visitId, 'from_date' => $fromDate,
                    'to_date' => $this->today, 'stop_id' => (int)$visit['stop_id'], 'old_stop_deleted' => false];
        }
        if ($status !== 'scheduled') {
            return ['success' => false, 'status' => 409, 'error' => "This visit is {$status} — it can't be moved."];
        }
        if ((string)$visit['plan_status'] !== 'active') {
            return ['success' => false, 'status' => 409, 'error' => 'This job is not active.'];
        }
        $days = (int)round((strtotime($fromDate) - strtotime($this->today)) / 86400);
        if (abs($days) > self::WINDOW_DAYS) {
            return ['success' => false, 'status' => 409, 'error' => 'Only visits within a week of today can be moved here.'];
        }

        $crewId     = $visit['assigned_crew_id'] !== null ? (int)$visit['assigned_crew_id']
                    : ($visit['default_crew_id'] !== null ? (int)$visit['default_crew_id'] : null);
        $propertyId = (int)$visit['property_id'];
        $oldStopId  = $visit['stop_id'] !== null ? (int)$visit['stop_id'] : null;

        $ownTxn = !$this->db->inTransaction();
        if ($ownTxn) {
            $this->db->beginTransaction();
        }
        try {
            $newStopId = $this->findOrCreateStop($propertyId, $this->today, $crewId);

            $this->db->prepare("
                UPDATE job_visits
                SET scheduled_date = ?, stop_id = ?,
                    original_scheduled_date = COALESCE(original_scheduled_date, ?)
                WHERE id = ? AND status = 'scheduled'
            ")->execute([$this->today, $newStopId, $fromDate, $visitId]);

            // The person on site is crew on today's stop, so the existing timer path
            // (startJobTimer → userIsCrewOnVisit) accepts them.
            if ($userId > 0 && $userId !== $crewId) {
                $has = $this->db->prepare("SELECT COUNT(*) FROM calendar_stop_crew WHERE stop_id = ? AND user_id = ?");
                $has->execute([$newStopId, $userId]);
                if ((int)$has->fetchColumn() === 0) {
                    $this->db->prepare("INSERT INTO calendar_stop_crew (stop_id, user_id) VALUES (?, ?)")
                             ->execute([$newStopId, $userId]);
                }
            }

            $oldDeleted = ($oldStopId && $oldStopId !== $newStopId)
                ? CalendarStopTidyService::deleteIfEmpty($this->db, $oldStopId)
                : false;

            $this->recordMove([
                'visit_id' => $visitId, 'plan_id' => (int)$visit['plan_id'], 'property_id' => $propertyId,
                'from_date' => $fromDate, 'to_date' => $this->today, 'from_stop_id' => $oldStopId,
                'to_stop_id' => $newStopId, 'from_stop_deleted' => $oldDeleted ? 1 : 0,
                'reason' => $reason, 'moved_by' => $userId ?: null,
                'request_key' => $requestKey !== '' ? $requestKey : null,
            ]);

            if ($ownTxn) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTxn && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        if (function_exists('logActivityExtended')) {
            try {
                logActivityExtended($userId, 'Visit pulled forward',
                    "Visit {$visit['visit_number']} moved {$fromDate} → {$this->today} ({$reason})",
                    null, null, null, null, (int)$visit['plan_id'], $visitId);
            } catch (Throwable $e) { /* activity log is a convenience */ }
        }

        return ['success' => true, 'already' => false, 'visit_id' => $visitId, 'from_date' => $fromDate,
                'to_date' => $this->today, 'stop_id' => $newStopId, 'old_stop_deleted' => $oldDeleted];
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function shape(array $c, string $day): array
    {
        return [
            'visit_id'        => (int)$c['id'],
            'visit_number'    => (string)$c['visit_number'],
            'plan_id'         => (int)$c['plan_id'],
            'plan_number'     => (string)($c['plan_number'] ?? ''),
            'title'           => (string)($c['plan_title'] ?? ''),
            'service_type'    => (string)($c['service_type'] ?? ''),
            'scheduled_date'  => substr((string)$c['scheduled_date'], 0, 10),
            'when_label'      => self::whenLabel(substr((string)$c['scheduled_date'], 0, 10), $day),
            'overdue'         => (bool)$c['overdue'],
            'days_from_today' => (int)$c['days_from_today'],
            'is_recurring'    => (bool)($c['is_recurring'] ?? false),
        ];
    }

    /** Active plans at the property not already represented — offered as "add a visit today". */
    private function activePlans(int $propertyId, array $excludePlanIds): array
    {
        $s = $this->db->prepare("SELECT id, plan_number, title, service_type FROM job_plans WHERE property_id = ? AND status = 'active' ORDER BY id");
        $s->execute([$propertyId]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $p) {
            if (in_array((int)$p['id'], array_map('intval', $excludePlanIds), true)) {
                continue;
            }
            $out[] = ['plan_id' => (int)$p['id'], 'plan_number' => (string)$p['plan_number'],
                      'title' => (string)$p['title'], 'service_type' => (string)$p['service_type']];
        }
        return $out;
    }

    private function findOrCreateStop(int $propertyId, string $date, ?int $crewId): int
    {
        $find = function () use ($propertyId, $date, $crewId): int {
            if ($crewId === null) {
                $s = $this->db->prepare("SELECT id FROM calendar_stops WHERE property_id = ? AND stop_date = ? AND crew_id IS NULL ORDER BY id LIMIT 1");
                $s->execute([$propertyId, $date]);
            } else {
                $s = $this->db->prepare("SELECT id FROM calendar_stops WHERE property_id = ? AND stop_date = ? AND crew_id = ? ORDER BY id LIMIT 1");
                $s->execute([$propertyId, $date, $crewId]);
            }
            return (int)$s->fetchColumn();
        };
        $id = $find();
        if ($id > 0) {
            return $id;
        }
        $o = $this->db->prepare("SELECT COALESCE(MAX(route_order), 0) + 1 FROM calendar_stops WHERE stop_date = ?");
        $o->execute([$date]);
        $order = (int)$o->fetchColumn();
        try {
            $this->db->prepare("INSERT INTO calendar_stops (property_id, stop_date, crew_id, route_order, status) VALUES (?, ?, ?, ?, 'scheduled')")
                     ->execute([$propertyId, $date, $crewId, $order]);
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            // Unique (property, date, crew) race — another request made it first.
            $id = $find();
            if ($id > 0) {
                return $id;
            }
            throw $e;
        }
    }

    private function priorMove(string $requestKey): ?array
    {
        try {
            $s = $this->db->prepare("SELECT * FROM visit_moves WHERE request_key = ? LIMIT 1");
            $s->execute([$requestKey]);
            $m = $s->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return null; // migration 1275 not run yet
        }
        if (!$m) {
            return null;
        }
        return ['success' => true, 'already' => true, 'visit_id' => (int)$m['visit_id'],
                'from_date' => substr((string)$m['from_date'], 0, 10), 'to_date' => substr((string)$m['to_date'], 0, 10),
                'stop_id' => (int)$m['to_stop_id'], 'old_stop_deleted' => (bool)$m['from_stop_deleted']];
    }

    private function recordMove(array $m): void
    {
        try {
            $this->db->prepare("
                INSERT INTO visit_moves (visit_id, plan_id, property_id, from_date, to_date, from_stop_id,
                                         to_stop_id, from_stop_deleted, reason, moved_by, request_key)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$m['visit_id'], $m['plan_id'], $m['property_id'], $m['from_date'], $m['to_date'],
                         $m['from_stop_id'], $m['to_stop_id'], $m['from_stop_deleted'], $m['reason'],
                         $m['moved_by'], $m['request_key']]);
        } catch (PDOException $e) {
            // Missing table (1275 not run) must not undo a move the crew is standing in front of.
            $missing = $e->getCode() === '42S02'
                || stripos($e->getMessage(), 'no such table') !== false
                || stripos($e->getMessage(), "doesn't exist") !== false;
            if (!$missing) {
                throw $e; // e.g. a duplicate request_key race — roll back, the replay finds the first
            }
            error_log('[VisitPullForward] visit_moves not available: ' . $e->getMessage());
        }
    }
}
