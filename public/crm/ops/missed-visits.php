<?php
/**
 * Otto's desk — the end-of-day missed-visit review (migration 1349).
 *
 * Today's visits that were not done, plus older ones still open, each with Otto's pick and his
 * reason; the person moves (tomorrow / a date), skips with a reason, or cancels with a reason —
 * or accepts all of Otto's picks at once. Opened from the push (Tim, managers, truck tablets)
 * and from Otto's dashboard card. schedule.edit only. All logic: MissedVisitReviewService.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
requirePermission('schedule.edit');
$user = getCurrentUser();

$pageTitle = "Otto: missed visits";
$activePage = 'schedule';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <div class="mw-omr-page-hd">
            <img class="mw-otto-face" src="/crm/img/heads/otto.jpg" alt="Otto, operations" width="56" height="56">
            <div>
              <h1 class="h3 mb-1">Missed visits</h1>
              <p class="text-muted mb-0">What wasn't done, and Otto's pick for each. Nothing changes until you tap. Nothing is sent to clients.</p>
            </div>
          </div>

          <div class="card">
            <div class="card-body">
              <div class="mw-omr" id="mw-otto-missed-page" aria-live="polite"><div class="mw-otto-empty">Loading…</div></div>
            </div>
          </div>

          <script src="<?= _av('/crm/js/otto-missed.js') ?>" defer></script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
