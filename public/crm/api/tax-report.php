<?php
/**
 * CRA Tax Report API
 * Returns GST summary and invoice/expense detail for a reporting period.
 *
 * GET params:
 *   year    int   (default: current year)
 *   quarter int   1-4 or 0 = full year (default: current quarter)
 *   format  string  'json' (default) or 'csv'
 *   mode    'compare' → read-only old-vs-new numbers for the period (or &from=&to=)
 *
 * Numbers come from GstReportService (the one GST calculation): ITCs from approved or
 * posted expenses only, meals & entertainment limited to 50% (ops_settings).
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
$fmt  = ($_GET['format'] ?? '') === 'csv' ? 'csv' : 'json';

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
$bsRow = [];
try {
    $bsRow = $db->query("SELECT company_name, gst_registration FROM business_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $__e) { /* silent */ }
$businessName  = $bsRow['company_name']     ?? '';
$gstRegNumber  = $bsRow['gst_registration'] ?? '';

// ── The return: one shared calculation (GstReportService) ─────────────────
// ITCs from approved or posted expenses only, with the meals & entertainment limit.
$apiErrors = [];
$invoices  = [];
$expenses  = [];
$rpt       = null;
try {
    require_once APP_ROOT . '/Modules/Accounting/Services/GstReportService.php';
    $rpt      = (new GstReportService($db))->reportForRange($dateFrom, $dateTo, $periodLabel);
    $invoices = $rpt['invoices'];
    $expenses = $rpt['expenses'];
} catch (\Throwable $__e) {
    $apiErrors[] = 'report_query_failed';
}

// ── Summary calculations ───────────────────────────────────────────────────
$totalRevenue = $rpt ? $rpt['line_101'] : 0.0;
$totalGstOut  = $rpt ? $rpt['line_103'] : 0.0;
$totalPaid    = round((float)array_sum(array_column($invoices, 'amount_paid')), 2);
$itcGross     = $rpt ? $rpt['itc_gross'] : 0.0;
$mealsLimit   = $rpt ? $rpt['meals_limit'] : 0.0;
$mealsLabel   = $rpt ? $rpt['meals_limit_label'] : '';
$totalITC     = $rpt ? $rpt['line_106'] : 0.0;
$netTax       = $rpt ? $rpt['line_109'] : 0.0;

// ── What changed (read-only) — ?mode=compare[&from=YYYY-MM-DD&to=YYYY-MM-DD] ──
// Old numbers (old page / old API / ledger) next to the new ones for a period already
// filed. Reads only; no filed record is touched.
if (($_GET['mode'] ?? '') === 'compare') {
    $isDate = static fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
    $cFrom = $isDate($_GET['from'] ?? null) ? $_GET['from'] : $dateFrom;
    $cTo   = $isDate($_GET['to'] ?? null)   ? $_GET['to']   : $dateTo;
    header('Content-Type: application/json');
    try {
        require_once APP_ROOT . '/Modules/Accounting/Services/GstReportService.php';
        echo json_encode(['ok' => true] + (new GstReportService($db))->compareLegacy($cFrom, $cTo));
    } catch (\Throwable $__e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'compare_failed']);
    }
    exit;
}

// ── CSV export ─────────────────────────────────────────────────────────────
if ($fmt === 'csv') {
    $safePeriod = str_replace(' ', '-', $periodLabel);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="gst-report-' . $safePeriod . '-' . date('Y-m-d') . '.csv"');

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
    fputcsv($out, ['GST paid on approved/posted expenses', number_format($itcGross, 2)]);
    if ($mealsLimit > 0) {
        fputcsv($out, [$mealsLabel, number_format(-$mealsLimit, 2)]);
    }
    fputcsv($out, ['Line 106 — Input Tax Credits (ITCs)', number_format($totalITC, 2)]);
    fputcsv($out, ['Line 109 — Net tax owing (Line 103 - Line 106)', number_format($netTax, 2)]);
    fputcsv($out, []);

    // Invoice detail
    fputcsv($out, ['INVOICE DETAIL']);
    fputcsv($out, ['Invoice #', 'Client', 'Issue Date', 'Status', 'Subtotal', 'GST Rate %', 'GST Reg #', 'GST Collected', 'Total', 'Paid', 'Balance Due', 'Payment Method']);
    foreach ($invoices as $inv) {
        fputcsv($out, [
            $inv['invoice_number'],
            $inv['client_name'],
            $inv['issue_date'],
            $inv['status'],
            number_format((float)$inv['subtotal'], 2),
            number_format((float)($inv['tax_rate'] ?? 0.05) * 100, 2),
            $inv['gst_number'] ?? $gstRegNumber,
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
        fputcsv($out, ['Date', 'Vendor', 'Description', 'Category', 'Status', 'Pre-tax Amount', 'GST Paid', 'ITC Claimed']);
        foreach ($expenses as $exp) {
            fputcsv($out, [
                $exp['expense_date'],
                $exp['vendor_name'] ?? '',
                $exp['description'] ?? '',
                $exp['category'] ?? '',
                $exp['status'] ?? '',
                number_format((float)$exp['amount'], 2),
                number_format((float)$exp['gst_amount'], 2),
                number_format((float)$exp['itc_claimable'], 2),
            ]);
        }
    }

    fclose($out);
    exit;
}

// ── JSON response ──────────────────────────────────────────────────────────
header('Content-Type: application/json');
echo json_encode([
    'ok'          => empty($apiErrors),
    'errors'      => $apiErrors,
    'period'      => $periodLabel,
    'date_from'   => $dateFrom,
    'date_to'     => $dateTo,
    'gst_number'  => $gstRegNumber,
    'summary'     => [
        'total_revenue'  => $totalRevenue,
        'gst_collected'  => $totalGstOut,
        'itc_gross'      => $itcGross,
        'meals_limit'    => $mealsLimit,
        'meals_limit_label' => $mealsLabel,
        'total_itc'      => $totalITC,
        'net_tax'        => $netTax,
        'invoice_count'  => count($invoices),
        'expense_count'  => count($expenses),
    ],
    'invoices'  => $invoices,
    'expenses'  => $expenses,
]);
