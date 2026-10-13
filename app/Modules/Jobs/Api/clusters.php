<?php
declare(strict_types=1);

/**
 * app/Modules/Jobs/Api/clusters.php
 *
 * Property cluster management API.
 *
 * Actions (POST JSON or GET with ?action=):
 *   GET  list           — all active clusters with member details
 *   GET  detect         — run auto-detection, return candidates
 *   GET  session        — get open session for a cluster+date
 *   GET  properties     — active properties for the cluster picker (office)
 *   POST create         — create a cluster (manual or from candidate)
 *   POST update         — rename / change travel time
 *   POST delete         — soft-deactivate
 *   POST session_open   — open a cluster session (crew clock-in)
 *   POST session_close  — close a session and write time entries
 */

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    require_once APP_ROOT . '/Core/config.php';
    // requireLoginOrJwt(), verifyCSRFToken(), userHasPermission() live in auth.php — config.php
    // alone does not define them.
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Modules/Jobs/Services/ClusterService.php';
    require_once APP_ROOT . '/Modules/Jobs/Services/ClusterDetectionService.php';

    // Under JWT getCurrentUser() is null — use the user requireLoginOrJwt() returns (2026-10-12).
    $isJwt  = strpos($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '', 'Bearer ') === 0;
    $user   = requireLoginOrJwt();
    $can    = function (string $perm) use ($isJwt, $user): bool {
        return $isJwt ? jwtUserHasPermission($user, $perm) : userHasPermission($perm);
    };
    // Creating / changing clusters, or opening a session for someone else, is office work:
    // the people who edit the schedule (admins AND managers), by permission key.
    $isAdmin = $can('schedule.edit') || $can('jobs.edit');

    $method = $_SERVER['REQUEST_METHOD'];
    // GET actions come as ?mode= — through the /api/jobs/clusters router `action` is taken by the
    // route itself (QSA duplicates it), so ?action= never reached this file. ?action= kept for
    // direct callers.
    $action = $method === 'GET'
        ? trim((string)($_GET['mode'] ?? $_GET['action'] ?? ''))
        : (json_decode(file_get_contents('php://input'), true)['action'] ?? '');

    $db        = getDB();
    $clusters  = new ClusterService($db);
    $detection = new ClusterDetectionService($db);

    // -------------------------------------------------------------------------
    // GET actions
    // -------------------------------------------------------------------------
    if ($method === 'GET') {
        switch ($action) {

            case 'list':
                $stmt = $db->query(
                    'SELECT pc.*,
                            GROUP_CONCAT(pcm.property_id ORDER BY pcm.sort_order) AS property_ids,
                            GROUP_CONCAT(
                                CONCAT(COALESCE(c.first_name,\'\'),\' \',COALESCE(c.last_name,\'\'))
                                ORDER BY pcm.sort_order SEPARATOR \' / \'
                            ) AS member_names
                     FROM property_clusters pc
                     LEFT JOIN property_cluster_members pcm ON pcm.cluster_id = pc.id
                     LEFT JOIN properties p ON p.id = pcm.property_id
                     LEFT JOIN contacts c ON c.id = p.site_contact_id
                     WHERE pc.is_active = 1
                     GROUP BY pc.id
                     ORDER BY pc.name ASC'
                );
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($rows as &$row) {
                    $row['property_ids'] = $row['property_ids']
                        ? array_map('intval', explode(',', $row['property_ids']))
                        : [];
                }
                echo json_encode(['success' => true, 'clusters' => $rows]);
                break;

            case 'properties':
                // Picker for a new cluster (office only — the page is admin/manager).
                if (!$isAdmin) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Not allowed']); exit; }
                echo json_encode(['success' => true, 'properties' => $clusters->listActiveProperties()]);
                break;

            case 'detect':
                $candidates = $detection->detectCandidates();
                echo json_encode(['success' => true, 'candidates' => $candidates]);
                break;

            case 'session':
                $clusterId = (int)($_GET['cluster_id'] ?? 0);
                $date      = $_GET['date'] ?? date('Y-m-d');
                $crewId    = isset($_GET['crew_id']) ? (int)$_GET['crew_id'] : null;
                $session   = $clusters->getOpenSession($clusterId, $date, $crewId);
                echo json_encode(['success' => true, 'session' => $session]);
                break;

            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Unknown action']);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // POST actions (admin-only for create/update/delete)
    // -------------------------------------------------------------------------
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    // Session POSTs carry the CSRF token (header or body); JWT callers are exempt.
    if (!$isJwt) {
        $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? '');
        if (!verifyCSRFToken($token)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token — refresh and retry', 'code' => 'CSRF_INVALID']);
            exit;
        }
    }

    switch ($action) {

        case 'create':
            if (!$isAdmin) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Admin required']); exit; }
            $name          = trim($input['name'] ?? '');
            $propertyIds   = array_map('intval', $input['property_ids'] ?? []);
            $travelMinutes = (int)($input['travel_minutes'] ?? 0);
            $notes         = trim($input['notes'] ?? '') ?: null;

            if (!$name || count($propertyIds) < 2) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'name and at least 2 property_ids required']);
                exit;
            }

            $clusterId = $clusters->createCluster($name, $propertyIds, $travelMinutes, $notes);
            echo json_encode(['success' => true, 'cluster_id' => $clusterId]);
            break;

        case 'update':
            if (!$isAdmin) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Admin required']); exit; }
            $clusterId     = (int)($input['cluster_id'] ?? 0);
            $name          = trim($input['name'] ?? '');
            $travelMinutes = (int)($input['travel_minutes'] ?? 0);
            $notes         = trim($input['notes'] ?? '') ?: null;

            if (!$clusterId || !$name) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'cluster_id and name required']);
                exit;
            }

            $ok = $clusters->updateCluster($clusterId, $name, $travelMinutes, $notes);
            echo json_encode(['success' => $ok]);
            break;

        case 'delete':
            if (!$isAdmin) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Admin required']); exit; }
            $clusterId = (int)($input['cluster_id'] ?? 0);
            $ok = $clusterId ? $clusters->deactivateCluster($clusterId) : false;
            echo json_encode(['success' => $ok]);
            break;

        case 'session_open':
            $clusterId = (int)($input['cluster_id'] ?? 0);
            $date      = $input['date'] ?? date('Y-m-d');
            $crewId    = isset($input['crew_id']) ? (int)$input['crew_id'] : (int)$user['id'];
            if ($crewId !== (int)$user['id'] && !$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'You can only open a session for yourself']);
                exit;
            }
            $lat       = (float)($input['lat'] ?? 0);
            $lng       = (float)($input['lng'] ?? 0);
            $source    = in_array($input['source'] ?? '', ['gps', 'manual'], true) ? $input['source'] : 'manual';

            if (!$clusterId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'cluster_id required']);
                exit;
            }

            $sessionId = $clusters->openSession($clusterId, $date, $crewId, $lat, $lng, $source);
            echo json_encode(['success' => true, 'session_id' => $sessionId]);
            break;

        case 'session_close':
            $sessionId = (int)($input['session_id'] ?? 0);
            $lat       = (float)($input['lat'] ?? 0);
            $lng       = (float)($input['lng'] ?? 0);

            if (!$sessionId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'session_id required']);
                exit;
            }

            $ok = $clusters->closeSession($sessionId, $lat, $lng, (int)$user['id']);
            echo json_encode(['success' => $ok]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }

} catch (\Throwable $e) {
    error_log('[jobs/clusters] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
