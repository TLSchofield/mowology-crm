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
     *  Line 101 — Total sales and other revenue (incl GST)
     *  Line 103 — GST/HST collected or collectible
     *  Line 106 — ITCs (GST paid on business expenses)
     *  Line 109 — Net tax owing (Line 103 – Line 106)
     *
     * @param int $year
     * @param int $quarter  1–4, or 0 for full year
     */
    public function getGstFilingReport(int $year, int $quarter = 0): array
    {
        [$dateFrom, $dateTo] = $this->getQuarterRange($year, $quarter);

        // Line 101: Total sales (gross invoice amounts including GST)
        $salesStmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount + gst_amount + pst_amount), 0) AS total_sales,
                   COALESCE(SUM(amount), 0)                           AS net_sales,
                   COALESCE(SUM(gst_amount), 0)                       AS gst_collected
            FROM accounting_transactions
            WHERE type = 'income'
              AND transaction_date BETWEEN ? AND ?
              AND status IN ('cleared', 'reconciled')
        ");
        $salesStmt->execute([$dateFrom, $dateTo]);
        $sales = $salesStmt->fetch(PDO::FETCH_ASSOC);

        // Line 106: ITCs — GST paid on business expenses
        $itcStmt = $this->db->prepare("
            SELECT COALESCE(SUM(gst_amount), 0) AS gst_itc
            FROM accounting_transactions
            WHERE type = 'expense'
              AND transaction_date BETWEEN ? AND ?
              AND status IN ('cleared', 'reconciled')
        ");
        $itcStmt->execute([$dateFrom, $dateTo]);
        $itcRow = $itcStmt->fetch(PDO::FETCH_ASSOC);

        $line101 = (float)$sales['total_sales'];   // Total revenue incl tax
        $line103 = (float)$sales['gst_collected']; // GST collected
        $line106 = (float)$itcRow['gst_itc'];      // ITCs
        $line109 = $line103 - $line106;            // Net tax owing (negative = refund)

        // Breakdown by expense category for ITC detail
        $itcDetailStmt = $this->db->prepare("
            SELECT
                coa.name  AS account_name,
                coa.code  AS account_code,
                SUM(t.amount)     AS net_amount,
                SUM(t.gst_amount) AS gst_amount
            FROM accounting_transactions t
            JOIN chart_of_accounts coa ON coa.id = t.account_id
            WHERE t.type = 'expense'
              AND t.transaction_date BETWEEN ? AND ?
              AND t.gst_amount > 0
              AND t.status IN ('cleared', 'reconciled')
            GROUP BY coa.id, coa.name, coa.code
            ORDER BY gst_amount DESC
        ");
        $itcDetailStmt->execute([$dateFrom, $dateTo]);
        $itcDetail = $itcDetailStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'period_label' => $quarter > 0
                ? "Q$quarter $year"
                : "Full Year $year",
            'date_from'    => $dateFrom,
            'date_to'      => $dateTo,

            // CRA form lines
            'line_101'     => round($line101, 2),  // Total sales
            'line_103'     => round($line103, 2),  // GST/HST collected
            'line_106'     => round($line106, 2),  // ITCs
            'line_109'     => round($line109, 2),  // Net owing (positive=owe, negative=refund)

            'net_sales'    => round((float)$sales['net_sales'], 2),
            'is_refund'    => $line109 < 0,
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
