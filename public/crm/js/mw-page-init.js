/**
 * mw-page-init.js — page-level initialisation that previously lived as inline
 * <script> blocks in appstack_footer.php. Loaded with defer after app.js so
 * jQuery and feather are guaranteed to be available.
 */

// ── 1. Dropdown fix ────────────────────────────────────────────────────────
// app.js bundles jQuery+Bootstrap whose dropdown plugin fails without global
// Popper. Remove the broken jQuery handler and replace with vanilla JS.
(function(){
  $(document).off('click.bs.dropdown');
  $(document).off('click.bs.dropdown.data-api');
  $('[data-toggle="dropdown"]').off('click.bs.dropdown');

  document.addEventListener('click', function(e){
    var toggle = e.target.closest('[data-toggle="dropdown"]');
    if (toggle) {
      e.preventDefault();
      var parent = toggle.closest('.dropdown') || toggle.parentElement;
      var menu = parent.querySelector('.dropdown-menu');
      if (!menu) return;
      var isOpen = parent.classList.contains('show');
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
      document.querySelectorAll('.dropdown.show, .nav-item.show').forEach(function(el){
        el.classList.remove('show');
        var m = el.querySelector('.dropdown-menu');
        if (m) m.classList.remove('show');
      });
    }
  });
})();

// ── 2. Service Worker registration (PWA + Capacitor) ──────────────────────
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    navigator.serviceWorker.register('/service-worker.js', { scope: '/' })
      .then(function(reg) {
        setInterval(function() { reg.update(); }, 1800000);
      })
      .catch(function() {});
  });
}

// ── 3. Hide sidebar Install App link when in native app or installed PWA ───
(function(){
  var sidebarItem = document.getElementById('mw-pwa-sidebar-item');
  if (!sidebarItem) return;
  if (window.Capacitor && window.Capacitor.isNativePlatform()) {
    sidebarItem.style.display = 'none';
    return;
  }
  if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true) {
    sidebarItem.style.display = 'none';
    return;
  }
  var isMobile = /Android|iPhone|iPad|iPod|webOS|BlackBerry/i.test(navigator.userAgent);
  if (!isMobile) {
    sidebarItem.style.display = 'none';
    return;
  }
})();

// ── 4. Mobile App Install Splash Screen ────────────────────────────────────
(function(){
  if (window.Capacitor && window.Capacitor.isNativePlatform()) return;
  if (window.matchMedia('(display-mode: standalone)').matches) return;
  if (window.navigator.standalone === true) return;
  var isMobile = /Android|iPhone|iPad|iPod|webOS|BlackBerry/i.test(navigator.userAgent);
  if (!isMobile) return;
  var dismissed = localStorage.getItem('mw-install-splash-dismissed');
  if (dismissed && Date.now() - parseInt(dismissed, 10) < 604800000) return;

  var isAndroid = /Android/.test(navigator.userAgent);
  var isIOS = /iPhone|iPad|iPod/.test(navigator.userAgent) && !window.MSStream;

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
  document.body.style.overflow = 'hidden';

  document.getElementById('mw-install-splash-skip').addEventListener('click', function() {
    overlay.remove();
    document.body.style.overflow = '';
    localStorage.setItem('mw-install-splash-dismissed', Date.now().toString());
  });

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
        pwaBtn.textContent = 'Use Chrome menu → "Add to Home Screen"';
        pwaBtn.disabled = true;
        pwaBtn.style.opacity = '0.7';
      }
    });
  }
})();

// ── 5. Capture Android PWA beforeinstallprompt for deferred use ────────────
window.addEventListener('beforeinstallprompt', function(e) {
  e.preventDefault();
  window._mwInstallPrompt = e;
  window.mwDeferredInstall = e;
});
if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone) {
  var pwaItem = document.getElementById('mw-pwa-sidebar-item');
  if (pwaItem) pwaItem.style.display = 'none';
}

// ── 6. Universal form submit loading state ─────────────────────────────────
(function(){
  document.addEventListener('submit', function(e) {
    var form = e.target;
    if (!form || form.tagName !== 'FORM') return;
    if (form.method.toUpperCase() === 'GET' && form.querySelectorAll('input:not([type=hidden])').length <= 1) return;
    var btn = form.querySelector('button[type="submit"], input[type="submit"], button:not([type])');
    if (!btn || btn.disabled) return;
    if (btn.hasAttribute('data-no-loading')) return;
    btn.disabled = true;
    btn.dataset.mwOrigText = btn.innerHTML;
    var label = btn.textContent.trim();
    if (label.toLowerCase().indexOf('save') !== -1) {
      btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1" role="status"></span> Saving…';
    } else if (label.toLowerCase().indexOf('send') !== -1) {
      btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1" role="status"></span> Sending…';
    } else if (label.toLowerCase().indexOf('creat') !== -1) {
      btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1" role="status"></span> Creating…';
    } else if (label.toLowerCase().indexOf('delet') !== -1) {
      btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1" role="status"></span> Deleting…';
    } else {
      btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1" role="status"></span> Submitting…';
    }
    setTimeout(function() {
      if (btn.disabled && btn.dataset.mwOrigText) {
        btn.disabled = false;
        btn.innerHTML = btn.dataset.mwOrigText;
        delete btn.dataset.mwOrigText;
      }
    }, 8000);
  });

  document.addEventListener('click', function(e) {
    var btn = e.target.closest('.mw-btn-loading');
    if (!btn || btn.disabled) return;
    btn.disabled = true;
    btn.dataset.mwOrigText = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1" role="status"></span> ' + (btn.dataset.loadingText || 'Working…');
  });

  window.mwResetBtn = function(btn) {
    if (btn && btn.dataset.mwOrigText) {
      btn.disabled = false;
      btn.innerHTML = btn.dataset.mwOrigText;
      delete btn.dataset.mwOrigText;
      if (typeof feather !== 'undefined') feather.replace();
    }
  };
})();

// ── 7. Clickable table rows ────────────────────────────────────────────────
(function(){
  document.querySelectorAll('tr[data-href]').forEach(function(row){
    row.addEventListener('click', function(e){
      if (e.target.closest('a,button,input,select,textarea,label')) return;
      window.location.href = row.dataset.href;
    });
  });
})();
