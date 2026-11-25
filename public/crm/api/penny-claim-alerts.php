<?php
/**
 * Penny's "deposit this e-Transfer" pushes (EtransferClaimAlertService). Admin only.
 *
 * GET  ?mode=list                      → live claim-type transfers (deposit link, expiry, state)
 * POST {mode:'test', csrf_token}       → a test push to the signed-in admin
 * POST {mode:'run',  csrf_token}       → check deposits + push what's due now
 * POST {mode:'deposited', id, csrf_token} → stop the pushes for one transfer
 * ?mode=, not ?action= (see the /api/ router note in the vault).
 */
declare(strict_types=1);

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
require_once APP_ROOT . '/Modules/Accounting/Services/EtransferClaimAlertService.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();
if (!isAdmin()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Admin only']); exit; }
$user = getCurrentUser();
$db   = getDB();
$svc  = new EtransferClaimAlertService($db);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        if (!verifyCSRFToken($in['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Session expired - refresh and try again.']);
            exit;
        }
        session_write_close();
        switch ((string)($in['mode'] ?? '')) {
            case 'test':      echo json_encode(['ok' => true, 'result' => $svc->testPush((int)$user['id'])]); break;
            case 'run':       echo json_encode(['ok' => true, 'result' => $svc->run()]); break;
            case 'deposited': echo json_encode(['ok' => $svc->markDeposited((int)($in['id'] ?? 0))]); break;
            default:          echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
        }
        exit;
    }
    session_write_close();
    $rows = $db->query("
        SELECT id, sender_name, amount, email_date, expires_on, status, claim_alerted_at, claim_reminded, deposited_at,
               (deposit_url IS NOT NULL) AS has_link
        FROM etransfer_notifications WHERE transfer_type = 'claim'
        ORDER BY id DESC LIMIT 40
    ")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'claims' => $rows]);
} catch (Throwable $e) {
    error_log('penny-claim-alerts: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not load Penny\'s claim alerts.']);
}
