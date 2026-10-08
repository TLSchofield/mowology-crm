<?php
/**
 * Visit Pull-Forward API — "you're at a property whose visit is on another day: doing it now?"
 *
 * POST ?mode=offer   { lat, lng, accuracy, on_open? }        → { success, offer|null, reason }
 * POST ?mode=accept  { visit_id, property_id?, request_key }  → move that visit to today
 *        The client then starts the timer through the existing path (POST /crm/api/job-timer.php
 *        action=start). Both POSTs are on the offline queue, replayed in order.
 * GET  ?mode=dryrun&property_id=73&date=2026-10-05            → read-only: what would have been
 *        offered there that day (admin/manager). &address=Fremlin finds the property by address.
 *
 * Auth: session (CSRF required on POST — X-CSRF-Token header or csrf_token in the body; a stale
 * token answers 403 code CSRF_INVALID so the client refreshes from /crm/api/get-csrf.php and
 * retries) or JWT Bearer (iOS, no CSRF). Rules live in VisitPullForwardService.
 */
declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store');

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

function vpfRespond(array $body, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once CRM_INCLUDES . '/timeclock-functions.php';
    require_once APP_ROOT . '/Modules/Team/Services/ProximityAutoStartService.php';
    require_once APP_ROOT . '/Modules/Jobs/Services/VisitPullForwardService.php';
    // Drawn arrival borders (geofencePointInPolygon) — same fence test as auto-arrival.
    if (is_file(APP_ROOT . '/Modules/Geofence/Models/GeofenceModel.php')) {
        require_once APP_ROOT . '/Modules/Geofence/Models/GeofenceModel.php';
    }

    $isJwt = strpos($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '', 'Bearer ') === 0;
    $user  = requireLoginOrJwt();
    $userId = (int)$user['id'];
    $mode  = (string)($_GET['mode'] ?? '');
    $db    = getDB();

    // ── Read-only dry run ────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if ($mode !== 'dryrun') {
            vpfRespond(['success' => false, 'error' => 'Use ?mode=dryrun'], 400);
        }
        if (!in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
            vpfRespond(['success' => false, 'error' => 'Admin or manager only'], 403);
        }
        $date = (string)($_GET['date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            vpfRespond(['success' => false, 'error' => 'date must be YYYY-MM-DD'], 400);
        }
        $svc = new VisitPullForwardService($db);
        $propertyIds = [];
        if (!empty($_GET['property_id'])) {
            $propertyIds[] = (int)$_GET['property_id'];
        } elseif (trim((string)($_GET['address'] ?? '')) !== '') {
            $s = $db->prepare("SELECT id FROM properties WHERE address LIKE ? OR property_name LIKE ? ORDER BY id LIMIT 5");
            $like = '%' . trim((string)$_GET['address']) . '%';
            $s->execute([$like, $like]);
            $propertyIds = array_map('intval', array_column($s->fetchAll(PDO::FETCH_ASSOC), 'id'));
        }
        if (!$propertyIds) {
            vpfRespond(['success' => false, 'error' => 'Give property_id or address'], 400);
        }
        $out = [];
        foreach ($propertyIds as $pid) {
            $out[] = $svc->dryRun($pid, $date);
        }
        vpfRespond(['success' => true, 'date' => $date, 'results' => $out]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        vpfRespond(['success' => false, 'error' => 'Method not allowed'], 405);
    }

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    if (!$isJwt) {
        $token = (string)($input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!verifyCSRFToken($token)) {
            vpfRespond(['success' => false, 'error' => 'Invalid CSRF token — refresh and retry', 'code' => 'CSRF_INVALID'], 403);
        }
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    // requirePermission() reads the SESSION user — under a Bearer token there is none, so it
    // denied every iOS call. JWT callers are checked against the token's user (2026-10-08).
    if ($isJwt) {
        if (!jwtUserHasPermission($user, 'timer.start')) {
            vpfRespond(['success' => false, 'error' => 'Permission denied: timer.start required'], 403);
        }
    } else {
        requirePermission('timer.start');
    }

    $svc = new VisitPullForwardService($db);

    // ── Offer ────────────────────────────────────────────────────────────────
    if ($mode === 'offer') {
        if (getTimeClockSetting('pull_forward_offers_enabled', '1') !== '1') {
            vpfRespond(['success' => true, 'offer' => null, 'reason' => 'disabled']);
        }
        $lat = isset($input['lat']) ? (float)$input['lat'] : 0.0;
        $lng = isset($input['lng']) ? (float)$input['lng'] : 0.0;
        $acc = isset($input['accuracy']) ? (float)$input['accuracy'] : 50.0;
        if (!$lat || !$lng) {
            vpfRespond(['success' => false, 'error' => 'lat and lng required'], 400);
        }
        // A truck tablet has no one to ask.
        $dev = $db->prepare("SELECT IFNULL(device_type, 'personal') FROM users WHERE id = ?");
        $dev->execute([$userId]);
        if ($dev->fetchColumn() === 'truck') {
            vpfRespond(['success' => true, 'offer' => null, 'reason' => 'truck_device']);
        }
        $radius = (int)getTimeClockSetting('gps_proximity_meters', '150');
        if (!ProximityAutoStartService::accuracyAcceptable($acc, $radius)) {
            vpfRespond(['success' => true, 'offer' => null, 'reason' => 'gps_too_coarse']);
        }
        if (getLiveJobTimer($userId)) {
            vpfRespond(['success' => true, 'offer' => null, 'reason' => 'timer_running']);
        }

        $res = $svc->offerAt($lat, $lng, $radius);

        // Dwell for background checks: one fix inside a fence is a drive-by. Opening the app
        // on site (on_open) is deliberate, as with the auto-arrival one-shot check.
        if ($res['offer'] && empty($input['on_open'])) {
            $pid = (int)$res['offer']['property_id'];
            $fx = $db->prepare("
                SELECT latitude AS lat, longitude AS lng,
                       (UNIX_TIMESTAMP() - UNIX_TIMESTAMP(timestamp)) AS age_seconds
                FROM crew_location_history
                WHERE crew_id = ? AND timestamp >= (NOW() - INTERVAL ? SECOND)
                ORDER BY timestamp DESC LIMIT 20
            ");
            $fx->execute([$userId, ProximityAutoStartService::DWELL_MAX_AGE_SECONDS]);
            $dwell = ProximityAutoStartService::hasDwell($fx->fetchAll(PDO::FETCH_ASSOC),
                static function (float $fLat, float $fLng) use ($svc, $radius, $pid): bool {
                    foreach ($svc->propertiesAt($fLat, $fLng, $radius) as $p) {
                        if ($p['property_id'] === $pid) {
                            return true;
                        }
                    }
                    return false;
                });
            if (!$dwell) {
                vpfRespond(['success' => true, 'offer' => null, 'reason' => 'no_dwell_yet']);
            }
        }
        vpfRespond(['success' => true, 'offer' => $res['offer'], 'reason' => $res['reason']]);
    }

    // ── Accept: move the visit to today ──────────────────────────────────────
    if ($mode === 'accept') {
        $visitId = (int)($input['visit_id'] ?? 0);
        if ($visitId < 1) {
            vpfRespond(['success' => false, 'error' => 'visit_id required'], 400);
        }
        $key = (string)($input['request_key'] ?? $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
        $propertyId = isset($input['property_id']) ? (int)$input['property_id'] : null;
        require_once CRM_INCLUDES . '/plan-functions.php'; // logActivityExtended + plan helpers
        $result = $svc->pullForward($visitId, $userId, $key, $propertyId);
        if (empty($result['success'])) {
            vpfRespond(['success' => false, 'error' => $result['error'] ?? 'Could not move the visit.'], (int)($result['status'] ?? 422));
        }
        vpfRespond($result);
    }

    vpfRespond(['success' => false, 'error' => 'Unknown mode. Use offer, accept or dryrun.'], 400);

} catch (Throwable $e) {
    error_log('[visit-pull-forward] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    vpfRespond(['success' => false, 'error' => 'Server error'], 500);
}
