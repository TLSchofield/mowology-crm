<?php
/**
 * Day Summary Card Partial — Weekly Schedule "Battle Card" view
 *
 * Renders the .mw-dsc card shown at the top of each day column: revenue,
 * margin, density, drive time, time-load bar, and the 4-signal Route
 * GO/Caution/No-Go feasibility verdict.
 *
 * Extracted from schedule.php so both the page's weekly render loop and
 * reschedule-stop.php (to refresh a day's card after a drag, without a
 * full page reload) can render it for one date at a time.
 *
 * Expected variables:
 *   $dateStr — 'Y-m-d' string for this day
 *   $isToday — bool
 *   $bcData  — return value of computeDayBattleCard($dateStr)
 */

$bcMargin  = $bcData['margin']       ?? null;
$bcDensity = $bcData['density']      ?? 0;
$bcOutlier = $bcData['outlier']      ?? false;
$bcRev     = $bcData['revenue']      ?? 0;
$bcStops   = $bcData['stops']        ?? 0;
$bcDrive   = $bcData['drive_min']    ?? 0;

$bcVerdict = $bcData['verdict']  ?? 'empty';
$bcIssues  = $bcData['issues']   ?? [];
$bcLoadPct = $bcData['load_pct'] ?? 0;
$bcSignals = $bcData['signals']  ?? [];

$bcLabelMap = ['go' => 'Route GO', 'caution' => 'Caution', 'no-go' => 'No-Go'];
$bcIconMap  = ['go' => '&#10003;', 'caution' => '&#9888;', 'no-go' => '&#10005;'];
$bcVerdictLabel = $bcLabelMap[$bcVerdict] ?? '';
$bcVerdictIcon  = $bcIconMap[$bcVerdict]  ?? '';
$bcTooltip = empty($bcIssues)
    ? ($bcVerdict === 'go' ? "All systems go · {$bcLoadPct}% capacity" : '')
    : implode(' · ', $bcIssues);

$dscTier = 'grey';
if ($bcMargin !== null) {
    $dscTier = $bcMargin >= 30 ? 'green' : ($bcMargin >= 15 ? 'amber' : 'red');
}
$dscLoss = ($bcMargin !== null && $bcMargin < 0);

$dscDensityClass = $bcDensity >= 70 ? '' : ($bcDensity >= 40 ? 'dv-amber' : 'dv-red');
if ($bcStops === 0) $dscDensityClass = 'dv-grey';

$dscDriveClass = $bcDrive <= 30 ? '' : ($bcDrive <= 60 ? 'drv-amber' : 'drv-red');
if ($bcStops === 0) $dscDriveClass = 'drv-grey';

$dscMarginFill = ($bcMargin !== null) ? max(0, min(100, $bcMargin)) : 0;

$dscJobMin   = (int)($bcData['duration_min'] ?? 0);
$dscDriveMin = (int)$bcDrive;
$dscTotalMin = $dscJobMin + $dscDriveMin;
$dscCapacity = 480; // 8-hour day in minutes
$dscJobPct   = min(85, round(($dscJobMin / max(1, $dscCapacity)) * 100));
$dscDrivePct = min(85 - $dscJobPct, round(($dscDriveMin / max(1, $dscCapacity)) * 100));
$dscDriveOverload = ($dscDriveMin > 0 && $dscJobMin > 0 && $dscDriveMin >= $dscJobMin);
$dscTotalH   = intdiv($dscTotalMin, 60);
$dscTotalM   = $dscTotalMin % 60;
$dscTimeTotalLabel = $dscTotalH > 0 ? "{$dscTotalH}h {$dscTotalM}m" : "{$dscTotalM}m";
$dscTimeTierClass  = $dscTotalMin <= 240 ? 'tl-green' : ($dscTotalMin <= 360 ? 'tl-amber' : 'tl-red');
if ($bcStops === 0) $dscTimeTierClass = 'tl-grey';
?>
<?php if ($bcStops > 0): ?>
<div class="mw-dsc dsc-tier-<?php echo $dscTier; ?><?php echo $dscLoss ? ' dsc-loss' : ''; ?>"
     title="<?php echo htmlspecialchars("Revenue \${$bcRev} · Margin {$bcMargin}% · Density {$bcDensity}/100"); ?>">

    <!-- Row 1: Revenue + TODAY badge + stop count -->
    <div class="mw-dsc-top">
        <span class="mw-dsc-revenue">$<?php echo number_format($bcRev, 0); ?></span>
        <?php if ($isToday): ?>
        <span class="mw-dsc-today-badge">Today</span>
        <?php endif; ?>
        <?php if (!empty($bcData['has_crew_overlap'])): ?>
        <span class="mw-crew-overlap-badge" title="A crew member has multiple stops on this day">&#9888;</span>
        <?php endif; ?>
        <?php if ($dscLoss): ?>
        <span class="mw-dsc-outlier-flag" title="Loss day">&#9888;</span>
        <?php elseif ($bcOutlier): ?>
        <span class="mw-dsc-outlier-flag" title="Low-margin stop">&#9888;</span>
        <?php endif; ?>
        <span class="mw-dsc-stops"><?php echo $bcStops; ?> stop<?php echo $bcStops !== 1 ? 's' : ''; ?></span>
    </div>

    <!-- Row 2: Margin pill + fill bar -->
    <?php if ($bcMargin !== null): ?>
    <div class="mw-dsc-margin-row">
        <span class="mw-dsc-margin-pill"><?php echo $bcMargin; ?>%</span>
        <div class="mw-dsc-margin-bar-track">
            <div class="mw-dsc-margin-bar-fill" style="width:<?php echo $dscMarginFill; ?>%"></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Row 3: Density bar + Drive time -->
    <div class="mw-dsc-metrics">
        <div class="mw-dsc-density">
            <div class="mw-dsc-density-header">
                <span class="mw-dsc-density-label">Density</span>
                <span class="mw-dsc-density-val <?php echo $dscDensityClass; ?>"><?php echo $bcDensity; ?></span>
            </div>
            <div class="mw-dsc-density-track">
                <div class="mw-dsc-density-fill" style="width:<?php echo $bcDensity; ?>%;background:<?php
                    echo $bcDensity >= 70 ? 'linear-gradient(90deg,#34D399,#2D8659)' :
                        ($bcDensity >= 40 ? 'linear-gradient(90deg,#FCD34D,#F59E0B)' : 'linear-gradient(90deg,#F87171,#DC2626)');
                ?>"></div>
            </div>
        </div>
        <div class="mw-dsc-drive">
            <span class="mw-dsc-drive-val <?php echo $dscDriveClass; ?>"><?php echo $bcDrive; ?>m</span>
            <span class="mw-dsc-drive-label">
                <svg width="8" height="8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                drive
            </span>
        </div>
    </div>

    <!-- Row 4: Time load segmented bar -->
    <?php if ($dscTotalMin > 0): ?>
    <div class="mw-dsc-divider"></div>
    <div class="mw-dsc-time-row">
        <div class="mw-dsc-time-header">
            <span class="mw-dsc-time-label">Time load</span>
            <span class="mw-dsc-time-total <?php echo $dscTimeTierClass; ?>"><?php echo $dscTimeTotalLabel; ?></span>
        </div>
        <div class="mw-dsc-time-track">
            <div class="mw-dsc-seg-job" style="width:<?php echo $dscJobPct; ?>%"></div>
            <div class="mw-dsc-seg-drive<?php echo $dscDriveOverload ? ' is-overload' : ''; ?>" style="width:<?php echo $dscDrivePct; ?>%"></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Feasibility verdict row -->
    <?php if ($bcVerdict !== 'empty'): ?>
    <div class="mw-dsc-divider"></div>
    <div class="mw-bc-feasibility mw-bc-feas-<?php echo $bcVerdict; ?>"
         title="<?php echo htmlspecialchars($bcTooltip); ?>">
        <span class="mw-bc-feas-icon"><?php echo $bcVerdictIcon; ?></span>
        <span class="mw-bc-feas-label"><?php echo $bcVerdictLabel; ?></span>
        <?php if (!empty($bcIssues)): ?>
        <span class="mw-bc-feas-issues"><?php echo count($bcIssues); ?> issue<?php echo count($bcIssues) > 1 ? 's' : ''; ?></span>
        <?php else: ?>
        <span class="mw-bc-feas-pct"><?php echo $bcLoadPct; ?>%</span>
        <?php endif; ?>
        <div class="mw-bc-feas-signals">
            <span class="mw-bc-sig mw-bc-sig-<?php echo $bcSignals['crew'] ?? 'grey'; ?>" title="Crew">&#128100;</span>
            <span class="mw-bc-sig mw-bc-sig-<?php echo $bcSignals['load'] ?? 'grey'; ?>" title="Load">&#128202;</span>
            <span class="mw-bc-sig mw-bc-sig-<?php echo $bcSignals['weather'] ?? 'grey'; ?>" title="Weather">&#127780;</span>
            <span class="mw-bc-sig mw-bc-sig-<?php echo $bcSignals['blocked'] ?? 'grey'; ?>" title="Blocked">&#128683;</span>
        </div>
    </div>
    <?php endif; ?>

    <!-- Bottom profit meter -->
    <?php if ($bcMargin !== null): ?>
    <div class="mw-dsc-profit-meter" title="Margin: <?php echo $bcMargin; ?>%">
        <div class="mw-dsc-profit-fill" style="width:<?php echo $dscMarginFill; ?>%"></div>
    </div>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="mw-dsc dsc-empty">
    <div class="mw-dsc-empty-label">No stops</div>
</div>
<?php endif; ?>
