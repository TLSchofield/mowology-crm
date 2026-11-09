<?php
/**
 * Otto — a border for every client property (PropertyBorderService, migration 1285).
 *
 * GET  ?mode=audit[&offset=N][&rings=1]  Read-only. Counts: active properties, with / without pin (list),
 *                                        drawn / default / no border, pins > 60 m from where crews work
 *                                        (list with distances), overlapping borders (list), and the
 *                                        default borders an apply WOULD create (the dry run; &rings=1
 *                                        adds each polygon). Reading the GPS is time-budgeted: when
 *                                        next_offset is set, call again with &offset=<next_offset>.
 * GET  ?mode=missing_pins                Properties with no pin, for the map's geocode-all tool.
 * POST {mode: 'apply', property_ids?: [..], offset?: N, csrf_token}
 *                                        Create the default borders (never where ANY arrival border
 *                                        exists) and store the measured sites. Run the audit first.
 * POST {mode: 'measure', offset?: N, csrf_token}
 *                                        Store the measured sites only (refreshes Otto's "pin is X m
 *                                        off" items) — no borders created.
 *
 * ?mode=, never ?action= (see the /api/ router note in the vault). jobs.edit only.
 */
declare(strict_types=1);
header('Content-Type: application/json');

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

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    requireLogin();
    if (!userHasPermission('jobs.edit')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Otto is for people who run the schedule (jobs.edit).']);
        exit;
    }
    $user = getCurrentUser();
    $method = $_SERVER['REQUEST_METHOD'];
    $input  = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?? []) : [];
    $mode   = $method === 'POST' ? (string)($input['mode'] ?? '') : (string)($_GET['mode'] ?? 'audit');
    if ($method === 'POST' && !verifyCSRFToken((string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token — reload the page and try again.']);
        exit;
    }
    $db = getDB();
    session_write_close();
    @set_time_limit(60);

    require_once APP_ROOT . '/Modules/Operations/Services/PropertyBorderService.php';
    $svc = new PropertyBorderService($db);

    if ($method === 'GET' && $mode === 'audit') {
        $a = $svc->audit(max(0, (int)($_GET['offset'] ?? 0)));
        if (empty($_GET['rings'])) {
            $a['proposed'] = array_map(fn($p) => array_diff_key($p, ['ring' => 1]), $a['proposed']);
        }
        echo json_encode(['ok' => true] + $a);
        exit;
    }

    if ($method === 'GET' && $mode === 'missing_pins') {
        echo json_encode(['ok' => true, 'properties' => $svc->missingPins()]);
        exit;
    }

    if ($method === 'POST' && $mode === 'apply') {
        $ids = isset($input['property_ids']) && is_array($input['property_ids']) ? array_map('intval', $input['property_ids']) : null;
        $r = $svc->apply((int)$user['id'], $ids, max(0, (int)($input['offset'] ?? 0)));
        if (!$r['ok']) http_response_code(400);
        echo json_encode($r);
        exit;
    }

    if ($method === 'POST' && $mode === 'measure') {
        $r = $svc->refreshSites(max(0, (int)($input['offset'] ?? 0)));
        if (!$r['ok']) http_response_code(400);
        echo json_encode($r);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
} catch (Throwable $e) {
    error_log('Otto borders API: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Otto hit a snag. Try again in a minute.']);
}
