<?php
/**
 * Where Intuit sends the owner back after the QuickBooks sign-in (?code, ?state, ?realmId, or ?error).
 * Must match the Intuit app's redirect URI exactly. Checks the state, stores the connection, reads the
 * lists, then returns to quickbooks.php with a message. Nothing is sent to QuickBooks.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
requirePermission('settings.edit');
$user = getCurrentUser();
require_once APP_ROOT . '/Modules/QuickBooks/Services/QboConnectionService.php';

if (!empty($_GET['error'])) {
    // Only a cancel that carries our own state counts (Codex review 2026-10-20: a forged ?error link
    // must not wipe a sign-in in progress).
    if (!(new QboConnectionService(getDB()))->cancel($_SESSION, (string)($_GET['state'] ?? ''))) {
        header('Location: /crm/accounting/quickbooks.php'); exit;
    }
    $_SESSION['qbo_flash'] = ['ok' => false, 'message' => $_GET['error'] === 'access_denied'
        ? 'QuickBooks was not connected (the sign-in was cancelled).'
        : 'QuickBooks did not connect (' . substr(preg_replace('/[^a-z_]/i', '', (string)$_GET['error']), 0, 40) . ').'];
} else {
    try {
        $_SESSION['qbo_flash'] = (new QboConnectionService(getDB()))->finish(
            $_SESSION,
            (string)($_GET['code'] ?? ''),
            (string)($_GET['state'] ?? ''),
            (string)($_GET['realmId'] ?? ''),
            (int)($user['id'] ?? 0)
        );
    } catch (Throwable $e) {
        error_log('QuickBooks callback: ' . $e->getMessage());
        $_SESSION['qbo_flash'] = ['ok' => false, 'message' => 'QuickBooks did not connect. Try again.'];
    }
}
header('Location: /crm/accounting/quickbooks.php');
exit;
