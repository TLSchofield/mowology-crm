<?php
/**
 * OpsDeskService — Otto's desk: today's crews and stops, weather that changes the plan,
 * and the gaps in GPS and timesheets. Feeds the dashboard card, the card's API and
 * Charlie's 7 am brief.
 *
 * Where the facts come from:
 *   today      calendar_stops (+ calendar_stop_crew), time_clock_entries
 *   weather    job_visits.weather_status / weather_snapshot_raw, written by the noon
 *              weather guard (app/Modules/Jobs/Cron/weather_schedule_guard.php)
 *   clock-outs time_clock_entries still open from an earlier day, or closed by the
 *              auto_clockout cron ("[auto-completed by cron" in notes)
 *   timers     job_time_entries.auto_stopped = 1 (stop_orphaned_job_timers cron)
 *   no time    completed job_visits with no job_time_entries and no actual duration
 *   quiet      TrackingHealthService — clocked in, tracking on, nothing heard
 *
 * Otto only suggests. OttoActionService applies a suggestion when the owner clicks it.
 * current(true) records each suggestion once (otto_suggestions) so the owner's decision
 * can be learned from; current(false) and brief() only read.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/OttoRules.php';

class OpsDeskService
{
    public const KINDS = ['weather', 'clock_out', 'job_timer', 'no_time', 'silent', 'bylaw', 'west_end', 'truck_range', 'maintenance', 'pack_fading', 'training_gap', 'training_quality', 'training_topic', 'safety_refresher', 'unscheduled', 'extra_work', 'visit_date', 'duration'];
    /** Dispatcher kinds (phase 2): rule tables + equipment register. */
    public const DISPATCH_KINDS = ['bylaw', 'west_end', 'truck_range', 'maintenance', 'pack_fading'];
    /** Crew training kinds (quiz + certification watched by Otto). */
    public const TRAINING_KINDS = ['training_gap', 'training_quality', 'training_topic', 'safety_refresher'];
    private const LIMIT = 20;

    private PDO $db;
    private string $today;

    public function __construct(PDO $db, ?string $today = null)
    {
        $this->db = $db;
        $this->today = $today ?? date('Y-m-d');
    }

    /** Migrations 1150–1152 have run. */
    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'otto_suggestions'")->rowCount() > 0
                && $this->db->query("SHOW TABLES LIKE 'otto_lessons'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Today
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{stops: int, done: int, unassigned: int, crews: array, on_clock: int} */
    public function today(): array
    {
        $out = ['stops' => 0, 'done' => 0, 'unassigned' => 0, 'crews' => [], 'on_clock' => 0];
        try {
            $s = $this->db->prepare("
                SELECT cs.id, cs.status, cs.crew_id, u.full_name
                FROM calendar_stops cs
                LEFT JOIN users u ON u.id = cs.crew_id
                WHERE cs.stop_date = ?
            ");
            $s->execute([$this->today]);
            $stops = $s->fetchAll(PDO::FETCH_ASSOC);
            $extra = [];
            if ($stops) {
                try {
                    $ids = array_map(fn($r) => (int)$r['id'], $stops);
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    $c = $this->db->prepare("
                        SELECT csc.stop_id, u.id AS user_id, u.full_name
                        FROM calendar_stop_crew csc JOIN users u ON u.id = csc.user_id
                        WHERE csc.stop_id IN ({$in})
                    ");
                    $c->execute($ids);
                    foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $r) $extra[(int)$r['stop_id']][(int)$r['user_id']] = (string)$r['full_name'];
                } catch (Throwable $e) { /* no crew table — lead crew only */ }
            }
            $crews = [];
            foreach ($stops as $r) {
                $out['stops']++;
                $done = in_array($r['status'], ['completed', 'skipped'], true);
                if ($done) $out['done']++;
                $people = $extra[(int)$r['id']] ?? [];
                if ($r['crew_id']) $people = [(int)$r['crew_id'] => (string)$r['full_name']] + $people;
                if (!$people) {
                    $out['unassigned']++;
                    continue;
                }
                // A crew is named by its lead (the stop's crew_id), so a two-person crew counts once.
                $lead = array_key_first($people);
                $crews[$lead] ??= ['name' => self::firstName((string)$people[$lead]), 'stops' => 0, 'done' => 0];
                $crews[$lead]['stops']++;
                if ($done) $crews[$lead]['done']++;
            }
            $out['crews'] = array_values($crews);
            $c = $this->db->prepare("SELECT COUNT(*) FROM time_clock_entries WHERE status = 'active' AND clock_out IS NULL AND clock_in >= ?");
            $c->execute([$this->today . ' 00:00:00']);
            $out['on_clock'] = (int)$c->fetchColumn();
        } catch (Throwable $e) {
            error_log('Otto today: ' . $e->getMessage());
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Suggestions
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Everything Otto would suggest right now, minus what the owner already decided.
     * @param bool $persist record new suggestions and expire ones that no longer apply
     * @return array<int, array> items, most urgent first
     */
    public function current(bool $persist = false): array
    {
        $items = array_merge($this->weatherItems(), $this->clockOutItems(), $this->timerItems(), $this->noTimeItems(), $this->silentItems(), $this->dispatchItems(),
            $this->unscheduledItems($persist), $this->durationItems());
        $known = $this->known();
        $open = [];
        foreach ($items as $it) {
            $k = self::rowKey($it['kind'], $it['subject_type'], (int)$it['subject_id'], $it['for_date']);
            $row = $known[$k] ?? null;
            if ($row && $row['status'] !== 'open') continue;
            if (!$row && $persist) $row = $this->record($it);
            $it['sid'] = $row ? (int)$row['id'] : null;
            $open[$k] = $it;
        }
        if ($persist) $this->expire($known, $open);
        return OttoRules::sortItems(array_values($open));
    }

    /** One line per thing for the card's numbers and the brief. */
    public function stats(?array $items = null): array
    {
        $items ??= $this->current(false);
        $by = array_fill_keys(self::KINDS, 0);
        $silent = [];
        foreach ($items as $it) {
            $by[$it['kind']]++;
            if ($it['kind'] === 'silent') $silent[] = $it['who'];
        }
        $today = $this->today();
        return [
            'today'   => $today,
            'by_kind' => $by,
            'weather' => $by['weather'],
            'gaps'    => $by['clock_out'] + $by['job_timer'] + $by['no_time'],
            'dispatch' => array_sum(array_intersect_key($by, array_flip(self::DISPATCH_KINDS))),
            'training' => array_sum(array_intersect_key($by, array_flip(self::TRAINING_KINDS))),
            'silent'  => $silent,
            'unscheduled' => $by['unscheduled'] + $by['extra_work'],
            'durations' => $by['duration'],
            'coverage' => $this->coverage(),
            'right_first_time' => $this->rightFirstTime(),
            'outlook' => $this->outlookLine(),
        ];
    }

    /**
     * Charlie's 7 am brief. Read-only and cheap.
     * @return array{head: string, headline: string, items: array, count: int}
     */
    public function brief(string $ownerFirstName): array
    {
        if (!$this->ready()) {
            return ['head' => 'otto', 'headline' => '', 'items' => [], 'count' => 0];
        }
        $items = $this->current(false);
        $s = $this->stats($items);
        $headline = OttoRules::headline([
            'stops' => $s['today']['stops'], 'done' => $s['today']['done'], 'crews' => count($s['today']['crews']),
            'silent' => $s['silent'], 'weather' => $s['weather'], 'gaps' => $s['gaps'], 'dispatch' => $s['dispatch'] ?? 0, 'training' => $s['training'] ?? 0,
        ], $ownerFirstName);
        $out = [];
        foreach ($items as $it) {
            $out[] = [
                'key' => $it['key'], 'kind' => $it['kind'], 'text' => $it['text'], 'url' => $it['url'],
                'priority' => (int)$it['priority'], 'value' => $it['value'] ?? null, 'since' => $it['since'] ?? null,
            ];
        }
        if ($s['today']['unassigned'] > 0) {
            $n = $s['today']['unassigned'];
            $out[] = ['key' => 'otto:unassigned:' . $this->today, 'kind' => 'unassigned',
                'text' => OttoRules::plural($n, 'stop') . ' today ' . ($n === 1 ? 'has' : 'have') . ' no crew.',
                'url' => '/crm/jobs/schedule.php', 'priority' => 2, 'value' => $n, 'since' => $this->today];
        }
        // Jobs whose dump / supply runs cost more than the quote allowed for trips (priority 3) — Penny's tagging.
        try {
            require_once dirname(__DIR__, 2) . '/Expenses/Services/TripAttributionService.php';
            foreach ((new TripAttributionService($this->db, $this->today))->briefItems() as $it) $out[] = $it;
        } catch (Throwable $e) { /* additive only (migration 1218) */ }
        // Completed visits with no photos in the last 7 days (priority 3) — Mia's library needs them.
        try {
            require_once dirname(__DIR__, 2) . '/Marketing/Services/MediaTagService.php';
            foreach (MediaTagService::missingPhotoItems($this->db, $this->today) as $it) $out[] = $it;
        } catch (Throwable $e) { /* additive only */ }
        // Unnamed truck stops today (priority 3) — name them once so dump / supply runs get costed.
        try {
            require_once __DIR__ . '/TripCostService.php';
            foreach ((new TripCostService($this->db))->briefItems($this->today) as $it) $out[] = $it;
        } catch (Throwable $e) { /* additive only (migration 1216) */ }
        // Machines due / nearly due for service, low stock, machines from label photos (priority 2–3).
        try {
            $care = dirname(__DIR__, 2) . '/Products/Services/ProductCareService.php';
            if (is_file($care)) {
                require_once $care;
                foreach ((new ProductCareService($this->db, $this->today))->briefItems() as $it) $out[] = $it;
            }
        } catch (Throwable $e) { /* additive only (migration 1225) */ }
        $out = OttoRules::sortItems($out);
        return ['head' => 'otto', 'headline' => $headline, 'items' => $out, 'count' => count($out)];
    }

    // ── Weather ─────────────────────────────────────────────────────────────

    private function weatherItems(): array
    {
        try {
            $s = $this->db->prepare("
                SELECT v.id, v.visit_number, v.scheduled_date, v.scheduled_time_start, v.weather_status,
                       v.weather_reason, v.weather_snapshot_raw, v.assigned_crew_id,
                       p.service_type, p.title AS plan_title, prop.address
                FROM job_visits v
                JOIN job_plans p ON p.id = v.plan_id
                JOIN properties prop ON prop.id = p.property_id
                WHERE v.scheduled_date BETWEEN ? AND ?
                  AND v.status = 'scheduled'
                  AND v.weather_status IN ('NOT_OK', 'BORDERLINE')
                  AND NOT EXISTS (SELECT 1 FROM weather_action_log w
                                  WHERE w.entity_type = 'visit' AND w.entity_id = v.id
                                    AND w.action_type = 'DISMISSED' AND w.action_date >= ?)
                ORDER BY v.scheduled_date, v.scheduled_time_start
                LIMIT " . self::LIMIT
            );
            $s->execute([$this->today, date('Y-m-d', strtotime($this->today . ' +1 day')), date('Y-m-d', strtotime($this->today . ' -3 days'))]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Otto weather: ' . $e->getMessage());
            return [];
        }
        $lessons = $this->lessons('weather');
        $out = [];
        foreach ($rows as $r) {
            $service = trim((string)($r['service_type'] ?: 'visit'));
            $rain = OttoRules::rainChance($r['weather_snapshot_raw']);
            $lean = OttoRules::weatherLean($lessons[strtolower($service)] ?? [], $rain);
            $day = OttoRules::dayWord((string)$r['scheduled_date'], $this->today);
            $when = $r['scheduled_time_start'] ? ' at ' . date('g:i a', strtotime((string)$r['scheduled_time_start'])) : '';
            $what = strtolower($service) . ' at ' . self::street((string)$r['address']);
            $why = $rain !== null ? "Rain {$rain}%" : trim((string)($r['weather_reason'] ?: 'The forecast'));
            $text = "{$why} {$day}{$when} — {$what}.";
            $text .= $lean === 'move' ? ' You usually move ' . strtolower($service) . ' in rain like this.'
                   : ($lean === 'keep' ? ' You usually keep ' . strtolower($service) . ' going in rain like this.' : '');
            $out[] = [
                'key' => 'otto:visit:' . (int)$r['id'], 'kind' => 'weather', 'subject_type' => 'visit', 'subject_id' => (int)$r['id'],
                'for_date' => (string)$r['scheduled_date'], 'user_id' => $r['assigned_crew_id'] ? (int)$r['assigned_crew_id'] : null,
                'priority' => $r['weather_status'] === 'NOT_OK' ? 1 : 2,
                'text' => $text, 'detail' => (string)($r['weather_reason'] ?? ''),
                'url' => '/crm/jobs/visit-detail.php?id=' . (int)$r['id'],
                'service_type' => $service, 'rain' => $rain, 'value' => $rain, 'since' => (string)$r['scheduled_date'],
                'propose' => ['lean' => $lean, 'status' => $r['weather_status']],
            ];
        }
        return $out;
    }

    // ── Clock-outs ──────────────────────────────────────────────────────────

    private function clockOutItems(): array
    {
        try {
            $s = $this->db->prepare("
                SELECT tce.id, tce.user_id, tce.clock_in, tce.clock_out, tce.status, u.full_name,
                       UNIX_TIMESTAMP(tce.clock_in) AS in_ts,
                       (SELECT UNIX_TIMESTAMP(MAX(jte.end_time)) FROM job_time_entries jte
                         WHERE jte.user_id = tce.user_id AND jte.auto_stopped = 0
                           AND jte.end_time > tce.clock_in AND jte.end_time <= DATE_ADD(tce.clock_in, INTERVAL ? HOUR)) AS timer_ts,
                       (SELECT UNIX_TIMESTAMP(MAX(clh.timestamp)) FROM crew_location_history clh
                         WHERE clh.crew_id = tce.user_id AND clh.visit_id IS NOT NULL
                           AND clh.timestamp > tce.clock_in AND clh.timestamp <= DATE_ADD(tce.clock_in, INTERVAL ? HOUR)) AS fix_ts
                FROM time_clock_entries tce
                JOIN users u ON u.id = tce.user_id
                WHERE tce.clock_in >= ?
                  AND (
                        (tce.status = 'active' AND tce.clock_out IS NULL AND tce.clock_in < ?)
                     OR (tce.status = 'completed' AND tce.notes LIKE '%auto-completed by cron%')
                  )
                ORDER BY tce.clock_in DESC
                LIMIT " . self::LIMIT
            );
            $h = OttoRules::MAX_SHIFT_HOURS;
            $s->execute([$h, $h, $this->since(), $this->today . ' 00:00:00']);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Otto clock-outs: ' . $e->getMessage());
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $who = self::firstName((string)$r['full_name']);
            $day = date('D M j', strtotime((string)$r['clock_in']));
            $open = $r['status'] === 'active';
            $g = OttoRules::suggestClockOut((int)$r['in_ts'], $r['timer_ts'] !== null ? (int)$r['timer_ts'] : null, $r['fix_ts'] !== null ? (int)$r['fix_ts'] : null);
            $text = $open ? "{$who} never clocked out on {$day}." : "{$who}'s shift on {$day} was closed by the system, not by {$who}.";
            if ($g) $text .= ' ' . ($g[1] === 'timer' ? "{$who}'s last job timer stopped at " : "{$who} was last on a job at ") . date('g:i a', $g[0]) . '.';
            else $text .= " I have nothing to go on. Check with {$who}.";
            $out[] = [
                'key' => 'otto:clock:' . (int)$r['id'], 'kind' => 'clock_out', 'subject_type' => 'clock', 'subject_id' => (int)$r['id'],
                'for_date' => date('Y-m-d', strtotime((string)$r['clock_in'])), 'user_id' => (int)$r['user_id'],
                'priority' => $open ? 1 : 2, 'text' => $text, 'who' => $who,
                'detail' => 'Clocked in ' . date('g:i a', (int)$r['in_ts']),
                'url' => '/crm/timeclock/timesheets.php?user_id=' . (int)$r['user_id'] . '&week=' . date('Y-m-d', strtotime('monday this week', strtotime((string)$r['clock_in']))),
                'value' => $g ? date('Y-m-d H:i', $g[0]) : null, 'since' => date('Y-m-d', strtotime((string)$r['clock_in'])),
                'propose' => ['clock_in' => date('Y-m-d\TH:i', (int)$r['in_ts']), 'clock_out' => $g ? date('Y-m-d\TH:i', $g[0]) : null, 'source' => $g[1] ?? null],
            ];
        }
        return $out;
    }

    // ── Job timers the night cron stopped ───────────────────────────────────

    private function timerItems(): array
    {
        try {
            $s = $this->db->prepare("
                SELECT jte.id, jte.user_id, jte.visit_id, jte.start_time, jte.duration_minutes, u.full_name,
                       prop.address, p.estimated_duration_minutes AS estimate, p.id AS plan_id,
                       (SELECT TIMESTAMPDIFF(MINUTE, MIN(clh.timestamp), MAX(clh.timestamp)) FROM crew_location_history clh
                         WHERE clh.crew_id = jte.user_id AND clh.visit_id = jte.visit_id) AS dwell
                FROM job_time_entries jte
                JOIN users u ON u.id = jte.user_id
                JOIN job_visits v ON v.id = jte.visit_id
                JOIN job_plans p ON p.id = v.plan_id
                JOIN properties prop ON prop.id = p.property_id
                WHERE jte.auto_stopped = 1 AND jte.status = 'completed' AND jte.start_time >= ?
                ORDER BY jte.start_time DESC
                LIMIT " . self::LIMIT
            );
            $s->execute([$this->since()]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Otto timers: ' . $e->getMessage());
            return [];
        }
        $durations = $this->lessons('duration');
        $out = [];
        foreach ($rows as $r) {
            $who = self::firstName((string)$r['full_name']);
            $g = OttoRules::guessMinutes($r['dwell'] !== null ? (int)$r['dwell'] : null, (array)($durations['plan:' . (int)$r['plan_id']]['minutes'] ?? []), $r['estimate'] !== null ? (int)$r['estimate'] : null);
            $text = "{$who}'s timer at " . self::street((string)$r['address']) . ' on ' . date('D M j', strtotime((string)$r['start_time']))
                  . ' ran ' . self::hours((int)$r['duration_minutes']) . ' until the night cron stopped it.';
            if ($g) $text .= ' ' . self::because($g) . ' ' . $g[0] . ' min.';
            $out[] = [
                'key' => 'otto:timer:' . (int)$r['id'], 'kind' => 'job_timer', 'subject_type' => 'timer', 'subject_id' => (int)$r['id'],
                'for_date' => date('Y-m-d', strtotime((string)$r['start_time'])), 'user_id' => (int)$r['user_id'],
                'priority' => 2, 'text' => $text, 'who' => $who, 'detail' => '',
                'url' => '/crm/jobs/visit-detail.php?id=' . (int)$r['visit_id'],
                'value' => $g[0] ?? null, 'since' => date('Y-m-d', strtotime((string)$r['start_time'])),
                'propose' => ['minutes' => $g[0] ?? null, 'source' => $g[1] ?? null, 'plan_id' => (int)$r['plan_id']],
            ];
        }
        return $out;
    }

    // ── Completed visits with no time ───────────────────────────────────────

    private function noTimeItems(): array
    {
        try {
            $s = $this->db->prepare("
                SELECT v.id, v.scheduled_date, v.assigned_crew_id, p.id AS plan_id, p.service_type,
                       p.estimated_duration_minutes AS estimate, prop.address,
                       (SELECT TIMESTAMPDIFF(MINUTE, MIN(clh.timestamp), MAX(clh.timestamp)) FROM crew_location_history clh
                         WHERE clh.visit_id = v.id) AS dwell
                FROM job_visits v
                JOIN job_plans p ON p.id = v.plan_id
                JOIN properties prop ON prop.id = p.property_id
                WHERE v.status = 'completed'
                  AND v.scheduled_date >= ?
                  AND (v.actual_duration_minutes IS NULL OR v.actual_duration_minutes = 0)
                  AND NOT EXISTS (SELECT 1 FROM job_time_entries jte WHERE jte.visit_id = v.id AND jte.status <> 'void')
                ORDER BY v.scheduled_date DESC
                LIMIT " . self::LIMIT
            );
            $s->execute([date('Y-m-d', strtotime($this->since()))]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Otto no-time: ' . $e->getMessage());
            return [];
        }
        $durations = $this->lessons('duration');
        $out = [];
        foreach ($rows as $r) {
            $g = OttoRules::guessMinutes($r['dwell'] !== null ? (int)$r['dwell'] : null, (array)($durations['plan:' . (int)$r['plan_id']]['minutes'] ?? []), $r['estimate'] !== null ? (int)$r['estimate'] : null);
            $what = strtolower(trim((string)($r['service_type'] ?: 'Visit')));
            $text = ucfirst($what) . ' at ' . self::street((string)$r['address']) . ' on ' . date('D M j', strtotime((string)$r['scheduled_date']))
                  . ' is done but has no time on it.';
            if ($g) $text .= ' ' . self::because($g) . ' ' . $g[0] . ' min.';
            $out[] = [
                'key' => 'otto:visit-time:' . (int)$r['id'], 'kind' => 'no_time', 'subject_type' => 'visit', 'subject_id' => (int)$r['id'],
                'for_date' => (string)$r['scheduled_date'], 'user_id' => $r['assigned_crew_id'] ? (int)$r['assigned_crew_id'] : null,
                'priority' => 3, 'text' => $text, 'detail' => '',
                'url' => '/crm/jobs/visit-detail.php?id=' . (int)$r['id'],
                'value' => $g[0] ?? null, 'since' => (string)$r['scheduled_date'],
                'propose' => ['minutes' => $g[0] ?? null, 'source' => $g[1] ?? null, 'plan_id' => (int)$r['plan_id']],
            ];
        }
        return $out;
    }

    // ── Quiet phones ────────────────────────────────────────────────────────

    private function silentItems(): array
    {
        try {
            require_once APP_ROOT . '/Modules/Team/Services/TrackingHealthService.php';
            $th = new TrackingHealthService($this->db);
            $rows = $th->clockedInTrackedUsers();
        } catch (Throwable $e) {
            error_log('Otto quiet phones: ' . $e->getMessage());
            return [];
        }
        $lessons = $this->lessons('silent');
        $now = time();
        $out = [];
        foreach ($rows as $r) {
            $uid = (int)$r['user_id'];
            $wait = OttoRules::silentWait($lessons['user:' . $uid] ?? null);
            $last = $r['last_fix_ts'] !== null ? (int)$r['last_fix_ts'] : null;
            if (!TrackingHealthService::isSilent((int)$r['clock_in_ts'], $last, $now, $wait)) continue;
            $health = null;
            try { $health = $th->latestHealth($uid); } catch (Throwable $e) { /* table not migrated */ }
            $who = self::firstName((string)$r['full_name']);
            $quietFor = (int)floor(($now - max((int)$r['clock_in_ts'], $last ?? 0)) / 60);
            $tablet = ($r['device_type'] ?? '') === 'truck';
            $text = ($tablet ? "The {$who} tablet" : "{$who}'s phone") . ' has sent no location for ' . self::hours($quietFor) . ' while on the clock.';
            $out[] = [
                'key' => 'otto:silent:' . $uid, 'kind' => 'silent', 'subject_type' => 'user', 'subject_id' => $uid,
                'for_date' => $this->today, 'user_id' => $uid, 'priority' => 1, 'text' => $text, 'who' => $who,
                'detail' => TrackingHealthService::likelyCause($health, (int)$r['clock_in_ts']),
                'url' => '/crm/timeclock/crew-map.php', 'value' => $quietFor, 'since' => $this->today,
                'propose' => ['minutes' => $quietFor],
            ];
        }
        return $out;
    }

    // ── Dispatcher: bylaw hours, West End, Might-E range, maintenance, packs ─

    /** From the rule tables and equipment register; nothing until migrations 1153–1157 have run. */
    private function dispatchItems(): array
    {
        $out = [];
        try {
            require_once __DIR__ . '/MunicipalRuleService.php';
            [$bylaw, $westEnd] = (new MunicipalRuleService($this->db, $this->today))->items();
            $out = array_merge($out, $bylaw, $westEnd);
        } catch (Throwable $e) {
            error_log('Otto rules: ' . $e->getMessage());
        }
        try {
            require_once __DIR__ . '/EquipmentService.php';
            [$maint, $packs, $trucks] = (new EquipmentService($this->db, $this->today))->suggestionItems();
            $out = array_merge($out, $trucks, $maint, $packs);
        } catch (Throwable $e) {
            error_log('Otto equipment: ' . $e->getMessage());
        }
        try {
            require_once __DIR__ . '/TrainingService.php';
            $out = array_merge($out, (new TrainingService($this->db, $this->today))->items());
        } catch (Throwable $e) {
            error_log('Otto training: ' . $e->getMessage());
        }
        return $out;
    }

    // ── Work with nothing scheduled; real visit lengths (migrations 1265 / 1266) ─

    /** $fill: compute up to a few uncached days (the API call), never on a page render. */
    private function unscheduledItems(bool $fill): array
    {
        try {
            require_once __DIR__ . '/UnscheduledWorkService.php';
            return (new UnscheduledWorkService($this->db, $this->today))->items($fill);
        } catch (Throwable $e) {
            error_log('Otto unscheduled: ' . $e->getMessage());
            return [];
        }
    }

    private function durationItems(): array
    {
        try {
            require_once __DIR__ . '/VisitDurationService.php';
            return (new VisitDurationService($this->db, $this->today))->items();
        } catch (Throwable $e) {
            error_log('Otto durations: ' . $e->getMessage());
            return [];
        }
    }

    /** Share of visits completed in the last 60 days that have a job timer. */
    public function coverage(): ?array
    {
        try {
            require_once __DIR__ . '/VisitDurationService.php';
            return (new VisitDurationService($this->db, $this->today))->coverage();
        } catch (Throwable $e) {
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Storage
    // ─────────────────────────────────────────────────────────────────────────

    /** Lessons for one scope, keyed by scope_key, values decoded. */
    public function lessons(string $scope): array
    {
        try {
            $s = $this->db->prepare("SELECT scope_key, value_json FROM otto_lessons WHERE scope = ?");
            $s->execute([$scope]);
            $out = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(string)$r['scope_key']] = json_decode((string)$r['value_json'], true) ?: [];
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Suggestions already recorded for the window Otto looks at. */
    private function known(): array
    {
        try {
            $s = $this->db->prepare("SELECT id, kind, subject_type, subject_id, for_date, status FROM otto_suggestions WHERE for_date >= ? OR status = 'open'");
            $s->execute([date('Y-m-d', strtotime($this->since()))]);
            $out = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[self::rowKey($r['kind'], $r['subject_type'], (int)$r['subject_id'], (string)$r['for_date'])] = $r;
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    private function record(array $it): ?array
    {
        try {
            $this->db->prepare("
                INSERT IGNORE INTO otto_suggestions (kind, subject_type, subject_id, for_date, user_id, service_type, rain_pct, suggestion_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $it['kind'], $it['subject_type'], (int)$it['subject_id'], $it['for_date'], $it['user_id'] ?? null,
                isset($it['service_type']) ? mb_substr((string)$it['service_type'], 0, 80) : null,
                $it['rain'] ?? null, json_encode($it['propose'] ?? []),
            ]);
            $s = $this->db->prepare("SELECT id, status FROM otto_suggestions WHERE kind = ? AND subject_type = ? AND subject_id = ? AND for_date = ?");
            $s->execute([$it['kind'], $it['subject_type'], (int)$it['subject_id'], $it['for_date']]);
            return $s->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            error_log('Otto record: ' . $e->getMessage());
            return null;
        }
    }

    /** Open suggestions whose problem went away (fixed elsewhere, visit moved) — not Otto's call to count. */
    private function expire(array $known, array $open): void
    {
        $gone = [];
        foreach ($known as $k => $r) {
            if ($r['status'] === 'open' && !isset($open[$k])) $gone[] = (int)$r['id'];
        }
        if (!$gone) return;
        try {
            $in = implode(',', array_fill(0, count($gone), '?'));
            $this->db->prepare("UPDATE otto_suggestions SET status = 'expired', decided_at = NOW() WHERE status = 'open' AND id IN ({$in})")->execute($gone);
        } catch (Throwable $e) {
            error_log('Otto expire: ' . $e->getMessage());
        }
    }

    /** % of Otto's last 30 judged calls the owner kept. */
    public function rightFirstTime(): ?int
    {
        try {
            $rows = $this->db->query("
                SELECT kind, status, outcome_json FROM otto_suggestions
                WHERE status IN ('accepted', 'edited') AND kind <> 'silent'
                ORDER BY decided_at DESC, id DESC LIMIT 30
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return null;
        }
        $st = [];
        foreach ($rows as $r) {
            if ($r['kind'] === 'weather') {
                $o = json_decode((string)$r['outcome_json'], true) ?: [];
                if (!isset($o['followed']) || $o['followed'] === null) continue;
                $st[] = $o['followed'] ? 'accepted' : 'edited';
                continue;
            }
            $st[] = $r['status'];
        }
        return OttoRules::rightFirstTime($st);
    }

    /** One line from the winter outlook, Nov–Mar only. */
    private function outlookLine(): ?string
    {
        try {
            require_once APP_ROOT . '/Modules/Jobs/Services/SeasonalOutlookService.php';
            $o = (new SeasonalOutlookService($this->db))->activeOutlook($this->today);
            if (!$o) return null;
            foreach (['headline', 'summary', 'label'] as $k) {
                if (!empty($o[$k]) && is_string($o[$k])) return $o[$k];
            }
        } catch (Throwable $e) { /* outlook is a bonus */ }
        return null;
    }

    private function since(): string
    {
        return date('Y-m-d 00:00:00', strtotime($this->today . ' -' . OttoRules::GAP_DAYS . ' days'));
    }

    // ── Small pure helpers ──────────────────────────────────────────────────

    public static function rowKey(string $kind, string $type, int $id, string $date): string
    {
        return "{$kind}|{$type}|{$id}|{$date}";
    }

    public static function firstName(string $full): string
    {
        $full = trim($full);
        return $full === '' ? 'Someone' : (string)strtok($full, ' ');
    }

    /** "1234 Main St, Burnaby BC" → "1234 Main St". */
    public static function street(string $address): string
    {
        $a = trim(explode(',', $address)[0] ?? '');
        return $a !== '' ? $a : 'the property';
    }

    /** 95 → "1 h 35 min", 40 → "40 min". */
    public static function hours(int $minutes): string
    {
        $minutes = max(0, $minutes);
        if ($minutes < 60) return $minutes . ' min';
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $h . ' h' . ($m ? ' ' . $m . ' min' : '');
    }

    private static function because(array $guess): string
    {
        return [
            'gps' => 'GPS has the crew on site for',
            'learned' => 'You usually set this one at',
            'estimate' => 'The plan estimate is',
        ][$guess[1]] ?? 'About';
    }
}
