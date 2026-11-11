<?php
declare(strict_types=1);
/**
 * VisitTimerStopPolicy — which job timers a stop/complete request ends.
 *
 * Found 2026-10-06: the DODGE RAM truck login's GPS auto-started the Cambridge visit, so
 * the only running timer belonged to the truck. Tim tapped Mark Complete on his own phone
 * and stopJobTimer() — which only ever looked for the CALLER's timer — threw "No active
 * timer for this job", leaving the visit stuck in_progress with the truck's timer running.
 * DepartureAutoStopService reaches the same dead end by design: it ends the timer, keeps
 * the visit in_progress and asks the crew to mark it complete.
 *
 * Rule (owner decision 2026-10-06): COMPLETING a visit ends every active timer on it,
 * whoever owns it — the same thing the desktop visit completion in jobs/view.php has
 * always done. PAUSING stays strictly the caller's own timer.
 *
 * Pure: no DB. Global-namespace, no autoloader — require_once, then call statically.
 */
final class VisitTimerStopPolicy
{
    public const NO_TIMER = 'No active timer for this job';

    /** Visit statuses that may be completed with no timer at all (matches pow-actions end_visit). */
    private const COMPLETABLE_WITHOUT_TIMER = ['scheduled', 'in_progress'];

    /**
     * @param int    $userId        who is stopping
     * @param array<int, array{id:int|string, user_id:int|string}> $activeEntries
     *        the visit's active job_time_entries, newest first
     * @param bool   $completeVisit false = pause (own timer only)
     * @param string $visitStatus   job_visits.status
     * @return array{stop:int[], own:?int, complete:bool, error:?string}
     *         stop: entry ids to end (own first) · own: the caller's entry id, if any ·
     *         complete: run visit completion · error: throw this instead of doing anything
     */
    public static function decide(int $userId, array $activeEntries, bool $completeVisit, string $visitStatus): array
    {
        $own = null;
        $others = [];
        foreach ($activeEntries as $e) {
            $id = (int)$e['id'];
            if ($own === null && (int)$e['user_id'] === $userId) {
                $own = $id;
            } else {
                $others[] = $id;
            }
        }

        if (!$completeVisit) {
            return $own === null
                ? ['stop' => [], 'own' => null, 'complete' => false, 'error' => self::NO_TIMER]
                : ['stop' => [$own], 'own' => $own, 'complete' => false, 'error' => null];
        }

        if ($own !== null || $others) {
            $stop = $own !== null ? array_merge([$own], $others) : $others;
            return ['stop' => $stop, 'own' => $own, 'complete' => true, 'error' => null];
        }

        // No timer anywhere on the visit.
        if ($visitStatus === 'completed') {
            // Already done (a retry, or a second crew phone) — succeed without re-completing,
            // which would re-run completion side effects.
            return ['stop' => [], 'own' => null, 'complete' => false, 'error' => null];
        }
        if (in_array($visitStatus, self::COMPLETABLE_WITHOUT_TIMER, true)) {
            return ['stop' => [], 'own' => null, 'complete' => true, 'error' => null];
        }
        return ['stop' => [], 'own' => null, 'complete' => false, 'error' => self::NO_TIMER];
    }
}
