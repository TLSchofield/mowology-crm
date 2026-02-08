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

  <script src="/crm/js/app.js"></script>

  <!-- Debug Panel (development tool) -->
  <?php include __DIR__ . '/debug-panel.php'; ?>
</body>
</html>
