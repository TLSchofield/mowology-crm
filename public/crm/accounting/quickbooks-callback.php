<?php
/** Intuit OAuth return for both the CRM session and the phone Custom Tab. */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once APP_ROOT . '/Modules/QuickBooks/Services/QboConnectionService.php';
require_once APP_ROOT . '/Modules/QuickBooks/Services/QboPhoneCallbackService.php';

$db = getDB();
$state = (string)($_GET['state'] ?? '');
$phoneResult = (new QboPhoneCallbackService($db))->handle($_GET, !empty($_SESSION['qbo_oauth']));
if ($phoneResult !== null) {
    $result = $phoneResult;
    error_log('QuickBooks phone callback: ' . (!empty($result['ok']) ? 'connected' : 'not connected'));
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>QuickBooks · Ittai</title><link rel="stylesheet" href="/crm/css/penny-onboarding.css"><link rel="stylesheet" href="/crm/css/ittai-onboarding.css"></head><body class="it-onboard"><main class="it-ob-shell"><section class="it-ob-conversation"><h1><?= !empty($result['ok']) ? 'QuickBooks is connected.' : 'QuickBooks did not connect.' ?></h1><p><?= h(!empty($result['ok']) ? 'Penny can read your QuickBooks now.' : (string)$result['message']) ?></p><div class="it-ob-actions"><a class="it-ob-button it-ob-button-primary" href="ittai://return">Back to Ittai</a></div><p class="it-ob-knowledge">Or close this tab.</p></section></main></body></html><?php
    exit;
}

requireLogin();
requirePermission('settings.edit');
$user = getCurrentUser();
error_log('QuickBooks callback: returned' . (!empty($_GET['error']) ? ' error=' . substr(preg_replace('/[^a-z_]/i', '', (string)$_GET['error']), 0, 40) : '')
          . ' code=' . (empty($_GET['code']) ? 'no' : 'yes') . ' realm=' . (empty($_GET['realmId']) ? 'no' : 'yes')
          . ' state=' . (empty($_GET['state']) ? 'no' : 'yes') . ' session_state=' . (empty($_SESSION['qbo_oauth']) ? 'no' : 'yes'));
if (!empty($_GET['error'])) {
    if (!(new QboConnectionService($db))->cancel($_SESSION, $state)) { header('Location: /crm/accounting/quickbooks.php'); exit; }
    $_SESSION['qbo_flash'] = ['ok' => false, 'message' => $_GET['error'] === 'access_denied' ? 'QuickBooks was not connected (the sign-in was cancelled).' : 'QuickBooks did not connect (' . substr(preg_replace('/[^a-z_]/i', '', (string)$_GET['error']), 0, 40) . ').'];
} else {
    try {
        $_SESSION['qbo_flash'] = (new QboConnectionService($db))->finish($_SESSION, (string)($_GET['code'] ?? ''), $state, (string)($_GET['realmId'] ?? ''), (int)($user['id'] ?? 0));
        error_log('QuickBooks callback: ' . (!empty($_SESSION['qbo_flash']['ok']) ? 'connected' : 'not connected'));
    } catch (Throwable $e) { error_log('QuickBooks callback: ' . get_class($e)); $_SESSION['qbo_flash'] = ['ok' => false, 'message' => 'QuickBooks did not connect. Try again.']; }
}
header('Location: /crm/accounting/quickbooks.php');
exit;
