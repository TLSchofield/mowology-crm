<?php
/**
 * UnbilledVisitService — which completed visits are really waiting on an invoice.
 *
 * Why (2026-10-07): Charlie's card said "186 unbilled visits — $28,920.40 completed but not
 * yet invoiced" and Otto told Tim Alexandra's MONTHLY UPFRONT contract had "6 finished visits
 * not invoiced yet". Both counted every completed visit without job_visits.invoice_id, but
 * most visits are never invoiced one by one: contract_billing.php bills a monthly contract
 * per month, in advance; seasonal / annual / custom contracts and flat-priced plans bill per
 * period; pre-sold visits carry actual_amount 0.00. The single definition now lives here and
 * both getWorkQueueItems() (Charlie / Action Board) and ContactTeamService (Otto per client)
 * read it.
 *
 * A completed visit falls in exactly one bucket, checked in this order:
 *   test          ZZTEST plan, property or company
 *   invoiced      linked to a live invoice: job_visits.is_invoiced / invoice_id (not void),
 *                 an invoice_line_items.visit_id row, invoices.visit_id, or — for a one-off
 *                 (non-recurring) plan — an invoice raised for the whole plan (invoices.plan_id)
 *   no_charge     actual_amount set to 0.00 (pre-sold / no charge)
 *   contract      its plan's contract bills per period (billing_cycle other than per_visit)
 *                 and that period's invoice exists, or is still to come (monthly: the current
 *                 month; other cycles: the contract is active)
 *   contract_gap  as above, but the period's invoice does not exist and won't be made by the
 *                 cron (a past month, or the contract is no longer active). Not per-visit
 *                 money, so not in the unbilled count — shown in the breakdown so Tim can check
 *   fixed_price   a plan priced monthly_flat / seasonal / custom, with no per-visit contract
 *   before_crm    done before the CRM took over billing from Jobber (CUTOVER_DATE) — the
 *                 invoice for it lives in Jobber
 *   unbilled      everything else: per-visit contracts, per-visit plans and one-off jobs
 *
 * Value per unbilled visit = what invoicing it would charge (invoice-prefill.php's rule): the
 * plan's line items if it has any, else actual_amount ?: price_per_visit ?: estimated_amount,
 * plus extras_amount. Before tax.
 *
 * Read-only. No namespace / no autoloader in production: require_once and `new`.
 */
class UnbilledVisitService
{
    /** Billing moved from Jobber to the CRM on this day (memory: project_income_double_count_jobber_cutover). */
    public const CUTOVER_DATE = '2026-02-25';

    public const BUCKETS = ['unbilled', 'invoiced', 'contract', 'contract_gap', 'fixed_price', 'no_charge', 'before_crm', 'test'];
    public const VOID = ['void', 'voided', 'cancelled'];

    private PDO $db;
    private array $cols = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function hasColumn(string $table, string $col): bool
    {
        $k = $table . '.' . $col;
        if (!isset($this->cols[$k])) {
            try {
                $this->db->query('SELECT `' . preg_replace('/[^a-z0-9_]/', '', $col) . '` FROM `' . preg_replace('/[^a-z0-9_]/', '', $table) . '` LIMIT 0');
                $this->cols[$k] = true;
            } catch (Throwable $e) {
                $this->cols[$k] = false;
            }
        }
        return $this->cols[$k];
    }

    /**
     * The summary: unbilled count + value, every bucket, and the before/after line.
     * @param int[]|null $planIds only these plans (Otto, per client); null = everything
     */
    public function summary(?array $planIds = null, ?string $today = null): array
    {
        return self::summarise($this->visits($planIds), $today ?? date('Y-m-d'));
    }

    /**
     * Completed visits that are not plainly linked to a live invoice (the old definition's
     * set, plus visits linked only to a void invoice), each with the facts classify() needs.
     * @param int[]|null $planIds
     */
    public function visits(?array $planIds = null): array
    {
        if ($planIds !== null) {
            $planIds = array_values(array_unique(array_filter(array_map('intval', $planIds))));
            if (!$planIds) return [];
        }
        $void = "'" . implode("','", self::VOID) . "'";
        $hasContract = $this->hasColumn('job_plans', 'contract_id');
        $extras = $this->hasColumn('job_visits', 'extras_amount') ? 'jv.extras_amount' : '0';
        $ctrCols = $hasContract
            ? 'jp.contract_id, ctr.billing_cycle, ctr.billing_amount, ctr.status AS contract_status, ctr.contract_number'
            : 'NULL AS contract_id, NULL AS billing_cycle, NULL AS billing_amount, NULL AS contract_status, NULL AS contract_number';
        $ctrJoin = $hasContract ? 'LEFT JOIN contracts ctr ON ctr.id = jp.contract_id' : '';
        $scope = $planIds !== null ? ' AND jv.plan_id IN (' . implode(',', $planIds) . ')' : '';   // ints only

        $rows = $this->db->query("
            SELECT jv.id, jv.plan_id, jv.scheduled_date, jv.is_invoiced, jv.invoice_id, vi.status AS invoice_status,
                   jv.actual_amount, {$extras} AS extras_amount,
                   jp.pricing_model, jp.is_recurring, jp.price_per_visit, jp.estimated_amount, jp.title AS plan_title,
                   {$ctrCols},
                   CONCAT_WS(' ', jp.title, p.address, co.company_name) AS test_text
            FROM job_visits jv
            JOIN job_plans jp ON jp.id = jv.plan_id
            {$ctrJoin}
            LEFT JOIN invoices vi ON vi.id = jv.invoice_id
            LEFT JOIN properties p ON p.id = jp.property_id
            LEFT JOIN companies co ON co.id = jp.company_id
            WHERE jv.status = 'completed'{$scope}
              AND (jv.is_invoiced = 0 OR jv.invoice_id IS NULL OR vi.id IS NULL OR vi.status IN ({$void}))
            ORDER BY jv.scheduled_date, jv.id
        ")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];

        $visitIds = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
        $planList = implode(',', array_unique(array_map(fn($r) => (int)$r['plan_id'], $rows)));
        $ctrIds = array_unique(array_filter(array_map(fn($r) => (int)$r['contract_id'], $rows)));

        $lineLinked = $this->idSet("SELECT DISTINCT li.visit_id FROM invoice_line_items li JOIN invoices i ON i.id = li.invoice_id
                                    WHERE li.visit_id IN ({$visitIds}) AND COALESCE(i.status, '') NOT IN ({$void})", 'invoice_line_items', 'visit_id');
        $invLinked = $this->idSet("SELECT DISTINCT i.visit_id FROM invoices i
                                   WHERE i.visit_id IN ({$visitIds}) AND COALESCE(i.status, '') NOT IN ({$void})", 'invoices', 'visit_id');
        $planInvoiced = $this->idSet("SELECT DISTINCT i.plan_id FROM invoices i
                                      WHERE i.plan_id IN ({$planList}) AND COALESCE(i.status, '') NOT IN ({$void})", 'invoices', 'plan_id');
        $ctrInvoices = [];
        if ($ctrIds && $this->hasColumn('invoices', 'contract_id')) {
            foreach ($this->db->query("SELECT contract_id, invoice_date FROM invoices
                                       WHERE contract_id IN (" . implode(',', $ctrIds) . ") AND COALESCE(status, '') NOT IN ({$void})")
                         ->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ctrInvoices[(int)$r['contract_id']][] = (string)$r['invoice_date'];
            }
        }
        $lineTotals = [];
        try {
            foreach ($this->db->query("SELECT plan_id, SUM(line_total) FROM plan_line_items WHERE plan_id IN ({$planList}) GROUP BY plan_id")
                         ->fetchAll(PDO::FETCH_NUM) as $r) {
                $lineTotals[(int)$r[0]] = (float)$r[1];
            }
        } catch (Throwable $e) { /* no plan line items → the plan price is the line */ }

        foreach ($rows as &$r) {
            $id = (int)$r['id'];
            $r['line_linked'] = isset($lineLinked[$id]) || isset($invLinked[$id]);
            $r['plan_invoiced'] = isset($planInvoiced[(int)$r['plan_id']]);
            $r['contract_invoice_dates'] = $ctrInvoices[(int)$r['contract_id']] ?? [];
            $r['plan_lines_total'] = $lineTotals[(int)$r['plan_id']] ?? null;
        }
        unset($r);
        return $rows;
    }

    /** One column of ids as a set; empty when the column isn't there. */
    private function idSet(string $sql, string $table, string $col): array
    {
        if (!$this->hasColumn($table, $col)) return [];
        try {
            return array_flip(array_map('intval', $this->db->query($sql)->fetchAll(PDO::FETCH_COLUMN)));
        } catch (Throwable $e) {
            error_log('UnbilledVisitService: ' . $e->getMessage());
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The bucket for one visit (see the class comment for the order).
     * @param array $v visits() row: is_invoiced, invoice_id, invoice_status, line_linked, plan_invoiced,
     *                 is_recurring, actual_amount, contract_id, billing_cycle, contract_status,
     *                 contract_invoice_dates, pricing_model, scheduled_date, test_text
     */
    public static function classify(array $v, string $today): string
    {
        if (stripos((string)($v['test_text'] ?? ''), 'ZZTEST') !== false) return 'test';

        $invStatus = strtolower((string)($v['invoice_status'] ?? ''));
        $linkedById = !empty($v['invoice_id']) && $invStatus !== '' && !in_array($invStatus, self::VOID, true);
        // Flagged invoiced with no invoice id: invoiced by hand, trust it. An id whose invoice is
        // void or gone is not a bill.
        $flagged = !empty($v['is_invoiced']) && empty($v['invoice_id']);
        if ($linkedById || $flagged || !empty($v['line_linked'])
            || (!empty($v['plan_invoiced']) && empty($v['is_recurring']))) {
            return 'invoiced';
        }

        if ($v['actual_amount'] !== null && $v['actual_amount'] !== '' && abs((float)$v['actual_amount']) < 0.005) return 'no_charge';

        $cycle = strtolower(trim((string)($v['billing_cycle'] ?? '')));
        if (!empty($v['contract_id']) && $cycle !== '' && $cycle !== 'per_visit') {
            return self::contractCovers($v, $cycle, $today) ? 'contract' : 'contract_gap';
        }

        if (in_array((string)($v['pricing_model'] ?? 'per_visit'), ['monthly_flat', 'seasonal', 'custom'], true)) return 'fixed_price';

        if ((string)($v['scheduled_date'] ?? '') !== '' && (string)$v['scheduled_date'] < self::CUTOVER_DATE) return 'before_crm';

        return 'unbilled';
    }

    /** Does the contract's invoice for this visit's period exist, or is it still to come? */
    public static function contractCovers(array $v, string $cycle, string $today): bool
    {
        $day = substr((string)($v['scheduled_date'] ?? ''), 0, 10);
        $dates = $v['contract_invoice_dates'] ?? [];
        $active = strtolower((string)($v['contract_status'] ?? '')) === 'active';
        if ($cycle === 'monthly') {
            foreach ($dates as $d) if (substr((string)$d, 0, 7) === substr($day, 0, 7)) return true;
            // This month's run may not exist yet for a contract that started this month; the cron
            // bills it on the 1st. Past months with no invoice are a gap.
            return $active && substr($day, 0, 7) >= substr($today, 0, 7);
        }
        // seasonal / annual / custom: billed once per season or year, often by hand.
        $t = strtotime($day);
        foreach ($dates as $d) if (abs(strtotime((string)$d) - $t) <= 366 * 86400) return true;
        return $active;
    }

    /** What invoicing this visit would charge, before tax (invoice-prefill.php's rule). */
    public static function value(array $v): float
    {
        $lines = $v['plan_lines_total'] ?? null;
        $base = $lines !== null && (float)$lines > 0
            ? (float)$lines
            : ((float)($v['actual_amount'] ?? 0) ?: ((float)($v['price_per_visit'] ?? 0) ?: (float)($v['estimated_amount'] ?? 0)));
        return round($base + max(0.0, (float)($v['extras_amount'] ?? 0)), 2);
    }

    /**
     * Count + value per bucket, the old count, and a plain sentence for the admin check.
     * contract_gap's value is the contract's billing_amount once per missing (contract, month)
     * for monthly contracts and once per contract otherwise — the money the missing invoice
     * would have carried, not a per-visit price.
     */
    public static function summarise(array $visits, string $today): array
    {
        $b = array_fill_keys(self::BUCKETS, ['count' => 0, 'amount' => 0.0]);
        $was = ['count' => 0, 'amount' => 0.0];
        $unbilledVisits = [];
        $gapPeriods = [];
        foreach ($visits as $v) {
            // The old definition: completed and (not flagged invoiced OR no invoice id).
            if (empty($v['is_invoiced']) || empty($v['invoice_id'])) {
                $was['count']++;
                $was['amount'] += (float)($v['actual_amount'] ?? $v['price_per_visit'] ?? 0);   // COALESCE(actual, plan price, 0)
            }
            $bucket = self::classify($v, $today);
            $b[$bucket]['count']++;
            if ($bucket === 'contract_gap') {
                $cycle = strtolower((string)($v['billing_cycle'] ?? ''));
                $k = (int)$v['contract_id'] . ':' . ($cycle === 'monthly' ? substr((string)$v['scheduled_date'], 0, 7) : '*');
                if (!isset($gapPeriods[$k])) {
                    $gapPeriods[$k] = ['contract_id' => (int)$v['contract_id'], 'contract_number' => (string)($v['contract_number'] ?? ''),
                                       'period' => $cycle === 'monthly' ? substr((string)$v['scheduled_date'], 0, 7) : $cycle,
                                       'amount' => round((float)($v['billing_amount'] ?? 0), 2)];
                    $b[$bucket]['amount'] += (float)($v['billing_amount'] ?? 0);
                }
                continue;
            }
            $amt = self::value($v);
            if ($bucket === 'no_charge') $amt = 0.0;
            $b[$bucket]['amount'] += $amt;
            if ($bucket === 'unbilled') {
                $unbilledVisits[] = ['visit_id' => (int)$v['id'], 'plan_id' => (int)$v['plan_id'], 'date' => (string)$v['scheduled_date'],
                                     'plan' => (string)($v['plan_title'] ?? ''), 'amount' => $amt];
            }
        }
        foreach ($b as &$x) $x['amount'] = round($x['amount'], 2);
        unset($x);
        $was['amount'] = round($was['amount'], 2);

        return [
            'count'    => $b['unbilled']['count'],
            'amount'   => $b['unbilled']['amount'],
            'was'      => $was,
            'buckets'  => $b,
            'gaps'     => array_values($gapPeriods),
            'visits'   => $unbilledVisits,
            'text'     => self::sentence($was['count'], $b),
        ];
    }

    /** "186 → 12 truly unbilled; 150 covered by contracts; 9 already invoiced; 15 no charge" */
    public static function sentence(int $was, array $b): string
    {
        $parts = [$was . ' → ' . $b['unbilled']['count'] . ' truly unbilled (' . self::money($b['unbilled']['amount']) . ')'];
        $labels = [
            'contract'     => 'covered by contract billing (monthly / seasonal)',
            'invoiced'     => 'already invoiced',
            'fixed_price'  => 'on flat-priced plans',
            'no_charge'    => 'no charge',
            'before_crm'   => 'before ' . self::CUTOVER_DATE . ' (billed in Jobber)',
            'test'         => 'test records',
            'contract_gap' => 'on contracts with no invoice for that period — check',
        ];
        foreach ($labels as $k => $label) {
            if ($b[$k]['count'] > 0) $parts[] = $b[$k]['count'] . ' ' . $label;
        }
        return implode('; ', $parts);
    }

    public static function money(float $n): string
    {
        return '$' . number_format($n, 2);
    }
}
