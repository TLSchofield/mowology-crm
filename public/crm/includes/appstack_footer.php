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

  <!-- Scripts: load order matters — feather → app.js (jQuery+Bootstrap) → dependents → page-init.
       All deferred: browser downloads in parallel while parsing HTML, executes in listed order
       after parse completes. 3.64 MB app.js no longer blocks first paint. -->
  <script src="/crm/js/feather.min.js" defer></script>
  <script src="/crm/js/feather-helper.js" defer></script>
  <script src="/crm/js/app.js" defer></script>
  <script src="/crm/js/mw-layout-manager.js?v=20260306" defer></script>
  <script src="/crm/js/mw-toast.js?v=20260306c" defer></script>
  <script src="/crm/js/time-clock-widget.js?v=20260309a" defer></script>
  <script src="/crm/js/capacitor-bridge.js?v=20260309a" defer></script>
  <!-- Dropdown fix, SW registration, PWA install, form loading state, clickable rows -->
  <script src="/crm/js/mw-page-init.js?v=20260505" defer></script>

  <!-- Debug Panel (development tool) -->
  <?php include __DIR__ . '/debug-panel.php'; ?>
</body>
</html>
