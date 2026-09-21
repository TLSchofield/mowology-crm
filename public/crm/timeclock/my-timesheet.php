<?php
/**
 * My Timesheet — the signed-in employee's own week: shifts, the jobs inside them, and totals.
 *
 * Same data as the iOS "My Timesheet" screen: both read TimesheetService (one Monday→Sunday
 * week: clocked time, time on jobs, per-day shifts and jobs). All employees' timesheets for
 * the office stay at timesheets.php.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/timeclock-functions.php';
require_once APP_ROOT . '/Modules/Team/Services/TimesheetService.php';

requireLogin();
$user = getCurrentUser();

$today   = date('Y-m-d');
$rawWeek = isset($_GET['week']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['week']) ? (string)$_GET['week'] : $today;

$week       = TimesheetService::forUser((int)$user['id'], $rawWeek);
$weekStart  = $week['week_start'];
$weekEnd    = $week['week_end'];
$prevWeek   = date('Y-m-d', strtotime($weekStart . ' -7 days'));
$nextWeek   = date('Y-m-d', strtotime($weekStart . ' +7 days'));
$isThisWeek = ($weekStart === TimesheetService::weekStartFor($today));
$weekLabel  = date('M j', strtotime($weekStart)) . ' – ' . date('M j, Y', strtotime($weekEnd));

$openSeconds = 0;
foreach ($week['days'] as $d) {
    foreach ($d['entries'] as $e) {
        if ($e['is_open']) { $openSeconds = (int)$e['duration_seconds']; }
    }
}
$isClockedIn = $isThisWeek && $openSeconds > 0;
$hasHours    = $week['total_seconds'] > 0;

function mwTsDuration(int $seconds): string {
    if ($seconds <= 0) return '—';
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return $h > 0 ? $h . 'h' . ($m > 0 ? ' ' . $m . 'm' : '') : $m . 'm';
}
function mwTsTime(?string $dt): string {
    return $dt ? date('g:i a', strtotime($dt)) : '—';
}

$pageTitle  = 'My Timesheet';
$activePage = 'timeclock';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-myts-page">

    <div class="mw-myts-week-nav">
        <a href="?week=<?php echo h($prevWeek); ?>" class="mw-myts-week-arrow" aria-label="Previous week">&#8249;</a>
        <div class="mw-myts-week-label">
            <?php echo h($weekLabel); ?>
            <?php if ($isThisWeek): ?><small>This week</small><?php endif; ?>
        </div>
        <?php if (!$isThisWeek): ?>
            <a href="?week=<?php echo h($nextWeek); ?>" class="mw-myts-week-arrow" aria-label="Next week">&#8250;</a>
        <?php else: ?>
            <span class="mw-myts-week-arrow mw-myts-week-arrow--off" aria-hidden="true"></span>
        <?php endif; ?>
    </div>

    <!-- Totals: what you were paid for, and how much of it was on a job -->
    <div class="mw-myts-totals">
        <div class="mw-myts-totals-col">
            <div class="mw-myts-totals-value mw-myts-totals-value--clocked" id="mwMytsWeekTotal"><?php echo mwTsDuration((int)$week['total_seconds']); ?></div>
            <div class="mw-myts-totals-label">Clocked</div>
        </div>
        <div class="mw-myts-totals-col">
            <div class="mw-myts-totals-value"><?php echo mwTsDuration((int)$week['job_total_seconds']); ?></div>
            <div class="mw-myts-totals-label">On jobs</div>
        </div>
    </div>

    <?php if ($isThisWeek): ?>
    <div class="mw-myts-status <?php echo $isClockedIn ? 'mw-myts-status-on' : 'mw-myts-status-off'; ?>">
        <span class="mw-myts-status-dot"></span>
        <?php echo $isClockedIn ? 'Clocked in' : 'Not clocked in'; ?>
    </div>
    <?php endif; ?>

    <?php if (!$hasHours): ?>
        <div class="mw-myts-none">No hours recorded this week</div>
    <?php endif; ?>

    <?php foreach ($week['days'] as $day):
        if (empty($day['entries']) && empty($day['jobs'])) continue;
        $isToday = ($day['date'] === $today);
    ?>
    <div class="mw-myts-day">
        <div class="mw-myts-day-header">
            <span class="mw-myts-day-name<?php echo $isToday ? ' mw-myts-day-name-today' : ''; ?>">
                <?php echo h(date('l, M j', strtotime($day['date']))); ?><?php echo $isToday ? ' <small>(today)</small>' : ''; ?>
            </span>
            <span class="mw-myts-day-total"><?php echo mwTsDuration((int)$day['total_seconds']); ?></span>
        </div>

        <?php foreach ($day['entries'] as $entry): ?>
        <div class="mw-myts-entry<?php echo $entry['is_open'] ? ' mw-myts-entry-active' : ''; ?>">
            <div class="mw-myts-entry-times">
                <span><?php echo h(mwTsTime($entry['clock_in'])); ?></span>
                <span class="mw-myts-entry-arrow">&rarr;</span>
                <span><?php echo $entry['is_open'] ? 'Now' : h(mwTsTime($entry['clock_out'])); ?></span>
                <?php if ($entry['edited']): ?><span class="mw-myts-tag">Edited</span><?php endif; ?>
                <?php if ($entry['notes'] && stripos((string)$entry['notes'], 'auto-completed') !== false): ?>
                    <span class="mw-myts-tag mw-myts-tag--warn">Auto clock-out</span>
                <?php endif; ?>
            </div>
            <span class="mw-myts-entry-dur"><?php echo mwTsDuration((int)$entry['duration_seconds']); ?></span>
        </div>
        <?php endforeach; ?>

        <?php if (!empty($day['jobs'])): ?>
        <div class="mw-myts-jobs">
            <div class="mw-myts-jobs-head">
                <span>Jobs</span>
                <span><?php echo mwTsDuration((int)$day['job_seconds']); ?></span>
            </div>
            <?php foreach ($day['jobs'] as $job):
                $primary   = $job['property_address'] ?: ($job['job_title'] ?: 'Visit #' . (int)$job['visit_id']);
                $secondary = $job['property_address'] ? $job['job_title'] : null;
            ?>
            <div class="mw-myts-job">
                <div class="mw-myts-job-main">
                    <div class="mw-myts-job-title"><?php echo h($primary); ?></div>
                    <div class="mw-myts-job-sub">
                        <?php echo $secondary ? h($secondary) . ' · ' : ''; ?>
                        <?php echo h(mwTsTime($job['start_time'])); ?> &ndash; <?php echo $job['end_time'] ? h(mwTsTime($job['end_time'])) : 'running'; ?>
                    </div>
                </div>
                <span class="mw-myts-job-dur"><?php echo mwTsDuration((int)$job['duration_seconds']); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <p class="mw-myts-foot">Something wrong with these hours? Tell the office &mdash; they can correct a shift, and it will show here as Edited.</p>

</div><!-- /.mw-myts-page -->

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
