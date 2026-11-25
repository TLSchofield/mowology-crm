<?php
/**
 * OttoContractLogService — Otto logs contract-site work by himself (owner, 2026-10-07).
 *
 * When the unscheduled-work detector flags a property covered by an ACTIVE contract on that date,
 * Otto does not ask. He logs a completed job_visit with the observed times (the UNEXPLAINED remainder
 * only — a neighbour's scheduled visit keeps its part) on the contract's plan for that property, and
 * marks it covered by the contract. Non-contract properties keep asking.
 *
 * How contract visits stay out of per-visit billing (verified 2026-10-08):
 *   - The source of truth is ContractService::isPlanContractBilled(): job_plans.contract_id → contracts,
 *     status 'active' AND billing_cycle <> 'per_visit'. InvoiceFromVisitService::createFromVisit(),
 *     pow-actions.php and the schedule sheet refuse a per-visit invoice for such a plan (CONTRACT_BILLED).
 *   - contract_billing.php bills the contract a FLAT monthly amount; it never counts or links visits, so
 *     one more logged visit cannot change the contract invoice.
 *   - NOT every list honours it: the dashboard "Unbilled visits" count (CrmFunctions), ContactTeamService,
 *     invoices/create.php?visit_id= and the mobile invoice-create-send only look at
 *     job_visits.is_invoiced / invoice_id. So the logged visit is ALSO set is_invoiced = 1 and linked to
 *     that month's contract invoice when one exists — no list sees it as unbilled, no path can invoice it.
 * Refused (Otto asks instead): the plan is not contract-billed (per-visit cycle / contract not active),
 * no active plan on the contract, nobody to assign it to, or an invoice not from the contract already
 * exists for the property within 21 days (the owner may have billed it by hand).
 *
 * Nothing is sent: UnscheduledWorkService::completeQuietly() (no job-complete email, review request or push).
 * Every action is a row in otto_auto_visits (migration 1267), one per property per day; Undo cancels
 * the visit.
 *
 * Visits timed on the wrong day (a timer today on a visit of this property scheduled within ±7 days —
 * UnscheduledWorkService::scheduledIds) are MOVED to the day they were done (kind 'move', one row per
 * visit per day; Undo moves it back) instead of logging a duplicate.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/UnscheduledWorkService.php';

class OttoContractLogService
{
    private PDO $db;
    private UnscheduledWorkService $uw;
    private string $today;
    private array $memo = [];

    public function __construct(PDO $db, ?UnscheduledWorkService $uw = null, ?string $today = null)
    {
        $this->db = $db;
        $this->today = $today ?? date('Y-m-d');
        $this->uw = $uw ?? new UnscheduledWorkService($db, $this->today);
    }

    public function ready(): bool
    {
        if (isset($this->memo['ready'])) return $this->memo['ready'];
        try {
            return $this->memo['ready'] = $this->db->query("SELECT 1 FROM otto_auto_visits LIMIT 1") !== false;
        } catch (Throwable $e) {
            return $this->memo['ready'] = false;
        }
    }

    /** The otto_auto_visits row for a property and day (any status), or null. */
    public function row(int $propertyId, string $day): ?array
    {
        if (!$this->ready()) return null;
        $s = $this->db->prepare("SELECT * FROM otto_auto_visits WHERE property_id = ? AND day = ? AND kind <> 'move' ORDER BY id LIMIT 1");
        $s->execute([$propertyId, $day]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Would Otto log this one? (read-only — the dry run shows it)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param array $c a DESCRIBED candidate (UnscheduledWorkService::describe): property_id, date, start, end,
     *                 minutes, crew_people, invoices
     * @return array|null null = not a contract site (ask as usual); else
     *   {log: bool, reason: string, contract: {id, number, title, billing_cycle}, plan: ?{id, number, title},
     *    crew_id: ?int, invoice_id: ?int, invoice_number: ?string}
     */
    public function preview(array $c): ?array
    {
        $pid = (int)$c['property_id'];
        $date = (string)$c['date'];
        $ctr = $this->contractFor($pid, $date);
        if (!$ctr) return null;
        $out = ['log' => false, 'reason' => '', 'contract' => ['id' => (int)$ctr['id'], 'number' => (string)$ctr['contract_number'],
            'title' => (string)($ctr['title'] ?? ''), 'billing_cycle' => (string)$ctr['billing_cycle']],
            'plan' => null, 'crew_id' => null, 'invoice_id' => null, 'invoice_number' => null];
        if ($ctr['billing_cycle'] === 'per_visit') return ['reason' => 'contract ' . $ctr['contract_number'] . ' bills per visit — asking'] + $out;
        $plan = $this->choosePlan((int)$ctr['id'], $pid, $date, (int)$c['minutes']);
        if (!$plan) return ['reason' => 'contract ' . $ctr['contract_number'] . ' has no active plan — asking'] + $out;
        $out['plan'] = ['id' => (int)$plan['id'], 'number' => (string)$plan['plan_number'], 'title' => (string)($plan['title'] ?: $plan['service_type'])];
        if (!$this->contractBilled((int)$plan['id'])) return ['reason' => 'plan ' . $plan['plan_number'] . ' is not billed through the contract — asking'] + $out;
        foreach ((array)($c['invoices'] ?? []) as $inv) {
            if ((int)($inv['contract_id'] ?? 0) !== (int)$ctr['id'] && !$this->isContractInvoice((int)$inv['id'], (int)$ctr['id'])) {
                return ['reason' => 'invoice ' . $inv['number'] . ' already exists for this property — could double-bill, asking'] + $out;
            }
        }
        $crew = null;
        foreach ((array)($c['crew_people'] ?? []) as $p) if (empty($p['truck'])) { $crew = (int)$p['id']; break; }
        $crew ??= $plan['default_crew_id'] !== null ? (int)$plan['default_crew_id'] : null;
        if (!$crew) return ['reason' => 'nobody to assign the visit to — asking'] + $out;
        $inv = $this->monthInvoice((int)$ctr['id'], $date);
        return ['log' => true, 'reason' => 'covered by contract ' . $ctr['contract_number'], 'crew_id' => $crew,
            'invoice_id' => $inv ? (int)$inv['id'] : null, 'invoice_number' => $inv ? (string)$inv['invoice_number'] : null] + $out;
    }

    /** The active contract covering a property on a date: by its own property, or by an active plan on it. */
    public function contractFor(int $propertyId, string $date): ?array
    {
        $k = "c{$propertyId}:{$date}";
        if (array_key_exists($k, $this->memo)) return $this->memo[$k];
        try {
            $s = $this->db->prepare("
                SELECT c.id, c.contract_number, c.title, c.status, c.billing_cycle, c.property_id
                FROM contracts c
                WHERE c.status = 'active' AND c.start_date <= ? AND (c.end_date IS NULL OR c.end_date >= ?)
                  AND (c.property_id = ? OR EXISTS (SELECT 1 FROM job_plans jp WHERE jp.contract_id = c.id AND jp.property_id = ? AND jp.status = 'active'))
                ORDER BY (c.property_id = ?) DESC, c.id
                LIMIT 1
            ");
            $s->execute([$date, $date, $propertyId, $propertyId, $propertyId]);
            return $this->memo[$k] = ($s->fetch(PDO::FETCH_ASSOC) ?: null);
        } catch (Throwable $e) {
            return $this->memo[$k] = null;   // no contracts table
        }
    }

    /**
     * The contract's plan for this property: the one whose schedule fits the day (recurring on that weekday),
     * then the closest length, else the contract's main plan (its lowest-numbered active plan).
     */
    public function choosePlan(int $contractId, int $propertyId, string $date, int $minutes): ?array
    {
        $s = $this->db->prepare("
            SELECT id, plan_number, title, service_type, property_id, is_recurring, recurrence_day_of_week,
                   estimated_duration_minutes, default_crew_id
            FROM job_plans WHERE contract_id = ? AND status = 'active' ORDER BY id
        ");
        $s->execute([$contractId]);
        $plans = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$plans) return null;
        $here = array_values(array_filter($plans, fn($p) => (int)$p['property_id'] === $propertyId));
        if (!$here) return $plans[0];
        $dow = (int)date('w', strtotime($date));
        usort($here, function ($a, $b) use ($dow, $minutes) {
            $score = fn($p) => [
                (int)$p['is_recurring'] === 1 && $p['recurrence_day_of_week'] !== null && (int)$p['recurrence_day_of_week'] === $dow ? 1 : 0,
                -abs((int)($p['estimated_duration_minutes'] ?? 0) - $minutes),
                -(int)$p['id'],
            ];
            return $score($b) <=> $score($a);
        });
        return $here[0];
    }

    private function contractBilled(int $planId): bool
    {
        require_once dirname(__DIR__, 2) . '/Contracts/Services/ContractService.php';
        return (new ContractService($this->db))->isPlanContractBilled($planId);
    }

    private function isContractInvoice(int $invoiceId, int $contractId): bool
    {
        try {
            $s = $this->db->prepare("SELECT contract_id FROM invoices WHERE id = ?");
            $s->execute([$invoiceId]);
            return (int)$s->fetchColumn() === $contractId;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** The contract's invoice for the month of $date (the monthly cron issues it on the 1st). */
    private function monthInvoice(int $contractId, string $date): ?array
    {
        try {
            $s = $this->db->prepare("
                SELECT id, invoice_number FROM invoices
                WHERE contract_id = ? AND issue_date BETWEEN ? AND ? AND status NOT IN ('void', 'cancelled')
                ORDER BY id LIMIT 1
            ");
            $s->execute([$contractId, date('Y-m-01', strtotime($date)), date('Y-m-t', strtotime($date))]);
            return $s->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Log / undo
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Log one flagged, described candidate. Idempotent: a property/day already in otto_auto_visits (logged,
     * undone or failed) is never logged again.
     * @return array{done: bool, asked: bool, reason: string, visit_id?: int}
     *   done = logged now or before (don't ask); asked = not a contract site or refused (ask as usual)
     */
    public function log(array $c, int $actorId = 0): array
    {
        if (!$this->ready()) return ['done' => false, 'asked' => true, 'reason' => 'migration 1267 not run'];
        $pid = (int)$c['property_id'];
        $day = (string)$c['date'];
        $row = $this->row($pid, $day);
        if ($row) {
            return in_array($row['status'], ['logged', 'logging'], true)
                ? ['done' => true, 'asked' => false, 'reason' => 'already logged']
                : ['done' => false, 'asked' => true, 'reason' => 'logged before and ' . $row['status'] . ' — asking'];
        }
        $p = $this->preview($c);
        if ($p === null) return ['done' => false, 'asked' => true, 'reason' => 'not a contract site'];
        if (!$p['log']) return ['done' => false, 'asked' => true, 'reason' => $p['reason']];

        $start = date('H:i', (int)$c['start']);
        $end = date('H:i', (int)$c['end']);
        // The unique key (property_id, day) is the lock: a second pass racing this one fails here.
        try {
            $this->db->prepare("
                INSERT INTO otto_auto_visits (property_id, day, kind, contract_id, plan_id, start_time, end_time, minutes, status, reason, evidence)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'logging', ?, ?)
            ")->execute([$pid, $day, (string)($c['kind'] ?? 'unscheduled'), $p['contract']['id'], $p['plan']['id'], $start . ':00', $end . ':00',
                (int)$c['minutes'], mb_substr($p['reason'], 0, 255), mb_substr((string)($c['evidence'] ?? ''), 0, 4000)]);
        } catch (PDOException $e) {
            return ['done' => true, 'asked' => false, 'reason' => 'already logged'];
        }
        $rowId = (int)$this->db->lastInsertId();
        try {
            $r = $this->uw->addVisitFor($p['plan']['id'], $day, $p['crew_id']);
            if (empty($r['success'])) throw new RuntimeException(implode(' ', $r['errors'] ?? ['could not add the visit']));
            $visitId = (int)$r['visit_id'];
            $people = count(array_filter((array)($c['crew_people'] ?? []), fn($x) => empty($x['truck']))) ?: null;
            if (!$this->uw->completeQuietly($visitId, $day, $start, $end, $people, (string)($c['basis'] ?? ''), $actorId)) {
                throw new RuntimeException('visit ' . $visitId . ' was already started or done');
            }
            $this->db->prepare("
                UPDATE job_visits
                SET is_invoiced = 1, invoice_id = ?,
                    completion_notes = CONCAT(COALESCE(completion_notes, ''), ?)
                WHERE id = ?
            ")->execute([$p['invoice_id'], ' Covered by contract ' . $p['contract']['number'] . ' — not billed per visit (logged by Otto).', $visitId]);
            $this->db->prepare("UPDATE otto_auto_visits SET status = 'logged', visit_id = ?, invoice_id = ? WHERE id = ?")
                ->execute([$visitId, $p['invoice_id'], $rowId]);
            return ['done' => true, 'asked' => false, 'reason' => $p['reason'], 'visit_id' => $visitId];
        } catch (Throwable $e) {
            error_log('Otto contract log: ' . $e->getMessage());
            $this->db->prepare("UPDATE otto_auto_visits SET status = 'failed', reason = ? WHERE id = ?")
                ->execute([mb_substr('failed: ' . $e->getMessage(), 0, 255), $rowId]);
            return ['done' => false, 'asked' => true, 'reason' => 'could not log it (' . $e->getMessage() . ') — asking'];
        }
    }

    /**
     * A contract-site visit timed on another day than it was scheduled: move it to the day it was done.
     * Refused (null) when the property isn't a contract site that day or the visit's plan isn't contract-billed
     * — then Otto asks ('visit_date' item). @param array $v UnscheduledWorkService::movedVisits() row
     * @return array{done: bool, reason: string}|null
     */
    public function move(array $v, string $day): ?array
    {
        if (!$this->ready()) return null;
        $ctr = $this->contractFor((int)$v['property_id'], $day);
        if (!$ctr || $ctr['billing_cycle'] === 'per_visit' || !$this->contractBilled((int)$v['plan_id'])) return null;
        $q = $this->db->prepare("SELECT status FROM otto_auto_visits WHERE kind = 'move' AND source_visit_id = ? AND day = ?");
        $q->execute([(int)$v['visit_id'], $day]);
        $st = $q->fetchColumn();
        if ($st !== false) return ['done' => $st === 'logged', 'reason' => 'moved before (' . $st . ')'];
        try {
            $this->db->prepare("
                INSERT INTO otto_auto_visits (property_id, day, kind, source_visit_id, moved_from, contract_id, plan_id, visit_id, minutes, status, reason)
                VALUES (?, ?, 'move', ?, ?, ?, ?, ?, ?, 'logging', ?)
            ")->execute([(int)$v['property_id'], $day, (int)$v['visit_id'], $v['moved_from'], (int)$ctr['id'], (int)$v['plan_id'], (int)$v['visit_id'],
                $v['timer_min'], 'timed ' . $day . ', was scheduled ' . $v['moved_from']]);
        } catch (PDOException $e) {
            return ['done' => true, 'reason' => 'already moved'];
        }
        $id = (int)$this->db->lastInsertId();
        $ok = $this->uw->moveVisitDate((int)$v['visit_id'], $day);
        $this->db->prepare("UPDATE otto_auto_visits SET status = ? WHERE id = ?")->execute([$ok ? 'logged' : 'failed', $id]);
        return ['done' => $ok, 'reason' => $ok ? 'moved to the day it was done (contract ' . $ctr['contract_number'] . ')' : 'could not move it'];
    }

    /** Undo: a logged visit is cancelled (kept, not deleted), a moved one goes back to its date; Otto then asks. */
    public function undo(int $id, int $actorId): array
    {
        $s = $this->db->prepare("SELECT * FROM otto_auto_visits WHERE id = ?");
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['ok' => false, 'message' => 'That entry is gone.'];
        if ($row['status'] !== 'logged') return ['ok' => false, 'message' => 'Only a logged visit can be undone (this one is ' . $row['status'] . ').'];
        if ($row['kind'] === 'move') {
            if (!$this->uw->moveVisitDate((int)$row['visit_id'], (string)$row['moved_from'])) return ['ok' => false, 'message' => 'The visit was changed since — open it to check.'];
            $this->db->prepare("UPDATE otto_auto_visits SET status = 'undone', undone_by = ?, undone_at = ? WHERE id = ?")
                ->execute([$actorId ?: null, date('Y-m-d H:i:s'), $id]);
            return ['ok' => true, 'message' => 'Moved back to ' . date('D M j', strtotime((string)$row['moved_from'])) . '. I\'ll ask about it instead.'];
        }
        $u = $this->db->prepare("
            UPDATE job_visits SET status = 'cancelled', is_invoiced = 0, invoice_id = NULL,
                completion_notes = CONCAT(COALESCE(completion_notes, ''), ' [Undone from Otto]')
            WHERE id = ? AND status = 'completed'
        ");
        $u->execute([(int)$row['visit_id']]);
        if ($u->rowCount() === 0) return ['ok' => false, 'message' => 'The visit was changed since — open it to check.'];
        try {
            $st = $this->db->prepare("SELECT stop_id FROM job_visits WHERE id = ?");
            $st->execute([(int)$row['visit_id']]);
            $stopId = (int)$st->fetchColumn();
            if ($stopId > 0 && class_exists('VisitLifecycleService')) VisitLifecycleService::propagateStopStatus($stopId);
        } catch (Throwable $e) { /* the stop status catches up on the next change */ }
        $this->db->prepare("UPDATE otto_auto_visits SET status = 'undone', undone_by = ?, undone_at = ? WHERE id = ?")
            ->execute([$actorId ?: null, date('Y-m-d H:i:s'), $id]);
        return ['ok' => true, 'message' => 'Undone — the visit is cancelled. I\'ll ask about that day instead.'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The daily pass and what the card says
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Log every contract-site candidate for ended days: yesterday, or the last LOOKBACK_DAYS the first time
     * (nothing in otto_auto_visits yet). Days are taken from the evidence cache (computed + stored when missing).
     * @return array{days: int, logged: int, asked: int, minutes: int}
     */
    public function dailyPass(?int $days = null): array
    {
        $out = ['days' => 0, 'logged' => 0, 'asked' => 0, 'minutes' => 0, 'moved' => 0];
        if (!$this->ready()) return $out;
        if ($days === null) {
            $any = (int)$this->db->query("SELECT COUNT(*) FROM otto_auto_visits")->fetchColumn();
            $days = $any > 0 ? 1 : UnscheduledWorkRules::LOOKBACK_DAYS;
        }
        $notWork = $this->uw->notWork();
        for ($i = 1; $i <= $days; $i++) {
            $d = date('Y-m-d', strtotime($this->today . " -{$i} days"));
            $ev = $this->uw->evidence($d, true, true);
            $cands = UnscheduledWorkRules::candidates($d, $ev['truck'], $ev['crew'], $this->uw->scheduledIds($d), $notWork);
            foreach ($this->uw->describePublic(array_values(array_filter($cands, fn($c) => $c['flag']))) as $c) {
                $r = $this->log($c);
                if (!empty($r['visit_id'])) { $out['logged']++; $out['minutes'] += (int)$c['minutes']; }
                elseif ($r['asked']) $out['asked']++;
            }
            foreach ($this->uw->movedVisits($d) as $v) {
                $m = $this->move($v, $d);
                if ($m && $m['done'] && $m['reason'] !== 'already moved' && strpos($m['reason'], 'moved before') !== 0) $out['moved']++;
            }
            $out['days']++;
        }
        return $out;
    }

    /** "Logged 4 contract visits yesterday (6 h 10 min)" — null when nothing recent. */
    public function summary(): ?array
    {
        if (!$this->ready()) return null;
        try {
            $y = date('Y-m-d', strtotime($this->today . ' -1 day'));
            $s = $this->db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(minutes), 0) AS m FROM otto_auto_visits WHERE status = 'logged' AND kind <> 'move' AND day = ?");
            $s->execute([$y]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if ((int)$r['n'] > 0) return ['n' => (int)$r['n'], 'minutes' => (int)$r['m'], 'when' => 'yesterday',
                'line' => 'Logged ' . (int)$r['n'] . ' contract visit' . ((int)$r['n'] === 1 ? '' : 's') . ' yesterday (' . UnscheduledWorkRules::hours((int)$r['m']) . ')'];
            $s = $this->db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(minutes), 0) AS m FROM otto_auto_visits WHERE status = 'logged' AND kind <> 'move' AND day >= ?");
            $s->execute([date('Y-m-d', strtotime($this->today . ' -' . UnscheduledWorkRules::LOOKBACK_DAYS . ' days'))]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if ((int)$r['n'] > 0) return ['n' => (int)$r['n'], 'minutes' => (int)$r['m'], 'when' => 'last ' . UnscheduledWorkRules::LOOKBACK_DAYS . ' days',
                'line' => 'Logged ' . (int)$r['n'] . ' contract visit' . ((int)$r['n'] === 1 ? '' : 's') . ' in the last ' . UnscheduledWorkRules::LOOKBACK_DAYS . ' days (' . UnscheduledWorkRules::hours((int)$r['m']) . ')'];
        } catch (Throwable $e) { /* not migrated */ }
        return null;
    }

    /** Recent rows for the review page, newest first. */
    public function recent(int $days = 14): array
    {
        if (!$this->ready()) return [];
        $s = $this->db->prepare("
            SELECT a.*, p.address, c.contract_number, jp.plan_number, i.invoice_number
            FROM otto_auto_visits a
            LEFT JOIN properties p ON p.id = a.property_id
            LEFT JOIN contracts c ON c.id = a.contract_id
            LEFT JOIN job_plans jp ON jp.id = a.plan_id
            LEFT JOIN invoices i ON i.id = a.invoice_id
            WHERE a.day >= ?
            ORDER BY a.day DESC, a.id DESC
        ");
        $s->execute([date('Y-m-d', strtotime($this->today . " -{$days} days"))]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
}
