<?php
/**
 * Payroll Summary — Bi-weekly (or custom range) pay breakdown for all employees.
 * Computes BC overtime, shows gross pay per employee, supports CSV export.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/timeclock-functions.php';
require_once APP_ROOT . '/Modules/Team/Services/OvertimeCalculator.php';
require_once APP_ROOT . '/Core/Csv.php';   // csvPut(): formula-safe cells (audit #18)
require_once APP_ROOT . '/Modules/Operations/Services/BreakService.php';

requireLogin();
$user = getCurrentUser();
requirePermission('timer.override');

$db = getDB();

// ── Date range ────────────────────────────────────────────────────────────────
// Default: the last COMPLETE bi-weekly period, Monday to Sunday like the timesheets (so "approve all" only
// ever covers whole timesheets), ending on the most recent Sunday before today. BC's overtime week runs
// Sunday–Saturday, so the Sunday before the period's first Monday is passed in as a prior day that counts
// toward the 40 h without being paid again (Codex review 2026-10-23).
$today     = new DateTime('today');
$lastSun   = new DateTime('last sunday');
$defaultEnd   = $lastSun->format('Y-m-d');
$defaultStart = (clone $lastSun)->modify('-13 days')->format('Y-m-d');

$rawStart = isset($_GET['start']) ? $_GET['start'] : $defaultStart;
$rawEnd   = isset($_GET['end'])   ? $_GET['end']   : $defaultEnd;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawStart)) $rawStart = $defaultStart;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawEnd))   $rawEnd   = $defaultEnd;

// Clamp: end must be >= start
if ($rawEnd < $rawStart) $rawEnd = $rawStart;

$rangeStart = $rawStart;
$rangeEnd   = $rawEnd;

// ── CSV export ────────────────────────────────────────────────────────────────
$doExport = isset($_GET['export']) && $_GET['export'] === 'csv';

// ── Fetch all active employees with hourly rate ───────────────────────────────
$empStmt = $db->prepare("
    SELECT id, full_name, email, hourly_rate, role
    FROM users
    WHERE is_active = 1
      -- Every person who clocks in gets paid. This used to filter on role IN
      -- ('admin','manager','team_member'), but crew are created with role 'user',
      -- so they silently dropped off the wages page. Truck tablets are not people.
      AND IFNULL(device_type, 'personal') <> 'truck'
    ORDER BY full_name ASC
");
$empStmt->execute();
$employees = $empStmt->fetchAll(PDO::FETCH_ASSOC);

// Breaks (BreakService, 2026-10-13): shown per person; when breaks are UNPAID (ops_settings
// breaks_paid = 0) tapped break minutes come off each day BEFORE the daily/weekly overtime split.
// Mowology pays breaks, so its hours and pay are unchanged.
$breakSvc   = new BreakService($db);
$breaksPaid = $breakSvc->breaksPaid();
$grandTotalBreak  = 0;
$grandTotalUnpaid = 0;

// ── For each employee, fetch daily minutes and compute pay ────────────────────
$rows = [];
$grandTotalReg  = 0;
$grandTotalOt15 = 0;
$grandTotalOt20 = 0;
$grandTotalPay  = 0;

foreach ($employees as $emp) {
    // Per-day shift minutes inside the range (the same rows and statuses recalculateTimesheetTotals()
    // sums into timesheets.total_shift_minutes), then BC overtime per day and per Sunday–Saturday
    // week (BC ESA) via OvertimeCalculator::calculatePeriod(). Until 2026-10-12 this summed the stored
    // weekly totals of every timesheet overlapping the range and applied the 40 h rule ONCE to the
    // whole (bi-weekly) lump, with no daily 8 h / 12 h rule — the 1.5×/2× split was wrong.
    $dayStmt = $db->prepare("
        SELECT DATE(clock_in) AS day, SUM(total_minutes) AS day_min
        FROM time_clock_entries
        WHERE user_id = ?
          AND clock_in >= ? AND clock_in < DATE_ADD(?, INTERVAL 1 DAY)
          AND status IN ('completed', 'edited')
        GROUP BY DATE(clock_in)
    ");
    $dayStmt->execute([$emp['id'], $rangeStart, $rangeEnd]);
    $dailyMinutes = [];
    foreach ($dayStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $dailyMinutes[$d['day']] = max(0, (int)$d['day_min']);
    }

    $clockedMin = array_sum($dailyMinutes);
    if ($clockedMin <= 0) continue; // no hours this period

    $brk = $breakSvc->periodForUser((int)$emp['id'], $rangeStart, $rangeEnd);
    foreach ($brk['days'] as $bd => $bm) {
        if (isset($dailyMinutes[$bd])) {
            $dailyMinutes[$bd] = BreakService::paidMinutes($dailyMinutes[$bd], (int)$bm['unpaid_minutes']);
        }
    }
    $totalShiftMin = array_sum($dailyMinutes);   // paid hours (= clocked when breaks are paid)

    $rate = (float)($emp['hourly_rate'] ?? 0);
    // Days of the first Sunday–Saturday week before the range (already paid): counted toward the 40 h only.
    $priorDays = [];
    $wkStart = date('Y-m-d', strtotime('-' . (int)date('w', strtotime($rangeStart)) . ' days', strtotime($rangeStart)));
    if ($wkStart < $rangeStart) {
        $dayStmt->execute([$emp['id'], $wkStart, date('Y-m-d', strtotime($rangeStart . ' -1 day'))]);
        foreach ($dayStmt->fetchAll(PDO::FETCH_ASSOC) as $d) $priorDays[$d['day']] = max(0, (int)$d['day_min']);
        $pbrk = $breakSvc->periodForUser((int)$emp['id'], $wkStart, date('Y-m-d', strtotime($rangeStart . ' -1 day')));
        foreach ($pbrk['days'] as $bd => $bm) if (isset($priorDays[$bd])) $priorDays[$bd] = BreakService::paidMinutes($priorDays[$bd], (int)$bm['unpaid_minutes']);
    }
    $pay  = OvertimeCalculator::calculatePeriod($dailyMinutes, $rate, $priorDays);

    // Fetch individual timesheet rows for status + IDs (separate query for correctness)
    $tsStmt = $db->prepare("
        SELECT id, status
        FROM timesheets
        WHERE user_id = ?
          AND week_start <= ?
          AND week_end   >= ?
        ORDER BY week_start ASC
    ");
    $tsStmt->execute([$emp['id'], $rangeEnd, $rangeStart]);
    $weeklySheets = $tsStmt->fetchAll(PDO::FETCH_ASSOC);

    $sheetStatuses = array_column($weeklySheets, 'status');
    $overallStatus = 'no_timesheet';
    if (!empty($sheetStatuses)) {
        if (in_array('rejected', $sheetStatuses))       $overallStatus = 'rejected';
        elseif (in_array('pending', $sheetStatuses))    $overallStatus = 'pending';
        elseif (in_array('submitted', $sheetStatuses))  $overallStatus = 'submitted';
        else                                             $overallStatus = 'approved';
    }

    $rows[] = [
        'emp'          => $emp,
        'pay'          => $pay,
        'total_shift'  => $totalShiftMin,
        'clocked'      => $clockedMin,
        'break_min'    => (int)$brk['break_minutes'],
        'unpaid_min'   => (int)$brk['unpaid_minutes'],
        'break_count'  => (int)$brk['count'],
        'status'       => $overallStatus,
        'timesheet_ids'=> array_column($weeklySheets, 'id'),
        'rate'         => $rate,
    ];

    $grandTotalReg  += $pay['regular_minutes'];
    $grandTotalOt15 += $pay['ot_1_5_minutes'];
    $grandTotalOt20 += $pay['ot_2_0_minutes'];
    $grandTotalPay  += $pay['gross_pay'];
    $grandTotalBreak  += (int)$brk['break_minutes'];
    $grandTotalUnpaid += (int)$brk['unpaid_minutes'];
}

// ── CSV Export ────────────────────────────────────────────────────────────────
if ($doExport) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payroll-' . $rangeStart . '-to-' . $rangeEnd . '.csv"');
    $out = fopen('php://output', 'w');

    csvPut($out, [
        'Employee', 'Email', 'Rate ($/h)',
        'Total Hours', 'Regular Hours', 'OT 1.5x Hours', 'OT 2x Hours',
        'Regular Pay', 'OT 1.5x Pay', 'OT 2x Pay', 'Gross Pay', 'Status',
        'Period Start', 'Period End',
        'Clocked Hours', 'Break Hours', 'Unpaid Break Hours (deducted)', 'Breaks Paid',
    ]);

    foreach ($rows as $r) {
        $pay = $r['pay'];
        csvPut($out, [
            $r['emp']['full_name'],
            $r['emp']['email'],
            number_format($r['rate'], 2),
            OvertimeCalculator::minutesToHours($r['total_shift']),
            OvertimeCalculator::minutesToHours($pay['regular_minutes']),
            OvertimeCalculator::minutesToHours($pay['ot_1_5_minutes']),
            OvertimeCalculator::minutesToHours($pay['ot_2_0_minutes']),
            number_format($pay['regular_pay'], 2),
            number_format($pay['ot_1_5_pay'], 2),
            number_format($pay['ot_2_0_pay'], 2),
            number_format($pay['gross_pay'], 2),
            $r['status'],
            $rangeStart,
            $rangeEnd,
            OvertimeCalculator::minutesToHours($r['clocked']),
            OvertimeCalculator::minutesToHours($r['break_min']),
            OvertimeCalculator::minutesToHours($r['unpaid_min']),
            $breaksPaid ? 'yes' : 'no',
        ]);
    }

    // Totals row
    csvPut($out, [
        'TOTAL', '', '',
        '',
        OvertimeCalculator::minutesToHours($grandTotalReg),
        OvertimeCalculator::minutesToHours($grandTotalOt15),
        OvertimeCalculator::minutesToHours($grandTotalOt20),
        '', '', '',
        number_format($grandTotalPay, 2),
        '', '', '',
        '',
        OvertimeCalculator::minutesToHours($grandTotalBreak),
        OvertimeCalculator::minutesToHours($grandTotalUnpaid),
        $breaksPaid ? 'yes' : 'no',
    ]);

    fclose($out);
    exit;
}

// ── Bulk approve (POST) ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_approve'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (verifyCSRFToken($csrfToken)) {
        $ids = array_map('intval', (array)($_POST['timesheet_ids'] ?? []));
        if (!empty($ids)) {
            // The same checks as approving one week (Codex review 2026-10-14): not your own,
            // no timer still running, totals recalculated first.
            require_once APP_ROOT . '/Modules/Team/Services/TimesheetApprovalService.php';
            $res = (new TimesheetApprovalService($db))->approveMany($ids, (int)$user['id']);
            $_SESSION['flash_success'] = $res['approved'] . ' timesheet' . ($res['approved'] === 1 ? '' : 's') . ' approved.';
            if ($res['refused']) {
                $_SESSION['flash_error'] = count($res['refused']) . ' not approved: ' . implode(' ', array_unique(array_values($res['refused'])));
            }
        }
    }
    header('Location: /crm/timeclock/payroll-summary.php?start=' . urlencode($rangeStart) . '&end=' . urlencode($rangeEnd));
    exit;
}

// ── Preset date helpers ───────────────────────────────────────────────────────
// Current bi-weekly: most recent completed 2-week block
$curBiStart = (new DateTime('last sunday'))->modify('-13 days')->format('Y-m-d');
$curBiEnd   = (new DateTime($curBiStart))->modify('+13 days')->format('Y-m-d');
// Previous bi-weekly
$prevBiStart = (new DateTime($curBiStart))->modify('-14 days')->format('Y-m-d');
$prevBiEnd   = (new DateTime($prevBiStart))->modify('+13 days')->format('Y-m-d');
// Current month
$curMonStart = date('Y-m-01');
$curMonEnd   = date('Y-m-t');

$pageTitle  = 'Payroll Summary';
$activePage = 'payroll';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>
<?php include dirname(__DIR__) . '/includes/flash.php'; ?>

<!-- Header -->
<div class="mw-payroll-header">
    <div>
        <h1 class="h3 mb-1">Payroll Summary</h1>
        <p class="text-muted mb-0">BC overtime rules applied &bull; OT: &gt;8h/day = 1.5&times;, &gt;12h/day = 2&times;, &gt;40h/wk = 1.5&times;</p>
        <p class="text-muted small mb-0">
            <?php if ($breaksPaid): ?>
                Breaks are <strong>paid</strong> &mdash; break time stays in paid hours.
            <?php else: ?>
                Breaks are <strong>unpaid</strong> &mdash; tapped break time is taken off each day before overtime is worked out.
            <?php endif; ?>
            <a href="/crm/timeclock/settings.php#breaks">Change</a>
        </p>
    </div>
    <div class="d-flex" style="gap:8px;">
        <a href="/crm/timeclock/timesheets.php" class="btn btn-sm btn-outline-secondary">
            <i data-feather="list" style="width:14px;height:14px;"></i> All Timesheets
        </a>
        <?php if (function_exists('isAdmin') && isAdmin()): ?>
        <a href="/crm/accounting/payroll-import.php" class="btn btn-sm btn-outline-secondary" title="Upload Wave's monthly Wages &amp; taxes report so Penny can book the month">
            <i data-feather="upload" style="width:14px;height:14px;"></i> Import Wave report
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filter bar -->
<form method="get" action="/crm/timeclock/payroll-summary.php" id="payrollForm">
<div class="mw-payroll-filter-bar">
    <!-- Presets -->
    <div>
        <div class="small font-weight-bold mb-1" style="color:#555;">Quick Presets</div>
        <div class="mw-payroll-preset-btns">
            <button type="button" class="mw-payroll-preset-btn<?php echo ($rangeStart === $curBiStart && $rangeEnd === $curBiEnd) ? ' active' : ''; ?>"
                    onclick="setRange('<?php echo $curBiStart; ?>','<?php echo $curBiEnd; ?>')">
                Current Bi-weekly
            </button>
            <button type="button" class="mw-payroll-preset-btn<?php echo ($rangeStart === $prevBiStart && $rangeEnd === $prevBiEnd) ? ' active' : ''; ?>"
                    onclick="setRange('<?php echo $prevBiStart; ?>','<?php echo $prevBiEnd; ?>')">
                Previous Bi-weekly
            </button>
            <button type="button" class="mw-payroll-preset-btn<?php echo ($rangeStart === $curMonStart && $rangeEnd === $curMonEnd) ? ' active' : ''; ?>"
                    onclick="setRange('<?php echo $curMonStart; ?>','<?php echo $curMonEnd; ?>')">
                This Month
            </button>
        </div>
    </div>

    <!-- Custom range -->
    <div class="d-flex" style="gap:8px; align-items:flex-end;">
        <div>
            <label class="small font-weight-bold mb-1" style="color:#555;">From</label>
            <input type="date" name="start" id="inputStart" value="<?php echo htmlspecialchars($rangeStart); ?>"
                   class="form-control form-control-sm" style="width:145px;">
        </div>
        <div>
            <label class="small font-weight-bold mb-1" style="color:#555;">To</label>
            <input type="date" name="end" id="inputEnd" value="<?php echo htmlspecialchars($rangeEnd); ?>"
                   class="form-control form-control-sm" style="width:145px;">
        </div>
        <button type="submit" class="btn btn-sm" style="background:var(--mw-green);color:#fff;">Apply</button>
    </div>

    <!-- Actions -->
    <div class="mw-payroll-actions" style="margin-left:auto;">
        <a href="?start=<?php echo urlencode($rangeStart); ?>&end=<?php echo urlencode($rangeEnd); ?>&export=csv"
           class="btn btn-sm btn-outline-secondary">
            <i data-feather="download" style="width:13px;height:13px;"></i> Export CSV
        </a>
        <button type="button" onclick="window.print();" class="btn btn-sm btn-outline-secondary">
            <i data-feather="printer" style="width:13px;height:13px;"></i> Print
        </button>
    </div>
</div>
</form>

<!-- Period badge -->
<div class="mb-3">
    <span class="mw-payroll-period-badge">
        <i data-feather="calendar" style="width:14px;height:14px;"></i>
        <?php echo date('M j, Y', strtotime($rangeStart)); ?> &ndash; <?php echo date('M j, Y', strtotime($rangeEnd)); ?>
        &bull; <?php echo (int)((strtotime($rangeEnd) - strtotime($rangeStart)) / 86400 + 1); ?> days
    </span>
    <?php if (date('N', strtotime($rangeStart)) !== '1' || date('N', strtotime($rangeEnd)) !== '7'): ?>
    <div class="text-muted small mt-1">Overtime is worked out per day and per Sunday&ndash;Saturday week (BC law); days of that week before the range count toward the 40 h but aren&rsquo;t paid again. This range cuts a week, so weekly overtime only counts the days inside it &mdash; use whole weeks for a pay run.</div>
    <?php endif; ?>
</div>

<!-- Payroll Table -->
<?php if (empty($rows)): ?>
<div class="mw-schedule-empty">
    <i data-feather="dollar-sign"></i>
    <h3>No payroll data</h3>
    <p>No employees logged hours in the selected date range.</p>
</div>
<?php else: ?>

<?php
// Collect all submitted timesheet IDs for bulk approve
$allSubmittedIds = [];
foreach ($rows as $r) {
    if ($r['status'] === 'submitted') {
        foreach ($r['timesheet_ids'] as $tid) {
            $allSubmittedIds[] = $tid;
        }
    }
}
?>

<?php if (!empty($allSubmittedIds)): ?>
<form method="post" action="/crm/timeclock/payroll-summary.php?start=<?php echo urlencode($rangeStart); ?>&end=<?php echo urlencode($rangeEnd); ?>" class="mb-3">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
    <?php foreach ($allSubmittedIds as $sid): ?>
        <input type="hidden" name="timesheet_ids[]" value="<?php echo (int)$sid; ?>">
    <?php endforeach; ?>
    <button type="submit" name="bulk_approve" value="1" class="btn btn-sm"
            style="background:var(--mw-green);color:#fff;"
            onclick="return confirm('Approve all submitted timesheets in this period?')">
        <i data-feather="check-circle" style="width:14px;height:14px;"></i>
        Approve All Submitted (<?php echo count($allSubmittedIds); ?> timesheet<?php echo count($allSubmittedIds) !== 1 ? 's' : ''; ?>)
    </button>
</form>
<?php endif; ?>

<div class="table-responsive">
<table class="mw-payroll-table">
    <thead>
        <tr>
            <th>Employee</th>
            <th>Rate</th>
            <th>Total Hours</th>
            <th>Breaks</th>
            <th>Regular</th>
            <th>OT 1.5&times;</th>
            <th>OT 2&times;</th>
            <th>Gross Pay</th>
            <th>Status</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
        $pay  = $r['pay'];
        $emp  = $r['emp'];
        $rate = $r['rate'];
    ?>
    <tr>
        <td>
            <div class="mw-payroll-emp-name"><?php echo htmlspecialchars($emp['full_name']); ?></div>
            <?php if ($rate > 0): ?>
                <div class="mw-payroll-emp-rate"><?php echo OvertimeCalculator::formatPay($rate); ?>/h</div>
            <?php endif; ?>
        </td>
        <td>
            <?php echo $rate > 0 ? OvertimeCalculator::formatPay($rate) . '/h' : '<span class="text-muted">—</span>'; ?>
        </td>
        <td><?php echo OvertimeCalculator::minutesToHours($r['total_shift']); ?>
            <?php if ($r['unpaid_min'] > 0): ?>
                <div class="mw-payroll-emp-rate">of <?php echo OvertimeCalculator::minutesToHours($r['clocked']); ?> clocked</div>
            <?php endif; ?>
        </td>
        <td>
            <?php if ($r['break_min'] > 0): ?>
                <?php echo OvertimeCalculator::minutesToHours($r['break_min']); ?>
                <div class="mw-payroll-emp-rate">
                    <?php echo $r['break_count']; ?> break<?php echo $r['break_count'] === 1 ? '' : 's'; ?>
                    &bull; <?php echo $r['unpaid_min'] > 0 ? OvertimeCalculator::minutesToHours($r['unpaid_min']) . ' unpaid' : 'paid'; ?>
                </div>
            <?php else: ?>
                <span class="text-muted">—</span>
            <?php endif; ?>
        </td>
        <td><?php echo OvertimeCalculator::minutesToHours($pay['regular_minutes']); ?></td>
        <td>
            <?php if ($pay['ot_1_5_minutes'] > 0): ?>
                <span style="color:#e67e22; font-weight:700;">
                    <?php echo OvertimeCalculator::minutesToHours($pay['ot_1_5_minutes']); ?>
                </span>
            <?php else: ?>
                <span class="text-muted">—</span>
            <?php endif; ?>
        </td>
        <td>
            <?php if ($pay['ot_2_0_minutes'] > 0): ?>
                <span style="color:#c0392b; font-weight:700;">
                    <?php echo OvertimeCalculator::minutesToHours($pay['ot_2_0_minutes']); ?>
                </span>
            <?php else: ?>
                <span class="text-muted">—</span>
            <?php endif; ?>
        </td>
        <td>
            <?php if ($rate > 0): ?>
                <span class="mw-payroll-gross"><?php echo OvertimeCalculator::formatPay($pay['gross_pay']); ?></span>
                <?php if ($pay['has_overtime']): ?>
                    <span class="mw-ot-badge">OT</span>
                <?php endif; ?>
            <?php else: ?>
                <span class="text-muted" title="Set hourly rate in employee profile">No rate set</span>
            <?php endif; ?>
        </td>
        <td>
            <span class="mw-ts-status mw-ts-status-<?php echo htmlspecialchars($r['status']); ?>">
                <?php echo ucfirst(str_replace('_', ' ', $r['status'])); ?>
            </span>
        </td>
        <td>
            <a href="/crm/timeclock/timesheets.php?user_id=<?php echo (int)$emp['id']; ?>"
               class="btn btn-sm btn-outline-secondary" style="font-size:0.7rem; padding:2px 8px;">
                Timesheets
            </a>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="3"><strong>Totals</strong></td>
            <td><strong><?php echo $grandTotalBreak > 0 ? OvertimeCalculator::minutesToHours($grandTotalBreak) : '—'; ?></strong></td>
            <td><strong><?php echo OvertimeCalculator::minutesToHours($grandTotalReg); ?></strong></td>
            <td><strong style="color:#e67e22;"><?php echo OvertimeCalculator::minutesToHours($grandTotalOt15); ?></strong></td>
            <td><strong style="color:#c0392b;"><?php echo OvertimeCalculator::minutesToHours($grandTotalOt20); ?></strong></td>
            <td><strong class="mw-payroll-gross" style="font-size:1.05rem;"><?php echo OvertimeCalculator::formatPay($grandTotalPay); ?></strong></td>
            <td colspan="2"></td>
        </tr>
    </tfoot>
</table>
</div>
<?php endif; ?>

<script>
function setRange(start, end) {
    document.getElementById('inputStart').value = start;
    document.getElementById('inputEnd').value   = end;
    document.getElementById('payrollForm').submit();
}
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
