<?php
/**
 * OttoScheduleService — Otto on the schedule surfaces: the strip above the desktop Schedule (day and
 * week), the Territory Map and the dashboard's 7-Day Operations.
 *
 * For one day it returns Otto's items that belong to that day, most urgent first:
 *   from his desk (OpsDeskService::current — the same suggestions, the same ids, the same buttons):
 *     unscheduled / extra_work   work found that day with nothing (or less) scheduled
 *     visit_date                 a visit done that day but booked another (or booked that day, done another)
 *     no_time                    completed that day with no time on it
 *     weather                    that day's visits the weather guard flagged
 *     duration                   a plan length that looks wrong, for plans with a visit that day
 *     no_pin / pin_off / default_border / border_overlap   for properties on that day
 *   worked out here (links only, nothing to decide):
 *     overbooked                 a crew's plan lengths for the day over the day's capacity
 *     empty_stop                 a calendar stop with no visit left on it
 *     no_border                  a pinned property on the day with no arrival border at all
 *
 * Cheap by construction: the desk reads the unscheduled-work evidence CACHE only (fill = false — never
 * the 14-day GPS scan), and each day's answer is kept 5 minutes in otto_day_strip (migration 1286).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/OpsDeskService.php';
require_once __DIR__ . '/OttoRules.php';
require_once __DIR__ . '/PropertyBorderRules.php';

class OttoScheduleService
{
    public const TTL_SECONDS = 300;
    public const CAPACITY_MINUTES = 540;   // StopRescheduleService::DAY_CAPACITY_MINUTES — the drag-and-drop warning
    public const DAY_KINDS = ['unscheduled', 'extra_work', 'visit_date', 'no_time', 'weather'];
    public const PROPERTY_KINDS = ['no_pin', 'pin_off', 'default_border', 'border_overlap'];
    public const MAX_DAYS = 14;

    protected PDO $db;
    private string $today;
    private ?array $deskMemo = null;

    public function __construct(PDO $db, ?string $today = null)
    {
        $this->db = $db;
        $this->today = $today ?? date('Y-m-d');
    }

    /**
     * @return array{date: string, count: int, items: array, crews: array, cached: bool}
     */
    public function day(string $date, bool $fresh = false): array
    {
        if (!$fresh && ($c = $this->cached($date)) !== null) return $c + ['cached' => true];
        $visits = $this->visits($date);
        $stops = $this->stops($date);
        $props = [];
        $plans = [];
        foreach ($visits as $v) {
            $props[(int)$v['property_id']] = true;
            $plans[(int)$v['plan_id']] = true;
        }
        foreach ($stops as $s) $props[(int)$s['property_id']] = true;

        $items = self::forDay($this->desk(), $date, array_keys($props), array_keys($plans));
        [$over, $crews] = self::overbooked($visits, self::capacity());
        foreach ($over as $o) {
            $items[] = [
                'id' => null, 'key' => 'otto:overbooked:' . $o['crew_id'] . ':' . $date, 'kind' => 'overbooked', 'priority' => 2,
                'text' => $o['name'] . '\'s day is ' . OttoRules::plural(count($o['stops']), 'stop') . ' and ' . self::hours($o['minutes'])
                    . ' of plan lengths — ' . self::hours($o['capacity']) . ' fits.',
                'detail' => 'Plan lengths, not drive time. Move a stop to a lighter day, or check the lengths (Visit lengths).',
                'url' => '/crm/jobs/schedule.php?view=day&date=' . $date, 'propose' => [],
            ];
        }
        foreach ($this->emptyStops($date) as $s) {
            $items[] = [
                'id' => null, 'key' => 'otto:empty_stop:' . $s['id'], 'kind' => 'empty_stop', 'priority' => 3,
                'text' => 'Empty stop at ' . PropertyBorderRules::street((string)$s['address']) . ' — nothing is on it any more.',
                'detail' => 'Usually left behind when its visit moved. Remove it, or put a visit back on it.',
                'url' => '/crm/jobs/schedule.php?view=day&date=' . $date, 'propose' => ['stop_id' => (int)$s['id']],
            ];
        }
        $flagged = [];
        foreach ($items as $it) if (in_array($it['kind'], self::PROPERTY_KINDS, true)) $flagged[(int)($it['propose']['property_id'] ?? 0)] = true;
        foreach ($this->unbordered(array_keys($props)) as $p) {
            if (isset($flagged[(int)$p['id']])) continue;
            $items[] = [
                'id' => null, 'key' => 'otto:no_border:' . $p['id'], 'kind' => 'no_border', 'priority' => 4,
                'text' => PropertyBorderRules::street((string)$p['address']) . ' has no border — arrival only works on the 150 m radius there.',
                'detail' => 'Draw it, or let Otto make a default one (Pins & borders).',
                'url' => '/crm/jobs/zone-editor.php?property_id=' . (int)$p['id'], 'propose' => ['property_id' => (int)$p['id']],
            ];
        }
        $items = OttoRules::sortItems($items);
        $out = ['date' => $date, 'count' => count($items), 'items' => $items, 'crews' => $crews, 'stops' => count($stops), 'visits' => count($visits)];
        $this->store($date, $out);
        return $out + ['cached' => false];
    }

    /** Counts for a run of days (week view / 7-day ops) plus the full list for $focus. */
    public function range(string $from, string $to, ?string $focus = null, bool $fresh = false): array
    {
        $days = [];
        $d = $from;
        for ($i = 0; $i < self::MAX_DAYS && $d <= $to; $i++) {
            $r = $this->day($d, $fresh);
            $days[] = ['date' => $d, 'count' => $r['count'], 'top' => $r['items'][0]['text'] ?? null,
                       'p1' => count(array_filter($r['items'], fn($x) => (int)$x['priority'] <= 2))];
            $d = date('Y-m-d', strtotime($d . ' +1 day'));
        }
        $focus ??= ($this->today >= $from && $this->today <= $to) ? $this->today : $from;
        return ['from' => $from, 'to' => $to, 'days' => $days, 'focus' => $this->day($focus, $fresh)];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Desk items that belong to $date. Items keep their suggestion id ('sid' → 'id').
     * @param int[] $propertyIds properties with a visit or stop that day
     * @param int[] $planIds     plans with a visit that day
     */
    public static function forDay(array $desk, string $date, array $propertyIds, array $planIds): array
    {
        $props = array_flip(array_map('intval', $propertyIds));
        $plans = array_flip(array_map('intval', $planIds));
        $out = [];
        foreach ($desk as $it) {
            $k = (string)$it['kind'];
            $p = (array)($it['propose'] ?? []);
            $hit = false;
            if (in_array($k, self::DAY_KINDS, true)) {
                $hit = (string)$it['for_date'] === $date || ($k === 'visit_date' && (string)($p['from'] ?? '') === $date);
            } elseif ($k === 'duration') {
                $hit = isset($plans[(int)$it['subject_id']]);
            } elseif (in_array($k, self::PROPERTY_KINDS, true)) {
                $hit = isset($props[(int)$it['subject_id']]) || ($k === 'border_overlap' && isset($props[(int)($p['other_id'] ?? 0)]));
            }
            if (!$hit) continue;
            $out[] = [
                'id' => $it['sid'] ?? null, 'key' => $it['key'], 'kind' => $k, 'priority' => (int)$it['priority'],
                'text' => $it['text'], 'detail' => $it['detail'] ?? '', 'url' => $it['url'] ?? '', 'propose' => $p,
            ];
        }
        return $out;
    }

    /**
     * Per lead crew: plan lengths of the day's (non-cancelled, non-skipped) visits against $capacity.
     * @param array $visits [{crew_id, crew_name, stop_id, estimated_duration_minutes}]
     * @return array{0: array, 1: array} [over capacity, every crew {crew_id, name, minutes, stops, capacity}]
     */
    public static function overbooked(array $visits, int $capacity): array
    {
        $crews = [];
        foreach ($visits as $v) {
            if (empty($v['crew_id'])) continue;
            $cid = (int)$v['crew_id'];
            $crews[$cid] ??= ['crew_id' => $cid, 'name' => OpsDeskService::firstName((string)($v['crew_name'] ?? '')), 'minutes' => 0, 'stops' => [], 'capacity' => $capacity];
            $crews[$cid]['minutes'] += max(0, (int)($v['estimated_duration_minutes'] ?? 0));
            if (!empty($v['stop_id'])) $crews[$cid]['stops'][(int)$v['stop_id']] = true;
        }
        $all = [];
        $over = [];
        foreach ($crews as $c) {
            $c['stops'] = array_keys($c['stops']);
            $all[] = ['crew_id' => $c['crew_id'], 'name' => $c['name'], 'minutes' => $c['minutes'], 'stops' => count($c['stops']), 'capacity' => $capacity];
            if ($c['minutes'] > $capacity) $over[] = $c;
        }
        return [$over, $all];
    }

    public static function hours(int $minutes): string
    {
        $h = round($minutes / 60, 1);
        return ($h == (int)$h ? (int)$h : $h) . ' h';
    }

    public static function capacity(): int
    {
        $f = dirname(__DIR__, 2) . '/Jobs/Services/StopRescheduleService.php';
        if (!class_exists('StopRescheduleService', false) && is_file($f)) require_once $f;
        return class_exists('StopRescheduleService', false) ? (int)StopRescheduleService::DAY_CAPACITY_MINUTES : self::CAPACITY_MINUTES;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Facts
    // ─────────────────────────────────────────────────────────────────────────

    /** Read once per request — a week's strip shares one desk. */
    private function desk(): array
    {
        return $this->deskMemo ??= $this->deskItems();
    }

    /** Otto's desk, recorded (so every item has an id to act on) but never filling the GPS cache. */
    protected function deskItems(): array
    {
        try {
            $desk = new OpsDeskService($this->db, $this->today);
            return $desk->ready() ? $desk->neverFill()->current(true) : [];
        } catch (Throwable $e) {
            error_log('Otto strip desk: ' . $e->getMessage());
            return [];
        }
    }

    private function visits(string $date): array
    {
        try {
            $s = $this->db->prepare("
                SELECT v.id, v.plan_id, v.status, v.stop_id, jp.property_id, jp.estimated_duration_minutes,
                       COALESCE(cs.crew_id, v.assigned_crew_id) AS crew_id, u.full_name AS crew_name
                FROM job_visits v
                JOIN job_plans jp ON jp.id = v.plan_id
                LEFT JOIN calendar_stops cs ON cs.id = v.stop_id
                LEFT JOIN users u ON u.id = COALESCE(cs.crew_id, v.assigned_crew_id)
                WHERE v.scheduled_date = ? AND v.status NOT IN ('cancelled', 'skipped')
            ");
            $s->execute([$date]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Otto strip visits: ' . $e->getMessage());
            return [];
        }
    }

    private function stops(string $date): array
    {
        try {
            $s = $this->db->prepare("SELECT id, property_id FROM calendar_stops WHERE stop_date = ?");
            $s->execute([$date]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** UnscheduledWorkService::emptyStops() with the address. */
    private function emptyStops(string $date): array
    {
        try {
            require_once __DIR__ . '/UnscheduledWorkService.php';
            $by = (new UnscheduledWorkService($this->db, $this->today))->emptyStops($date);
        } catch (Throwable $e) {
            return [];
        }
        if (!$by) return [];
        $in = implode(',', array_fill(0, count($by), '?'));
        $addr = [];
        try {
            $s = $this->db->prepare("SELECT id, address FROM properties WHERE id IN ({$in})");
            $s->execute(array_map('intval', array_keys($by)));
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $addr[(int)$r['id']] = (string)$r['address'];
        } catch (Throwable $e) { /* address is a nicety */ }
        $out = [];
        foreach ($by as $pid => $list) foreach ($list as $st) $out[] = ['id' => $st['id'], 'property_id' => (int)$pid, 'address' => $addr[(int)$pid] ?? ''];
        return $out;
    }

    /** Pinned properties among $ids with no arrival border at all. */
    private function unbordered(array $ids): array
    {
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        try {
            $s = $this->db->prepare("
                SELECT p.id, p.address, p.latitude, p.longitude FROM properties p
                WHERE p.id IN ({$in})
                  AND NOT EXISTS (SELECT 1 FROM job_geofences g WHERE g.property_id = p.id AND g.zone_type = 'arrival_border')
            ");
            $s->execute(array_map('intval', $ids));
            return array_values(array_filter($s->fetchAll(PDO::FETCH_ASSOC), fn($p) => PropertyBorderRules::pinOf($p) !== null));
        } catch (Throwable $e) {
            return [];
        }
    }

    // ── Cache (migration 1286) ──────────────────────────────────────────────

    private function cached(string $date): ?array
    {
        try {
            $s = $this->db->prepare("SELECT payload_json, computed_at FROM otto_day_strip WHERE day = ?");
            $s->execute([$date]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if (!$r || strtotime((string)$r['computed_at']) < time() - self::TTL_SECONDS) return null;
            $p = json_decode((string)$r['payload_json'], true);
            return is_array($p) ? $p : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function store(string $date, array $out): void
    {
        try {
            $this->db->prepare("REPLACE INTO otto_day_strip (day, payload_json, computed_at) VALUES (?, ?, ?)")
                     ->execute([$date, json_encode($out), date('Y-m-d H:i:s')]);
        } catch (Throwable $e) { /* migration 1286 not run — computed each time */ }
    }

    /** Called after an owner decision so the strip shows the change at once. */
    public function forget(?string $date = null): void
    {
        try {
            if ($date === null) $this->db->exec("DELETE FROM otto_day_strip");
            else $this->db->prepare("DELETE FROM otto_day_strip WHERE day = ?")->execute([$date]);
        } catch (Throwable $e) { /* not migrated */ }
    }
}
