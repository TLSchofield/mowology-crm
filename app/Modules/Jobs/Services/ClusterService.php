<?php
declare(strict_types=1);

/**
 * ClusterService
 *
 * Manages route-stop clusters and distributes time + cost across members.
 *
 * Time priority chain per cluster session:
 *   1. Explicit GPS clock-in/out  (time_source = 'gps')
 *   2. Photo EXIF timestamps      (time_source = 'photo_inferred')
 *   3. Sum of estimated_duration  (time_source = 'estimated')
 *
 * Cost split:
 *   Travel minutes — equal split across all members
 *   On-property minutes — proportional to each plan's estimated_duration_minutes
 */
class ClusterService
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    // =========================================================================
    // Cluster CRUD
    // =========================================================================

    public function createCluster(string $name, array $propertyIds, int $travelMinutes = 0, ?string $notes = null): int
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO property_clusters (name, notes, default_travel_minutes) VALUES (?, ?, ?)'
            );
            $stmt->execute([$name, $notes, $travelMinutes]);
            $clusterId = (int)$this->db->lastInsertId();

            $memberStmt = $this->db->prepare(
                'INSERT INTO property_cluster_members (cluster_id, property_id, sort_order) VALUES (?, ?, ?)'
            );
            foreach (array_values($propertyIds) as $i => $pid) {
                $memberStmt->execute([$clusterId, (int)$pid, $i]);
            }

            // Tag any future calendar_stops for these properties
            $this->tagExistingStops($clusterId, $propertyIds);

            $this->db->commit();
            return $clusterId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function updateCluster(int $clusterId, string $name, int $travelMinutes, ?string $notes): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE property_clusters SET name = ?, default_travel_minutes = ?, notes = ? WHERE id = ?'
        );
        return $stmt->execute([$name, $travelMinutes, $notes, $clusterId]);
    }

    public function deactivateCluster(int $clusterId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE property_clusters SET is_active = 0 WHERE id = ?'
        );
        return $stmt->execute([$clusterId]);
    }

    // =========================================================================
    // Lookup
    // =========================================================================

    /**
     * Given a calendar_stop id, return the cluster record (or null).
     */
    public function getClusterForStop(int $stopId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT pc.*
             FROM calendar_stops cs
             JOIN property_clusters pc ON pc.id = cs.cluster_id
             WHERE cs.id = ? AND pc.is_active = 1'
        );
        $stmt->execute([$stopId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Given a property_id, return the active cluster it belongs to (or null).
     */
    public function getClusterForProperty(int $propertyId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT pc.*
             FROM property_cluster_members pcm
             JOIN property_clusters pc ON pc.id = pcm.cluster_id
             WHERE pcm.property_id = ? AND pc.is_active = 1
             LIMIT 1'
        );
        $stmt->execute([$propertyId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Return all stops (calendar_stops) in the same cluster on a given date.
     */
    public function getClusterStopsOnDate(int $clusterId, string $date, ?int $crewId = null): array
    {
        $sql = 'SELECT cs.*, p.address, p.city,
                       c.first_name, c.last_name,
                       jp.estimated_duration_minutes, jp.id AS plan_id
                FROM calendar_stops cs
                JOIN properties p ON p.id = cs.property_id
                LEFT JOIN contacts c ON c.id = p.site_contact_id
                LEFT JOIN job_plans jp ON jp.property_id = cs.property_id AND jp.status = \'active\'
                WHERE cs.cluster_id = ? AND cs.stop_date = ?';
        $params = [$clusterId, $date];
        if ($crewId !== null) {
            $sql .= ' AND cs.crew_id = ?';
            $params[] = $crewId;
        }
        $sql .= ' ORDER BY cs.route_order ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // Session management (clock-in / clock-out)
    // =========================================================================

    /**
     * Open a cluster session. Called when ANY property in the cluster triggers
     * a clock-in (GPS or manual). Returns the session id.
     */
    public function openSession(int $clusterId, string $date, ?int $crewId, float $lat, float $lng, string $source = 'gps'): int
    {
        // Idempotent — if already open, return existing session
        $existing = $this->getOpenSession($clusterId, $date, $crewId);
        if ($existing) return (int)$existing['id'];

        $stmt = $this->db->prepare(
            'INSERT INTO cluster_sessions
                (cluster_id, session_date, crew_id, started_at, time_source)
             VALUES (?, ?, ?, NOW(), ?)'
        );
        $stmt->execute([$clusterId, $date, $crewId, $source]);
        $sessionId = (int)$this->db->lastInsertId();

        // Clock in all associated visits for today
        $this->clockInAllMembers($clusterId, $date, $crewId, $lat, $lng, $sessionId, $source);

        return $sessionId;
    }

    /**
     * Close a cluster session and write apportioned time entries.
     */
    public function closeSession(int $sessionId, float $lat, float $lng, int $closedBy): bool
    {
        $session = $this->getSession($sessionId);
        if (!$session || $session['completed_at'] !== null) return false;

        $startedAt   = strtotime($session['started_at']);
        $totalMinutes = (int)round((time() - $startedAt) / 60);
        $travelMinutes = (int)$this->getTravelMinutes((int)$session['cluster_id']);

        // Cap travel at total time
        $travelMinutes = min($travelMinutes, $totalMinutes);
        $onPropertyMinutes = $totalMinutes - $travelMinutes;

        $this->db->prepare(
            'UPDATE cluster_sessions
             SET completed_at = NOW(), total_minutes = ?, travel_minutes = ?
             WHERE id = ?'
        )->execute([$onPropertyMinutes, $travelMinutes, $sessionId]);

        $this->writeApportionedEntries(
            (int)$session['cluster_id'],
            $session['session_date'],
            $session['crew_id'] ? (int)$session['crew_id'] : null,
            $onPropertyMinutes,
            $travelMinutes,
            $sessionId,
            $session['time_source']
        );

        return true;
    }

    public function getOpenSession(int $clusterId, string $date, ?int $crewId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM cluster_sessions
             WHERE cluster_id = ? AND session_date = ?
               AND (? IS NULL OR crew_id = ?)
               AND completed_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([$clusterId, $date, $crewId, $crewId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getSession(int $sessionId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM cluster_sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // =========================================================================
    // Apportionment
    // =========================================================================

    /**
     * Calculate each member's share of on-property and travel minutes.
     *
     * Returns array keyed by plan_id:
     *   ['plan_id' => int, 'property_id' => int, 'on_property_minutes' => float, 'travel_minutes' => float]
     */
    public function apportionTime(int $clusterId, string $date, int $onPropertyMinutes, int $travelMinutes, ?int $crewId = null): array
    {
        $members = $this->getMembersWithDurations($clusterId, $date, $crewId);
        if (empty($members)) return [];

        $memberCount   = count($members);
        $totalEstimate = array_sum(array_column($members, 'estimated_duration_minutes'));

        // Travel: equal split (per-member cost of driving to the cluster)
        $travelPerMember = $memberCount > 0 ? $travelMinutes / $memberCount : 0;

        $result = [];
        $allocatedOnProperty = 0;

        foreach (array_values($members) as $i => $member) {
            $lastMember = ($i === $memberCount - 1);

            // On-property: proportional to estimated duration
            if ($member['apportionment_override'] !== null) {
                $pct = (float)$member['apportionment_override'] / 100.0;
            } elseif ($totalEstimate > 0) {
                $pct = (float)$member['estimated_duration_minutes'] / $totalEstimate;
            } else {
                $pct = 1.0 / $memberCount; // equal fallback
            }

            if ($lastMember) {
                // Assign remainder to avoid rounding drift
                $onProp = $onPropertyMinutes - $allocatedOnProperty;
            } else {
                $onProp = round($onPropertyMinutes * $pct, 2);
                $allocatedOnProperty += $onProp;
            }

            // Travel: last member absorbs any rounding remainder
            $travelShare = $lastMember
                ? $travelMinutes - ($travelPerMember * ($memberCount - 1))
                : round($travelPerMember, 2);

            $result[] = [
                'plan_id'             => (int)$member['plan_id'],
                'property_id'         => (int)$member['property_id'],
                'visit_id'            => $member['visit_id'] ? (int)$member['visit_id'] : null,
                'on_property_minutes' => max(0.0, $onProp),
                'travel_minutes'      => max(0.0, $travelShare),
                'total_minutes'       => max(0.0, $onProp + $travelShare),
            ];
        }

        return $result;
    }

    /**
     * Write apportioned job_time_entries for all cluster members.
     */
    public function writeApportionedEntries(
        int $clusterId,
        string $date,
        ?int $crewId,
        int $onPropertyMinutes,
        int $travelMinutes,
        int $sessionId,
        string $timeSource = 'gps'
    ): void {
        $allocations = $this->apportionTime($clusterId, $date, $onPropertyMinutes, $travelMinutes, $crewId);

        $photoSource = $timeSource === 'photo_inferred' ? 'photo_inferred' : null;

        $stmt = $this->db->prepare(
            'INSERT INTO job_time_entries
                (plan_id, visit_id, user_id, start_time, duration_minutes,
                 cluster_session_id, time_source, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'completed\')'
        );

        foreach ($allocations as $alloc) {
            if ($alloc['on_property_minutes'] > 0) {
                $stmt->execute([
                    $alloc['plan_id'],
                    $alloc['visit_id'],
                    $crewId,
                    $date . ' 00:00:00',
                    round($alloc['on_property_minutes']),
                    $sessionId,
                    $photoSource ?? 'cluster_apportioned',
                ]);
            }
            if ($alloc['travel_minutes'] > 0) {
                $stmt->execute([
                    $alloc['plan_id'],
                    $alloc['visit_id'],
                    $crewId,
                    $date . ' 00:00:00',
                    round($alloc['travel_minutes']),
                    $sessionId,
                    'travel_apportioned',
                ]);
            }
        }
    }

    /**
     * Attempt to infer the cluster time window from photo EXIF timestamps.
     * Returns ['total_minutes' => int, 'source' => 'photo_inferred'] or null.
     */
    public function inferTimeFromPhotos(int $clusterId, string $date): ?array
    {
        // Collect visit_ids for all plans active on this date within the cluster
        $stmt = $this->db->prepare(
            'SELECT jv.id AS visit_id
             FROM property_cluster_members pcm
             JOIN job_plans jp ON jp.property_id = pcm.property_id AND jp.status = \'active\'
             JOIN job_visits jv ON jv.plan_id = jp.id AND jv.scheduled_date = ?
             WHERE pcm.cluster_id = ?'
        );
        $stmt->execute([$date, $clusterId]);
        $visitIds = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'visit_id');

        if (empty($visitIds)) return null;

        $placeholders = implode(',', array_fill(0, count($visitIds), '?'));
        $photoStmt = $this->db->prepare(
            "SELECT MIN(ma.captured_at) AS earliest, MAX(ma.captured_at) AS latest
             FROM media_links ml
             JOIN media_assets ma ON ma.id = ml.media_id
             WHERE ml.context_type = 'job_visit'
               AND ml.context_id IN ($placeholders)
               AND ma.captured_at IS NOT NULL"
        );
        $photoStmt->execute($visitIds);
        $row = $photoStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row || !$row['earliest'] || !$row['latest']) return null;

        $minutes = (int)round((strtotime($row['latest']) - strtotime($row['earliest'])) / 60);
        if ($minutes < 1) return null;

        return ['total_minutes' => $minutes, 'source' => 'photo_inferred', 'earliest' => $row['earliest'], 'latest' => $row['latest']];
    }

    // =========================================================================
    // GPS clock-in integration
    // =========================================================================

    /**
     * Called by the GPS/clock-in handler when a stop triggers an arrival.
     * If the stop belongs to a cluster, fires clock-in for all other members too.
     * Returns the cluster session id, or null if not a cluster stop.
     */
    public function handleArrival(int $stopId, int $userId, float $lat, float $lng, string $date): ?int
    {
        $cluster = $this->getClusterForStop($stopId);
        if (!$cluster) return null;

        return $this->openSession((int)$cluster['id'], $date, $userId, $lat, $lng, 'gps');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function getMembersWithDurations(int $clusterId, string $date, ?int $crewId): array
    {
        $stmt = $this->db->prepare(
            'SELECT pcm.property_id, pcm.apportionment_override,
                    jp.id AS plan_id, jp.estimated_duration_minutes,
                    jv.id AS visit_id
             FROM property_cluster_members pcm
             JOIN property_clusters pc ON pc.id = pcm.cluster_id AND pc.is_active = 1
             JOIN job_plans jp ON jp.property_id = pcm.property_id AND jp.status = \'active\'
             LEFT JOIN job_visits jv
                 ON  jv.plan_id       = jp.id
                 AND jv.scheduled_date = ?
             WHERE pcm.cluster_id = ?
             ORDER BY pcm.sort_order ASC'
        );
        $stmt->execute([$date, $clusterId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function getTravelMinutes(int $clusterId): int
    {
        $stmt = $this->db->prepare(
            'SELECT default_travel_minutes FROM property_clusters WHERE id = ?'
        );
        $stmt->execute([$clusterId]);
        return (int)$stmt->fetchColumn();
    }

    private function clockInAllMembers(int $clusterId, string $date, ?int $crewId, float $lat, float $lng, int $sessionId, string $source): void
    {
        $stops = $this->getClusterStopsOnDate($clusterId, $date, $crewId);

        $visitStmt = $this->db->prepare(
            'UPDATE job_visits SET started_at = NOW(), status = \'in_progress\'
             WHERE plan_id = ? AND scheduled_date = ? AND started_at IS NULL'
        );
        $stopStmt = $this->db->prepare(
            'UPDATE calendar_stops SET status = \'in_progress\' WHERE id = ?'
        );

        foreach ($stops as $stop) {
            if ($stop['plan_id']) {
                $visitStmt->execute([$stop['plan_id'], $date]);
            }
            $stopStmt->execute([$stop['id']]);
        }
    }

    private function tagExistingStops(int $clusterId, array $propertyIds): void
    {
        if (empty($propertyIds)) return;
        $placeholders = implode(',', array_fill(0, count($propertyIds), '?'));
        $stmt = $this->db->prepare(
            "UPDATE calendar_stops SET cluster_id = ?
             WHERE property_id IN ($placeholders) AND cluster_id IS NULL"
        );
        $stmt->execute(array_merge([$clusterId], $propertyIds));
    }
}
