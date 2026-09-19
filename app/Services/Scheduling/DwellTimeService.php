<?php
declare(strict_types=1);

/**
 * DwellTimeService
 * ─────────────────
 * Derives how long a crew spent on a property from visit_gps_points,
 * independent of the manual job timer (job_time_entries).
 *
 * GPS dwell = elapsed time from first to last accuracy-filtered GPS point
 * recorded during a visit. More objective than timer data because it doesn't
 * rely on the crew remembering to start/stop the timer.
 *
 * Outputs:
 *   job_visits.gps_dwell_minutes   — raw elapsed GPS time for this visit
 *   job_visits.gps_dwell_quality   — data quality score (0–3)
 *   job_plans.gps_avg_dwell_minutes — rolling GPS average across completed visits
 *   job_plans.gps_visit_count       — count of GPS-quality visits
 *
 * When gps_visit_count >= 2, the GPS average is promoted into
 * job_plans.estimated_duration_minutes so the scheduler uses it automatically.
 *
 * Called from:
 *   VisitCompletionService::capture()  — per-visit, at completion
 *   backfill-gps-dwell.php             — admin backfill for historical visits
 */
class DwellTimeService
{
    /** Accept GPS points up to this horizontal accuracy (metres). More permissive
     *  than MapSnapshotService (50m) — dwell needs quantity, not tight accuracy. */
    private const ACCURACY_THRESHOLD = 100.0;

    /** Minimum quality-filtered points required to record dwell time. */
    private const MIN_POINTS = 3;

    /** GPS-derived minimum before promoting to estimated_duration (sanity floor). */
    private const GPS_MIN_MINUTES = 5;

    /** GPS-derived maximum — anything longer is probably a forgotten clock-out. */
    private const GPS_MAX_MINUTES = 480;

    /** How many GPS visits are required before GPS overrides the timer estimate. */
    private const GPS_PROMOTE_THRESHOLD = 2;

    /**
     * Compute GPS dwell time for a single completed visit and persist it.
     *
     * Returns an array with dwell stats, or null if there are insufficient
     * GPS points to compute a meaningful dwell time (not stored in that case).
     *
     * @return array{dwell_minutes:int, quality:int, point_count:int, filtered_count:int}|null
     */
    public static function computeForVisit(int $visitId): ?array
    {
        $db = getDB();

        $stmt = $db->prepare("
            SELECT ts, accuracy_m
            FROM visit_gps_points
            WHERE visit_id = ?
            ORDER BY ts ASC
        ");
        $stmt->execute([$visitId]);
        $points = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($points)) {
            return null;
        }

        $filtered = array_values(array_filter($points, static function (array $p): bool {
            return $p['accuracy_m'] === null || (float)$p['accuracy_m'] <= self::ACCURACY_THRESHOLD;
        }));

        $filteredCount = count($filtered);

        if ($filteredCount < self::MIN_POINTS) {
            return null;
        }

        $first          = strtotime($filtered[0]['ts']);
        $last           = strtotime($filtered[$filteredCount - 1]['ts']);
        $elapsedSeconds = max(0, $last - $first);
        $dwellMinutes   = (int)ceil($elapsedSeconds / 60);

        // Quality tiers by filtered point count
        if ($filteredCount < 6) {
            $quality = 1; // sparse
        } elseif ($filteredCount < 10) {
            $quality = 2; // moderate
        } else {
            $quality = 3; // good
        }

        // Sanity check: reject implausibly short or long values
        if ($dwellMinutes < self::GPS_MIN_MINUTES || $dwellMinutes > self::GPS_MAX_MINUTES) {
            error_log(sprintf(
                '[DwellTimeService] Implausible dwell for visit #%d: %d min — skipping',
                $visitId,
                $dwellMinutes
            ));
            return null;
        }

        $db->prepare("
            UPDATE job_visits
            SET gps_dwell_minutes = ?,
                gps_dwell_quality = ?
            WHERE id = ?
        ")->execute([$dwellMinutes, $quality, $visitId]);

        return [
            'dwell_minutes'  => $dwellMinutes,
            'quality'        => $quality,
            'point_count'    => count($points),
            'filtered_count' => $filteredCount,
        ];
    }

    /**
     * Recompute the rolling GPS dwell average for a plan from all its completed,
     * quality-filtered visits, then write gps_avg_dwell_minutes and gps_visit_count.
     *
     * When the count reaches GPS_PROMOTE_THRESHOLD, the GPS average is promoted
     * into estimated_duration_minutes so the scheduler uses it automatically.
     */
    public static function updatePlanDwellAverage(int $planId): void
    {
        $db = getDB();

        $stmt = $db->prepare("
            SELECT
                ROUND(AVG(gps_dwell_minutes)) AS avg_dwell,
                COUNT(*)                       AS visit_count
            FROM job_visits
            WHERE plan_id = ?
              AND gps_dwell_minutes IS NOT NULL
              AND gps_dwell_quality >= 2
              AND status = 'completed'
        ");
        $stmt->execute([$planId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $avgDwell   = ($row && $row['avg_dwell'] !== null) ? (int)$row['avg_dwell'] : null;
        $visitCount = $row ? (int)$row['visit_count'] : 0;

        $db->prepare("
            UPDATE job_plans
            SET gps_avg_dwell_minutes = ?,
                gps_visit_count       = ?
            WHERE id = ?
        ")->execute([$avgDwell, $visitCount, $planId]);

        // Promote GPS average into the scheduler's estimated_duration_minutes
        // once enough data points have accumulated.
        if ($avgDwell !== null
            && $visitCount >= self::GPS_PROMOTE_THRESHOLD
            && $avgDwell >= self::GPS_MIN_MINUTES
        ) {
            $db->prepare("
                UPDATE job_plans SET estimated_duration_minutes = ? WHERE id = ?
            ")->execute([$avgDwell, $planId]);

            error_log(sprintf(
                '[DwellTimeService] GPS dwell promoted — plan #%d: %d min (%d GPS visits)',
                $planId,
                $avgDwell,
                $visitCount
            ));
        }
    }

    /**
     * Backfill GPS dwell for all completed visits that have GPS point data
     * but no dwell time recorded yet. Run via backfill-gps-dwell.php.
     *
     * @return array{updated:int, no_data:int, plans_updated:int}
     */
    public static function backfillAllVisits(): array
    {
        $db = getDB();

        // Find completed visits with GPS points but no dwell computed yet
        $stmt = $db->query("
            SELECT DISTINCT vgp.visit_id
            FROM visit_gps_points vgp
            JOIN job_visits jv ON jv.id = vgp.visit_id
            WHERE jv.status = 'completed'
              AND jv.gps_dwell_minutes IS NULL
        ");
        $visitIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'visit_id');

        $updated  = 0;
        $noData   = 0;
        $planIds  = [];

        foreach ($visitIds as $vid) {
            $result = self::computeForVisit((int)$vid);
            if ($result !== null) {
                $updated++;
                $planRow = $db->prepare("SELECT plan_id FROM job_visits WHERE id = ?");
                $planRow->execute([$vid]);
                $pid = $planRow->fetchColumn();
                if ($pid) {
                    $planIds[(int)$pid] = true;
                }
            } else {
                $noData++;
            }
        }

        foreach (array_keys($planIds) as $pid) {
            self::updatePlanDwellAverage($pid);
        }

        return [
            'updated'       => $updated,
            'no_data'       => $noData,
            'plans_updated' => count($planIds),
        ];
    }
}
