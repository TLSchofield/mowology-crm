<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Split a receipt by line (migration 1233) — Tim's live case, Lawnboy #412 (2026-10-07):
 * $218.40 = seed 2 × $60 (shop stock, PST 7% = $8.40) + mulch 2 × $40 (Oakridge Gardens), GST $10.
 * The money per line, Penny's pre-fill, the journal entry, job profitability, trip attribution
 * and the GST report all read the same split.
 */
class ExpenseSplitServiceTest extends TestCase
{
    // ── The money ────────────────────────────────────────────────────────

    public function test_spread_sums_to_the_cent(): void
    {
        $s = ExpenseSplitService::spread(10.00, ['a' => 3, 'b' => 3, 'c' => 3]);
        $this->assertEqualsWithDelta(10.00, array_sum($s), 0.0001);
        $this->assertSame(['a' => 3.34, 'b' => 3.33, 'c' => 3.33], $s);
    }

    public function test_the_printed_pst_is_found_on_the_seed_only(): void
    {
        $this->assertSame(['seed'], ExpenseSplitService::pstSubset(['seed' => 120.0, 'mulch' => 80.0], 8.40));
        $this->assertNull(ExpenseSplitService::pstSubset(['a' => 100.0, 'b' => 100.0], 7.00), 'two lines fit — ambiguous, spread it');
        $this->assertNull(ExpenseSplitService::pstSubset(['a' => 120.0, 'b' => 80.0], 14.00), 'every line taxable — just spread it');
    }

    public function test_412_allocates_gst_by_net_and_pst_to_the_taxable_line(): void
    {
        $a = ExpenseSplitService::allocate([['key' => 229, 'net' => 120.0], ['key' => 230, 'net' => 80.0]], 218.40, 10.00, 8.40);
        $this->assertSame(['net' => 120.0, 'gst' => 6.0, 'pst' => 8.4, 'pst_rule' => 'printed'], $a[229]);
        $this->assertSame(['net' => 80.0, 'gst' => 4.0, 'pst' => 0.0, 'pst_rule' => 'printed'], $a[230]);
        $sum = 0.0;
        foreach ($a as $x) $sum += $x['net'] + $x['gst'] + $x['pst'];
        $this->assertEqualsWithDelta(218.40, $sum, 0.0001, 'the split is the receipt, to the cent');
    }

    public function test_the_owners_pst_tick_wins_and_lines_off_the_receipt_net_are_scaled(): void
    {
        $a = ExpenseSplitService::allocate([['key' => 1, 'net' => 50.0, 'pst_taxable' => false], ['key' => 2, 'net' => 50.0, 'pst_taxable' => true]], 112.00, 5.00, 7.00);
        $this->assertSame([0.0, 7.0], [$a[1]['pst'], $a[2]['pst']]);
        // Lines add to $110 but the receipt's net is $100 (a $10 discount read as no line): spread by size.
        $b = ExpenseSplitService::allocate([['key' => 1, 'net' => 60.0], ['key' => 2, 'net' => 50.0]], 105.00, 5.00, 0.0);
        $this->assertEqualsWithDelta(100.00, $b[1]['net'] + $b[2]['net'], 0.0001);
    }

    public function test_job_cost_is_net_plus_pst_plus_gst_not_claimable(): void
    {
        $this->assertSame(80.00, ExpenseSplitService::jobCost(80.00, 4.00, 0.00), 'Oakridge: $80 of mulch — its GST is an ITC');
        $this->assertSame(128.40, ExpenseSplitService::jobCost(120.00, 6.00, 8.40), 'the seed: PST is cost');
        $this->assertSame(10.50, ExpenseSplitService::jobCost(10.00, 1.00, 0.00, true, 0.5), 'a coffee: half its GST is cost');
    }

    // ── Penny's pre-fill ────────────────────────────────────────────────

    public function test_penny_prefills_each_line_by_tims_rules(): void
    {
        $oak = ['plan_id' => 501, 'label' => 'Oakridge Gardens', 'quote_lines' => [['product_id' => 11, 'service_type' => 'Mulching', 'description' => 'Black bark mulch']]];
        $h = ['accounting_category' => 'Materials', 'job_id' => null, 'asset_tag' => null];
        $mulch = ExpenseSplitService::proposeLine(['name' => 'Black Composted Bark Mulch', 'line_total' => 80, 'product_id' => 11, 'track_inventory' => 1], [$oak], null, $h);
        $this->assertSame([501, 'Materials', false, 'job_quote'], [$mulch['job_id'], $mulch['accounting_category'], $mulch['is_stock'], $mulch['rule']],
                          'on the job\'s quote → the job, even though the product is stocked');
        $seed = ExpenseSplitService::proposeLine(['name' => 'Richardson Sun & Shade Lawn Seed 5 kg', 'line_total' => 120, 'product_id' => 44, 'track_inventory' => 1], [$oak], null, $h);
        $this->assertSame([null, true, 'stock_product'], [$seed['job_id'], $seed['is_stock'], $seed['rule']]);
        $diesel = ExpenseSplitService::proposeLine(['name' => 'CLEAR DSL 62.1L', 'line_total' => 98.20], [$oak], null, $h);
        $this->assertSame(['Fuel', 'truck'], [$diesel['accounting_category'], $diesel['asset_tag']], 'diesel → the Dodge Ram');
        $gas = ExpenseSplitService::proposeLine(['name' => 'REG UNL 18.2 L', 'line_total' => 31.40], [$oak], null, $h);
        $this->assertSame(['Fuel', 'equipment'], [$gas['accounting_category'], $gas['asset_tag']], 'gas under $50 → the equipment');
        $coffee = ExpenseSplitService::proposeLine(['name' => 'BISTRO 24OZ COFFEE', 'line_total' => 3.29], [$oak], null, $h);
        $this->assertSame('Meals', $coffee['accounting_category']);
        $this->assertFalse(ExpenseSplitService::isFood('Water hose 50 ft'), '"water" alone is not a drink');
    }

    public function test_what_tim_did_before_wins(): void
    {
        $lesson = ['accounting_category' => 'Tools/Equipment', 'asset_tag' => null, 'is_stock' => 0, 'to_job' => 0, 'times_seen' => 1];
        $p = ExpenseSplitService::proposeLine(['name' => 'Trimmer line .095', 'line_total' => 24.99], [], $lesson, ['accounting_category' => 'Materials']);
        $this->assertSame(['Tools/Equipment', 'learned'], [$p['accounting_category'], $p['rule']]);
    }

    public function test_propose_412_from_the_database(): void
    {
        $db = ExpenseGateTestDb::make();
        ExpenseGateTestDb::lawnboy412($db);
        $p = (new ExpenseSplitService($db))->propose(412);
        $this->assertTrue($p['suggest'], 'mulch for a job and seed for stock — Penny suggests the split');
        $by = array_column($p['lines'], null, 'line_item_id');
        $this->assertSame(501, $by[230]['job_id']);
        $this->assertTrue($by[229]['is_stock']);
        $this->assertSame([6.0, 8.4], [$by[229]['gst'], $by[229]['pst']]);
        $this->assertSame('Oakridge Gardens (JOB-2026-0501)', $p['jobs'][0]['label']);
    }

    // ── The books ───────────────────────────────────────────────────────

    private function saved412(): PDO
    {
        $db = ExpenseGateTestDb::make();
        ExpenseGateTestDb::lawnboy412($db, 'approved');
        (new ExpenseSplitService($db))->save(412, ExpenseGateTestDb::split412(), 6);
        return $db;
    }

    public function test_one_journal_entry_one_debit_per_line_with_the_job_on_the_mulch(): void
    {
        $db = $this->saved412();
        $ledger = new LedgerService($db);
        $args = $ledger->withAllocations(['id' => 412, 'date' => '2026-10-07', 'net' => 200, 'gst' => 10, 'pst' => 8.40,
                                          'expense_account' => '5200', 'funding' => '2400', 'vendor_id' => 7]);
        $entry = $ledger->buildExpenseEntry($args);
        $lines = array_map(function ($l) { return [$l['account'], $l['debit'], $l['credit'], $l['job_id'] ?? null]; }, $entry['lines']);
        $this->assertSame([
            ['5200', 128.4, 0.0, null], // seed: net + PST, shop stock, no job
            ['5200', 80.0, 0.0, 501],   // mulch on Oakridge
            ['2210', 10.0, 0, null],    // GST ITC
            ['2400', 0, 218.4, null],   // the card
        ], $lines);
        $ledger->validateEntry(['lines' => array_map(function ($l) { return $l + ['account_id' => 1]; }, $entry['lines'])]);   // balances

        $id = $ledger->postExpense(['id' => 412, 'date' => '2026-10-07', 'net' => 200, 'gst' => 10, 'pst' => 8.40, 'expense_account' => '5200', 'funding' => '2400']);
        $posted = $db->query("SELECT jl.debit, jl.job_id FROM journal_lines jl WHERE jl.entry_id = {$id} AND jl.job_id = 501")->fetch();
        $this->assertSame(80.0, (float)$posted['debit'], 'the nightly post picks the split up by itself');
    }

    public function test_a_meals_share_claims_half_its_gst(): void
    {
        $entry = (new LedgerService(ExpenseGateTestDb::make()))->buildExpenseEntry(['id' => 9, 'date' => '2026-10-07', 'funding' => '2400', 'meals_itc_rate' => 0.5,
            'allocations' => [['account' => '6100', 'net' => 80.00, 'gst' => 4.00, 'pst' => 0, 'meals' => false],
                              ['account' => '6150', 'net' => 10.00, 'gst' => 0.50, 'pst' => 0, 'meals' => true]]]);
        $by = [];
        foreach ($entry['lines'] as $l) $by[$l['account']] = $l['debit'] ?: -$l['credit'];
        $this->assertSame(['6100' => 80.0, '6150' => 10.25, '2210' => 4.25, '2400' => -94.5], $by);
    }

    public function test_job_profitability_takes_the_mulch_not_the_whole_receipt(): void
    {
        $db = $this->saved412();
        // The legacy transaction row carries the whole receipt on the header's job.
        $db->exec("INSERT INTO accounting_transactions (transaction_date, type, account_id, amount, reference_type, reference_id, job_id, status)
                   VALUES ('2026-10-07', 'expense', 4, 218.40, 'expense', 412, 501, 'cleared'),
                          ('2026-10-07', 'income', 8, 950.00, 'invoice', 88, 501, 'cleared')");
        $rows = (new AccountingService($db))->withSplitReceiptShares([['job_id' => 501, 'revenue' => 950.0, 'expenses' => 218.40]], '2026-10-01', '2026-10-31');
        $this->assertSame(80.0, $rows[0]['expenses']);
        $this->assertSame(80.0, $rows[0]['split_receipt_cost']);
    }

    public function test_trip_attribution_reads_the_split(): void
    {
        $db = $this->saved412();
        $r = (new TripAttributionService($db))->receipts([412]);
        $res = TripAttributionService::attributeRun(['id' => 1, 'run_date' => '2026-10-07', 'kind' => 'supplier', 'labour_cost' => 20, 'truck_cost' => 10, 'property_id' => 31],
                                                    ['plan_id' => 501, 'visit_id' => 9001, 'quote_lines' => []], $r);
        $lines = array_values(array_filter($res['rows'], function ($x) { return $x['source'] === 'receipt_line'; }));
        $this->assertSame([[null, 128.4, 1], [501, 80.0, 0]], array_map(function ($x) { return [$x['job_plan_id'], $x['amount'], $x['is_stock']]; }, $lines));
        $this->assertSame([], $res['tag'], 'a split receipt is never tagged whole');
        $this->assertTrue($res['trip_to_job'], 'mulch bought for Oakridge — the run is the job\'s');
    }

    public function test_gst_report_counts_a_split_by_its_shares(): void
    {
        $rows = [['id' => 412, 'gst_amount' => 10.0, 'category' => 'Materials', 'description' => null, 'amount' => 200, 'total' => 218.40]];
        $split = [412 => [['label' => 'Coffee', 'net_amount' => 10.0, 'gst_amount' => 0.5, 'pst_amount' => 0, 'accounting_category' => 'Meals'],
                          ['label' => 'Seed', 'net_amount' => 190.0, 'gst_amount' => 9.5, 'pst_amount' => 8.4, 'accounting_category' => 'Materials']]];
        $out = GstReportService::expandSplits($rows, $split);
        $this->assertSame([['Meals', 0.5], ['Materials', 9.5]], array_map(function ($r) { return [$r['category'], $r['gst_amount']]; }, $out));
        $sum = GstReportService::summarize([], $out, ['meals_categories' => ['Meals'], 'meals_rate' => 0.5]);
        $this->assertSame(9.75, $sum['line_106'], 'the coffee\'s GST is limited, the seed\'s claimed in full');
    }

    public function test_repost_plan_compares_a_split_receipt_with_its_split_recipe(): void
    {
        $entry = (new LedgerService(ExpenseGateTestDb::make()))->buildExpenseEntry(['id' => 412, 'date' => '2026-10-07', 'funding' => '2400',
            'allocations' => [['account' => '5200', 'net' => 120, 'gst' => 6, 'pst' => 8.4], ['account' => '5200', 'net' => 80, 'gst' => 4, 'pst' => 0, 'job_id' => 501]]]);
        $this->assertSame(['5200' => 208.4], LedgerRepostService::categoryMap($entry));
    }
}
