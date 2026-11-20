<?php
/**
 * Reset OPcache — one big button, works on a phone. Admin only.
 *
 * This host runs OPcache with validate_timestamps off, so deployed PHP does not take effect until the cache is
 * reset. The topbar button is not on the phone layout, so this page exists too (and it is a NEW file, so it is
 * never itself stuck behind a stale cache). Same rules as api/opcache-reset.php: POST + CSRF only, admin only.
 */
declare(strict_types=1);

require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    exit('Admins only.');
}
header('Cache-Control: no-store');

$msg = '';
$ok = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $msg = 'That page expired. Reload it and press the button again.';
        $ok = false;
    } elseif (!function_exists('opcache_reset')) {
        $msg = 'OPcache is not enabled on this server, so there is nothing to reset.';
        $ok = false;
    } else {
        $ok = (bool)opcache_reset();
        $msg = $ok ? 'OPcache reset. New code is live.' : 'The reset did not work. Try once more.';
    }
}
$t = htmlspecialchars(generateCSRFToken(), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reset OPcache</title>
<style>
  body { font-family: -apple-system, system-ui, sans-serif; margin: 0; padding: 32px 20px; background: #E8F3F0; color: #0D3B2E; }
  main { max-width: 420px; margin: 0 auto; }
  h1 { font-size: 1.4rem; margin: 0 0 8px; }
  p { line-height: 1.5; }
  button { width: 100%; padding: 18px; font-size: 1.1rem; font-weight: 700; border: 0; border-radius: 12px; background: #2D8659; color: #fff; }
  .result { margin: 16px 0; padding: 14px; border-radius: 10px; background: #fff; font-weight: 600; }
  .result.bad { color: #8a1f11; background: #fdecea; }
  a { color: #2D8659; }
</style>
</head>
<body>
<main>
  <h1>Reset OPcache</h1>
  <p>After a deploy, the server keeps running the old PHP until this is pressed. Press it once, then carry on.</p>
  <?php if ($msg !== ''): ?><div class="result <?= $ok ? '' : 'bad' ?>"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= $t ?>">
    <button type="submit">Reset OPcache</button>
  </form>
  <p><a href="/crm/database_appstack.php">Back to Database Manager</a></p>
</main>
</body>
</html>
