<?php
/**
 * "Let Otto draw it" — Otto's property card asks for one property's arrival border from the city
 * lot map (OttoAutoPlaceService::borderOne). POST JSON { property_id[, csrf_token][, action: 'remove'|'approve'] } —
 * 'remove' is "Not right? Remove it": deletes Otto's lot border and he never draws that property again.
 * 'approve' is "Looks right": the border is kept (quality step only — an unapproved border counts for arrival too).
 * Auth: session + csrf_token (web), or JWT Bearer (the native apps, 2026-11-20; no csrf).
 * Same permission as placing a pin (geocode-save.php): a border moves auto-arrival and route proof.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid JSON body']);
    exit;
}
$jwt = getJwtUser();
if ($jwt !== null) {
    $uid = (int)$jwt['id'];
    $perms = (($jwt['role'] ?? '') === 'admin') ? ['*'] : getUserPermissions($uid);
    $allowed = in_array('*', $perms, true) || in_array('clients.edit', $perms, true) || in_array('jobs.edit', $perms, true);
    // Crew adding a client in the field (jobs.create_field) may have Otto draw that border, nothing more.
    // Only a property made in the last 30 minutes (the client they are adding), so no one can walk other properties by id.
    $crewDraw = !$allowed && in_array('jobs.create_field', $perms, true) && !in_array((string)($in['action'] ?? ''), ['remove', 'approve'], true);
    if ($crewDraw) {
        try {
            $q = getDB()->prepare('SELECT COUNT(*) FROM properties WHERE id = ? AND created_at >= (NOW() - INTERVAL 30 MINUTE)');
            $q->execute([(int)($in['property_id'] ?? 0)]);
            $allowed = (int)$q->fetchColumn() > 0;
        } catch (Throwable $e) { $allowed = false; }
    }
} else {
    requireLogin();
    if (!verifyCSRFToken((string)($in['csrf_token'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Your session expired — reload the page.']);
        exit;
    }
    $allowed = userHasAnyPermission(['clients.edit', 'jobs.edit']);
    session_write_close();
}
if (!$allowed) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Permission denied']);
    exit;
}
$propertyId = (int)($in['property_id'] ?? 0);
if ($propertyId < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'No property.']);
    exit;
}
try {
    require_once APP_ROOT . '/Modules/Operations/Services/OttoAutoPlaceService.php';
    $svc = new OttoAutoPlaceService(getDB());
    if (($in['action'] ?? '') === 'remove') {
        $svc->removeLotBorder($propertyId);
        echo json_encode(['ok' => true, 'message' => "Removed. Otto won't draw this one again — draw it in the zone editor."]);
    } elseif (($in['action'] ?? '') === 'approve') {
        $done = $svc->approveLotBorder($propertyId);
        echo json_encode(['ok' => $done, 'message' => $done ? 'Kept.' : 'There is no Otto border here to approve.']);
    } else {
        echo json_encode($svc->borderOne($propertyId));
    }
} catch (Throwable $e) {
    error_log('otto-lot-border: ' . get_class($e));
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'Otto could not draw it just now — try again.']);
}
