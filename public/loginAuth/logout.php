<?php
/**
 * Sign out — POST with the session's CSRF token (Fable audit 2026-10-12). A plain GET (an old link,
 * or a forged <img src> on another site) no longer signs anyone out: it shows a confirm button that
 * posts the token.
 */
require_once __DIR__ . '/auth.php';

if (!isLoggedIn()) {
    header('Location: /loginAuth/login.php');
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && verifyCSRFToken((string)($_POST['csrf_token'] ?? $_POST['csrf'] ?? ''))) {
    logoutUser();
    header('Location: /loginAuth/login.php');
    exit();
}

$token = generateCSRFToken();
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign out — Mowology</title>
</head>
<body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f2f2f7;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">
  <form method="post" action="/loginAuth/logout.php" style="background:#fff;padding:32px;border-radius:12px;text-align:center;max-width:340px">
    <p style="margin:0 0 20px;color:#333">Sign out of Mowology?</p>
    <input type="hidden" name="csrf_token" value="<?= h($token) ?>">
    <button type="submit" style="background:#2D8659;color:#fff;border:0;border-radius:8px;padding:12px 28px;font-size:15px;cursor:pointer">Sign out</button>
    <p style="margin:16px 0 0"><a href="/crm/" style="color:#666">Cancel</a></p>
  </form>
</body>
</html>
