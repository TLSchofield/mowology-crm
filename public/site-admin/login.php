<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

// Already logged in — go to dashboard
if (isSiteLoggedIn()) {
    header('Location: /site-admin/');
    exit;
}

$error    = '';
$redirect = $_GET['redirect'] ?? '/site-admin/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        $error = 'Email and password are required.';
    } else {
        $user = siteLogin($email, $password);
        if ($user) {
            $redirect = filter_var($redirect, FILTER_VALIDATE_URL) ? $redirect : '/site-admin/';
            // Only allow same-origin redirects
            $redirectPath = parse_url($redirect, PHP_URL_PATH) ?? '/site-admin/';
            header('Location: ' . $redirectPath);
            exit;
        } else {
            $error = 'Invalid email or password.';
        }
    }
}

$siteRecord = $GLOBALS['__cms_site'] ?? [];
$logoPath   = $siteRecord['logo_path'] ?? '';
$siteName   = defined('SITE_NAME') ? SITE_NAME : 'My Site';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign In — <?= h($siteName) ?></title>
  <meta name="robots" content="noindex, nofollow">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/site-admin/css/admin.css">
</head>
<body class="sa-login-body">

<div class="sa-login-card">

  <div class="sa-login-logo">
    <?php if ($logoPath): ?>
      <img src="<?= h($logoPath) ?>" alt="<?= h($siteName) ?>">
    <?php else: ?>
      <div class="sa-login-logo-name"><?= h($siteName) ?></div>
    <?php endif; ?>
  </div>

  <h1>Sign in to your site</h1>

  <?php if ($error): ?>
    <div class="sa-error"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="POST">
    <div class="sa-form-group">
      <label class="sa-label" for="email">Email</label>
      <input
        type="email"
        id="email"
        name="email"
        class="sa-input"
        value="<?= h($_POST['email'] ?? '') ?>"
        autocomplete="email"
        autofocus
        required
      >
    </div>
    <div class="sa-form-group">
      <label class="sa-label" for="password">Password</label>
      <input
        type="password"
        id="password"
        name="password"
        class="sa-input"
        autocomplete="current-password"
        required
      >
    </div>
    <button type="submit" class="sa-btn sa-btn-primary" style="width:100%;justify-content:center;padding:11px;">
      Sign In
    </button>
  </form>

</div>

</body>
</html>
