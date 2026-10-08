<?php
/**
 * VisitDurationService — Otto: real lawn-cut lengths against the plan, and how much is timed.
 *
 *   review()            every recurring lawn plan: samples, medians (crew- and person-minutes),
 *                       what was dropped and why, and the proposed length. Read-only.
 *   coverage()          of the visits completed in the last 60 days, how many have a job timer.
 *   items()             Otto's suggestions (kind 'duration'), one per plan with a proposal.
 *   apply() / undo()    job_plans.estimated_duration_minutes, only on the owner's click, each change
 *                       kept in otto_duration_changes (migration 1266) so it can be undone.
 *   applyAllConfident() every proposal with ≥ 5 samples and a stable spread, as one batch.
 *
 * The plan length is what the day's capacity reads (StopRescheduleService::capacityWarning,
 * DayBattleCard, the schedule's job minutes), so applying here is how the planner learns.
 * Otto never changes a length by himself.
 *
 * Note: DwellTimeService::updatePlanDwellAverage() ALSO writes estimated_duration_minutes (from GPS
 * dwell) when /crm/api/backfill-gps-dwell.php is run. That path is silent; this one is not.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/VisitDurationRules.php';

class VisitDurationService
{
    /** How far back to look for the last SAMPLES timed visits. */
    public const LOOKBACK_DAYS = 240;

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
            return $this->db->query("SELECT 1 FROM otto_duration_changes LIMIT 1") !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Loaders (overridable in tests)
    // ─────────────────────────────────────────────────────────────────────────

    /** Active recurring plans (lawn only unless $all). */
    protected function loadPlans(bool $all): array
    {
        $rows = $this->db->query("
            SELECT jp.id, jp.plan_number, jp.title, jp.service_type, jp.estimated_duration_minutes, jp.default_crew_size,
                   jp.property_id, p.address
            FROM job_plans jp JOIN properties p ON p.id = jp.property_id
            WHERE jp.status = 'active' AND jp.is_recurring = 1
            ORDER BY p.address
        ")->fetchAll(PDO::FETCH_ASSOC);
        return $all ? $rows : array_values(array_filter($rows, fn($r) => VisitDurationRules::isLawn((string)$r['service_type'], (string)$r['title'])));
    }

    /**
     * Completed visits of these plans, most recent first, each with its timer entries.
     * @return array<int, list<array{visit_id: int, date: string, departure: bool, entries: list<array>}>> by plan id
     */
    protected function loadVisits(array $planIds): array
    {
        if (!$planIds) return [];
        $in = implode(',', array_fill(0, count($planIds), '?'));
        $s = $this->db->prepare("
            SELECT v.id, v.plan_id, v.scheduled_date, (v.gps_departure_lat IS NOT NULL) AS departure
            FROM job_visits v
            WHERE v.plan_id IN ({$in}) AND v.status = 'completed' AND v.scheduled_date >= ?
            ORDER BY v.scheduled_date DESC, v.id DESC
        ");
        $s->execute(array_merge(array_values($planIds), [date('Y-m-d', strtotime($this->today . ' -' . self::LOOKBACK_DAYS . ' days'))]));
        $visits = $s->fetchAll(PDO::FETCH_ASSOC);
        $entries = $this->loadEntries(array_map(fn($v) => (int)$v['id'], $visits));
        $out = [];
        foreach ($visits as $v) {
            $out[(int)$v['plan_id']][] = ['visit_id' => (int)$v['id'], 'date' => (string)$v['scheduled_date'],
                'departure' => (bool)$v['departure'], 'entries' => $entries[(int)$v['id']] ?? []];
        }
        return $out;
    }

    /** [visit_id => entries] in the shape VisitDurationRules::visitMinutes() takes. */
    protected function loadEntries(array $visitIds): array
    {
        $out = [];
        foreach (array_chunk($visitIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $s = $this->db->prepare("
                SELECT jte.visit_id, jte.user_id, jte.start_time, jte.end_time, jte.duration_minutes,
                       COALESCE(jte.auto_stopped, 0) AS auto_stopped, (jte.end_lat IS NOT NULL) AS end_gps,
                       COALESCE(u.device_type, 'personal') AS device_type
                FROM job_time_entries jte LEFT JOIN users u ON u.id = jte.user_id
                WHERE jte.visit_id IN ({$in}) AND jte.status IN ('completed', 'edited')
            ");
            $s->execute($chunk);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['visit_id']][] = [
                    'user_id' => (int)$r['user_id'], 'truck' => $r['device_type'] === 'truck',
                    'start' => (int)strtotime((string)$r['start_time']), 'end' => $r['end_time'] ? (int)strtotime((string)$r['end_time']) : null,
                    'duration' => $r['duration_minutes'] !== null ? (int)$r['duration_minutes'] : null,
                    'auto_stopped' => (int)$r['auto_stopped'] === 1, 'end_gps' => (int)$r['end_gps'] === 1,
                ];
            }
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Review
    // ─────────────────────────────────────────────────────────────────────────

    /** @return list<array> one row per plan: plan facts + VisitDurationRules::summarise() + line/detail */
    public function review(bool $all = false): array
    {
        $plans = $this->loadPlans($all);
        $visits = $this->loadVisits(array_map(fn($p) => (int)$p['id'], $plans));
        $out = [];
        foreach ($plans as $p) {
            $rows = [];
            foreach ($visits[(int)$p['id']] ?? [] as $v) {
                $m = VisitDurationRules::visitMinutes($v['entries'], $v['departure']);
                $rows[] = ['visit_id' => $v['visit_id'], 'date' => $v['date']] + $m;
            }
            $planned = $p['estimated_duration_minutes'] !== null ? (int)$p['estimated_duration_minutes'] : null;
            $s = VisitDurationRules::summarise($rows, $planned);
            $street = self::street((string)$p['address']);
            $out[] = [
                'plan_id' => (int)$p['id'], 'plan_number' => (string)$p['plan_number'], 'title' => (string)$p['title'],
                'service_type' => (string)$p['service_type'], 'property_id' => (int)$p['property_id'], 'street' => $street,
                'completed' => count($rows), 'timed' => count(array_filter($rows, fn($r) => $r['excluded'] !== 'untimed')),
                'last_date' => $s['used'][0]['date'] ?? null,
                'line' => $s['median_crew'] !== null ? VisitDurationRules::line($street, $s) : $street . ': no timed visits yet.',
                'detail' => VisitDurationRules::detail($s),
            ] + $s;
        }
        usort($out, fn($a, $b) => [$b['proposed'] !== null, $b['confident'], abs((int)$b['change'])] <=> [$a['proposed'] !== null, $a['confident'], abs((int)$a['change'])]);
        return $out;
    }

    /**
     * Of the visits completed in the last COVERAGE_DAYS days, how many have a job timer.
     * @return array{days: int, completed: int, timed: int, manual: int, untimed: int, pct: ?int}
     */
    public function coverage(): array
    {
        $out = ['days' => VisitDurationRules::COVERAGE_DAYS, 'completed' => 0, 'timed' => 0, 'manual' => 0, 'untimed' => 0, 'pct' => null];
        try {
            $s = $this->db->prepare("
                SELECT COUNT(*) AS completed,
                       SUM(EXISTS (SELECT 1 FROM job_time_entries jte WHERE jte.visit_id = v.id AND jte.status <> 'void')) AS timed,
                       SUM(NOT EXISTS (SELECT 1 FROM job_time_entries jte WHERE jte.visit_id = v.id AND jte.status <> 'void')
                           AND COALESCE(v.actual_duration_minutes, 0) > 0) AS manual
                FROM job_visits v
                WHERE v.status = 'completed' AND v.scheduled_date BETWEEN ? AND ?
            ");
            $s->execute([date('Y-m-d', strtotime($this->today . ' -' . VisitDurationRules::COVERAGE_DAYS . ' days')), $this->today]);
            $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['completed'] = (int)($r['completed'] ?? 0);
            $out['timed'] = (int)($r['timed'] ?? 0);
            $out['manual'] = (int)($r['manual'] ?? 0);
            $out['untimed'] = max(0, $out['completed'] - $out['timed'] - $out['manual']);
            $out['pct'] = $out['completed'] > 0 ? (int)round($out['timed'] / $out['completed'] * 100) : null;
        } catch (Throwable $e) {
            error_log('Otto coverage: ' . $e->getMessage());
        }
        return $out;
    }

    /** Otto's suggestions: one per plan with a proposal. */
    public function items(): array
    {
        if (!$this->ready()) return [];
        $out = [];
        foreach ($this->review(false) as $r) {
            if ($r['proposed'] === null || $r['last_date'] === null) continue;
            $out[] = [
                'key' => 'otto:duration:' . $r['plan_id'], 'kind' => 'duration', 'subject_type' => 'plan', 'subject_id' => $r['plan_id'],
                'for_date' => $r['last_date'], 'user_id' => null, 'priority' => 3,
                'text' => $r['line'] . ($r['confident'] ? '' : ' (' . ($r['reason'] === 'spread' ? 'times vary a lot' : 'only ' . $r['samples'] . ' timed') . ')'),
                'detail' => $r['detail'], 'url' => '/crm/ops/otto-review.php?view=durations#plan-' . $r['plan_id'],
                'value' => $r['proposed'], 'since' => $r['last_date'], 'service_type' => $r['service_type'],
                'propose' => ['minutes' => $r['proposed'], 'planned' => $r['planned'], 'median_crew' => $r['median_crew'],
                    'median_person' => $r['median_person'], 'samples' => $r['samples'], 'confident' => $r['confident'], 'plan_id' => $r['plan_id']],
            ];
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Apply / undo
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Set one plan's length. Closes the plan's open Otto suggestion when $closeSuggestion.
     * @return array{ok: bool, message: string, change_id?: int, old?: ?int, new?: int}
     */
    public function apply(int $planId, int $minutes, int $actorId, array $basis = [], ?string $batch = null, bool $closeSuggestion = true): array
    {
        if ($minutes < 5 || $minutes > 600) return ['ok' => false, 'message' => 'A visit length is 5 to 600 minutes.'];
        $s = $this->db->prepare("SELECT estimated_duration_minutes FROM job_plans WHERE id = ?");
        $s->execute([$planId]);
        $old = $s->fetchColumn();
        if ($old === false) return ['ok' => false, 'message' => 'That plan is gone.'];
        $old = $old === null ? null : (int)$old;
        if ($old === $minutes) return ['ok' => false, 'message' => 'It is already ' . $minutes . ' min.'];
        $now = date('Y-m-d H:i:s');
        $this->db->prepare("UPDATE job_plans SET estimated_duration_minutes = ? WHERE id = ?")->execute([$minutes, $planId]);
        $this->db->prepare("
            INSERT INTO otto_duration_changes (plan_id, old_minutes, new_minutes, median_crew, median_person, samples, confident, batch_key, applied_by, applied_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$planId, $old, $minutes, $basis['median_crew'] ?? null, $basis['median_person'] ?? null, $basis['samples'] ?? null,
            !empty($basis['confident']) ? 1 : 0, $batch, $actorId ?: null, $now]);
        $id = (int)$this->db->lastInsertId();
        if ($closeSuggestion) $this->closeSuggestion($planId, isset($basis['proposed']) && (int)$basis['proposed'] !== $minutes ? 'edited' : 'accepted',
            ['choice' => 'apply', 'minutes' => $minutes, 'was' => $old, 'change_id' => $id, 'batch' => $batch], $actorId);
        return ['ok' => true, 'message' => 'Plan set to ' . $minutes . ' min' . ($old !== null ? ' (was ' . $old . ')' : '') . '.', 'change_id' => $id, 'old' => $old, 'new' => $minutes];
    }

    /** Every confident proposal, as one batch. @return array{ok: bool, message: string, batch: string, applied: list<array>} */
    public function applyAllConfident(int $actorId): array
    {
        $batch = 'b' . date('YmdHis') . '-' . $actorId;
        $done = [];
        foreach ($this->review(false) as $r) {
            if ($r['proposed'] === null || !$r['confident']) continue;
            $res = $this->apply($r['plan_id'], $r['proposed'], $actorId, $r, $batch);
            if ($res['ok']) $done[] = ['plan_id' => $r['plan_id'], 'street' => $r['street'], 'old' => $res['old'], 'new' => $res['new'], 'change_id' => $res['change_id']];
        }
        return ['ok' => true, 'batch' => $batch, 'applied' => $done,
            'message' => $done ? 'Updated ' . count($done) . ' plan' . (count($done) === 1 ? '' : 's') . '. Undo puts them all back.' : 'Nothing confident to apply.'];
    }

    /**
     * Put a change back — only if nobody has changed the plan since.
     * @return array{ok: bool, message: string}
     */
    public function undo(int $changeId, int $actorId): array
    {
        $s = $this->db->prepare("SELECT * FROM otto_duration_changes WHERE id = ?");
        $s->execute([$changeId]);
        $c = $s->fetch(PDO::FETCH_ASSOC);
        if (!$c) return ['ok' => false, 'message' => 'That change is gone.'];
        if (!empty($c['undone_at'])) return ['ok' => false, 'message' => 'Already undone.'];
        $p = $this->db->prepare("SELECT estimated_duration_minutes FROM job_plans WHERE id = ?");
        $p->execute([(int)$c['plan_id']]);
        $now = $p->fetchColumn();
        if ($now === false) return ['ok' => false, 'message' => 'That plan is gone.'];
        if ((int)$now !== (int)$c['new_minutes']) return ['ok' => false, 'message' => 'The plan was changed again since (now ' . (int)$now . ' min) — left as it is.'];
        $this->db->prepare("UPDATE job_plans SET estimated_duration_minutes = ? WHERE id = ?")->execute([$c['old_minutes'], (int)$c['plan_id']]);
        $this->db->prepare("UPDATE otto_duration_changes SET undone_at = ?, undone_by = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $actorId ?: null, $changeId]);
        return ['ok' => true, 'message' => 'Back to ' . ($c['old_minutes'] === null ? 'no length' : (int)$c['old_minutes'] . ' min') . '.'];
    }

    /** Undo every change in a batch that can still be undone. */
    public function undoBatch(string $batch, int $actorId): array
    {
        $s = $this->db->prepare("SELECT id FROM otto_duration_changes WHERE batch_key = ? AND undone_at IS NULL ORDER BY id DESC");
        $s->execute([$batch]);
        $ok = 0;
        $skipped = 0;
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $this->undo((int)$id, $actorId)['ok'] ? $ok++ : $skipped++;
        }
        return ['ok' => $ok > 0, 'message' => 'Put back ' . $ok . ' plan' . ($ok === 1 ? '' : 's') . ($skipped ? ', ' . $skipped . ' changed since and left' : '') . '.'];
    }

    /** Recent changes for the review page. */
    public function history(int $limit = 30): array
    {
        try {
            $s = $this->db->prepare("
                SELECT c.*, p.address FROM otto_duration_changes c
                JOIN job_plans jp ON jp.id = c.plan_id JOIN properties p ON p.id = jp.property_id
                ORDER BY c.id DESC LIMIT " . max(1, min(200, $limit))
            );
            $s->execute();
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function closeSuggestion(int $planId, string $status, array $outcome, int $actorId): void
    {
        try {
            $this->db->prepare("
                UPDATE otto_suggestions SET status = ?, outcome_json = ?, decided_by = ?, decided_at = ?
                WHERE kind = 'duration' AND subject_type = 'plan' AND subject_id = ? AND status = 'open'
            ")->execute([$status, json_encode($outcome), $actorId ?: null, date('Y-m-d H:i:s'), $planId]);
        } catch (Throwable $e) { /* suggestions table not migrated — the change still stands */ }
    }

    public static function street(string $address): string
    {
        $a = trim(explode(',', $address)[0] ?? '');
        return $a !== '' ? $a : 'the property';
    }
}
