<?php
declare(strict_types=1);

/**
 * Assigns/unassigns crew members on a calendar stop, syncing the lead crew
 * column, linked scheduled visits, and the calendar_stop_crew junction table.
 * Shared by the CRM web endpoint and the iOS JWT endpoint so both stay in sync.
 */
class CrewAssignmentService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @param int[] $crewIds Crew member user IDs; first entry becomes the "lead" crew.
     *                       Empty array unassigns the stop.
     * @return array Assignment result payload.
     * @throws Exception If the stop doesn't exist, a crew member is invalid, or
     *                    a duplicate stop already exists for this property/date/crew.
     */
    public function assignCrew(
        int $stopId,
        array $crewIds,
        string $scope = 'this_visit',
        int $planId = 0,
        ?int $actorUserId = null
    ): array {
        $scope = $scope === 'all_future' ? 'all_future' : 'this_visit';
        $crewIds = array_values(array_unique(array_filter(
            array_map('intval', $crewIds),
            static fn (int $id): bool => $id > 0
        )));
        $leadCrewId = !empty($crewIds) ? $crewIds[0] : null;

        $stmt = $this->db->prepare("SELECT id, crew_id, property_id, stop_date FROM calendar_stops WHERE id = ?");
        $stmt->execute([$stopId]);
        $stop = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$stop) {
            throw new Exception('Stop not found');
        }

        // Validate all crew members exist and are active; build display names in order.
        $crewNames = [];
        if (!empty($crewIds)) {
            $placeholders = implode(',', array_fill(0, count($crewIds), '?'));
            $cStmt = $this->db->prepare("SELECT id, full_name FROM users WHERE id IN ({$placeholders}) AND is_active = 1");
            $cStmt->execute($crewIds);
            $foundUsers = $cStmt->fetchAll(PDO::FETCH_ASSOC);

            $foundIds = array_map('intval', array_column($foundUsers, 'id'));
            foreach ($crewIds as $cid) {
                if (!in_array($cid, $foundIds, true)) {
                    throw new Exception("Crew member #{$cid} not found or inactive");
                }
            }

            $userMap = [];
            foreach ($foundUsers as $u) {
                $userMap[(int)$u['id']] = $u['full_name'];
            }
            foreach ($crewIds as $cid) {
                $crewNames[] = $userMap[$cid];
            }
        }

        $crewDisplay = !empty($crewNames) ? implode(', ', $crewNames) : 'Unassigned';

        // Duplicate-stop check, written portably (equivalent to MySQL's `<=>` null-safe equal).
        $dupStmt = $this->db->prepare("
            SELECT id FROM calendar_stops
            WHERE property_id = ? AND stop_date = ?
              AND (crew_id = ? OR (crew_id IS NULL AND ? IS NULL))
              AND id != ?
        ");
        $dupStmt->execute([$stop['property_id'], $stop['stop_date'], $leadCrewId, $leadCrewId, $stopId]);
        if ($dupStmt->fetch()) {
            throw new Exception('A stop already exists for this property, date, and crew');
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE calendar_stops SET crew_id = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$leadCrewId, $stopId]);

            $this->db->prepare("
                UPDATE job_visits
                SET assigned_crew_id = ?, updated_at = NOW()
                WHERE stop_id = ? AND status IN ('scheduled', 'in_progress')
            ")->execute([$leadCrewId, $stopId]);

            $this->db->prepare("DELETE FROM calendar_stop_crew WHERE stop_id = ?")->execute([$stopId]);

            if (!empty($crewIds)) {
                $insStmt = $this->db->prepare("INSERT INTO calendar_stop_crew (stop_id, user_id) VALUES (?, ?)");
                foreach ($crewIds as $cid) {
                    $insStmt->execute([$stopId, $cid]);
                }
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // ── "All future visits" scope: propagate to plan + all future stops ──────
        $futureStopsUpdated = 0;
        if ($scope === 'all_future' && $planId > 0) {
            $futureStopsUpdated = $this->propagateToFuture($planId, $stopId, $crewIds, $leadCrewId);
        }

        $this->notifyAssignedCrew($crewIds, $stop, $stopId);

        if ($actorUserId !== null && function_exists('logActivityExtended')) {
            $logDetail = "Stop #{$stopId} crew changed to {$crewDisplay}";
            if ($scope === 'all_future' && $planId > 0) {
                $logDetail .= " (+ {$futureStopsUpdated} future stops for plan #{$planId})";
            }
            logActivityExtended($actorUserId, 'Crew reassigned', $logDetail, null, null, null, null, null, null);
        }

        return [
            'success'              => true,
            'message'              => $scope === 'all_future'
                                        ? "Crew updated for this stop and {$futureStopsUpdated} future stops"
                                        : 'Crew updated successfully',
            'stop_id'              => $stopId,
            'crew_ids'             => $crewIds,
            'crew_names'           => $crewNames,
            'crew_id'              => $leadCrewId,
            'crew_name'            => $crewDisplay,
            'scope'                => $scope,
            'future_stops_updated' => $futureStopsUpdated,
        ];
    }

    private function propagateToFuture(int $planId, int $stopId, array $crewIds, ?int $leadCrewId): int
    {
        if (function_exists('setPlanCrewAssignments')) {
            setPlanCrewAssignments($planId, $crewIds, $leadCrewId);
        } elseif (defined('APP_ROOT') && is_file(APP_ROOT . '/Modules/Jobs/Services/Plan/PlanCrew.php')) {
            require_once APP_ROOT . '/Modules/Jobs/Services/Plan/PlanCrew.php';
            if (function_exists('setPlanCrewAssignments')) {
                setPlanCrewAssignments($planId, $crewIds, $leadCrewId);
            }
        }

        $futureStmt = $this->db->prepare("
            SELECT DISTINCT jv.stop_id
            FROM job_visits jv
            WHERE jv.plan_id = ?
              AND jv.status = 'scheduled'
              AND jv.scheduled_date >= CURDATE()
              AND jv.stop_id IS NOT NULL
              AND jv.stop_id != ?
        ");
        $futureStmt->execute([$planId, $stopId]);
        $futureStopIds = $futureStmt->fetchAll(PDO::FETCH_COLUMN);

        $futureStopsUpdated = 0;
        if (!empty($futureStopIds)) {
            $delFutureStmt = $this->db->prepare("DELETE FROM calendar_stop_crew WHERE stop_id = ?");
            $insFutureStmt = $this->db->prepare("INSERT INTO calendar_stop_crew (stop_id, user_id) VALUES (?, ?)");
            $updFutureStmt = $this->db->prepare("UPDATE calendar_stops SET crew_id = ?, updated_at = NOW() WHERE id = ?");
            $clrFutureStmt = $this->db->prepare("UPDATE calendar_stops SET crew_id = NULL, updated_at = NOW() WHERE id = ?");

            foreach ($futureStopIds as $fid) {
                $delFutureStmt->execute([$fid]);
                if (!empty($crewIds)) {
                    foreach ($crewIds as $cid) {
                        $insFutureStmt->execute([$fid, $cid]);
                    }
                    $updFutureStmt->execute([$leadCrewId, $fid]);
                } else {
                    $clrFutureStmt->execute([$fid]);
                }
                $futureStopsUpdated++;
            }
        }

        return $futureStopsUpdated;
    }

    /** Push notification: tell newly assigned crew members about the job. Never blocks the response. */
    private function notifyAssignedCrew(array $crewIds, array $stop, int $stopId): void
    {
        if (empty($crewIds)) {
            return;
        }
        try {
            require_once APP_ROOT . '/Services/Push/ApnsService.php';
            require_once APP_ROOT . '/Services/Push/PushDispatcher.php';
            $propStmt = $this->db->prepare("SELECT address, city FROM properties WHERE id = ?");
            $propStmt->execute([$stop['property_id']]);
            $prop = $propStmt->fetch(PDO::FETCH_ASSOC);
            $addressLine = $prop ? ($prop['address'] . ', ' . $prop['city']) : 'your next stop';
            $dateLabel = date('D, M j', strtotime((string)$stop['stop_date']));
            PushDispatcher::notifyUsers(
                $crewIds,
                'Job Assigned',
                "{$dateLabel} — {$addressLine}",
                // 'date' lets the app open the right day — assignments are often for a future date.
                ['stop_id' => $stopId, 'screen' => 'schedule', 'date' => date('Y-m-d', strtotime((string)$stop['stop_date']))]
            );
        } catch (Throwable $pushErr) {
            error_log('[CrewAssignmentService] push error: ' . $pushErr->getMessage());
        }
    }
}
