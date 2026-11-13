<?php
declare(strict_types=1);

/**
 * Otto's schedule job for the job strip (Tim, 2026-11-10: "otto needs his flow line in place"). The decisions he carries
 * on the Schedule page, one at a time, for the days in view:
 *   unscheduled / extra_work  the crew was somewhere with nothing (or less) booked: add the visit, or "not work"
 *   visit_date                a visit done one day but booked another: move the booking, or leave it
 *   weather                   a visit the weather guard flagged: move it to the guard's next good slot, or keep it
 *   overbooked                a crew's plan lengths over the day's capacity: opens that day (nothing is moved from here)
 *
 * A new face on existing logic only. The items come from OttoScheduleService::day() (the same desk, ids and 5-minute cache as
 * the things strip above the calendar); every button posts what Otto's card posts to /crm/api/otto.php?mode=decide
 * (OttoActionService::decide, the one write path). Nothing here writes anything, and no click happens for the owner.
 * Since 2026-11-12 the rest of the old things card rides here too (the card is retired in the Ittai shell): no_time, duration,
 * pins and borders, empty stops, borders missing, and the Route Reconciliation conflicts (truck GPS vs clock-in). Link-only
 * kinds open where they are fixed; nothing moves without a click.
 *
 * Off unless ops_settings it_otto_job = '1'. Any failure means no job. No namespace / no autoloader in production.
 */
class OttoJobAdapter
{
    public const SETTING = 'it_otto_job';
    public const MAX_JOBS = 60;
    /** Accepted decisions of these kinds before his confidence reads mastered (the knowledge triangle: gold after five). */
    public const MASTERED_AT = 5;
    public const API = '/crm/api/otto.php?mode=decide';
    /** The decisions his confidence record counts (the housekeeping kinds below do not move it). */
    public const KINDS = ['unscheduled', 'extra_work', 'visit_date', 'weather'];
    /** Housekeeping the old things card carried: time and plan length, pins and borders. All have a suggestion id. */
    public const HOUSEKEEPING = ['no_time', 'duration', 'pin_off', 'no_pin', 'default_border', 'border_overlap'];
    /** Desk items with no suggestion id: they link to where they are fixed. */
    public const LINK_ONLY = ['overbooked', 'empty_stop', 'no_border'];
    /** Most urgent first. */
    private const ORDER = ['weather' => 0, 'overbooked' => 1, 'route_conflict' => 2, 'visit_date' => 3, 'unscheduled' => 4, 'extra_work' => 4,
        'no_time' => 5, 'duration' => 5, 'empty_stop' => 6, 'pin_off' => 7, 'no_pin' => 7, 'default_border' => 8, 'border_overlap' => 8, 'no_border' => 9];

    public static function enabled(PDO $db): bool
    {
        try {
            $s = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([self::SETTING]);
            return (string)$s->fetchColumn() === '1';
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @return list<array> the days' decisions as jobs. [] when off or on any failure.
     */
    public static function jobs(PDO $db, string $from, string $to, ?string $today = null): array
    {
        try {
            if (!self::enabled($db)) return [];
            require_once __DIR__ . '/OttoScheduleService.php';
            $svc = new OttoScheduleService($db, $today);
            $items = [];
            $d = $from;
            for ($i = 0; $i < OttoScheduleService::MAX_DAYS && $d <= $to; $i++) {
                foreach ($svc->day($d)['items'] as $it) {
                    if (in_array($it['kind'], array_merge(self::KINDS, self::HOUSEKEEPING, self::LINK_ONLY), true)) $items[] = $it + ['day' => $d];
                }
                $d = date('Y-m-d', strtotime($d . ' +1 day'));
            }
            $conflicts = self::routeConflicts($db, $from, $to);
            if (!$items && !$conflicts) return [];
            $subjects = self::subjects($db, $items);
            // The day's answer is cached 5 minutes: a suggestion decided since (here or on the card) must not come back.
            $items = array_values(array_filter($items, static function ($it) use ($subjects) {
                $sid = (int)($it['id'] ?? 0);
                return !$sid || (($subjects[$sid]['status'] ?? '') === 'open');
            }));
            if (!$items && !$conflicts) return [];
            $plans = [];
            $slots = [];
            require_once __DIR__ . '/UnscheduledWorkService.php';
            require_once __DIR__ . '/OttoActionService.php';
            foreach ($items as $it) {
                $sid = (int)($it['id'] ?? 0);
                if (!$sid) continue;
                $sub = $subjects[$sid] ?? ['subject_id' => 0];
                if (in_array($it['kind'], ['unscheduled', 'extra_work'], true)) {
                    $plans[$sid] = (new UnscheduledWorkService($db))->plans((int)$sub['subject_id']);
                } elseif ($it['kind'] === 'weather') {
                    $slots[$sid] = (new OttoActionService($db))->findSlot((int)$sub['subject_id']);
                }
            }
            return self::queue(array_merge($items, $conflicts), $plans, $slots, self::record($db), $today ?? date('Y-m-d'), [$from, $to]);
        } catch (Throwable $e) {
            error_log('OttoJobAdapter::jobs: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Route Reconciliation conflicts for the days in view, shaped like desk items (kind route_conflict, no suggestion id).
     * Cached 5 minutes per range (the check reads a week of truck pings). [] on any failure.
     * @return list<array>
     */
    public static function routeConflicts(PDO $db, string $from, string $to): array
    {
        try {
            // Keyed by the business's database: one code tree serves every tenant.
            $dbName = (string)$db->query('SELECT DATABASE()')->fetchColumn();
            $file = sys_get_temp_dir() . '/mw-rr-' . md5($dbName . '|' . $from . '|' . $to) . '.json';
            $r = null;
            if (is_file($file) && filemtime($file) > time() - 300) $r = json_decode((string)@file_get_contents($file), true);
            if (!is_array($r)) {
                require_once __DIR__ . '/RouteReconciliationService.php';
                $r = RouteReconciliationService::conflicts($db, $from, $to);
                @file_put_contents($file, json_encode($r));
            }
            $conflicts = (array)($r['conflicts'] ?? []);
            // Resolved since the cache was written (conflict-resolution.php): drop them now, not in five minutes.
            try {
                $res = $db->prepare("SELECT visit_id, visit_date FROM conflict_resolutions WHERE visit_date BETWEEN ? AND ?");
                $res->execute([$from, $to]);
                $done = [];
                foreach ($res->fetchAll(PDO::FETCH_ASSOC) as $x) $done[$x['visit_id'] . '_' . $x['visit_date']] = true;
                if ($done) $conflicts = array_values(array_filter($conflicts, fn($c) => !isset($done[($c['visit_id'] ?? '') . '_' . ($c['visit_date'] ?? '')])));
            } catch (Throwable $e) { /* table not there: nothing resolved */ }
            return self::conflictItems($conflicts);
        } catch (Throwable $e) {
            error_log('OttoJobAdapter::routeConflicts: ' . $e->getMessage());
            return [];
        }
    }

    /** Pure: RouteReconciliationService conflicts into desk-shaped items. */
    public static function conflictItems(array $conflicts): array
    {
        $out = [];
        foreach ($conflicts as $c) {
            $vid = (int)($c['visit_id'] ?? 0);
            $day = (string)($c['visit_date'] ?? '');
            if (!$vid || $day === '') continue;
            $addr = (string)($c['property_address'] ?? 'a property');
            $gps = ($c['type'] ?? '') === 'truck_at_site_no_clockin';
            $url = '/crm/jobs/conflict-resolution.php?visit_id=' . $vid . '&date=' . $day . (!empty($c['truck_id']) ? '&truck_id=' . (int)$c['truck_id'] : '');
            $out[] = [
                'id' => null, 'key' => 'otto:rr:' . $vid . ':' . $day, 'kind' => 'route_conflict', 'priority' => $gps ? 2 : 3,
                'text' => $gps
                    ? (string)($c['truck_name'] ?? 'The truck') . ' was at ' . $addr . ' ' . (string)($c['first_seen'] ?? '') . '-' . (string)($c['last_seen'] ?? '') . ' (' . (int)($c['dwell_minutes'] ?? 0) . ' min), but the visit was never clocked in.'
                    : 'The visit at ' . $addr . ' is done, but no truck GPS was seen there.',
                'detail' => trim((string)($c['crew_name'] ?? '') !== '' ? 'Crew: ' . $c['crew_name'] . '. ' : '') . 'Truck GPS and the clock-in disagree. Open it to sort out which is right.',
                'url' => $url, 'propose' => [], 'day' => $day,
            ];
        }
        return $out;
    }

    /**
     * Pure shaping, so it can be tested without a database.
     * @param array $items  OttoScheduleService day items, each with 'day' (Y-m-d)
     * @param array $plans  active plans by suggestion id (UnscheduledWorkService::plans)
     * @param array $slots  the weather guard's next good slot by suggestion id ({date, time_start} or null)
     * @param array $record [decided from the flow line or the card, of those followed]
     * @param ?string $today Y-m-d: a full day or a weather move only means something from today on; null = no cut-off
     * @param ?array $range [from, to] the days in view: the strip only carries decisions for those days; null = no limit
     */
    public static function queue(array $items, array $plans, array $slots, array $record, ?string $today = null, ?array $range = null): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $it) {
            $kind = (string)($it['kind'] ?? '');
            $key = (string)($it['key'] ?? '');
            if ($key === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $sid = (int)($it['id'] ?? 0);
            $day = (string)($it['day'] ?? '');
            if ($range && $day !== '' && ($day < $range[0] || $day > $range[1])) continue;   // not one of the days on screen
            if ($today !== null && in_array($kind, ['overbooked', 'weather'], true) && (string)($it['day'] ?? '') < $today) continue;   // nothing to fix on a day that is gone
            $job = null;
            if ($kind === 'overbooked') {
                $job = self::overbooked($it);
            } elseif ($sid && $kind === 'visit_date') {
                $job = self::visitDate($it, $sid, $range);
            } elseif ($sid && $kind === 'weather') {
                $job = self::weather($it, $sid, $slots[$sid] ?? null);
            } elseif ($sid && in_array($kind, ['unscheduled', 'extra_work'], true)) {
                $job = self::unscheduled($it, $sid, (array)($plans[$sid] ?? []));
            } elseif (in_array($kind, ['route_conflict', 'empty_stop', 'no_border'], true)) {
                $job = self::linkOnly($it, $kind);
            } elseif ($sid && in_array($kind, self::HOUSEKEEPING, true)) {
                $job = self::housekeeping($it, $sid, $kind);
            }
            if ($job) $out[] = $job + ['sure' => self::sure($record), 'confidence' => self::confidenceLine($record)];
        }
        usort($out, fn($a, $b) => (self::ORDER[$a['kind']] ?? 9) <=> (self::ORDER[$b['kind']] ?? 9));
        return array_slice($out, 0, self::MAX_JOBS);
    }

    private static function base(array $it, string $kind, string $title): array
    {
        $day = (string)($it['day'] ?? '');
        return [
            'head' => 'otto', 'kind' => $kind, 'key' => 'otto:' . (string)$it['key'], 'day' => $day,
            'title' => $title,
            'why' => (string)($it['detail'] ?? ''),
            'post' => ['platforms' => [], 'when' => $day !== '' ? date('D M j', strtotime($day)) : '', 'caption' => (string)($it['text'] ?? '')],
            'detail' => '', 'fields' => [], 'right' => [], 'later' => true,
        ];
    }

    private static function decide(int $sid, array $body): array
    {
        return ['url' => self::API, 'body' => ['suggestion_id' => $sid] + $body];
    }

    private static function visitDate(array $it, int $sid, ?array $range = null): array
    {
        $p = (array)($it['propose'] ?? []);
        $to = (string)($p['to_label'] ?? $p['to'] ?? 'the day it was done');
        if (!empty($p['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$p['to'])
            && (!$range || ((string)$p['to'] >= $range[0] && (string)$p['to'] <= $range[1]))) $it['day'] = (string)$p['to'];   // the day it moves to, whichever day was in view first
        return self::base($it, 'visit_date', 'Move this booking to the day it was done?') + [
            'primary' => ['label' => 'Move it', 'sub' => 'to ' . $to, 'request' => self::decide($sid, ['choice' => 'move'])],
            'secondary' => ['label' => 'Leave it', 'sub' => 'stays booked', 'request' => self::decide($sid, ['choice' => 'dismiss'])],
        ];
    }

    private static function weather(array $it, int $sid, ?array $slot): array
    {
        $job = self::base($it, 'weather', 'Move this visit out of the weather?');
        $keep = ['label' => 'Keep it', 'sub' => 'stays on the day', 'request' => self::decide($sid, ['choice' => 'keep'])];
        if ($slot && !empty($slot['date'])) {
            $time = substr((string)($slot['time_start'] ?? '08:00'), 0, 5);
            $job['primary'] = ['label' => 'Move it', 'sub' => 'to ' . date('D M j', strtotime((string)$slot['date'])) . ' at ' . date('g:i a', strtotime($time)),
                'request' => self::decide($sid, ['choice' => 'move', 'date' => (string)$slot['date'], 'time' => $time])];
        } else {
            $job['primary'] = ['label' => 'Open the day', 'sub' => 'no good slot found', 'href' => self::dayUrl((string)$it['day'])];
        }
        return $job + ['secondary' => $keep];
    }

    private static function unscheduled(array $it, int $sid, array $plans): array
    {
        $p = (array)($it['propose'] ?? []);
        $job = self::base($it, (string)$it['kind'], 'Add the visit the crew did?');
        $notWork = ['label' => 'Not work', 'sub' => 'Otto stops asking', 'request' => self::decide($sid, ['choice' => 'not_work'])];
        $start = (string)($p['start'] ?? '');
        $end = (string)($p['end'] ?? '');
        // One active plan and Otto's own times: the same add_visit his card sends. Otherwise the card's form (plan, times) decides.
        if (count($plans) === 1 && $start !== '' && $end !== '') {
            $job['primary'] = ['label' => 'Add the visit', 'sub' => $start . '–' . $end . ' on ' . (string)($plans[0]['number'] ?? 'the plan'),
                'request' => self::decide($sid, ['choice' => 'add_visit', 'plan_id' => (int)$plans[0]['id'], 'start' => $start, 'end' => $end])];
        } else {
            $job['primary'] = ['label' => 'Open the day', 'sub' => 'pick the plan and times', 'href' => self::dayUrl((string)$it['day'])];
        }
        return $job + ['secondary' => $notWork];
    }

    private static function overbooked(array $it): array
    {
        return self::base($it, 'overbooked', 'Is this day too full?') + [
            'primary' => ['label' => 'Open the day', 'sub' => 'moves nothing', 'href' => self::dayUrl((string)$it['day'])],
            'secondary' => ['label' => 'Not yet', 'sub' => 'ask again tomorrow', 'skip' => true],
        ];
    }

    /** Empty stop, no border, route conflict: opens where it is fixed; "Not yet" asks again tomorrow. */
    private static function linkOnly(array $it, string $kind): array
    {
        $titles = ['route_conflict' => 'Do truck GPS and the clock-in disagree?', 'empty_stop' => 'Remove this empty stop?', 'no_border' => 'Draw this property\'s border?'];
        $url = (string)($it['url'] ?? '') !== '' ? (string)$it['url'] : self::dayUrl((string)$it['day']);
        return self::base($it, $kind, $titles[$kind]) + [
            'primary' => ['label' => $kind === 'no_border' ? 'Draw it' : 'Open it', 'sub' => 'moves nothing', 'href' => $url],
            'secondary' => ['label' => 'Not yet', 'sub' => 'ask again tomorrow', 'skip' => true],
        ];
    }

    /** Time, plan length, pins and borders: the card's own buttons, through the same decide. */
    private static function housekeeping(array $it, int $sid, string $kind): array
    {
        $p = (array)($it['propose'] ?? []);
        $url = (string)($it['url'] ?? '') !== '' ? (string)$it['url'] : self::dayUrl((string)$it['day']);
        $skip = ['label' => 'Not yet', 'sub' => 'ask again tomorrow', 'skip' => true];
        $minutes = (int)($p['minutes'] ?? 0);
        switch ($kind) {
            case 'no_time':
                $job = self::base($it, $kind, 'How long did this visit take?');
                $job['primary'] = $minutes > 0
                    ? ['label' => 'Save ' . $minutes . ' min', 'sub' => 'on the visit', 'request' => self::decide($sid, ['choice' => 'apply', 'minutes' => $minutes])]
                    : ['label' => 'Open the day', 'sub' => 'enter the time', 'href' => $url];
                return $job + ['secondary' => $skip];
            case 'duration':
                $job = self::base($it, $kind, 'Change this plan\'s visit length?');
                $job['primary'] = $minutes > 0
                    ? ['label' => 'Update the plan', 'sub' => 'to ' . $minutes . ' min', 'request' => self::decide($sid, ['choice' => 'apply', 'minutes' => $minutes])]
                    : ['label' => 'Open the day', 'sub' => 'set the length', 'href' => $url];
                return $job + ['secondary' => ['label' => 'Keep it', 'sub' => $p['planned'] ?? null ? $p['planned'] . ' min stays' : 'stays as is', 'request' => self::decide($sid, ['choice' => 'keep'])]];
            case 'pin_off':
                return self::base($it, $kind, 'Move this property\'s pin to where the crews work?') + [
                    'primary' => ['label' => 'Move the pin', 'sub' => 'to where they work', 'request' => self::decide($sid, ['choice' => 'move_pin'])],
                    'secondary' => ['label' => 'The pin is right', 'sub' => 'leave it', 'request' => self::decide($sid, ['choice' => 'dismiss'])],
                ];
            case 'default_border':
                return self::base($it, $kind, 'Draw this property\'s real border?') + [
                    'primary' => ['label' => 'Draw it', 'sub' => 'opens the editor', 'href' => $url],
                    'secondary' => ['label' => 'Fine as it is', 'sub' => 'stops asking', 'request' => self::decide($sid, ['choice' => 'keep'])],
                ];
            default:   // no_pin, border_overlap
                $title = $kind === 'no_pin' ? 'Find this property on the map?' : 'Do these two borders overlap?';
                return self::base($it, $kind, $title) + [
                    'primary' => ['label' => $kind === 'no_pin' ? 'Find it' : 'Redraw', 'sub' => 'opens the map', 'href' => $url],
                    'secondary' => ['label' => 'Not now', 'sub' => 'leave it', 'request' => self::decide($sid, ['choice' => 'dismiss'])],
                ];
        }
    }

    private static function dayUrl(string $day): string
    {
        return '/crm/jobs/schedule.php?view=day&date=' . $day;
    }

    /** The four-state triangle from the record: none = forming, any not followed = held, 2-4 followed = known, 5+ = mastered. */
    public static function sure(array $record): string
    {
        $called = (int)($record[0] ?? 0);
        $kept = (int)($record[1] ?? 0);
        if ($called <= 0) return 'forming';
        if ($kept < $called) return 'held';
        return $kept >= self::MASTERED_AT ? 'mastered' : ($kept >= 2 ? 'known' : 'held');
    }

    private static function confidenceLine(array $record): string
    {
        $called = (int)($record[0] ?? 0);
        return $called > 0 ? $called . ' decided, ' . (int)($record[1] ?? 0) . ' went his way.' : '';
    }

    /** [decided, accepted] over his schedule kinds. [0, 0] on any failure. */
    public static function record(PDO $db): array
    {
        try {
            $in = implode(',', array_fill(0, count(self::KINDS), '?'));
            $s = $db->prepare("SELECT COUNT(*) AS called, COALESCE(SUM(status = 'accepted'), 0) AS kept
                FROM otto_suggestions WHERE kind IN ($in) AND status IN ('accepted', 'dismissed')");
            $s->execute(self::KINDS);
            $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
            return [(int)($r['called'] ?? 0), (int)($r['kept'] ?? 0)];
        } catch (Throwable $e) {
            return [0, 0];
        }
    }

    /** @return array<int, array{subject_id: int, status: string}> by suggestion id */
    private static function subjects(PDO $db, array $items): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn($i) => (int)($i['id'] ?? 0), $items))));
        if (!$ids) return [];
        $s = $db->query("SELECT id, subject_id, status FROM otto_suggestions WHERE id IN (" . implode(',', $ids) . ")");
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = ['subject_id' => (int)$r['subject_id'], 'status' => (string)$r['status']];
        return $out;
    }
}
