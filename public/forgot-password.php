<?php
declare(strict_types=1);
/**
 * /public/forgot-password.php
 *
 * Forgot Password — public standalone page (no CRM auth required).
 * Used by the iOS app: opens in Safari when user taps "Forgot password?".
 *
 * GET  → show email form
 * POST → validate email, generate token, send reset email, show success
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
require_once APP_ROOT . '/Services/Messaging/EmailHelper.php';

// ── Handle POST ───────────────────────────────────────────────────────────
$sent    = false;
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $db   = getDB();
        $user = $db->prepare("SELECT id, full_name FROM users WHERE LOWER(email) = ? AND is_active = 1 LIMIT 1");
        $user->execute([$email]);
        $row = $user->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            // Generate a cryptographically secure token
            $rawToken  = bin2hex(random_bytes(32));           // 64 hex chars
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1-hour window

            // Invalidate any existing unused tokens for this user
            $db->prepare("DELETE FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL")
               ->execute([(int)$row['id']]);

            // Store the hash (never the raw token)
            $db->prepare("INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)")
               ->execute([(int)$row['id'], $tokenHash, $expiresAt]);

            // Build reset URL
            $resetUrl = 'https://mowology.ca/reset-password?token=' . urlencode($rawToken);
            $name     = htmlspecialchars($row['full_name'] ?? 'there');

            $subject  = 'Reset your Mowology password';
            $body     = <<<HTML
            <div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:32px 24px;">
              <div style="text-align:center;margin-bottom:28px;">
                <div style="display:inline-block;background:#2D8659;border-radius:50%;width:56px;height:56px;line-height:56px;text-align:center;">
                  <span style="color:#fff;font-size:28px;">🌿</span>
                </div>
                <h2 style="color:#1A5F4A;margin:12px 0 0;">Mowology</h2>
              </div>
              <h3 style="color:#1a1a1a;margin:0 0 12px;">Reset your password</h3>
              <p style="color:#444;line-height:1.6;margin:0 0 24px;">Hi {$name},<br><br>
                We received a request to reset the password for your Mowology Field Manager account.
                Click the button below to choose a new password. This link expires in <strong>1 hour</strong>.
              </p>
              <div style="text-align:center;margin:24px 0;">
                <a href="{$resetUrl}"
                   style="display:inline-block;background:#2D8659;color:#fff;text-decoration:none;
                          padding:14px 32px;border-radius:8px;font-weight:bold;font-size:15px;">
                  Reset Password
                </a>
              </div>
              <p style="color:#888;font-size:13px;margin:24px 0 0;line-height:1.6;">
                If you didn't request this, you can safely ignore this email — your password won't change.<br><br>
                This link will expire at {$expiresAt} (server time).
              </p>
              <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
              <p style="color:#aaa;font-size:12px;text-align:center;margin:0;">
                Mowology Landscaping · Vancouver, BC · (778) 846-9273
              </p>
            </div>
HTML;

            sendCrmEmail($email, $subject, $body);
        }

        // Always show success — avoids email enumeration
        $sent = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot Password — Mowology</title>
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
    .header-icon {
      font-size: 40px;
      margin-bottom: 10px;
      display: block;
    }
    .header h1 {
      color: #fff;
      font-size: 26px;
      font-weight: 700;
      letter-spacing: -0.3px;
    }
    .header p {
      color: rgba(255,255,255,0.78);
      font-size: 13px;
      margin-top: 4px;
    }

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

    .field label {
      display: block;
      font-size: 12px;
      font-weight: 600;
      color: #555;
      text-transform: uppercase;
      letter-spacing: 0.4px;
      margin-bottom: 6px;
    }
    .field input[type="email"] {
      width: 100%;
      padding: 12px 14px;
      border: 1px solid #ddd;
      border-radius: 10px;
      font-size: 15px;
      color: #1a1a1a;
      background: #fafafa;
      transition: border-color 0.15s;
    }
    .field input[type="email"]:focus {
      outline: none;
      border-color: #2D8659;
      background: #fff;
    }

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
      margin-top: 18px;
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

    /* Success state */
    .success-icon { font-size: 48px; text-align: center; margin-bottom: 12px; }
    .success-title { font-size: 19px; font-weight: 700; color: #1a1a1a; text-align: center; margin-bottom: 8px; }
    .success-body  { color: #555; font-size: 14px; line-height: 1.6; text-align: center; }
    .success-note  { color: #888; font-size: 12px; text-align: center; margin-top: 16px; line-height: 1.5; }
  </style>
</head>
<body>

  <div class="header">
    <span class="header-icon">🌿</span>
    <h1>Mowology</h1>
    <p>Field Manager</p>
  </div>

  <div class="card">

    <?php if ($sent): ?>

      <div class="success-icon">📬</div>
      <div class="success-title">Check your email</div>
      <div class="success-body">
        If that email address is linked to an account, we've sent a password reset link.
        It expires in <strong>1 hour</strong>.
      </div>
      <div class="success-note">
        Didn't get it? Check your spam folder or contact<br>
        <strong>(778) 846-9273</strong>
      </div>
      <a href="/forgot-password" class="back-link">Try a different email</a>

    <?php else: ?>

      <h2>Forgot your password?</h2>
      <p class="subtitle">Enter your work email and we'll send you a link to reset it.</p>

      <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" action="/forgot-password">
        <div class="field">
          <label for="email">Email address</label>
          <input
            type="email"
            id="email"
            name="email"
            placeholder="you@mowology.ca"
            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
            autocomplete="email"
            required
            autofocus
          >
        </div>
        <button type="submit">Send Reset Link</button>
      </form>

    <?php endif; ?>

  </div>

</body>
</html>
