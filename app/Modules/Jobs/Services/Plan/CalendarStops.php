<?php
/**
 * Part of the Job Plan / Visit / Calendar Stop function library.
 * ensureCalendarStop / getCalendarStops
 *
 * Loaded via app/Modules/Jobs/Services/PlanFunctions.php (aggregator).
 * Global functions — names/signatures unchanged from the original monolith.
 */

// ============================================================================
// CALENDAR STOPS
// ============================================================================

/**
 * Ensure a calendar_stop exists for property+date+crew.
 * Uses INSERT ... ON DUPLICATE KEY UPDATE for idempotency.
 * Returns the stop_id.
 */
/**
 * Ensure a calendar_stops row exists for the given property+date+crew.
 *
 * On INSERT: writes estimated_arrival and estimated_departure if provided.
 * On DUPLICATE KEY: preserves any existing times (COALESCE), only updates
 * them when the column is currently NULL — so manual dispatcher edits are
 * never overwritten by cron regeneration.
 *
 * @param int         $propertyId
 * @param string      $date             YYYY-MM-DD
 * @param int|null    $crewId
 * @param string|null $estimatedArrival  HH:MM derived from plan default_time_start
 * @param string|null $estimatedDeparture HH:MM derived from arrival + duration
 * @return int  calendar_stops.id
 */
function ensureCalendarStop(int $propertyId, string $date, ?int $crewId,
                            ?string $estimatedArrival = null,
                            ?string $estimatedDeparture = null): int {
    $db = getDB();

    // INSERT with time fields; on duplicate preserve existing times (COALESCE),
    // only back-fill when the column is currently NULL.
    $stmt = $db->prepare("
        INSERT INTO calendar_stops
            (property_id, stop_date, crew_id, estimated_arrival, estimated_departure, status)
        VALUES (?, ?, ?, ?, ?, 'scheduled')
        ON DUPLICATE KEY UPDATE
            estimated_arrival   = COALESCE(estimated_arrival,   VALUES(estimated_arrival)),
            estimated_departure = COALESCE(estimated_departure, VALUES(estimated_departure)),
            updated_at          = NOW()
    ");
    $stmt->execute([$propertyId, $date, $crewId, $estimatedArrival, $estimatedDeparture]);

    // Fetch the ID (works for both insert and duplicate)
    $fetchStmt = $db->prepare("
        SELECT id FROM calendar_stops
        WHERE property_id = ? AND stop_date = ? AND crew_id <=> ?
    ");
    $fetchStmt->execute([$propertyId, $date, $crewId]);
    return (int)$fetchStmt->fetchColumn();
}

/**
 * Get calendar stops with their visits for a date range.
 * Returns nested array: [date][stop_id] => {stop data + visits[]}
 *
 * @param string $startDate YYYY-MM-DD
 * @param string $endDate   YYYY-MM-DD
 * @param int|null $crewId  Filter by crew, or null for all
 * @return array
 */
function getCalendarStops(string $startDate, string $endDate, ?int $crewId = null, ?string $serviceType = null): array {
    $db = getDB();

    // Check if Obsidian Root enrollment column exists (migration 920)
    $hasOrStatus = false;
    try {
        $hasOrStatus = $db->query("SHOW COLUMNS FROM properties LIKE 'or_status'")->rowCount() > 0;
    } catch (Exception $e) { /* ignore */ }
    $orStatusSelect = $hasOrStatus ? ', p.or_status' : '';

    // Persisted route pin ('first' | 'last' | NULL) — migration 1065.
    // Guarded so the schedule still loads on environments missing the column.
    $hasRoutePin = false;
    try {
        $hasRoutePin = $db->query("SHOW COLUMNS FROM calendar_stops LIKE 'route_pin'")->rowCount() > 0;
    } catch (Exception $e) { /* ignore */ }
    $routePinSelect = $hasRoutePin ? ', cs.route_pin' : '';

    // Single query: stops with their visits.
    // Note: lawn_sqft + last_completed_date used to be computed via two
    // correlated subqueries in the SELECT list, producing O(N) extra
    // subqueries per row (~250–500 ms on a 50-stop week view on shared
    // hosting). They are now computed in two batched follow-up queries
    // after the main fetch (see below).
    $sql = "
        SELECT
            cs.id AS stop_id,
            cs.stop_date,
            cs.route_order,
            cs.crew_id,
            cs.estimated_arrival,
            cs.estimated_departure,
            cs.status AS stop_status,
            p.id AS property_id,
            p.address AS property_address,
            p.city AS property_city,
            p.latitude,
            p.longitude,
            p.property_name,
            p.total_lawn_sqft,
            p.lawn_size_sqft,
            p.notes AS property_notes,
            cs.notes AS stop_notes
            {$orStatusSelect}{$routePinSelect},
            co.company_name,
            ct.id AS contact_id,
            CONCAT(ct.first_name, ' ', ct.last_name) AS contact_name,
            ct.mobile AS contact_mobile,
            ct.phone AS contact_phone,
            u.full_name AS crew_name,
            jv.id AS visit_id,
            jv.visit_number,
            jv.status AS visit_status,
            jv.is_invoiced,
            jv.invoice_id,
            jv.scheduled_time_start,
            jv.scheduled_time_end,
            jv.assigned_crew_id,
            jv.sequence_index,
            jp.id AS plan_id,
            jp.title AS plan_title,
            jp.plan_number,
            jp.service_type,
            jp.pricing_model,
            jp.price_per_visit,
            jp.estimated_duration_minutes,
            COALESCE(jv.is_flagged, 0) AS is_flagged,
            COALESCE(ct.has_reviewed, 0) AS contact_has_reviewed
        FROM calendar_stops cs
        JOIN properties p ON cs.property_id = p.id
        LEFT JOIN company_properties cp ON p.id = cp.property_id
        LEFT JOIN companies co ON cp.company_id = co.id
        LEFT JOIN contacts ct ON p.site_contact_id = ct.id
        LEFT JOIN users u ON cs.crew_id = u.id
        LEFT JOIN job_visits jv ON jv.stop_id = cs.id AND jv.status NOT IN ('cancelled')
        LEFT JOIN job_plans jp ON jv.plan_id = jp.id
        WHERE cs.stop_date BETWEEN ? AND ?
    ";
    $params = [$startDate, $endDate];

    if ($crewId !== null) {
        // Filter: show stops where this crew is assigned (lead or additional)
        $sql .= " AND (cs.crew_id = ? OR cs.id IN (SELECT stop_id FROM calendar_stop_crew WHERE user_id = ?))";
        $params[] = $crewId;
        $params[] = $crewId;
    }

    if ($serviceType !== null) {
        // Filter: only show stops that have at least one visit with this service type
        $sql .= " AND cs.id IN (SELECT jv2.stop_id FROM job_visits jv2 JOIN job_plans jp2 ON jv2.plan_id = jp2.id WHERE jv2.stop_id IS NOT NULL AND jp2.service_type = ?)";
        $params[] = $serviceType;
    }

    $sql .= " ORDER BY cs.stop_date, cs.crew_id, cs.route_order, jv.scheduled_time_start";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Collect IDs for the batch follow-up queries.
    $stopIds     = [];
    $propertyIds = [];
    foreach ($rows as $row) {
        $stopIds[(int)$row['stop_id']] = true;
        if (!empty($row['property_id'])) {
            $propertyIds[(int)$row['property_id']] = true;
        }
    }
    $stopIds     = array_keys($stopIds);
    $propertyIds = array_keys($propertyIds);

    // ── Batch 1: property_measurements lawn/garden area per property ──
    // Replaces the per-row correlated subquery that previously ran
    // SUM() for every row. One indexed lookup for the whole window.
    $measuredLawnByProperty = [];
    if (!empty($propertyIds)) {
        $ph = implode(',', array_fill(0, count($propertyIds), '?'));
        $mStmt = $db->prepare("
            SELECT property_id, SUM(area_sqft) AS measured_sqft
            FROM property_measurements
            WHERE property_id IN ({$ph})
              AND measurement_type IN ('lawn', 'garden')
            GROUP BY property_id
        ");
        $mStmt->execute($propertyIds);
        foreach ($mStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $measuredLawnByProperty[(int)$m['property_id']] = (float)$m['measured_sqft'];
        }
    }

    // ── Batch 2: last completed stop per property, before the window ──
    // The original subquery filtered on cs2.stop_date < cs.stop_date
    // (per-row precision). We now compute it relative to $startDate,
    // which is the intended semantic for the calendar view (show "last
    // time we were here before this view opened"). Callers that need
    // per-row precision are rare and can look it up separately.
    $lastCompletedByProperty = [];
    if (!empty($propertyIds)) {
        $ph = implode(',', array_fill(0, count($propertyIds), '?'));
        $lcStmt = $db->prepare("
            SELECT property_id, MAX(stop_date) AS last_completed
            FROM calendar_stops
            WHERE property_id IN ({$ph})
              AND status = 'completed'
              AND stop_date < ?
            GROUP BY property_id
        ");
        $lcStmt->execute(array_merge($propertyIds, [$startDate]));
        foreach ($lcStmt->fetchAll(PDO::FETCH_ASSOC) as $lc) {
            $lastCompletedByProperty[(int)$lc['property_id']] = $lc['last_completed'];
        }
    }

    // ── Batch 3: geofence drawn status per property ──
    $geofenceByProperty = [];
    if (!empty($propertyIds)) {
        $ph = implode(',', array_fill(0, count($propertyIds), '?'));
        $gfStmt = $db->prepare("
            SELECT DISTINCT property_id
            FROM job_geofences
            WHERE property_id IN ({$ph})
        ");
        $gfStmt->execute($propertyIds);
        foreach ($gfStmt->fetchAll(PDO::FETCH_ASSOC) as $gf) {
            $geofenceByProperty[(int)$gf['property_id']] = true;
        }
    }

    // Load multi-crew assignments from junction table
    $crewByStop = []; // stop_id => [ ['id' => ..., 'name' => ...], ... ]
    if (!empty($stopIds)) {
        $placeholders = implode(',', array_fill(0, count($stopIds), '?'));
        $crewStmt = $db->prepare("
            SELECT csc.stop_id, csc.user_id, u2.full_name
            FROM calendar_stop_crew csc
            JOIN users u2 ON csc.user_id = u2.id
            WHERE csc.stop_id IN ({$placeholders})
            ORDER BY csc.id
        ");
        $crewStmt->execute($stopIds);
        foreach ($crewStmt->fetchAll(PDO::FETCH_ASSOC) as $cr) {
            $crewByStop[(int)$cr['stop_id']][] = [
                'id'   => (int)$cr['user_id'],
                'name' => $cr['full_name'],
            ];
        }
    }

    // Group by date → stop_id → visits
    $result = [];
    foreach ($rows as $row) {
        $date = $row['stop_date'];
        $stopId = $row['stop_id'];

        if (!isset($result[$date][$stopId])) {
            // Multi-crew data from junction table (with fallback to single crew_id)
            $stopCrewList = $crewByStop[(int)$stopId] ?? [];
            $crewIdsArr = !empty($stopCrewList) ? array_column($stopCrewList, 'id') : ($row['crew_id'] ? [(int)$row['crew_id']] : []);
            $crewNamesArr = !empty($stopCrewList) ? array_column($stopCrewList, 'name') : ($row['crew_name'] ? [$row['crew_name']] : []);

            $result[$date][$stopId] = [
                'stop_id'       => (int)$stopId,
                'stop_date'     => $date,
                'route_order'   => (int)$row['route_order'],
                'route_pin'     => $row['route_pin'] ?? null,
                'crew_id'       => $row['crew_id'] ? (int)$row['crew_id'] : null,
                'crew_name'     => $row['crew_name'],
                'crew_ids'      => $crewIdsArr,
                'crew_names'    => $crewNamesArr,
                'estimated_arrival'   => $row['estimated_arrival'],
                'estimated_departure' => $row['estimated_departure'],
                'stop_status'   => $row['stop_status'],
                'property_id'   => (int)$row['property_id'],
                'property_address' => $row['property_address'],
                'property_city' => $row['property_city'],
                'latitude'      => $row['latitude'],
                'longitude'     => $row['longitude'],
                'company_name'  => $row['company_name'],
                'contact_id'    => $row['contact_id'] ? (int)$row['contact_id'] : null,
                'contact_name'  => $row['contact_name'],
                'contact_phone' => $row['contact_mobile'] ?: ($row['contact_phone'] ?? null),
                'property_name' => $row['property_name'],
                'property_notes' => !empty($row['property_notes']) ? trim($row['property_notes']) : null,
                'stop_notes'     => !empty($row['stop_notes'])     ? trim($row['stop_notes'])     : null,
                'or_status'     => $row['or_status'] ?? 'none',
                // Replaces the correlated COALESCE subquery that
                // previously ran once per row. Same semantic: prefer
                // the manually-entered total, fall back to the measured
                // sum (from batch 1), then the legacy lawn_size_sqft.
                'lawn_sqft'     => (function () use ($row, $measuredLawnByProperty) {
                    $pid = (int)($row['property_id'] ?? 0);
                    if (!empty($row['total_lawn_sqft']) && $row['total_lawn_sqft'] > 0) {
                        return (float)$row['total_lawn_sqft'];
                    }
                    if (!empty($measuredLawnByProperty[$pid])) {
                        return (float)$measuredLawnByProperty[$pid];
                    }
                    if (!empty($row['lawn_size_sqft']) && $row['lawn_size_sqft'] > 0) {
                        return (float)$row['lawn_size_sqft'];
                    }
                    return null;
                })(),
                'last_completed_date' => $lastCompletedByProperty[(int)$row['property_id']] ?? null,
                'has_geofence'  => isset($geofenceByProperty[(int)$row['property_id']]),
                'visits'        => [],
            ];
        }

        // Add visit if present (LEFT JOIN can produce null visit)
        if ($row['visit_id']) {
            $result[$date][$stopId]['visits'][] = [
                'visit_id'       => (int)$row['visit_id'],
                'visit_number'   => $row['visit_number'],
                'visit_status'   => $row['visit_status'],
                'is_invoiced'    => (int)($row['is_invoiced'] ?? 0),
                'invoice_id'     => $row['invoice_id'] ? (int)$row['invoice_id'] : null,
                'plan_id'        => (int)$row['plan_id'],
                'plan_title'     => $row['plan_title'],
                'plan_number'    => $row['plan_number'],
                'service_type'   => $row['service_type'],
                'pricing_model'  => $row['pricing_model'] ?? 'per_visit',
                'price_per_visit' => $row['price_per_visit'],
                'estimated_duration' => $row['estimated_duration_minutes'],
                'scheduled_time_start' => $row['scheduled_time_start'],
                'scheduled_time_end'   => $row['scheduled_time_end'],
                'sequence_index' => (int)$row['sequence_index'],
            ];
        }
    }

    // Remove stops that have no active visits (e.g. all visits cancelled)
    foreach ($result as $date => &$stops) {
        foreach ($stops as $stopId => $stop) {
            if (empty($stop['visits'])) {
                unset($stops[$stopId]);
            }
        }
        if (empty($stops)) {
            unset($result[$date]);
        }
    }
    unset($stops);

    return $result;
}
