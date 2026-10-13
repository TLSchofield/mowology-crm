<?php
/**
 * Confirm a new email address chosen in the client portal (security review #14, 2026-10-12).
 *
 *   GET  /customer/confirm-email.php?t=…  → shows the change and a Confirm button (a mail scanner
 *                                           fetching the link changes nothing)
 *   POST t=…                              → applies it (PortalEmailChangeService::confirm)
 * The token is single-use, stored hashed, and lasts 48 hours.
 */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

require_once dirname(__DIR__) . '/app_config/config.php';
require_once APP_ROOT . '/Core/Csp.php';
MwCsp::send();
require_once APP_ROOT . '/Modules/Contacts/Services/PortalEmailChangeService.php';

$db = getDB();
$svc = new PortalEmailChangeService($db);
$token = (string)($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['t'] ?? '') : ($_GET['t'] ?? ''));
$token = preg_match('/^[a-f0-9]{64}$/', $token) ? $token : '';

$done = null;
$pending = null;
$message = '';
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $done = $token !== '' ? $svc->confirm($token, (string)($_SERVER['REMOTE_ADDR'] ?? '')) : null;
        if ($done === null) $message = 'This link has expired or was already used. Please make the change again from your portal, or call (778) 846-9273.';
    } else {
        $pending = $token !== '' ? $svc->pending($token) : null;
        if ($pending === null) $message = 'This link has expired or was already used. Please make the change again from your portal, or call (778) 846-9273.';
    }
} catch (Throwable $e) {
    error_log('confirm-email: ' . $e->getMessage());
    $message = 'Something went wrong. Please call (778) 846-9273.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Confirm Your Email — Mowology Landscaping</title>
  <meta name="robots" content="noindex, nofollow">
  <link rel="stylesheet" href="/customer/portal.css">
</head>
<body>
  <div class="portal-container" style="max-width:640px;">
    <div class="portal-hero">
      <div class="portal-hero-greeting">Confirm your email</div>
      <div class="portal-hero-sub">
        <?php if ($done !== null): ?>
          Done — we'll use <?= htmlspecialchars($done) ?> from now on.
        <?php elseif ($pending !== null): ?>
          Use <?= htmlspecialchars((string)$pending['new_email']) ?> for your Mowology invoices and updates?
        <?php else: ?>
          <?= htmlspecialchars($message) ?>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($pending !== null && $done === null): ?>
      <form method="post" action="/customer/confirm-email.php" style="margin-top:20px;">
        <input type="hidden" name="t" value="<?= htmlspecialchars($token) ?>">
        <button type="submit" class="portal-btn">Confirm my new email address</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>
