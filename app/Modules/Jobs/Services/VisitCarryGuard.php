<?php
declare(strict_types=1);

require_once __DIR__ . '/PlanHelpersService.php';

/**
 * Keeps finished work on the day it was done when a visit or stop is moved.
 *
 * Why (2026-10-27): a recurring visit was photographed Before and After one week but never
 * marked complete. The stop was then rescheduled to the next week; the reschedule carried the
 * still-"scheduled" visit row with it, so the crew app showed last week's photos on this week's
 * card and the new week's proof would have landed on the same visit.
 *
 * Rule: a visit that already carries proof of work — an After photo, or a completed_at time — is
 * never moved to another date. It is completed where it is (completed_at = the After photo's
 * time when there is one), and a fresh scheduled visit for the same plan and crew is created on
 * the target date instead. Every move path (stop reschedule from phone/desktop, a single visit's
 * move, Otto's unscheduled-work mover) asks this guard first.
 *
 * Deliberately NOT routed through VisitLifecycleService::updateVisitStatus(): that stamps
 * completed_at = NOW(), which would record the work as done on the day of the move, and it
 * fires the completion push / bundle counter for what is a bookkeeping correction.
 */
final class VisitCarryGuard
{
    public const NOTE = 'Completed by the reschedule guard: the After photo was already taken, so the work was done on this day. A fresh visit was created on the new date.';

    /** Statuses a move would otherwise carry forward. */
    private const CARRYABLE = ['scheduled', 'in_progress'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * When the visit already has proof of work, complete it in place and create a fresh
     * scheduled visit on $toDate / $toStopId for the same plan and crew.
     *
     * @return int|null The fresh visit's id, or null when the visit should simply be moved.
     */
    public function splitIfFinished(int $visitId, string $toDate, ?int $toStopId): ?int
    {
        $visit = $this->carryableVisit($visitId, self::CARRYABLE);
        if (!$visit) {
            return null;
        }
        $proofAt = $this->proofOfWork($visitId, $visit);
        if ($proofAt === null) {
            return null;
        }
        // The two single-visit movers run outside a transaction: completing the old visit and
        // creating the new one must land together or not at all (Codex, 2026-10-27).
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            $this->completeInPlace($visit, $proofAt);
            $freshId = $this->freshVisit($visit, $toDate, $toStopId);
            if ($ownTx) {
                $this->db->commit();
            }
            return $freshId;
        } catch (Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Stop-level: complete every carryable visit on $stopId that has proof of work, so the
     * reschedule leaves it behind. Returns what to recreate once the new stop is known.
     *
     * @return array<int,array> The settled visit rows (plan_id, assigned_crew_id, scheduled_time_start …)
     */
    public function settleStop(int $stopId): array
    {
        // Only 'scheduled' here: the stop reschedule never carries an in-progress visit, so there
        // is nothing to settle for one (it stays on its stop as before).
        $stmt = $this->db->prepare("SELECT * FROM job_visits WHERE stop_id = ? AND status = 'scheduled'");
        $stmt->execute([$stopId]);

        $settled = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $visit) {
            $proofAt = $this->proofOfWork((int)$visit['id'], $visit);
            if ($proofAt === null) {
                continue;
            }
            $this->completeInPlace($visit, $proofAt);
            $settled[] = $visit;
        }
        return $settled;
    }

    /**
     * Stop-level, second half: one fresh scheduled visit per settled visit on the new day.
     *
     * @param array<int,array> $settled From settleStop()
     * @return int[] Fresh visit ids
     */
    public function recreateOn(array $settled, string $date, ?int $stopId): array
    {
        $ids = [];
        foreach ($settled as $visit) {
            $ids[] = $this->freshVisit($visit, $date, $stopId);
        }
        return $ids;
    }

    /**
     * Proof the work was done: the latest After photo's capture time, else completed_at.
     * Null when there is neither — the visit is genuinely still to do.
     */
    public function proofOfWork(int $visitId, ?array $visit = null): ?string
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(ma.captured_at, ma.created_at)
            FROM media_links ml
            JOIN media_assets ma ON ma.id = ml.media_id
            WHERE ml.context_type = 'job_visit' AND ml.context_id = ? AND ml.category = 'after'
            ORDER BY ma.id DESC LIMIT 1
        ");
        $stmt->execute([$visitId]);
        $afterAt = $stmt->fetchColumn();
        if ($afterAt !== false && $afterAt !== null && (string)$afterAt !== '') {
            return (string)$afterAt;
        }

        if ($visit === null) {
            $visit = $this->carryableVisit($visitId);
        }
        $completedAt = $visit['completed_at'] ?? null;
        return ($completedAt !== null && (string)$completedAt !== '') ? (string)$completedAt : null;
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function carryableVisit(int $visitId, array $statuses = self::CARRYABLE): ?array
    {
        $in   = implode(',', array_fill(0, count($statuses), '?'));
        $stmt = $this->db->prepare("SELECT * FROM job_visits WHERE id = ? AND status IN ($in)");
        $stmt->execute(array_merge([$visitId], $statuses));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Completed on its own day: completed_at keeps the real time; the note says why. */
    private function completeInPlace(array $visit, string $proofAt): void
    {
        $this->db->prepare("
            UPDATE job_visits
            SET status = 'completed',
                status_changed_at = NOW(),
                completed_at = COALESCE(completed_at, ?),
                completion_notes = COALESCE(NULLIF(completion_notes, ''), ?),
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$proofAt, self::NOTE, (int)$visit['id']]);

        if (function_exists('logActivityExtended')) {
            try {
                logActivityExtended(
                    0,
                    'Visit completed',
                    'Visit ' . (string)($visit['visit_number'] ?? $visit['id']) . ' completed by the reschedule guard (After photo at ' . $proofAt . ')',
                    null, null, null, null,
                    (int)$visit['plan_id'],
                    (int)$visit['id']
                );
            } catch (Throwable $e) {
                error_log('VisitCarryGuard activity log: ' . $e->getMessage());
            }
        }
    }

    /** Next scheduled visit for the same plan and crew on $date; same time of day as the one it replaces. */
    private function freshVisit(array $visit, string $date, ?int $stopId): int
    {
        $planId = (int)$visit['plan_id'];

        $plan = $this->db->prepare("SELECT plan_number FROM job_plans WHERE id = ?");
        $plan->execute([$planId]);
        $planNumber = (string)($plan->fetchColumn() ?: ('PLAN-' . $planId));

        $crewId = isset($visit['assigned_crew_id']) && $visit['assigned_crew_id'] !== null ? (int)$visit['assigned_crew_id'] : null;

        // MAX(sequence_index) + 1 is plan-wide, so it is unique within one run; two concurrent
        // splits for the same plan could still pick the same number (uk_plan_date_seq), so one
        // duplicate-key hit recomputes and retries (Codex, 2026-10-27).
        for ($attempt = 0; ; $attempt++) {
            $seqStmt = $this->db->prepare("SELECT COALESCE(MAX(sequence_index), 0) + 1 FROM job_visits WHERE plan_id = ?");
            $seqStmt->execute([$planId]);
            $seq = (int)$seqStmt->fetchColumn();
            try {
                $insert = $this->db->prepare("
                    INSERT INTO job_visits (visit_number, plan_id, stop_id, scheduled_date, scheduled_time_start, sequence_index, assigned_crew_id, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'scheduled')
                ");
                $insert->execute([
                    PlanHelpersService::generateVisitNumber($planNumber, $seq),
                    $planId, $stopId, $date, $visit['scheduled_time_start'] ?? null, $seq, $crewId,
                ]);
                return (int)$this->db->lastInsertId();
            } catch (PDOException $e) {
                // MySQL: "1062 Duplicate entry"; SQLite: "UNIQUE constraint failed". Anything else is a real error.
                $duplicate = stripos($e->getMessage(), 'Duplicate entry') !== false || stripos($e->getMessage(), 'UNIQUE constraint') !== false;
                if (!$duplicate || $attempt >= 2) {
                    throw $e;
                }
            }
        }
    }
}
