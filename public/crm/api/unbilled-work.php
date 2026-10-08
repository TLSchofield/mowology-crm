<?php
/**
 * GET /crm/api/unbilled-work.php — other unbilled work at an address (read-only).
 *
 * Thin controller over UnbilledWorkFinder (app/Modules/Invoices/Services/UnbilledWorkFinder.php).
 * Session auth + billing.edit. GET only — nothing here writes; the claiming happens inside the
 * invoice transaction of invoices/create.php, pow-actions.php complete_stop and the iOS invoice API.
 *
 *   ?mode=list&property_id=29[&company_id=7][&exclude_visit_id=2500]
 *   ?mode=list&visit_id=2500            property + payer taken from the visit, which is excluded
 *       → { success, property_id, since, today, items[], hints[], subtotal_preselected, can_mark_done }
 *
 *   ?mode=dryrun&property_id=29          same, plus a plain-text summary per item, for checking
 *       → { success, ..., summary[] }    the finder against live data (expect #2112 Sep 29 "possibly
 *                                        done" with its 22-min timer, and #2412 $0 aeration)
 *
 * `mode`, never `action` (the /api/ rewrite appends its own action — see reference_api_router_action_param_collision).
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
require_once APP_ROOT . '/Modules/Invoices/Services/UnbilledWorkFinder.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

requireLogin();
$user = getCurrentUser();
session_write_close();

if (!userHasPermission('billing.edit') && ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Billing permission required.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'GET only.']);
    exit;
}

$mode = (string)($_GET['mode'] ?? 'list');
if (!in_array($mode, ['list', 'dryrun'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'mode must be list or dryrun.']);
    exit;
}

try {
    $db = getDB();
    $finder = new UnbilledWorkFinder($db);

    $propertyId = (int)($_GET['property_id'] ?? 0);
    $companyId  = (int)($_GET['company_id'] ?? 0) ?: null;
    $exclude    = array_filter([(int)($_GET['exclude_visit_id'] ?? 0)]);
    $visitId    = (int)($_GET['visit_id'] ?? 0);
    if ($visitId > 0) {
        $anchor = $finder->anchorForVisit($visitId);
        if (!$anchor) {
            echo json_encode(['success' => false, 'error' => 'Visit not found.']);
            exit;
        }
        $propertyId = $propertyId ?: $anchor['property_id'];
        $companyId  = $companyId ?: $anchor['company_id'];
        $exclude[]  = $visitId;
    }
    if ($propertyId < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'property_id or visit_id is required.']);
        exit;
    }

    $role = (string)($user['role'] ?? '');
    $out = ['success' => true, 'mode' => $mode, 'can_mark_done' => in_array($role, ['admin', 'manager'], true)]
         + $finder->find($propertyId, ['exclude_visit_ids' => array_values($exclude), 'company_id' => $companyId]);

    if ($mode === 'dryrun') {
        $out['summary'] = array_map(fn($it) => sprintf(
            '#%d %s · %s · $%s%s · %s%s',
            $it['visit_id'], $it['badge'], $it['description'],
            number_format((float)$it['amount'], 2),
            $it['suggested_amount'] !== null ? ' (suggest $' . number_format((float)$it['suggested_amount'], 2) . ')' : '',
            implode(' ', $it['evidence']),
            $it['warnings'] ? ' · WARN: ' . implode(' ', $it['warnings']) : ''
        ), $out['items']);
    }
    echo json_encode($out);
} catch (Throwable $e) {
    error_log('unbilled-work.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not look up unbilled work.']);
}
