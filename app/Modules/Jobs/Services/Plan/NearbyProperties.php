<?php
/**
 * Part of the Job Plan / Visit / Calendar Stop function library.
 * findNearbyProperties — GPS-first property picker for the field "add a job"
 * flow. Each result carries its most recent active plan (if any) so the UI
 * can offer "add a visit" vs "start a new job" in one step.
 *
 * Loaded via app/Modules/Jobs/Services/PlanFunctions.php (aggregator).
 */

/**
 * @param float|null $lat    Crew's current latitude, or null to search by text only.
 * @param float|null $lng    Crew's current longitude, or null to search by text only.
 * @param string     $search Free-text match against address / contact name.
 * @param int        $limit
 * @return array List of ['property_id','address','city','distance_km','contact_name',
 *               'plan_id','plan_title','plan_service_type','has_active_plan']
 */
function findNearbyProperties(?float $lat, ?float $lng, string $search = '', int $limit = 15): array {
    $db = getDB();
    $search = trim($search);
    $hasFix = ($lat !== null && $lng !== null);
    $params = [];

    // Haversine distance in km.
    $distanceExpr = $hasFix
        ? "(6371 * ACOS(LEAST(1, GREATEST(-1,
              COS(RADIANS(?)) * COS(RADIANS(p.latitude)) * COS(RADIANS(p.longitude) - RADIANS(?))
            + SIN(RADIANS(?)) * SIN(RADIANS(p.latitude))
          ))))"
        : "NULL";
    if ($hasFix) {
        $params[] = $lat;
        $params[] = $lng;
        $params[] = $lat;
    }

    $sql = "
        SELECT
            p.id AS property_id,
            p.address,
            p.city,
            $distanceExpr AS distance_km,
            TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) AS contact_name,
            jp.id AS plan_id,
            jp.title AS plan_title,
            jp.service_type AS plan_service_type
        FROM properties p
        LEFT JOIN contacts c ON c.id = p.site_contact_id
        LEFT JOIN job_plans jp ON jp.id = (
            SELECT id FROM job_plans
            WHERE property_id = p.id AND status = 'active'
            ORDER BY id DESC LIMIT 1
        )
        WHERE p.latitude IS NOT NULL AND p.longitude IS NOT NULL
    ";

    if ($search !== '') {
        $sql .= " AND (p.address LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= $hasFix ? " ORDER BY distance_km ASC" : " ORDER BY p.address ASC";
    $sql .= " LIMIT " . (int)$limit;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['property_id']     = (int)$row['property_id'];
        $row['distance_km']     = $row['distance_km'] !== null ? round((float)$row['distance_km'], 2) : null;
        $row['plan_id']         = $row['plan_id'] !== null ? (int)$row['plan_id'] : null;
        $row['has_active_plan'] = $row['plan_id'] !== null;
    }
    unset($row);

    return $rows;
}
