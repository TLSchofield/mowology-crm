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
  <script src="/crm/js/time-clock-widget.js"></script>

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
          // Check for updates periodically (every 30 min)
          setInterval(function() { reg.update(); }, 1800000);
        })
        .catch(function() { /* SW registration failed — non-critical */ });
    });
  }
  </script>

  <!-- Debug Panel (development tool) -->
  <?php include __DIR__ . '/debug-panel.php'; ?>
</body>
</html>
