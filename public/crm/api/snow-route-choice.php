<?php
/**
 * Snow & salt route stop — what was done there.
 *
 * GET  ?visit_id=N            → { is_route, choice, label, rates[], choices[] }
 * POST {visit_id, choice, csrf_token}   choice: salt | arctic | snow | none
 *
 * The crew record it on completion; the office can correct it until the run is
 * invoiced. The run is billed at the recorded rate only (SnowContractService).
 * Thin controller — rules live in SnowContractService.
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
require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Modules/Contracts/Services/SnowContractService.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();
$user = getCurrentUser();
session_write_close();

$db  = getDB();
$svc = new SnowContractService($db);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        if (!verifyCSRFToken($in['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Session expired - refresh and try again.', 'code' => 'CSRF_INVALID']);
            exit;
        }
        echo json_encode($svc->recordChoice((int)($in['visit_id'] ?? 0), (string)($in['choice'] ?? ''), (int)$user['id'],
            in_array($user['role'] ?? '', ['admin', 'manager'], true) ? 'office' : 'crew'));
        exit;
    }

    $visitId = (int)($_GET['visit_id'] ?? 0);
    $stmt = $db->prepare("SELECT plan_id FROM job_visits WHERE id = ?");
    $stmt->execute([$visitId]);
    $planId = (int)($stmt->fetchColumn() ?: 0);
    $rates  = $planId ? $svc->ratesForPlan($planId) : [];
    $choice = $rates ? $svc->choiceForVisit($visitId) : null;

    $choices = [];
    foreach (SnowContractService::CHOICES as $c) {
        $choices[] = ['value' => $c, 'label' => SnowContractService::choiceLabel($c)];
    }
    echo json_encode([
        'success'  => true,
        'is_route' => (bool)$rates,
        'choice'   => $choice,
        'label'    => $choice ? SnowContractService::choiceLabel($choice) : null,
        'rates'    => array_map(fn($r) => ['role' => $r['role'], 'label' => $r['label'], 'unit_price' => (float)$r['unit_price']], $rates),
        'choices'  => $rates ? $choices : [],
    ]);
} catch (Throwable $e) {
    error_log('snow-route-choice: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not load this stop.']);
}
