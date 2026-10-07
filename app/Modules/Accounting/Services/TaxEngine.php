<?php
/**
 * TaxEngine — Canadian GST/PST/HST calculation and reporting.
 *
 * Handles:
 *  - Calculate GST/PST on a given amount
 *  - Generate CRA-ready GST/HST filing report
 *  - Track quarterly remittance periods
 *  - Summarize Input Tax Credits (ITCs) from business expenses
 */
declare(strict_types=1);

require_once __DIR__ . '/GstReportService.php';

class TaxEngine
{
    private PDO $db;

    // CRA standard quarterly periods (GST/HST)
    private const QUARTERS = [
        1 => ['01-01', '03-31'],
        2 => ['04-01', '06-30'],
        3 => ['07-01', '09-30'],
        4 => ['10-01', '12-31'],
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // RATE LOOKUP
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Get active tax rates from DB.
     * Returns ['GST' => 0.05, 'PST' => 0.07, ...]
     */
    public function getRates(): array
    {
        $rows = $this->db->query(
            "SELECT code, rate FROM tax_rates WHERE is_active = 1"
        )->fetchAll(PDO::FETCH_ASSOC);

        $rates = [];
        foreach ($rows as $row) {
            $rates[$row['code']] = (float)$row['rate'];
        }
        return $rates;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // CALCULATION HELPERS
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Calculate tax breakdown from a subtotal (tax-exclusive).
     *
     * @param float $subtotal  Pre-tax amount
     * @param bool  $gst       Include GST
     * @param bool  $pst       Include PST
     * @return array [gst_amount, pst_amount, total_tax, grand_total]
     */
    public function calculateFromSubtotal(float $subtotal, bool $gst = true, bool $pst = false): array
    {
        $rates = $this->getRates();

        $gstAmount = $gst ? round($subtotal * ($rates['GST'] ?? 0.05), 2) : 0.00;
        $pstAmount = $pst ? round($subtotal * ($rates['PST'] ?? 0.07), 2) : 0.00;
        $totalTax  = $gstAmount + $pstAmount;

        return [
            'subtotal'    => round($subtotal, 2),
            'gst_amount'  => $gstAmount,
            'pst_amount'  => $pstAmount,
            'total_tax'   => $totalTax,
            'grand_total' => round($subtotal + $totalTax, 2),
            'gst_rate'    => $rates['GST'] ?? 0.05,
            'pst_rate'    => $rates['PST'] ?? 0.07,
        ];
    }

    /**
     * Back-calculate subtotal from a tax-inclusive total.
     *
     * @param float $grossTotal Tax-inclusive amount
     * @param bool  $gst
     * @param bool  $pst
     */
    public function calculateFromGross(float $grossTotal, bool $gst = true, bool $pst = false): array
    {
        $rates    = $this->getRates();
        $taxRate  = 0;
        if ($gst) $taxRate += ($rates['GST'] ?? 0.05);
        if ($pst) $taxRate += ($rates['PST'] ?? 0.07);

        $subtotal  = $taxRate > 0 ? round($grossTotal / (1 + $taxRate), 2) : $grossTotal;
        $gstAmount = $gst ? round($subtotal * ($rates['GST'] ?? 0.05), 2) : 0.00;
        $pstAmount = $pst ? round($subtotal * ($rates['PST'] ?? 0.07), 2) : 0.00;

        return [
            'subtotal'    => $subtotal,
            'gst_amount'  => $gstAmount,
            'pst_amount'  => $pstAmount,
            'total_tax'   => $gstAmount + $pstAmount,
            'grand_total' => $grossTotal,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GST/HST FILING REPORT (CRA Line 101–109)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Generate a CRA GST/HST return report for a given quarter/period.
     *
     * CRA Lines:
     *  Line 101 — Total sales and other revenue (before GST)
     *  Line 103 — GST/HST collected or collectible
     *  Line 106 — ITCs (approved/posted expenses; meals & entertainment limited)
     *  Line 109 — Net tax owing (Line 103 – Line 106)
     *
     * @param int $year
     * @param int $quarter  1–4, or 0 for full year
     */
    public function getGstFilingReport(int $year, int $quarter = 0): array
    {
        // One calculation for every GST report (2026-10-07): GstReportService. It used to be
        // read from the ledger here, which disagreed with the CRA Tax Report page and API
        // (income incl. bank deposits, bank lines repeating a receipt's GST).
        [$dateFrom, $dateTo] = $this->getQuarterRange($year, $quarter);
        $label = $quarter > 0 ? "Q$quarter $year" : "Full Year $year";
        $r = (new GstReportService($this->db))->reportForRange($dateFrom, $dateTo, $label);

        // ITC detail by expense category (the shape the reports page reads).
        $itcDetail = array_map(fn($c) => [
            'account_name' => $c['category'],
            'account_code' => '',
            'net_amount'   => null,
            'gst_amount'   => $c['itc'],
            'gst_paid'     => $c['gst_paid'],
        ], $r['itc_by_category']);

        return [
            'period_label' => $label,
            'date_from'    => $dateFrom,
            'date_to'      => $dateTo,

            // CRA form lines
            'line_101'     => $r['line_101'],  // Sales before GST
            'line_103'     => $r['line_103'],  // GST/HST collected
            'line_106'     => $r['line_106'],  // ITCs (approved/posted expenses, meals limited)
            'line_109'     => $r['line_109'],  // Net owing (positive=owe, negative=refund)

            'itc_gross'         => $r['itc_gross'],
            'meals_limit'       => $r['meals_limit'],
            'meals_limit_label' => $r['meals_limit_label'],
            'net_sales'    => $r['line_101'],
            'is_refund'    => $r['is_refund'],
            'itc_detail'   => $itcDetail,
        ];
    }

    /**
     * All quarterly summaries for a given year.
     */
    public function getYearlyGstSummary(int $year): array
    {
        $quarters = [];
        for ($q = 1; $q <= 4; $q++) {
            $quarters[] = $this->getGstFilingReport($year, $q);
        }
        return $quarters;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Returns [dateFrom, dateTo] for a quarter (or full year if quarter=0).
     */
    private function getQuarterRange(int $year, int $quarter): array
    {
        if ($quarter === 0) {
            return ["$year-01-01", "$year-12-31"];
        }

        $bounds = self::QUARTERS[$quarter] ?? self::QUARTERS[1];
        return ["$year-{$bounds[0]}", "$year-{$bounds[1]}"];
    }

    /**
     * Which CRA quarter is today in?
     */
    public function currentQuarter(): int
    {
        $month = (int)date('n');
        return (int)ceil($month / 3);
    }

    /**
     * Quick tax summary for the accounting dashboard.
     */
    public function getDashboardTaxWidget(): array
    {
        $year    = (int)date('Y');
        $quarter = $this->currentQuarter();
        $report  = $this->getGstFilingReport($year, $quarter);

        return [
            'quarter'       => "Q$quarter $year",
            'gst_collected' => $report['line_103'],
            'gst_itc'       => $report['line_106'],
            'net_owing'     => $report['line_109'],
            'is_refund'     => $report['is_refund'],
        ];
    }
}
