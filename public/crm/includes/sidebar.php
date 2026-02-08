<?php
if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
?>



<?php
/**
 * Shared Sidebar Component
 * Include this in all CRM pages for consistent navigation
 */

// Determine current page for active state
$currentScript = basename($_SERVER['PHP_SELF'], '.php');
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

// Get user info if not already available
if (!isset($user)) {
    $user = getCurrentUser();
}

// Helper function to check if nav item is active
function isNavActive($pages, $dirs = []) {
    global $currentScript, $currentDir;
    if (in_array($currentScript, $pages)) return true;
    if (in_array($currentDir, $dirs)) return true;
    return false;
}
?>
<aside class="sidebar">
    <div class="logo-section">
        <div class="logo">Mowology</div>
        <div class="tagline">CRM System</div>
    </div>

    <nav>
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="/crm/dashboard.php" class="nav-link <?php echo isNavActive(['dashboard']) ? 'active' : ''; ?>">
                    <span class="nav-icon">📊</span>
                    Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a href="/crm/clients_cdn.php" class="nav-link <?php echo isNavActive(['clients', 'clients_cdn', 'client_form', 'client_view']) ? 'active' : ''; ?>">
                    <span class="nav-icon">👥</span>
                    Clients
                </a>
            </li>
            <li class="nav-item">
                <a href="/crm/quotes/index.php" class="nav-link <?php echo isNavActive([], ['quotes']) ? 'active' : ''; ?>">
                    <span class="nav-icon">💰</span>
                    Quotes
                </a>
            </li>
            <li class="nav-item">
                <a href="/crm/jobs/index.php" class="nav-link <?php echo isNavActive([], ['jobs']) ? 'active' : ''; ?>">
                    <span class="nav-icon">🔧</span>
                    Jobs
                </a>
            </li>
            <li class="nav-item">
                <a href="/crm/invoices/index.php" class="nav-link <?php echo isNavActive([], ['invoices']) ? 'active' : ''; ?>">
                    <span class="nav-icon">📄</span>
                    Invoices
                </a>
            </li>
            <li class="nav-item">
                <a href="/crm/jobs/schedule.php" class="nav-link <?php echo isNavActive(['schedule']) ? 'active' : ''; ?>">
                    <span class="nav-icon">📅</span>
                    Schedule
                </a>
            </li>
            <li class="nav-item">
                <a href="/crm/map.php" class="nav-link <?php echo isNavActive(['map']) ? 'active' : ''; ?>">
                    <span class="nav-icon">🗺️</span>
                    Territory Map
                </a>
            </li>
        </ul>
    </nav>

    <div class="user-section">
        <div class="user-info">
            <div class="user-avatar"><?php echo strtoupper(substr($user['name'] ?: 'M', 0, 1)); ?></div>
            <div class="user-details">
                <div class="user-name"><?php echo htmlspecialchars($user['name'] ?: 'Admin'); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($user['role']); ?></div>
            </div>
        </div>
        <a href="/crm/logout_secure.php" class="logout-btn">Sign Out</a>
    </div>
</aside>
