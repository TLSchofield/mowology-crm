<?php
/**
 * My Timesheet — current employee's own clock entries for the selected week.
 * Accessible to any logged-in user (shows only their own data).
 * Admin/manager timesheets (all employees) remain at timesheets.php.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/timeclock-functions.php';

requireLogin();
$user = getCurrentUser();
$db   = getDB();

// ── Week navigation ───────────────────────────────────────────────────────────
$today      = date('Y-m-d');
$rawWeek    = isset($_GET['week']) ? $_GET['week'] : $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawWeek)) $rawWeek = $today;

$weekStart  = date('Y-m-d', strtotime('monday this week', strtotime($rawWeek)));
$weekEnd    = date('Y-m-d', strtotime('sunday this week', strtotime($rawWeek)));
$prevWeek   = date('Y-m-d', strtotime($weekStart . ' -7 days'));
$nextWeek   = date('Y-m-d', strtotime($weekStart . ' +7 days'));
$isThisWeek = ($weekStart === date('Y-m-d', strtotime('monday this week')));

$weekLabel  = date('M j', strtotime($weekStart)) . ' – ' . date('M j, Y', strtotime($weekEnd));

// ── Clock entries for this week ───────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT id, clock_in, clock_out, status, notes,
           TIMESTAMPDIFF(SECOND, clock_in, COALESCE(clock_out, NOW())) AS duration_seconds
    FROM   time_clock_entries
    WHERE  user_id = ?
      AND  DATE(clock_in) BETWEEN ? AND ?
    ORDER  BY clock_in ASC
");
$stmt->execute([$user['id'], $weekStart, $weekEnd]);
$allEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group entries by date and accumulate totals
$byDate       = [];
$weekTotalSec = 0;
$activeEntry  = null;

foreach ($allEntries as $entry) {
    $d = date('Y-m-d', strtotime($entry['clock_in']));
    if (!isset($byDate[$d])) $byDate[$d] = [];
    $byDate[$d][] = $entry;
    $weekTotalSec += max(0, (int)$entry['duration_seconds']);
    if ($entry['status'] === 'active' && !$entry['clock_out']) {
        $activeEntry = $entry;
    }
}

// ── Current clock status (for header badge) ───────────────────────────────────
$isClockedIn         = ($activeEntry !== null);
$clockElapsedSeconds = $isClockedIn ? max(0, (int)$activeEntry['duration_seconds']) : 0;

// ── Build the 7-day week skeleton (Mon → Sun) ─────────────────────────────────
$weekDays = [];
for ($i = 0; $i < 7; $i++) {
    $d = date('Y-m-d', strtotime($weekStart . " +{$i} days"));
    $weekDays[] = $d;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmtDuration(int $seconds): string {
    if ($seconds <= 0) return '—';
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    if ($h > 0) return $h . 'h ' . ($m > 0 ? $m . 'm' : '');
    return $m . 'm';
}
function fmtTime(string $dt): string {
    return date('g:i a', strtotime($dt));
}

// ── Page setup ────────────────────────────────────────────────────────────────
$pageTitle  = 'My Timesheet';
$activePage = 'schedule';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<style>
/* ── My Timesheet page — mobile-first clean layout ── */
.mw-ts-page { max-width: 640px; margin: 0 auto; padding-bottom: 32px; }

/* Week header */
.mw-ts-week-nav {
    display: flex; align-items: center; justify-content: space-between;
    gap: 8px; margin-bottom: 20px;
}
.mw-ts-week-arrow {
    display: flex; align-items: center; justify-content: center;
    width: 40px; height: 40px; border-radius: 10px;
    background: #f4f4f4; color: #555; text-decoration: none;
    flex-shrink: 0; transition: background 0.15s;
}
.mw-ts-week-arrow:hover { background: #e8e8e8; text-decoration: none; }
.mw-ts-week-label {
    flex: 1; text-align: center; font-size: 0.92rem; font-weight: 700;
    color: #1a1a1a; font-family: 'Montserrat', sans-serif;
}

/* Clock status pill */
.mw-ts-status {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 8px 16px; border-radius: 24px; font-size: 0.82rem;
    font-weight: 700; font-family: 'Montserrat', sans-serif;
    margin-bottom: 20px;
}
.mw-ts-status-on  { background: #dcfce7; color: #15803d; }
.mw-ts-status-off { background: #fee2e2; color: #b91c1c; }
.mw-ts-status-dot {
    width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
}
.mw-ts-status-on  .mw-ts-status-dot { background: #22c55e; animation: mw-ts-pulse 2s ease-in-out infinite; }
.mw-ts-status-off .mw-ts-status-dot { background: #ef4444; }
@keyframes mw-ts-pulse {
    0%,100% { box-shadow: 0 0 0 0 rgba(34,197,94,0.5); }
    50%      { box-shadow: 0 0 0 6px rgba(34,197,94,0); }
}

/* Day cards */
.mw-ts-day {
    background: #fff; border-radius: 14px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    margin-bottom: 10px; overflow: hidden;
}
.mw-ts-day-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 16px;
}
.mw-ts-day-name {
    font-size: 0.85rem; font-weight: 700; color: #1a1a1a;
    font-family: 'Montserrat', sans-serif;
}
.mw-ts-day-name-today { color: var(--mw-green, #2D8659); }
.mw-ts-day-total {
    font-size: 0.78rem; font-weight: 700; color: #666;
    font-family: 'Montserrat', sans-serif;
}
.mw-ts-day-empty .mw-ts-day-header { padding-bottom: 12px; }
.mw-ts-day-empty-lbl {
    font-size: 0.75rem; color: #bbb; padding: 0 16px 12px;
}

/* Entry rows */
.mw-ts-entry {
    display: flex; align-items: center; gap: 12px;
    padding: 9px 16px; border-top: 1px solid #f4f4f4;
}
.mw-ts-entry-times {
    flex: 1; display: flex; align-items: center; gap: 6px;
    font-size: 0.82rem; color: #444;
}
.mw-ts-entry-arrow { color: #ccc; font-size: 0.75rem; }
.mw-ts-entry-active .mw-ts-entry-out { color: var(--mw-green, #2D8659); font-weight: 700; }
.mw-ts-entry-dur {
    font-size: 0.78rem; font-weight: 700; color: #888;
    white-space: nowrap; min-width: 44px; text-align: right;
}
.mw-ts-entry-active .mw-ts-entry-dur {
    color: var(--mw-green, #2D8659);
    font-variant-numeric: tabular-nums;
}

/* Week total card */
.mw-ts-total {
    display: flex; align-items: center; justify-content: space-between;
    background: var(--mw-forest, #0D3B2E); color: #fff;
    border-radius: 14px; padding: 16px 20px; margin-top: 16px;
}
.mw-ts-total-label { font-size: 0.8rem; font-weight: 600; opacity: 0.75; }
.mw-ts-total-hours { font-size: 1.4rem; font-weight: 800; font-family: 'Montserrat', sans-serif; }
.mw-ts-total-sub   { font-size: 0.7rem; opacity: 0.6; margin-top: 2px; }
</style>

<div class="mw-ts-page">

    <!-- Week navigation -->
    <div class="mw-ts-week-nav">
        <a href="?week=<?php echo htmlspecialchars($prevWeek); ?>" class="mw-ts-week-arrow" aria-label="Previous week">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        </a>
        <span class="mw-ts-week-label"><?php echo htmlspecialchars($weekLabel); ?></span>
        <?php if (!$isThisWeek): ?>
        <a href="?week=<?php echo htmlspecialchars($today); ?>" class="mw-ts-week-arrow" title="Jump to this week">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <?php else: ?>
        <div class="mw-ts-week-arrow" style="background:transparent;"></div>
        <?php endif; ?>
    </div>

    <!-- Clock status -->
    <?php if ($isThisWeek): ?>
    <div class="mw-ts-status <?php echo $isClockedIn ? 'mw-ts-status-on' : 'mw-ts-status-off'; ?>">
        <span class="mw-ts-status-dot"></span>
        <?php if ($isClockedIn): ?>
            Clocked in — <span id="mwTsElapsed"><?php
                $h = floor($clockElapsedSeconds / 3600);
                $m = floor(($clockElapsedSeconds % 3600) / 60);
                $s = $clockElapsedSeconds % 60;
                echo $h > 0 ? $h.':'.(str_pad($m,2,'0',STR_PAD_LEFT)).':'.(str_pad($s,2,'0',STR_PAD_LEFT))
                             : $m.':'.(str_pad($s,2,'0',STR_PAD_LEFT));
            ?></span>
        <?php else: ?>
            Not clocked in
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Day cards -->
    <?php foreach ($weekDays as $dayDate):
        $isToday    = ($dayDate === $today);
        $entries    = $byDate[$dayDate] ?? [];
        $daySeconds = array_sum(array_column($entries, 'duration_seconds'));
        $dayLabel   = date('l, M j', strtotime($dayDate)); // "Monday, Mar 3"
        $isEmpty    = empty($entries);
    ?>
    <div class="mw-ts-day<?php echo $isEmpty ? ' mw-ts-day-empty' : ''; ?>">
        <div class="mw-ts-day-header">
            <span class="mw-ts-day-name<?php echo $isToday ? ' mw-ts-day-name-today' : ''; ?>">
                <?php echo htmlspecialchars($dayLabel); echo $isToday ? ' <small style="font-weight:500;opacity:.6;">(today)</small>' : ''; ?>
            </span>
            <?php if (!$isEmpty): ?>
            <span class="mw-ts-day-total"><?php echo fmtDuration((int)$daySeconds); ?></span>
            <?php endif; ?>
        </div>
        <?php if ($isEmpty): ?>
            <div class="mw-ts-day-empty-lbl">No entries</div>
        <?php else: ?>
            <?php foreach ($entries as $entry):
                $isActive = ($entry['status'] === 'active' && !$entry['clock_out']);
            ?>
            <div class="mw-ts-entry<?php echo $isActive ? ' mw-ts-entry-active' : ''; ?>">
                <div class="mw-ts-entry-times">
                    <span class="mw-ts-entry-in"><?php echo fmtTime($entry['clock_in']); ?></span>
                    <span class="mw-ts-entry-arrow">→</span>
                    <span class="mw-ts-entry-out">
                        <?php echo $isActive ? 'Now' : ($entry['clock_out'] ? fmtTime($entry['clock_out']) : '—'); ?>
                    </span>
                </div>
                <span class="mw-ts-entry-dur" <?php echo $isActive ? 'id="mwTsEntryDur"' : ''; ?>>
                    <?php echo fmtDuration((int)$entry['duration_seconds']); ?>
                </span>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <!-- Weekly total -->
    <div class="mw-ts-total">
        <div>
            <div class="mw-ts-total-label">Week total</div>
            <div class="mw-ts-total-sub"><?php echo htmlspecialchars($weekLabel); ?></div>
        </div>
        <div class="mw-ts-total-hours" id="mwTsWeekTotal">
            <?php echo fmtDuration((int)$weekTotalSec); ?>
        </div>
    </div>

</div><!-- /.mw-ts-page -->

<?php if ($isClockedIn && $isThisWeek): ?>
<script>
// Live tick for the clocked-in entry
(function () {
    'use strict';
    var elapsedEl  = document.getElementById('mwTsElapsed');
    var entryDurEl = document.getElementById('mwTsEntryDur');
    var totalEl    = document.getElementById('mwTsWeekTotal');

    var elapsedSec = <?php echo (int)$clockElapsedSeconds; ?>;
    var weekSec    = <?php echo (int)$weekTotalSec; ?>;

    function fmtElapsed(s) {
        var h  = Math.floor(s / 3600);
        var m  = Math.floor((s % 3600) / 60);
        var ss = s % 60;
        var pad = function(n) { return n < 10 ? '0' + n : '' + n; };
        return h > 0 ? h + ':' + pad(m) + ':' + pad(ss) : m + ':' + pad(ss);
    }

    function fmtDur(s) {
        if (s <= 0) return '0m';
        var h = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        if (h > 0) return h + 'h ' + (m > 0 ? m + 'm' : '');
        return m + 'm';
    }

    setInterval(function () {
        elapsedSec++;
        weekSec++;
        if (elapsedEl)  elapsedEl.textContent  = fmtElapsed(elapsedSec);
        if (entryDurEl) entryDurEl.textContent = fmtDur(elapsedSec);
        if (totalEl)    totalEl.textContent    = fmtDur(weekSec);
    }, 1000);
})();
</script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
