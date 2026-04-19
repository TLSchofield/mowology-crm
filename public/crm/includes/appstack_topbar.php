<?php
/**
 * Shared AppStack Top Navigation Bar.
 *
 * Layout (left → right):
 *   sidebar toggle | tracking dot | clock widget | snap receipt   [spacer]   search ⌘K | settings icon ▾
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
        <span class="mw-tracking-dot" id="trackingDot">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="2"></circle><path d="M16.24 7.76a6 6 0 0 1 0 8.49"></path><path d="M7.76 16.24a6 6 0 0 1 0-8.49"></path><path d="M19.07 4.93a10 10 0 0 1 0 14.14"></path><path d="M4.93 19.07a10 10 0 0 1 0-14.14"></path></svg>
        </span>
    </div>

    <!-- Time Clock Widget (JS enhances this default button) -->
    <div class="mw-clock-widget" id="clockWidget">
        <button class="mw-clock-btn mw-clock-in" id="btnClockIn" title="Clock In" style="opacity:0.6" disabled>
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polygon points="10 8 16 12 10 16 10 8"></polygon></svg>
            <span class="mw-clock-label">Clock In</span>
        </button>
    </div>

    <!-- Snap Receipt Quick-Access Button -->
    <?php if (isset($user) && function_exists('userHasPermission') && userHasPermission('expenses.edit')): ?>
    <a href="/crm/expenses_appstack.php?mode=quick&amp;return=<?php echo urlencode($_SERVER['REQUEST_URI'] ?? '/crm/dashboard_appstack.php'); ?>"
       class="mw-snap-receipt-btn"
       title="Snap Receipt">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
        <span class="mw-snap-receipt-label">Receipt</span>
    </a>
    <?php endif; ?>

    <!-- Right cluster: search + settings icon — pushed to far right via CSS -->
    <div class="navbar-collapse collapse">
        <ul class="navbar-nav mw-topbar-right">

            <!-- Global Search Trigger -->
            <li class="nav-item mw-search-nav-item">
                <button class="mw-spotlight-trigger" data-spotlight-open title="Search (<?php echo PHP_OS === 'Darwin' || stripos($_SERVER['HTTP_USER_AGENT'] ?? '', 'mac') !== false ? '⌘' : 'Ctrl'; ?>+K)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <span class="mw-spotlight-trigger-text">Search...</span>
                    <kbd class="mw-spotlight-trigger-kbd"><?php echo PHP_OS === 'Darwin' || stripos($_SERVER['HTTP_USER_AGENT'] ?? '', 'mac') !== false ? '⌘K' : 'Ctrl+K'; ?></kbd>
                </button>
            </li>

            <!-- Settings / Profile icon dropdown -->
            <li class="nav-item dropdown">
                <a class="mw-topbar-settings-btn dropdown-toggle" href="#" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="<?php echo htmlspecialchars($user['name'] ?? 'Admin'); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"></circle><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"></path></svg>
                </a>
                <div class="dropdown-menu dropdown-menu-right">
                    <div class="dropdown-header px-3 py-2 border-bottom mb-1">
                        <div style="font-weight:600;font-size:0.85rem;color:var(--mw-ink-900);"><?php echo htmlspecialchars($user['name'] ?? 'Admin'); ?></div>
                        <div style="font-size:0.75rem;color:var(--mw-ink-500);"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
                    </div>
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

<!-- Spotlight Search Overlay -->
<div class="mw-spotlight" id="mwSpotlight">
    <div class="mw-spotlight-dialog">
        <div class="mw-spotlight-header">
            <svg class="mw-spotlight-search-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input type="text" id="mwSpotlightInput" class="mw-spotlight-input" placeholder="Search contacts, quotes, jobs..." autocomplete="off" spellcheck="false">
            <kbd class="mw-kbd mw-spotlight-esc">Esc</kbd>
        </div>
        <div class="mw-spotlight-body" id="mwSpotlightBody"></div>
    </div>
</div>
