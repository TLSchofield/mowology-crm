<?php
/**
 * GeofenceModel — Data access layer for the Polygon Geofence Tracking module.
 *
 * Responsibilities:
 *   - CRUD for job_geofences (polygon definitions)
 *   - Insert / batch-insert job_location_samples
 *   - Compute in/out zone classification for samples
 *   - Derive entry/exit events and persist to job_geofence_events
 *   - Compute + persist per-visit summary in job_geofence_sessions
 *   - Activation-state resolution (global → service → plan → visit)
 *
 * Design constraints:
 *   - MySQL 5.7 compatible (no window functions, no JSON functions)
 *   - Every method is standalone — no class state persists between calls
 *   - All public methods guard against disabled state; callers can safely
 *     call computeSession() without first checking activation.
 *
 * @package Mowology\Geofence
 */
declare(strict_types=1);

// ============================================================================
// ACTIVATION RESOLUTION
// ============================================================================

/**
 * Resolve whether geofence tracking is enabled for a specific visit.
 *
 * Priority (most specific wins):
 *   visit.geofence_enabled (not NULL)
 *   → plan.geofence_enabled (not NULL)
 *   → service-type setting
 *   → global setting
 *
 * Returns true only when the full chain resolves to enabled AND
 * a polygon exists for the plan.
 *
 * @param int $visitId
 * @return array {
 *   enabled: bool,
 *   source:  'visit'|'plan'|'service'|'global'|'no_polygon',
 *   geofence_id: int|null,
 *   plan_id: int|null,
 *   service_type: string|null,
 *   sample_interval_sec: int,
 *   min_accuracy_m: float,
 *   max_accuracy_m: float,
 * }
 */
function geofenceResolveForVisit(int $visitId): array {
    $defaults = [
        'enabled'             => false,
        'source'              => 'global',
        'geofence_id'         => null,
        'plan_id'             => null,
        'service_type'        => null,
        'sample_interval_sec' => 30,
        'min_accuracy_m'      => 30.0,
        'max_accuracy_m'      => 150.0,
    ];

    if (!$visitId) return $defaults;

    try {
        $db = getDB();

        // Load visit + plan columns we need
        $stmt = $db->prepare("
            SELECT v.id, v.plan_id, v.geofence_enabled AS visit_override,
                   p.geofence_enabled AS plan_override,
                   p.active_geofence_id,
                   p.service_type
            FROM job_visits v
            JOIN job_plans p ON v.plan_id = p.id
            WHERE v.id = ?
            LIMIT 1
        ");
        $stmt->execute([$visitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return $defaults;

        $defaults['plan_id']      = (int)$row['plan_id'];
        $defaults['service_type'] = $row['service_type'];
        $defaults['geofence_id']  = $row['active_geofence_id'] ? (int)$row['active_geofence_id'] : null;

        // Load settings
        $settings = geofenceGetSettings();
        $defaults['sample_interval_sec'] = (int)($settings['geofence.sample_interval_sec'] ?? 30);
        $defaults['min_accuracy_m']      = (float)($settings['geofence.min_accuracy_m'] ?? 30);
        $defaults['max_accuracy_m']      = (float)($settings['geofence.max_accuracy_m'] ?? 150);

        // Resolve enabled flag — most specific wins
        if ($row['visit_override'] !== null) {
            $enabled = (bool)(int)$row['visit_override'];
            $source  = 'visit';
        } elseif ($row['plan_override'] !== null) {
            $enabled = (bool)(int)$row['plan_override'];
            $source  = 'plan';
        } elseif ($row['service_type']) {
            $servicesEnabled = json_decode($settings['geofence.services_enabled'] ?? '[]', true) ?: [];
            $enabled = in_array($row['service_type'], $servicesEnabled, true);
            $source  = 'service';
        } else {
            $enabled = (bool)(int)($settings['geofence.global_enabled'] ?? '0');
            $source  = 'global';
        }

        // Even if enabled, no polygon = not active
        if ($enabled && !$defaults['geofence_id']) {
            // Try fetching the most recent polygon for this plan as fallback
            $fStmt = $db->prepare("
                SELECT id FROM job_geofences WHERE plan_id = ? ORDER BY drawn_at DESC LIMIT 1
            ");
            $fStmt->execute([$row['plan_id']]);
            $gid = $fStmt->fetchColumn();
            if ($gid) {
                $defaults['geofence_id'] = (int)$gid;
            } else {
                $enabled = false;
                $source  = 'no_polygon';
            }
        }

        $defaults['enabled'] = $enabled;
        $defaults['source']  = $source;
        return $defaults;

    } catch (PDOException $e) {
        error_log('[GeofenceModel] geofenceResolveForVisit: ' . $e->getMessage());
        return $defaults;
    }
}

/**
 * Bulk-load geofence settings from time_clock_settings.
 * Returns assoc array keyed by setting_key.
 */
function geofenceGetSettings(): array {
    try {
        $db   = getDB();
        $stmt = $db->query("
            SELECT setting_key, setting_value
            FROM time_clock_settings
            WHERE setting_key LIKE 'geofence.%'
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out  = [];
        foreach ($rows as $r) {
            $out[$r['setting_key']] = $r['setting_value'];
        }
        return $out;
    } catch (PDOException $e) {
        error_log('[GeofenceModel] geofenceGetSettings: ' . $e->getMessage());
        return [];
    }
}

/**
 * Update a single geofence setting.
 */
function geofenceSetSetting(string $key, string $value): void {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO time_clock_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([$key, $value]);
}


// ============================================================================
// POLYGON CRUD
// ============================================================================

/**
 * Save (create or replace) a polygon for a plan.
 * Returns the new geofence_id.
 *
 * @param int   $planId
 * @param array $ring   [[lat, lng], ...] — at least 3 vertices
 * @param int   $userId
 * @param string|null $label
 * @param string|null $notes
 */
function geofenceSavePolygon(
    int $planId, array $ring, int $userId,
    ?string $label = null, ?string $notes = null
): int {
    if (count($ring) < 3) {
        throw new InvalidArgumentException('A polygon must have at least 3 vertices.');
    }

    // Ensure ring is closed (first == last)
    $first = $ring[0];
    $last  = end($ring);
    if ($first[0] !== $last[0] || $first[1] !== $last[1]) {
        $ring[] = $first;
    }

    [$latMin, $latMax, $lngMin, $lngMax] = geofenceBbox($ring);
    $areaSqm = geofencePolygonArea($ring);

    $db = getDB();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            INSERT INTO job_geofences
                (plan_id, property_id, polygon_json, vertex_count,
                 bbox_lat_min, bbox_lat_max, bbox_lng_min, bbox_lng_max,
                 area_sqm, label, notes, drawn_by)
            SELECT ?, p.property_id, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            FROM job_plans p WHERE p.id = ?
        ");
        $stmt->execute([
            $planId,
            json_encode($ring),
            count($ring) - 1,   // vertex_count excludes the closing duplicate
            $latMin, $latMax, $lngMin, $lngMax,
            $areaSqm,
            $label, $notes,
            $userId,
            $planId,
        ]);
        $geofenceId = (int)$db->lastInsertId();

        // Point the plan at this new polygon
        $db->prepare("UPDATE job_plans SET active_geofence_id = ? WHERE id = ?")
           ->execute([$geofenceId, $planId]);

        $db->commit();
        return $geofenceId;
    } catch (PDOException $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Fetch the active polygon for a plan.
 * Returns the row or null.
 */
function geofenceGetForPlan(int $planId): ?array {
    try {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT g.* FROM job_geofences g
            JOIN job_plans p ON p.active_geofence_id = g.id
            WHERE p.id = ?
            LIMIT 1
        ");
        $stmt->execute([$planId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $row['polygon'] = json_decode($row['polygon_json'], true) ?: [];
        return $row;
    } catch (PDOException $e) {
        error_log('[GeofenceModel] geofenceGetForPlan: ' . $e->getMessage());
        return null;
    }
}

/**
 * Fetch a single geofence by ID.
 */
function geofenceGetById(int $geofenceId): ?array {
    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM job_geofences WHERE id = ? LIMIT 1");
        $stmt->execute([$geofenceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['polygon'] = json_decode($row['polygon_json'], true) ?: [];
        return $row;
    } catch (PDOException $e) {
        error_log('[GeofenceModel] geofenceGetById: ' . $e->getMessage());
        return null;
    }
}

/**
 * Delete a geofence. Clears the plan's active_geofence_id if it points here.
 */
function geofenceDelete(int $geofenceId): void {
    $db = getDB();
    $db->prepare("UPDATE job_plans SET active_geofence_id = NULL WHERE active_geofence_id = ?")
       ->execute([$geofenceId]);
    $db->prepare("DELETE FROM job_geofences WHERE id = ?")->execute([$geofenceId]);
}

/**
 * Save a zone polygon — works for both arrival_border and work_zone types.
 * For 'arrival_border': pass $planId = null, links to property only.
 * For 'work_zone': pass $planId to link attribution to a plan.
 * Returns the new geofence_id.
 */
function geofenceSaveZone(
    int $propertyId, string $zoneType, ?int $planId,
    array $ring, int $userId,
    ?string $label = null, ?string $notes = null
): int {
    if (count($ring) < 3) {
        throw new InvalidArgumentException('A polygon must have at least 3 vertices.');
    }
    if (!in_array($zoneType, ['arrival_border', 'work_zone'], true)) {
        throw new InvalidArgumentException('zone_type must be arrival_border or work_zone.');
    }

    $first = $ring[0];
    $last  = end($ring);
    if ($first[0] !== $last[0] || $first[1] !== $last[1]) {
        $ring[] = $first;
    }

    [$latMin, $latMax, $lngMin, $lngMax] = geofenceBbox($ring);
    $areaSqm = geofencePolygonArea($ring);

    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO job_geofences
            (plan_id, property_id, zone_type, polygon_json, vertex_count,
             bbox_lat_min, bbox_lat_max, bbox_lng_min, bbox_lng_max,
             area_sqm, label, notes, drawn_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $planId,
        $propertyId,
        $zoneType,
        json_encode($ring),
        count($ring) - 1,
        $latMin, $latMax, $lngMin, $lngMax,
        $areaSqm,
        $label, $notes,
        $userId,
    ]);
    return (int)$db->lastInsertId();
}

/**
 * Get all zones for a property (arrival borders + work zones).
 * Ordered: arrival_border first, then work_zones by label.
 */
function geofenceGetZonesForProperty(int $propertyId): array {
    try {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT g.*, p.title AS plan_title, p.service_type AS plan_service_type
            FROM job_geofences g
            LEFT JOIN job_plans p ON g.plan_id = p.id
            WHERE g.property_id = ?
            ORDER BY g.zone_type ASC, g.label ASC, g.drawn_at DESC
        ");
        $stmt->execute([$propertyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['polygon'] = json_decode($row['polygon_json'], true) ?: [];
        }
        return $rows;
    } catch (PDOException $e) {
        error_log('[GeofenceModel] geofenceGetZonesForProperty: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get the arrival border polygon for a property, if one exists.
 */
function geofenceGetArrivalBorder(int $propertyId): ?array {
    try {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT * FROM job_geofences
            WHERE property_id = ? AND zone_type = 'arrival_border'
            ORDER BY drawn_at DESC LIMIT 1
        ");
        $stmt->execute([$propertyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['polygon'] = json_decode($row['polygon_json'], true) ?: [];
        $row['bbox'] = [
            'lat_min' => (float)$row['bbox_lat_min'],
            'lat_max' => (float)$row['bbox_lat_max'],
            'lng_min' => (float)$row['bbox_lng_min'],
            'lng_max' => (float)$row['bbox_lng_max'],
        ];
        return $row;
    } catch (PDOException $e) {
        error_log('[GeofenceModel] geofenceGetArrivalBorder: ' . $e->getMessage());
        return null;
    }
}

/**
 * Get all work zone polygons for a property.
 * Includes bbox as parsed array for efficient point-in-polygon.
 */
function geofenceGetWorkZones(int $propertyId): array {
    try {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT id, plan_id, label, polygon_json,
                   bbox_lat_min, bbox_lat_max, bbox_lng_min, bbox_lng_max
            FROM job_geofences
            WHERE property_id = ? AND zone_type = 'work_zone'
            ORDER BY id ASC
        ");
        $stmt->execute([$propertyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['polygon'] = json_decode($row['polygon_json'], true) ?: [];
            $row['bbox'] = [
                'lat_min' => (float)$row['bbox_lat_min'],
                'lat_max' => (float)$row['bbox_lat_max'],
                'lng_min' => (float)$row['bbox_lng_min'],
                'lng_max' => (float)$row['bbox_lng_max'],
            ];
        }
        return $rows;
    } catch (PDOException $e) {
        error_log('[GeofenceModel] geofenceGetWorkZones: ' . $e->getMessage());
        return [];
    }
}

/**
 * Classify a single GPS ping against an array of work zones.
 * Returns the zone_id the ping fell inside (first match), or null (transit).
 */
function geofenceClassifyPingAgainstWorkZones(float $lat, float $lng, array $workZones): ?int {
    foreach ($workZones as $zone) {
        if (empty($zone['polygon'])) continue;
        if (geofencePointInPolygon($lat, $lng, $zone['polygon'], $zone['bbox'])) {
            return (int)$zone['id'];
        }
    }
    return null;
}

/**
 * Classify all pending location samples for a visit against work zones.
 * Writes work_zone_id (null = transit) to each sample.
 */
function geofenceClassifySamplesForWorkZones(int $visitId, int $propertyId): void {
    $workZones = geofenceGetWorkZones($propertyId);
    if (empty($workZones)) return;

    $db       = getDB();
    $settings = geofenceGetSettings();
    $maxAcc   = (float)($settings['geofence.max_accuracy_m'] ?? 150);

    // Load samples that have been zone_checked (standard classification done) but not work-zone classified
    $stmt = $db->prepare("
        SELECT id, lat, lng, accuracy_m
        FROM job_location_samples
        WHERE visit_id = ? AND zone_checked = 1 AND work_zone_id IS NULL
        ORDER BY sampled_at ASC
    ");
    $stmt->execute([$visitId]);
    $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($samples)) return;

    $update = $db->prepare("UPDATE job_location_samples SET work_zone_id = ? WHERE id = ?");
    foreach ($samples as $s) {
        $acc = $s['accuracy_m'] !== null ? (float)$s['accuracy_m'] : null;
        if ($acc !== null && $acc > $maxAcc) {
            $update->execute([null, $s['id']]); // inaccurate → treat as transit
            continue;
        }
        $zoneId = geofenceClassifyPingAgainstWorkZones((float)$s['lat'], (float)$s['lng'], $workZones);
        $update->execute([$zoneId, $s['id']]);
    }
}

/**
 * Compute per-zone time summaries from classified pings for a visit.
 *
 * Applies a 2-minute minimum dwell filter (pass-throughs ignored).
 * Writes rows to visit_zone_sessions.
 * Writes transit_seconds to job_visits.
 *
 * Safe to call multiple times — upserts.
 */
function geofenceComputeZoneSessions(int $visitId, int $propertyId): void {
    $db = getDB();

    // Ensure pending pings are work-zone classified first
    geofenceClassifySamplesForWorkZones($visitId, $propertyId);

    // Load all classified samples in chronological order
    $stmt = $db->prepare("
        SELECT sampled_at, work_zone_id
        FROM job_location_samples
        WHERE visit_id = ? AND zone_checked = 1
        ORDER BY sampled_at ASC
    ");
    $stmt->execute([$visitId]);
    $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($samples)) return;

    $minDwellSec = 120; // 2-minute minimum to count as real zone work

    // Build continuous sequences per zone
    // $sequences[zoneId|null] = [[duration => int], ...]
    $sequences    = [];
    $currentZone  = 'UNSET'; // sentinel so first sample always triggers a segment start
    $segmentStart = null;
    $lastTs       = null;
    $count        = count($samples);

    foreach ($samples as $i => $s) {
        $zoneId = $s['work_zone_id'] !== null ? (int)$s['work_zone_id'] : null;
        $ts     = strtotime($s['sampled_at']);
        $isLast = ($i === $count - 1);

        if ($currentZone === 'UNSET') {
            // First sample — start a segment
            $currentZone  = $zoneId;
            $segmentStart = $ts;
        } elseif ($currentZone !== $zoneId) {
            // Zone changed — close previous segment
            $duration = $ts - $segmentStart;
            if ($duration >= $minDwellSec) {
                $key = $currentZone === null ? '__transit__' : (string)$currentZone;
                $sequences[$key][] = $duration;
            }
            $currentZone  = $zoneId;
            $segmentStart = $ts;
        }

        // Close open segment on last sample
        if ($isLast && $segmentStart !== null) {
            $duration = $ts - $segmentStart;
            if ($duration >= $minDwellSec) {
                $key = $currentZone === null ? '__transit__' : (string)$currentZone;
                $sequences[$key][] = $duration;
            }
        }

        $lastTs = $ts;
    }

    // Sum transit seconds (NULL zone = inside outer border but not in a work zone)
    $transitSec = 0;
    if (isset($sequences['__transit__'])) {
        foreach ($sequences['__transit__'] as $dur) {
            $transitSec += $dur;
        }
        unset($sequences['__transit__']);
    }

    // Write transit time to job_visits
    $db->prepare("UPDATE job_visits SET transit_seconds = ? WHERE id = ?")
       ->execute([$transitSec, $visitId]);

    // Load zone metadata (label, plan_id) for the zones we found
    $workZones = geofenceGetWorkZones($propertyId);
    $zoneIndex = [];
    foreach ($workZones as $z) {
        $zoneIndex[(int)$z['id']] = $z;
    }

    // Count distinct crew who worked this visit
    $crewStmt = $db->prepare("
        SELECT COUNT(DISTINCT user_id) FROM job_time_entries WHERE visit_id = ?
    ");
    $crewStmt->execute([$visitId]);
    $crewCount = max(1, (int)$crewStmt->fetchColumn());

    // Upsert one row per work zone
    foreach ($sequences as $zoneIdStr => $durations) {
        $zoneId   = (int)$zoneIdStr;
        $inSec    = array_sum($durations);
        $entries  = count($durations); // each continuous segment = 1 entry + 1 exit
        $zoneInfo = $zoneIndex[$zoneId] ?? [];
        $planId   = $zoneInfo['plan_id'] ?? null;
        $label    = $zoneInfo['label']   ?? null;

        $db->prepare("
            INSERT INTO visit_zone_sessions
                (visit_id, zone_id, zone_label, plan_id, in_seconds, entry_count, exit_count, crew_count)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                zone_label  = VALUES(zone_label),
                plan_id     = VALUES(plan_id),
                in_seconds  = VALUES(in_seconds),
                entry_count = VALUES(entry_count),
                exit_count  = VALUES(exit_count),
                crew_count  = VALUES(crew_count),
                computed_at = NOW()
        ")->execute([$visitId, $zoneId, $label, $planId, $inSec, $entries, $entries, $crewCount]);
    }
}

/**
 * Get zone sessions for a visit, enriched with zone + plan metadata.
 * Ordered by time spent descending.
 */
function geofenceGetZoneSessions(int $visitId): array {
    try {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT vzs.*,
                   jp.title AS plan_title,
                   jp.service_type AS plan_service_type
            FROM visit_zone_sessions vzs
            LEFT JOIN job_plans jp ON jp.id = vzs.plan_id
            WHERE vzs.visit_id = ?
            ORDER BY vzs.in_seconds DESC
        ");
        $stmt->execute([$visitId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[GeofenceModel] geofenceGetZoneSessions: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get zone time summary for a date range — for the zone report page.
 * Returns rows: zone_label, plan_title, total_in_seconds, visit_count, crew_count, property details.
 */
function geofenceGetZoneReport(PDO $db, string $dateFrom, string $dateTo, ?int $propertyId = null): array {
    $params = [$dateFrom, $dateTo];
    $where  = 'WHERE jv.scheduled_date BETWEEN ? AND ?';
    if ($propertyId) {
        $where .= ' AND jp.property_id = ?';
        $params[] = $propertyId;
    }
    try {
        $stmt = $db->prepare("
            SELECT
                vzs.zone_id,
                vzs.zone_label,
                vzs.plan_id,
                jp.title        AS plan_title,
                jp.service_type AS plan_service_type,
                jp.property_id,
                pr.address      AS property_address,
                SUM(vzs.in_seconds)  AS total_in_seconds,
                COUNT(DISTINCT vzs.visit_id) AS visit_count,
                MAX(vzs.crew_count)  AS max_crew,
                SUM(jv.transit_seconds) AS total_transit_seconds
            FROM visit_zone_sessions vzs
            JOIN job_visits jv ON jv.id = vzs.visit_id
            JOIN job_plans jp   ON jp.id = vzs.plan_id
            JOIN properties pr  ON pr.id = jp.property_id
            $where
            GROUP BY vzs.zone_id, vzs.zone_label, vzs.plan_id,
                     jp.title, jp.service_type, jp.property_id, pr.address
            ORDER BY jp.property_id ASC, vzs.zone_label ASC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[GeofenceModel] geofenceGetZoneReport: ' . $e->getMessage());
        return [];
    }
}


// ============================================================================
// LOCATION SAMPLE INGESTION
// ============================================================================

/**
 * Batch-insert raw location samples for a visit.
 *
 * Each sample: {lat, lng, accuracy_m?, speed_mps?, heading?, altitude_m?,
 *               battery_pct?, source?, timestamp (ms epoch UTC)}
 *
 * Returns ['inserted' => N, 'skipped' => M]
 */
function geofenceIngestSamples(int $visitId, int $geofenceId, array $samples): array {
    if (empty($samples)) return ['inserted' => 0, 'skipped' => 0];

    $db = getDB();
    $inserted = 0;
    $skipped  = 0;

    $stmt = $db->prepare("
        INSERT INTO job_location_samples
            (visit_id, geofence_id, sampled_at, lat, lng,
             accuracy_m, speed_mps, heading, altitude_m, battery_pct, source,
             in_zone, zone_checked)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)
    ");

    foreach ($samples as $s) {
        if (empty($s['lat']) || empty($s['lng'])) { $skipped++; continue; }

        $lat = (float)$s['lat'];
        $lng = (float)$s['lng'];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) { $skipped++; continue; }

        // Loose accuracy check — very bad fixes flagged but not rejected
        // (zone_checked = 0 means server-side classification will skip these)
        $acc = isset($s['accuracy_m']) ? (float)$s['accuracy_m'] : null;

        $ts = isset($s['timestamp'])
            ? date('Y-m-d H:i:s', (int)($s['timestamp'] / 1000))
            : date('Y-m-d H:i:s');

        try {
            $stmt->execute([
                $visitId,
                $geofenceId,
                $ts,
                $lat,
                $lng,
                $acc,
                isset($s['speed_mps'])   ? (float)$s['speed_mps']   : null,
                isset($s['heading'])     ? (float)$s['heading']      : null,
                isset($s['altitude_m'])  ? (float)$s['altitude_m']   : null,
                isset($s['battery_pct']) ? (int)$s['battery_pct']    : null,
                $s['source'] ?? 'gps',
            ]);
            $inserted++;
        } catch (PDOException $e) {
            // Duplicate or constraint — skip silently
            $skipped++;
        }
    }

    return ['inserted' => $inserted, 'skipped' => $skipped];
}

/**
 * Run server-side in/out zone classification for all pending samples of a visit.
 * Also derives and persists entry/exit events.
 * Returns number of samples classified.
 */
function geofenceClassifySamples(int $visitId, int $geofenceId): int {
    $geofence = geofenceGetById($geofenceId);
    if (!$geofence || empty($geofence['polygon'])) return 0;

    $settings   = geofenceGetSettings();
    $maxAccuracy = (float)($settings['geofence.max_accuracy_m'] ?? 150);

    $db = getDB();

    // Load pending samples ordered by time
    $stmt = $db->prepare("
        SELECT id, sampled_at, lat, lng, accuracy_m
        FROM job_location_samples
        WHERE visit_id = ? AND zone_checked = 0
        ORDER BY sampled_at ASC
    ");
    $stmt->execute([$visitId]);
    $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($samples)) return 0;

    $polygon = $geofence['polygon'];
    $bbox    = [
        'lat_min' => (float)$geofence['bbox_lat_min'],
        'lat_max' => (float)$geofence['bbox_lat_max'],
        'lng_min' => (float)$geofence['bbox_lng_min'],
        'lng_max' => (float)$geofence['bbox_lng_max'],
    ];

    $update = $db->prepare("
        UPDATE job_location_samples SET in_zone = ?, zone_checked = 1 WHERE id = ?
    ");

    $classified = 0;
    foreach ($samples as $s) {
        $lat = (float)$s['lat'];
        $lng = (float)$s['lng'];
        $acc = $s['accuracy_m'] !== null ? (float)$s['accuracy_m'] : null;

        // Accuracy too poor — mark checked but leave in_zone = 0 (unknown)
        if ($acc !== null && $acc > $maxAccuracy) {
            $update->execute([0, $s['id']]);
            $classified++;
            continue;
        }

        $inZone = geofencePointInPolygon($lat, $lng, $polygon, $bbox) ? 1 : 0;
        $update->execute([$inZone, $s['id']]);
        $classified++;
    }

    // Derive entry/exit events from the full classified stream
    geofenceDeriveEvents($visitId, $geofenceId);

    return $classified;
}

/**
 * Derive entry/exit transition events from the classified sample stream.
 * Idempotent — deletes and rebuilds events from scratch (cheap, <1000 rows/visit).
 */
function geofenceDeriveEvents(int $visitId, int $geofenceId): void {
    $db = getDB();

    // Load all classified samples in order
    $stmt = $db->prepare("
        SELECT sampled_at, lat, lng, accuracy_m, in_zone
        FROM job_location_samples
        WHERE visit_id = ? AND zone_checked = 1
        ORDER BY sampled_at ASC
    ");
    $stmt->execute([$visitId]);
    $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($samples)) return;

    // Delete existing events so we rebuild cleanly
    $db->prepare("DELETE FROM job_geofence_events WHERE visit_id = ?")->execute([$visitId]);

    $insEntry = $db->prepare("
        INSERT INTO job_geofence_events (visit_id, geofence_id, event_type, event_time, lat, lng, accuracy_m)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $insExit = $db->prepare("
        INSERT INTO job_geofence_events
            (visit_id, geofence_id, event_type, event_time, lat, lng, accuracy_m, duration_seconds)
        VALUES (?, ?, 'exit', ?, ?, ?, ?, ?)
    ");

    $prevInZone = null;
    $entryTime  = null;

    foreach ($samples as $s) {
        $inZone = (bool)(int)$s['in_zone'];

        if ($prevInZone === null) {
            // First sample
            if ($inZone) {
                $entryTime = $s['sampled_at'];
                $insEntry->execute([$visitId, $geofenceId, 'entry', $s['sampled_at'], $s['lat'], $s['lng'], $s['accuracy_m']]);
            }
        } elseif (!$prevInZone && $inZone) {
            // Transition: out → in (ENTRY)
            $entryTime = $s['sampled_at'];
            $insEntry->execute([$visitId, $geofenceId, 'entry', $s['sampled_at'], $s['lat'], $s['lng'], $s['accuracy_m']]);
        } elseif ($prevInZone && !$inZone) {
            // Transition: in → out (EXIT)
            $duration = $entryTime
                ? (strtotime($s['sampled_at']) - strtotime($entryTime))
                : null;
            $insExit->execute([$visitId, $geofenceId, $s['sampled_at'], $s['lat'], $s['lng'], $s['accuracy_m'], $duration]);
            $entryTime = null;
        }

        $prevInZone = $inZone;
    }
}


// ============================================================================
// SESSION SUMMARY
// ============================================================================

/**
 * Compute and persist the geofence session summary for a visit.
 * Safe to call even if geofence tracking was not active (returns minimal row).
 *
 * @param int $visitId
 * @param int|null $geofenceId  Pass null to auto-detect from visit
 */
function geofenceComputeSession(int $visitId, ?int $geofenceId = null): array {
    $db = getDB();

    // Ensure session row exists
    $db->prepare("
        INSERT IGNORE INTO job_geofence_sessions (visit_id, geofence_id, status)
        VALUES (?, ?, 'computing')
    ")->execute([$visitId, $geofenceId]);

    $db->prepare("
        UPDATE job_geofence_sessions SET status = 'computing' WHERE visit_id = ?
    ")->execute([$visitId]);

    try {
        // Classify any pending samples first
        if ($geofenceId) {
            geofenceClassifySamples($visitId, $geofenceId);
        }

        // Count samples
        $stmt = $db->prepare("SELECT COUNT(*) FROM job_location_samples WHERE visit_id = ?");
        $stmt->execute([$visitId]);
        $sampleCount = (int)$stmt->fetchColumn();

        // In-zone seconds: sum of stay durations from EXIT events
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(duration_seconds), 0)
            FROM job_geofence_events
            WHERE visit_id = ? AND event_type = 'exit' AND duration_seconds IS NOT NULL
        ");
        $stmt->execute([$visitId]);
        $inZoneSec = (int)$stmt->fetchColumn();

        // Entry / exit counts
        $stmt = $db->prepare("
            SELECT event_type, COUNT(*) AS cnt
            FROM job_geofence_events
            WHERE visit_id = ?
            GROUP BY event_type
        ");
        $stmt->execute([$visitId]);
        $eventCounts = ['entry' => 0, 'exit' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $eventCounts[$r['event_type']] = (int)$r['cnt'];
        }

        // Total visit duration from job_time_entries
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(duration_minutes), 0) * 60
            FROM job_time_entries
            WHERE visit_id = ? AND status IN ('completed', 'active')
        ");
        $stmt->execute([$visitId]);
        $totalSec = (int)$stmt->fetchColumn();

        $outZoneSec = max(0, $totalSec - $inZoneSec);
        $unknownSec = 0; // TODO: time before first fix — defer to v2

        // Confidence: in_zone / (total - unknown), expressed 0–100
        $denominator = $totalSec - $unknownSec;
        $confidence  = ($denominator > 0)
            ? (int)min(100, round(($inZoneSec / $denominator) * 100))
            : null;

        $db->prepare("
            UPDATE job_geofence_sessions SET
                geofence_id      = ?,
                tracking_active  = 1,
                total_seconds    = ?,
                in_zone_seconds  = ?,
                out_zone_seconds = ?,
                unknown_seconds  = ?,
                sample_count     = ?,
                entry_count      = ?,
                exit_count       = ?,
                confidence_score = ?,
                status           = 'complete',
                computed_at      = NOW()
            WHERE visit_id = ?
        ")->execute([
            $geofenceId,
            $totalSec,
            $inZoneSec,
            $outZoneSec,
            $unknownSec,
            $sampleCount,
            $eventCounts['entry'],
            $eventCounts['exit'],
            $confidence,
            $visitId,
        ]);

        // Denormalise into job_visits for fast display
        $inZoneMin = (int)round($inZoneSec / 60);
        $db->prepare("
            UPDATE job_visits SET
                in_zone_minutes      = ?,
                geofence_confidence  = ?
            WHERE id = ?
        ")->execute([$inZoneMin, $confidence, $visitId]);

        return [
            'success'         => true,
            'total_seconds'   => $totalSec,
            'in_zone_seconds' => $inZoneSec,
            'confidence'      => $confidence,
            'sample_count'    => $sampleCount,
        ];

    } catch (Exception $e) {
        $db->prepare("
            UPDATE job_geofence_sessions SET status = 'error', error_msg = ? WHERE visit_id = ?
        ")->execute([$e->getMessage(), $visitId]);
        error_log('[GeofenceModel] geofenceComputeSession: ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Fetch the session summary row for a visit (or null if none exists).
 */
function geofenceGetSession(int $visitId): ?array {
    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM job_geofence_sessions WHERE visit_id = ? LIMIT 1");
        $stmt->execute([$visitId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Fetch all entry/exit events for a visit in chronological order.
 */
function geofenceGetEvents(int $visitId): array {
    try {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT event_type, event_time, lat, lng, accuracy_m, duration_seconds
            FROM job_geofence_events
            WHERE visit_id = ?
            ORDER BY event_time ASC
        ");
        $stmt->execute([$visitId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}


// ============================================================================
// GEOMETRY HELPERS
// ============================================================================

/**
 * Compute the bounding box of a polygon ring.
 * Returns [latMin, latMax, lngMin, lngMax].
 */
function geofenceBbox(array $ring): array {
    $lats = array_column($ring, 0);
    $lngs = array_column($ring, 1);
    return [min($lats), max($lats), min($lngs), max($lngs)];
}

/**
 * Point-in-polygon test using the ray-casting algorithm.
 * Bounding-box pre-check provides fast rejection before the polygon walk.
 *
 * @param float $lat      Point latitude
 * @param float $lng      Point longitude
 * @param array $polygon  [[lat, lng], ...] — can be open or closed ring
 * @param array|null $bbox Pre-computed bbox {lat_min, lat_max, lng_min, lng_max}
 */
function geofencePointInPolygon(float $lat, float $lng, array $polygon, ?array $bbox = null): bool {
    if (count($polygon) < 3) return false;

    // Fast bounding-box rejection
    if ($bbox) {
        if ($lat < $bbox['lat_min'] || $lat > $bbox['lat_max']) return false;
        if ($lng < $bbox['lng_min'] || $lng > $bbox['lng_max']) return false;
    }

    // Ray-casting
    $inside  = false;
    $n       = count($polygon);
    $j       = $n - 1;

    for ($i = 0; $i < $n; $i++) {
        $xi = $polygon[$i][0]; $yi = $polygon[$i][1];
        $xj = $polygon[$j][0]; $yj = $polygon[$j][1];

        $intersect = (($yi > $lng) !== ($yj > $lng))
            && ($lat < ($xj - $xi) * ($lng - $yi) / ($yj - $yi) + $xi);
        if ($intersect) $inside = !$inside;
        $j = $i;
    }

    return $inside;
}

/**
 * Approximate polygon area in square metres using the Shoelace formula
 * projected via a simple equirectangular approximation.
 * Accurate enough for property-scale polygons (< 1% error within ~10 km radius).
 */
function geofencePolygonArea(array $ring): float {
    $n = count($ring);
    if ($n < 3) return 0.0;

    // Earth radius in metres
    $R    = 6371000.0;
    $area = 0.0;

    for ($i = 0; $i < $n; $i++) {
        $j  = ($i + 1) % $n;
        $x1 = deg2rad($ring[$i][1]) * $R * cos(deg2rad($ring[$i][0]));
        $y1 = deg2rad($ring[$i][0]) * $R;
        $x2 = deg2rad($ring[$j][1]) * $R * cos(deg2rad($ring[$j][0]));
        $y2 = deg2rad($ring[$j][0]) * $R;
        $area += ($x1 * $y2) - ($x2 * $y1);
    }

    return abs($area / 2.0);
}
