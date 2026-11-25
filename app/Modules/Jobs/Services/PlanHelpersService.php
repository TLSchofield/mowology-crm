<?php
/**
 * PlanHelpersService — time helpers, visit-horizon check, plan/visit numbering.
 *
 * Extracted (2026-06-18, refactor Phase 2) from the procedural Plan function
 * library. The global functions in Plan/PlanHelpers.php delegate here, so all
 * callers and the legacy shim are unchanged.
 *
 * Pure (unit-tested): planTimeStringToMinutes, planMinutesToTimeString,
 * generateVisitNumber. DB-backed: isVisitHorizonCurrent, generatePlanNumber.
 * Logic is byte-for-byte the original inline code.
 */
class PlanHelpersService
{
    /**
     * Convert a HH:MM or HH:MM:SS time string to total minutes.
     * Returns 0 for empty/invalid input.
     */
    public static function planTimeStringToMinutes(string $t): int {
        $t = trim($t);
        if ($t === '') return 0;
        $parts = explode(':', $t);
        return ((int)($parts[0] ?? 0)) * 60 + ((int)($parts[1] ?? 0));
    }

    /**
     * Convert total minutes back to a HH:MM time string.
     * Clamps to 23:59 to avoid overflowing midnight.
     */
    public static function planMinutesToTimeString(int $minutes): string {
        $h = (int)floor($minutes / 60);
        $m = $minutes % 60;
        return sprintf('%02d:%02d', min($h, 23), max(0, $m));
    }

    /**
     * Quick read-only check: are all active recurring plans generated far enough ahead?
     *
     * Returns true  → horizon is current (cron ran recently enough).
     * Returns false → at least one plan needs visit generation.
     *
     * "Current" means every active recurring plan has visits_generated_through
     * at least 14 days into the future. Single COUNT(*) — no writes.
     */
    public static function isVisitHorizonCurrent(): bool {
        try {
            $db = getDB();
            $minHorizon = date('Y-m-d', strtotime('+14 days'));
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM job_plans
                WHERE status = 'active'
                  AND is_recurring = 1
                  AND (
                    visits_generated_through IS NULL
                    OR visits_generated_through < ?
                  )
            ");
            $stmt->execute([$minHorizon]);
            return ((int)$stmt->fetchColumn()) === 0;
        } catch (Exception $e) {
            // On any DB error, report stale so the cron knows to run
            error_log('[isVisitHorizonCurrent] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate a unique plan number: PLN-YYYY-NNNN
     */
    public static function generatePlanNumber(): string {
        $db = getDB();
        $year = date('Y');
        $prefix = "PLN-{$year}-";

        $stmt = $db->prepare("
            SELECT MAX(CAST(SUBSTRING(plan_number, ?) AS UNSIGNED)) as max_num
            FROM job_plans
            WHERE plan_number LIKE ?
        ");
        $stmt->execute([strlen($prefix) + 1, $prefix . '%']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $next = ($row['max_num'] ?? 0) + 1;

        return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Generate a visit number: PLN-2026-0001-V001
     */
    public static function generateVisitNumber(string $planNumber, int $sequenceIndex): string {
        return $planNumber . '-V' . str_pad((string)$sequenceIndex, 3, '0', STR_PAD_LEFT);
    }
}
