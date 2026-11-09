<?php
/**
 * Two-step sign-in settings — any signed-in person, their own account only (sign-in security plan step 2).
 * Logic lives in app/Core/Auth/TwoStepService.php; this page only asks and shows.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
$userId = (int)$user['id'];

$pageTitle = 'Two-step sign-in';
$activePage = 'settings';

$svc = new TwoStepService(getDB(), TwoStepService::keyFromSecrets());
$flash = '';
$flashType = 'success';
$newCodes = null;   // backup codes, shown once, straight from the POST — never kept in the session

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('The security token expired. Please try again.');
        }
        $code = (string)($_POST['code'] ?? '');
        if ($action === 'begin') {
            $svc->beginSetup($userId);
        } elseif ($action === 'confirm') {
            $newCodes = $svc->confirmSetup($userId, $code);
            if ($newCodes === null) throw new RuntimeException("That code didn't match. Check the 6 digits in your app and try again.");
            $flash = 'Two-step sign-in is on.';
        } elseif ($action === 'new_codes') {
            // Throttled like sign-in, so a hijacked session can't guess its way to switching this off (review 2026-11-06).
            if (isLoginRateLimited('2fa:' . $userId)) throw new RuntimeException('Too many wrong codes. Wait a few minutes and try again.');
            if (!$svc->verify($userId, $code)) { recordFailedLogin('2fa:' . $userId); throw new RuntimeException("That code didn't work, so your backup codes were not changed."); }
            $newCodes = $svc->regenerateBackupCodes($userId);
            $flash = 'New backup codes. The old ones no longer work.';
        } elseif ($action === 'turn_off') {
            // Throttled like sign-in, so a hijacked session can't guess its way to switching this off (review 2026-11-06).
            if (isLoginRateLimited('2fa:' . $userId)) throw new RuntimeException('Too many wrong codes. Wait a few minutes and try again.');
            if (!$svc->verify($userId, $code)) { recordFailedLogin('2fa:' . $userId); throw new RuntimeException("That code didn't work, so two-step sign-in is still on."); }
            $svc->disable($userId, $userId);
            $flash = 'Two-step sign-in is off.';
        } elseif ($action === 'revoke') {
            $svc->revokeDevice($userId, (int)($_POST['device_id'] ?? 0));
            $flash = 'That device will be asked for a code next time.';
        } elseif ($action === 'revoke_all') {
            $svc->revokeAllDevices($userId);
            $flash = 'Every device will be asked for a code next time.';
        }
    } catch (RuntimeException $e) {
        $flash = $e->getMessage();
        $flashType = 'danger';
    } catch (Throwable $e) {
        error_log('two-step settings: ' . $e->getMessage());
        $flash = 'Something went wrong. Please try again.';
        $flashType = 'danger';
    }
}

$status = ['available' => $svc->available(), 'tables' => false, 'enrolled' => false, 'setup_started' => false, 'backup_left' => 0];
try { $status = $svc->status($userId); } catch (Throwable $e) { error_log('two-step status: ' . $e->getMessage()); }
$setupSecret = $status['setup_started'] ? $svc->setupSecret($userId) : null;
$otpUrl = '';
if ($setupSecret !== null) {
    $otpUrl = 'otpauth://totp/' . rawurlencode('Ittai:' . (string)($user['email'] ?? '')) . '?secret=' . $setupSecret . '&issuer=Ittai';
}
$devices = $status['enrolled'] ? $svc->devices($userId) : [];
$csrf = generateCSRFToken();
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <h1 class="h3 mb-3">Two-step sign-in</h1>

          <?php if ($flash !== ''): ?>
          <div class="alert alert-<?= h($flashType) ?>" role="alert"><?= h($flash) ?></div>
          <?php endif; ?>

          <?php if (!$status['available'] || !$status['tables']): ?>
          <div class="card"><div class="card-body">
            <p class="mb-0">Two-step sign-in is not available here yet. Ask your administrator to finish setting it up.</p>
          </div></div>

          <?php elseif ($newCodes !== null): ?>
          <div class="card"><div class="card-body">
            <h5 class="card-title">Your backup codes</h5>
            <p>Each code works once if you can't use your authenticator app. Save them somewhere safe now: they are not shown again.</p>
            <ul class="mw-backup-codes">
              <?php foreach ($newCodes as $c): ?><li><?= h($c) ?></li><?php endforeach; ?>
            </ul>
            <a class="btn btn-primary" href="/crm/account/two-step.php">I've saved these</a>
          </div></div>

          <?php elseif ($status['enrolled']): ?>
          <div class="card mb-3"><div class="card-body">
            <p>Two-step sign-in is <strong>on</strong>. After your password you are asked for a code from your authenticator app. You have <strong><?= (int)$status['backup_left'] ?></strong> backup code(s) left.</p>
            <form method="POST" class="mb-3">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="new_codes">
              <label for="code-new">Get new backup codes (enter a current code to confirm)</label>
              <div class="input-group mw-two-step-field">
                <input type="text" id="code-new" name="code" class="form-control" required autocomplete="one-time-code" inputmode="text">
                <div class="input-group-append"><button type="submit" class="btn btn-outline-primary">Get new codes</button></div>
              </div>
            </form>
            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="turn_off">
              <label for="code-off">Turn off two-step sign-in (enter a current code or a backup code)</label>
              <div class="input-group mw-two-step-field">
                <input type="text" id="code-off" name="code" class="form-control" required autocomplete="one-time-code" inputmode="text">
                <div class="input-group-append"><button type="submit" class="btn btn-outline-danger">Turn off</button></div>
              </div>
            </form>
          </div></div>

          <div class="card"><div class="card-body">
            <h5 class="card-title">Trusted devices</h5>
            <?php if (!$devices): ?>
              <p class="text-muted mb-0">None. Tick "Trust this device for 30 days" when you sign in to add one.</p>
            <?php else: ?>
              <table class="table table-sm">
                <thead><tr><th>Browser</th><th>Added</th><th>Last used</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($devices as $d): ?>
                  <tr>
                    <td><?= h(TwoStepService::describeAgent((string)$d['user_agent'])) ?></td>
                    <td><?= h(substr((string)$d['created_at'], 0, 10)) ?></td>
                    <td><?= h(substr((string)$d['last_seen_at'], 0, 10)) ?></td>
                    <td class="text-right">
                      <form method="POST" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="revoke">
                        <input type="hidden" name="device_id" value="<?= (int)$d['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Revoke</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
              <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="revoke_all">
                <button type="submit" class="btn btn-sm btn-outline-danger">Revoke all</button>
              </form>
            <?php endif; ?>
          </div></div>

          <?php elseif ($setupSecret !== null): ?>
          <div class="card"><div class="card-body">
            <h5 class="card-title">Set up your authenticator app</h5>
            <p>Scan this with an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password and others), then enter the 6-digit code it shows.</p>
            <div class="mw-two-step-qr" id="two-step-qr" aria-hidden="true"></div>
            <p class="mt-3 mb-1">Can't scan? Enter this key in the app:</p>
            <p class="mw-two-step-key"><?= h(trim(chunk_split($setupSecret, 4, ' '))) ?></p>
            <p><a href="<?= h($otpUrl) ?>">Open in an authenticator app on this device</a></p>
            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="confirm">
              <label for="code-confirm">6-digit code</label>
              <div class="input-group mw-two-step-field">
                <input type="text" id="code-confirm" name="code" class="form-control" required autocomplete="one-time-code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7">
                <div class="input-group-append"><button type="submit" class="btn btn-primary">Turn on</button></div>
              </div>
            </form>
          </div></div>
          <script src="/crm/js/vendor/qrcode.min.js"></script>
          <script>
          (function () {
              var box = document.getElementById('two-step-qr');
              if (!box || typeof qrcode !== 'function') return;   // no QR library: the key and link above still work
              var qr = qrcode(0, 'M');
              qr.addData(<?= json_encode($otpUrl) ?>);
              qr.make();
              box.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
              box.removeAttribute('aria-hidden');
          })();
          </script>

          <?php else: ?>
          <div class="card"><div class="card-body">
            <p>Two-step sign-in adds a 6-digit code from an app on your phone to your password, so a stolen password alone can't open your account.</p>
            <p>It is off until you turn it on. You will also get 10 backup codes, and you can trust a device for 30 days so it doesn't ask every time.</p>
            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="begin">
              <button type="submit" class="btn btn-primary">Turn on</button>
            </form>
          </div></div>
          <?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
