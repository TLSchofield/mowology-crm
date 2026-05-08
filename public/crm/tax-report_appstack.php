<?php
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
requirePermission('billing.view');
$user = getCurrentUser();

$pageTitle  = 'CRA Tax Report';
$activePage = 'tax-report';

$db = getDB();

// Year / quarter defaults
$currentYear    = (int)date('Y');
$currentQuarter = (int)ceil((int)date('n') / 3);

$year    = max(2020, min(2099, intval($_GET['year']    ?? $currentYear)));
$quarter = max(0,   min(4,    intval($_GET['quarter']  ?? $currentQuarter)));

if ($quarter >= 1 && $quarter <= 4) {
    $startMonth  = ($quarter - 1) * 3 + 1;
    $endMonth    = $startMonth + 2;
    $dateFrom    = sprintf('%04d-%02d-01', $year, $startMonth);
    $dateTo      = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $endMonth)));
    $periodLabel = "Q{$quarter} {$year}";
} else {
    $dateFrom    = "{$year}-01-01";
    $dateTo      = "{$year}-12-31";
    $periodLabel = "Full Year {$year}";
}

// Business settings
$bs = $db->query("SELECT company_name, gst_registration, gst_rate FROM business_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$gstRegNumber = $bs['gst_registration'] ?? '';
$gstRatePct   = floatval($bs['gst_rate'] ?? 5.00);

// Invoices
$invStmt = $db->prepare("
    SELECT i.id, i.invoice_number, i.issue_date, i.status,
           i.subtotal, i.tax_rate, i.tax_amount, i.total,
           i.amount_paid, i.balance_due, i.payment_method,
           COALESCE(CONCAT(pc.first_name,' ',pc.last_name), co.company_name, 'Unknown') AS client_name
    FROM invoices i
    LEFT JOIN properties p  ON i.property_id = p.id
    LEFT JOIN contacts   pc ON p.site_contact_id = pc.id
    LEFT JOIN companies  co ON i.company_id = co.id
    WHERE i.issue_date BETWEEN ? AND ?
      AND i.status NOT IN ('draft','cancelled')
    ORDER BY i.issue_date ASC
");
$invStmt->execute([$dateFrom, $dateTo]);
$invoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);

// Expenses / ITCs
$expenses = [];
try {
    $expStmt = $db->prepare("
        SELECT e.id, e.expense_date, e.description, e.vendor_name,
               e.amount, e.gst_amount, e.total, e.category
        FROM expenses e
        WHERE e.expense_date BETWEEN ? AND ?
          AND e.status != 'rejected'
          AND e.gst_amount > 0
        ORDER BY e.expense_date ASC
    ");
    $expStmt->execute([$dateFrom, $dateTo]);
    $expenses = $expStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { /* expenses table may not have all columns */ }

// Calculations
$totalRevenue = array_sum(array_column($invoices, 'subtotal'));
$totalGst     = array_sum(array_column($invoices, 'tax_amount'));
$totalITC     = array_sum(array_column($expenses, 'gst_amount'));
$netTax       = $totalGst - $totalITC;

// Status badge
$statusClass = [
    'paid'    => 'success',
    'partial' => 'warning',
    'overdue' => 'danger',
    'sent'    => 'info',
    'viewed'  => 'info',
];
?>
<?php include __DIR__ . '/includes/appstack_head.php'; ?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0">CRA Tax Report</h1>
        <?php if ($gstRegNumber): ?>
        <p class="text-muted mb-0" style="font-size:0.9em;">GST Registration # <?php echo h($gstRegNumber); ?></p>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
        <a href="/crm/api/tax-report.php?year=<?php echo $year; ?>&quarter=<?php echo $quarter; ?>&format=csv"
           class="btn btn-outline-secondary btn-sm">
            <i data-feather="download" class="align-middle mr-1"></i> Export CSV
        </a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i data-feather="printer" class="align-middle mr-1"></i> Print
        </button>
    </div>
</div>

<!-- Period picker -->
<form method="get" class="card mw-card mb-4">
    <div class="card-body py-3">
        <div class="row align-items-end mw-form-row">
            <div class="col-auto">
                <label class="form-label mb-1">Year</label>
                <select name="year" class="form-control form-control-sm" style="width:100px;">
                    <?php for ($y = $currentYear; $y >= 2024; $y--): ?>
                    <option value="<?php echo $y; ?>"<?php echo $y === $year ? ' selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label mb-1">Period</label>
                <select name="quarter" class="form-control form-control-sm" style="width:150px;">
                    <option value="1"<?php echo $quarter===1?' selected':''; ?>>Q1 (Jan – Mar)</option>
                    <option value="2"<?php echo $quarter===2?' selected':''; ?>>Q2 (Apr – Jun)</option>
                    <option value="3"<?php echo $quarter===3?' selected':''; ?>>Q3 (Jul – Sep)</option>
                    <option value="4"<?php echo $quarter===4?' selected':''; ?>>Q4 (Oct – Dec)</option>
                    <option value="0"<?php echo $quarter===0?' selected':''; ?>>Full Year</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm mw-btn-primary">View Report</button>
            </div>
            <div class="col-auto ml-auto text-muted" style="font-size:0.85em;">
                <?php echo h($periodLabel); ?> &mdash; <?php echo date('M j, Y', strtotime($dateFrom)); ?> to <?php echo date('M j, Y', strtotime($dateTo)); ?>
            </div>
        </div>
    </div>
</form>

<!-- GST34 summary cards -->
<div class="row mb-4">
    <div class="col-sm-6 col-xl-3 mb-3">
        <div class="card mw-card h-100">
            <div class="card-body">
                <div class="mw-stat-label">Line 101 — Total Revenue</div>
                <div class="mw-stat-value">$<?php echo number_format($totalRevenue, 2); ?></div>
                <div class="mw-stat-sub"><?php echo count($invoices); ?> invoice<?php echo count($invoices)!==1?'s':''; ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3 mb-3">
        <div class="card mw-card h-100">
            <div class="card-body">
                <div class="mw-stat-label">Line 103 — GST Collected</div>
                <div class="mw-stat-value" style="color:var(--mw-orange);">$<?php echo number_format($totalGst, 2); ?></div>
                <div class="mw-stat-sub">Output tax (<?php echo $gstRatePct; ?>% rate)</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3 mb-3">
        <div class="card mw-card h-100">
            <div class="card-body">
                <div class="mw-stat-label">Line 106 — Input Tax Credits</div>
                <div class="mw-stat-value" style="color:var(--mw-green);">$<?php echo number_format($totalITC, 2); ?></div>
                <div class="mw-stat-sub"><?php echo count($expenses); ?> eligible expense<?php echo count($expenses)!==1?'s':''; ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3 mb-3">
        <div class="card mw-card h-100">
            <div class="card-body">
                <div class="mw-stat-label">Line 109 — Net Tax Owing</div>
                <div class="mw-stat-value" style="color:<?php echo $netTax > 0 ? '#dc2626' : 'var(--mw-green)'; ?>;">
                    <?php echo $netTax >= 0 ? '' : '-'; ?>$<?php echo number_format(abs($netTax), 2); ?>
                </div>
                <div class="mw-stat-sub"><?php echo $netTax > 0 ? 'Remit to CRA' : ($netTax < 0 ? 'Refund due from CRA' : 'No tax owing'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- GST34 filing guide -->
<div class="card mw-card mb-4">
    <div class="card-header d-flex align-items-center">
        <i data-feather="file-text" class="align-middle mr-2" style="width:16px;height:16px;"></i>
        <h5 class="card-title mb-0">GST34 Filing Summary — <?php echo h($periodLabel); ?></h5>
    </div>
    <div class="card-body">
        <div class="mw-cra-table">
            <div class="mw-cra-row">
                <div class="mw-cra-line">Line 101</div>
                <div class="mw-cra-desc">Total sales and other revenues</div>
                <div class="mw-cra-amount">$<?php echo number_format($totalRevenue, 2); ?></div>
            </div>
            <div class="mw-cra-row">
                <div class="mw-cra-line">Line 103</div>
                <div class="mw-cra-desc">Total GST/HST and adjustments to remit</div>
                <div class="mw-cra-amount">$<?php echo number_format($totalGst, 2); ?></div>
            </div>
            <div class="mw-cra-row">
                <div class="mw-cra-line">Line 106</div>
                <div class="mw-cra-desc">Total ITCs and adjustments (GST paid on business expenses)</div>
                <div class="mw-cra-amount">$<?php echo number_format($totalITC, 2); ?></div>
            </div>
            <div class="mw-cra-row mw-cra-row--total">
                <div class="mw-cra-line">Line 109</div>
                <div class="mw-cra-desc">Net tax (Line 103 minus Line 106)</div>
                <div class="mw-cra-amount" style="color:<?php echo $netTax > 0 ? '#dc2626' : 'var(--mw-green)'; ?>;">
                    $<?php echo number_format($netTax, 2); ?>
                </div>
            </div>
        </div>
        <p class="text-muted mb-0 mt-3" style="font-size:0.82em;">
            File your GST34 return at <a href="https://www.canada.ca/en/revenue-agency/services/e-services/e-services-businesses/gst-hst-netfile.html" target="_blank" rel="noopener">CRA My Business Account</a>.
            Quarterly filers must remit by the last day of the month following the quarter end.
            <?php if ($gstRegNumber): ?>Registration # <strong><?php echo h($gstRegNumber); ?></strong>.<?php endif; ?>
        </p>
    </div>
</div>

<!-- Invoice detail table -->
<div class="card mw-card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h5 class="card-title mb-0">Invoice Detail</h5>
        <span class="badge badge-secondary"><?php echo count($invoices); ?></span>
    </div>
    <div class="card-body p-0">
        <?php if ($invoices): ?>
        <div class="table-responsive">
            <table class="table table-sm mw-table mb-0">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Client</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th class="text-right">Subtotal</th>
                        <th class="text-right">GST</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">Paid</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $runningGst = 0.0;
                    foreach ($invoices as $inv):
                        $runningGst += floatval($inv['tax_amount']);
                        $sc = $statusClass[$inv['status']] ?? 'secondary';
                    ?>
                    <tr>
                        <td><a href="/crm/invoices/view.php?id=<?php echo $inv['id']; ?>"><?php echo h($inv['invoice_number']); ?></a></td>
                        <td><?php echo h($inv['client_name']); ?></td>
                        <td><?php echo date('M j, Y', strtotime($inv['issue_date'])); ?></td>
                        <td><span class="badge badge-<?php echo $sc; ?>"><?php echo h(ucfirst($inv['status'])); ?></span></td>
                        <td class="text-right">$<?php echo number_format((float)$inv['subtotal'], 2); ?></td>
                        <td class="text-right">$<?php echo number_format((float)$inv['tax_amount'], 2); ?></td>
                        <td class="text-right">$<?php echo number_format((float)$inv['total'], 2); ?></td>
                        <td class="text-right" style="color:var(--mw-green);">$<?php echo number_format((float)$inv['amount_paid'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight:600;border-top:2px solid var(--mw-light);">
                        <td colspan="4">Totals</td>
                        <td class="text-right">$<?php echo number_format($totalRevenue, 2); ?></td>
                        <td class="text-right" style="color:var(--mw-orange);">$<?php echo number_format($totalGst, 2); ?></td>
                        <td class="text-right">$<?php echo number_format($totalRevenue + $totalGst, 2); ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center text-muted py-5">No invoices in this period.</div>
        <?php endif; ?>
    </div>
</div>

<!-- Expense ITC detail -->
<div class="card mw-card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h5 class="card-title mb-0">Input Tax Credits (ITCs) — Expenses</h5>
        <span class="badge badge-secondary"><?php echo count($expenses); ?></span>
    </div>
    <div class="card-body p-0">
        <?php if ($expenses): ?>
        <div class="table-responsive">
            <table class="table table-sm mw-table mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Vendor</th>
                        <th>Description</th>
                        <th>Category</th>
                        <th class="text-right">Pre-tax Amount</th>
                        <th class="text-right">GST Paid (ITC)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expenses as $exp): ?>
                    <tr>
                        <td><?php echo $exp['expense_date'] ? date('M j, Y', strtotime($exp['expense_date'])) : '—'; ?></td>
                        <td><?php echo h($exp['vendor_name'] ?? ''); ?></td>
                        <td><?php echo h($exp['description'] ?? ''); ?></td>
                        <td><?php echo h($exp['category'] ?? ''); ?></td>
                        <td class="text-right">$<?php echo number_format((float)$exp['amount'], 2); ?></td>
                        <td class="text-right" style="color:var(--mw-green);">$<?php echo number_format((float)$exp['gst_amount'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight:600;border-top:2px solid var(--mw-light);">
                        <td colspan="5">Total ITCs</td>
                        <td class="text-right" style="color:var(--mw-green);">$<?php echo number_format($totalITC, 2); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center text-muted py-4" style="font-size:0.9em;">
            No expenses with GST tracked in this period.
            <?php if (!$gstRegNumber): ?>
            <br><a href="/crm/settings.php">Set your GST registration number</a> in Business Settings to enable full tracking.
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/appstack_footer.php'; ?>
