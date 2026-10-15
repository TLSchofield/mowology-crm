<?php
/**
 * SupplierJobSuggestService — Otto suggests the job for a material receipt from a supplier stop
 * (plan docs/plans/2026-10-13-otto-supplier-stops.md step 4, migration 1359).
 *
 * SHADOW MODE: Otto only suggests, on the receipt editor, and one tap confirms. Nothing here sets a
 * job by itself. Every outcome (confirmed / changed / rejected) is kept in
 * supplier_stop_job_suggestions so his hit rate is scored before any rule could be switched on
 * (the head-learning rule: N >= SHADOW_MIN at >= SHADOW_RATE; switching on is Tim's call, not built).
 *
 * How he picks, for a receipt matched to a stop (SupplierStopService) with no job yet:
 *   1. who      the crew whose phones (or the truck tablet) made the stop; with only the truck, who
 *               was on the clock.
 *   2. where    the visit their GPS was tagged to before and after the stop (within LOOK_S); with no
 *               tags, their scheduled visits that day. Every plan at those properties is a candidate
 *               (a property can have the regular visits AND a one-off job — 6015 Tisdall has both).
 *   3. which    strongest first: the plan's accepted quote lists the material on the receipt; the last
 *               material receipt for that property (within RECENT_DAYS, before this one) was put on
 *               this plan; the crew was there both before and after; GPS-tagged rather than only
 *               scheduled; a one-off job rather than the regular visits.
 * He always says why. A person's answer is a lesson: a Penny lesson (vendor + material → job type)
 * on every decision, an Otto lesson (stop → job) when the answer differs from his pick
 * (head_lessons only keeps answers that differ from what the head said).
 *
 * Office only (admins / managers): stops come from crew GPS. No namespace: require_once and `new`.
 */
require_once __DIR__ . '/SupplierStopService.php';
require_once dirname(__DIR__, 2) . '/Expenses/Services/TripAttributionService.php';

class SupplierJobSuggestService
{
    public const LOOK_S = 14400;
    public const RECENT_DAYS = 60;
    public const SHADOW_MIN = 10;
    public const SHADOW_RATE = 0.95;
    public const W_QUOTE = 100, W_LEARNED = 50, W_RECENT = 15, W_BOTH_SIDES = 20, W_GPS = 10, W_VISIT = 5, W_ONE_OFF = 3;

    private PDO $db;
    private SupplierStopService $stops;

    public function __construct(PDO $db, ?SupplierStopService $stops = null)
    {
        $this->db = $db;
        $this->stops = $stops ?? new SupplierStopService($db);
    }

    public function ready(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM supplier_stop_job_suggestions LIMIT 0");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** Is this a material purchase (not fuel)? Category Materials, or material words on a supplier receipt. */
    public static function isMaterial(?string $category, string $kind, array $lineNames): bool
    {
        if ($category === 'Fuel' || $kind === 'fuel') return false;
        if ($category === 'Materials') return true;
        return (bool)TripAttributionService::materialWords(implode(' ', $lineNames));
    }

    /**
     * The best plan among candidates, with its score and the facts behind it.
     * @param list<array{plan_id: int, plan_number: string, street: string, is_recurring: bool, quote_lines: list,
     *                   before: bool, after: bool, gps: bool, visit: bool, learned: ?array, rule?: ?array}> $cands
     *        learned = the last material receipt at this property went on this plan (a hint, W_RECENT);
     *        rule = Tim has put this vendor + material on this kind of job twice running (HeadLessons::rule, W_LEARNED).
     * @param list<array{name: string, product_id?: ?int}> $lines the receipt's line items
     * @return ?array the winning candidate + score + quote_words
     */
    public static function rank(array $cands, array $lines): ?array
    {
        $best = null;
        foreach ($cands as $c) {
            // The materials both the receipt and the quote name (or the line's own name on a product match).
            $quoteWords = [];
            foreach ($c['quote_lines'] ?? [] as $q) {
                if (!TripAttributionService::isTripLine($q)) $quoteWords = array_merge($quoteWords, TripAttributionService::materialWords(TripAttributionService::quoteLabel($q)));
            }
            $words = [];
            foreach ($lines as $l) {
                if (!TripAttributionService::isJobLine($l, $c['quote_lines'] ?? [])) continue;
                $both = array_intersect(TripAttributionService::materialWords((string)($l['name'] ?? '')), $quoteWords);
                $words = array_merge($words, $both ?: [strtolower(trim((string)$l['name']))]);
            }
            $score = ($words ? self::W_QUOTE : 0) + (!empty($c['rule']) ? self::W_LEARNED : 0) + (!empty($c['learned']) ? self::W_RECENT : 0)
                   + (!empty($c['before']) && !empty($c['after']) ? self::W_BOTH_SIDES : 0)
                   + (!empty($c['gps']) ? self::W_GPS : 0) + (!empty($c['visit']) ? self::W_VISIT : 0)
                   + (empty($c['is_recurring']) ? self::W_ONE_OFF : 0);
            $c['score'] = $score;
            $c['quote_words'] = array_values(array_unique($words));
            if ($best === null || $score > $best['score']
                || ($score === $best['score'] && [!empty($c['visit']), (int)$c['plan_id']] > [!empty($best['visit']), (int)$best['plan_id']])) {
                $best = $c;
            }
        }
        return $best;
    }

    /** Otto's reason in one sentence. */
    public static function reason(array $best, string $who, string $place, int $others): string
    {
        $who = $who !== '' ? $who : 'The crew';
        $where = (string)$best['street'];
        $was = strpos($who, '+') !== false ? 'were' : 'was';
        if (!empty($best['before']) && !empty($best['after'])) $bits = ["{$who} {$was} at {$where} before and after the {$place} stop"];
        elseif (!empty($best['before'])) $bits = ["{$who} {$was} at {$where} before the {$place} stop"];
        elseif (!empty($best['after'])) $bits = ["{$who} went to {$where} after the {$place} stop"];
        else $bits = ["{$where} was on {$who}'s schedule that day"];
        if (!empty($best['quote_words'])) $bits[] = 'its quote lists ' . implode(', ', array_slice($best['quote_words'], 0, 3));
        if (!empty($best['rule'])) {
            $bits[] = 'you have put ' . $best['rule']['what'] . ' on ' . $best['rule']['value'] . ' jobs ' . (int)$best['rule']['streak'] . ' times running';
        }
        if (!empty($best['learned'])) {
            $l = $best['learned'];
            $bits[] = 'the last material receipt for ' . $where . ' (#' . (int)$l['id'] . ', ' . date('M j', strtotime((string)$l['expense_date'])) . ') went on this job';
        }
        if ($others > 0 && empty($best['quote_words']) && empty($best['learned']) && empty($best['rule']) && empty($best['is_recurring'])) {
            $bits[] = 'it is the one-off job there, not the regular visits';
        }
        return ucfirst(implode('; ', $bits)) . '.';
    }

    /** "6015 Tisdall Street, Vancouver BC" → "6015 Tisdall Street". */
    public static function street(string $address): string
    {
        $a = trim(explode(',', $address)[0] ?? '');
        return $a !== '' ? $a : 'the property';
    }

    /** Shadow score from decided suggestions' statuses. */
    public static function score(array $statusCounts): array
    {
        $right = (int)($statusCounts['confirmed'] ?? 0);
        $total = $right + (int)($statusCounts['changed'] ?? 0) + (int)($statusCounts['rejected'] ?? 0);
        $meets = $total >= self::SHADOW_MIN && $right / max(1, $total) >= self::SHADOW_RATE;
        return ['right' => $right, 'total' => $total, 'meets_bar' => $meets,
                'line' => $total === 0 ? 'Otto is on trial: no answers yet (he needs ' . self::SHADOW_MIN . ' at 95%).'
                    : "Otto's job picks so far: {$right} of {$total} right" . ($meets ? ' — good enough to switch on, if you want.' : ' (on trial until ' . self::SHADOW_MIN . ' at 95%).')];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Reading
    // ─────────────────────────────────────────────────────────────────────────

    public function receipt(int $expenseId): ?array
    {
        $s = $this->db->prepare("
            SELECT e.id, e.expense_date, e.vendor_id, COALESCE(v.name, e.vendor_name_raw) AS vendor, e.accounting_category, e.job_id,
                   e.status, e.created_by, e.forwarded_to_accounting
            FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id WHERE e.id = ?
        ");
        $s->execute([$expenseId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        try {
            $l = $this->db->prepare("SELECT id, product_id, name, line_total FROM expense_line_items WHERE expense_id = ? ORDER BY sort_order, id");
            $l->execute([$expenseId]);
            $r['lines'] = $l->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $r['lines'] = [];
        }
        return $r;
    }

    /** The visit a crew's GPS was tagged to, nearest before $t (dir -1) or after $t (dir 1). */
    private function taggedVisit(array $crew, int $from, int $to, bool $latest): ?int
    {
        if (!$crew) return null;
        $in = implode(',', array_fill(0, count($crew), '?'));
        $s = $this->db->prepare("
            SELECT visit_id FROM crew_location_history
            WHERE crew_id IN ({$in}) AND visit_id IS NOT NULL AND `timestamp` BETWEEN ? AND ?
            ORDER BY `timestamp` " . ($latest ? 'DESC' : 'ASC') . " LIMIT 1
        ");
        $s->execute(array_merge($crew, [date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $to)]));
        $v = $s->fetchColumn();
        return $v ? (int)$v : null;
    }

    /**
     * The truck trail around the stop: the client property it stopped at last before and first after
     * (within LOOK_S) — [before property id, after property id].
     */
    private function truckProperties(string $date, int $start, int $end): array
    {
        $seg = new TripSegmentService($this->db);
        $p = $seg->pings($date);
        if (!$p) return [null, null];
        $before = null; $after = null;
        foreach (TripSegmentService::segments($p, $seg->propertiesNear($p), $seg->places()) as $s) {
            if ($s['type'] !== 'stop' || ($s['label']['type'] ?? '') !== 'property') continue;
            if ($s['end'] <= $start && $s['end'] >= $start - self::LOOK_S) $before = (int)$s['label']['id'];
            if ($after === null && $s['start'] >= $end && $s['start'] <= $end + self::LOOK_S) $after = (int)$s['label']['id'];
        }
        return [$before, $after];
    }

    /** That day's live visits at these properties: visit id → [plan_id, property_id]. */
    private function visitsAt(array $props, string $date): array
    {
        $props = array_values(array_unique(array_filter($props)));
        if (!$props) return [];
        $in = implode(',', array_fill(0, count($props), '?'));
        $s = $this->db->prepare("SELECT jv.id FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id
                                 WHERE jp.property_id IN ({$in}) AND jv.scheduled_date = ? AND jv.status NOT IN ('cancelled', 'skipped')");
        $s->execute(array_merge($props, [$date]));
        return $this->visitPlans(array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** Scheduled visits that day for the crew (skipped ones weren't done). */
    private function scheduledVisits(array $crew, string $date): array
    {
        if (!$crew) return [];
        $in = implode(',', array_fill(0, count($crew), '?'));
        $sql = "SELECT id FROM job_visits WHERE scheduled_date = ? AND status NOT IN ('cancelled', 'skipped') AND (assigned_crew_id IN ({$in})";
        try {
            $this->db->query("SELECT 1 FROM visit_crew_assignments LIMIT 0");
            $sql .= " OR id IN (SELECT visit_id FROM visit_crew_assignments WHERE user_id IN ({$in}))";
            $args = array_merge([$date], $crew, $crew);
        } catch (Throwable $e) {
            $args = array_merge([$date], $crew);
        }
        $s = $this->db->prepare($sql . ")");
        $s->execute($args);
        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    }

    /** visit id → [plan_id, property_id] */
    private function visitPlans(array $visitIds): array
    {
        $visitIds = array_values(array_unique(array_filter($visitIds)));
        if (!$visitIds) return [];
        $in = implode(',', array_fill(0, count($visitIds), '?'));
        $s = $this->db->prepare("SELECT jv.id, jv.plan_id, jp.property_id FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id WHERE jv.id IN ({$in})");
        $s->execute($visitIds);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = ['plan_id' => (int)$r['plan_id'], 'property_id' => (int)$r['property_id']];
        return $out;
    }

    /**
     * Otto's pick for a receipt, or null. $ignoreJob replays a receipt that already has a job
     * (the proof: would he have picked what Tim picked?).
     * @return ?array{plan_id, plan_number, street, reason, stop_id, stop_line, score, candidates}
     */
    public function suggest(int $expenseId, bool $ignoreJob = false, ?array $stop = null): ?array
    {
        $rc = $this->receipt($expenseId);
        if (!$rc || $rc['status'] === 'rejected') return null;
        if (!$ignoreJob && !empty($rc['job_id'])) return null;
        $stop ??= $this->stops->stopForExpense($expenseId);
        if (!$stop) return null;
        if (!self::isMaterial($rc['accounting_category'], (string)$stop['kind'], array_column($rc['lines'], 'name'))) return null;
        $date = (string)$rc['expense_date'];
        $start = (int)$stop['start'];
        $end = (int)$stop['end'];

        $crew = array_values(array_filter(array_map('intval', explode(',', (string)($stop['user_ids'] ?? '')))));
        if (!$crew) {
            $crew = (new TripSegmentService($this->db))->clockedIn(date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $end));
        }
        // Where they were: the visit their phones were tagged to before / after the stop; else where
        // the truck stopped at a client property before / after it; else their schedule that day.
        $before = $this->taggedVisit($crew, $start - self::LOOK_S, $start, true);
        $after = $this->taggedVisit($crew, $end, $end + self::LOOK_S, false);
        $vp = $this->visitPlans(array_values(array_filter([$before, $after])));
        $beforeProp = $before && isset($vp[$before]) ? $vp[$before]['property_id'] : null;
        $afterProp = $after && isset($vp[$after]) ? $vp[$after]['property_id'] : null;
        $gps = (bool)$vp;
        $props = array_values(array_unique(array_column($vp, 'property_id')));
        if (!$props && in_array($stop['seen_by'] ?? '', ['truck', 'both'], true)) {
            [$beforeProp, $afterProp] = $this->truckProperties($date, $start, $end);
            $props = array_values(array_unique(array_filter([$beforeProp, $afterProp])));
            $gps = (bool)$props;
            $vp = $this->visitsAt($props, $date);
        }
        if (!$props) {
            $vp = $this->visitPlans($this->scheduledVisits($crew, $date));
            $props = array_values(array_unique(array_column($vp, 'property_id')));
        }
        if (!$props) return null;
        $visitPlanIds = array_column($vp, 'plan_id') ?: [0];

        $in = implode(',', array_fill(0, count($props), '?'));
        $s = $this->db->prepare("
            SELECT jp.id, jp.plan_number, jp.property_id, jp.is_recurring, jp.service_type, jp.quote_id, jp.status, q.status AS quote_status,
                   COALESCE(p.address, p.property_name, '') AS address
            FROM job_plans jp LEFT JOIN quotes q ON q.id = jp.quote_id LEFT JOIN properties p ON p.id = jp.property_id
            WHERE jp.property_id IN ({$in}) AND (jp.status <> 'cancelled' OR jp.id IN (" . implode(',', array_map('intval', $visitPlanIds)) . "))
        ");
        $s->execute($props);
        $plans = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$plans) return null;

        $attr = new TripAttributionService($this->db);
        $learnedBy = $this->lastMaterialReceipts($props, $date, $expenseId);
        $rule = $this->materialRule($rc);
        $cands = [];
        foreach ($plans as $p) {
            $prop = (int)$p['property_id'];
            $l = $learnedBy[$prop] ?? null;
            $cands[] = [
                'plan_id' => (int)$p['id'], 'plan_number' => (string)$p['plan_number'], 'street' => self::street((string)$p['address']),
                'is_recurring' => (int)$p['is_recurring'] === 1,
                'quote_lines' => $p['quote_id'] && $p['quote_status'] === 'accepted' ? $attr->quoteLines((int)$p['quote_id']) : [],
                'before' => $beforeProp === $prop, 'after' => $afterProp === $prop, 'gps' => $gps,
                'visit' => in_array((int)$p['id'], $visitPlanIds, true),
                'learned' => $l && (int)$l['job_id'] === (int)$p['id'] ? $l : null,
                'rule' => $rule && strcasecmp((string)$p['service_type'], $rule['value']) === 0 ? $rule : null,
            ];
        }
        $best = self::rank($cands, $rc['lines']);
        if (!$best) return null;
        $who = trim(preg_replace('/\s*\(on the clock\)$/', '', (string)($stop['people'] ?? '')));
        return [
            'plan_id' => $best['plan_id'], 'plan_number' => $best['plan_number'], 'street' => $best['street'],
            'reason' => self::reason($best, $who, (string)$stop['place_name'], count($cands) - 1),
            'stop_id' => isset($stop['id']) ? (int)$stop['id'] : null, 'stop_line' => SupplierStopService::line($stop),
            'score' => $best['score'], 'candidates' => count($cands),
        ];
    }

    /** Per property: the most recent material receipt (before $date, within RECENT_DAYS) that has a job at that property. */
    private function lastMaterialReceipts(array $props, string $date, int $exceptId): array
    {
        $in = implode(',', array_fill(0, count($props), '?'));
        $s = $this->db->prepare("
            SELECT e.id, e.expense_date, e.job_id, jp.property_id
            FROM expenses e JOIN job_plans jp ON jp.id = e.job_id
            WHERE jp.property_id IN ({$in}) AND e.expense_date < ? AND e.expense_date >= ? AND e.id <> ?
              AND e.status <> 'rejected' AND e.accounting_category = 'Materials'
            ORDER BY e.expense_date DESC, e.id DESC
        ");
        $s->execute(array_merge($props, [$date, date('Y-m-d', strtotime($date . ' -' . self::RECENT_DAYS . ' days')), $exceptId]));
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['property_id']] ??= $r;
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The receipt editor
    // ─────────────────────────────────────────────────────────────────────────

    /** Store (or refresh) an open suggestion; decided ones are never touched. */
    public function remember(int $expenseId, array $sug): void
    {
        if (!$this->ready()) return;
        $this->db->prepare("
            INSERT INTO supplier_stop_job_suggestions (expense_id, stop_id, stop_line, suggested_plan_id, reason, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'open', ?)
            ON DUPLICATE KEY UPDATE
              stop_id = IF(status = 'open', VALUES(stop_id), stop_id), stop_line = IF(status = 'open', VALUES(stop_line), stop_line),
              suggested_plan_id = IF(status = 'open', VALUES(suggested_plan_id), suggested_plan_id),
              reason = IF(status = 'open', VALUES(reason), reason), updated_at = IF(status = 'open', VALUES(created_at), updated_at)
        ")->execute([$expenseId, $sug['stop_id'], mb_substr((string)$sug['stop_line'], 0, 255), $sug['plan_id'], mb_substr((string)$sug['reason'], 0, 500),
                     date('Y-m-d H:i:s')]);
    }

    private function row(int $expenseId): ?array
    {
        if (!$this->ready()) return null;
        $s = $this->db->prepare("SELECT * FROM supplier_stop_job_suggestions WHERE expense_id = ?");
        $s->execute([$expenseId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function shadowScore(): array
    {
        $counts = [];
        if ($this->ready()) {
            $counts = $this->db->query("SELECT status, COUNT(*) FROM supplier_stop_job_suggestions WHERE status IN ('confirmed', 'changed', 'rejected') GROUP BY status")
                               ->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        return self::score($counts);
    }

    /** What the receipt editor shows: the stop, Otto's pick (open receipts with no job), his score. */
    public function forEditor(int $expenseId): array
    {
        $stop = $this->stops->stopForExpense($expenseId);
        if (!$stop) return ['stop' => null, 'suggestion' => null];
        $out = ['stop' => ['line' => $stop['line'], 'basis' => $stop['basis'], 'minutes' => (int)$stop['minutes'], 'seen_by' => $stop['seen_by']],
                'suggestion' => null];
        $this->reconcile([$expenseId]);
        $row = $this->row($expenseId);
        if ($row && $row['status'] !== 'open') return $out;
        $sug = $this->suggest($expenseId, false, $stop);
        if (!$sug) return $out;
        $this->remember($expenseId, $sug);
        $out['suggestion'] = $sug + ['shadow' => $this->shadowScore()];
        return $out;
    }

    /**
     * Tim's one tap: put the receipt on $planId (Otto's pick, or another) — through the expense gate,
     * only while the receipt has no job.
     */
    public function confirm(int $expenseId, int $planId, array $user, $gate = null): array
    {
        $rc = $this->receipt($expenseId);
        if (!$rc) return ['ok' => false, 'message' => 'Receipt not found'];
        $p = $this->db->prepare("SELECT id, plan_number, service_type FROM job_plans WHERE id = ?");
        $p->execute([$planId]);
        $plan = $p->fetch(PDO::FETCH_ASSOC);
        if (!$plan) return ['ok' => false, 'message' => 'That job is gone'];
        $row = $this->row($expenseId);
        require_once dirname(__DIR__, 2) . '/Expenses/Services/ExpenseGate.php';
        $gate ??= new ExpenseGate($this->db);
        $res = $gate->apply($expenseId, ['job_id' => $planId], ExpenseGate::actor($user), 'otto_stop_suggestion',
                            ['only_if' => function (array $e) { return empty($e['job_id']); }]);
        if (!empty($res['noop'])) return ['ok' => false, 'message' => 'This receipt already has a job — change it in the form'];
        $uid = isset($user['id']) ? (int)$user['id'] : null;
        $suggested = $row ? (int)$row['suggested_plan_id'] : null;
        $status = $suggested === $planId ? 'confirmed' : 'changed';
        if ($row) {
            $this->db->prepare("UPDATE supplier_stop_job_suggestions SET status = ?, decided_plan_id = ?, decided_by = ?, decided_at = ? WHERE id = ? AND status = 'open'")
                     ->execute([$status, $planId, $uid, date('Y-m-d H:i:s'), (int)$row['id']]);
        }
        $this->lessons($rc, $suggested, $plan, $uid, 'otto_stop_suggestion');
        return ['ok' => true, 'status' => $status, 'message' => 'On ' . $plan['plan_number'] . '.'];
    }

    /** "Not this job" — Otto's pick was wrong; the receipt is left as it is. */
    public function reject(int $expenseId, ?int $userId): array
    {
        $row = $this->row($expenseId);
        if (!$row || $row['status'] !== 'open') return ['ok' => false, 'message' => 'Nothing to answer'];
        $this->db->prepare("UPDATE supplier_stop_job_suggestions SET status = 'rejected', decided_by = ?, decided_at = ? WHERE id = ? AND status = 'open'")
                 ->execute([$userId, date('Y-m-d H:i:s'), (int)$row['id']]);
        $rc = $this->receipt($expenseId);
        if ($rc) $this->ottoLesson($rc, $this->planNumber((int)$row['suggested_plan_id']), null, $userId, 'otto_stop_suggestion:not_this', 'Not this job');
        return ['ok' => true, 'message' => 'Noted — Otto learns from it.'];
    }

    /**
     * Open suggestions whose receipt got a job some other way (the form, Penny, the trip cron): scored
     * as confirmed when it is Otto's pick, changed otherwise; a rejected receipt makes it moot.
     */
    public function reconcile(?array $expenseIds = null): int
    {
        if (!$this->ready()) return 0;
        $sql = "SELECT s.id, s.expense_id, s.suggested_plan_id, e.job_id, e.status AS expense_status FROM supplier_stop_job_suggestions s
                JOIN expenses e ON e.id = s.expense_id WHERE s.status = 'open' AND (e.job_id IS NOT NULL OR e.status = 'rejected')";
        $args = [];
        if ($expenseIds) {
            $sql .= ' AND s.expense_id IN (' . implode(',', array_fill(0, count($expenseIds), '?')) . ')';
            $args = array_map('intval', $expenseIds);
        }
        $s = $this->db->prepare($sql);
        $s->execute($args);
        $n = 0;
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['expense_status'] === 'rejected') {
                $status = 'moot';
            } else {
                $status = (int)$r['job_id'] === (int)$r['suggested_plan_id'] ? 'confirmed' : 'changed';
            }
            $this->db->prepare("UPDATE supplier_stop_job_suggestions SET status = ?, decided_plan_id = ?, decided_at = ? WHERE id = ? AND status = 'open'")
                     ->execute([$status, $r['job_id'] ?: null, date('Y-m-d H:i:s'), (int)$r['id']]);
            if ($status !== 'moot') {
                $rc = $this->receipt((int)$r['expense_id']);
                $p = $this->db->prepare("SELECT id, plan_number, service_type FROM job_plans WHERE id = ?");
                $p->execute([(int)$r['job_id']]);
                $plan = $p->fetch(PDO::FETCH_ASSOC);
                // Only a person's choice teaches: a job the trip cron or Penny set would just teach Otto his own guesses.
                $by = $this->personWhoSetJob((int)$r['expense_id']);
                if ($rc && $plan && $by !== false) $this->lessons($rc, (int)$r['suggested_plan_id'], $plan, $by, 'otto_stop_suggestion:elsewhere');
            }
            $n++;
        }
        return $n;
    }

    /**
     * Cron: an open suggestion for every material receipt at a stop on $date with no job, and the
     * scoring of ones answered elsewhere. @return array{suggested: int, reconciled: int}
     */
    public function refreshDay(string $date): array
    {
        if (!$this->ready()) return ['suggested' => 0, 'reconciled' => 0];
        $n = 0;
        foreach ($this->stops->withReceipts($date) as $st) {
            foreach ($st['receipts'] as $rc) {
                $row = $this->row($rc['receipt_id']);
                if ($row && $row['status'] !== 'open') continue;
                $sug = $this->suggest($rc['receipt_id'], false, $st + ['line' => SupplierStopService::line($st)]);
                if ($sug) { $this->remember($rc['receipt_id'], $sug); $n++; }
            }
        }
        return ['suggested' => $n, 'reconciled' => $this->reconcile()];
    }

    /** The user who last set the receipt's job (expense_change_log), null for an unknown person; false when it wasn't a person. */
    private function personWhoSetJob(int $expenseId)
    {
        try {
            $s = $this->db->prepare("SELECT actor_kind, actor_user_id FROM expense_change_log WHERE expense_id = ? AND fields LIKE '%job_id%'
                                     ORDER BY created_at DESC, id DESC LIMIT 1");
            $s->execute([$expenseId]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return false;
        }
        if (!$row || ($row['actor_kind'] ?? '') !== 'user') return false;
        return $row['actor_user_id'] !== null ? (int)$row['actor_user_id'] : null;
    }

    private function planNumber(int $planId): ?string
    {
        if ($planId <= 0) return null;
        $s = $this->db->prepare("SELECT plan_number FROM job_plans WHERE id = ?");
        $s->execute([$planId]);
        return ($v = $s->fetchColumn()) ? (string)$v : null;
    }

    /** "LAWNBOY|bark+mulch": the vendor + the material words on the receipt (the key Penny's lessons use). */
    public static function materialKey(string $vendor, array $lineNames): string
    {
        $words = TripAttributionService::materialWords(implode(' ', $lineNames));
        return trim($vendor) . '|' . ($words ? implode('+', $words) : 'materials');
    }

    /**
     * Tim's rule for this vendor + material → job type: the same type twice running (HeadLessons::rule;
     * a newer different answer resets it, a year old fades). @return ?array{value, streak, what}
     */
    private function materialRule(array $rc): ?array
    {
        require_once APP_ROOT . '/Services/HeadLessons.php';
        $names = array_column($rc['lines'], 'name');
        $r = (new HeadLessons($this->db))->rule('penny', 'vendor_material', self::materialKey((string)$rc['vendor'], $names), 'job_type');
        if (!$r || $r['value'] === '' || $r['value'] === 'unknown') return null;
        $words = TripAttributionService::materialWords(implode(' ', $names));
        return $r + ['what' => trim((string)$rc['vendor']) . ' ' . ($words ? implode(', ', $words) : 'materials')];
    }

    /** Penny: vendor + material → job type (every decision). Otto: stop → job (every decision; said = his pick). */
    private function lessons(array $rc, ?int $suggestedPlanId, array $plan, ?int $by, string $source): void
    {
        require_once APP_ROOT . '/Services/HeadLessons.php';
        $key = self::materialKey((string)$rc['vendor'], array_column($rc['lines'], 'name'));
        $said = null;
        if ($suggestedPlanId) {
            $s = $this->db->prepare("SELECT service_type FROM job_plans WHERE id = ?");
            $s->execute([$suggestedPlanId]);
            $said = ($v = $s->fetchColumn()) !== false ? ((string)$v ?: 'unknown') : null;
        }
        HeadLessons::recordQuietly($this->db, [
            'head' => 'penny', 'subject_kind' => 'vendor_material', 'subject_key' => $key, 'field' => 'job_type',
            'said' => $said, 'correct' => (string)($plan['service_type'] ?: 'unknown'), 'source' => $source, 'agreement' => true,
            'ref_type' => 'expense', 'ref_id' => (int)$rc['id'], 'corrected_by' => $by,
        ]);
        $this->ottoLesson($rc, $suggestedPlanId ? $this->planNumber($suggestedPlanId) : null, (string)$plan['plan_number'], $by, $source, null);
    }

    private function ottoLesson(array $rc, ?string $said, ?string $correct, ?int $by, string $source, ?string $reason): void
    {
        require_once APP_ROOT . '/Services/HeadLessons.php';
        HeadLessons::recordQuietly($this->db, [
            'head' => 'otto', 'subject_kind' => 'supplier_stop', 'subject_key' => trim((string)$rc['vendor']) ?: 'unknown vendor',
            'field' => 'job', 'said' => $said, 'correct' => $correct, 'source' => $source, 'agreement' => $correct !== null,
            'ref_type' => 'expense', 'ref_id' => (int)$rc['id'], 'corrected_by' => $by, 'reason' => $reason,
        ]);
    }
}

