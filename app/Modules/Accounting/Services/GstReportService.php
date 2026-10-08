<?php
/**
 * GstReportService — the ONE GST/HST (GST34) calculation.
 *
 * Before 2026-10-07 four places computed the return and disagreed:
 *   - tax-report_appstack.php   ITCs from every expense not rejected (drafts included)
 *   - api/tax-report.php        ITCs from approved expenses only
 *   - TaxEngine::getGstFilingReport (accounting/reports.php GST tab) and
 *     AccountingService::getTaxSummary (gst_itc) — from the ledger
 *     (accounting_transactions), where bank-import lines can carry a matched receipt's
 *     GST a second time and income includes bank deposits.
 * All of them now call this service.
 *
 * Rules:
 *   - Line 101 sales = invoice subtotals (pre-tax) by issue date, status not draft/cancelled.
 *   - Line 103 GST collected = those invoices' tax_amount.
 *   - Line 106 ITCs = gst_amount of expenses that are APPROVED or POSTED ('forwarded' —
 *     sent to the books/accountant) only. Drafts, pending_approval and rejected never count.
 *   - Meals & Entertainment limit (Excise Tax Act s.236): only 50% of the GST on meals and
 *     entertainment is claimable. The category name(s) and the rate are settings because
 *     Tim confirms them with his accountant:
 *         ops_settings gst_meals_itc_categories  comma list, default 'Meals'
 *         ops_settings gst_meals_itc_rate        0..1,       default 0.5
 *     The reduction is shown as its own line ("Meals ITC limited to 50%: −$X").
 *   - Line 109 = 103 − 106 (negative = refund).
 *
 * compareLegacy() is read-only: it puts the old numbers next to the new ones for a date
 * range so Tim can see whether a period he has already filed would differ. Nothing filed
 * is ever changed here.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */

class GstReportService
{
    public const ITC_STATUSES = ['approved', 'forwarded'];
    public const DEFAULT_MEALS_CATEGORIES = ['Meals'];
    public const DEFAULT_MEALS_RATE = 0.5;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Settings ─────────────────────────────────────────────────────────────

    /** @return array{meals_categories:string[], meals_rate:float} */
    public function settings(): array
    {
        $raw = [];
        try {
            $stmt = $this->db->prepare("SELECT setting_key, setting_value FROM ops_settings WHERE setting_key IN (?, ?)");
            $stmt->execute(['gst_meals_itc_categories', 'gst_meals_itc_rate']);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $raw[$r['setting_key']] = $r['setting_value'];
            }
        } catch (Throwable $e) { /* defaults */ }
        return self::parseSettings($raw['gst_meals_itc_categories'] ?? null, $raw['gst_meals_itc_rate'] ?? null);
    }

    /** Settings from their stored strings, falling back to the defaults. Pure. */
    public static function parseSettings(?string $categories, ?string $rate): array
    {
        $cats = [];
        foreach (explode(',', (string)$categories) as $c) {
            $c = trim($c);
            if ($c !== '') $cats[] = $c;
        }
        $r = is_numeric($rate) ? (float)$rate : self::DEFAULT_MEALS_RATE;
        if ($r < 0 || $r > 1) $r = self::DEFAULT_MEALS_RATE;
        return ['meals_categories' => $cats ?: self::DEFAULT_MEALS_CATEGORIES, 'meals_rate' => $r];
    }

    /** Is this expense category one the meals limit applies to? Case-insensitive. Pure. */
    public static function isMeals(?string $category, array $mealsCategories): bool
    {
        $c = strtolower(trim((string)$category));
        if ($c === '') return false;
        foreach ($mealsCategories as $m) {
            if ($c === strtolower(trim((string)$m))) return true;
        }
        return false;
    }

    // ── Periods ──────────────────────────────────────────────────────────────

    /** [dateFrom, dateTo, label] for a quarter 1–4, or the full year for 0. Pure. */
    public static function periodRange(int $year, int $quarter): array
    {
        if ($quarter >= 1 && $quarter <= 4) {
            $startMonth = ($quarter - 1) * 3 + 1;
            $from = sprintf('%04d-%02d-01', $year, $startMonth);
            $to   = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $startMonth + 2)));
            return [$from, $to, "Q{$quarter} {$year}"];
        }
        return ["{$year}-01-01", "{$year}-12-31", "Full Year {$year}"];
    }

    // ── Data ─────────────────────────────────────────────────────────────────

    public function invoices(string $from, string $to): array
    {
        $stmt = $this->db->prepare("
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
        $stmt->execute([$from, $to]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Expenses whose GST may be claimed: approved or posted, with GST. */
    public function itcExpenses(string $from, string $to): array
    {
        $in = implode(',', array_fill(0, count(self::ITC_STATUSES), '?'));
        $stmt = $this->db->prepare("
            SELECT e.id, e.expense_date, e.description,
                   COALESCE(v.name, e.vendor_name_raw) AS vendor_name,
                   e.amount, e.gst_amount, e.total, e.status,
                   e.accounting_category AS category
            FROM expenses e
            LEFT JOIN vendors v ON v.id = e.vendor_id
            WHERE e.expense_date BETWEEN ? AND ?
              AND e.gst_amount > 0
              AND e.status IN ($in)
            ORDER BY e.expense_date ASC, e.id ASC
        ");
        $stmt->execute(array_merge([$from, $to], self::ITC_STATUSES));
        return $this->bySplitShare($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * A receipt split by line (migration 1233) counts by its shares — a coffee on a Chevron fill-up
     * is a Meals share, limited, while the diesel's GST is claimed in full. Unsplit rows pass through.
     */
    private function bySplitShare(array $rows): array
    {
        if (!$rows) return $rows;
        try {
            $f = dirname(__DIR__, 2) . '/Expenses/Services/ExpenseSplitService.php';
            if (!class_exists('ExpenseSplitService')) {
                if (!is_file($f)) return $rows;
                require_once $f;
            }
            $split = (new ExpenseSplitService($this->db))->forExpenses(array_column($rows, 'id'));
        } catch (Throwable $e) {
            return $rows;
        }
        return self::expandSplits($rows, $split);
    }

    /** Pure: replace each split receipt by one row per share (its GST, its category). */
    public static function expandSplits(array $rows, array $splitByExpense): array
    {
        if (!$splitByExpense) return $rows;
        $out = [];
        foreach ($rows as $r) {
            $shares = $splitByExpense[(int)$r['id']] ?? [];
            if (!$shares) { $out[] = $r; continue; }
            foreach ($shares as $a) {
                if ((float)$a['gst_amount'] == 0.0) continue;
                $out[] = array_merge($r, [
                    'description' => $a['label'],
                    'amount'      => (float)$a['net_amount'],
                    'gst_amount'  => (float)$a['gst_amount'],
                    'total'       => round((float)$a['net_amount'] + (float)$a['gst_amount'] + (float)$a['pst_amount'], 2),
                    'category'    => $a['accounting_category'] ?? $r['category'],
                    'split_share' => true,
                ]);
            }
        }
        return $out;
    }

    // ── The calculation ──────────────────────────────────────────────────────

    /**
     * The GST34 lines from invoice and expense rows. Pure.
     * Each expense comes back with itc_claimable (and meals_limited) added.
     */
    public static function summarize(array $invoices, array $expenses, array $settings): array
    {
        $rate = (float)($settings['meals_rate'] ?? self::DEFAULT_MEALS_RATE);
        $cats = $settings['meals_categories'] ?? self::DEFAULT_MEALS_CATEGORIES;

        $sales = 0.0; $collected = 0.0;
        foreach ($invoices as $inv) {
            $sales     += (float)($inv['subtotal'] ?? 0);
            $collected += (float)($inv['tax_amount'] ?? 0);
        }

        $gross = 0.0; $mealsGross = 0.0; $mealsClaimable = 0.0; $byCat = [];
        foreach ($expenses as &$exp) {
            $gst = round((float)($exp['gst_amount'] ?? 0), 2);
            $isMeals = self::isMeals($exp['category'] ?? null, $cats);
            $claim = $isMeals ? round($gst * $rate, 2) : $gst;
            $exp['meals_limited'] = $isMeals;
            $exp['itc_claimable'] = $claim;
            $gross += $gst;
            if ($isMeals) { $mealsGross += $gst; $mealsClaimable += $claim; }
            $cat = trim((string)($exp['category'] ?? '')) ?: 'Uncategorized';
            if (!isset($byCat[$cat])) $byCat[$cat] = ['category' => $cat, 'gst_paid' => 0.0, 'itc' => 0.0, 'count' => 0];
            $byCat[$cat]['gst_paid'] += $gst;
            $byCat[$cat]['itc'] += $claim;
            $byCat[$cat]['count']++;
        }
        unset($exp);
        foreach ($byCat as &$c) { $c['gst_paid'] = round($c['gst_paid'], 2); $c['itc'] = round($c['itc'], 2); }
        unset($c);
        usort($byCat, fn($a, $b) => $b['itc'] <=> $a['itc']);

        $mealsLimit = round($mealsGross - $mealsClaimable, 2);   // positive amount taken OFF
        $itc = round($gross - $mealsLimit, 2);
        $line103 = round($collected, 2);
        $net = round($line103 - $itc, 2);

        return [
            'line_101'          => round($sales, 2),
            'line_103'          => $line103,
            'itc_gross'         => round($gross, 2),
            'meals_gst'         => round($mealsGross, 2),
            'meals_rate'        => $rate,
            'meals_categories'  => array_values($cats),
            'meals_limit'       => $mealsLimit,
            'meals_limit_label' => self::mealsLimitLabel($rate, $mealsLimit),
            'line_106'          => $itc,
            'line_109'          => $net,
            'is_refund'         => $net < 0,
            'invoice_count'     => count($invoices),
            'expense_count'     => count($expenses),
            'itc_by_category'   => array_values($byCat),
            'expenses'          => $expenses,
        ];
    }

    /** "Meals ITC limited to 50%: −$12.34" Pure. */
    public static function mealsLimitLabel(float $rate, float $limit): string
    {
        $pct = rtrim(rtrim(number_format($rate * 100, 1, '.', ''), '0'), '.');
        return 'Meals ITC limited to ' . $pct . '%: −$' . number_format($limit, 2);
    }

    /** The full return for a date range: summary lines + the invoice and expense rows. */
    public function reportForRange(string $from, string $to, string $label = ''): array
    {
        $invoices = $this->invoices($from, $to);
        $s = self::summarize($invoices, $this->itcExpenses($from, $to), $this->settings());
        $expenses = $s['expenses'];
        unset($s['expenses']);
        return $s + [
            'period_label' => $label !== '' ? $label : "{$from} to {$to}",
            'date_from'    => $from,
            'date_to'      => $to,
            'invoices'     => $invoices,
            'expenses'     => $expenses,
        ];
    }

    public function report(int $year, int $quarter = 0): array
    {
        [$from, $to, $label] = self::periodRange($year, $quarter);
        return $this->reportForRange($from, $to, $label);
    }

    // ── What changed (read-only) ─────────────────────────────────────────────

    /**
     * The numbers the old reports gave for this range next to the new ones, so Tim can see
     * whether a period he already filed would differ. Reads only; changes nothing.
     */
    public function compareLegacy(string $from, string $to): array
    {
        $new = $this->reportForRange($from, $to);
        $one = function (string $sql, array $p) {
            try {
                $st = $this->db->prepare($sql);
                $st->execute($p);
                return round((float)$st->fetchColumn(), 2);
            } catch (Throwable $e) {
                return null;   // that old query failed outright (e.g. a column that does not exist)
            }
        };
        $old = [
            // tax-report_appstack.php before: anything not rejected, drafts included
            'tax_report_page' => ['line_106' => $one("SELECT COALESCE(SUM(gst_amount),0) FROM expenses
                                                      WHERE expense_date BETWEEN ? AND ? AND status != 'rejected' AND gst_amount > 0", [$from, $to])],
            // api/tax-report.php before: approved only, no meals limit
            'tax_report_api'  => ['line_106' => $one("SELECT COALESCE(SUM(gst_amount),0) FROM expenses
                                                      WHERE expense_date BETWEEN ? AND ? AND status = 'approved' AND gst_amount > 0", [$from, $to])],
            // TaxEngine / AccountingService before: the ledger
            'ledger'          => [
                'line_101' => $one("SELECT COALESCE(SUM(amount + gst_amount + pst_amount),0) FROM accounting_transactions
                                    WHERE type = 'income' AND transaction_date BETWEEN ? AND ? AND status IN ('cleared','reconciled')", [$from, $to]),
                'line_103' => $one("SELECT COALESCE(SUM(gst_amount),0) FROM accounting_transactions
                                    WHERE type = 'income' AND transaction_date BETWEEN ? AND ? AND status IN ('cleared','reconciled')", [$from, $to]),
                'line_106' => $one("SELECT COALESCE(SUM(gst_amount),0) FROM accounting_transactions
                                    WHERE type = 'expense' AND transaction_date BETWEEN ? AND ? AND status IN ('cleared','reconciled')", [$from, $to]),
            ],
        ];
        // The old page and API shared the invoice side with the new one.
        foreach (['tax_report_page', 'tax_report_api'] as $k) {
            $old[$k]['line_101'] = $new['line_101'];
            $old[$k]['line_103'] = $new['line_103'];
        }
        $newLines = ['line_101' => $new['line_101'], 'line_103' => $new['line_103'], 'line_106' => $new['line_106'], 'line_109' => $new['line_109']];
        return [
            'date_from' => $from,
            'date_to'   => $to,
            'new'       => $newLines + ['meals_limit' => $new['meals_limit'], 'itc_gross' => $new['itc_gross']],
            'old'       => array_map(fn($o) => self::withNet($o), $old),
            'diff'      => array_map(fn($o) => self::diffLines(self::withNet($o), $newLines), $old),
        ];
    }

    /** Adds line_109 when 103 and 106 are both known. Pure. */
    public static function withNet(array $lines): array
    {
        if (isset($lines['line_103'], $lines['line_106'])) {
            $lines['line_109'] = round($lines['line_103'] - $lines['line_106'], 2);
        }
        return $lines;
    }

    /** new − old per line (null when the old figure is unknown). Pure. */
    public static function diffLines(array $old, array $new): array
    {
        $d = [];
        foreach ($new as $k => $v) {
            $d[$k] = array_key_exists($k, $old) && $old[$k] !== null ? round((float)$v - (float)$old[$k], 2) : null;
        }
        return $d;
    }
}
