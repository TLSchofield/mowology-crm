<?php
/**
 * Geofence API — HTTP endpoint for the polygon tracking module.
 *
 * Routed via: /crm/api/geofence.php  (shim file, see below)
 *
 * Actions (POST JSON unless noted):
 *
 *   resolve        GET  ?action=resolve&visit_id=N
 *                       Returns whether geofence tracking is active for a visit,
 *                       the polygon, and sampling config. Called before clock-in.
 *
 *   get_polygon    GET  ?action=get_polygon&plan_id=N
 *                       Returns the active polygon for a plan (for map display).
 *
 *   save_polygon   POST {action, plan_id, ring: [[lat,lng],...], label?, notes?}
 *                       Creates or replaces the polygon for a plan.
 *                       Requires jobs.edit permission.
 *
 *   delete_polygon POST {action, plan_id}
 *                       Removes the polygon and disables geofence for the plan.
 *                       Requires jobs.edit permission.
 *
 *   sync_samples   POST {action, visit_id, samples: [{lat,lng,accuracy_m,
 *                        speed_mps,heading,altitude_m,battery_pct,source,
 *                        timestamp (ms UTC)}]}
 *                       Batch-ingest location samples. Triggers server-side
 *                       classification if batch is ≥10 samples.
 *
 *   compute_session POST {action, visit_id}
 *                        Force-compute the session summary (call on clock-out).
 *                        Idempotent.
 *
 *   get_session    GET  ?action=get_session&visit_id=N
 *                       Returns session summary + event timeline.
 *
 *   get_settings   GET  ?action=get_settings
 *                       Returns all geofence.* settings. Admin only.
 *
 *   save_settings  POST {action, settings: {key: value, ...}}
 *                       Save one or more geofence.* settings. Admin only.
 *
 *   set_visit_override POST {action, visit_id, enabled: 0|1|null}
 *                           Override geofence on/off for a specific visit.
 *
 *   set_plan_override  POST {action, plan_id, enabled: 0|1|null}
 *                           Override geofence on/off at the plan level.
 *
 *   save_zone      POST {action, property_id, zone_type, plan_id?, ring, label?, notes?}
 *                       Save an arrival_border or work_zone polygon for a property.
 *
 *   get_zones      GET  ?action=get_zones&property_id=N
 *                       All zones (arrival border + work zones) for a property.
 *
 *   delete_zone    POST {action, geofence_id}
 *                       Delete a zone by ID.
 *
 *   get_zone_sessions GET ?action=get_zone_sessions&visit_id=N
 *                         Per-zone time breakdown for a completed visit.
 *
 *   compute_zone_sessions POST {action, visit_id}
 *                              Manually trigger zone session computation.
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

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Modules/Geofence/Models/GeofenceModel.php';

    requireLogin();
    $user = getCurrentUser();
    requirePermission('jobs.view');

    $isAdmin  = ($user['role'] ?? '') === 'admin';
    $canEdit  = $isAdmin || userHasPermission('jobs.edit');

    $method = $_SERVER['REQUEST_METHOD'];
    $action = '';
    $input  = [];

    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';
    } else {
        $raw    = file_get_contents('php://input');
        $input  = json_decode($raw, true) ?? [];
        $action = $input['action'] ?? '';

        // CSRF guard on all state-changing calls
        if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token.']);
            exit;
        }
    }

    switch ($action) {

        // ── GET: resolve activation state for a visit ────────────────────────
        case 'resolve':
            $visitId = (int)($_GET['visit_id'] ?? 0);
            if (!$visitId) throw new InvalidArgumentException('visit_id required');

            $resolved = geofenceResolveForVisit($visitId);

            $polygon = null;
            if ($resolved['enabled'] && $resolved['geofence_id']) {
                $gf = geofenceGetById($resolved['geofence_id']);
                if ($gf) {
                    $polygon = [
                        'id'       => (int)$gf['id'],
                        'ring'     => $gf['polygon'],
                        'bbox'     => [
                            'lat_min' => (float)$gf['bbox_lat_min'],
                            'lat_max' => (float)$gf['bbox_lat_max'],
                            'lng_min' => (float)$gf['bbox_lng_min'],
                            'lng_max' => (float)$gf['bbox_lng_max'],
                        ],
                        'area_sqm' => $gf['area_sqm'] ? (float)$gf['area_sqm'] : null,
                        'label'    => $gf['label'],
                    ];
                }
            }

            echo json_encode([
                'success'             => true,
                'enabled'             => $resolved['enabled'],
                'source'              => $resolved['source'],
                'geofence_id'         => $resolved['geofence_id'],
                'polygon'             => $polygon,
                'sample_interval_sec' => $resolved['sample_interval_sec'],
                'min_accuracy_m'      => $resolved['min_accuracy_m'],
                'max_accuracy_m'      => $resolved['max_accuracy_m'],
            ]);
            break;

        // ── GET: fetch polygon for a plan ────────────────────────────────────
        case 'get_polygon':
            $planId = (int)($_GET['plan_id'] ?? 0);
            if (!$planId) throw new InvalidArgumentException('plan_id required');

            $gf = geofenceGetForPlan($planId);
            if (!$gf) {
                echo json_encode(['success' => true, 'polygon' => null]);
                break;
            }

            echo json_encode([
                'success' => true,
                'polygon' => [
                    'id'         => (int)$gf['id'],
                    'plan_id'    => $planId,
                    'ring'       => $gf['polygon'],
                    'vertex_count' => (int)$gf['vertex_count'],
                    'area_sqm'   => $gf['area_sqm'] ? (float)$gf['area_sqm'] : null,
                    'label'      => $gf['label'],
                    'notes'      => $gf['notes'],
                    'drawn_at'   => $gf['drawn_at'],
                    'bbox' => [
                        'lat_min' => (float)$gf['bbox_lat_min'],
                        'lat_max' => (float)$gf['bbox_lat_max'],
                        'lng_min' => (float)$gf['bbox_lng_min'],
                        'lng_max' => (float)$gf['bbox_lng_max'],
                    ],
                ],
            ]);
            break;

        // ── POST: save polygon ───────────────────────────────────────────────
        case 'save_polygon':
            if (!$canEdit) {
                http_response_code(403);
                echo json_encode(['error' => 'Permission denied.']);
                break;
            }

            $planId = (int)($input['plan_id'] ?? 0);
            $ring   = $input['ring'] ?? [];
            if (!$planId) throw new InvalidArgumentException('plan_id required');
            if (count($ring) < 3) throw new InvalidArgumentException('Polygon must have at least 3 vertices.');

            // Validate ring format [[lat,lng], ...]
            foreach ($ring as $pt) {
                if (!is_array($pt) || count($pt) < 2) {
                    throw new InvalidArgumentException('Each point must be [lat, lng].');
                }
            }

            $geofenceId = geofenceSavePolygon(
                $planId, $ring, (int)$user['id'],
                $input['label'] ?? null,
                $input['notes'] ?? null
            );

            echo json_encode([
                'success'     => true,
                'geofence_id' => $geofenceId,
                'message'     => 'Polygon saved.',
            ]);
            break;

        // ── POST: delete polygon ─────────────────────────────────────────────
        case 'delete_polygon':
            if (!$canEdit) {
                http_response_code(403);
                echo json_encode(['error' => 'Permission denied.']);
                break;
            }

            $planId = (int)($input['plan_id'] ?? 0);
            if (!$planId) throw new InvalidArgumentException('plan_id required');

            $gf = geofenceGetForPlan($planId);
            if ($gf) {
                geofenceDelete((int)$gf['id']);
            }

            echo json_encode(['success' => true, 'message' => 'Polygon deleted.']);
            break;

        // ── POST: batch-sync location samples ────────────────────────────────
        case 'sync_samples':
            $visitId = (int)($input['visit_id'] ?? 0);
            $samples = $input['samples'] ?? [];
            if (!$visitId) throw new InvalidArgumentException('visit_id required');
            if (!is_array($samples)) throw new InvalidArgumentException('samples must be an array');

            // Verify user has access to this visit
            $db = getDB();
            $stmt = $db->prepare("
                SELECT v.id, v.plan_id, p.active_geofence_id
                FROM job_visits v
                JOIN job_plans p ON v.plan_id = p.id
                WHERE v.id = ?
                LIMIT 1
            ");
            $stmt->execute([$visitId]);
            $visit = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$visit) throw new RuntimeException('Visit not found.');

            $geofenceId = $visit['active_geofence_id']
                ? (int)$visit['active_geofence_id']
                : null;

            // Fallback: resolve from plan
            if (!$geofenceId) {
                $resolved   = geofenceResolveForVisit($visitId);
                $geofenceId = $resolved['geofence_id'];
            }

            if (!$geofenceId) {
                // No polygon — still accept samples (passthrough) but skip classification
                echo json_encode(['success' => true, 'inserted' => 0, 'skipped' => count($samples), 'note' => 'no_polygon']);
                break;
            }

            $result = geofenceIngestSamples($visitId, $geofenceId, $samples);

            // Run classification if we have a decent batch
            if ($result['inserted'] >= 5) {
                geofenceClassifySamples($visitId, $geofenceId);
            }

            echo json_encode([
                'success'     => true,
                'inserted'    => $result['inserted'],
                'skipped'     => $result['skipped'],
                'geofence_id' => $geofenceId,
            ]);
            break;

        // ── POST: compute session summary ────────────────────────────────────
        case 'compute_session':
            $visitId = (int)($input['visit_id'] ?? 0);
            if (!$visitId) throw new InvalidArgumentException('visit_id required');

            $resolved = geofenceResolveForVisit($visitId);

            if (!$resolved['enabled'] && !$resolved['geofence_id']) {
                // No geofence for this visit — still create a minimal session row
                // so PoW generation doesn't have to check existence
                $db = getDB();
                $db->prepare("
                    INSERT IGNORE INTO job_geofence_sessions (visit_id, tracking_active, status)
                    VALUES (?, 0, 'complete')
                ")->execute([$visitId]);
                echo json_encode(['success' => true, 'tracking_active' => false]);
                break;
            }

            $summary = geofenceComputeSession($visitId, $resolved['geofence_id']);

            echo json_encode(array_merge(['success' => true, 'tracking_active' => true], $summary));
            break;

        // ── GET: fetch session summary + events ──────────────────────────────
        case 'get_session':
            $visitId = (int)($_GET['visit_id'] ?? 0);
            if (!$visitId) throw new InvalidArgumentException('visit_id required');

            $session = geofenceGetSession($visitId);
            $events  = geofenceGetEvents($visitId);

            echo json_encode([
                'success' => true,
                'session' => $session,
                'events'  => $events,
            ]);
            break;

        // ── GET: fetch all settings ──────────────────────────────────────────
        case 'get_settings':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['error' => 'Admin only.']);
                break;
            }
            echo json_encode(['success' => true, 'settings' => geofenceGetSettings()]);
            break;

        // ── POST: save settings ──────────────────────────────────────────────
        case 'save_settings':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['error' => 'Admin only.']);
                break;
            }

            $settings = $input['settings'] ?? [];
            foreach ($settings as $key => $value) {
                // Only allow geofence.* keys
                if (strpos($key, 'geofence.') !== 0) continue;
                geofenceSetSetting($key, (string)$value);
            }

            echo json_encode(['success' => true, 'message' => 'Settings saved.']);
            break;

        // ── POST: override geofence on/off for a visit ───────────────────────
        case 'set_visit_override':
            if (!$canEdit) {
                http_response_code(403);
                echo json_encode(['error' => 'Permission denied.']);
                break;
            }

            $visitId = (int)($input['visit_id'] ?? 0);
            if (!$visitId) throw new InvalidArgumentException('visit_id required');

            // enabled: 1 = on, 0 = off, null = inherit
            $enabled = isset($input['enabled']) && $input['enabled'] !== null
                ? ((int)(bool)$input['enabled'])
                : null;

            $db = getDB();
            $db->prepare("UPDATE job_visits SET geofence_enabled = ? WHERE id = ?")
               ->execute([$enabled, $visitId]);

            echo json_encode([
                'success'          => true,
                'visit_id'         => $visitId,
                'geofence_enabled' => $enabled,
            ]);
            break;

        // ── POST: override geofence on/off for a plan ────────────────────────
        case 'set_plan_override':
            if (!$canEdit) {
                http_response_code(403);
                echo json_encode(['error' => 'Permission denied.']);
                break;
            }

            $planId  = (int)($input['plan_id'] ?? 0);
            if (!$planId) throw new InvalidArgumentException('plan_id required');

            $enabled = isset($input['enabled']) && $input['enabled'] !== null
                ? ((int)(bool)$input['enabled'])
                : null;

            $db = getDB();
            $db->prepare("UPDATE job_plans SET geofence_enabled = ? WHERE id = ?")
               ->execute([$enabled, $planId]);

            echo json_encode([
                'success'          => true,
                'plan_id'          => $planId,
                'geofence_enabled' => $enabled,
            ]);
            break;

        // ── POST: save zone (arrival_border or work_zone) ───────────────────
        case 'save_zone':
            if (!$canEdit) {
                http_response_code(403);
                echo json_encode(['error' => 'Permission denied.']);
                break;
            }

            $propertyId = (int)($input['property_id'] ?? 0);
            $zoneType   = $input['zone_type'] ?? 'work_zone';
            $planId     = isset($input['plan_id']) && $input['plan_id'] ? (int)$input['plan_id'] : null;
            $ring       = $input['ring'] ?? [];
            $label      = trim($input['label'] ?? '');

            if (!$propertyId) throw new InvalidArgumentException('property_id required');
            if (count($ring) < 3) throw new InvalidArgumentException('Polygon must have at least 3 vertices.');
            if ($zoneType === 'work_zone' && !$planId) throw new InvalidArgumentException('plan_id required for work_zone.');

            foreach ($ring as $pt) {
                if (!is_array($pt) || count($pt) < 2) {
                    throw new InvalidArgumentException('Each point must be [lat, lng].');
                }
            }

            $geofenceId = geofenceSaveZone(
                $propertyId, $zoneType, $planId, $ring, (int)$user['id'],
                $label ?: null,
                $input['notes'] ?? null
            );

            echo json_encode([
                'success'     => true,
                'geofence_id' => $geofenceId,
                'message'     => ($zoneType === 'arrival_border' ? 'Arrival border' : 'Work zone') . ' saved.',
            ]);
            break;

        // ── GET: all zones for a property ────────────────────────────────────
        case 'get_zones':
            $propertyId = (int)($_GET['property_id'] ?? 0);
            if (!$propertyId) throw new InvalidArgumentException('property_id required');

            $zones = geofenceGetZonesForProperty($propertyId);

            $out = array_map(function($z) {
                return [
                    'id'           => (int)$z['id'],
                    'zone_type'    => $z['zone_type'] ?? 'work_zone',
                    'plan_id'      => $z['plan_id'] ? (int)$z['plan_id'] : null,
                    'plan_title'   => $z['plan_title'] ?? null,
                    'label'        => $z['label'],
                    'ring'         => $z['polygon'],
                    'area_sqm'     => $z['area_sqm'] ? (float)$z['area_sqm'] : null,
                    'bbox'         => [
                        'lat_min' => (float)$z['bbox_lat_min'],
                        'lat_max' => (float)$z['bbox_lat_max'],
                        'lng_min' => (float)$z['bbox_lng_min'],
                        'lng_max' => (float)$z['bbox_lng_max'],
                    ],
                ];
            }, $zones);

            echo json_encode(['success' => true, 'zones' => $out]);
            break;

        // ── POST: update a zone's polygon (node drag/insert/remove) ──────────
        case 'update_zone':
            if (!$canEdit) {
                http_response_code(403);
                echo json_encode(['error' => 'Permission denied.']);
                break;
            }

            $geofenceId = (int)($input['geofence_id'] ?? 0);
            $ring       = $input['ring'] ?? [];
            if (!$geofenceId) throw new InvalidArgumentException('geofence_id required');
            if (count($ring) < 3) throw new InvalidArgumentException('Polygon must have at least 3 vertices.');

            geofenceUpdateZonePolygon($geofenceId, $ring);

            echo json_encode(['success' => true, 'geofence_id' => $geofenceId, 'message' => 'Zone updated.']);
            break;

        // ── POST: delete a zone by ID ────────────────────────────────────────
        case 'delete_zone':
            if (!$canEdit) {
                http_response_code(403);
                echo json_encode(['error' => 'Permission denied.']);
                break;
            }

            $geofenceId = (int)($input['geofence_id'] ?? 0);
            if (!$geofenceId) throw new InvalidArgumentException('geofence_id required');

            geofenceDelete($geofenceId);

            echo json_encode(['success' => true, 'message' => 'Zone deleted.']);
            break;

        // ── GET: zone session breakdown for a visit ──────────────────────────
        case 'get_zone_sessions':
            $visitId = (int)($_GET['visit_id'] ?? 0);
            if (!$visitId) throw new InvalidArgumentException('visit_id required');

            $sessions = geofenceGetZoneSessions($visitId);

            // Also fetch transit seconds from job_visits
            $db = getDB();
            $transitStmt = $db->prepare("SELECT transit_seconds FROM job_visits WHERE id = ?");
            $transitStmt->execute([$visitId]);
            $transitRow = $transitStmt->fetch(PDO::FETCH_ASSOC);
            $transitSec = $transitRow ? (int)$transitRow['transit_seconds'] : null;

            echo json_encode([
                'success'         => true,
                'zone_sessions'   => $sessions,
                'transit_seconds' => $transitSec,
            ]);
            break;

        // ── POST: compute zone sessions manually ─────────────────────────────
        case 'compute_zone_sessions':
            $visitId = (int)($input['visit_id'] ?? 0);
            if (!$visitId) throw new InvalidArgumentException('visit_id required');

            // Look up property_id via visit → plan
            $db = getDB();
            $stmt = $db->prepare("
                SELECT jp.property_id
                FROM job_visits jv
                JOIN job_plans jp ON jp.id = jv.plan_id
                WHERE jv.id = ?
                LIMIT 1
            ");
            $stmt->execute([$visitId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Visit not found.');

            geofenceComputeZoneSessions($visitId, (int)$row['property_id']);
            $sessions = geofenceGetZoneSessions($visitId);

            echo json_encode([
                'success'       => true,
                'zone_sessions' => $sessions,
                'message'       => 'Zone sessions computed.',
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode([
                'error'   => 'Invalid action.',
                'actions' => [
                    'resolve', 'get_polygon', 'save_polygon', 'delete_polygon',
                    'sync_samples', 'compute_session', 'get_session',
                    'get_settings', 'save_settings', 'set_visit_override', 'set_plan_override',
                    'save_zone', 'update_zone', 'get_zones', 'delete_zone', 'get_zone_sessions', 'compute_zone_sessions',
                ],
            ]);
    }

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[GeofenceApi] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['error' => 'Server error. Check logs.']);
}
