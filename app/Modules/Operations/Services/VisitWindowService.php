<?php
/**
 * VisitWindowService — Otto: book a recurring visit's window from when the crew really finishes, weekday by weekday.
 *
 *   proposals()   per plan + weekday with upcoming visits that have an end time: real finishes against the booked end.
 *   items()       Otto's suggestions: kind 'visit_window', and for a week after a change 'visit_window_done' with Undo.
 *   apply()       the upcoming visits of that weekday still on the old end move to the new end. A one-weekday plan changes
 *                 its default end; a plan with several weekdays keeps it in plan_weekday_windows for new visits.
 *   undo()        puts back what apply() changed, leaving any visit somebody has moved since.
 *
 * Otto never changes a window by himself; both write paths come from the owner's tap (OttoActionService).
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/VisitWindowRules.php';
require_once __DIR__ . '/VisitDurationRules.php';

class VisitWindowService
{
    /** About a season: summer's long days must not hold November's window open. */
    public const LOOKBACK_DAYS = 120;
    public const UNDO_DAYS = 7;

    private PDO $db;
    private string $today;

    public function __construct(PDO $db, ?string $today = null)
    {
        $this->db = $db;
        $this->today = $today ?? date('Y-m-d');
    }

    public function ready(): bool
    {
        try {
            return $this->db->query('SELECT 1 FROM otto_window_changes LIMIT 1') !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** [day_of_week => end time] agreed for a plan; empty when there are none or the migration has not run. */
    public static function windowsFor(PDO $db, int $planId): array
    {
        try {
            $s = $db->prepare('SELECT day_of_week, time_end FROM plan_weekday_windows WHERE plan_id = ?');
            $s->execute([$planId]);
            $out = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['day_of_week']] = (string)$r['time_end'];
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** The owner set the plan's times by hand: that wins over anything agreed per weekday. */
    public static function clearWindows(PDO $db, int $planId): void
    {
        try {
            $db->prepare('DELETE FROM plan_weekday_windows WHERE plan_id = ?')->execute([$planId]);
        } catch (Throwable $e) { /* migration not run yet: nothing to clear */ }
    }

    /** [plan_id => [dow => first upcoming visit]] for active recurring plans; the first upcoming visit says what is booked. */
    protected function loadUpcoming(): array
    {
        $s = $this->db->prepare("
            SELECT jv.plan_id, jv.scheduled_date, jv.scheduled_time_start, jv.scheduled_time_end, jp.title, jp.property_id,
                   p.address, p.property_name
            FROM job_visits jv
            JOIN job_plans jp ON jp.id = jv.plan_id
            JOIN properties p ON p.id = jp.property_id
            WHERE jv.status = 'scheduled' AND jv.scheduled_date >= ? AND jv.scheduled_time_start IS NOT NULL
              AND jv.scheduled_time_end IS NOT NULL AND jp.status = 'active' AND jp.is_recurring = 1
            ORDER BY jv.scheduled_date, jv.id
        ");
        $s->execute([$this->today]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $dow = VisitWindowRules::dayOfWeek((string)$r['scheduled_date']);
            if (!isset($out[(int)$r['plan_id']][$dow])) $out[(int)$r['plan_id']][$dow] = $r;
        }
        return $out;
    }

    /** [plan_id => completed visits newest first] in the shape VisitWindowRules::finish() takes. */
    protected function loadFinished(array $planIds): array
    {
        $out = [];
        if (!$planIds) return $out;
        $since = date('Y-m-d', strtotime($this->today . ' -' . self::LOOKBACK_DAYS . ' days'));
        foreach (array_chunk($planIds, 200) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $s = $this->db->prepare("
                SELECT jv.id, jv.plan_id, jv.scheduled_date, jv.actual_duration_minutes, o.first_at, o.last_at, o.minutes AS gps_minutes,
                       EXISTS (SELECT 1 FROM job_visits o2 JOIN job_plans p2 ON p2.id = o2.plan_id
                               WHERE o2.id <> jv.id AND o2.status = 'completed' AND o2.scheduled_date = jv.scheduled_date
                                 AND p2.property_id = jp.property_id) AS shared_day
                FROM job_visits jv
                JOIN job_plans jp ON jp.id = jv.plan_id
                LEFT JOIN visit_onsite_time o ON o.visit_id = jv.id
                WHERE jv.plan_id IN ({$in}) AND jv.status = 'completed' AND jv.scheduled_date >= ? AND jv.scheduled_date < ?
                ORDER BY jv.scheduled_date DESC, jv.id DESC
            ");
            $s->execute(array_merge($chunk, [$since, $this->today]));
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
            $entries = $this->loadEntries(array_map('intval', array_column($rows, 'id')));
            foreach ($rows as $r) {
                $out[(int)$r['plan_id']][] = [
                    'date' => (string)$r['scheduled_date'], 'entries' => $entries[(int)$r['id']] ?? [],
                    'gps_first' => $r['first_at'], 'gps_last' => $r['last_at'], 'gps_minutes' => $r['gps_minutes'] !== null ? (int)$r['gps_minutes'] : null,
                    'actual' => $r['actual_duration_minutes'] !== null ? (int)$r['actual_duration_minutes'] : null,
                    'shared_day' => (bool)$r['shared_day'],
                ];
            }
        }
        return $out;
    }

    private function loadEntries(array $visitIds): array
    {
        $out = [];
        foreach (array_chunk($visitIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $s = $this->db->prepare("
                SELECT visit_id, start_time, end_time, COALESCE(auto_stopped, 0) AS auto_stopped FROM job_time_entries
                WHERE visit_id IN ({$in}) AND status IN ('completed', 'edited') AND end_time IS NOT NULL
            ");
            $s->execute($chunk);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['visit_id']][] = ['start' => (string)$r['start_time'], 'end' => (string)$r['end_time'], 'auto_stopped' => (int)$r['auto_stopped'] === 1];
            }
        }
        return $out;
    }

    /** [plan_id:dow => for_date of the latest "Leave it"]. */
    protected function keptOn(): array
    {
        $out = [];
        $s = $this->db->query("SELECT subject_id, for_date, outcome_json FROM otto_suggestions
                               WHERE kind = 'visit_window' AND subject_type = 'plan' AND status = 'dismissed'");
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $o = json_decode((string)$r['outcome_json'], true) ?: [];
            if (($o['choice'] ?? '') !== 'keep') continue;
            $key = (int)$r['subject_id'] . ':' . (int)($o['dow'] ?? -1);
            if (!isset($out[$key]) || (string)$r['for_date'] > $out[$key]) $out[$key] = (string)$r['for_date'];
        }
        return $out;
    }

    /** @return list<array> one per plan + weekday with something to propose */
    public function proposals(): array
    {
        $upcoming = $this->loadUpcoming();
        $finished = $this->loadFinished(array_keys($upcoming));
        $out = [];
        foreach ($upcoming as $planId => $days) {
            foreach ($days as $dow => $next) {
                $finishes = [];
                foreach ($finished[$planId] ?? [] as $v) {
                    if (VisitWindowRules::dayOfWeek($v['date']) !== $dow) continue;
                    $f = VisitWindowRules::finish($v);
                    if ($f !== null) $finishes[] = ['date' => $v['date']] + $f;
                }
                $p = VisitWindowRules::propose($finishes, VisitWindowRules::minutes((string)$next['scheduled_time_start']),
                                               VisitWindowRules::minutes((string)$next['scheduled_time_end']));
                if ($p === null) continue;
                $name = trim((string)($next['property_name'] ?? ''));
                $out[] = ['plan_id' => $planId, 'dow' => $dow, 'place' => $name !== '' ? $name : trim(explode(',', (string)$next['address'])[0]),
                          'title' => (string)$next['title'], 'old_end' => (string)$next['scheduled_time_end'],
                          'finish_dates' => array_column($finishes, 'date')] + $p;
            }
        }
        return $out;
    }

    public function items(): array
    {
        if (!$this->ready()) return [];
        $out = [];
        $kept = $this->keptOn();
        foreach ($this->proposals() as $p) {
            $key = $p['plan_id'] . ':' . $p['dow'];
            if (isset($kept[$key]) && !VisitDurationRules::askAgain($p['finish_dates'], $kept[$key])) continue;
            $window = VisitWindowRules::clock($p['start']) . '–' . VisitWindowRules::clock($p['end']);
            $out[] = [
                'key' => 'otto:visit-window:' . $key, 'kind' => 'visit_window', 'subject_type' => 'plan', 'subject_id' => $p['plan_id'],
                'for_date' => $p['samples'][0]['date'], 'user_id' => null, 'priority' => 3,
                'text' => VisitWindowRules::line($p['place'], $p['dow'], $p), 'detail' => VisitWindowRules::detail($p),
                'url' => '/crm/jobs/view.php?id=' . $p['plan_id'], 'value' => $p['end'], 'since' => $p['samples'][0]['date'],
                'propose' => ['dow' => $p['dow'], 'end' => VisitWindowRules::hhmm($p['end']), 'old_end' => $p['old_end'],
                    'finishes' => $p['samples'], 'answers' => VisitWindowRules::answers($p),
                    'buttons' => [['label' => 'Book ' . $window, 'choice' => 'set_window', 'main' => true], ['label' => 'Leave it', 'choice' => 'keep', 'main' => false]]],
            ];
        }
        return array_merge($out, $this->doneItems());
    }

    /** A change stays on Otto's card with an Undo for UNDO_DAYS. */
    private function doneItems(): array
    {
        $since = date('Y-m-d 00:00:00', strtotime($this->today . ' -' . self::UNDO_DAYS . ' days'));
        $s = $this->db->prepare("
            SELECT c.id, c.plan_id, c.day_of_week, c.old_end, c.new_end, c.changed_at, p.address, p.property_name
            FROM otto_window_changes c JOIN job_plans jp ON jp.id = c.plan_id JOIN properties p ON p.id = jp.property_id
            WHERE c.undone_at IS NULL AND c.changed_at >= ? ORDER BY c.id
        ");
        $s->execute([$since]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = trim((string)($r['property_name'] ?? ''));
            $place = $name !== '' ? $name : trim(explode(',', (string)$r['address'])[0]);
            $date = substr((string)$r['changed_at'], 0, 10);
            $out[] = [
                'key' => 'otto:visit-window-done:' . (int)$r['id'], 'kind' => 'visit_window_done', 'subject_type' => 'window_change',
                'subject_id' => (int)$r['id'], 'for_date' => $date, 'user_id' => null, 'priority' => 4,
                'text' => $place . ', ' . VisitWindowRules::DAYS[(int)$r['day_of_week']] . ': now finishing by '
                        . VisitWindowRules::clock(VisitWindowRules::minutes((string)$r['new_end'])) . ' (was '
                        . VisitWindowRules::clock(VisitWindowRules::minutes((string)$r['old_end'])) . ').',
                'detail' => '', 'url' => '/crm/jobs/view.php?id=' . (int)$r['plan_id'], 'value' => null, 'since' => $date,
                'propose' => ['buttons' => [['label' => 'Undo', 'choice' => 'undo_window', 'main' => false], ['label' => 'Fine', 'choice' => 'dismiss', 'main' => true]]],
            ];
        }
        return $out;
    }

    /** @return array{ok:bool, message:string, change_id?:int, visits?:int} */
    public function apply(int $planId, int $dow, string $oldEnd, string $newEnd, int $actorId, array $basis = []): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Run migration 1444 first.'];
        if ($dow < 0 || $dow > 6) return ['ok' => false, 'message' => 'That weekday is not valid.'];
        $s = $this->db->prepare('SELECT id, recurrence_pattern, recurrence_day_of_week FROM job_plans WHERE id = ?');
        $s->execute([$planId]);
        $plan = $s->fetch(PDO::FETCH_ASSOC);
        if (!$plan) return ['ok' => false, 'message' => 'That plan is gone.'];

        $s = $this->db->prepare("SELECT id, scheduled_date FROM job_visits WHERE plan_id = ? AND status = 'scheduled' AND scheduled_date >= ? AND scheduled_time_end = ?");
        $s->execute([$planId, $this->today, $oldEnd]);
        $ids = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (VisitWindowRules::dayOfWeek((string)$r['scheduled_date']) === $dow) $ids[] = (int)$r['id'];
        }
        if (!$ids) return ['ok' => false, 'message' => 'No upcoming visits on that day still have that end time.'];

        $days = array_filter(explode(',', (string)$plan['recurrence_day_of_week']), 'strlen');
        // Only a plan that truly runs on one weekday may change its default; a custom or monthly plan lands on several.
        $onPlan = count($days) === 1 && in_array((string)$plan['recurrence_pattern'], ['weekly', 'biweekly'], true);
        $oldWindow = self::windowsFor($this->db, $planId)[$dow] ?? null;

        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $u = $this->db->prepare("UPDATE job_visits SET scheduled_time_end = ? WHERE id = ? AND status = 'scheduled' AND scheduled_time_end = ?");
            foreach ($ids as $id) $u->execute([$newEnd, $id, $oldEnd]);
            if ($onPlan) {
                $this->db->prepare('UPDATE job_plans SET default_time_end = ? WHERE id = ?')->execute([$newEnd, $planId]);
            } else {
                $this->setWindow($planId, $dow, $newEnd, $actorId);
            }
            $this->db->prepare('INSERT INTO otto_window_changes (plan_id, day_of_week, old_end, new_end, old_window_end, on_plan, visit_ids, basis_json, changed_by, changed_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$planId, $dow, $oldEnd, $newEnd, $oldWindow, $onPlan ? 1 : 0, implode(',', $ids), json_encode($basis), $actorId ?: null, date('Y-m-d H:i:s')]);
            $changeId = (int)$this->db->lastInsertId();
            if ($own) $this->db->commit();
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        $n = count($ids);
        return ['ok' => true, 'change_id' => $changeId, 'visits' => $n,
                'message' => VisitWindowRules::DAYS[$dow] . ' now end by ' . VisitWindowRules::clock(VisitWindowRules::minutes($newEnd)) . ' (' . $n . ' upcoming visit' . ($n === 1 ? '' : 's') . ' changed).'];
    }

    /** @return array{ok:bool, message:string} */
    public function undo(int $changeId, int $actorId): array
    {
        $s = $this->db->prepare('SELECT * FROM otto_window_changes WHERE id = ?');
        $s->execute([$changeId]);
        $c = $s->fetch(PDO::FETCH_ASSOC);
        if (!$c) return ['ok' => false, 'message' => 'That change is gone.'];
        if ($c['undone_at'] !== null) return ['ok' => false, 'message' => 'Already undone.'];
        $planId = (int)$c['plan_id'];
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $u = $this->db->prepare("UPDATE job_visits SET scheduled_time_end = ? WHERE id = ? AND status = 'scheduled' AND scheduled_time_end = ?");
            foreach (array_filter(explode(',', (string)$c['visit_ids']), 'strlen') as $id) $u->execute([$c['old_end'], (int)$id, $c['new_end']]);
            if ((int)$c['on_plan'] === 1) {
                $this->db->prepare('UPDATE job_plans SET default_time_end = ? WHERE id = ? AND default_time_end = ?')->execute([$c['old_end'], $planId, $c['new_end']]);
            } elseif ($c['old_window_end'] !== null) {
                $this->setWindow($planId, (int)$c['day_of_week'], (string)$c['old_window_end'], $actorId);
            } else {
                $this->db->prepare('DELETE FROM plan_weekday_windows WHERE plan_id = ? AND day_of_week = ?')->execute([$planId, (int)$c['day_of_week']]);
            }
            $this->db->prepare('UPDATE otto_window_changes SET undone_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $changeId]);
            if ($own) $this->db->commit();
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return ['ok' => true, 'message' => 'Back to finishing by ' . VisitWindowRules::clock(VisitWindowRules::minutes((string)$c['old_end'])) . '.'];
    }

    private function setWindow(int $planId, int $dow, string $end, int $actorId): void
    {
        $this->db->prepare('DELETE FROM plan_weekday_windows WHERE plan_id = ? AND day_of_week = ?')->execute([$planId, $dow]);
        $this->db->prepare('INSERT INTO plan_weekday_windows (plan_id, day_of_week, time_end, updated_by, updated_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$planId, $dow, $end, $actorId ?: null, date('Y-m-d H:i:s')]);
    }
}
