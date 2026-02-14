<?php
/**
 * Shared AppStack Top Navigation Bar.
 *
 * Expected variable:
 *   $user — array with at least 'name' key
 */
if (!isset($user)) $user = ['name' => 'Admin'];
?>
<nav class="navbar navbar-expand navbar-light navbar-bg">
    <a class="sidebar-toggle d-flex">
        <i class="hamburger align-self-center"></i>
    </a>

    <!-- Location Tracking Status Indicator (always visible) -->
    <div class="mw-tracking-dot-wrapper" id="trackingDotWrapper" title="Loading...">
        <span class="mw-tracking-dot" id="trackingDot"></span>
    </div>

    <!-- Time Clock Widget (JS enhances this default button) -->
    <div class="mw-clock-widget" id="clockWidget">
        <button class="mw-clock-btn mw-clock-in" id="btnClockIn" title="Clock In" style="opacity:0.6" disabled>
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polygon points="10 8 16 12 10 16 10 8"></polygon></svg>
            <span class="mw-clock-label">Clock In</span>
        </button>
    </div>

    <div class="navbar-collapse collapse">
        <ul class="navbar-nav navbar-align">
            <li class="nav-item dropdown">
                <a class="nav-icon dropdown-toggle d-inline-block d-sm-none" href="#" data-toggle="dropdown">
                    <i class="align-middle" data-feather="settings"></i>
                </a>

                <a class="nav-link dropdown-toggle d-none d-sm-inline-block" href="#" data-toggle="dropdown">
                    <span class="text-dark"><?php echo htmlspecialchars($user['name'] ?? 'Admin'); ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-right">
                    <a class="dropdown-item" href="/crm/profile.php">
                        <i class="align-middle mr-1" data-feather="user"></i> Profile
                    </a>
                    <a class="dropdown-item" href="/crm/settings.php">
                        <i class="align-middle mr-1" data-feather="settings"></i> Settings
                    </a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="/crm/logout_secure.php">
                        <i class="align-middle mr-1" data-feather="log-out"></i> Log out
                    </a>
                </div>
            </li>
        </ul>
    </div>
</nav>
