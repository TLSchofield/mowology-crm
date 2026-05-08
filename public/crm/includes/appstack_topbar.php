<?php
/**
 * Shared AppStack Top Navigation Bar.
 *
 * Layout (left → right):
 *   sidebar toggle | tracking dot | clock widget | snap receipt  [auto-spacer]  search ⌘K | user icon ▾
 *
 * Right cluster lives OUTSIDE navbar-collapse so Bootstrap dropdown/Popper.js
 * can position menus correctly without flex-container interference.
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

    <!-- Location Tracking Status Indicator -->
    <div class="mw-tracking-dot-wrapper" id="trackingDotWrapper" title="Loading...">
        <span class="mw-tracking-dot" id="trackingDot">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="2"></circle><path d="M16.24 7.76a6 6 0 0 1 0 8.49"></path><path d="M7.76 16.24a6 6 0 0 1 0-8.49"></path><path d="M19.07 4.93a10 10 0 0 1 0 14.14"></path><path d="M4.93 19.07a10 10 0 0 1 0-14.14"></path></svg>
        </span>
    </div>

    <!-- Time Clock Widget -->
    <div class="mw-clock-widget" id="clockWidget">
        <button class="mw-clock-btn mw-clock-in" id="btnClockIn" title="Clock In" style="opacity:0.6" disabled>
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polygon points="10 8 16 12 10 16 10 8"></polygon></svg>
            <span class="mw-clock-label">Clock In</span>
        </button>
    </div>

    <!-- Snap Receipt Quick-Access Button -->
    <?php if (isset($user) && function_exists('userHasPermission') && userHasPermission('expenses.edit')): ?>
    <button type="button" class="mw-snap-receipt-btn" title="Snap Receipt"
        onclick="if(typeof window.triggerCamera==='function'){triggerCamera();}else{window.location='/crm/expenses_appstack.php?mode=quick&trigger=camera&return='+encodeURIComponent(window.location.pathname+window.location.search);}">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
        <span class="mw-snap-receipt-label">Receipt</span>
    </button>
    <?php endif; ?>

    <!-- Right cluster — margin-left:auto pushes to far right.
         Kept OUTSIDE .navbar-collapse so Bootstrap/Popper dropdown positioning works correctly. -->
    <div class="mw-topbar-right-cluster">

        <!-- Global Search Trigger -->
        <button class="mw-spotlight-trigger" data-spotlight-open title="Search (<?php echo PHP_OS === 'Darwin' || stripos($_SERVER['HTTP_USER_AGENT'] ?? '', 'mac') !== false ? '⌘' : 'Ctrl'; ?>+K)">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <span class="mw-spotlight-trigger-text">Search...</span>
            <kbd class="mw-spotlight-trigger-kbd"><?php echo PHP_OS === 'Darwin' || stripos($_SERVER['HTTP_USER_AGENT'] ?? '', 'mac') !== false ? '⌘K' : 'Ctrl+K'; ?></kbd>
        </button>

        <!-- Debug Panel Toggle -->
        <?php
        $mw_debug_on     = isset($_COOKIE['debug_panel']) && $_COOKIE['debug_panel'] === 'enabled';
        $mw_debug_errors = class_exists('CRMErrorHandler') && CRMErrorHandler::hasRequestErrors();
        ?>
        <button
          class="mw-debug-toggle<?php echo $mw_debug_on ? ' mw-debug-toggle--on' : ''; ?>"
          id="mwDebugToggleBtn"
          title="<?php echo $mw_debug_on ? 'Debug panel ON — click to disable' : 'Debug panel OFF — click to enable'; ?>"
          onclick="(function(){
            var on = '<?php echo $mw_debug_on ? '1' : '0'; ?>';
            if (on === '1') {
              document.cookie = 'debug_panel=; Max-Age=0; path=/; SameSite=Lax';
            } else {
              document.cookie = 'debug_panel=enabled; Max-Age=604800; path=/; SameSite=Lax';
            }
            location.reload();
          })()">
          <!-- Bug / ladybug icon -->
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 2a4 4 0 0 1 4 4"></path><path d="M8 6a4 4 0 0 1 4-4"></path>
            <path d="M12 18c-4 0-7-2.686-7-6v-2a7 7 0 0 1 14 0v2c0 3.314-3 6-7 6z"></path>
            <line x1="12" y1="18" x2="12" y2="22"></line>
            <line x1="8" y1="20" x2="16" y2="20"></line>
            <line x1="5" y1="10" x2="2" y2="9"></line>
            <line x1="19" y1="10" x2="22" y2="9"></line>
            <line x1="5" y1="14" x2="2" y2="15"></line>
            <line x1="19" y1="14" x2="22" y2="15"></line>
          </svg>
          <?php if ($mw_debug_on && $mw_debug_errors): ?>
          <span class="mw-debug-badge">!</span>
          <?php endif; ?>
        </button>

        <!-- User / Settings icon dropdown -->
        <div class="dropdown">
            <button class="mw-topbar-settings-btn dropdown-toggle" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="<?php echo htmlspecialchars($user['name'] ?? 'Admin'); ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"></circle><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"></path></svg>
            </button>
            <div class="dropdown-menu dropdown-menu-right">
                <div class="px-3 py-2 border-bottom mb-1">
                    <div style="font-weight:600;font-size:0.85rem;color:var(--mw-ink-900);"><?php echo htmlspecialchars($user['name'] ?? 'Admin'); ?></div>
                    <?php if (!empty($user['email'])): ?>
                    <div style="font-size:0.75rem;color:var(--mw-ink-500);"><?php echo htmlspecialchars($user['email']); ?></div>
                    <?php endif; ?>
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
        </div>

    </div><!-- /.mw-topbar-right-cluster -->
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
