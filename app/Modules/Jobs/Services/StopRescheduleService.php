<?php
declare(strict_types=1);

/**
 * Moves a calendar stop (and its linked scheduled visits) to another date.
 * Shared by the CRM web endpoint (desktop drag-and-drop, mobile "Move" button)
 * and the iOS JWT endpoint so every surface reschedules the same way.
 */
class StopRescheduleService
{
    /** A crew day longer than this (minutes) triggers the soft capacity warning. */
    public const DAY_CAPACITY_MINUTES = 540;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @throws Exception If the stop doesn't exist.
     */
    public function getStop(int $stopId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM calendar_stops WHERE id = ?");
        $stmt->execute([$stopId]);
        $stop = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$stop) {
            throw new Exception('Stop not found');
        }
        return $stop;
    }

    /**
     * Soft capacity check: would the lead crew's day on $newDate exceed
     * DAY_CAPACITY_MINUTES once this stop lands on it? Not race-safe by design —
     * it is a warning the caller can override, never a hard block.
     *
     * @return array|null ['message' => string, 'minutes_after' => int] or null when fine.
     */
    public function capacityWarning(int $stopId, string $newDate): ?array
    {
        $stop = $this->getStop($stopId);
        if ($newDate === $stop['stop_date'] || empty($stop['crew_id'])) {
            return null;
        }

        $capStmt = $this->db->prepare("
            SELECT COALESCE(SUM(jp.estimated_duration_minutes), 0) AS existing_min
            FROM calendar_stops cs
            JOIN job_visits jv ON jv.stop_id = cs.id
                AND jv.status NOT IN ('cancelled', 'skipped', 'completed')
            JOIN job_plans jp ON jv.plan_id = jp.id
            WHERE cs.stop_date = ?
              AND cs.crew_id = ?
              AND cs.id != ?
        ");
        $capStmt->execute([$newDate, (int)$stop['crew_id'], $stopId]);
        $existingMin = (int)$capStmt->fetchColumn();

        $stopStmt = $this->db->prepare("
            SELECT COALESCE(SUM(jp.estimated_duration_minutes), 0) AS stop_min
            FROM job_visits jv
            JOIN job_plans jp ON jv.plan_id = jp.id
            WHERE jv.stop_id = ?
              AND jv.status NOT IN ('cancelled', 'skipped', 'completed')
        ");
        $stopStmt->execute([$stopId]);
        $stopMin = (int)$stopStmt->fetchColumn();

        $minutesAfter = $existingMin + $stopMin;
        if ($minutesAfter <= self::DAY_CAPACITY_MINUTES) {
            return null;
        }

        $hoursAfter = round($minutesAfter / 60, 1);
        return [
            'message'       => "Crew day is over capacity ({$hoursAfter}h) — continue?",
            'minutes_after' => $minutesAfter,
        ];
    }

    /**
     * Move the stop to $newDate. If the same property + crew already has a stop
     * on that date, the scheduled visits are merged into it and the old stop is
     * deleted. Only visits still 'scheduled' move — finished work stays where it
     * was recorded.
     *
     * @param int|null $newRouteOrder Explicit position on the new day (desktop drag).
     * @param bool     $appendToEnd   When no explicit position is given, put the stop
     *                                last on the new day (mobile date-picker move).
     * @return array ['stop_id' => int, 'old_date' => string, 'new_date' => string, 'merged' => bool]
     * @throws Exception If the stop doesn't exist or the date/time is malformed.
     */
    public function reschedule(
        int $stopId,
        string $newDate,
        ?int $newRouteOrder = null,
        ?string $newTime = null,
        bool $appendToEnd = false
    ): array {
        if (!self::isValidDate($newDate)) {
            throw new Exception('Invalid date format');
        }
        if ($newTime !== null && $newTime !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $newTime)) {
            throw new Exception('Invalid time format');
        }
        if ($newTime === '') {
            $newTime = null;
        }

        $stop    = $this->getStop($stopId);
        $oldDate = (string)$stop['stop_date'];

        $this->db->beginTransaction();
        try {
            // crew_id may be NULL (unassigned stop) — match it explicitly.
            if ($stop['crew_id'] === null) {
                $existingStmt = $this->db->prepare("
                    SELECT id FROM calendar_stops
                    WHERE property_id = ? AND stop_date = ? AND crew_id IS NULL AND id != ?
                ");
                $existingStmt->execute([$stop['property_id'], $newDate, $stopId]);
            } else {
                $existingStmt = $this->db->prepare("
                    SELECT id FROM calendar_stops
                    WHERE property_id = ? AND stop_date = ? AND crew_id = ? AND id != ?
                ");
                $existingStmt->execute([$stop['property_id'], $newDate, $stop['crew_id'], $stopId]);
            }
            $existingStop = $existingStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingStop) {
                $resultStopId = (int)$existingStop['id'];

                $this->db->prepare("
                    UPDATE job_visits
                    SET stop_id = ?, scheduled_date = ?, updated_at = NOW()
                    WHERE stop_id = ? AND status = 'scheduled'
                ")->execute([$resultStopId, $newDate, $stopId]);

                $this->db->prepare("DELETE FROM calendar_stops WHERE id = ?")->execute([$stopId]);
            } else {
                if ($newRouteOrder === null && $appendToEnd && $newDate !== $oldDate) {
                    $orderStmt = $this->db->prepare("
                        SELECT COALESCE(MAX(route_order), 0) + 1 FROM calendar_stops
                        WHERE stop_date = ? AND id != ?
                    ");
                    $orderStmt->execute([$newDate, $stopId]);
                    $newRouteOrder = (int)$orderStmt->fetchColumn();
                }

                $setClauses = ["stop_date = ?", "updated_at = NOW()"];
                $params     = [$newDate];
                if ($newRouteOrder !== null) {
                    $setClauses[] = "route_order = ?";
                    $params[]     = $newRouteOrder;
                }
                if ($newTime) {
                    $setClauses[] = "estimated_arrival = ?";
                    $params[]     = $newTime;
                }
                $params[] = $stopId;
                $this->db->prepare(
                    "UPDATE calendar_stops SET " . implode(', ', $setClauses) . " WHERE id = ?"
                )->execute($params);

                $visitUpdate = "UPDATE job_visits SET scheduled_date = ?";
                $visitParams = [$newDate];
                if ($newTime) {
                    $visitUpdate  .= ", scheduled_time_start = ?";
                    $visitParams[] = $newTime;
                }
                $visitUpdate  .= ", updated_at = NOW() WHERE stop_id = ? AND status = 'scheduled'";
                $visitParams[] = $stopId;
                $this->db->prepare($visitUpdate)->execute($visitParams);

                $resultStopId = $stopId;
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return [
            'stop_id'         => $resultStopId,
            'old_date'        => $oldDate,
            'new_date'        => $newDate,
            'new_route_order' => $newRouteOrder,
            'merged'          => (bool)$existingStop,
        ];
    }

    /**
     * True when the stop still has work to do — i.e. moving it makes sense.
     * A stop whose visits are all finished, or that has a job timer running,
     * must not be moved from a phone.
     */
    public function isMovable(int $stopId): bool
    {
        $stmt = $this->db->prepare("
            SELECT
                SUM(CASE WHEN status = 'scheduled'   THEN 1 ELSE 0 END) AS scheduled_n,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS active_n
            FROM job_visits WHERE stop_id = ?
        ");
        $stmt->execute([$stopId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return (int)($row['scheduled_n'] ?? 0) > 0 && (int)($row['active_n'] ?? 0) === 0;
    }

    public static function isValidDate(string $date): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return false;
        }
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }
}
