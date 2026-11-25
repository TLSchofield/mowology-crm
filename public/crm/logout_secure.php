<?php
require_once __DIR__ . '/../loginAuth/auth.php';

// Crew treat "Log out" as "I'm done for the day" — but signing out never clocked anyone
// out. Every September 2026 shift ran to the 12 h auto clock-out, and with the native
// tracking engine (APK 1.3.0) the phone would keep recording them at home until it fired.
// So: if you are still clocked in, you are asked first. ?stay=1 skips the question.
$clockedIn = false;
if (isLoggedIn() && empty($_GET['stay'])) {
    try {
        require_once __DIR__ . '/includes/functions.php';
        require_once __DIR__ . '/includes/timeclock-functions.php';
        $user = getCurrentUser();
        $clockedIn = (bool)getActiveClockEntry((int)$user['id']);
    } catch (Throwable $e) {
        // Never trap someone in the app because the check failed.
        error_log('[logout] clock check failed: ' . $e->getMessage());
        $clockedIn = false;
    }
}

if (!$clockedIn) {
    logoutUser();
    header('Location: login_secure.php');
    exit();
}

$pageTitle  = 'Sign out';
$activePage = '';
?>
<?php include __DIR__ . '/includes/appstack_head.php'; ?>

          <div class="row justify-content-center">
            <div class="col-12 col-md-8 col-lg-5">
              <div class="card">
                <div class="card-body text-center">
                  <h1 class="h3 mb-2">You're still clocked in</h1>
                  <p class="text-muted mb-4">Signing out doesn't end your shift. Your hours keep running, and location tracking stays on, until you clock out.</p>
                  <div id="mwLogoutError" class="alert alert-danger d-none" role="alert"></div>
                  <button type="button" id="mwLogoutClockOut" class="btn btn-primary btn-lg btn-block mb-2">Clock out and sign out</button>
                  <a href="/crm/logout_secure.php?stay=1" class="btn btn-outline-secondary btn-block mb-2">Sign out only — I'm still working</a>
                  <a href="/crm/homebase.php" class="btn btn-link btn-block">Cancel</a>
                </div>
              </div>
            </div>
          </div>

          <script src="/crm/js/mw-logout-prompt.js?v=20260919a"></script>

<?php include __DIR__ . '/includes/appstack_footer.php'; ?>
