<?php
/**
 * OttoActionService — applies one of Otto's suggestions when the owner clicks it, and
 * learns from what he chose.
 *
 *   weather   move  → executeReschedule() (the weather guard's own rescheduler; logs
 *                     VISIT_RESCHEDULE to weather_action_log) to the date/time he confirmed
 *             keep  → clearWeatherEvaluation() + MANUAL_KEEP, exactly as the Weather
 *                     Actions page does
 *             either way the rain % is added to the service type's weather lesson
 *   clock_out apply → time_clock_entries.clock_out = his time, status 'edited', timesheet
 *                     totals recalculated (same as /crm/api/time-entry-edit.php)
 *   job_timer apply → job_time_entries end/duration = his minutes, status 'edited'
 *   no_time   apply → job_visits.actual_duration_minutes = his minutes; the plan's
 *                     duration lesson remembers it
 *   silent    real | fine → recorded only (true positive / false alarm)
 *   any       dismiss → recorded, nothing changes
 *
 * Nothing here messages a crew member. Nothing runs without the owner's click.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/OttoRules.php';
require_once __DIR__ . '/DispatchRules.php';

class OttoActionService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @param array $in  choice, plus date/time (weather move), clock_out (Y-m-d H:i), minutes
     * @return array{ok: bool, message: string, status?: string}
     */
    public function decide(int $suggestionId, array $in, int $actorId): array
    {
        $s = $this->db->prepare("SELECT * FROM otto_suggestions WHERE id = ?");
        $s->execute([$suggestionId]);
        $sug = $s->fetch(PDO::FETCH_ASSOC);
        if (!$sug) return ['ok' => false, 'message' => 'That suggestion is gone.'];
        if ($sug['status'] !== 'open') return ['ok' => false, 'message' => 'Already decided.'];

        $choice = (string)($in['choice'] ?? '');
        $propose = json_decode((string)$sug['suggestion_json'], true) ?: [];
        if ($choice === 'dismiss') {
            return $this->close($sug, 'dismissed', ['choice' => 'dismiss'], $actorId, 'Left as it is.');
        }
        switch ($sug['kind']) {
            case 'weather':   return $this->weather($sug, $propose, $choice, $in, $actorId);
            case 'clock_out': return $this->clockOut($sug, $propose, $choice, $in, $actorId);
            case 'job_timer': return $this->timer($sug, $propose, $choice, $in, $actorId);
            case 'no_time':   return $this->noTime($sug, $propose, $choice, $in, $actorId);
            case 'silent':    return $this->silent($sug, $choice, $actorId);
            case 'bylaw':     return $this->bylaw($sug, $propose, $choice, $in, $actorId);
            case 'west_end':  return $this->westEnd($sug, $propose, $choice, $actorId);
            case 'truck_range':
                if ($choice !== 'ack') return ['ok' => false, 'message' => 'Rework the day, or leave it?'];
                return $this->close($sug, 'accepted', ['choice' => 'ack'], $actorId, 'Noted. Move a stop to another day or truck on the schedule.');
            case 'maintenance': return $this->maintenance($sug, $propose, $choice, $actorId);
            case 'pack_fading':
                if ($choice !== 'retire') return ['ok' => false, 'message' => 'Retire it, or keep using it?'];
                require_once __DIR__ . '/EquipmentService.php';
                (new EquipmentService($this->db))->retire((int)$sug['subject_id']);
                return $this->close($sug, 'accepted', ['choice' => 'retire'], $actorId, 'Retired in the register.');
            case 'training_gap':
            case 'training_quality':
            case 'training_topic':
            case 'safety_refresher':
                return $this->training($sug, $propose, $choice, $actorId);
            case 'unscheduled':
            case 'extra_work':  return $this->unscheduled($sug, $in, $actorId);
            case 'visit_date':
                if ($choice !== 'move') return ['ok' => false, 'message' => 'Move it, or leave it?'];
                require_once __DIR__ . '/UnscheduledWorkService.php';
                if (!(new UnscheduledWorkService($this->db))->moveVisitDate((int)$sug['subject_id'], (string)$sug['for_date'])) {
                    return ['ok' => false, 'message' => 'Could not move it — it may have been changed or cancelled. Open the visit.'];
                }
                return $this->close($sug, 'accepted', ['choice' => 'move', 'from' => $propose['from'] ?? null, 'to' => $sug['for_date']], $actorId,
                    'Moved to ' . date('D M j', strtotime((string)$sug['for_date'])) . '.');
            case 'duration':    return $this->duration($sug, $propose, $choice, $in, $actorId);
        }
        return ['ok' => false, 'message' => 'Unknown suggestion.'];
    }

    /**
     * The guard's own pick of the next good slot for a flagged visit — read only.
     * @return array{date: string, time_start: string}|null
     */
    public function findSlot(int $visitId): ?array
    {
        $this->loadWeatherModules();
        $s = $this->db->prepare("
            SELECT v.id, v.scheduled_date, v.scheduled_time_start, v.scheduled_time_end, v.assigned_crew_id,
                   p.service_package_id, p.service_type, p.property_id, prop.latitude, prop.longitude, prop.address
            FROM job_visits v JOIN job_plans p ON p.id = v.plan_id JOIN properties prop ON prop.id = p.property_id
            WHERE v.id = ?
        ");
        $s->execute([$visitId]);
        $v = $s->fetch(PDO::FETCH_ASSOC);
        if (!$v) return null;
        $rule = aggregateVisitRules($v['service_package_id'] ? [(int)$v['service_package_id']] : []);
        $slot = findAlternateSlot($visitId, $rule, $v + ['latitude' => (float)$v['latitude'], 'longitude' => (float)$v['longitude']]);
        if (!$slot || empty($slot['date'])) return null;
        return ['date' => (string)$slot['date'], 'time_start' => substr((string)($slot['time_start'] ?? ($v['scheduled_time_start'] ?: '08:00')), 0, 5)];
    }

    // ── Kinds ───────────────────────────────────────────────────────────────

    private function weather(array $sug, array $propose, string $choice, array $in, int $actorId): array
    {
        if (!in_array($choice, ['move', 'keep'], true)) return ['ok' => false, 'message' => 'Move or keep?'];
        $this->loadWeatherModules();
        $visitId = (int)$sug['subject_id'];
        $lean = $propose['lean'] ?? null;
        $outcome = ['choice' => $choice, 'lean' => $lean, 'followed' => $lean === null ? null : $lean === $choice];
        if ($choice === 'move') {
            $date = (string)($in['date'] ?? '');
            $time = substr((string)($in['time'] ?? ''), 0, 5);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < date('Y-m-d')) return ['ok' => false, 'message' => 'Pick a date from today on.'];
            if (!preg_match('/^\d{2}:\d{2}$/', $time)) $time = '08:00';
            $r = executeReschedule($visitId, $date, $time, $actorId, 'Weather — moved from Otto\'s suggestion');
            if (empty($r['success'])) return ['ok' => false, 'message' => 'Could not move it: ' . ($r['error'] ?? 'unknown')];
            $outcome += ['date' => $date, 'time' => $time];
            $msg = 'Moved to ' . date('D M j', strtotime($date)) . ' at ' . date('g:i a', strtotime($time)) . '.';
        } else {
            clearWeatherEvaluation($visitId, 'Kept from Otto\'s suggestion');
            logWeatherAction('MANUAL_KEEP', 'visit', $visitId, json_encode(['reason' => 'Kept from Otto', 'user' => $actorId]), $actorId);
            $msg = 'Kept. It stays on the schedule.';
        }
        $this->learnWeather((string)($sug['service_type'] ?? ''), $choice, $sug['rain_pct'] !== null ? (int)$sug['rain_pct'] : null);
        return $this->close($sug, 'accepted', $outcome, $actorId, $msg);
    }

    private function clockOut(array $sug, array $propose, string $choice, array $in, int $actorId): array
    {
        if ($choice !== 'apply') return ['ok' => false, 'message' => 'Apply or leave it?'];
        $s = $this->db->prepare("SELECT * FROM time_clock_entries WHERE id = ?");
        $s->execute([(int)$sug['subject_id']]);
        $e = $s->fetch(PDO::FETCH_ASSOC);
        if (!$e || $e['status'] === 'void') return ['ok' => false, 'message' => 'That shift is gone.'];
        $outTs = strtotime((string)($in['clock_out'] ?? ''));
        $inTs = strtotime((string)$e['clock_in']);
        if (!$outTs || $outTs <= $inTs || $outTs > $inTs + OttoRules::MAX_SHIFT_HOURS * 3600) {
            return ['ok' => false, 'message' => 'The clock-out has to be after ' . date('g:i a', $inTs) . ' and within ' . OttoRules::MAX_SHIFT_HOURS . ' hours.'];
        }
        $minutes = (int)round(($outTs - $inTs) / 60);
        $this->db->prepare("
            UPDATE time_clock_entries
            SET clock_out = ?, total_minutes = ?, status = 'edited', edited_by = ?, edited_at = NOW(),
                notes = CONCAT(COALESCE(notes, ''), ' [clock-out set by the owner from Otto]')
            WHERE id = ? AND status IN ('active', 'completed', 'edited')
        ")->execute([date('Y-m-d H:i:s', $outTs), $minutes, $actorId, (int)$e['id']]);
        $this->recalcTimesheet((int)$e['user_id'], (string)$e['clock_in']);
        $sugTs = !empty($propose['clock_out']) ? strtotime((string)$propose['clock_out']) : null;
        $status = $sugTs ? OttoRules::keptOrEdited((int)round($sugTs / 60), (int)round($outTs / 60)) : 'edited';
        return $this->close($sug, $status, ['choice' => 'apply', 'clock_out' => date('Y-m-d H:i', $outTs), 'suggested' => $propose['clock_out'] ?? null], $actorId,
            'Clock-out set to ' . date('g:i a', $outTs) . '. Timesheet updated.');
    }

    private function timer(array $sug, array $propose, string $choice, array $in, int $actorId): array
    {
        $minutes = (int)($in['minutes'] ?? 0);
        if ($choice !== 'apply' || $minutes < 1 || $minutes > 720) return ['ok' => false, 'message' => 'Give the minutes, 1 to 720.'];
        $s = $this->db->prepare("SELECT * FROM job_time_entries WHERE id = ?");
        $s->execute([(int)$sug['subject_id']]);
        $e = $s->fetch(PDO::FETCH_ASSOC);
        if (!$e) return ['ok' => false, 'message' => 'That timer is gone.'];
        $this->db->prepare("
            UPDATE job_time_entries
            SET end_time = DATE_ADD(start_time, INTERVAL ? MINUTE), duration_minutes = ?, status = 'edited',
                notes = CONCAT(COALESCE(notes, ''), ' [length set by the owner from Otto]')
            WHERE id = ? AND status IN ('completed', 'edited')
        ")->execute([$minutes, $minutes, (int)$e['id']]);
        $this->recalcTimesheet((int)$e['user_id'], (string)$e['start_time']);
        $this->learnDuration((int)($propose['plan_id'] ?? 0), $minutes);
        $status = isset($propose['minutes']) ? OttoRules::keptOrEdited((int)$propose['minutes'], $minutes) : 'edited';
        return $this->close($sug, $status, ['choice' => 'apply', 'minutes' => $minutes, 'suggested' => $propose['minutes'] ?? null], $actorId,
            'Timer set to ' . $minutes . ' min.');
    }

    private function noTime(array $sug, array $propose, string $choice, array $in, int $actorId): array
    {
        $minutes = (int)($in['minutes'] ?? 0);
        if ($choice !== 'apply' || $minutes < 1 || $minutes > 720) return ['ok' => false, 'message' => 'Give the minutes, 1 to 720.'];
        $this->db->prepare("
            UPDATE job_visits SET actual_duration_minutes = ?
            WHERE id = ? AND (actual_duration_minutes IS NULL OR actual_duration_minutes = 0)
        ")->execute([$minutes, (int)$sug['subject_id']]);
        $this->learnDuration((int)($propose['plan_id'] ?? 0), $minutes);
        $status = isset($propose['minutes']) ? OttoRules::keptOrEdited((int)$propose['minutes'], $minutes) : 'edited';
        return $this->close($sug, $status, ['choice' => 'apply', 'minutes' => $minutes, 'suggested' => $propose['minutes'] ?? null], $actorId,
            'Saved ' . $minutes . ' min on the visit.');
    }

    private function silent(array $sug, string $choice, int $actorId): array
    {
        if (!in_array($choice, ['real', 'fine'], true)) return ['ok' => false, 'message' => 'Problem or fine?'];
        $r = $this->close($sug, $choice === 'real' ? 'accepted' : 'dismissed', ['choice' => $choice], $actorId,
            $choice === 'real' ? 'Noted. Worth a call.' : 'Noted. I\'ll remember.');
        if ($choice === 'fine') {
            require_once __DIR__ . '/OttoQuestionService.php';
            (new OttoQuestionService($this->db))->afterSilentFine((int)$sug['subject_id']);
        }
        return $r;
    }

    // ── Dispatcher kinds ────────────────────────────────────────────────────

    private function bylaw(array $sug, array $propose, string $choice, array $in, int $actorId): array
    {
        $visitId = (int)$sug['subject_id'];
        if ($choice === 'note') {
            $this->visitNote($visitId, 'Bylaw: ' . (($propose['problem'] ?? '') === 'ban' ? 'no leaf blowers this day here' : 'keep power equipment inside the allowed hours')
                . ' — rake or vacuum instead. (From Otto)', $actorId);
            return $this->close($sug, 'accepted', ['choice' => 'note'], $actorId, 'Note added to the visit for the crew.');
        }
        if ($choice !== 'retime') return ['ok' => false, 'message' => 'New time, a crew note, or leave it?'];
        $time = substr((string)($in['time'] ?? ''), 0, 5);
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) return ['ok' => false, 'message' => 'Give a start time.'];
        if (!empty($propose['allowed_start']) && $time < $propose['allowed_start']) {
            return ['ok' => false, 'message' => 'That is still before ' . date('g:i a', strtotime('2000-01-01 ' . $propose['allowed_start'])) . '.'];
        }
        $len = (!empty($propose['start']) && !empty($propose['end'])) ? max(15, (int)((strtotime('2000-01-01 ' . $propose['end']) - strtotime('2000-01-01 ' . $propose['start'])) / 60)) : 60;
        $end = DispatchRules::endTime($time, $len);
        $this->db->prepare("UPDATE job_visits SET scheduled_time_start = ?, scheduled_time_end = ? WHERE id = ? AND status = 'scheduled'")
            ->execute([$time . ':00', $end . ':00', $visitId]);
        return $this->close($sug, 'accepted', ['choice' => 'retime', 'time' => $time, 'was' => $propose['start'] ?? null], $actorId,
            'Moved to ' . date('g:i a', strtotime('2000-01-01 ' . $time)) . '.');
    }

    private function westEnd(array $sug, array $propose, string $choice, int $actorId): array
    {
        if ($choice !== 'note') return ['ok' => false, 'message' => 'Add the crew note, or leave it?'];
        $this->visitNote((int)$sug['subject_id'], ($propose['area'] ?? 'West End') . ': no ' . (!empty($propose['gas_only']) ? 'gas ' : '')
            . 'leaf blowers. Plan rake or vacuum time. (From Otto)', $actorId);
        return $this->close($sug, 'accepted', ['choice' => 'note'], $actorId, 'Note added to the visit for the crew.');
    }

    private function maintenance(array $sug, array $propose, string $choice, int $actorId): array
    {
        require_once __DIR__ . '/EquipmentService.php';
        $eq = new EquipmentService($this->db);
        $item = null;
        foreach ($eq->items() as $it) if ((int)$it['id'] === (int)$sug['subject_id']) $item = $it;
        if (!$item) return ['ok' => false, 'message' => 'That item is gone.'];
        $due = (array)($propose['due'] ?? []);
        if ($choice === 'task') {
            $taskId = $eq->createTask($item, $due, $actorId);
            return $this->close($sug, 'accepted', ['choice' => 'task', 'task_id' => $taskId], $actorId, 'Task made. Log the service on the equipment page when it is done.');
        }
        if ($choice === 'done') {
            foreach ($due as $d) $eq->logService((int)$item['id'], (string)$d['task'], null, $actorId, null, 'Marked done from Otto');
            return $this->close($sug, 'accepted', ['choice' => 'done'], $actorId, 'Logged as done today.');
        }
        return ['ok' => false, 'message' => 'Make a task, mark it done, or leave it?'];
    }

    /** Crew training: every "Do it" is a task for the person who clicked (Tim). Nothing reaches the crew. */
    private function training(array $sug, array $propose, string $choice, int $actorId): array
    {
        require_once __DIR__ . '/TrainingService.php';
        $tr = new TrainingService($this->db);
        $kind = (string)$sug['kind'];
        if ($kind === 'training_gap' && $choice === 'shadow') {
            $this->updateLesson('training', 'shadow:' . (int)$sug['user_id'] . ':' . ($propose['service'] ?? ''),
                fn(array $v) => ['since' => date('Y-m-d')] + $v);
            return $this->close($sug, 'accepted', ['choice' => 'shadow'], $actorId,
                "Got it. I won't flag " . ($propose['who'] ?? 'them') . ' on stops shared with someone certified.');
        }
        if ($choice !== 'task') return ['ok' => false, 'message' => 'Make the task, or leave it?'];
        $who = (string)($propose['who'] ?? 'Someone');
        switch ($kind) {
            case 'training_gap':
                $need = $propose['course'] ?? ('Tier ' . ($propose['tier'] ?? ''));
                $title = "Training: {$who} needs {$need} before " . date('D M j', strtotime((string)($propose['first'] ?? 'today')));
                $body = "{$who} is scheduled on " . strtolower((string)($propose['service_label'] ?? 'this service')) . " work without {$need}.\nBook the module on the Certification page, or pair them with someone certified.";
                $due = (string)($propose['first'] ?? date('Y-m-d'));
                break;
            case 'training_quality':
                $title = "Training: go over " . strtolower((string)($propose['service_label'] ?? 'the work')) . " with {$who}";
                $body = 'Repeat problems: ' . ($propose['evidence'] ?? '') . '.' . (!empty($propose['course']) ? "\nThe {$propose['course']} module covers it." : '');
                $due = date('Y-m-d', strtotime('+7 days'));
                break;
            case 'training_topic':
                $title = 'Crew meeting: ' . ($propose['category'] ?? 'quiz topic');
                $body = "Questions most of the crew got wrong:\n• " . implode("\n• ", (array)($propose['questions'] ?? []));
                $due = date('Y-m-d', strtotime('next monday'));
                break;
            default: // safety_refresher
                $title = "Safety: {$who} — " . ($propose['course'] ?? 'safety') . ' ' . (($propose['state'] ?? '') === 'due' ? 'refresher' : 'certification');
                $body = ($propose['state'] ?? '') === 'missing' ? "{$who} runs power equipment without this cert."
                      : "{$who}'s cert " . (($propose['state'] ?? '') === 'expired' ? 'lapsed' : 'runs out') . ' on ' . ($propose['expires'] ?? '?') . '.';
                $exp = (string)($propose['expires'] ?? '');
                $due = ($propose['state'] ?? '') === 'due' && $exp !== '' ? $exp : date('Y-m-d', strtotime('+7 days'));
        }
        $taskId = $tr->taskForOwner($title, $body, $due, $actorId);
        $outcome = ['choice' => 'task', 'task_id' => $taskId];
        if ($kind === 'safety_refresher') $outcome['before_expiry'] = ($propose['state'] ?? '') === 'due';
        return $this->close($sug, 'accepted', $outcome, $actorId, 'Task made for you, due ' . date('M j', strtotime($due)) . '.');
    }

    // ── Work with nothing scheduled / real visit lengths ────────────────────

    /** Add the visit (existing plan or one-off) as done with the observed times, then invoice / link; or "not work". */
    private function unscheduled(array $sug, array $in, int $actorId): array
    {
        require_once __DIR__ . '/UnscheduledWorkService.php';
        $r = (new UnscheduledWorkService($this->db))->apply($sug, $in, $actorId);
        if (!$r['ok']) return $r;
        $out = $this->close($sug, $r['status'], $r['outcome'], $actorId, $r['message']);
        if (!empty($r['redirect'])) $out['redirect'] = $r['redirect'];
        return $out;
    }

    /** apply → the plan's length (logged, undoable); keep → the plan stays as it is. */
    private function duration(array $sug, array $propose, string $choice, array $in, int $actorId): array
    {
        if ($choice === 'keep') return $this->close($sug, 'dismissed', ['choice' => 'keep', 'proposed' => $propose['minutes'] ?? null], $actorId, 'Kept the plan length.');
        if ($choice !== 'apply') return ['ok' => false, 'message' => 'Apply the new length, or keep the plan?'];
        require_once __DIR__ . '/VisitDurationService.php';
        $minutes = (int)($in['minutes'] ?? 0);
        $basis = $propose + ['proposed' => $propose['minutes'] ?? null];
        $r = (new VisitDurationService($this->db))->apply((int)$sug['subject_id'], $minutes, $actorId, $basis, null, false);
        if (!$r['ok']) return $r;
        $status = isset($propose['minutes']) ? OttoRules::keptOrEdited((int)$propose['minutes'], $minutes) : 'edited';
        $out = $this->close($sug, $status, ['choice' => 'apply', 'minutes' => $minutes, 'was' => $r['old'], 'change_id' => $r['change_id'], 'suggested' => $propose['minutes'] ?? null], $actorId, $r['message']);
        $out['change_id'] = $r['change_id'];
        return $out;
    }

    private function visitNote(int $visitId, string $text, int $actorId): void
    {
        $this->db->prepare("INSERT INTO visit_notes (visit_id, note_type, content, is_visible_to_customer, created_by) VALUES (?, 'internal', ?, 0, ?)")
            ->execute([$visitId, $text, $actorId ?: null]);
    }

    // ── Shared ──────────────────────────────────────────────────────────────

    private function close(array $sug, string $status, array $outcome, int $actorId, string $message): array
    {
        $this->db->prepare("
            UPDATE otto_suggestions SET status = ?, outcome_json = ?, decided_by = ?, decided_at = NOW()
            WHERE id = ? AND status = 'open'
        ")->execute([$status, json_encode($outcome), $actorId ?: null, (int)$sug['id']]);
        return ['ok' => true, 'message' => $message, 'status' => $status];
    }

    public function learnWeather(string $serviceType, string $choice, ?int $rain): void
    {
        $key = strtolower(trim($serviceType)) ?: 'visit';
        $this->updateLesson('weather', $key, fn(array $v) => OttoRules::addWeatherDecision($v, $choice, $rain));
    }

    private function learnDuration(int $planId, int $minutes): void
    {
        if ($planId <= 0) return;
        $this->updateLesson('duration', 'plan:' . $planId, function (array $v) use ($minutes) {
            $v['minutes'] = array_slice(array_merge((array)($v['minutes'] ?? []), [$minutes]), -10);
            return $v;
        });
    }

    public function updateLesson(string $scope, string $key, callable $change): void
    {
        try {
            $s = $this->db->prepare("SELECT value_json FROM otto_lessons WHERE scope = ? AND scope_key = ?");
            $s->execute([$scope, $key]);
            $v = json_decode((string)$s->fetchColumn(), true) ?: [];
            $this->db->prepare("
                INSERT INTO otto_lessons (scope, scope_key, value_json) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE value_json = VALUES(value_json)
            ")->execute([$scope, mb_substr($key, 0, 120), json_encode($change($v))]);
        } catch (Throwable $e) {
            error_log('Otto lesson: ' . $e->getMessage());
        }
    }

    private function recalcTimesheet(int $userId, string $when): void
    {
        try {
            require_once APP_ROOT . '/Modules/Team/Services/TimeclockFunctions.php';
            ensureTimesheetExists($userId, date('Y-m-d', strtotime($when)));
        } catch (Throwable $e) {
            error_log('Otto timesheet recalc: ' . $e->getMessage());
        }
    }

    private function loadWeatherModules(): void
    {
        require_once CRM_INCLUDES . '/weather-service.php';
        require_once CRM_ROOT . '/modules/weather/weather-rules.php';
        require_once CRM_ROOT . '/modules/weather/weather-evaluator.php';
        require_once CRM_ROOT . '/modules/scheduling/rescheduler.php';
        require_once CRM_ROOT . '/modules/snapshots/snapshot-manager.php';
    }
}
