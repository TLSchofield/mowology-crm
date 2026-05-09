<?php
/**
 * Global Mobile Navigation Bars
 * ──────────────────────────────
 * Outputs: top bar, bottom bar, and slide-up menu overlay.
 * Visible only on mobile (≤ 991px) via mobile-nav.css.
 * Included by appstack_head.php after the AppStack topbar.
 *
 * Expects: $activePage, $user (same vars as sidebar).
 */
if (!isset($activePage)) $activePage = '';
if (!isset($user))       $user = ['name' => 'Admin'];

// Derive a short page title from $activePage or $pageTitle
$mobilePageTitles = [
    'dashboard'     => 'Dashboard',
    'clients'       => 'Clients',
    'companies'     => 'Companies',
    'quotes'        => 'Quotes',
    'jobs'          => 'Jobs',
    'invoices'      => 'Invoices',
    'expenses'      => 'Expenses',
    'profitability' => 'Profitability',
    'leaderboard'   => 'Leaderboard',
    'schedule'      => 'Schedule',
    'timeclock'     => 'My Schedule',
    'team'          => 'Team',
    'map'           => 'Territory Map',
    'products'      => 'Products',
    'portfolio'     => 'Portfolio',
    'cms'           => 'CMS',
    'media'         => 'Media',
    'marketing'     => 'Marketing',
    'weather-ops'   => 'Weather Ops',
    'settings'      => 'Settings',
    'users'         => 'Users',
    'database'      => 'Database',
    'driver'        => 'Driver Portal',
    'trip-reports'  => 'Trip Reports',
];
$mobileTitle = $mobilePageTitles[$activePage] ?? (isset($pageTitle) ? $pageTitle : 'Mowology');

// User initials for avatar
$userName   = $user['name'] ?? 'Admin';
$userParts  = explode(' ', trim($userName));
$initials   = strtoupper(substr($userParts[0], 0, 1) . (isset($userParts[1]) ? substr($userParts[1], 0, 1) : ''));

// Detect crew/driver role — they get a driver-centric bottom bar
$_mobileUserRole = $user['role'] ?? 'user';
$_isCrew = ($_mobileUserRole === 'user');

// Bottom nav items — keep tight: just the 4 most used
// Crew members see Driver Portal instead of Clients
$bottomNav = [
    ['key' => 'schedule',  'label' => 'Schedule', 'href' => '/crm/jobs/schedule.php',
     'icon' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>'],
    $_isCrew
        ? ['key' => 'driver',    'label' => 'Portal',   'href' => '/crm/driver-portal.php',
           'icon' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>']
        : ['key' => 'clients',   'label' => 'Clients',  'href' => '/crm/clients_appstack.php',
           'icon' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'],
    ['key' => 'expenses',  'label' => 'Receipt',  'href' => '/crm/expenses_appstack.php?mode=quick&return=' . urlencode($_SERVER['REQUEST_URI'] ?? '/crm/dashboard_appstack.php'),
     'icon' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>'],
    ['key' => '__menu__',  'label' => 'Menu',     'href' => '#',
     'icon' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>'],
];

// Menu grid items (all nav sections shown in the slide-up)
$menuItems = [
    ['key' => 'dashboard',     'label' => 'Dashboard',  'href' => '/crm/dashboard_appstack.php',
     'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>'],
    ['key' => 'schedule',      'label' => 'Schedule',   'href' => '/crm/jobs/schedule.php',
     'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>'],
    ['key' => 'clients',       'label' => 'Clients',    'href' => '/crm/clients_appstack.php',
     'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'],
    ['key' => 'quotes',        'label' => 'Quotes',     'href' => '/crm/quotes_appstack.php',
     'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>'],
    ['key' => 'jobs',          'label' => 'Jobs',       'href' => '/crm/jobs/index.php',
     'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>'],
    ['key' => 'invoices',      'label' => 'Invoices',   'href' => '/crm/invoices/index.php',
     'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>'],
    ['key' => 'expenses',      'label' => 'Expenses',   'href' => '/crm/expenses_appstack.php',
     'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>'],
    ['key' => 'team',          'label' => 'Team',       'href' => '/crm/team/index.php',
     'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'],
    ['key' => 'map',           'label' => 'Map',        'href' => '/crm/map_appstack.php',
     'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>'],
    ['key' => 'driver',        'label' => 'Driver',     'href' => '/crm/driver-portal.php',
     'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>'],
    ['key' => 'timeclock',    'label' => 'Clock',      'href' => '/crm/timeclock/my-timesheet.php',
     'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'],
];
?>

<!-- ── Mobile Top Bar ── -->
<div class="mw-mobile-topbar">
    <div class="mw-mobile-topbar-left">
        <img src="/assets/favicon/apple-touch-icon.png" alt="Mowology" class="mw-mobile-topbar-logo-img">
        <span class="mw-mobile-topbar-title"><?php echo htmlspecialchars($mobileTitle); ?></span>
    </div>
    <div class="mw-mobile-topbar-right">
        <!-- Reuse existing clock widget from appstack_topbar — JS targets #clockWidget -->
        <div class="mw-clock-widget" id="mwMobileClockWidget"></div>
        <button type="button" class="mw-mobile-search-btn" data-spotlight-open aria-label="Search">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        </button>
    </div>
</div>

<!-- ── Mobile Bottom Bar ── -->
<div class="mw-mobile-bottombar">
    <?php foreach ($bottomNav as $btn):
        $isActive = ($btn['key'] === $activePage || ($btn['key'] === 'expenses' && $activePage === 'expenses'));
        $isMenu   = $btn['key'] === '__menu__';
        $cls      = 'mw-mobile-nav-btn' . ($isActive ? ' mw-mobile-nav-active' : '');
        if ($isMenu): ?>
        <button type="button" class="<?php echo $cls; ?>" id="mwMobileMenuBtnBottom" aria-label="Menu">
            <?php echo $btn['icon']; ?>
            <span><?php echo $btn['label']; ?></span>
        </button>
        <?php else: ?>
        <a href="<?php echo $btn['href']; ?>" class="<?php echo $cls; ?>">
            <?php echo $btn['icon']; ?>
            <span><?php echo $btn['label']; ?></span>
        </a>
        <?php endif;
    endforeach; ?>
</div>

<!-- ── Slide-up Menu Overlay ── -->
<div class="mw-mobile-menu-overlay" id="mwMobileMenuOverlay">
    <div class="mw-mobile-menu-scrim" id="mwMobileMenuScrim"></div>
    <div class="mw-mobile-menu-sheet" id="mwMobileMenuSheet">
        <div class="mw-mobile-menu-handle"></div>

        <!-- User info -->
        <div class="mw-mobile-menu-user">
            <div class="mw-mobile-menu-avatar"><?php echo htmlspecialchars($initials); ?></div>
            <div>
                <div class="mw-mobile-menu-username"><?php echo htmlspecialchars($userName); ?></div>
                <div class="mw-mobile-menu-role"><?php echo htmlspecialchars(ucfirst($user['role'] ?? 'crew')); ?></div>
            </div>
        </div>

        <!-- Clock In / Out -->
        <div class="mw-mobile-clock-banner" id="mwMobileClockBanner">
            <div class="mw-mobile-clock-status">
                <span class="mw-mobile-clock-dot" id="mwMobileClockDot"></span>
                <span class="mw-mobile-clock-label" id="mwMobileClockLabelText">Loading…</span>
                <span class="mw-mobile-clock-timer" id="mwMobileClockTimer"></span>
            </div>
            <button type="button" class="mw-mobile-clock-btn" id="mwMobileClockBtn" disabled>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>
                <span id="mwMobileClockBtnText">Clock In</span>
            </button>
        </div>

        <!-- Nav grid -->
        <div class="mw-mobile-menu-grid">
            <?php foreach ($menuItems as $item):
                $isActive = ($activePage === $item['key']);
                $cls = 'mw-mobile-menu-item' . ($isActive ? ' mw-menu-item-active' : '');
            ?>
            <a href="<?php echo $item['href']; ?>" class="<?php echo $cls; ?>">
                <div class="mw-mobile-menu-item-icon">
                    <?php echo $item['icon']; ?>
                </div>
                <?php echo htmlspecialchars($item['label']); ?>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Account actions -->
        <div class="mw-mobile-menu-actions">
            <a href="/crm/profile.php" class="mw-mobile-menu-action">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Profile
            </a>
            <a href="/crm/settings.php" class="mw-mobile-menu-action">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                Settings
            </a>
            <a href="/crm/logout_secure.php" class="mw-mobile-menu-action mw-menu-action-danger">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Log out
            </a>
        </div>
    </div>
</div>

<!-- ── Menu JS (inline, no extra file needed) ── -->
<script>
(function() {
    var overlay  = document.getElementById('mwMobileMenuOverlay');
    var scrim    = document.getElementById('mwMobileMenuScrim');
    var botBtn   = document.getElementById('mwMobileMenuBtnBottom');

    if (!overlay) return;

    function openMenu() {
        overlay.classList.add('mw-menu-open');
        document.body.style.overflow = 'hidden';
    }

    function closeMenu() {
        overlay.classList.remove('mw-menu-open');
        document.body.style.overflow = '';
    }

    if (botBtn) botBtn.addEventListener('click', openMenu);
    if (scrim)  scrim.addEventListener('click', closeMenu);

    // Close on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeMenu();
    });

    // Sync clock widget — move the existing clockWidget into the mobile topbar slot
    // (the appstack topbar is hidden on mobile, so the JS-enhanced widget needs
    //  to be in the visible topbar instead)
    document.addEventListener('DOMContentLoaded', function() {
        var src  = document.getElementById('clockWidget');
        var dest = document.getElementById('mwMobileClockWidget');
        if (src && dest && window.innerWidth <= 991) {
            dest.appendChild(src);
        }
    });

    // ── Mobile clock banner sync ────────────────────────────────────
    // The clock widget JS replaces #btnClockIn with #btnClockOut once
    // clocked in, and uses #clockTimer for the running elapsed time.
    // We read state from MwTimeClock.isActive() + DOM, and delegate
    // clicks to whichever real button is active.
    (function() {
        var mobileBtn   = document.getElementById('mwMobileClockBtn');
        var mobileBtnTxt= document.getElementById('mwMobileClockBtnText');
        var mobileDot   = document.getElementById('mwMobileClockDot');
        var mobileLbl   = document.getElementById('mwMobileClockLabelText');
        var mobileTimer = document.getElementById('mwMobileClockTimer');

        if (!mobileBtn) return;

        // Delegate click to whichever real clock button exists right now
        mobileBtn.addEventListener('click', function() {
            var realBtn = document.getElementById('btnClockOut') || document.getElementById('btnClockIn');
            if (realBtn && !realBtn.disabled) {
                realBtn.click();
                // Re-sync after widget updates DOM (~800ms)
                setTimeout(syncClockState, 800);
                setTimeout(syncClockState, 1600);
            }
        });

        function syncClockState() {
            // Primary: use MwTimeClock API if available
            var isActive = (typeof window.MwTimeClock !== 'undefined')
                ? window.MwTimeClock.isActive()
                : null;

            // Fallback: infer from which button exists in DOM
            var btnOut = document.getElementById('btnClockOut');
            var btnIn  = document.getElementById('btnClockIn');

            if (isActive === null) {
                // Widget not loaded yet — check DOM
                if (!btnOut && !btnIn) {
                    // Still loading
                    mobileLbl.textContent    = 'Loading…';
                    mobileBtnTxt.textContent = 'Clock In';
                    mobileTimer.textContent  = '';
                    mobileBtn.disabled = true;
                    mobileBtn.className = 'mw-mobile-clock-btn';
                    mobileDot.className = 'mw-mobile-clock-dot';
                    return;
                }
                isActive = !!btnOut;
            }

            mobileBtn.disabled = false;

            if (isActive) {
                // Clocked IN
                mobileDot.className      = 'mw-mobile-clock-dot mw-clock-dot-active';
                mobileLbl.textContent    = 'Clocked in';
                mobileBtnTxt.textContent = 'Clock Out';
                mobileBtn.className      = 'mw-mobile-clock-btn mw-mobile-clock-btn-out';
                // Pull running timer from the widget's #clockTimer span
                var timerEl = document.getElementById('clockTimer');
                mobileTimer.textContent = timerEl ? timerEl.textContent.trim() : '';
            } else {
                // Clocked OUT
                mobileDot.className      = 'mw-mobile-clock-dot';
                mobileLbl.textContent    = 'Not clocked in';
                mobileBtnTxt.textContent = 'Clock In';
                mobileBtn.className      = 'mw-mobile-clock-btn mw-mobile-clock-btn-in';
                mobileTimer.textContent  = '';
            }
        }

        // Initial sync — run once now, then again after widget JS fires
        syncClockState();
        setTimeout(syncClockState, 800);
        setTimeout(syncClockState, 2000);

        // Keep timer display live while menu is open
        setInterval(syncClockState, 5000);

        // Re-sync immediately whenever menu opens
        var menuBotBtn2 = document.getElementById('mwMobileMenuBtnBottom');
        if (menuBotBtn2) menuBotBtn2.addEventListener('click', function() {
            syncClockState();
            setTimeout(syncClockState, 300);
        });
    })();
})();
</script>
