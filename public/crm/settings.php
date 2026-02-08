<?php
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();

$pageTitle = 'Settings';
$activePage = 'settings';
?>
<?php include 'includes/appstack_head.php'; ?>

          <h1 class="h3 mb-3">Settings</h1>
          <div class="card">
            <div class="card-body text-center py-5">
              <i data-feather="settings" style="width: 64px; height: 64px;" class="text-muted mb-3"></i>
              <h3>Settings</h3>
              <p class="text-muted">Account and system settings coming soon.</p>
              <a href="dashboard_appstack.php" class="btn btn-primary mt-3">Back to Dashboard</a>
            </div>
          </div>

<?php include 'includes/appstack_footer.php'; ?>
