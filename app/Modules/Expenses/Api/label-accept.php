<?php
/**
 * iOS: "Add it" on a product label photo — JWT-authenticated.
 *
 * POST /api/expenses/label-accept  JSON {proposal_id, name?, unit_cost?}
 * Auth: Authorization: Bearer <jwt> (no CSRF). Needs expenses.edit (the same key as Penny's
 * Add button on the web). Only a PRODUCT proposal made by a label photo this person took
 * (an admin may accept anyone's). Machines stay Otto's web card.
 *
 * Writes through ProductProposalService::accept() — the same explicit-column insert the web
 * button uses — so there is one place that creates a product from a label.
 * Returns {success, message, product_id}.
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

header('Content-Type: application/json');

try {
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once APP_ROOT . '/Core/ApiResponse.php';
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Modules/Products/Services/ProductProposalService.php';

    $jwtUser = requireJwt();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }
    if (!jwtUserHasPermission($jwtUser, 'expenses.edit')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Adding products needs the expenses permission.']);
        exit;
    }
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $db = getDB();
    $pp = new ProductProposalService($db);
    $p = $pp->get((int)($in['proposal_id'] ?? 0));
    if (!$p) {
        echo json_encode(['success' => false, 'error' => 'That proposal is gone.']);
        exit;
    }
    if (!in_array($p['kind'], ['product_new', 'product_match'], true)) {
        echo json_encode(['success' => false, 'error' => 'That one is a machine — Otto will ask about it on the web.']);
        exit;
    }
    $own = false;
    if (!empty($p['label_capture_id'])) {
        $s = $db->prepare("SELECT captured_by FROM label_captures WHERE id = ?");
        $s->execute([(int)$p['label_capture_id']]);
        $own = (int)$s->fetchColumn() === (int)$jwtUser['id'];
    }
    if (!$own && ($jwtUser['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'That photo was taken by someone else.']);
        exit;
    }
    $cost = $in['unit_cost'] ?? null;
    if ($cost !== null && $cost !== '' && (!is_numeric($cost) || (float)$cost < 0 || (float)$cost > 100000)) {
        echo json_encode(['success' => false, 'error' => 'The cost does not look right.']);
        exit;
    }
    $r = $pp->accept((int)$p['id'], (int)$jwtUser['id'], [
        'name'      => isset($in['name']) ? mb_substr(trim((string)$in['name']), 0, 255) : null,
        'unit_cost' => $cost,
    ]);
    echo json_encode(['success' => (bool)$r['ok'], 'message' => (string)($r['message'] ?? ''), 'product_id' => $r['id'] ?? null]
        + (!empty($r['ok']) ? [] : ['error' => (string)($r['message'] ?? 'Could not save.')]));
} catch (Throwable $e) {
    error_log('label-accept: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not add the product: ' . ApiResponse::message($e, 'label-accept')]);
}
