<?php
/**
 * Sam the Closer — his price beside a quote's own price, and Tim's rate card.
 *
 * GET  ?mode=quote&id=N    The Closer's price per line, the three tiers, drive minutes added,
 *                          margin-floor flags. Read-only: never writes a price.
 * GET  ?mode=card          The rate card, where each number came from, and the weekly
 *                          calibration / drift report (admin).
 * POST {mode: 'save_card', hourly_cost?, margin_floor_pct?, min_visit?, depot_lat?, depot_lng?,
 *       minutes?: {service: {fixed, per_unit}}, csrf_token}
 *                          Tim's edits. Admin only. Nothing automatic calls this.
 *
 * ?mode=, not ?action= (see the /api/ router note in the vault). Permission: billing.edit.
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
    requirePermission('billing.edit');
    $user = getCurrentUser();

    $method = $_SERVER['REQUEST_METHOD'];
    $input  = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
    $mode   = $method === 'POST' ? ($input['mode'] ?? '') : ($_GET['mode'] ?? 'quote');

    if ($method === 'POST' && !verifyCSRFToken($input['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $db = getDB();
    session_write_close();

    require_once APP_ROOT . '/Modules/Sales/Services/CloserService.php';
    require_once APP_ROOT . '/Modules/Sales/Services/CloserSettingsService.php';
    $closer = new CloserService($db);
    if (!$closer->ready()) {
        echo json_encode(['ok' => false, 'error' => 'Migration 1141 has not run yet']);
        exit;
    }
    $isAdmin = ($user['role'] ?? '') === 'admin';

    switch ($mode) {
        case 'quote': {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Quote id required']); break; }
            echo json_encode(['ok' => true, 'admin' => $isAdmin] + $closer->forQuote($id));
            break;
        }

        case 'card': {
            if (!$isAdmin) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Admin only']); break; }
            echo json_encode(['ok' => true, 'card' => (new CloserRateCard($db))->load(),
                              'calibration' => $closer->calibrationReport(), 'services' => CloserPricing::SERVICES]);
            break;
        }

        case 'save_card': {
            if (!$isAdmin) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Admin only']); break; }
            echo json_encode((new CloserSettingsService($db))->save($input, (int)$user['id']));
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('[closer] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'The Closer hit a snag — try again.']);
}
