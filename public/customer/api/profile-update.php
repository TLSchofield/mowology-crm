<?php
/**
 * Client Profile Update API
 *
 * POST /customer/api/profile-update.php
 *   token                      - contacts.portal_token (required)
 *   first_name, last_name      - string
 *   email                      - string
 *   phone, mobile              - string
 *   preferred_contact_method   - 'email' | 'phone' | 'text'
 *   receive_marketing          - 0 | 1
 *   receive_sms                - 0 | 1
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/app_config/config.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('POST required');
    }

    $token = trim($_POST['token'] ?? '');
    if ($token === '') throw new Exception('Missing token');

    $db = getDB();
    require_once APP_ROOT . '/Modules/Contacts/Services/PortalTokenService.php';
    require_once APP_ROOT . '/Modules/Contacts/Services/PortalEmailChangeService.php';

    $stmt = $db->prepare("SELECT id, first_name, last_name, email, phone, mobile, preferred_contact_method,
                                 receive_marketing, receive_sms
                          FROM contacts WHERE portal_token = ?" . PortalTokenService::liveSql($db) . " LIMIT 1");
    $stmt->execute([$token]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contact) throw new Exception('Client not found');

    // ── Gather + validate ─────────────────────────────────────────────────
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $mobile    = trim($_POST['mobile'] ?? '');
    $preferred = trim($_POST['preferred_contact_method'] ?? '');

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Please enter a valid email address.');
    }
    if (!in_array($preferred, ['', 'email', 'phone', 'text'], true)) {
        $preferred = 'email';
    }

    // Phone sanity — strip to digits only for length check, but keep raw formatting
    $phoneDigits = preg_replace('/\D+/', '', $phone);
    if ($phone !== '' && strlen($phoneDigits) < 7) {
        throw new Exception('Phone number looks too short.');
    }
    $mobileDigits = preg_replace('/\D+/', '', $mobile);
    if ($mobile !== '' && strlen($mobileDigits) < 7) {
        throw new Exception('Mobile number looks too short.');
    }

    // An email change is never applied from the portal link alone (security review #14): it waits
    // for the NEW address to confirm, and the OLD address is told. Blanking the email is ignored.
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $emailPending = false;
    $oldEmail = (string)($contact['email'] ?? '');
    if (PortalEmailChangeService::needsConfirmation($oldEmail, $email)) {
        $changes = new PortalEmailChangeService($db);
        if (!$changes->ready()) {
            throw new Exception('To change your email address, please call us at (778) 846-9273.');
        }
        $base = defined('SITE_URL') ? (string)SITE_URL : 'https://mowology.ca';
        $changes->request((int)$contact['id'], $oldEmail !== '' ? $oldEmail : null, $email, (string)($contact['first_name'] ?? ''), $ip, $base);
        $emailPending = true;
    }
    $email = $oldEmail;   // the stored email only changes through PortalEmailChangeService::confirm()

    $receiveMarketing = !empty($_POST['receive_marketing']) ? 1 : 0;
    $receiveSms       = !empty($_POST['receive_sms']) ? 1 : 0;

    // Full-name composition (the users/contacts tables tend to keep both separate
    // fields AND a full_name column — update both for safety)
    $fullName = trim($firstName . ' ' . $lastName);

    // Build update
    $sets   = [];
    $params = [];

    $sets[] = 'first_name = ?';      $params[] = $firstName ?: null;
    $sets[] = 'last_name = ?';       $params[] = $lastName ?: null;
    $sets[] = 'email = ?';           $params[] = $email ?: null;
    $sets[] = 'phone = ?';           $params[] = $phone ?: null;
    $sets[] = 'mobile = ?';          $params[] = $mobile ?: null;
    $sets[] = 'preferred_contact_method = ?'; $params[] = $preferred ?: null;
    $sets[] = 'receive_marketing = ?'; $params[] = $receiveMarketing;
    $sets[] = 'receive_sms = ?';       $params[] = $receiveSms;

    // full_name may or may not exist on contacts — try to include it, fall back
    try {
        $params[] = $fullName ?: null;
        $params[] = (int)$contact['id'];
        $upd = $db->prepare("UPDATE contacts SET " . implode(', ', $sets) . ", full_name = ? WHERE id = ?");
        $upd->execute($params);
    } catch (PDOException $e) {
        // Retry without full_name
        array_pop($params); // remove id
        array_pop($params); // remove full_name
        $params[] = (int)$contact['id'];
        $upd = $db->prepare("UPDATE contacts SET " . implode(', ', $sets) . " WHERE id = ?");
        $upd->execute($params);
    }

    // Trail for every change (Fable review 2026-10-12) — the token is permanent, so an email/phone
    // change made with it must be visible to the office afterwards.
    require_once APP_ROOT . '/Modules/Contacts/Services/PortalProfileAudit.php';
    PortalProfileAudit::record($db, (int)$contact['id'], PortalProfileAudit::diff($contact, [
        'first_name' => $firstName, 'last_name' => $lastName, 'email' => $email, 'phone' => $phone,
        'mobile' => $mobile, 'preferred_contact_method' => $preferred,
        'receive_marketing' => $receiveMarketing, 'receive_sms' => $receiveSms,
    ]), $ip, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

    echo json_encode(['success' => true, 'email_pending' => $emailPending,
                      'message' => $emailPending ? 'Saved. We sent a link to your new email address — your email changes once you click it.' : null]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
