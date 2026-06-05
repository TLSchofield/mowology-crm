<?php
/**
 * ONE-TIME ADMIN REPAIR — Backfill visit_id onto orphaned crew GPS pings.
 *
 * Problem this fixes:
 *   When a crew completes a job but the server-side timer never started
 *   (offline / crew-assignment mismatch), the GPS pings are recorded under
 *   the crew member's id with visit_id = NULL. The customer portal + service
 *   report query pings strictly by visit_id, so the "GPS Verified Route" map
 *   is blank even though the location trail exists.
 *
 * What it does:
 *   For a given visit, finds the property coordinates and service date, then
 *   locates orphaned pings (visit_id IS NULL) from ANY location-tracked crew
 *   within ~150m of the property on that date, and attributes them to the visit.
 *
 * Safety:
 *   - Admin only.
 *   - DRY-RUN by default. Shows exactly what it would link. Changes nothing.
 *   - Pass &apply=1 to commit.
 *   - Only touches rows where visit_id IS NULL (never steals attributed pings).
 *   - Bounded to a tight geofence + the single service date.
 *
 * Usage:
 *   /crm/api/repair-visit-pings.php?visit_id=NNN              (dry run)
 *   /crm/api/repair-visit-pings.php?visit_id=NNN&apply=1      (commit)
 *   /crm/api/repair-visit-pings.php?contact_id=581            (auto-resolve latest visit, dry run)
 *
 * DELETE THIS FILE after the affected visits are repaired.
 */

declare(strict_types=1);

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

require_once PUBLIC_ROOT . '/loginAuth/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requireLogin();
    $user = getCurrentUser();
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin only']);
        exit;
    }

    $db = getDB();

    // ── DEBUG: run the portal's exact completed-visits query and surface
    // any SQL error the portal silently swallows. ?debug_portal=581
    if (isset($_GET['debug_portal'])) {
        $cid = (int)$_GET['debug_portal'];
        $out = ['contact_id' => $cid];
        try {
            $q = $db->prepare("
                SELECT jv.id, jv.visit_number, jv.scheduled_date, jv.status,
                       jv.completed_at, jv.actual_duration_minutes, jv.actual_amount,
                       jp.title AS service_name, jp.service_type,
                       p.address AS property_address
                FROM job_visits jv
                JOIN job_plans jp ON jv.plan_id = jp.id
                JOIN properties p ON jp.property_id = p.id
                WHERE p.site_contact_id = ?
                  AND jv.status = 'completed'
                ORDER BY jv.scheduled_date DESC
                LIMIT 20
            ");
            $q->execute([$cid]);
            $out['portal_query_rows'] = $q->fetchAll(PDO::FETCH_ASSOC);
            $out['row_count'] = count($out['portal_query_rows']);
        } catch (Throwable $e) {
            $out['portal_query_ERROR'] = $e->getMessage();
        }
        // Also: what does the visit look like raw, and its property linkage?
        try {
            $q2 = $db->prepare("
                SELECT jv.id, jv.status, jv.plan_id, jp.property_id,
                       p.site_contact_id, p.address
                FROM job_visits jv
                JOIN job_plans jp ON jv.plan_id = jp.id
                JOIN properties p ON jp.property_id = p.id
                WHERE jv.id = 1418
            ");
            $q2->execute();
            $out['visit_1418_linkage'] = $q2->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $out['linkage_ERROR'] = $e->getMessage();
        }
        echo json_encode($out, JSON_PRETTY_PRINT);
        exit;
    }

    // ── LIST: all visits for a contact so you can target the right visit_id.
    // ?list_visits=56
    if (isset($_GET['list_visits'])) {
        $cid = (int)$_GET['list_visits'];
        $q = $db->prepare("
            SELECT jv.id AS visit_id,
                   jv.visit_number,
                   jv.scheduled_date,
                   jv.status,
                   jv.started_at,
                   jv.completed_at,
                   jp.title AS service_name,
                   p.address
            FROM job_visits jv
            JOIN job_plans jp ON jv.plan_id = jp.id
            JOIN properties p ON jp.property_id = p.id
            WHERE p.site_contact_id = ?
            ORDER BY jv.scheduled_date DESC, jv.id DESC
        ");
        $q->execute([$cid]);
        echo json_encode([
            'contact_id' => $cid,
            'visits'     => $q->fetchAll(PDO::FETCH_ASSOC),
        ], JSON_PRETTY_PRINT);
        exit;
    }

    $visitId   = isset($_GET['visit_id'])   ? (int)$_GET['visit_id']   : 0;
    $contactId = isset($_GET['contact_id']) ? (int)$_GET['contact_id'] : 0;
    $apply     = isset($_GET['apply']) && $_GET['apply'] === '1';
    $radiusM   = isset($_GET['radius']) ? max(30, min(500, (int)$_GET['radius'])) : 150;
    // Optional: scope to a single crew member's pings. Essential when two crews
    // serviced the same property on the same day (e.g. fertilizer + lawn cut)
    // so each visit gets only its own worker's GPS trail.
    $crewId    = isset($_GET['crew_id']) ? (int)$_GET['crew_id'] : 0;

    // Resolve visit_id from contact if not given — pick the most recent visit
    // for any property whose site_contact_id matches.
    if (!$visitId && $contactId) {
        $r = $db->prepare("
            SELECT jv.id
            FROM job_visits jv
            JOIN job_plans jp ON jv.plan_id = jp.id
            JOIN properties p ON jp.property_id = p.id
            WHERE p.site_contact_id = ?
            ORDER BY jv.scheduled_date DESC, jv.id DESC
            LIMIT 1
        ");
        $r->execute([$contactId]);
        $visitId = (int)($r->fetchColumn() ?: 0);
    }

    if (!$visitId) {
        echo json_encode(['error' => 'Provide visit_id or contact_id']);
        exit;
    }

    // Load visit + property coordinates + service date.
    $vstmt = $db->prepare("
        SELECT jv.id            AS visit_id,
               jv.visit_number,
               jv.scheduled_date,
               jv.status,
               jv.started_at,
               jv.completed_at,
               p.id             AS property_id,
               p.address,
               p.latitude,
               p.longitude,
               p.site_contact_id
        FROM job_visits jv
        JOIN job_plans jp ON jv.plan_id = jp.id
        JOIN properties p ON jp.property_id = p.id
        WHERE jv.id = ?
        LIMIT 1
    ");
    $vstmt->execute([$visitId]);
    $visit = $vstmt->fetch(PDO::FETCH_ASSOC);

    if (!$visit) {
        echo json_encode(['error' => "Visit $visitId not found"]);
        exit;
    }
    if (empty($visit['latitude']) || empty($visit['longitude'])) {
        echo json_encode(['error' => 'Property has no coordinates — cannot geofence pings', 'visit' => $visit]);
        exit;
    }

    $propLat = (float)$visit['latitude'];
    $propLng = (float)$visit['longitude'];
    $date    = $visit['scheduled_date'];

    // Tight bounding box (~radius metres). 1 deg lat ≈ 111_320 m.
    $latDelta = $radiusM / 111320.0;
    $lngDelta = $radiusM / (111320.0 * max(cos(deg2rad($propLat)), 0.001));

    // Candidate orphaned pings: visit_id IS NULL, on the service date, inside the box.
    // Search ALL location-tracked crew (the worker may differ from the assigned
    // equipment/crew on the stop — which is exactly the failure mode here).
    $crewFilter = $crewId ? " AND clh.crew_id = ? " : "";
    $pstmt = $db->prepare("
        SELECT clh.id,
               clh.crew_id,
               u.full_name AS crew_name,
               clh.latitude,
               clh.longitude,
               clh.timestamp
        FROM crew_location_history clh
        LEFT JOIN users u ON u.id = clh.crew_id
        WHERE clh.visit_id IS NULL
          AND DATE(clh.timestamp) = ?
          AND clh.latitude  BETWEEN ? AND ?
          AND clh.longitude BETWEEN ? AND ?
          $crewFilter
        ORDER BY clh.timestamp ASC
    ");
    $pparams = [
        $date,
        $propLat - $latDelta, $propLat + $latDelta,
        $propLng - $lngDelta, $propLng + $lngDelta,
    ];
    if ($crewId) $pparams[] = $crewId;
    $pstmt->execute($pparams);
    $candidates = $pstmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by crew so the admin can see who was actually on-site.
    $byCrew = [];
    foreach ($candidates as $c) {
        $byCrew[$c['crew_name'] ?? ('crew#' . $c['crew_id'])][] = $c;
    }
    $crewSummary = [];
    foreach ($byCrew as $name => $rows) {
        $crewSummary[] = [
            'crew'        => $name,
            'crew_id'     => (int)$rows[0]['crew_id'],
            'ping_count'  => count($rows),
            'first_ping'  => $rows[0]['timestamp'],
            'last_ping'   => $rows[count($rows) - 1]['timestamp'],
        ];
    }

    $result = [
        'mode'        => $apply ? 'APPLIED' : 'DRY-RUN (nothing changed — add &apply=1 to commit)',
        'visit_id'    => (int)$visit['visit_id'],
        'visit_number'=> $visit['visit_number'],
        'status'      => $visit['status'],
        'property'    => $visit['address'],
        'service_date'=> $date,
        'geofence_m'  => $radiusM,
        'crew_filter' => $crewId ? "crew_id = $crewId only" : "all crew in geofence",
        'orphan_pings_found' => count($candidates),
        'crew_breakdown'     => $crewSummary,
    ];

    if (!$apply) {
        $result['next'] = "Review crew_breakdown. To commit: add &apply=1 to this URL.";
        echo json_encode($result, JSON_PRETTY_PRINT);
        exit;
    }

    if (empty($candidates)) {
        $result['linked'] = 0;
        $result['note']   = 'No orphan pings to link.';
        echo json_encode($result, JSON_PRETTY_PRINT);
        exit;
    }

    // COMMIT — attribute the candidate pings to this visit.
    $ids = array_column($candidates, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $upd = $db->prepare("UPDATE crew_location_history SET visit_id = ? WHERE id IN ($ph)");
    $upd->execute(array_merge([$visitId], $ids));
    $linked = $upd->rowCount();

    // Backfill started_at / completed_at from the real ping window if missing,
    // so the report shows accurate on-site times + duration.
    $firstTs = $candidates[0]['timestamp'];
    $lastTs  = $candidates[count($candidates) - 1]['timestamp'];
    $setCols = [];
    $setVals = [];
    if (empty($visit['started_at'])) {
        $setCols[] = 'started_at = ?';
        $setVals[] = $firstTs;
    }
    if (empty($visit['completed_at'])) {
        $setCols[] = 'completed_at = ?';
        $setVals[] = $lastTs;
    }
    $timesUpdated = false;
    if ($setCols) {
        $setVals[] = $visitId;
        $db->prepare("UPDATE job_visits SET " . implode(', ', $setCols) . " WHERE id = ?")
           ->execute($setVals);
        $timesUpdated = true;
    }

    // Promote the visit to completed — but ONLY from scheduled/in_progress.
    // Never downgrade an already-completed/cancelled/skipped visit.
    $statusPromoted = false;
    if (in_array($visit['status'], ['scheduled', 'in_progress'], true)) {
        $db->prepare("
            UPDATE job_visits
            SET status = 'completed',
                completed_at = COALESCE(completed_at, ?)
            WHERE id = ?
              AND status IN ('scheduled', 'in_progress')
        ")->execute([$lastTs, $visitId]);
        $statusPromoted = true;
    }

    $result['linked']          = $linked;
    $result['times_backfilled']= $timesUpdated ? ['started_at' => $firstTs, 'completed_at' => $lastTs] : 'already set';
    $result['status_promoted'] = $statusPromoted ? "scheduled → completed" : "left as '{$visit['status']}'";
    $result['note']            = 'Done. Reload the portal/report to see the GPS route. DELETE this script when finished.';

    echo json_encode($result, JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
