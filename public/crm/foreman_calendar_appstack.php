<?php
/**
 * Charlie, Foreman — deadlines, decisions and rules (owner only).
 *
 * Thin controller: the page is a shell; /crm/js/foreman-calendar.js loads everything from
 * /crm/api/charlie.php?mode=calendar and posts Tim's changes back (DeadlineService,
 * CharlieInboxService, CharlieUrgentService). Charlie files, pays and sends nothing.
 */
declare(strict_types=1);
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();

require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/CharlieDeskService.php';
$__desk = new CharlieDeskService(getDB());
$__allowed = $__desk->ready() && $__desk->isOwner((array)$user);

$pageTitle = 'Charlie · Foreman';
$activePage = 'dashboard';
?>
<?php include 'includes/appstack_head.php'; ?>

<div class="mw-page-header">
  <div>
    <h1 class="mw-page-title">Charlie · Foreman</h1>
    <p class="text-muted mb-0">Deadlines he watches, decisions waiting on you, and the rules between the heads.</p>
  </div>
  <a class="btn btn-sm btn-outline-secondary" href="/crm/dashboard_appstack.php">← Dashboard</a>
</div>

<?php if (!$__allowed): ?>
  <div class="card"><div class="card-body">Charlie works for the owner only<?= $__desk->ready() ? '' : ' (migration 1170 has not run yet)' ?>.</div></div>
<?php else: ?>
<div class="mw-charlie-page" id="mw-foreman">
  <ul class="nav nav-tabs mw-charlie-tabs" role="tablist">
    <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#deadlines" role="tab">Deadlines</a></li>
    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#decisions" role="tab">Decisions</a></li>
    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#rules" role="tab">Rules</a></li>
    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#history" role="tab">History</a></li>
  </ul>
  <div class="mw-charlie-msg mw-charlie-page-msg" id="mw-foreman-msg" aria-live="polite"></div>
  <div class="tab-content">
    <div class="tab-pane fade show active" id="deadlines" role="tabpanel">
      <div class="mw-charlie-qs" id="mw-foreman-qs" hidden></div>
      <div id="mw-foreman-deadlines" class="mw-charlie-cal"><div class="mw-charlie-empty">Loading…</div></div>
      <div class="card mw-charlie-pack" id="mw-foreman-pack" hidden>
        <div class="card-body">
          <h5 class="card-title">Year-end package <span id="mw-foreman-pack-year"></span></h5>
          <ul class="mw-charlie-pack-list"></ul>
          <p class="text-muted small mb-0">Charlie gets these ready; you and your accountant file. Confirm every tax and payroll date with your accountant.</p>
        </div>
      </div>
      <div class="card mw-charlie-edit">
        <div class="card-body">
          <h5 class="card-title" id="mw-foreman-form-title">Add a deadline</h5>
          <form id="mw-foreman-form" class="mw-charlie-form" novalidate>
            <input type="hidden" name="id" value="">
            <div class="mw-charlie-form-grid">
              <label>Name<input type="text" name="title" class="form-control" maxlength="200"></label>
              <label>Kind
                <select name="category" class="form-control">
                  <option value="tax">Tax</option><option value="payroll">Payroll</option><option value="safety">WorkSafeBC / safety</option>
                  <option value="licence">Licence</option><option value="insurance">Insurance</option><option value="vehicle">Vehicle</option>
                  <option value="equipment">Equipment</option><option value="contract">Contract</option><option value="other">Other</option>
                </select>
              </label>
              <label>Repeats
                <select name="repeat" class="form-control">
                  <option value="annual">Every year</option><option value="dates">Several dates a year</option><option value="monthly">Every month</option>
                  <option value="every">Every few months</option><option value="once">Once</option><option value="">Not set yet</option>
                </select>
              </label>
              <label data-for="annual once">Date<input type="date" name="date" class="form-control"></label>
              <label data-for="dates">Dates (month-day, comma separated)<input type="text" name="dates" class="form-control" placeholder="01-31, 04-30, 07-31, 10-31"></label>
              <label data-for="monthly">Day of the month<input type="text" name="day" class="form-control" placeholder="15 or last"></label>
              <label data-for="every">Every how many months<input type="number" name="months" class="form-control" min="1" max="60"></label>
              <label data-for="every">Counting from<input type="date" name="anchor_date" class="form-control"></label>
              <label>Remind me this many days before<input type="number" name="lead_days" class="form-control" min="0" max="365" value="14"></label>
              <label>Cost, if known ($)<input type="number" name="amount_hint" class="form-control" min="0" step="0.01"></label>
              <label class="mw-charlie-wide">What to get ready (one step per line)<textarea name="prepare" class="form-control" rows="3"></textarea></label>
              <label class="mw-charlie-check"><input type="checkbox" name="active" checked> Watching this one</label>
            </div>
            <div class="mw-charlie-form-btns">
              <button type="submit" class="btn btn-success btn-sm">Save</button>
              <button type="button" class="btn btn-outline-secondary btn-sm" id="mw-foreman-form-reset">Clear</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="tab-pane fade" id="decisions" role="tabpanel">
      <div class="mw-charlie-inbox-page">
        <div class="mw-charlie-rows" id="mw-foreman-rows"><div class="mw-charlie-empty">Loading…</div></div>
        <div class="mw-charlie-held" id="mw-foreman-held" hidden></div>
      </div>
    </div>

    <div class="tab-pane fade" id="rules" role="tabpanel">
      <div id="mw-foreman-rules" class="mw-charlie-rules"></div>
      <div class="card mw-charlie-edit">
        <div class="card-body">
          <h5 class="card-title">Interrupt me when</h5>
          <form id="mw-foreman-settings" class="mw-charlie-form" novalidate>
            <label>A payment fails for at least ($)<input type="number" name="payment_min" class="form-control" min="0" step="1"></label>
            <p class="text-muted small">…and when Otto says a visit today can't go ahead in this weather. Nothing else interrupts you; it waits for the 7 am brief.</p>
            <button type="submit" class="btn btn-success btn-sm">Save</button>
          </form>
        </div>
      </div>
      <h5 class="mt-4">Every ruling</h5>
      <div id="mw-foreman-rulings" class="mw-charlie-rulings"></div>
    </div>

    <div class="tab-pane fade" id="history" role="tabpanel">
      <div id="mw-foreman-history" class="mw-charlie-history"></div>
    </div>
  </div>
</div>
<script src="<?= function_exists('_av') ? _av('/crm/js/charlie-inbox.js') : '/crm/js/charlie-inbox.js' ?>" defer></script>
<script src="<?= function_exists('_av') ? _av('/crm/js/foreman-calendar.js') : '/crm/js/foreman-calendar.js' ?>" defer></script>
<?php endif; ?>

<?php include 'includes/appstack_footer.php'; ?>
