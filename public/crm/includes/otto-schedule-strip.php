<?php
/**
 * Otto's strip on the schedule surfaces — one line with his face and a count for the day(s) in view,
 * opening to his items for the day (same buttons as his dashboard card via window.MwOtto).
 *
 * Set before including:
 *   $ottoStrip = ['date' => 'Y-m-d']                                  one day (Schedule day view, Map)
 *   $ottoStrip = ['from' => 'Y-m-d', 'to' => 'Y-m-d', 'focus' => ?]    a run of days (week view, 7-day ops)
 *
 * Loads from /crm/api/otto-schedule.php after the page renders (never blocks it; cached 5 min server-side).
 * Shown only to users with jobs.edit. Renders nothing on any failure.
 */
if (!function_exists('userHasPermission') || !userHasPermission('jobs.edit') || empty($ottoStrip) || !is_array($ottoStrip)) {
    return;
}
$__osOk = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
$__osDay = isset($ottoStrip['date']) && $__osOk($ottoStrip['date']);
$__osRange = isset($ottoStrip['from'], $ottoStrip['to']) && $__osOk($ottoStrip['from']) && $__osOk($ottoStrip['to']);
if (!$__osDay && !$__osRange) {
    return;
}
$__osAsset = function (string $p): string {
    $f = (defined('PUBLIC_ROOT') ? PUBLIC_ROOT : dirname(__DIR__, 2)) . $p;
    return $p . '?v=' . (@filemtime($f) ?: '1');
};
?>
<section class="mw-otto-strip is-loading" aria-label="Otto on the schedule"
  <?php if ($__osDay): ?>data-date="<?= htmlspecialchars($ottoStrip['date']) ?>"
  <?php else: ?>data-from="<?= htmlspecialchars($ottoStrip['from']) ?>" data-to="<?= htmlspecialchars($ottoStrip['to']) ?>"<?= !empty($ottoStrip['focus']) && $__osOk($ottoStrip['focus']) ? ' data-focus="' . htmlspecialchars($ottoStrip['focus']) . '"' : '' ?>
  <?php endif; ?>>
  <img class="mw-otto-strip-face" src="/crm/img/heads/otto.jpg" alt="Otto, operations" width="36" height="36">
  <div class="mw-otto-strip-main">
    <button type="button" class="mw-otto-strip-hd" aria-expanded="false">
      <b>Otto</b>
      <span class="mw-otto-strip-count">Checking the day…</span>
      <span class="mw-otto-strip-top"></span>
      <span class="mw-otto-strip-chev" aria-hidden="true">▾</span>
    </button>
    <div class="mw-otto-strip-days" hidden></div>
    <div class="mw-otto-strip-list mw-otto-list" hidden></div>
  </div>
</section>
<script src="<?= htmlspecialchars($__osAsset('/crm/js/otto-schedule-strip.js')) ?>" defer></script>
<?php unset($__osOk, $__osDay, $__osRange, $__osAsset); ?>
