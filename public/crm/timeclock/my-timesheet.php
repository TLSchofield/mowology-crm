<?php
/**
 * My Timesheet — Employee day-by-day timesheet with job breakdown.
 * Beats Jobber: shows global clock-in/out AND individual job entries per day.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/timeclock-functions.php';

requireLogin();
$user = getCurrentUser();
$db   = getDB();

// ── Date/week navigation ──────────────────────────────────────────────────────
$today   = date('Y-m-d');
$rawDate = isset($_GET['date']) ? $_GET['date'] : $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) $rawDate = $today;

$selDate      = $rawDate;
$weekStart    = date('Y-m-d', strtotime('monday this week', strtotime($selDate)));
$weekEnd      = date('Y-m-d', strtotime('sunday this week', strtotime($selDate)));
$prevWeekDate = date('Y-m-d', strtotime($weekStart . ' -7 days'));
$nextWeekDate = date('Y-m-d', strtotime($weekStart . ' +7 days'));
$isThisWeek   = ($weekStart === date('Y-m-d', strtotime('monday this week')));
$weekLabel    = date('M j', strtotime($weekStart)) . ' – ' . date('M j, Y', strtotime($weekEnd));

// ── Global clock entries ──────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT id, clock_in, clock_out, status, notes,
           TIMESTAMPDIFF(SECOND, clock_in, COALESCE(clock_out, NOW())) AS duration_seconds
    FROM   time_clock_entries
    WHERE  user_id = ?
      AND  DATE(clock_in) BETWEEN ? AND ?
    ORDER  BY clock_in ASC
");
$stmt->execute([$user['id'], $weekStart, $weekEnd]);
$allClockEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Job time entries with job/plan info ───────────────────────────────────────
$stmt = $db->prepare("
    SELECT jte.id, jte.visit_id, jte.clock_entry_id,
           jte.start_time, jte.end_time, jte.duration_minutes, jte.status,
           COALESCE(jp.title, 'Job Visit') AS job_title,
           COALESCE(jp.service_type, '')   AS service_type
    FROM   job_time_entries jte
    LEFT JOIN job_visits jv ON jte.visit_id = jv.id
    LEFT JOIN job_plans jp  ON jv.plan_id   = jp.id
    WHERE  jte.user_id = ?
      AND  DATE(jte.start_time) BETWEEN ? AND ?
      AND  jte.status != 'void'
    ORDER  BY jte.start_time ASC
");
$stmt->execute([$user['id'], $weekStart, $weekEnd]);
$allJobEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Group data by date ────────────────────────────────────────────────────────
$byDate           = [];  // date => ['clock' => [], 'orphan_jobs' => [], 'total_sec' => 0]
$jobsByClock      = [];  // clock_entry_id => [job entries]
$weekTotalSec     = 0;
$activeClockEntry = null;

foreach ($allClockEntries as $ce) {
    $d = date('Y-m-d', strtotime($ce['clock_in']));
    if (!isset($byDate[$d])) $byDate[$d] = ['clock' => [], 'orphan_jobs' => [], 'total_sec' => 0];
    $byDate[$d]['clock'][] = $ce;
    $sec = max(0, (int)$ce['duration_seconds']);
    $byDate[$d]['total_sec'] += $sec;
    $weekTotalSec += $sec;
    if ($ce['status'] === 'active' && !$ce['clock_out']) {
        $activeClockEntry = $ce;
    }
}

foreach ($allJobEntries as $je) {
    $d = date('Y-m-d', strtotime($je['start_time']));
    if (!isset($byDate[$d])) $byDate[$d] = ['clock' => [], 'orphan_jobs' => [], 'total_sec' => 0];
    if ($je['clock_entry_id']) {
        $cid = (int)$je['clock_entry_id'];
        if (!isset($jobsByClock[$cid])) $jobsByClock[$cid] = [];
        $jobsByClock[$cid][] = $je;
    } else {
        $byDate[$d]['orphan_jobs'][] = $je;
    }
}

// ── Scheduled shift for selected day ─────────────────────────────────────────
$selDow = (int)date('w', strtotime($selDate));
$shiftStmt = $db->prepare("
    SELECT start_time, end_time, notes
    FROM crew_work_schedules WHERE user_id = ? AND day_of_week = ?
");
$shiftStmt->execute([$user['id'], $selDow]);
$todayShift = $shiftStmt->fetch(PDO::FETCH_ASSOC) ?: null;

// ── Selected day helpers ──────────────────────────────────────────────────────
$selData      = $byDate[$selDate] ?? null;
$selClocks    = $selData ? $selData['clock'] : [];
$selOrphans   = $selData ? $selData['orphan_jobs'] : [];
$selDaySec    = $selData ? $selData['total_sec'] : 0;
$isSelToday   = ($selDate === $today);
$hasSelData   = !empty($selClocks) || !empty($selOrphans);

// Count jobs per day for week summary display
function countDayJobs(array $clocks, array $jobsByClock, array $orphans): int {
    $n = count($orphans);
    foreach ($clocks as $ce) {
        $n += count($jobsByClock[(int)$ce['id']] ?? []);
    }
    return $n;
}

// ── Build week day array (Mon–Sun) ────────────────────────────────────────────
$weekDays = [];
for ($i = 0; $i < 7; $i++) {
    $weekDays[] = date('Y-m-d', strtotime($weekStart . " +{$i} days"));
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function ts2Dur(int $seconds): string {
    if ($seconds <= 0) return '0m';
    $h = (int)floor($seconds / 3600);
    $m = (int)floor(($seconds % 3600) / 60);
    return $h > 0 ? $h . 'h' . ($m > 0 ? ' ' . $m . 'm' : '') : $m . 'm';
}
function ts2Time(string $dt): string {
    return date('g:i a', strtotime($dt));
}
function ts2SvcColor(string $type): string {
    $map = [
        'landscaping'        => '#2D8659',
        'lawn_care'          => '#7FD858',
        'snow_removal'       => '#3B82F6',
        'hedge_trimming'     => '#8B5CF6',
        'garden_maintenance' => '#F59E0B',
        'seasonal_cleanup'   => '#EC4899',
    ];
    return $map[$type] ?? '#888';
}

$pageTitle  = 'My Timesheet';
$activePage = 'timeclock';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-ts2-page">

    <!-- Week nav -->
    <div class="mw-ts2-week-nav">
        <a href="?date=<?php echo htmlspecialchars($prevWeekDate); ?>" class="mw-ts2-nav-arrow" aria-label="Previous week">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        </a>
        <span class="mw-ts2-week-label"><?php echo htmlspecialchars($weekLabel); ?></span>
        <?php if (!$isThisWeek): ?>
            <a href="?date=<?php echo htmlspecialchars($today); ?>" class="mw-ts2-today-btn">Today</a>
        <?php else: ?>
            <a href="?date=<?php echo htmlspecialchars($nextWeekDate); ?>" class="mw-ts2-nav-arrow" aria-label="Next week">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
        <?php endif; ?>
    </div>

    <!-- Day strip -->
    <div class="mw-ts2-day-strip">
        <?php foreach ($weekDays as $d):
            $isSelected = ($d === $selDate);
            $isToday    = ($d === $today);
            $dData      = $byDate[$d] ?? null;
            $dSec       = $dData ? $dData['total_sec'] : 0;
        ?>
        <a href="?date=<?php echo htmlspecialchars($d); ?>"
           class="mw-ts2-day-cell<?php echo $isSelected ? ' mw-ts2-day-cell--sel' : ''; ?><?php echo $isToday ? ' mw-ts2-day-cell--today' : ''; ?>">
            <span class="mw-ts2-dc-name"><?php echo date('D', strtotime($d)); ?></span>
            <span class="mw-ts2-dc-num"><?php echo date('j', strtotime($d)); ?></span>
            <span class="mw-ts2-dc-dur"><?php echo $dSec > 0 ? ts2Dur($dSec) : ''; ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Selected day header -->
    <div class="mw-ts2-detail-header">
        <h2 class="mw-ts2-detail-title">
            <?php echo date('l, M j', strtotime($selDate)); ?>
            <?php if ($isSelToday): ?><span class="mw-ts2-today-pill">Today</span><?php endif; ?>
        </h2>
        <?php if ($selDaySec > 0): ?>
        <div class="mw-ts2-detail-total">
            <span class="mw-ts2-detail-total-label">Tracked</span>
            <span class="mw-ts2-detail-total-val" id="mwTs2DayTotal"><?php echo ts2Dur($selDaySec); ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Scheduled shift row -->
    <?php if ($todayShift): ?>
    <?php
    $sfStart = ts2Time($todayShift['start_time']);
    $sfEnd   = ts2Time($todayShift['end_time']);
    $sfMin   = (strtotime($todayShift['end_time']) - strtotime($todayShift['start_time'])) / 60;
    $sfH = (int)floor($sfMin / 60); $sfM = (int)$sfMin % 60;
    $sfDur = $sfM ? "{$sfH}h {$sfM}m" : "{$sfH}h";
    ?>
    <div class="mw-ts2-shift-banner">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <span>Scheduled <strong><?php echo $sfStart; ?> – <?php echo $sfEnd; ?></strong></span>
        <span class="mw-ts2-shift-dur"><?php echo $sfDur; ?></span>
    </div>
    <?php endif; ?>

    <!-- Clock entries + job breakdown -->
    <?php if (!$hasSelData): ?>
        <div class="mw-ts2-empty">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="8" y1="15" x2="16" y2="15"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
            <p>No time entries for <?php echo date('M j', strtotime($selDate)); ?></p>
        </div>
    <?php else: ?>

        <?php foreach ($selClocks as $ce):
            $isActive = ($ce['status'] === 'active' && !$ce['clock_out']);
            $ceDurSec = max(0, (int)$ce['duration_seconds']);
            $ceJobs   = $jobsByClock[(int)$ce['id']] ?? [];
        ?>
        <div class="mw-ts2-clock-block<?php echo $isActive ? ' mw-ts2-clock-block--live' : ''; ?>">

            <!-- Clock entry header -->
            <div class="mw-ts2-clock-head">
                <span class="mw-ts2-clock-pill<?php echo $isActive ? ' mw-ts2-clock-pill--live' : ''; ?>">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <?php echo $isActive ? 'Clocked In' : 'Shift'; ?>
                </span>
                <div class="mw-ts2-clock-times">
                    <span><?php echo ts2Time($ce['clock_in']); ?></span>
                    <span class="mw-ts2-arrow">→</span>
                    <span class="<?php echo $isActive ? 'mw-ts2-live-text' : ''; ?>">
                        <?php echo $isActive ? 'Now' : ($ce['clock_out'] ? ts2Time($ce['clock_out']) : '—'); ?>
                    </span>
                </div>
                <span class="mw-ts2-clock-dur<?php echo $isActive ? ' mw-ts2-live-text' : ''; ?>"
                      <?php echo $isActive ? 'id="mwTs2ClockDur"' : ''; ?>>
                    <?php echo ts2Dur($ceDurSec); ?>
                </span>
            </div>

            <!-- Job entries under this clock entry -->
            <?php if (!empty($ceJobs)): ?>
            <div class="mw-ts2-job-list">
                <?php foreach ($ceJobs as $je):
                    $jDurSec   = (int)$je['duration_minutes'] * 60;
                    $jIsActive = ($je['status'] === 'active' && !$je['end_time']);
                    $svcColor  = ts2SvcColor($je['service_type']);
                    $svcLabel  = ucwords(str_replace('_', ' ', $je['service_type'] ?: 'General'));
                ?>
                <div class="mw-ts2-job-row">
                    <span class="mw-ts2-job-dot" style="background:<?php echo htmlspecialchars($svcColor); ?>;"></span>
                    <div class="mw-ts2-job-info">
                        <span class="mw-ts2-job-name"><?php echo htmlspecialchars($je['job_title']); ?></span>
                        <span class="mw-ts2-job-svc"><?php echo htmlspecialchars($svcLabel); ?></span>
                    </div>
                    <div class="mw-ts2-job-right">
                        <span class="mw-ts2-job-times">
                            <?php echo ts2Time($je['start_time']); ?>
                            <?php if ($je['end_time']): ?> → <?php echo ts2Time($je['end_time']); ?>
                            <?php elseif ($jIsActive): ?> → <span class="mw-ts2-live-text">Now</span>
                            <?php endif; ?>
                        </span>
                        <span class="mw-ts2-job-dur<?php echo $jIsActive ? ' mw-ts2-live-text' : ''; ?>">
                            <?php echo $jIsActive ? '…' : ts2Dur($jDurSec); ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <!-- Orphan job entries (no parent clock entry) -->
        <?php if (!empty($selOrphans)): ?>
        <div class="mw-ts2-clock-block">
            <div class="mw-ts2-clock-head">
                <span class="mw-ts2-clock-pill">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                    Job Timers
                </span>
            </div>
            <div class="mw-ts2-job-list">
                <?php foreach ($selOrphans as $je):
                    $jDurSec  = (int)$je['duration_minutes'] * 60;
                    $svcColor = ts2SvcColor($je['service_type']);
                    $svcLabel = ucwords(str_replace('_', ' ', $je['service_type'] ?: 'General'));
                ?>
                <div class="mw-ts2-job-row">
                    <span class="mw-ts2-job-dot" style="background:<?php echo htmlspecialchars($svcColor); ?>;"></span>
                    <div class="mw-ts2-job-info">
                        <span class="mw-ts2-job-name"><?php echo htmlspecialchars($je['job_title']); ?></span>
                        <span class="mw-ts2-job-svc"><?php echo htmlspecialchars($svcLabel); ?></span>
                    </div>
                    <div class="mw-ts2-job-right">
                        <span class="mw-ts2-job-times">
                            <?php echo ts2Time($je['start_time']); ?><?php echo $je['end_time'] ? ' → ' . ts2Time($je['end_time']) : ''; ?>
                        </span>
                        <span class="mw-ts2-job-dur"><?php echo ts2Dur($jDurSec); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    <?php endif; ?>

    <!-- Week summary -->
    <div class="mw-ts2-week-summary">
        <div class="mw-ts2-ws-title">Week Overview</div>
        <?php foreach ($weekDays as $d):
            $dData  = $byDate[$d] ?? null;
            $dSec   = $dData ? $dData['total_sec'] : 0;
            $dClocks = $dData ? $dData['clock'] : [];
            $dOrphans = $dData ? $dData['orphan_jobs'] : [];
            $dJobs  = countDayJobs($dClocks, $jobsByClock, $dOrphans);
        ?>
        <a href="?date=<?php echo htmlspecialchars($d); ?>"
           class="mw-ts2-ws-row<?php echo $d === $selDate ? ' mw-ts2-ws-row--sel' : ''; ?><?php echo $d === $today ? ' mw-ts2-ws-row--today' : ''; ?>">
            <span class="mw-ts2-ws-day"><?php echo date('D M j', strtotime($d)); ?></span>
            <span class="mw-ts2-ws-jobs"><?php echo $dJobs > 0 ? $dJobs . ' job' . ($dJobs !== 1 ? 's' : '') : ''; ?></span>
            <span class="mw-ts2-ws-dur" id="mwTs2Week_<?php echo str_replace('-', '', $d); ?>"><?php echo $dSec > 0 ? ts2Dur($dSec) : '—'; ?></span>
        </a>
        <?php endforeach; ?>
        <div class="mw-ts2-ws-total">
            <span>Week Total</span>
            <span id="mwTs2WeekTotal"><?php echo ts2Dur((int)$weekTotalSec); ?></span>
        </div>
    </div>

    <!-- Back to My Schedule -->
    <div style="text-align:center; margin-top:8px; margin-bottom: 16px;">
        <a href="/crm/timeclock/my-schedule.php" style="font-size:0.82rem; color:var(--mw-green); text-decoration:none;">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
            Back to My Schedule
        </a>
    </div>

</div><!-- /.mw-ts2-page -->

<?php if ($activeClockEntry && $isThisWeek): ?>
<script>
(function () {
    'use strict';
    var clockDurEl  = document.getElementById('mwTs2ClockDur');
    var dayTotalEl  = document.getElementById('mwTs2DayTotal');
    var weekTotalEl = document.getElementById('mwTs2WeekTotal');
    var isSelToday  = <?php echo $isSelToday ? 'true' : 'false'; ?>;

    var initElapsed  = <?php echo max(0, (int)$activeClockEntry['duration_seconds']); ?>;
    var initDayTotal = <?php echo (int)$selDaySec; ?>;
    var initWeekTotal = <?php echo (int)$weekTotalSec; ?>;
    var tick = 0;

    function fmt(s) {
        if (s <= 0) return '0m';
        var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
        return h > 0 ? h + 'h' + (m > 0 ? ' ' + m + 'm' : '') : m + 'm';
    }

    setInterval(function () {
        tick++;
        if (clockDurEl)  clockDurEl.textContent  = fmt(initElapsed + tick);
        if (isSelToday && dayTotalEl)
            dayTotalEl.textContent  = fmt(initDayTotal + tick);
        if (weekTotalEl) weekTotalEl.textContent = fmt(initWeekTotal + tick);
    }, 1000);
})();
</script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
