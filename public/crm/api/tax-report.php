<?php
/**
 * CRA Tax Report API
 * Returns GST summary and invoice/expense detail for a reporting period.
 *
 * GET params:
 *   year    int   (default: current year)
 *   quarter int   1-4 or 0 = full year (default: current quarter)
 *   format  string  'json' (default) or 'csv'
 */
declare(strict_types=1);

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once PUBLIC_ROOT . '/crm/includes/functions.php';
requireLogin();
requirePermission('billing.view');
session_write_close();

$db   = getDB();
$year = max(2020, min(2099, intval($_GET['year'] ?? date('Y'))));
$q    = intval($_GET['quarter'] ?? ceil((int)date('n') / 3));
$fmt  = $_GET['format'] ?? 'json';

// Resolve date range
if ($q >= 1 && $q <= 4) {
    $startMonth = ($q - 1) * 3 + 1;
    $endMonth   = $startMonth + 2;
    $dateFrom   = sprintf('%04d-%02d-01', $year, $startMonth);
    $dateTo     = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $endMonth)));
    $periodLabel = "Q{$q} {$year}";
} else {
    $dateFrom    = "{$year}-01-01";
    $dateTo      = "{$year}-12-31";
    $periodLabel = "Full Year {$year}";
    $q           = 0;
}

// ── Business settings ──────────────────────────────────────────────────────
$bsRow = $db->query("SELECT company_name, gst_registration FROM business_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$businessName  = $bsRow['company_name']     ?? '';
$gstRegNumber  = $bsRow['gst_registration'] ?? '';

// ── Invoices in period ─────────────────────────────────────────────────────
$invStmt = $db->prepare("
    SELECT i.id, i.invoice_number, i.issue_date, i.status,
           i.subtotal, i.tax_rate, i.tax_amount, i.gst_number,
           i.total, i.amount_paid, i.balance_due, i.payment_method,
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

// ── Expenses / ITCs in period ──────────────────────────────────────────────
$expStmt = $db->prepare("
    SELECT e.id, e.expense_date, e.description, e.vendor_name,
           e.amount, e.gst_amount, e.total, e.category
    FROM expenses e
    WHERE e.expense_date BETWEEN ? AND ?
      AND e.status != 'rejected'
      AND e.gst_amount > 0
    ORDER BY e.expense_date ASC
");
try {
    $expStmt->execute([$dateFrom, $dateTo]);
    $expenses = $expStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $expenses = [];
}

// ── Summary calculations ───────────────────────────────────────────────────
$totalRevenue  = 0.0;
$totalGstOut   = 0.0; // GST collected on invoices
$totalPaid     = 0.0;

foreach ($invoices as $inv) {
    $totalRevenue += floatval($inv['subtotal']);
    $totalGstOut  += floatval($inv['tax_amount']);
    $totalPaid    += floatval($inv['amount_paid']);
}

$totalITC = 0.0; // Input Tax Credits from expenses
foreach ($expenses as $exp) {
    $totalITC += floatval($exp['gst_amount']);
}

$netTax = $totalGstOut - $totalITC;

// ── CSV export ─────────────────────────────────────────────────────────────
if ($fmt === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="gst-report-' . $periodLabel . '-' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    // Summary block
    fputcsv($out, ['CRA GST/HST Report — ' . $businessName]);
    fputcsv($out, ['GST Registration #', $gstRegNumber]);
    fputcsv($out, ['Reporting Period', $periodLabel]);
    fputcsv($out, ['Date Range', $dateFrom . ' to ' . $dateTo]);
    fputcsv($out, ['Generated', date('Y-m-d H:i')]);
    fputcsv($out, []);
    fputcsv($out, ['GST34 FILING SUMMARY']);
    fputcsv($out, ['Line 101 — Total sales and other revenues', number_format($totalRevenue, 2)]);
    fputcsv($out, ['Line 103 — GST/HST collected', number_format($totalGstOut, 2)]);
    fputcsv($out, ['Line 106 — Input Tax Credits (ITCs)', number_format($totalITC, 2)]);
    fputcsv($out, ['Line 109 — Net tax owing (Line 103 - Line 106)', number_format($netTax, 2)]);
    fputcsv($out, []);

    // Invoice detail
    fputcsv($out, ['INVOICE DETAIL']);
    fputcsv($out, ['Invoice #', 'Client', 'Issue Date', 'Status', 'Subtotal', 'GST Rate %', 'GST Collected', 'Total', 'Paid', 'Balance Due', 'Payment Method']);
    foreach ($invoices as $inv) {
        fputcsv($out, [
            $inv['invoice_number'],
            $inv['client_name'],
            $inv['issue_date'],
            $inv['status'],
            number_format((float)$inv['subtotal'], 2),
            number_format((float)($inv['tax_rate'] ?? 0.05) * 100, 2),
            number_format((float)$inv['tax_amount'], 2),
            number_format((float)$inv['total'], 2),
            number_format((float)$inv['amount_paid'], 2),
            number_format((float)$inv['balance_due'], 2),
            $inv['payment_method'] ?? '',
        ]);
    }

    if ($expenses) {
        fputcsv($out, []);
        fputcsv($out, ['EXPENSE / INPUT TAX CREDITS (ITCs)']);
        fputcsv($out, ['Date', 'Vendor', 'Description', 'Category', 'Pre-tax Amount', 'GST Paid (ITC)']);
        foreach ($expenses as $exp) {
            fputcsv($out, [
                $exp['expense_date'],
                $exp['vendor_name'] ?? '',
                $exp['description'] ?? '',
                $exp['category'] ?? '',
                number_format((float)$exp['amount'], 2),
                number_format((float)$exp['gst_amount'], 2),
            ]);
        }
    }

    fclose($out);
    exit;
}

// ── JSON response ──────────────────────────────────────────────────────────
header('Content-Type: application/json');
echo json_encode([
    'ok'          => true,
    'period'      => $periodLabel,
    'date_from'   => $dateFrom,
    'date_to'     => $dateTo,
    'gst_number'  => $gstRegNumber,
    'summary'     => [
        'total_revenue'  => round($totalRevenue, 2),
        'gst_collected'  => round($totalGstOut, 2),
        'total_itc'      => round($totalITC, 2),
        'net_tax'        => round($netTax, 2),
        'invoice_count'  => count($invoices),
        'expense_count'  => count($expenses),
    ],
    'invoices'  => $invoices,
    'expenses'  => $expenses,
]);
