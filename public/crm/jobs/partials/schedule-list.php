<?php
/**
 * Schedule → List view: every stop in the viewed week, grouped by day, laid out like the iPhone's list.
 * Expects: $calendarData, $startDate, $endDate, $filterQueryStr (all set by schedule.php). Row building
 * lives in ScheduleListService; this file only prints.
 */
require_once APP_ROOT . '/Modules/Schedule/Services/ScheduleListService.php';
require_once APP_ROOT . '/Modules/Jobs/Services/ServiceHistoryService.php';

$mwListToday   = date('Y-m-d');
$mwListHistory = [];
try {
    $ids = [];
    foreach ($calendarData as $dayStops) {
        foreach ($dayStops as $s) {
            foreach (($s['visits'] ?? []) as $v) { $ids[] = (int)($v['visit_id'] ?? 0); }
        }
    }
    // Optional garnish — the list must render even if history fails.
    $mwListHistory = $ids ? (new ServiceHistoryService(getDB()))->forVisits($ids, $mwListToday) : [];
} catch (Throwable $e) {
    error_log('[schedule list] service history failed: ' . $e->getMessage());
}
$mwListDays = ScheduleListService::rowsByDay($calendarData, $mwListHistory, $mwListToday);
?>
<div class="mw-sl">
  <?php if (!$mwListDays): ?>
    <div class="mw-sl-empty">Nothing scheduled for this week.</div>
  <?php endif; ?>
  <?php foreach ($mwListDays as $date => $rows): ?>
    <?php $isToday = ($date === $mwListToday); ?>
    <section class="mw-sl-day<?php echo $isToday ? ' mw-sl-day--today' : ''; ?>">
      <h3 class="mw-sl-dayhead">
        <a href="?view=day&amp;date=<?php echo h($date) . $filterQueryStr; ?>"><?php echo h(date('l, M j', strtotime($date))); ?></a>
        <?php if ($isToday): ?><span class="mw-sl-today">Today</span><?php endif; ?>
        <span class="mw-sl-count"><?php echo count($rows); ?> stop<?php echo count($rows) === 1 ? '' : 's'; ?></span>
      </h3>
      <?php foreach ($rows as $r): ?>
        <?php $cls = 'mw-sl-row' . ($r['needs_attention'] ? ' mw-sl-row--attn' : '') . ($r['done'] ? ' mw-sl-row--done' : ''); ?>
        <div class="<?php echo $cls; ?>">
          <div class="mw-sl-time"><?php echo $r['time'] !== null ? h($r['time']) : 'Anytime'; ?></div>
          <div class="mw-sl-main">
            <div class="mw-sl-name"><?php echo h($r['headline']); ?></div>
            <?php if ($r['address'] !== '' && $r['address'] !== $r['headline']): ?>
              <div class="mw-sl-addr"><?php echo h($r['address']); ?></div>
            <?php endif; ?>
            <?php if ($r['services']): ?>
              <div class="mw-sl-services">
                <?php foreach ($r['services'] as $svc): ?><span class="mw-sl-svc"><?php echo h($svc); ?></span><?php endforeach; ?>
              </div>
            <?php endif; ?>
            <?php if ($r['history'] !== null): ?>
              <div class="mw-sl-history"><?php echo h($r['history']); ?></div>
            <?php endif; ?>
          </div>
          <div class="mw-sl-side">
            <?php if ($r['needs_attention']): ?><span class="mw-sl-badge">Needs attention</span><?php elseif ($r['done']): ?><span class="mw-sl-badge mw-sl-badge--done">Done</span><?php endif; ?>
            <div class="mw-sl-crew"><?php echo $r['crew'] ? h(implode(', ', $r['crew'])) : 'Unassigned'; ?></div>
            <div class="mw-sl-visits"><?php echo (int)$r['visit_count']; ?> visit<?php echo $r['visit_count'] === 1 ? '' : 's'; ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>
</div>
