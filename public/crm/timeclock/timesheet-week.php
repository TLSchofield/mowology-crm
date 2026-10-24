<?php
/**
 * Timesheets — the week as people × Monday–Sunday (Tim, 2026-10-23: "like Jobber's, adjustable always").
 *
 * The Mowology app is the master record of hours. One hairline list, a person per row, a day per column; click a day
 * to open that person's entries and change them. An approved week can be changed too: saving asks why, reopens the
 * week for approval (TimesheetReopenService) and then makes the change, so what was approved is never altered silently.
 * Design: Ittai v1.2 (docs/design/ittai-v1.2) — rows before cards, status as icon + word, tabular figures, sentence case.
 * Data: TimesheetWeekService. Edits: /crm/api/time-entry-edit.php, /crm/api/job-time-entry-edit.php,
 * /crm/api/timesheet-reopen.php. Script: /crm/js/timesheet-week.js.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/timeclock-functions.php';
require_once APP_ROOT . '/Modules/Operations/Services/BreakService.php';
require_once APP_ROOT . '/Modules/Team/Services/TimesheetWeekService.php';

requireLogin();
$user = getCurrentUser();
requirePermission('timer.override');

$db = getDB();
$canEdit = in_array($user['role'] ?? '', ['admin', 'manager'], true);

$weekParam = isset($_GET['week']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['week']) ? (string)$_GET['week'] : date('Y-m-d');
$week = TimesheetWeekService::forWeek($db, $weekParam);
$ws = $week['week_start'];
$prev = date('Y-m-d', strtotime($ws . ' -7 days'));
$next = date('Y-m-d', strtotime($ws . ' +7 days'));
$isThisWeek = $ws === TimesheetWeekService::weekStartOf(date('Y-m-d'));
$today = date('Y-m-d');
$e = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$hm = static function ($ts) { return $ts ? date('g:i A', strtotime((string)$ts)) : ''; };

$range = date('M j', strtotime($ws)) . ' – ' . (date('M', strtotime($ws)) === date('M', strtotime($week['week_end'])) ? date('j', strtotime($week['week_end'])) : date('M j', strtotime($week['week_end']))) . ', ' . date('Y', strtotime($week['week_end']));

// Status as icon + word (never colour alone).
$statusIcon = static function (string $code): string {
    $paths = [
        'approved'  => '<circle cx="8" cy="8" r="6.25"/><path d="M5.2 8.2l1.9 1.9 3.7-3.9"/>',
        'submitted' => '<circle cx="8" cy="8" r="6.25"/><path d="M8 4.6V8l2.3 1.4"/>',
        'rejected'  => '<circle cx="8" cy="8" r="6.25"/><path d="M5.6 5.6l4.8 4.8M10.4 5.6l-4.8 4.8"/>',
        'open'      => '<circle cx="8" cy="8" r="6.25" stroke-dasharray="2.2 2.2"/>',
        'flag'      => '<path d="M8 2.2l6.1 10.6H1.9z"/><path d="M8 6.6v2.8M8 11.3v.1"/>',
    ];
    return '<svg class="it-ico" viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$code] ?? $paths['open']) . '</svg>';
};

$pageTitle  = 'Timesheets';
$activePage = 'timesheets';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>
<?php include dirname(__DIR__) . '/includes/flash.php'; ?>

<div class="it-ts" id="it-ts" data-csrf="<?= $e(function_exists('generateCSRFToken') ? generateCSRFToken() : '') ?>" data-week="<?= $e($ws) ?>" data-can-edit="<?= $canEdit ? '1' : '0' ?>">
  <header class="it-ts-head">
    <div>
      <h1 class="it-ts-title">Timesheets</h1>
      <p class="it-ts-sub">Hours recorded in the Mowology app. Click a day to see or change it.</p>
    </div>
    <div class="it-ts-tools">
      <a class="it-ts-link" href="/crm/timeclock/timesheets.php">List view</a>
      <a class="it-ts-link" href="/crm/timeclock/payroll-summary.php">Payroll</a>
    </div>
  </header>

  <nav class="it-ts-weeknav" aria-label="Choose a week">
    <a class="it-ts-btn it-ts-btn-icon" href="?week=<?= $e($prev) ?>" aria-label="Previous week">
      <svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 3.5L5.5 8 10 12.5"/></svg>
    </a>
    <span class="it-ts-range" aria-live="polite"><?= $e($range) ?></span>
    <a class="it-ts-btn it-ts-btn-icon" href="?week=<?= $e($next) ?>" aria-label="Next week">
      <svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3.5L10.5 8 6 12.5"/></svg>
    </a>
    <?php if (!$isThisWeek): ?><a class="it-ts-btn" href="?week=<?= $e(date('Y-m-d')) ?>">This week</a><?php endif; ?>
  </nav>

  <?php $t = $week['totals']; ?>
  <p class="it-ts-summary">
    <span><strong><?= count($week['people']) ?></strong> <?= count($week['people']) === 1 ? 'person' : 'people' ?></span>
    <span><strong><?= $e(TimesheetWeekService::fmt($t['minutes'])) ?></strong> in the week</span>
    <?php if ($t['ot_minutes'] > 0): ?><span class="it-ts-attn"><?= $statusIcon('flag') ?> Overtime <strong><?= $e(TimesheetWeekService::fmt($t['ot_minutes'])) ?></strong></span><?php endif; ?>
    <?php if ($t['flags'] > 0): ?><span class="it-ts-attn"><?= $statusIcon('flag') ?> <strong><?= (int)$t['flags'] ?></strong> to check</span><?php endif; ?>
    <?php if ($t['waiting'] > 0): ?><span><?= $statusIcon('submitted') ?> <strong><?= (int)$t['waiting'] ?></strong> waiting for approval</span><?php endif; ?>
  </p>

  <?php if (!$week['people']): ?>
    <div class="it-ts-empty">No hours recorded for this week.</div>
  <?php else: ?>
  <div class="it-ts-list" role="table" aria-label="Hours by person and day">
    <div class="it-ts-colhead" role="row">
      <span class="it-ts-name-h" role="columnheader">Person</span>
      <?php foreach ($week['dates'] as $d): ?>
        <span class="it-ts-day-h<?= $d === $today ? ' is-today' : '' ?>" role="columnheader"><?= $e(date('D', strtotime($d))) ?> <b><?= (int)date('j', strtotime($d)) ?></b></span>
      <?php endforeach; ?>
      <span class="it-ts-total-h" role="columnheader">Total</span>
    </div>

    <?php foreach ($week['people'] as $p): $uid = (int)$p['user_id']; ?>
    <section class="it-ts-person" data-user="<?= $uid ?>" data-timesheet="<?= (int)($p['timesheet_id'] ?? 0) ?>" data-locked="<?= $p['locked'] ? '1' : '0' ?>" data-name="<?= $e($p['name']) ?>">
      <div class="it-ts-row" role="row">
        <button type="button" class="it-ts-who" aria-expanded="false" aria-controls="it-ts-d-<?= $uid ?>">
          <span class="it-ts-avatar" aria-hidden="true"><?= $e($p['initials']) ?></span>
          <span class="it-ts-who-text">
            <span class="it-ts-name"><?= $e($p['name']) ?></span>
            <span class="it-ts-status it-ts-st-<?= $e($p['status']) ?>"><?= $statusIcon($p['status']) ?> <?= $e($p['status_word']) ?></span>
          </span>
          <svg class="it-ts-chev" viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6l4 4 4-4"/></svg>
        </button>
        <?php foreach ($week['dates'] as $d): $day = $p['days'][$d]; $hasFlag = $day['anomaly_min'] > 0 || $day['open']; ?>
          <button type="button" class="it-ts-cell<?= $d === $today ? ' is-today' : '' ?><?= $day['shift_min'] > 480 ? ' is-long' : '' ?>" data-day="<?= $e($d) ?>"
                  aria-label="<?= $e(date('l j', strtotime($d)) . ': ' . ($day['shift_min'] > 0 ? TimesheetWeekService::fmt($day['shift_min']) : 'no hours') . ($hasFlag ? ', needs a look' : '') . '. Open to change.') ?>">
            <span class="it-ts-day-label"><?= $e(date('D j', strtotime($d))) ?></span>
            <span class="it-ts-num"><?= $e(TimesheetWeekService::fmt($day['shift_min'])) ?></span>
            <?php if ($hasFlag): ?><span class="it-ts-mark it-ts-attn"><?= $statusIcon('flag') ?><span class="it-sr"><?= $day['open'] ? 'Shift still running' : 'Timer ran past the shift' ?></span></span><?php endif; ?>
          </button>
        <?php endforeach; ?>
        <span class="it-ts-total" role="cell">
          <span class="it-ts-num it-ts-num-strong"><?= $e(TimesheetWeekService::fmt($p['total_min'])) ?></span>
          <?php if ($p['ot_min'] > 0): ?><span class="it-ts-ot it-ts-attn"><?= $statusIcon('flag') ?> Overtime <?= $e(TimesheetWeekService::fmt($p['ot_min'])) ?></span><?php endif; ?>
        </span>
      </div>

      <div class="it-ts-detail" id="it-ts-d-<?= $uid ?>" hidden>
        <?php if ($p['locked']): ?>
          <p class="it-ts-note"><?= $statusIcon('approved') ?> This week is approved. Changing an hour reopens it, and it needs approving again.</p>
        <?php endif; ?>
        <?php foreach ($week['dates'] as $d): $day = $p['days'][$d]; ?>
        <div class="it-ts-dayblock" data-day="<?= $e($d) ?>">
          <div class="it-ts-dayhead">
            <h3><?= $e(date('l, M j', strtotime($d))) ?></h3>
            <span class="it-ts-num"><?= $e($day['shift_min'] > 0 ? TimesheetWeekService::fmt($day['shift_min']) . ' paid' : 'No shift') ?></span>
          </div>
          <?php if ($day['anomaly_min'] > 0): ?>
            <p class="it-ts-note it-ts-attn"><?= $statusIcon('flag') ?> Job time (<?= $e(TimesheetWeekService::fmt($day['job_min'])) ?>) is <?= $e(TimesheetWeekService::fmt($day['anomaly_min'])) ?> more than the shift. A timer probably ran on. Correct the job time below.</p>
          <?php endif; ?>

          <?php foreach ($day['entries'] as $en): ?>
          <div class="it-ts-entry" data-kind="shift" data-id="<?= (int)$en['id'] ?>" data-in="<?= $e(substr($en['in'], 11, 5)) ?>" data-out="<?= $e($en['out'] ? substr($en['out'], 11, 5) : '') ?>" data-next="<?= $en['next_day'] ? '1' : '0' ?>" data-notes="<?= $e($en['notes']) ?>">
            <span class="it-ts-entry-main">
              <span class="it-ts-kind">Shift</span>
              <span class="it-ts-times"><?= $e($hm($en['in'])) ?> → <?= $en['out'] ? $e($hm($en['out'])) . ($en['next_day'] ? ' <em>next day</em>' : '') : '<em>still running</em>' ?></span>
            </span>
            <span class="it-ts-num"><?= $e(TimesheetWeekService::fmt($en['minutes'])) ?></span>
            <?php if ($canEdit): ?>
            <span class="it-ts-entry-acts">
              <button type="button" class="it-ts-btn it-ts-act-edit">Change</button>
              <button type="button" class="it-ts-btn it-ts-btn-quiet it-ts-act-void">Remove</button>
            </span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>

          <?php foreach ($day['jobs'] as $jb): ?>
          <div class="it-ts-entry it-ts-entry-job" data-kind="job" data-id="<?= (int)$jb['id'] ?>" data-min="<?= (int)$jb['minutes'] ?>">
            <span class="it-ts-entry-main">
              <span class="it-ts-kind">Job</span>
              <span class="it-ts-times"><?= $e($hm($jb['start'])) ?> → <?= $jb['end'] ? $e($hm($jb['end'])) . ($jb['next_day'] ? ' <em>next day</em>' : '') : '<em>still running</em>' ?>
                <small><?= $e(trim($jb['number'] . ' ' . $jb['title'])) ?><?= $jb['address'] !== '' ? ' · ' . $e($jb['address']) : '' ?></small></span>
            </span>
            <span class="it-ts-num"><?= $e(TimesheetWeekService::fmt($jb['minutes'])) ?></span>
            <?php if ($canEdit): ?>
            <span class="it-ts-entry-acts">
              <button type="button" class="it-ts-btn it-ts-act-edit">Change time</button>
              <button type="button" class="it-ts-btn it-ts-btn-quiet it-ts-act-void">Remove</button>
            </span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>

          <?php if ($canEdit): ?>
          <div class="it-ts-add"><button type="button" class="it-ts-btn it-ts-act-add" data-day="<?= $e($d) ?>">Add a shift</button></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<script src="<?= function_exists('_av') ? _av('/crm/js/timesheet-week.js') : '/crm/js/timesheet-week.js' ?>" defer></script>
<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
