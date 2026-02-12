<?php
/**
 * Swap Suggestions Module
 * /crm/modules/scheduling/swap-suggestions.php
 *
 * When a visit cannot be rescheduled forward (no clear weather in window),
 * this module finds other visits on the same crew/day that ARE weather-safe
 * and could be swapped into the bad-weather slot.
 *
 * Returns recommendations only — does NOT auto-swap.
 */

declare(strict_types=1);

/**
 * Find swap candidates for a weather-blocked visit.
 * Looks for other visits on the same crew and nearby dates that have
 * an ANY or less restrictive weather policy and would be OK in the bad weather.
 *
 * @param int   $blockedVisitId  The visit that's weather-blocked
 * @param array $blockedVisitData Visit record with scheduled_date, assigned_crew_id, etc.
 * @return array List of swap candidates:
 *   [{visit_id, visit_number, property_address, service_type, weather_policy, swap_reason}]
 */
function findSwapCandidates(int $blockedVisitId, array $blockedVisitData): array
{
    try {
        $db = getDB();

        $blockedDate = $blockedVisitData['scheduled_date'];
        $crewId      = $blockedVisitData['assigned_crew_id'] ?? null;

        if (!$crewId) {
            return [];
        }

        // Find other scheduled visits for same crew within +/- 2 days
        // that have a weather-safe policy (ANY or LIGHT_RAIN_OK)
        $stmt = $db->prepare("
            SELECT v.id AS visit_id, v.visit_number, v.scheduled_date,
                   v.scheduled_time_start, v.scheduled_time_end,
                   p.service_type, p.title AS plan_title, p.service_package_id,
                   prop.address AS property_address,
                   sp.weather_policy, sp.package_name AS service_name
            FROM job_visits v
            JOIN job_plans p ON v.plan_id = p.id
            JOIN properties prop ON p.property_id = prop.id
            LEFT JOIN service_packages sp ON p.service_package_id = sp.id
            WHERE v.assigned_crew_id = ?
              AND v.status = 'scheduled'
              AND v.id != ?
              AND v.scheduled_date BETWEEN DATE_SUB(?, INTERVAL 2 DAY) AND DATE_ADD(?, INTERVAL 2 DAY)
              AND (sp.weather_policy IS NULL OR sp.weather_policy IN ('ANY', 'LIGHT_RAIN_OK'))
            ORDER BY v.scheduled_date, v.scheduled_time_start
            LIMIT 10
        ");
        $stmt->execute([$crewId, $blockedVisitId, $blockedDate, $blockedDate]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $suggestions = [];
        foreach ($candidates as $c) {
            $suggestions[] = [
                'visit_id'         => (int)$c['visit_id'],
                'visit_number'     => $c['visit_number'],
                'scheduled_date'   => $c['scheduled_date'],
                'time_start'       => $c['scheduled_time_start'],
                'time_end'         => $c['scheduled_time_end'],
                'property_address' => $c['property_address'],
                'service_type'     => $c['service_type'],
                'service_name'     => $c['service_name'] ?? $c['plan_title'],
                'weather_policy'   => $c['weather_policy'] ?? 'ANY',
                'swap_reason'      => sprintf(
                    '%s is weather-safe (policy: %s) and can be moved to %s',
                    $c['service_name'] ?? $c['service_type'],
                    $c['weather_policy'] ?? 'ANY',
                    $blockedDate
                ),
            ];
        }

        return $suggestions;
    } catch (Throwable $e) {
        error_log("findSwapCandidates error: " . $e->getMessage());
        return [];
    }
}

/**
 * Execute a swap between two visits (exchange their scheduled dates/times).
 *
 * @param int $visitA First visit ID
 * @param int $visitB Second visit ID
 * @param int $userId User performing the swap
 * @return array ['success' => bool, 'error' => string|null]
 */
function executeSwap(int $visitA, int $visitB, int $userId): array
{
    try {
        $db = getDB();

        // Get both visits
        $stmt = $db->prepare("
            SELECT id, scheduled_date, scheduled_time_start, stop_id
            FROM job_visits
            WHERE id IN (?, ?) AND status = 'scheduled'
        ");
        $stmt->execute([$visitA, $visitB]);
        $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($visits) !== 2) {
            return ['success' => false, 'error' => 'Both visits must be in scheduled status'];
        }

        $a = $visits[0]['id'] == $visitA ? $visits[0] : $visits[1];
        $b = $visits[0]['id'] == $visitB ? $visits[0] : $visits[1];

        $db->beginTransaction();

        // Swap dates and times
        $update = $db->prepare("
            UPDATE job_visits
            SET scheduled_date = ?, scheduled_time_start = ?, stop_id = ?
            WHERE id = ?
        ");
        $update->execute([$b['scheduled_date'], $b['scheduled_time_start'], $b['stop_id'], $visitA]);
        $update->execute([$a['scheduled_date'], $a['scheduled_time_start'], $a['stop_id'], $visitB]);

        $db->commit();

        // Log both swap actions
        logWeatherAction('VISIT_SWAP', 'visit', $visitA, json_encode([
            'swapped_with' => $visitB,
            'new_date'     => $b['scheduled_date'],
        ]), $userId);
        logWeatherAction('VISIT_SWAP', 'visit', $visitB, json_encode([
            'swapped_with' => $visitA,
            'new_date'     => $a['scheduled_date'],
        ]), $userId);

        return ['success' => true, 'error' => null];
    } catch (Throwable $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log("executeSwap error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
