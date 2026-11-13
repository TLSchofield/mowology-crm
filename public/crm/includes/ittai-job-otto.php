<?php
/**
 * Otto's job strip — his schedule decisions (a visit to add, a booking to move, weather, a full day), one at a time, across the
 * top of the Schedule page (week and day) in the Ittai desktop shell. Included from appstack_head.php after Sam's strip, only
 * when $itShell is on.
 *
 * Nothing is decided here: OttoJobAdapter shapes what OttoScheduleService already finds for the days in view, and every button
 * posts what Otto's card posts (/crm/api/otto.php?mode=decide → OttoActionService::decide) or opens the day. Nothing moves
 * without the owner's click. /crm/js/ittai-job-mia.js (shared with Mia's and Sam's strips) sends it. Shown only to the owner
 * with jobs.edit, and only while ops_settings it_otto_job is on. The things strip above the calendar is unchanged.
 * Any failure renders nothing — it must never break the page.
 */
if (empty($itShell)) return;
if (!defined('APP_ROOT')) return;
if ((string)($activePage ?? '') !== 'schedule') return;
try {
    require_once __DIR__ . '/nav-items.php';
    $__ioUser = (array)($user ?? (function_exists('getCurrentUser') ? getCurrentUser() : []) ?? []);
    $__ioHere = false;
    foreach (mwIttaiNavBlocks((array)($navItems ?? []), $__ioUser, 'schedule') as $__ioB) {
        if (($__ioB['slug'] ?? '') === 'otto' && !empty($__ioB['here'])) { $__ioHere = true; break; }
    }
    if (!$__ioHere) return;
    if (function_exists('userHasPermission') && !userHasPermission('jobs.edit')) return;   // the decide endpoint asks for it
    $__ioOk = static function ($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); };
    $__ioFrom = $__ioOk($startDate ?? null) ? $startDate : null;
    $__ioTo = $__ioOk($endDate ?? null) ? $endDate : $__ioFrom;
    if (!$__ioFrom) return;
    require_once APP_ROOT . '/Services/HeadIdentity.php';
    require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/CharlieDeskService.php';
    $__ioDb = getDB();
    $__ioDesk = new CharlieDeskService($__ioDb);
    if (!$__ioDesk->ready() || !$__ioDesk->isOwner($__ioUser)) return;
    require_once APP_ROOT . '/Modules/Operations/Services/OttoJobAdapter.php';
    $__ioJobs = OttoJobAdapter::jobs($__ioDb, $__ioFrom, $__ioTo);
} catch (Throwable $__e) {
    error_log('Otto schedule strip unavailable: ' . $__e->getMessage());
    return;
}
// Local-only fixture so the strip can be drawn without live data (never on production).
if (($_GET['it_job_demo'] ?? '') === '1'
    && in_array(strtok((string)($_SERVER['HTTP_HOST'] ?? ''), ':'), ['127.0.0.1', 'localhost'], true)
    && in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)) {
    require_once APP_ROOT . '/Modules/Operations/Services/OttoJobAdapter.php';
    $__ioJobs = OttoJobAdapter::queue([
        ['id' => 11, 'key' => 'k:w', 'kind' => 'weather', 'text' => 'Rain 80% Thursday: 14 Elm Lawn cut is booked outdoors.', 'detail' => 'The weather guard flagged it.', 'propose' => [], 'day' => '2026-11-12'],
        ['id' => 12, 'key' => 'k:u', 'kind' => 'unscheduled', 'text' => 'The crew was at 12 Elm on Tue 9:10-10:40 with nothing booked.', 'detail' => 'Add the visit, or tell Otto it was not work.', 'propose' => ['start' => '09:10', 'end' => '10:40'], 'day' => '2026-11-10'],
        ['id' => null, 'key' => 'k:o', 'kind' => 'overbooked', 'text' => 'Crew 1 day is 9 stops and 11h of plan lengths: 9h fits.', 'detail' => 'Plan lengths, not drive time.', 'propose' => [], 'day' => '2026-11-11'],
    ], [12 => [['id' => 7, 'number' => 'PLN-2026-0007']]], [11 => ['date' => '2026-11-13', 'time_start' => '08:00:00']], [0, 0]);
}
// The numbers for the days in view (the old green panel, retired in the shell): they sit on the line when no decision does.
$__ioNums = [];
if (isset($mcDisplayRev, $mcDisplayTarget, $mcDisplayLabor, $mcDisplayMargin, $mcEfficiency, $mcCompletedStops, $mcWeekStops)) {
    $__ioDay = !empty($mcIsDay);
    $__ioNums = [
        ['$' . number_format((float)$mcDisplayRev, 0), $__ioDay ? 'Day revenue' : 'Revenue', 'of $' . number_format((float)$mcDisplayTarget, 0)],
        [(int)$mcCompletedStops . '/' . (int)$mcWeekStops, 'Stops done', ''],
        ['$' . number_format((float)$mcDisplayLabor, 0), 'Labor', !empty($mcDisplayLaborIsActual) ? 'actual' : 'estimated'],
        [(int)$mcDisplayMargin . '%', 'Margin', ''],
        [(string)(int)$mcEfficiency, 'Route efficiency', 'of 100'],
    ];
}
if (!$__ioJobs && !$__ioNums) return;

require_once __DIR__ . '/ittai-icons.php';
$__ioFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$__ioSvg = static function (string $id): string {
    return '<svg class="it-job-ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><use href="#it-w-' . $id . '"/></svg>';
};
itIconSprite();
?>
<?php if ($__ioJobs): ?>
<div class="it-job-outer"><div class="it-job-wrap is-solo">
<section class="it-job it-job-mia it-job-otto" data-head="otto" data-who="Otto" aria-label="Otto's job">
  <h2 class="it-vh it-job-tt"></h2>
  <div class="it-job-body"><div class="it-job-rail">
    <a class="it-job-anchor it-job-anchor-start" href="/crm/jobs/schedule.php"><span class="it-ad it-ad-fill"><?= $__ioSvg('eye') ?></span><span>Schedule</span></a>
    <span class="it-job-conf is-forming" role="img"><svg class="it-si" width="24" height="24" viewBox="0 0 16 16" aria-hidden="true"><use href="#it-st-approval-forming"/></svg></span>
    <div class="it-job-split">
      <div class="it-job-col it-job-left"><div class="it-job-what"><b class="it-job-who"></b><span class="it-job-at"></span><p class="it-job-quote"></p><ul class="it-job-cmp" hidden></ul><a class="it-job-open" href="#" target="_blank" rel="noopener" hidden></a><p class="it-job-why"></p></div></div>
      <div class="it-job-col it-job-fig-col"><figure class="it-job-fig" hidden><img alt="" src=""></figure></div>
      <span class="it-job-disc it-job-disc-info" role="img" aria-label="becomes"><?= $__ioSvg('arrow') ?></span>
      <div class="it-job-col it-job-right"><form class="it-job-form" novalidate></form></div>
      <div class="it-job-col it-job-acts"><button type="button" class="it-btn it-btn-fill" data-role="primary"><?= $__ioSvg('check') ?><b></b><span></span></button><span class="it-job-m"></span><button type="button" class="it-btn it-btn-line" data-role="secondary"><?= $__ioSvg('arrow') ?><b></b><span></span></button></div>
    </div>
    <a class="it-job-anchor it-job-anchor-end" href="/crm/jobs/schedule.php?view=day"><span class="it-ad"><?= $__ioSvg('compass') ?></span><span>Day view</span></a>
  </div></div>
  <p class="it-job-foot"><span class="it-job-n"></span> · <button type="button" class="it-job-skip">Not yet</button></p>
  <p class="it-job-msg" role="status" aria-live="polite" hidden><span class="it-job-msgt"></span></p>
  <script type="application/json" class="it-job-data"><?= json_encode($__ioJobs, $__ioFlags) ?></script>
</section>
</div></div>
<?php endif; ?>
<?php if ($__ioNums): ?>
<div class="it-job-outer it-job-nums-outer"><section class="it-job it-job-nums" data-head="otto" aria-label="This <?= !empty($mcIsDay) ? 'day' : 'week' ?> in numbers"<?= $__ioJobs ? ' hidden' : '' ?>>
  <div class="it-job-rail">
    <a class="it-job-anchor it-job-anchor-start" href="/crm/jobs/schedule.php"><span class="it-ad it-ad-fill"><?= $__ioSvg('eye') ?></span><span>Schedule</span></a>
    <?php foreach ($__ioNums as $__ioN): ?>
    <div class="it-job-num"><b><?= htmlspecialchars($__ioN[0], ENT_QUOTES) ?></b><span><?= htmlspecialchars($__ioN[1], ENT_QUOTES) ?></span><?php if ($__ioN[2] !== ''): ?><i><?= htmlspecialchars($__ioN[2], ENT_QUOTES) ?></i><?php endif; ?></div>
    <?php endforeach; ?>
    <a class="it-job-anchor it-job-anchor-end" href="/crm/jobs/schedule.php?view=day"><span class="it-ad"><?= $__ioSvg('compass') ?></span><span>Day view</span></a>
  </div>
</section></div>
<?php endif; ?>
<?php if ($__ioJobs): ?>
<script src="<?= function_exists('_av') ? _av('/crm/js/ittai-job-mia.js') : '/crm/js/ittai-job-mia.js' ?>" defer></script>
<?php endif; ?>
