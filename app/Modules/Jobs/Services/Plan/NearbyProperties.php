<?php
/**
 * Part of the Job Plan / Visit / Calendar Stop function library.
 * findNearbyProperties — GPS proximity lookup for the field "add a job"
 * overlay (field-job.js). Each result carries its most recent active plan
 * (if any) and whether that plan already has a visit today, so the UI can
 * branch straight to "add today's visit" vs "start a new job".
 *
 * Loaded via app/Modules/Jobs/Services/PlanFunctions.php (aggregator).
 */

/**
 * @param float $lat    Crew's current latitude.
 * @param float $lng    Crew's current longitude.
 * @param int   $radiusM Search radius in metres.
 * @param int   $limit
 * @return array List of ['id','address','city','distance_m','contact_name',
 *               'has_plan','has_visit_today','plan_id','plan_title']
 */
function findNearbyProperties(float $lat, float $lng, int $radiusM = 250, int $limit = 15): array {
    $db = getDB();
    $today = date('Y-m-d');

    // Haversine distance in metres.
    $distanceExpr = "(6371000 * ACOS(LEAST(1, GREATEST(-1,
          COS(RADIANS(?)) * COS(RADIANS(p.latitude)) * COS(RADIANS(p.longitude) - RADIANS(?))
        + SIN(RADIANS(?)) * SIN(RADIANS(p.latitude))
      ))))";

    $sql = "
        SELECT
            p.id,
            p.address,
            p.city,
            $distanceExpr AS distance_m,
            TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) AS contact_name,
            jp.id AS plan_id,
            jp.title AS plan_title,
            (
                SELECT COUNT(*) FROM job_visits
                WHERE plan_id = jp.id AND scheduled_date = ? AND status != 'cancelled'
            ) AS visit_today_count
        FROM properties p
        LEFT JOIN contacts c ON c.id = p.site_contact_id
        LEFT JOIN job_plans jp ON jp.id = (
            SELECT id FROM job_plans
            WHERE property_id = p.id AND status = 'active'
            ORDER BY id DESC LIMIT 1
        )
        WHERE p.latitude IS NOT NULL AND p.longitude IS NOT NULL
        HAVING distance_m <= ?
        ORDER BY distance_m ASC
        LIMIT " . (int)$limit;

    $stmt = $db->prepare($sql);
    $stmt->execute([$lat, $lng, $lat, $today, $radiusM]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($rows as $row) {
        $results[] = [
            'id'              => (int)$row['id'],
            'address'         => $row['address'],
            'city'            => $row['city'],
            'distance_m'      => (int)round((float)$row['distance_m']),
            'contact_name'    => $row['contact_name'] !== '' ? $row['contact_name'] : null,
            'has_plan'        => $row['plan_id'] !== null,
            'has_visit_today' => ((int)$row['visit_today_count']) > 0,
            'plan_id'         => $row['plan_id'] !== null ? (int)$row['plan_id'] : null,
            'plan_title'      => $row['plan_title'],
        ];
    }

    return $results;
}
