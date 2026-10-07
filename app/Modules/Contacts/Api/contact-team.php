<?php
/**
 * Contact page panels — "Team on this client" and "Recent conversation" (2026-10-07).
 *
 * GET ?mode=team&contact_id=N   ContactTeamService::forContact(): each head's lines about this
 *                               contact only, ordered (yes, money, replies, the rest), at most 6
 *                               with the rest counted.
 * GET ?mode=comms&contact_id=N  ContactTimelineService::forContact(): the last 90 days, newest first.
 *
 * Read-only. Session auth + clients.view (the contact page's own permission). ?mode=, never
 * ?action= (the /api/ router appends its own action). The page loads both after render, so a
 * failure here hides the panels and never breaks the page.
 */
declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store');

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
    requirePermission('clients.view');
    // Yui's sections and the SMS-consent fallback expect the messaging helpers.
    require_once CRM_INCLUDES . '/messaging.php';

    $mode = (string)($_GET['mode'] ?? '');
    $contactId = (int)($_GET['contact_id'] ?? 0);
    if ($contactId < 1 || !in_array($mode, ['team', 'comms'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'mode (team|comms) and contact_id are required']);
        exit;
    }
    session_write_close();   // read-only: don't hold the session lock while the heads think

    $db = getDB();
    if ($mode === 'team') {
        require_once APP_ROOT . '/Modules/Contacts/Services/ContactTeamService.php';
        echo json_encode(['ok' => true] + (new ContactTeamService($db))->forContact($contactId));
    } else {
        require_once APP_ROOT . '/Modules/Contacts/Services/ContactTimelineService.php';
        echo json_encode(['ok' => true] + (new ContactTimelineService($db))->forContact($contactId));
    }
} catch (Throwable $e) {
    error_log('contact-team API: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not load this panel.']);
}
