<?php
/**
 * Snapshot Manager Module
 * /crm/modules/snapshots/snapshot-manager.php
 *
 * Stores and retrieves weather evaluation snapshots on job_visits.
 * Each snapshot is a JSON record of the forecast + evaluation at decision time.
 */

declare(strict_types=1);

/**
 * Save a weather evaluation snapshot to a visit record.
 * Updates weather_ok, weather_status, weather_reason, weather_decision_at,
 * and weather_snapshot_raw on job_visits.
 *
 * @param int   $visitId     Visit ID
 * @param array $evalResult  Evaluation result from evaluateVisitWeather()
 * @return bool Success
 */
function saveWeatherSnapshot(int $visitId, array $evalResult): bool
{
    try {
        $db = getDB();

        $weatherOk = ($evalResult['status'] === 'OK') ? 1 : (($evalResult['status'] === 'BORDERLINE') ? null : 0);

        // Build snapshot JSON (compact: exclude raw_snapshot from the stored eval to avoid double-nesting)
        $snapshot = [
            'evaluated_at'  => date('Y-m-d H:i:s'),
            'status'        => $evalResult['status'],
            'reason'        => $evalResult['reason'],
            'summary'       => $evalResult['summary'],
            'worst_hour'    => $evalResult['worst_hour'],
            'checks'        => $evalResult['checks'] ?? [],
            'hourly_window' => $evalResult['raw_snapshot'] ?? [],
            'visit_rule'    => $evalResult['visit_rule'] ?? [],
        ];

        $stmt = $db->prepare("
            UPDATE job_visits
            SET weather_ok = ?,
                weather_status = ?,
                weather_reason = ?,
                weather_decision_at = NOW(),
                weather_snapshot_raw = ?
            WHERE id = ?
        ");

        return $stmt->execute([
            $weatherOk,
            $evalResult['status'],
            $evalResult['reason'],
            json_encode($snapshot),
            $visitId,
        ]);
    } catch (Throwable $e) {
        error_log("saveWeatherSnapshot error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get the weather snapshot for a visit.
 *
 * @param int $visitId
 * @return array|null Decoded snapshot or null
 */
function getWeatherSnapshot(int $visitId): ?array
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT weather_ok, weather_status, weather_reason, weather_decision_at,
                   weather_snapshot_raw, weather_card_path
            FROM job_visits
            WHERE id = ?
        ");
        $stmt->execute([$visitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return null;

        $result = [
            'weather_ok'          => $row['weather_ok'],
            'weather_status'      => $row['weather_status'],
            'weather_reason'      => $row['weather_reason'],
            'weather_decision_at' => $row['weather_decision_at'],
            'weather_card_path'   => $row['weather_card_path'],
            'snapshot'            => null,
        ];

        if (!empty($row['weather_snapshot_raw'])) {
            $result['snapshot'] = json_decode($row['weather_snapshot_raw'], true);
        }

        return $result;
    } catch (Throwable $e) {
        error_log("getWeatherSnapshot error: " . $e->getMessage());
        return null;
    }
}

/**
 * Update the weather card path on a visit after PNG generation.
 *
 * @param int    $visitId
 * @param string $cardPath Relative path to the generated PNG
 * @return bool
 */
function updateWeatherCardPath(int $visitId, string $cardPath): bool
{
    try {
        $db = getDB();
        $stmt = $db->prepare("UPDATE job_visits SET weather_card_path = ? WHERE id = ?");
        return $stmt->execute([$cardPath, $visitId]);
    } catch (Throwable $e) {
        error_log("updateWeatherCardPath error: " . $e->getMessage());
        return false;
    }
}

/**
 * Clear weather evaluation data from a visit (e.g., when manually overridden).
 *
 * @param int    $visitId
 * @param string $overrideReason
 * @return bool
 */
function clearWeatherEvaluation(int $visitId, string $overrideReason = 'Manual override'): bool
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            UPDATE job_visits
            SET weather_ok = 1,
                weather_status = 'OK',
                weather_reason = ?,
                weather_decision_at = NOW()
            WHERE id = ?
        ");
        return $stmt->execute([$overrideReason, $visitId]);
    } catch (Throwable $e) {
        error_log("clearWeatherEvaluation error: " . $e->getMessage());
        return false;
    }
}
