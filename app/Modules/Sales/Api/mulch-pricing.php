<?php
/**
 * Sam's mulch pricing — installed price per yard at an address. READ-ONLY: never writes a price.
 *
 * GET ?mode=dryrun&property_id=N [&family=mulch|soil|compost] [&product_id=N] [&disposal=1]   (admin)
 * GET ?mode=dryrun&postcode=V6K [&…]                                                           (admin)
 *        The whole breakdown: material receipts used and left out, the haul tier (real trips vs
 *        straight-line estimate), every input marked real / assumed, margin, minimum, history.
 * GET ?mode=coverage                                                                            (admin)
 *        How much real data there is: mulch / soil / compost receipt lines, supplier places and
 *        Otto's runs to them, properties with pins and postcodes, the settings in force.
 * GET ?mode=hint&property_id=N&text=Black mulch install [&product_id=N]   (billing.edit)
 * GET ?mode=hint&q=V6K | 2448 Larch                                         (billing.edit)
 *        The compact line for the quote builder and Sam's card. `text` that names no mulch / soil /
 *        compost returns {ok: true, applies: false}.
 *
 * ?mode=, not ?action= (see the /api/ router note in the vault). GET only, so no CSRF.
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
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'GET only']);
        exit;
    }
    $db = getDB();
    session_write_close();

    require_once APP_ROOT . '/Modules/Sales/Services/MulchPricingService.php';
    $svc = new MulchPricingService($db);
    $mode = (string)($_GET['mode'] ?? 'hint');
    $isAdmin = ($user['role'] ?? '') === 'admin';

    $opt = [];
    if (!empty($_GET['property_id'])) $opt['property_id'] = (int)$_GET['property_id'];
    if (!empty($_GET['postcode'])) $opt['postcode'] = substr((string)$_GET['postcode'], 0, 12);
    if (!empty($_GET['family'])) $opt['family'] = (string)$_GET['family'];
    if (!empty($_GET['product_id'])) $opt['product_id'] = (int)$_GET['product_id'];
    if (!empty($_GET['text'])) $opt['text'] = substr((string)$_GET['text'], 0, 200);
    if (!empty($_GET['disposal'])) $opt['disposal'] = true;

    switch ($mode) {
        case 'dryrun': {
            if (!$isAdmin) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Admin only']); break; }
            if (empty($opt['property_id']) && empty($opt['postcode'])) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'property_id or postcode required']);
                break;
            }
            echo json_encode(['mode' => 'dryrun', 'read_only' => true] + $svc->breakdown($opt), JSON_PRETTY_PRINT);
            break;
        }

        case 'coverage': {
            if (!$isAdmin) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Admin only']); break; }
            echo json_encode(['mode' => 'coverage', 'read_only' => true] + $svc->coverage(), JSON_PRETTY_PRINT);
            break;
        }

        case 'hint': {
            if (isset($opt['text']) && empty($opt['family']) && MulchPricingService::family($opt['text']) === null) {
                echo json_encode(['ok' => true, 'applies' => false]);
                break;
            }
            $q = trim(substr((string)($_GET['q'] ?? ''), 0, 120));
            if ($q !== '' && empty($opt['property_id'])) {
                if (MulchPricingService::postcode($q) !== null && !preg_match('/^\d/', $q)) $opt['postcode'] = $q;
                else $opt['address'] = $q;
            }
            if (empty($opt['property_id']) && empty($opt['postcode']) && empty($opt['address'])) {
                echo json_encode(['ok' => false, 'applies' => true, 'error' => 'Pick a property first']);
                break;
            }
            $b = $svc->breakdown($opt);
            echo json_encode(['applies' => true, 'location_error' => $b['location']['error'] ?? null] + MulchPricingService::hint($b));
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
    }
} catch (Throwable $e) {
    error_log('[mulch-pricing] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Sam hit a snag pricing that — try again.']);
}
