<?php
declare(strict_types=1);
/**
 * /public/reset-password.php
 *
 * Reset Password — validates the token from the email link,
 * shows a new-password form, and updates the user's password.
 *
 * GET  ?token=RAW_TOKEN → validate token, show form
 * POST ?token=RAW_TOKEN → validate + update password
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
    $__dir = dirname($__dir);
}
unset($__dir, $__i);

require_once APP_ROOT . '/Core/config.php';

// ── Token validation helper ───────────────────────────────────────────────
function lookupToken(PDO $db, string $rawToken): ?array
{
    $hash = hash('sha256', $rawToken);
    $stmt = $db->prepare("
        SELECT prt.id, prt.user_id, prt.expires_at, prt.used_at,
               u.email, u.full_name
        FROM   password_reset_tokens prt
        JOIN   users u ON u.id = prt.user_id
        WHERE  prt.token_hash = ?
        LIMIT  1
    ");
    $stmt->execute([$hash]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── State ─────────────────────────────────────────────────────────────────
$rawToken = trim($_GET['token'] ?? $_POST['token'] ?? '');
$done     = false;
$error    = '';
$tokenRow = null;
$tokenValid = false;

if ($rawToken !== '') {
    $db = getDB();
    $tokenRow = lookupToken($db, $rawToken);

    if (!$tokenRow) {
        $error = 'This reset link is invalid. Please request a new one.';
    } elseif ($tokenRow['used_at'] !== null) {
        $error = 'This reset link has already been used. Please request a new one.';
    } elseif (strtotime($tokenRow['expires_at']) < time()) {
        $error = 'This reset link has expired. Please request a new one.';
    } else {
        $tokenValid = true;
    }
} else {
    $error = 'No reset token provided. Please use the link from your email.';
}

// ── Handle POST (password update) ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid && $tokenRow) {
    $password  = $_POST['password']  ?? '';
    $password2 = $_POST['password2'] ?? '';

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
        $tokenValid = true; // keep form visible
    } elseif ($password !== $password2) {
        $error = 'Passwords do not match.';
        $tokenValid = true;
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $db->beginTransaction();
        try {
            $db->prepare("UPDATE users SET password_hash = ? WHERE id = ? LIMIT 1")
               ->execute([$hash, (int)$tokenRow['user_id']]);

            $db->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ? LIMIT 1")
               ->execute([(int)$tokenRow['id']]);

            $db->commit();
            $done = true;
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('[reset-password] ' . $e->getMessage());
            $error = 'Something went wrong. Please try again or contact support.';
        }
    }
}

$name = htmlspecialchars($tokenRow['full_name'] ?? 'there');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password — Mowology</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      background: #f2f2f7;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
    }

    .header {
      width: 100%;
      background: linear-gradient(135deg, #2D8659 0%, #1A5F4A 100%);
      padding: 48px 24px 44px;
      text-align: center;
    }
    .header-icon { font-size: 40px; margin-bottom: 10px; display: block; }
    .header h1  { color: #fff; font-size: 26px; font-weight: 700; letter-spacing: -0.3px; }
    .header p   { color: rgba(255,255,255,0.78); font-size: 13px; margin-top: 4px; }

    .card {
      background: #fff;
      border-radius: 16px;
      padding: 28px 24px;
      margin: -20px 20px 0;
      width: calc(100% - 40px);
      max-width: 400px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    }

    h2 { font-size: 19px; color: #1a1a1a; margin-bottom: 6px; }
    .subtitle { color: #666; font-size: 14px; line-height: 1.5; margin-bottom: 20px; }

    .field { margin-bottom: 16px; }
    .field label {
      display: block;
      font-size: 12px;
      font-weight: 600;
      color: #555;
      text-transform: uppercase;
      letter-spacing: 0.4px;
      margin-bottom: 6px;
    }
    .field input[type="password"] {
      width: 100%;
      padding: 12px 14px;
      border: 1px solid #ddd;
      border-radius: 10px;
      font-size: 15px;
      color: #1a1a1a;
      background: #fafafa;
      transition: border-color 0.15s;
    }
    .field input[type="password"]:focus {
      outline: none;
      border-color: #2D8659;
      background: #fff;
    }
    .hint { font-size: 11px; color: #999; margin-top: 4px; }

    .error {
      background: #fff0f0;
      border: 1px solid rgba(220,0,0,0.2);
      color: #c00;
      border-radius: 8px;
      padding: 10px 14px;
      font-size: 13px;
      margin-bottom: 16px;
    }

    button[type="submit"] {
      width: 100%;
      margin-top: 8px;
      padding: 14px;
      background: #2D8659;
      color: #fff;
      border: none;
      border-radius: 12px;
      font-size: 15px;
      font-weight: 700;
      cursor: pointer;
      transition: background 0.15s;
    }
    button[type="submit"]:hover { background: #1A5F4A; }

    .back-link {
      display: block;
      text-align: center;
      margin-top: 18px;
      color: #2D8659;
      font-size: 14px;
      text-decoration: none;
    }
    .back-link:hover { text-decoration: underline; }

    .success-icon  { font-size: 48px; text-align: center; margin-bottom: 12px; }
    .success-title { font-size: 19px; font-weight: 700; color: #1a1a1a; text-align: center; margin-bottom: 8px; }
    .success-body  { color: #555; font-size: 14px; line-height: 1.6; text-align: center; }
    .success-note  { color: #888; font-size: 12px; text-align: center; margin-top: 16px; }
  </style>
</head>
<body>

  <div class="header">
    <span class="header-icon">🌿</span>
    <h1>Mowology</h1>
    <p>Field Manager</p>
  </div>

  <div class="card">

    <?php if ($done): ?>

      <div class="success-icon">✅</div>
      <div class="success-title">Password updated!</div>
      <div class="success-body">
        Your password has been changed successfully.<br>
        You can now sign in to the Mowology app with your new password.
      </div>
      <div class="success-note">You can close this window.</div>

    <?php elseif (!$tokenValid): ?>

      <div class="error"><?= htmlspecialchars($error) ?></div>
      <a href="/forgot-password" class="back-link">← Request a new reset link</a>

    <?php else: ?>

      <h2>Choose a new password</h2>
      <p class="subtitle">Hi <?= $name ?>, enter a new password for your account.</p>

      <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" action="/reset-password?token=<?= urlencode($rawToken) ?>">
        <input type="hidden" name="token" value="<?= htmlspecialchars($rawToken) ?>">

        <div class="field">
          <label for="password">New password</label>
          <input
            type="password"
            id="password"
            name="password"
            autocomplete="new-password"
            placeholder="At least 8 characters"
            required
            autofocus
          >
          <p class="hint">Minimum 8 characters</p>
        </div>

        <div class="field">
          <label for="password2">Confirm password</label>
          <input
            type="password"
            id="password2"
            name="password2"
            autocomplete="new-password"
            placeholder="Repeat your new password"
            required
          >
        </div>

        <button type="submit">Set New Password</button>
      </form>

      <a href="/forgot-password" class="back-link">← Back to forgot password</a>

    <?php endif; ?>

  </div>

</body>
</html>
