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
 * The other desk kinds (no_time, duration, pins and borders) stay on the things strip.
 *
 * Off unless ops_settings it_otto_job = '1'. Any failure means no job. No namespace / no autoloader in production.
 */
class OttoJobAdapter
{
    public const SETTING = 'it_otto_job';
    public const MAX_JOBS = 5;
    /** Accepted decisions of these kinds before his confidence reads mastered (the knowledge triangle: gold after five). */
    public const MASTERED_AT = 5;
    public const API = '/crm/api/otto.php?mode=decide';
    public const KINDS = ['unscheduled', 'extra_work', 'visit_date', 'weather'];
    /** Most urgent first. */
    private const ORDER = ['weather' => 0, 'overbooked' => 1, 'visit_date' => 2, 'unscheduled' => 3, 'extra_work' => 3];

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
                    if (in_array($it['kind'], self::KINDS, true) || $it['kind'] === 'overbooked') $items[] = $it + ['day' => $d];
                }
                $d = date('Y-m-d', strtotime($d . ' +1 day'));
            }
            if (!$items) return [];
            $subjects = self::subjects($db, $items);
            // The day's answer is cached 5 minutes: a suggestion decided since (here or on the card) must not come back.
            $items = array_values(array_filter($items, static function ($it) use ($subjects) {
                $sid = (int)($it['id'] ?? 0);
                return !$sid || (($subjects[$sid]['status'] ?? '') === 'open');
            }));
            if (!$items) return [];
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
            return self::queue($items, $plans, $slots, self::record($db), $today ?? date('Y-m-d'));
        } catch (Throwable $e) {
            error_log('OttoJobAdapter::jobs: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Pure shaping, so it can be tested without a database.
     * @param array $items  OttoScheduleService day items, each with 'day' (Y-m-d)
     * @param array $plans  active plans by suggestion id (UnscheduledWorkService::plans)
     * @param array $slots  the weather guard's next good slot by suggestion id ({date, time_start} or null)
     * @param array $record [decided from the flow line or the card, of those followed]
     * @param ?string $today Y-m-d: a full day or a weather move only means something from today on; null = no cut-off
     */
    public static function queue(array $items, array $plans, array $slots, array $record, ?string $today = null): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $it) {
            $kind = (string)($it['kind'] ?? '');
            $key = (string)($it['key'] ?? '');
            if ($key === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $sid = (int)($it['id'] ?? 0);
            if ($today !== null && in_array($kind, ['overbooked', 'weather'], true) && (string)($it['day'] ?? '') < $today) continue;   // nothing to fix on a day that is gone
            $job = null;
            if ($kind === 'overbooked') {
                $job = self::overbooked($it);
            } elseif ($sid && $kind === 'visit_date') {
                $job = self::visitDate($it, $sid);
            } elseif ($sid && $kind === 'weather') {
                $job = self::weather($it, $sid, $slots[$sid] ?? null);
            } elseif ($sid && in_array($kind, ['unscheduled', 'extra_work'], true)) {
                $job = self::unscheduled($it, $sid, (array)($plans[$sid] ?? []));
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

    private static function visitDate(array $it, int $sid): array
    {
        $p = (array)($it['propose'] ?? []);
        $to = (string)($p['to_label'] ?? $p['to'] ?? 'the day it was done');
        if (!empty($p['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$p['to'])) $it['day'] = (string)$p['to'];   // the day it moves to, whichever day was in view first
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
