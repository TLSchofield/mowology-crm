<?php
declare(strict_types=1);

class InvoiceCrewTime
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Loads timer and visit facts for the office-only invoice-create summary. */
    public function forVisit(int $visitId): array
    {
        try {
            try {
                $entryStmt = $this->db->prepare("
                    SELECT jte.user_id, jte.start_time, jte.end_time, jte.duration_minutes,
                           jte.status, jte.time_source, u.full_name
                    FROM job_time_entries jte
                    LEFT JOIN users u ON u.id = jte.user_id
                    WHERE jte.visit_id = ? AND jte.status <> 'void'
                    ORDER BY jte.start_time, jte.id
                ");
                $entryStmt->execute([$visitId]);
                $entries = $entryStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $entryStmt = $this->db->prepare("
                    SELECT jte.user_id, jte.start_time, jte.end_time, jte.duration_minutes,
                           jte.status, u.full_name
                    FROM job_time_entries jte
                    LEFT JOIN users u ON u.id = jte.user_id
                    WHERE jte.visit_id = ? AND jte.status <> 'void'
                    ORDER BY jte.start_time, jte.id
                ");
                $entryStmt->execute([$visitId]);
                $entries = $entryStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($entries as &$entry) {
                    $entry['time_source'] = 'gps';
                }
                unset($entry);
            }

            $visitStmt = $this->db->prepare("
                SELECT started_at, completed_at, actual_duration_minutes
                FROM job_visits
                WHERE id = ?
            ");
            $visitStmt->execute([$visitId]);
            $visit = $visitStmt->fetch(PDO::FETCH_ASSOC);

            return self::summarise($entries, $visit ?: null, time());
        } catch (Throwable $e) {
            error_log('InvoiceCrewTime::forVisit failed: ' . $e->getMessage());
            return self::summarise([], null, time());
        }
    }

    /** Pure summary of per-person timers and the visit fallback. */
    public static function summarise(array $entries, ?array $visit, int $now): array
    {
        $peopleByKey = [];
        $peopleOrder = [];
        $crewMinutes = 0;
        $arrived = null;
        $left = null;
        $running = false;
        $estimated = false;
        $countedEntries = 0;
        $hasRealEnd = false;

        foreach ($entries as $entry) {
            $timeSource = (string)($entry['time_source'] ?? 'gps');
            if ($timeSource === 'travel_apportioned') {
                continue;
            }

            $isEstimate = in_array($timeSource, ['cluster_apportioned', 'photo_inferred', 'estimated'], true);
            $start = !empty($entry['start_time']) ? (string)$entry['start_time'] : null;
            $end = !empty($entry['end_time']) ? (string)$entry['end_time'] : null;
            $status = (string)($entry['status'] ?? '');
            $minutes = 0;

            if (!$isEstimate && $end !== null && $start !== null) {
                $minutes = (int)round((strtotime($end) - strtotime($start)) / 60);
            } elseif (!$isEstimate && $status === 'active' && $start !== null) {
                $minutes = (int)round(($now - strtotime($start)) / 60);
                $running = true;
            } else {
                $minutes = (int)($entry['duration_minutes'] ?? 0);
            }
            $minutes = max(0, $minutes);

            // Cluster-apportioned rows can carry a crews.id in user_id (ClusterService passes
            // either a crew or a user), so the users join may name the wrong person. Pool every
            // estimate under one line that is not counted as a crew member.
            $personKey = $isEstimate ? '__estimate' : (string)($entry['user_id'] ?? '');
            if (!array_key_exists($personKey, $peopleByKey)) {
                $name = $isEstimate ? 'Estimated' : trim((string)($entry['full_name'] ?? ''));
                $peopleByKey[$personKey] = [
                    'name' => $name !== '' ? $name : 'Crew member',
                    'minutes' => 0,
                ];
                $peopleOrder[] = $personKey;
            }
            $peopleByKey[$personKey]['minutes'] += $minutes;
            $crewMinutes += $minutes;
            $countedEntries++;

            if ($isEstimate) {
                $estimated = true;
                continue;
            }

            if ($start !== null && ($arrived === null || strtotime($start) < strtotime($arrived))) {
                $arrived = $start;
            }
            if ($end !== null) {
                $hasRealEnd = true;
                if ($left === null || strtotime($end) > strtotime($left)) {
                    $left = $end;
                }
            }
        }

        if ($countedEntries > 0) {
            if ($running || !$hasRealEnd) {
                $left = null;
            }
            $onSiteMinutes = null;
            if ($arrived !== null && $left !== null) {
                $onSiteMinutes = max(0, (int)round((strtotime($left) - strtotime($arrived)) / 60));
            }
            $people = [];
            foreach ($peopleOrder as $personKey) {
                $people[] = $peopleByKey[$personKey];
            }
            $crewCount = count($people) - (isset($peopleByKey['__estimate']) ? 1 : 0);

            return self::result(true, 'timers', $arrived, $left, $onSiteMinutes, $crewMinutes,
                $crewCount, $people, $running, $estimated);
        }

        $visitMinutes = (int)($visit['actual_duration_minutes'] ?? 0);
        if ($visitMinutes > 0) {
            $visitArrived = !empty($visit['started_at']) ? (string)$visit['started_at'] : null;
            $visitLeft = !empty($visit['completed_at']) ? (string)$visit['completed_at'] : null;
            $onSiteMinutes = null;
            if ($visitArrived !== null && $visitLeft !== null && strtotime($visitArrived) < strtotime($visitLeft)) {
                $onSiteMinutes = max(0, (int)round((strtotime($visitLeft) - strtotime($visitArrived)) / 60));
            } else {
                $visitArrived = null;
                $visitLeft = null;
            }

            return self::result(true, 'visit', $visitArrived, $visitLeft, $onSiteMinutes, $visitMinutes,
                0, [], false, false);
        }

        return self::result(false, 'none', null, null, null, 0, 0, [], false, false);
    }

    /** Format whole minutes for the compact office display. */
    public static function formatMinutes(int $minutes): string
    {
        $minutes = max(0, $minutes);
        $hours = intdiv($minutes, 60);
        $remainder = $minutes % 60;
        if ($hours === 0) {
            return $minutes . ' min';
        }
        if ($remainder === 0) {
            return $hours . ' h';
        }
        return $hours . ' h ' . $remainder . ' min';
    }

    private static function result(bool $hasTime, string $source, ?string $arrived, ?string $left,
        ?int $onSiteMinutes, int $crewMinutes, int $crewCount, array $people, bool $running,
        bool $estimated): array
    {
        return [
            'has_time' => $hasTime,
            'source' => $source,
            'arrived' => $arrived,
            'left' => $left,
            'on_site_minutes' => $onSiteMinutes,
            'crew_minutes' => $crewMinutes,
            'crew_count' => $crewCount,
            'people' => $people,
            'running' => $running,
            'estimated' => $estimated,
        ];
    }
}
