<?php
declare(strict_types=1);

/**
 * VisitCompletionService
 * ──────────────────────
 * Called whenever a job_visit transitions to status='completed'.
 * Captures a point-in-time labor cost snapshot so profitability
 * remains accurate even after hourly rate changes later.
 *
 * Also upserts a visit_margin_snapshots row with full P&L breakdown.
 *
 * Usage (call after marking visit completed):
 *   require_once APP_ROOT . '/Modules/Jobs/Services/VisitCompletionService.php';
 *   VisitCompletionService::capture($visitId, $completedByUserId);
 */

class VisitCompletionService
{
    /**
     * Capture labor cost snapshot and compute visit margin.
     * Safe to call multiple times — uses INSERT ... ON DUPLICATE KEY UPDATE.
     *
     * @param int $visitId          The job_visits.id that just completed
     * @param int $completedByUserId The user who completed it (for rate lookup)
     */
    public static function capture(int $visitId, int $completedByUserId): void
    {
        try {
            $db = getDB();

            // ── 1. Load visit data ────────────────────────────────────────────
            // Note: jp.contact_id may not exist on all deployments — use
            // LEFT JOIN contacts via property's site_contact_id as fallback
            $stmt = $db->prepare("
                SELECT
                    jv.id,
                    jv.plan_id,
                    jv.assigned_crew_id,
                    jv.actual_duration_minutes,
                    jv.scheduled_date,
                    jv.drive_time_minutes,
                    jp.property_id,
                    jp.service_type,
                    jp.estimated_duration_minutes,
                    COALESCE(p.site_contact_id, NULL) AS contact_id
                FROM job_visits jv
                JOIN job_plans jp ON jp.id = jv.plan_id
                LEFT JOIN properties p ON p.id = jp.property_id
                WHERE jv.id = ?
            ");
            $stmt->execute([$visitId]);
            $visit = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$visit) return;

            // ── 2. Determine which user's rate to use ─────────────────────────
            // Prefer assigned crew; fall back to the completing user.
            $rateUserId = (int)($visit['assigned_crew_id'] ?? $completedByUserId);
            if ($rateUserId <= 0) $rateUserId = $completedByUserId;

            // ── 3. Fetch hourly rate at this moment ───────────────────────────
            $rateStmt = $db->prepare("SELECT hourly_rate FROM users WHERE id = ?");
            $rateStmt->execute([$rateUserId]);
            $rateRow = $rateStmt->fetch(PDO::FETCH_ASSOC);
            $hourlyRate = $rateRow ? (float)($rateRow['hourly_rate'] ?? 0) : 0.0;

            // ── 4. Compute labor cost ─────────────────────────────────────────
            $actualMinutes = (int)($visit['actual_duration_minutes'] ?? 0);
            // If actual not set yet, use estimated as fallback
            if ($actualMinutes <= 0) {
                $actualMinutes = (int)($visit['estimated_duration_minutes'] ?? 0);
            }
            $laborCost = $hourlyRate > 0 && $actualMinutes > 0
                ? round($hourlyRate * ($actualMinutes / 60), 2)
                : null;

            // ── 5. Compute drive cost ─────────────────────────────────────────
            $driveMinutes = (int)($visit['drive_time_minutes'] ?? 0);
            $driveCost    = null;
            if ($driveMinutes > 0) {
                $rateRow2 = $db->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'drive_cost_per_minute'")->fetch(PDO::FETCH_ASSOC);
                $driveRate = $rateRow2 ? (float)$rateRow2['setting_value'] : 0.75;
                $driveCost = round($driveRate * $driveMinutes, 2);
            }

            // ── 6. Write snapshot columns onto job_visits ─────────────────────
            $updateStmt = $db->prepare("
                UPDATE job_visits
                SET labor_rate_snapshot = ?,
                    labor_cost_snapshot = ?,
                    drive_cost_snapshot = ?
                WHERE id = ?
                  AND labor_cost_snapshot IS NULL
            ");
            $updateStmt->execute([$hourlyRate ?: null, $laborCost, $driveCost, $visitId]);

            // ── 7. Get quoted revenue for this visit ──────────────────────────
            // Try invoice line items first, then fall back to plan's quote total / visit count.
            $quotedAmount = null;
            try {
                $quotedAmount = self::resolveQuotedAmount($db, $visitId, (int)$visit['plan_id']);
            } catch (Throwable $qaErr) {
                // Fallback: use plan's price_per_visit directly
                $ppvStmt = $db->prepare("SELECT price_per_visit FROM job_plans WHERE id = ?");
                $ppvStmt->execute([(int)$visit['plan_id']]);
                $ppv = $ppvStmt->fetchColumn();
                $quotedAmount = $ppv ? (float)$ppv : null;
            }

            // ── 8. Get actual material cost ───────────────────────────────────
            $materialCost = null;
            try {
                $materialCost = self::resolveMaterialCost($db, $visitId);
            } catch (Throwable $matErr) {
                // expenses.visit_id or total_amount may not exist — graceful skip
            }

            // ── 9. Compute gross margin ───────────────────────────────────────
            $grossMargin = null;
            $marginPct   = null;
            if ($quotedAmount !== null) {
                $totalCost   = ($laborCost ?? 0) + ($materialCost ?? 0) + ($driveCost ?? 0);
                $grossMargin = round($quotedAmount - $totalCost, 2);
                $marginPct   = $quotedAmount > 0
                    ? round($grossMargin / $quotedAmount * 100, 2)
                    : null;
            }

            // ── 10. Upsert margin snapshot ────────────────────────────────────
            $upsertStmt = $db->prepare("
                INSERT INTO visit_margin_snapshots
                    (visit_id, plan_id, contact_id, property_id, visit_date,
                     service_type, quoted_amount, labor_cost, material_cost,
                     drive_cost, gross_margin, margin_pct)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    quoted_amount  = VALUES(quoted_amount),
                    labor_cost     = VALUES(labor_cost),
                    material_cost  = VALUES(material_cost),
                    drive_cost     = VALUES(drive_cost),
                    gross_margin   = VALUES(gross_margin),
                    margin_pct     = VALUES(margin_pct),
                    computed_at    = NOW()
            ");
            $upsertStmt->execute([
                $visitId,
                $visit['plan_id'],
                $visit['contact_id'],
                $visit['property_id'],
                $visit['scheduled_date'],
                $visit['service_type'],
                $quotedAmount,
                $laborCost,
                $materialCost,
                $driveCost,
                $grossMargin,
                $marginPct,
            ]);

            // ── 11. Log low-margin warning ────────────────────────────────────
            if ($marginPct !== null && $marginPct < 20) {
                error_log(sprintf(
                    '[VisitCompletion] LOW MARGIN — visit #%d: margin=%.1f%% (quoted=%.2f labor=%.2f material=%.2f drive=%.2f)',
                    $visitId,
                    $marginPct,
                    $quotedAmount ?? 0,
                    $laborCost ?? 0,
                    $materialCost ?? 0,
                    $driveCost ?? 0
                ));
            }

            // ── 12. Populate job_visits.actual_duration_minutes from timer entries ─
            // Sum all completed/edited timer entries for this visit so the column
            // reflects reality rather than staying NULL.
            $db->prepare("
                UPDATE job_visits
                SET actual_duration_minutes = (
                    SELECT COALESCE(SUM(duration_minutes), 0)
                    FROM job_time_entries
                    WHERE visit_id = ? AND status IN ('completed', 'edited')
                )
                WHERE id = ?
            ")->execute([$visitId, $visitId]);

            // ── 12b. Compute work-zone time attribution from GPS pings ────────────
            // Derives per-zone in_seconds from classified job_location_samples.
            // Safe to call even if no zones are drawn — exits early.
            $geoModelPath = APP_ROOT . '/Modules/Geofence/Models/GeofenceModel.php';
            if (is_file($geoModelPath) && $visit['property_id']) {
                require_once $geoModelPath;
                if (function_exists('geofenceComputeZoneSessions')) {
                    try {
                        geofenceComputeZoneSessions($visitId, (int)$visit['property_id']);
                    } catch (Throwable $ze) {
                        error_log('[VisitCompletionService] Zone session compute failed for visit ' . $visitId . ': ' . $ze->getMessage());
                    }
                }
            }

            // ── 13. Update plan's estimated_duration_minutes with rolling average ──
            // Average per-visit actual timer totals across all completed visits that
            // have real timer data. Rounds to nearest minute. This continuously
            // improves scheduling accuracy as real visit times accumulate.
            $rollAvgStmt = $db->prepare("
                SELECT AVG(visit_total) AS avg_duration
                FROM (
                    SELECT SUM(jte.duration_minutes) AS visit_total
                    FROM job_visits jv
                    JOIN job_time_entries jte ON jte.visit_id = jv.id
                    WHERE jv.plan_id = ?
                      AND jv.status = 'completed'
                      AND jte.status IN ('completed', 'edited')
                    GROUP BY jv.id
                    HAVING SUM(jte.duration_minutes) > 0
                ) AS per_visit
            ");
            $rollAvgStmt->execute([$visit['plan_id']]);
            $rollAvgRow = $rollAvgStmt->fetch(PDO::FETCH_ASSOC);
            $newEstimate = ($rollAvgRow && $rollAvgRow['avg_duration'] !== null)
                ? (int)round((float)$rollAvgRow['avg_duration'])
                : 0;

            if ($newEstimate > 0) {
                $db->prepare("
                    UPDATE job_plans SET estimated_duration_minutes = ? WHERE id = ?
                ")->execute([$newEstimate, $visit['plan_id']]);
                error_log(sprintf(
                    '[VisitCompletion] Duration avg updated — plan #%d: %d min',
                    $visit['plan_id'],
                    $newEstimate
                ));
            }

        } catch (Throwable $e) {
            // Never let this block visit completion — just log
            error_log('[VisitCompletionService] Error capturing snapshot for visit ' . $visitId . ': ' . $e->getMessage());
        }
    }

    /**
     * Resolve quoted revenue for a visit.
     * Checks invoice_line_items first, falls back to plan-level quote total.
     */
    private static function resolveQuotedAmount(PDO $db, int $visitId, int $planId): ?float
    {
        // Try: invoice line items tied to this visit
        $stmt = $db->prepare("
            SELECT SUM(ili.total) as total
            FROM invoice_line_items ili
            JOIN invoices i ON i.id = ili.invoice_id
            WHERE ili.visit_id = ?
              AND i.status NOT IN ('cancelled', 'draft')
        ");
        $stmt->execute([$visitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['total'] !== null) {
            return (float)$row['total'];
        }

        // Fallback: plan's price_per_visit or quote total ÷ completed visits
        $stmt = $db->prepare("
            SELECT
                jp.price_per_visit,
                q.total_amount,
                (SELECT COUNT(*) FROM job_visits jv2
                 WHERE jv2.plan_id = jp.id AND jv2.status = 'completed') AS completed_count
            FROM job_plans jp
            LEFT JOIN quotes q ON q.id = jp.quote_id
            WHERE jp.id = ?
        ");
        $stmt->execute([$planId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ($row['price_per_visit'] > 0) {
                return (float)$row['price_per_visit'];
            }
            if ($row['total_amount'] > 0 && $row['completed_count'] > 0) {
                return round((float)$row['total_amount'] / (int)$row['completed_count'], 2);
            }
        }

        return null;
    }

    /**
     * Resolve actual material cost for a visit from expenses.
     */
    private static function resolveMaterialCost(PDO $db, int $visitId): ?float
    {
        $stmt = $db->prepare("
            SELECT SUM(e.total_amount) as total
            FROM expenses e
            WHERE e.visit_id = ?
              AND e.accounting_category = 'Materials'
              AND e.status NOT IN ('rejected')
        ");
        $stmt->execute([$visitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && $row['total'] !== null ? (float)$row['total'] : null;
    }
}
