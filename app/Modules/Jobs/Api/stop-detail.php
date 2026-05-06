<?php
/**
 * Stop Detail API
 * Enriched stop data for the schedule detail modal.
 *
 * GET ?stop_id=N
 * Returns crew GPS, clock-in status, visit checklist/photo stats, property tags.
 */
declare(strict_types=1);
header('Content-Type: application/json');

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r    = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return round($r * 2 * asin(sqrt($a)), 2);
}

function parseChecklistStats(?string $jsonNew, ?string $jsonOld): array {
    if ($jsonNew !== null && $jsonNew !== '') {
        $d = json_decode($jsonNew, true);
        if (is_array($d) && !empty($d)) {
            $total = count($d);
            $done  = count(array_filter($d, function ($i) { return !empty($i['checked']); }));
            return ['total' => $total, 'complete' => $done];
        }
    }
    if ($jsonOld !== null && $jsonOld !== '') {
        $d = json_decode($jsonOld, true);
        if (is_array($d)) {
            return ['total' => count($d), 'complete' => count(array_filter($d))];
        }
    }
    return ['total' => 0, 'complete' => 0];
}

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';

    requireLogin();
    $user = getCurrentUser();
    session_write_close(); // read-only endpoint — release session lock early

    $stopId = isset($_GET['stop_id']) ? (int)$_GET['stop_id'] : 0;
    if ($stopId < 1) {
        http_response_code(400);
        echo json_encode(['error' => 'stop_id is required']);
        exit;
    }

    $db = getDB();

    // ── A: Stop + Property + Contact ─────────────────────────────────────
    $stmt = $db->prepare("
        SELECT cs.id AS stop_id, cs.stop_date, cs.status AS stop_status,
            cs.estimated_arrival, cs.estimated_departure,
            p.id AS property_id, p.address AS property_address, p.city AS property_city,
            p.latitude, p.longitude, p.notes AS property_notes,
            COALESCE(p.total_lawn_sqft, p.lawn_size_sqft) AS lawn_sqft,
            ct.id AS contact_id,
            CONCAT(ct.first_name, ' ', ct.last_name) AS contact_name,
            COALESCE(ct.phone, ct.mobile) AS contact_phone,
            ct.email AS contact_email,
            co.company_name,
            (SELECT MAX(cs2.stop_date) FROM calendar_stops cs2
             WHERE cs2.property_id = p.id
               AND cs2.status = 'completed'
               AND cs2.stop_date < cs.stop_date) AS last_completed_date
        FROM calendar_stops cs
        JOIN properties p ON cs.property_id = p.id
        LEFT JOIN contacts ct ON p.site_contact_id = ct.id
        LEFT JOIN company_properties cp ON p.id = cp.property_id
        LEFT JOIN companies co ON cp.company_id = co.id
        WHERE cs.id = ?
        LIMIT 1
    ");
    $stmt->execute([$stopId]);
    $stop = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$stop) {
        http_response_code(404);
        echo json_encode(['error' => 'Stop not found']);
        exit;
    }

    $propertyId = (int)$stop['property_id'];
    $propLat    = $stop['latitude'] !== null ? (float)$stop['latitude'] : null;
    $propLng    = $stop['longitude'] !== null ? (float)$stop['longitude'] : null;

    // ── B: Visits for this stop ───────────────────────────────────────────
    $visitStmt = $db->prepare("
        SELECT jv.id AS visit_id, jv.visit_number, jv.status AS visit_status,
            jv.started_at, jv.completed_at,
            jp.id AS plan_id, jp.plan_number, jp.title AS plan_title,
            jp.service_type, jp.price_per_visit,
            jp.estimated_duration_minutes AS estimated_duration
        FROM job_visits jv
        JOIN job_plans jp ON jv.plan_id = jp.id
        WHERE jv.stop_id = ? AND jv.status NOT IN ('cancelled')
        ORDER BY jv.id ASC
    ");
    $visitStmt->execute([$stopId]);
    $visitRows = $visitStmt->fetchAll(PDO::FETCH_ASSOC);
    $visitIds  = array_values(array_column($visitRows, 'visit_id'));

    // ── C: Photo counts per visit (batched) ──────────────────────────────
    $photoCounts = [];
    if (!empty($visitIds)) {
        $phP   = implode(',', array_fill(0, count($visitIds), '?'));
        $phStmt = $db->prepare("
            SELECT visit_id, photo_type, COUNT(*) AS cnt
            FROM visit_photos
            WHERE visit_id IN ({$phP}) AND deleted_at IS NULL
            GROUP BY visit_id, photo_type
        ");
        $phStmt->execute($visitIds);
        while ($r = $phStmt->fetch(PDO::FETCH_ASSOC)) {
            $vid = (int)$r['visit_id'];
            if (!isset($photoCounts[$vid])) $photoCounts[$vid] = [];
            $photoCounts[$vid][$r['photo_type']] = (int)$r['cnt'];
        }
    }

    // ── D: Note counts per visit (batched) ───────────────────────────────
    $noteCounts = [];
    if (!empty($visitIds)) {
        $ntP    = implode(',', array_fill(0, count($visitIds), '?'));
        $ntStmt = $db->prepare("
            SELECT visit_id, COUNT(*) AS note_count
            FROM visit_notes WHERE visit_id IN ({$ntP})
            GROUP BY visit_id
        ");
        $ntStmt->execute($visitIds);
        while ($r = $ntStmt->fetch(PDO::FETCH_ASSOC)) {
            $noteCounts[(int)$r['visit_id']] = (int)$r['note_count'];
        }
    }

    // ── E: Crew from junction table, fall back to cs.crew_id ─────────────
    $crewStmt = $db->prepare("
        SELECT csc.user_id, u.full_name
        FROM calendar_stop_crew csc
        JOIN users u ON csc.user_id = u.id
        WHERE csc.stop_id = ?
        ORDER BY csc.id ASC
    ");
    $crewStmt->execute([$stopId]);
    $crewList = $crewStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($crewList)) {
        $fbStmt = $db->prepare("
            SELECT cs.crew_id AS user_id, u.full_name
            FROM calendar_stops cs
            JOIN users u ON cs.crew_id = u.id
            WHERE cs.id = ? AND cs.crew_id IS NOT NULL
        ");
        $fbStmt->execute([$stopId]);
        $fbRow = $fbStmt->fetch(PDO::FETCH_ASSOC);
        if ($fbRow) $crewList = [$fbRow];
    }

    $crewIds = array_values(array_filter(array_column($crewList, 'user_id')));

    // ── F: GPS + clock-in per crew (batched, MySQL 5.7 MAX subquery) ─────
    $crewData = [];
    if (!empty($crewIds)) {
        $crP    = implode(',', array_fill(0, count($crewIds), '?'));
        $crStmt = $db->prepare("
            SELECT u.id AS user_id, u.full_name,
                clh.latitude AS gps_lat, clh.longitude AS gps_lng,
                clh.accuracy_meters,
                UNIX_TIMESTAMP(clh.timestamp) AS gps_epoch,
                CASE WHEN tce.id IS NOT NULL THEN 1 ELSE 0 END AS is_clocked_in,
                tce.clock_in AS clock_in_time
            FROM users u
            LEFT JOIN (
                SELECT crew_id, MAX(id) AS max_id
                FROM crew_location_history
                WHERE UNIX_TIMESTAMP(timestamp) > UNIX_TIMESTAMP() - 86400
                GROUP BY crew_id
            ) latest ON latest.crew_id = u.id
            LEFT JOIN crew_location_history clh
                ON clh.id = latest.max_id
            LEFT JOIN time_clock_entries tce
                ON tce.user_id = u.id
               AND tce.status = 'active'
               AND tce.clock_out IS NULL
            WHERE u.id IN ({$crP})
        ");
        $crStmt->execute($crewIds);

        while ($cr = $crStmt->fetch(PDO::FETCH_ASSOC)) {
            $gpsLat     = $cr['gps_lat'] !== null ? (float)$cr['gps_lat'] : null;
            $gpsLng     = $cr['gps_lng'] !== null ? (float)$cr['gps_lng'] : null;
            $gpsEpoch   = $cr['gps_epoch'] !== null ? (int)$cr['gps_epoch'] : null;
            $secondsAgo = $gpsEpoch !== null ? (time() - $gpsEpoch) : null;

            $distKm = null;
            if ($gpsLat !== null && $gpsLng !== null && $propLat !== null && $propLng !== null) {
                $distKm = haversineKm($propLat, $propLng, $gpsLat, $gpsLng);
            }

            $parts    = explode(' ', $cr['full_name'], 2);
            $initials = strtoupper(substr($parts[0] ?? '', 0, 1) . substr($parts[1] ?? '', 0, 1));

            $crewData[] = [
                'user_id'         => (int)$cr['user_id'],
                'full_name'       => $cr['full_name'],
                'initials'        => $initials,
                'is_clocked_in'   => (bool)(int)$cr['is_clocked_in'],
                'clock_in_time'   => $cr['clock_in_time'],
                'gps_lat'         => $gpsLat,
                'gps_lng'         => $gpsLng,
                'gps_seconds_ago' => $secondsAgo,
                'gps_accuracy_m'  => $cr['accuracy_meters'] !== null ? (float)$cr['accuracy_meters'] : null,
                'distance_km'     => $distKm,
            ];
        }
    }

    // ── G: Property tags ─────────────────────────────────────────────────
    $tagStmt = $db->prepare("
        SELECT t.tag_label, t.tag_color, t.icon, et.tag_value, t.has_value
        FROM entity_tags et
        JOIN tags t ON t.id = et.tag_id
        WHERE et.entity_type = 'property'
          AND et.entity_id = ?
          AND t.show_on_card = 1
          AND t.is_active = 1
        ORDER BY t.sort_order ASC, t.tag_label ASC
    ");
    $tagStmt->execute([$propertyId]);
    $tagData = $tagStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Assemble visits ───────────────────────────────────────────────────
    $visits = [];
    foreach ($visitRows as $vr) {
        $vid    = (int)$vr['visit_id'];
        $byType = $photoCounts[$vid] ?? [];

        $visits[] = [
            'visit_id'           => $vid,
            'visit_number'       => $vr['visit_number'],
            'visit_status'       => $vr['visit_status'],
            'plan_id'            => (int)$vr['plan_id'],
            'plan_number'        => $vr['plan_number'],
            'plan_title'         => $vr['plan_title'],
            'service_type'       => $vr['service_type'],
            'price_per_visit'    => $vr['price_per_visit'] !== null ? (float)$vr['price_per_visit'] : null,
            'estimated_duration' => $vr['estimated_duration'] !== null ? (int)$vr['estimated_duration'] : null,
            'checklist_total'    => 0,
            'checklist_complete' => 0,
            'photos_before'      => $byType['before']  ?? 0,
            'photos_after'       => $byType['after']   ?? 0,
            'photos_during'      => $byType['during']  ?? 0,
            'note_count'         => $noteCounts[$vid]  ?? 0,
            'started_at'         => $vr['started_at'],
            'completed_at'       => $vr['completed_at'],
        ];
    }

    // ── Final payload ─────────────────────────────────────────────────────
    $payload = [
        'stop_id'            => (int)$stop['stop_id'],
        'stop_date'          => $stop['stop_date'],
        'stop_status'        => $stop['stop_status'],
        'estimated_arrival'  => $stop['estimated_arrival'],
        'estimated_departure'=> $stop['estimated_departure'],
        'property_id'        => $propertyId,
        'property_address'   => $stop['property_address'],
        'property_city'      => $stop['property_city'],
        'property_notes'     => $stop['property_notes'],
        'lawn_sqft'          => $stop['lawn_sqft'] !== null ? (float)$stop['lawn_sqft'] : null,
        'last_completed_date'=> $stop['last_completed_date'],
        'latitude'           => $propLat,
        'longitude'          => $propLng,
        'contact_id'         => $stop['contact_id'] !== null ? (int)$stop['contact_id'] : null,
        'contact_name'       => $stop['contact_name'],
        'contact_phone'      => $stop['contact_phone'],
        'contact_email'      => $stop['contact_email'],
        'company_name'       => $stop['company_name'],
        'crew'               => $crewData,
        'visits'             => $visits,
        'tags'               => $tagData,
    ];

    echo json_encode(['success' => true, 'stop' => $payload]);

} catch (PDOException $e) {
    error_log('stop-detail API PDO: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
} catch (Throwable $e) {
    error_log('stop-detail API: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
