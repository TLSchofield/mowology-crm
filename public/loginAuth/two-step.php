<?php
/**
 * Second step of sign-in for someone who turned two-step on (sign-in security plan step 2, 2026-11-06).
 * login.php sets $_SESSION['two_step_pending'] after a correct password; nobody is signed in until the code
 * here is right. The pending state lives 5 minutes and survives 5 wrong codes.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/signin-chrome.php';
$__bizName = itSigninBizName();

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if (isLoggedIn()) {
    header('Location: /crm/app-launch.php');
    exit();
}

/** Back to the sign-in page, optionally with a one-time message. The pending state is always thrown away. */
$backToSignIn = function (string $notice = ''): void {
    unset($_SESSION['two_step_pending']);
    if ($notice !== '') $_SESSION['signin_notice'] = $notice;
    header('Location: /loginAuth/login.php');
    exit();
};

if (isset($_GET['cancel'])) $backToSignIn();

$pending = $_SESSION['two_step_pending'] ?? null;
$state = TwoStepService::pendingState($pending, time());
if ($state === 'expired') $backToSignIn('That took too long. Please sign in again.');
if ($state !== 'ok') $backToSignIn();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = (string)$pending['email'];
    $uid   = (int)$pending['uid'];
    if (!(isset($_POST['csrf_token']) && verifyLoginCSRFToken((string)$_POST['csrf_token']))) {
        $error = 'Invalid request. Please try again.';
    } elseif (isLoginRateLimited($email)) {
        $backToSignIn('Too many login attempts. Please try again in a few minutes.');
    } else {
        // Count the try first: a crash or a closed tab mid-check still uses one up.
        $_SESSION['two_step_pending']['tries'] = (int)$pending['tries'] + 1;
        $db = getDB();
        $svc = new TwoStepService($db, TwoStepService::keyFromSecrets());
        $ok = false;
        try {
            $ok = $svc->verify($uid, (string)($_POST['code'] ?? ''));
        } catch (Throwable $e) {
            error_log('two-step verify: ' . $e->getMessage());   // never a pass
        }
        if ($ok) {
            // The person must still exist, be active, and have the same password they typed a moment ago.
            $st = $db->prepare("SELECT id, email, username, password_hash, full_name, first_name, last_name, role, is_active, is_driver FROM users WHERE id = ? LIMIT 1");
            $st->execute([$uid]);
            $user = $st->fetch(PDO::FETCH_ASSOC);
            if (!$user || (isset($user['is_active']) && (int)$user['is_active'] === 0)
                || !hash_equals((string)$pending['pwd_v'], AuthFreshness::passwordVersion((string)$user['password_hash']))) {
                $backToSignIn('Please sign in again.');
            }
            $trustToken = null;
            if (!empty($_POST['trust_device'])) {
                try {
                    $trustToken = $svc->trust($uid, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
                } catch (Throwable $e) {
                    error_log('two-step trust: ' . $e->getMessage());   // signing in still works, just not remembered
                }
            }
            $isDriver = false;
            $role = null;
            finishSignIn($user, $email, $isDriver, $role);   // clears the pending state and closes the session
            if ($trustToken !== null) {
                $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                       || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
                setcookie(TwoStepService::cookieName(Tenant::isDefault(), Tenant::key()), $trustToken, [
                    'expires'  => time() + TwoStepService::TRUST_DAYS * 86400,
                    'path'     => '/',
                    'secure'   => $secure,   // HTTPS-only on the live site; plain http only for local testing
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            header('Location: ' . signInDestination($isDriver, $role, $email));
            exit();
        }
        // Every wrong code counts against the sign-in limits (per email, IP and pair), so starting a fresh pending
        // session can't reset the guessing (review 2026-11-06: 4 guesses per session, repeat, was unthrottled).
        recordFailedLogin($email);
        if ((int)$_SESSION['two_step_pending']['tries'] >= TwoStepService::MAX_TRIES) {
            $backToSignIn('Too many wrong codes. Please sign in again.');
        }
        $error = "That code didn't work. Check the 6 digits in your app, or use a backup code.";
    }
}

$csrf_token = generateLoginCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php itSigninHead('Two-step sign-in - ' . $__bizName); ?>
</head>
<body class="<?= itSigninBodyClass() ?>">
<main class="it-signin-wrap">
    <?php itSigninLockup(); ?>

    <div class="it-signin-card">
        <h1 class="it-signin-title">Two-step sign-in</h1>
        <p class="it-signin-sub">for <strong><?= htmlspecialchars($__bizName) ?></strong></p>

        <form class="it-signin-form" method="POST" action="">
            <?php if ($error): ?>
                <div class="it-signin-msg it-signin-msg--error" role="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

            <div class="it-signin-field">
                <label class="it-signin-label" for="code">6-digit code from your authenticator app, or a backup code</label>
                <input type="text" id="code" name="code" class="it-signin-input it-signin-code" required autofocus
                       autocomplete="one-time-code" inputmode="text" autocorrect="off" autocapitalize="none" spellcheck="false">
            </div>

            <label class="it-signin-check">
                <input type="checkbox" name="trust_device" value="1">
                <span>Trust this device for 30 days</span>
            </label>

            <button type="submit" class="it-signin-button">Continue</button>

            <div class="it-signin-links">
                <a href="/loginAuth/two-step.php?cancel=1" class="it-signin-link">Cancel and sign in again</a>
            </div>
        </form>
    </div>
</main>
</body>
</html>
