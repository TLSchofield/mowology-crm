<?php
declare(strict_types=1);

/**
 * "This and all future visits" for a repeating job, from the Timeline modal.
 *
 * Everything is anchored on the date of the visit that was clicked, never on today and never on the
 * whole plan, so earlier visits, hand-moved visits and visits someone already worked are left alone.
 * updateJobPlan() is deliberately NOT used: it changes every scheduled visit from today and cancels
 * all of them in one bulk update on a recurrence change.
 *
 * The decisions are pure static functions (normaliseChange, planChange) and tested without a database.
 * preview() shows exactly what apply() will do; apply() recomputes it on the server inside one
 * transaction and never trusts a list from the browser.
 *
 * The repeat dates keep the job's own start date as their reference (calculateRecurrenceDates and the
 * nightly generator both read it), so the generator never drifts away from what was applied here.
 */
final class RecurringScheduleService
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Pure rules ────────────────────────────────────────────────────

    /**
     * Clean what the modal sent.
     * @param array<string,mixed> $in time_start, time_end, crew_ids[], repeat{every,unit,days[]}, stop_after, include_moved
     * @return array{change:array<string,mixed>,errors:string[]}
     */
    public static function normaliseChange(array $in): array
    {
        $c = ['time' => null, 'crew_ids' => null, 'repeat' => null, 'stop_after' => false, 'include_moved' => !empty($in['include_moved'])];
        $err = [];

        $s = isset($in['time_start']) && is_string($in['time_start']) ? $in['time_start'] : '';
        $e = isset($in['time_end']) && is_string($in['time_end']) ? $in['time_end'] : '';
        if ($s !== '' || $e !== '') {
            $sm = VisitResizeService::toMinutes($s);
            $em = VisitResizeService::toMinutes($e);
            if ($sm === null || $em === null) {
                $err[] = 'Give both a start and an end time.';
            } elseif ($em <= $sm) {
                $err[] = 'The end time has to be after the start time.';
            } else {
                $c['time'] = [VisitResizeService::fromMinutes($sm), VisitResizeService::fromMinutes($em)];
            }
        }

        if (isset($in['crew_ids'])) {
            $ids = is_array($in['crew_ids']) ? array_values(array_unique(array_filter(array_map('intval', $in['crew_ids']), function ($i) { return $i > 0; }))) : [];
            if (!$ids) {
                $err[] = 'Pick at least one person for the crew.';
            } else {
                $c['crew_ids'] = $ids;
            }
        }

        if (!empty($in['repeat']) && is_array($in['repeat'])) {
            $r = $in['repeat'];
            $unit = ($r['unit'] ?? 'weeks') === 'months' ? 'months' : 'weeks';
            $every = (int)($r['every'] ?? 1);
            if ($every < 1 || $every > ($unit === 'weeks' ? 12 : 12)) {
                $err[] = 'Repeat every 1 to 12 ' . $unit . '.';
            }
            $days = [];
            if ($unit === 'weeks') {
                $days = is_array($r['days'] ?? null) ? array_values(array_unique(array_filter(array_map('intval', $r['days']), function ($d) { return $d >= 0 && $d <= 6; }))) : [];
                sort($days);
                if (!$days) {
                    $err[] = 'Pick at least one weekday.';
                }
            }
            if (!$err) {
                $c['repeat'] = ['pattern' => $unit === 'weeks' ? 'weekly' : 'monthly', 'every' => $every, 'unit' => $unit, 'days' => $days];
            }
        }

        $c['stop_after'] = !empty($in['stop_after']);
        if ($c['stop_after'] && $c['repeat']) {
            $err[] = 'Choose either a new repeat or stop after this visit, not both.';
        }
        if (!$c['time'] && !$c['crew_ids'] && !$c['repeat'] && !$c['stop_after'] && !$err) {
            $err[] = 'Nothing to change.';
        }
        return ['change' => $c, 'errors' => $err];
    }

    /**
     * Decide what happens to each visit from the anchor date on.
     *
     * @param array<int,array<string,mixed>> $visits all non-cancelled visits of the plan on or after the anchor:
     *        id, scheduled_date, status, locked(bool), invoiced(bool), timer(bool), photos(int), notes(int), moved(bool)
     * @param array<string,mixed> $change   from normaliseChange()
     * @param string[]            $newDates dates the NEW repeat gives (empty unless repeat is changing)
     * @return array{update_time:int[],update_crew:int[],cancel:int[],add:string[],keep:array<int,array{id:int,date:string,why:string}>}
     */
    public static function planChange(array $visits, array $change, string $anchor, array $newDates): array
    {
        $out = ['update_time' => [], 'update_crew' => [], 'cancel' => [], 'add' => [], 'keep' => []];
        $keep = [];
        $cancelled = [];
        $occupied = [];

        foreach ($visits as $v) {
            $id = (int)$v['id'];
            $date = (string)$v['scheduled_date'];
            $occupied[$date] = true;
            if ($v['status'] !== 'scheduled') {
                continue;   // started, done, skipped: history, not part of the change
            }
            // Cancelling a visit (new repeat, stop after) is stricter than editing its time or crew.
            if ($change['repeat'] || $change['stop_after']) {
                $isNew = $change['repeat'] ? in_array($date, $newDates, true) : ($date <= $anchor);
                if (!$isNew) {
                    $why = self::cancelWhy($v);
                    if ($why !== null) {
                        $keep[$id] = ['id' => $id, 'date' => $date, 'why' => $why];
                    } else {
                        $out['cancel'][] = $id;
                        $cancelled[$id] = true;
                        unset($occupied[$date]);
                    }
                    continue;
                }
            }
            if ($change['time']) {
                $why = !empty($v['locked']) ? 'it is locked' : (!empty($v['moved']) && empty($change['include_moved']) ? 'you moved it by hand' : null);
                if ($why === null) {
                    $out['update_time'][] = $id;
                } else {
                    $keep[$id] = ['id' => $id, 'date' => $date, 'why' => $why];
                }
            }
            if ($change['crew_ids']) {
                if (!empty($v['locked'])) {
                    $keep[$id] = $keep[$id] ?? ['id' => $id, 'date' => $date, 'why' => 'it is locked'];
                } else {
                    $out['update_crew'][] = $id;
                }
            }
        }

        if ($change['repeat']) {
            foreach ($newDates as $d) {
                if (empty($occupied[$d])) {
                    $out['add'][] = $d;
                }
            }
            sort($out['add']);
        }
        $out['keep'] = array_values($keep);
        usort($out['keep'], function ($a, $b) { return strcmp($a['date'], $b['date']); });
        return $out;
    }

    /** Why a visit must not be cancelled by a repeat change, or null. */
    public static function cancelWhy(array $v): ?string
    {
        if (!empty($v['locked'])) return 'it is locked';
        if (!empty($v['invoiced'])) return 'it has an invoice';
        if (!empty($v['timer'])) return 'someone is working on it';
        if ((int)($v['photos'] ?? 0) > 0) return 'it has photos';
        if ((int)($v['notes'] ?? 0) > 0) return 'it has notes';
        if (!empty($v['moved'])) return 'you moved it by hand';
        return null;
    }

    // ── Preview and apply ─────────────────────────────────────────────

    /**
     * What would change, without changing anything.
     * @param array<string,mixed> $change from normaliseChange()
     * @return array<string,mixed>|null null when the visit does not exist
     */
    public function preview(int $visitId, array $change): ?array
    {
        $ctx = $this->context($visitId);
        if ($ctx === null) {
            return null;
        }
        $plan = $this->planChangeFor($ctx, $change);
        return $this->describe($ctx, $change, $plan);
    }

    /**
     * Apply the change from the clicked visit on. One transaction.
     * @param array<string,mixed> $change from normaliseChange()
     * @return array{success:bool,error?:string,result?:array<string,mixed>}
     */
    public function apply(int $visitId, int $userId, array $change, ?string $ip): array
    {
        $ctx = $this->context($visitId);
        if ($ctx === null) {
            return ['success' => false, 'error' => 'This visit no longer exists. Refresh the schedule.'];
        }
        if (empty($ctx['plan']['is_recurring'])) {
            return ['success' => false, 'error' => 'This job doesn’t repeat, so there are no later visits to change.'];
        }
        if ($ctx['plan']['status'] !== 'active') {
            return ['success' => false, 'error' => 'This job is paused. Resume it first.'];
        }
        $p = $this->planChangeFor($ctx, $change);
        $planId = (int)$ctx['plan']['id'];

        $own = $this->db->inTransaction() ? false : $this->db->beginTransaction();
        try {
            // Plan defaults first, so visits created below get the new time and crew.
            $set = [];
            $par = [];
            if ($change['time']) {
                $set[] = 'default_time_start = ?'; $par[] = $change['time'][0];
                $set[] = 'default_time_end = ?';   $par[] = $change['time'][1];
            }
            if ($change['repeat']) {
                $r = $change['repeat'];
                $set[] = 'is_recurring = 1';
                $set[] = 'recurrence_pattern = ?';       $par[] = $r['pattern'];
                $set[] = 'recurrence_interval = ?';      $par[] = $r['every'];
                $set[] = 'recurrence_interval_unit = ?'; $par[] = $r['unit'];
                $set[] = 'recurrence_day_of_week = ?';   $par[] = $r['days'] ? implode(',', $r['days']) : null;
            }
            if ($change['stop_after']) {
                $set[] = 'plan_end_date = ?'; $par[] = $ctx['anchor'];
            }
            if ($set) {
                $par[] = $planId;
                $this->db->prepare('UPDATE job_plans SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($par);
            }
            if ($change['time']) {
                require_once APP_ROOT . '/Modules/Operations/Services/VisitWindowService.php';
                VisitWindowService::clearWindows($this->db, $planId);
            }
            if ($change['crew_ids']) {
                setPlanCrewAssignments($planId, $change['crew_ids']);
            }

            $this->cancelVisits($p['cancel'], $userId, $ip, $ctx['anchor']);
            $this->updateTime($p['update_time'], $change['time'], $ctx);
            $this->updateCrew($p['update_crew'], $change['crew_ids'], $ctx);
            $created = $change['repeat'] ? $this->createVisits($this->freshPlan($planId), $p['add']) : 0;

            if ($own) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('RecurringScheduleService::apply ' . $e->getMessage());
            return ['success' => false, 'error' => 'Couldn’t save. Nothing was changed.'];
        }
        $this->audit($visitId, $userId, 'recurring_edit', [
            'from' => $ctx['anchor'], 'time' => $change['time'], 'crew' => $change['crew_ids'], 'repeat' => $change['repeat'],
            'stop_after' => $change['stop_after'], 'cancelled' => count($p['cancel']), 'added' => $created, 'kept' => count($p['keep']),
        ], $ip);
        $d = $this->describe($ctx, $change, $p);
        $d['added_count'] = $created;
        return ['success' => true, 'result' => $d];
    }

    /** Pause the whole job (existing rule: cancels every scheduled visit from today). */
    public function pause(int $visitId, int $userId, string $reason, ?string $ip): array
    {
        $planId = $this->planIdFor($visitId);
        if (!$planId) {
            return ['success' => false, 'error' => 'This visit no longer exists. Refresh the schedule.'];
        }
        if (!VisitLifecycleService::pausePlan($planId, $userId, $reason)) {
            return ['success' => false, 'error' => 'Couldn’t pause this job. It may already be paused.'];
        }
        $this->audit($visitId, $userId, 'plan_paused', ['plan_id' => $planId], $ip);
        return ['success' => true];
    }

    public function resume(int $visitId, int $userId, ?string $ip): array
    {
        $planId = $this->planIdFor($visitId);
        if (!$planId) {
            return ['success' => false, 'error' => 'This visit no longer exists. Refresh the schedule.'];
        }
        if (!VisitLifecycleService::resumePlan($planId, $userId)) {
            return ['success' => false, 'error' => 'Couldn’t resume this job. It may not be paused.'];
        }
        $this->audit($visitId, $userId, 'plan_resumed', ['plan_id' => $planId], $ip);
        return ['success' => true];
    }

    // ── Internals ─────────────────────────────────────────────────────

    private function planIdFor(int $visitId): int
    {
        $st = $this->db->prepare('SELECT plan_id FROM job_visits WHERE id = ?');
        $st->execute([$visitId]);
        return (int)$st->fetchColumn();
    }

    private function freshPlan(int $planId): array
    {
        $st = $this->db->prepare('SELECT * FROM job_plans WHERE id = ?');
        $st->execute([$planId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null */
    private function context(int $visitId): ?array
    {
        $st = $this->db->prepare('SELECT id, plan_id, scheduled_date FROM job_visits WHERE id = ?');
        $st->execute([$visitId]);
        $v = $st->fetch(PDO::FETCH_ASSOC);
        if (!$v) {
            return null;
        }
        $plan = $this->freshPlan((int)$v['plan_id']);
        if (!$plan) {
            return null;
        }
        $anchor = (string)$v['scheduled_date'];
        $q = $this->db->prepare("
            SELECT jv.id, jv.scheduled_date, jv.status, jv.locked_at, jv.invoice_id, jv.is_invoiced,
                   jv.original_scheduled_date, jv.stop_id,
                   (SELECT COUNT(*) FROM visit_photos vp WHERE vp.visit_id = jv.id AND vp.deleted_at IS NULL) AS photos,
                   (SELECT COUNT(*) FROM visit_notes vn WHERE vn.visit_id = jv.id) AS notes,
                   (SELECT COUNT(*) FROM job_time_entries jt WHERE jt.visit_id = jv.id AND jt.status = 'active' AND jt.end_time IS NULL) AS timers
            FROM job_visits jv
            WHERE jv.plan_id = ? AND jv.scheduled_date >= ? AND jv.status <> 'cancelled'
            ORDER BY jv.scheduled_date, jv.id
        ");
        $q->execute([(int)$v['plan_id'], $anchor]);
        $visits = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $visits[] = [
                'id' => (int)$r['id'], 'scheduled_date' => $r['scheduled_date'], 'status' => $r['status'], 'stop_id' => $r['stop_id'] ? (int)$r['stop_id'] : null,
                'locked' => !empty($r['locked_at']), 'invoiced' => !empty($r['invoice_id']) || !empty($r['is_invoiced']),
                'timer' => (int)$r['timers'] > 0, 'photos' => (int)$r['photos'], 'notes' => (int)$r['notes'], 'moved' => !empty($r['original_scheduled_date']),
            ];
        }
        return ['plan' => $plan, 'anchor' => $anchor, 'visits' => $visits];
    }

    /** @param array<string,mixed> $ctx @param array<string,mixed> $change */
    private function planChangeFor(array $ctx, array $change): array
    {
        $new = [];
        if ($change['repeat']) {
            $r = $change['repeat'];
            $plan = $ctx['plan'];
            $plan['recurrence_pattern'] = $r['pattern'];
            $plan['recurrence_interval'] = $r['every'];
            $plan['recurrence_interval_unit'] = $r['unit'];
            $plan['recurrence_day_of_week'] = $r['days'] ? implode(',', $r['days']) : null;
            $from = max($ctx['anchor'], date('Y-m-d'));
            $to = date('Y-m-d', strtotime('+' . max(7, (int)($plan['horizon_days'] ?: 28)) . ' days'));
            if (!empty($plan['plan_end_date']) && $plan['plan_end_date'] < $to) {
                $to = $plan['plan_end_date'];
            }
            if ($from <= $to) {
                $holidays = [];
                try {
                    $holidays = VisitGenerationService::getActiveHolidays($from, $to);
                } catch (Throwable $e) {
                    error_log('RecurringScheduleService holidays ' . $e->getMessage());
                }
                $new = VisitGenerationService::calculateRecurrenceDates($plan, $from, $to, $holidays);
            }
            // Dates before today are history: a visit there is never created or cancelled by this change.
            $ctx['visits'] = array_values(array_filter($ctx['visits'], function ($v) { return $v['scheduled_date'] >= date('Y-m-d') || $v['status'] !== 'scheduled'; }));
        }
        return self::planChange($ctx['visits'], $change, $ctx['anchor'], $new);
    }

    /** @param array<string,mixed> $ctx @param array<string,mixed> $change @param array<string,mixed> $p */
    private function describe(array $ctx, array $change, array $p): array
    {
        $dates = [];
        foreach ($ctx['visits'] as $v) {
            $dates[$v['id']] = $v['scheduled_date'];
        }
        $pick = function (array $ids) use ($dates) {
            $o = [];
            foreach ($ids as $id) { if (isset($dates[$id])) $o[] = $dates[$id]; }
            sort($o);
            return $o;
        };
        return [
            'from' => $ctx['anchor'],
            'time_dates' => $pick($p['update_time']),
            'crew_dates' => $pick($p['update_crew']),
            'cancel_dates' => $pick($p['cancel']),
            'add_dates' => $p['add'],
            'keep' => $p['keep'],
        ];
    }

    /** @param int[] $ids */
    private function cancelVisits(array $ids, int $userId, ?string $ip, string $anchor): void
    {
        if (!$ids) return;
        require_once APP_ROOT . '/Modules/Jobs/Services/CalendarStopTidyService.php';
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->db->prepare("SELECT id, stop_id FROM job_visits WHERE id IN ($ph)");
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $this->db->prepare("UPDATE job_visits SET status = 'cancelled', status_changed_at = NOW() WHERE id IN ($ph) AND status = 'scheduled'")->execute($ids);
        foreach ($rows as $r) {
            $this->audit((int)$r['id'], $userId, 'cancelled', ['by' => 'recurring_edit', 'from' => $anchor], $ip);
            if ($r['stop_id']) {
                CalendarStopTidyService::deleteIfEmpty($this->db, (int)$r['stop_id']);
            }
        }
    }

    /** @param int[] $ids @param array{0:string,1:string} $time */
    private function updateTime(array $ids, ?array $time, array $ctx): void
    {
        if (!$ids || !$time) return;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $col = VisitResizeService::columnReady($this->db) ? ', duration_override_minutes = NULL' : '';
        $this->db->prepare("UPDATE job_visits SET scheduled_time_start = ?, scheduled_time_end = ?{$col} WHERE id IN ($ph) AND status = 'scheduled' AND locked_at IS NULL")
            ->execute(array_merge([$time[0], $time[1]], $ids));
        // The stop's own arrival/departure, only for stops that hold nothing but this job's visits.
        $this->db->prepare("
            UPDATE calendar_stops cs SET cs.estimated_arrival = ?, cs.estimated_departure = ?
            WHERE cs.id IN (SELECT stop_id FROM (SELECT DISTINCT stop_id FROM job_visits WHERE id IN ($ph) AND stop_id IS NOT NULL) x)
              AND NOT EXISTS (SELECT 1 FROM job_visits o WHERE o.stop_id = cs.id AND o.plan_id <> ? AND o.status <> 'cancelled')
        ")->execute(array_merge([$time[0], $time[1]], $ids, [(int)$ctx['plan']['id']]));
    }

    /** @param int[] $ids @param int[] $crewIds */
    private function updateCrew(array $ids, ?array $crewIds, array $ctx): void
    {
        if (!$ids || !$crewIds) return;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $lead = $crewIds[0];
        $this->db->prepare("UPDATE job_visits SET assigned_crew_id = ? WHERE id IN ($ph) AND status = 'scheduled' AND locked_at IS NULL")->execute(array_merge([$lead], $ids));
        $st = $this->db->prepare("SELECT DISTINCT stop_id FROM job_visits WHERE id IN ($ph) AND stop_id IS NOT NULL");
        $st->execute($ids);
        $planId = (int)$ctx['plan']['id'];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $stopId) {
            $stopId = (int)$stopId;
            $shared = $this->db->prepare("SELECT 1 FROM job_visits WHERE stop_id = ? AND plan_id <> ? AND status <> 'cancelled' LIMIT 1");
            $shared->execute([$stopId, $planId]);
            if ($shared->fetchColumn()) {
                continue;   // another job's visit is on this stop: its crew is not ours to rewrite
            }
            $this->db->prepare('DELETE FROM calendar_stop_crew WHERE stop_id = ?')->execute([$stopId]);
            $ins = $this->db->prepare('INSERT IGNORE INTO calendar_stop_crew (stop_id, user_id) VALUES (?, ?)');
            foreach ($crewIds as $uid) {
                $ins->execute([$stopId, $uid]);
            }
            $this->db->prepare('UPDATE calendar_stops SET crew_id = ?, updated_at = NOW() WHERE id = ?')->execute([$lead, $stopId]);
        }
    }

    /**
     * Same steps as VisitGenerationService::generateVisits() for one date list (stop, crew rows, visit).
     * @param array<string,mixed> $plan @param string[] $dates
     */
    private function createVisits(array $plan, array $dates): int
    {
        if (!$dates) return 0;
        $planId = (int)$plan['id'];
        $crew = [];
        $cq = $this->db->prepare("SELECT user_id FROM plan_crew_assignments WHERE plan_id = ? ORDER BY FIELD(role, 'lead', 'crew'), user_id");
        $cq->execute([$planId]);
        foreach ($cq->fetchAll(PDO::FETCH_COLUMN) as $u) { $crew[] = (int)$u; }
        if (!$crew && $plan['default_crew_id']) { $crew = [(int)$plan['default_crew_id']]; }
        $seq = $this->db->prepare('SELECT MAX(sequence_index) FROM job_visits WHERE plan_id = ?');
        $seq->execute([$planId]);
        $next = ((int)$seq->fetchColumn()) + 1;
        $made = 0;
        foreach ($dates as $date) {
            $arr = $plan['default_time_start'] ?: null;
            $dep = null;
            if ($arr && !empty($plan['estimated_duration_minutes'])) {
                $dep = planMinutesToTimeString(planTimeStringToMinutes($arr) + (int)$plan['estimated_duration_minutes']);
            }
            $stopId = ensureCalendarStop((int)$plan['property_id'], $date, $plan['default_crew_id'] ? (int)$plan['default_crew_id'] : null, $arr, $dep);
            if ($stopId > 0 && $crew) {
                $ins = $this->db->prepare('INSERT IGNORE INTO calendar_stop_crew (stop_id, user_id) VALUES (?, ?)');
                foreach ($crew as $u) { $ins->execute([$stopId, $u]); }
            }
            $ins = $this->db->prepare("
                INSERT IGNORE INTO job_visits (visit_number, plan_id, stop_id, scheduled_date, scheduled_time_start, scheduled_time_end, sequence_index, assigned_crew_id, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'scheduled')
            ");
            $ins->execute([generateVisitNumber($plan['plan_number'], $next), $planId, $stopId, $date, $plan['default_time_start'],
                VisitGenerationService::endForDate($this->db, $plan, $date), $next, $plan['default_crew_id']]);
            $made += $ins->rowCount() > 0 ? 1 : 0;
            $next++;
        }
        return $made;
    }

    /** @param array<string,mixed> $payload */
    private function audit(int $visitId, int $userId, string $action, array $payload, ?string $ip): void
    {
        try {
            $this->db->prepare('INSERT INTO visit_audit_log (visit_id, user_id, action, payload_json, ip_address) VALUES (?, ?, ?, ?, ?)')
                ->execute([$visitId, $userId, $action, json_encode($payload), $ip ? substr($ip, 0, 45) : null]);
        } catch (Throwable $e) {
            error_log('RecurringScheduleService::audit ' . $e->getMessage());
        }
    }
}
