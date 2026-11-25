<?php
/**
 * Regenerate a client's portal link (security review #14, 2026-10-12).
 * POST contact_id, csrf_token — needs clients.edit. The old link stops working at once; the new one
 * lasts 365 days (PortalTokenService). Nothing is emailed: the office sends the new link when ready.
 * Redirects back to the contact page.
 */
declare(strict_types=1);

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
requirePermission('clients.edit');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('POST required'); }
if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) { http_response_code(403); exit('Invalid CSRF token'); }

$contactId = (int)($_POST['contact_id'] ?? 0);
$db = getDB();
$s = $db->prepare("SELECT id FROM contacts WHERE id = ?");
$s->execute([$contactId]);
if (!$s->fetchColumn()) { http_response_code(404); exit('Contact not found'); }

require_once APP_ROOT . '/Modules/Contacts/Services/PortalTokenService.php';
$user = getCurrentUser();
PortalTokenService::regenerate($db, $contactId, (int)($user['id'] ?? 0) ?: null, (string)($_SERVER['REMOTE_ADDR'] ?? ''));

header('Location: /crm/clients_appstack.php?action=view_contact&id=' . $contactId . '&portal_link=new');
exit;
