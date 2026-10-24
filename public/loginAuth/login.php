<?php
require_once __DIR__ . '/auth.php';
// The business's own name (Ittai founding businesses, 2026-10-23); never another business's.
require_once __DIR__ . '/signin-chrome.php';   // Ittai v1.2 sign-in look (2026-10-23)
$__bizName = itSigninBizName();

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// If already logged in, route to the correct portal
if (isLoggedIn()) {
    $u = getCurrentUser();
    header('Location: ' . (AccountantFence::isAccountantRole($u['role'] ?? '') ? AccountantFence::HOME
        : (!empty($u['is_driver']) ? '/crm/driver-portal.php' : '/crm/app-launch.php')));
    exit();
}


$error = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Use stateless HMAC CSRF for the login form — standard session-based CSRF
    // requires the session cookie to be sent on the first POST, which the
    // Capacitor Android WebView sometimes fails to do (cookie-store flush race).
    // The stateless token is valid for up to 10 minutes and needs no session.
    if (isset($_POST['csrf_token']) && verifyLoginCSRFToken($_POST['csrf_token'])) {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        // Normalize email: lowercase, strip invisible chars and spaces
        // (Android autocorrect can replace dots with spaces in email addresses)
        $email = strtolower($email);
        $email = preg_replace('/[\x00-\x1F\x7F\xC2\xA0\x20]/u', '', $email);

        if (empty($email) || empty($password)) {
            $error = 'Please enter both email and password.';
        } elseif (isLoginRateLimited($email)) {
            $error = 'Too many login attempts. Please try again in a few minutes.';
        } else {
            $isDriver = false;
            $loginRole = null;
            if (loginUser($email, $password, $isDriver, $loginRole)) {
                // Use the $isDriver flag captured by loginUser() before session_write_close()
                // cleared $_SESSION — reading $_SESSION here would always return empty.
                // The outside accountant lands on the Ledger (AccountantFence, 2026-10-13).
                $dest = AccountantFence::isAccountantRole($loginRole) ? AccountantFence::HOME
                    : ($isDriver ? '/crm/driver-portal.php' : '/crm/app-launch.php');
                header('Location: ' . $dest);
                exit();
            } else {
                $error = 'Invalid email or password.';
            }
        }
    } else {
        $error = 'Invalid request. Please try again.';
    }
}

$csrf_token = generateLoginCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php itSigninHead('Sign in - ' . $__bizName); ?>
</head>
<body class="<?= itSigninBodyClass() ?>">
<main class="it-signin-wrap">
    <?php itSigninLockup(); ?>

    <div class="it-signin-card">
        <h1 class="it-signin-title">Sign in</h1>
        <p class="it-signin-sub">to <strong><?= htmlspecialchars($__bizName) ?></strong></p>

        <div id="appBanner" class="it-signin-banner" role="status">
            <div class="it-signin-banner-icon" id="bannerIcon" aria-hidden="true"></div>
            <div class="it-signin-banner-text">
                <span class="it-signin-banner-label" id="bannerLabel"></span>
                <div class="it-signin-banner-title" id="bannerTitle"></div>
                <div class="it-signin-banner-desc" id="bannerDesc"></div>
            </div>
            <div class="it-signin-banner-arrow" id="bannerArrow" aria-hidden="true"></div>
        </div>

        <form class="it-signin-form" method="POST" action="">
            <?php if ($error): ?>
                <div class="it-signin-msg it-signin-msg--error" role="alert"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

            <div class="it-signin-field">
                <label class="it-signin-label" for="email">Email or username</label>
                <input
                    type="text"
                    id="email"
                    name="email"
                    class="it-signin-input"
                    required
                    autocomplete="username"
                    autocorrect="off"
                    autocapitalize="none"
                    spellcheck="false"
                    value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                >
            </div>

            <div class="it-signin-field">
                <label class="it-signin-label" for="password">Password</label>
                <div class="it-signin-pw">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="it-signin-input"
                        required
                        autocomplete="current-password"
                        autocorrect="off"
                        autocapitalize="none"
                        spellcheck="false"
                    >
                    <button type="button" id="toggle-password" class="it-signin-show" aria-label="Show password" aria-pressed="false">Show</button>
                </div>
            </div>

            <button type="submit" class="it-signin-button">Sign in</button>

            <div class="it-signin-links">
                <a href="forgot_password.php" class="it-signin-link">Forgot your password?</a>
            </div>
        </form>
    </div>
</main>
<script>
// Signed out (logout, or the login ran out): wipe the cached personal pages and API answers the app
// keeps for offline use, so the next person on a shared device (the truck's Samsung tablet) is never
// shown the last person's schedule, timesheet or client details (Codex review, 2026-10-14). Map tiles,
// app files and queued photos/receipts are kept: they are not personal pages, and photos must never be lost.
(function () {
    try {
        if (!window.caches || !caches.keys) return;
        caches.keys().then(function (keys) {
            keys.forEach(function (k) { if (k.indexOf('mw-pages-') === 0) caches.delete(k); });
        });
    } catch (e) { /* no cache API: nothing kept */ }
})();
(function() {
    'use strict';
    // The crew APK and its version feed belong to the original business; other businesses get no banner (yet).
    if (!<?= Tenant::isDefault() ? 'true' : 'false' ?>) return;
    var appName = <?= json_encode($__bizName . ' Crew') ?>;
    var ua = navigator.userAgent || '';
    var isAndroid  = /Android/i.test(ua);
    var isCapacitor = !!(window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform());
    var banner = document.getElementById('appBanner');

    // semver compare — returns 1 if a > b, -1 if a < b, 0 if equal
    function semverCmp(a, b) {
        var pa = (a || '0').split('.').map(Number);
        var pb = (b || '0').split('.').map(Number);
        for (var i = 0; i < 3; i++) {
            if ((pa[i] || 0) > (pb[i] || 0)) return 1;
            if ((pa[i] || 0) < (pb[i] || 0)) return -1;
        }
        return 0;
    }

    var _apkUrl = '/crm/downloads/mowology-crew.apk';

    // Tap handler — only active on update-available and download banners.
    // Strategy: App.openUrl is tried first (correct Capacitor path), but for
    // same-domain URLs it may return {completed:false} without rejecting.
    // Fallback: window.location.href triggers Android WebView's DownloadListener
    // (requires Content-Disposition:attachment on the server — set in downloads/.htaccess).
    window.handleAppBannerClick = function() {
        var url = 'https://mowology.ca' + _apkUrl;
        var cap = window.Capacitor;
        if (cap && cap.Plugins && cap.Plugins.App && cap.Plugins.App.openUrl) {
            cap.Plugins.App.openUrl({ url: url })
                .then(function(result) {
                    // completed:false means Android found no handler — fall through
                    if (!result || result.completed === false) {
                        window.location.href = url;
                    }
                })
                .catch(function() {
                    window.location.href = url;
                });
        } else {
            // No App plugin — direct navigation triggers WebView DownloadListener
            window.location.href = url;
        }
    };

    // State: up-to-date (non-interactive, reassuring)
    function showUpToDateBanner(version) {
        banner.querySelector('#bannerIcon').textContent = '✓';
        banner.querySelector('#bannerLabel').textContent = appName;
        banner.querySelector('#bannerTitle').textContent = 'Up to date — v' + version;
        banner.querySelector('#bannerDesc').textContent = 'You are running the latest version.';
        banner.querySelector('#bannerArrow').textContent = '';
        banner.removeAttribute('onclick');
        banner.className = 'it-signin-banner is-shown is-ok';
    }

    // State: update required (prominent, actionable)
    function showUpdateBanner(latestVersion, apkUrl, releaseNotes) {
        _apkUrl = apkUrl || _apkUrl;
        banner.querySelector('#bannerIcon').textContent = '⬆';
        banner.querySelector('#bannerLabel').textContent = 'Update Required';
        banner.querySelector('#bannerTitle').textContent = 'Version ' + latestVersion + ' is available';
        banner.querySelector('#bannerDesc').textContent = releaseNotes || 'Tap to download and install the update now.';
        banner.querySelector('#bannerArrow').textContent = '›';
        banner.setAttribute('onclick', 'handleAppBannerClick()');
        banner.className = 'it-signin-banner is-shown is-update';
    }

    // State: download prompt (Android browser, not yet installed)
    function showDownloadBanner() {
        banner.querySelector('#bannerIcon').textContent = '⬇';
        banner.querySelector('#bannerLabel').textContent = 'Android App';
        banner.querySelector('#bannerTitle').textContent = 'Install ' + appName;
        banner.querySelector('#bannerDesc').textContent = 'Get the native app for GPS tracking and time clock.';
        banner.querySelector('#bannerArrow').textContent = '›';
        banner.setAttribute('onclick', 'handleAppBannerClick()');
        banner.className = 'it-signin-banner is-shown is-download';
    }

    function checkVersion(installedVersion) {
        fetch('/crm/api/app-version.php', { credentials: 'same-origin' })
            .then(function(r) { return r.ok ? r.json() : null; })
            .then(function(data) {
                var android = data && (data.android || data);
                if (!android || !android.version) return;

                if (installedVersion === null) {
                    // Old APK / Android browser — no version interface, offer download
                    showDownloadBanner();
                    return;
                }

                // Compare against min_version (mandatory floor), not latest version.
                // Optional bumps (icon updates, minor fixes) should not keep triggering
                // the banner — the force-update overlay in capacitor-bridge.js handles
                // hard blocks once the crew is inside the app.
                var minVersion = android.min_version || android.version;
                if (semverCmp(installedVersion, minVersion) < 0) {
                    showUpdateBanner(android.version, android.apk_url, android.release_notes);
                }
                // Meets minimum — banner stays hidden
            })
            .catch(function() {
                if (!isCapacitor && isAndroid) showDownloadBanner();
            });
    }

    if (isCapacitor) {
        // MwNativeAndroid is a JavascriptInterface injected by MainActivity into ALL
        // pages in the WebView — it's available here on the login page directly,
        // without needing capacitor-bridge.js (which only loads inside /crm/ pages).
        var installedVersion = (window.MwNativeAndroid && typeof window.MwNativeAndroid.getVersion === 'function')
            ? window.MwNativeAndroid.getVersion()
            : null;

        if (installedVersion === null) {
            // Old APK build without the version interface — demand update immediately.
            // checkVersion() will refine this once the fetch completes.
            showUpdateBanner('latest', _apkUrl, 'Please update to the latest version for best performance.');
        }

        checkVersion(installedVersion);
    } else if (isAndroid) {
        checkVersion(null);
    }
})();

// ── Password show/hide toggle ───────────────────────────────────────────────
(function () {
    'use strict';
    var pwField  = document.getElementById('password');
    var toggleBtn = document.getElementById('toggle-password');

    if (toggleBtn && pwField) {
        toggleBtn.addEventListener('click', function () {
            var showing = pwField.type === 'text';
            pwField.type = showing ? 'password' : 'text';
            toggleBtn.textContent = showing ? 'Show' : 'Hide';
            toggleBtn.setAttribute('aria-pressed', showing ? 'false' : 'true');
            pwField.focus();
        });
    }

    // ── Guard against phantom short-password submits ────────────────────────
    // Android autofill can trigger form submission before the password field
    // is fully populated, sending pwLen=1 attempts. Block any submit where
    // the password is fewer than 4 characters, and show a visible error so the
    // user knows what happened.
    var form = document.querySelector('form');
    var shortPwError = null;
    if (form && pwField) {
        form.addEventListener('submit', function (e) {
            if (pwField.value.length < 4) {
                e.preventDefault();
                e.stopImmediatePropagation();
                // Show visible feedback instead of silently blocking.
                if (!shortPwError) {
                    shortPwError = document.createElement('div');
                    shortPwError.className = 'it-signin-msg it-signin-msg--error';
                    shortPwError.setAttribute('role', 'alert');
                    form.insertBefore(shortPwError, form.querySelector('.it-signin-field'));
                }
                shortPwError.textContent = 'Please enter your password.';
                pwField.value = '';
                pwField.focus();
            }
        }, true); // capture phase so it fires before anything else
    }
})();
</script>
</body>
</html>
