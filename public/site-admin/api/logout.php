<?php
declare(strict_types=1);

/**
 * Site-admin sign out — POST with the site-admin CSRF token (Fable audit 2026-10-12). A GET shows a
 * confirm button instead of signing out, so another site cannot log an editor out with a link.
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/auth.php';

if (!isSiteLoggedIn()) {
    header('Location: /site-admin/login.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && verifySiteCsrf()) {
    siteLogout();
    header('Location: /site-admin/login.php');
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Log out — Site admin</title>
</head>
<body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f2f2f7;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">
  <form method="post" action="/site-admin/api/logout.php" style="background:#fff;padding:32px;border-radius:12px;text-align:center;max-width:340px">
    <p style="margin:0 0 20px;color:#333">Log out of the site admin?</p>
    <?= siteCsrfField() ?>
    <button type="submit" style="background:#2D8659;color:#fff;border:0;border-radius:8px;padding:12px 28px;font-size:15px;cursor:pointer">Log out</button>
    <p style="margin:16px 0 0"><a href="/site-admin/" style="color:#666">Cancel</a></p>
  </form>
</body>
</html>
