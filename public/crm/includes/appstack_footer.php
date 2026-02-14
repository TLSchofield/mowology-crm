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
  <script src="/crm/js/time-clock-widget.js?v=20260214e"></script>

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

  <!-- Service Worker Registration (PWA) -->
  <script>
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
      navigator.serviceWorker.register('/service-worker.js', { scope: '/' })
        .then(function(reg) {
          setInterval(function() { reg.update(); }, 1800000);
        })
        .catch(function() { /* SW registration failed — non-critical */ });
    });
  }
  </script>

  <!-- PWA Install Prompt -->
  <script>
  (function(){
    // Already running as installed app — do nothing
    if (window.matchMedia('(display-mode: standalone)').matches) return;
    if (window.navigator.standalone === true) return;

    // Don't show if user dismissed recently (24h cooldown)
    var dismissed = localStorage.getItem('mw-pwa-dismissed');
    if (dismissed && Date.now() - parseInt(dismissed, 10) < 86400000) return;

    var isIOS = /iPhone|iPad|iPod/.test(navigator.userAgent) && !window.MSStream;
    var isAndroid = /Android/.test(navigator.userAgent);
    var deferredPrompt = null;

    // Android: capture the native install prompt
    if (isAndroid) {
      window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        deferredPrompt = e;
        showBanner('android');
      });
    }

    // iOS: show custom instruction banner after short delay
    if (isIOS) {
      setTimeout(function() { showBanner('ios'); }, 2000);
    }

    function showBanner(platform) {
      var banner = document.createElement('div');
      banner.id = 'mw-pwa-banner';
      banner.style.cssText = 'position:fixed;bottom:0;left:0;right:0;z-index:99999;' +
        'background:#fff;border-top:3px solid #2D8659;padding:16px 20px;' +
        'box-shadow:0 -4px 20px rgba(0,0,0,.15);display:flex;align-items:center;gap:14px;' +
        'font-family:-apple-system,sans-serif;animation:mw-slide-up .3s ease;';

      var icon = document.createElement('img');
      icon.src = '/assets/favicon/android-chrome-192x192.png';
      icon.style.cssText = 'width:48px;height:48px;border-radius:10px;flex-shrink:0;';

      var text = document.createElement('div');
      text.style.cssText = 'flex:1;min-width:0;';

      var title = document.createElement('div');
      title.style.cssText = 'font-weight:600;font-size:15px;color:#1A5F4A;margin-bottom:2px;';
      title.textContent = 'Install Mowology';

      var desc = document.createElement('div');
      desc.style.cssText = 'font-size:13px;color:#666;line-height:1.3;';

      if (platform === 'ios') {
        desc.innerHTML = 'Tap <svg style="display:inline;vertical-align:middle;width:18px;height:18px;" viewBox="0 0 24 24" fill="none" stroke="#007AFF" stroke-width="2"><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M12 18v-9m0 0l-3 3m3-3l3 3"/></svg> then <b>"Add to Home Screen"</b>';
      } else {
        desc.textContent = 'Add Mowology to your home screen for quick access';
      }

      text.appendChild(title);
      text.appendChild(desc);

      var actions = document.createElement('div');
      actions.style.cssText = 'display:flex;flex-direction:column;gap:6px;flex-shrink:0;';

      if (platform === 'android' && deferredPrompt) {
        var installBtn = document.createElement('button');
        installBtn.textContent = 'Install';
        installBtn.style.cssText = 'background:#2D8659;color:#fff;border:none;padding:8px 18px;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;';
        installBtn.addEventListener('click', function() {
          deferredPrompt.prompt();
          deferredPrompt.userChoice.then(function() { banner.remove(); });
        });
        actions.appendChild(installBtn);
      }

      var closeBtn = document.createElement('button');
      closeBtn.textContent = platform === 'ios' ? 'Got it' : 'Not now';
      closeBtn.style.cssText = 'background:none;border:1px solid #ddd;padding:6px 14px;border-radius:6px;font-size:13px;color:#666;cursor:pointer;';
      closeBtn.addEventListener('click', function() {
        banner.remove();
        localStorage.setItem('mw-pwa-dismissed', Date.now().toString());
      });
      actions.appendChild(closeBtn);

      banner.appendChild(icon);
      banner.appendChild(text);
      banner.appendChild(actions);

      // Slide-up animation
      var style = document.createElement('style');
      style.textContent = '@keyframes mw-slide-up{from{transform:translateY(100%)}to{transform:translateY(0)}}';
      document.head.appendChild(style);

      document.body.appendChild(banner);
    }
  })();
  </script>

  <!-- Debug Panel (development tool) -->
  <?php include __DIR__ . '/debug-panel.php'; ?>
</body>
</html>
