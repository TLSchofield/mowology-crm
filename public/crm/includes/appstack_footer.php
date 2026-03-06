<?php
/**
 * Shared AppStack Footer + Scripts.
 * ──────────────────────────────────
 * Include this at the bottom of every AppStack-based CRM page.
 *
 * Closes all tags opened by appstack_head.php:
 *   </div>  container-fluid
 *   </main> content
 *   <footer>
 *   </div>  .main
 *   </div>  .wrapper
 *   <script> app.js
 *   </body></html>
 */
?>
        </div><!-- /.container-fluid -->
      </main>

      <footer class="footer">
        <div class="container-fluid">
          <div class="row text-muted">
            <div class="col-6 text-left">
              <p class="mb-0">
                <strong>Mowology CRM</strong> &copy; <?php echo date('Y'); ?>
              </p>
            </div>
            <div class="col-6 text-right">
              <ul class="list-inline">
                <li class="list-inline-item">Vancouver, BC</li>
              </ul>
            </div>
          </div>
        </div>
      </footer>
    </div><!-- /.main -->
  </div><!-- /.wrapper -->

  <script src="/crm/js/feather-helper.js"></script>
  <script src="/crm/js/app.js"></script>
  <script src="/crm/js/mw-layout-manager.js?v=20260306" defer></script>
  <script src="/crm/js/mw-toast.js?v=20260306"></script>
  <script src="/crm/js/time-clock-widget.js?v=20260214h"></script>
  <script src="/crm/js/capacitor-bridge.js?v=20260214"></script>

  <!-- Dropdown fix: app.js bundles jQuery+Bootstrap whose dropdown plugin fails without global Popper.
       Remove the broken jQuery handler and replace with a working vanilla JS one. -->
  <script>
  (function(){
    // Remove Bootstrap's broken dropdown click handlers from jQuery
    $(document).off('click.bs.dropdown');
    $(document).off('click.bs.dropdown.data-api');
    $('[data-toggle="dropdown"]').off('click.bs.dropdown');

    // Vanilla JS dropdown toggle
    document.addEventListener('click', function(e){
      var toggle = e.target.closest('[data-toggle="dropdown"]');
      if (toggle) {
        e.preventDefault();
        var parent = toggle.closest('.dropdown') || toggle.parentElement;
        var menu = parent.querySelector('.dropdown-menu');
        if (!menu) return;
        var isOpen = parent.classList.contains('show');
        // Close all open dropdowns
        document.querySelectorAll('.dropdown.show, .nav-item.show').forEach(function(el){
          el.classList.remove('show');
          var m = el.querySelector('.dropdown-menu');
          if (m) m.classList.remove('show');
        });
        if (!isOpen) {
          parent.classList.add('show');
          menu.classList.add('show');
        }
      } else {
        // Click outside — close all dropdowns
        document.querySelectorAll('.dropdown.show, .nav-item.show').forEach(function(el){
          el.classList.remove('show');
          var m = el.querySelector('.dropdown-menu');
          if (m) m.classList.remove('show');
        });
      }
    });
  })();
  </script>

  <!-- Service Worker Registration (PWA + Capacitor) -->
  <!-- Capacitor uses a live server URL so its WebView can use a SW just
       like a browser — this is the primary caching layer for the Android app. -->
  <script>
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
      navigator.serviceWorker.register('/service-worker.js', { scope: '/' })
        .then(function(reg) {
          // Check for updates every 30 minutes
          setInterval(function() { reg.update(); }, 1800000);
        })
        .catch(function() { /* SW registration failed — non-critical */ });
    });
  }
  </script>

  <!-- Hide sidebar Install App link when already in native app or installed PWA -->
  <script>
  (function(){
    var sidebarItem = document.getElementById('mw-pwa-sidebar-item');
    if (!sidebarItem) return;

    // Hide if inside Capacitor native app
    if (window.Capacitor && window.Capacitor.isNativePlatform()) {
      sidebarItem.style.display = 'none';
      return;
    }
    // Hide if already running as installed PWA
    if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true) {
      sidebarItem.style.display = 'none';
      return;
    }
    // Hide on desktop (only useful on mobile/tablet)
    var isMobile = /Android|iPhone|iPad|iPod|webOS|BlackBerry/i.test(navigator.userAgent);
    if (!isMobile) {
      sidebarItem.style.display = 'none';
      return;
    }
  })();
  </script>

  <!-- Mobile App Install Splash Screen -->
  <script>
  (function(){
    // Skip if inside Capacitor native app
    if (window.Capacitor && window.Capacitor.isNativePlatform()) return;

    // Skip if already running as installed PWA
    if (window.matchMedia('(display-mode: standalone)').matches) return;
    if (window.navigator.standalone === true) return;

    // Only show on mobile/tablet devices
    var isMobile = /Android|iPhone|iPad|iPod|webOS|BlackBerry/i.test(navigator.userAgent);
    if (!isMobile) return;

    // Don't show if user dismissed recently (7 day cooldown)
    var dismissed = localStorage.getItem('mw-install-splash-dismissed');
    if (dismissed && Date.now() - parseInt(dismissed, 10) < 604800000) return;

    var isAndroid = /Android/.test(navigator.userAgent);
    var isIOS = /iPhone|iPad|iPod/.test(navigator.userAgent) && !window.MSStream;

    // Build the splash overlay
    var overlay = document.createElement('div');
    overlay.id = 'mw-install-splash';
    overlay.className = 'mw-install-splash';

    var content = '<div class="mw-install-splash-inner">';
    content += '<img src="/assets/favicon/android-chrome-512x512.png" alt="Mowology" class="mw-install-splash-icon">';
    content += '<h1 class="mw-install-splash-title">Mowology Crew</h1>';
    content += '<p class="mw-install-splash-desc">Install the app for the best experience — GPS tracking, notifications, and quick access from your home screen.</p>';
    content += '<div class="mw-install-splash-actions">';

    if (isAndroid) {
      content += '<button type="button" class="mw-install-splash-btn mw-install-splash-btn-primary" id="mw-install-splash-pwa">Add to Home Screen</button>';
    }

    if (isIOS) {
      content += '<div class="mw-install-splash-ios-hint">';
      content += '<div class="mw-install-splash-ios-steps">';
      content += '<div class="mw-install-splash-ios-step">';
      content += '<span class="mw-install-splash-ios-step-num">1</span>';
      content += 'Tap <svg style="display:inline;vertical-align:middle;width:22px;height:22px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="M8 7l4-4 4 4"/><path d="M4 14v5a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5"/></svg> <strong>Share</strong>';
      content += '</div>';
      content += '<div class="mw-install-splash-ios-step">';
      content += '<span class="mw-install-splash-ios-step-num">2</span>';
      content += 'Scroll down, tap <strong>"Add to Home Screen"</strong>';
      content += '</div>';
      content += '<div class="mw-install-splash-ios-step">';
      content += '<span class="mw-install-splash-ios-step-num">3</span>';
      content += 'Tap <strong>"Add"</strong> to install';
      content += '</div>';
      content += '</div>';
      content += '</div>';
    }

    content += '<button type="button" class="mw-install-splash-btn mw-install-splash-btn-secondary" id="mw-install-splash-skip">Continue in Browser</button>';
    content += '</div></div>';

    overlay.innerHTML = content;
    document.body.appendChild(overlay);

    // Prevent scrolling behind the overlay
    document.body.style.overflow = 'hidden';

    // "Continue in Browser" dismisses with cooldown
    document.getElementById('mw-install-splash-skip').addEventListener('click', function() {
      overlay.remove();
      document.body.style.overflow = '';
      localStorage.setItem('mw-install-splash-dismissed', Date.now().toString());
    });

    // Android "Add to Home Screen" — trigger the native PWA install prompt
    var pwaBtn = document.getElementById('mw-install-splash-pwa');
    if (pwaBtn) {
      pwaBtn.addEventListener('click', function() {
        if (window._mwInstallPrompt) {
          window._mwInstallPrompt.prompt();
          window._mwInstallPrompt.userChoice.then(function(result) {
            if (result.outcome === 'accepted') {
              overlay.remove();
              document.body.style.overflow = '';
            }
            window._mwInstallPrompt = null;
          });
        } else {
          // No deferred prompt available — show Chrome instructions
          pwaBtn.textContent = 'Use Chrome menu \u2192 "Add to Home Screen"';
          pwaBtn.disabled = true;
          pwaBtn.style.opacity = '0.7';
        }
      });
    }
  })();
  </script>

  <!-- Capture Android PWA beforeinstallprompt for deferred use -->
  <script>
  window.addEventListener('beforeinstallprompt', function(e) {
    e.preventDefault();
    window._mwInstallPrompt = e;
    window.mwDeferredInstall = e; // Shared alias for sidebar + login banner
  });
  // Hide PWA install sidebar item if already installed
  if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone) {
    document.addEventListener('DOMContentLoaded', function() {
      var pwaItem = document.getElementById('mw-pwa-sidebar-item');
      if (pwaItem) pwaItem.style.display = 'none';
    });
  }
  </script>

  <!-- Clickable table rows: any <tr data-href="..."> navigates on click -->
  <script>
  (function(){
    document.querySelectorAll('tr[data-href]').forEach(function(row){
      row.addEventListener('click', function(e){
        if (e.target.closest('a,button,input,select,textarea,label')) return;
        window.location.href = row.dataset.href;
      });
    });
  })();
  </script>

  <!-- Debug Panel (development tool) -->
  <?php include __DIR__ . '/debug-panel.php'; ?>
</body>
</html>
