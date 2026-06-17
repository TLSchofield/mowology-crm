<?php
/**
 * social-keymig.php — One-time SOCIAL_ENCRYPTION_KEY migration helper.
 *
 * Generates the base64 value you need to add to secrets.php so that
 * SocialEncryption uses a proper dedicated key instead of DB_PASS.
 *
 * The generated value is derived from DB_PASS using the same formula the
 * legacy fallback uses, so all previously encrypted tokens will continue
 * to decrypt after the migration — no re-auth required.
 *
 * Usage:
 *   1. Visit this page while logged in as admin
 *   2. Copy the define() line shown
 *   3. Add it to /public/app_config/secrets.php
 *   4. Delete this file
 */

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();

if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Admin only.');
}

if (!defined('DB_PASS') || DB_PASS === '') {
    http_response_code(500);
    exit('DB_PASS is not defined — cannot derive migration key.');
}

$legacyKey     = str_pad(substr(hash('sha256', DB_PASS, true), 0, 32), 32, "\0");
$base64Key     = base64_encode($legacyKey);
$alreadySet    = defined('SOCIAL_ENCRYPTION_KEY') && SOCIAL_ENCRYPTION_KEY !== '';
$keyMatches    = $alreadySet && base64_decode(SOCIAL_ENCRYPTION_KEY, true) === $legacyKey;

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Social Encryption Key Migration</title>
<style>
  body { font-family: monospace; max-width: 700px; margin: 40px auto; padding: 0 20px; }
  .ok  { color: #2D8659; } .warn { color: #e85d04; } .err { color: #c0392b; }
  pre  { background: #f4f4f4; padding: 16px; border-radius: 4px; overflow-x: auto; }
  code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; }
</style>
</head>
<body>
<h2>Social Encryption Key Migration</h2>

<?php if ($alreadySet && $keyMatches): ?>
  <p class="ok">&#10003; SOCIAL_ENCRYPTION_KEY is already set and matches the legacy key. Migration complete.</p>
  <p>You can delete this file.</p>
<?php elseif ($alreadySet && !$keyMatches): ?>
  <p class="err">&#9888; SOCIAL_ENCRYPTION_KEY is set but does NOT match the legacy key.<br>
     If you changed it, existing encrypted tokens will fail to decrypt. Proceed with caution.</p>
<?php else: ?>
  <p class="warn">&#9888; SOCIAL_ENCRYPTION_KEY is not yet in secrets.php.</p>
  <p>Add the following line to <code>/public/app_config/secrets.php</code>:</p>
  <pre>define('SOCIAL_ENCRYPTION_KEY', '<?= htmlspecialchars($base64Key) ?>');</pre>
  <p>This key is derived from DB_PASS using the same formula the legacy fallback uses,
     so all previously encrypted tokens (Google Business Profile, etc.) will continue
     to work after you add it — no re-authentication needed.</p>
  <p>After adding it, reload this page to confirm, then delete this file.</p>
<?php endif; ?>

<hr>
<h3>Current status</h3>
<ul>
  <li>SOCIAL_ENCRYPTION_KEY defined: <?= $alreadySet ? '<span class="ok">yes</span>' : '<span class="warn">no</span>' ?></li>
  <li>Matches legacy DB_PASS key: <?= $keyMatches ? '<span class="ok">yes</span>' : '<span class="warn">no</span>' ?></li>
</ul>

<p><small>Delete this file once migration is complete.</small></p>
</body>
</html>
