<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * OverheadSuggestionService — the pure maths behind "Penny suggests" on the Cost Factors page.
 * DB loaders are not tested here; everything is fed canned ledger / item rows.
 */
class OverheadSuggestionServiceTest extends TestCase
{
    private function item(int $id, string $cat, string $name, float $amount, string $freq = 'monthly', int $active = 1): array
    {
        return ['id' => $id, 'category' => $cat, 'item_name' => $name, 'amount' => $amount, 'frequency' => $freq, 'is_active' => $active];
    }

    private function row(string $cat, string $vendor, float $total, int $posts = 12, int $months = 12): array
    {
        return ['category' => $cat, 'vendor' => $vendor, 'account_name' => '', 'account_code' => '6700', 'total' => $total, 'posts' => $posts, 'months_active' => $months];
    }

    // ── frequency maths (ported from the page's FREQ_MULTIPLIER) ──────────────────────────

    public function testToMonthlyMatchesThePagesMultipliers(): void
    {
        $this->assertEqualsWithDelta(35.25, OverheadSuggestionService::toMonthly(423.0, 'annual'), 0.01);   // Business License on the page
        $this->assertEqualsWithDelta(75.0, OverheadSuggestionService::toMonthly(75.0, 'monthly'), 0.001);
        $this->assertEqualsWithDelta(100.0, OverheadSuggestionService::toMonthly(300.0, 'quarterly'), 0.001);
        $this->assertEqualsWithDelta(433.3, OverheadSuggestionService::toMonthly(100.0, 'weekly'), 0.01);
        $this->assertSame(50.0, OverheadSuggestionService::toMonthly(50.0, 'bogus'));                        // unknown frequency = monthly
    }

    public function testFromMonthlyIsTheInverse(): void
    {
        foreach (['weekly', 'monthly', 'quarterly', 'annual'] as $f) {
            $m = OverheadSuggestionService::toMonthly(123.45, $f);
            $this->assertEqualsWithDelta(123.45, OverheadSuggestionService::fromMonthly($m, $f), 0.001, $f);
        }
    }

    // ── observation window ────────────────────────────────────────────────────────────────

    public function testMonthsObservedIsCountedFromTheFirstLedgerMonthAndCapped(): void
    {
        $this->assertSame(8, OverheadSuggestionService::monthsObserved('2026-02-25', '2026-09-30'));   // Feb..Sep inclusive
        $this->assertSame(12, OverheadSuggestionService::monthsObserved('2020-01-01', '2026-10-20'));  // old ledger: capped at the window
        $this->assertSame(1, OverheadSuggestionService::monthsObserved('2026-10-02', '2026-10-20'));
        $this->assertSame(1, OverheadSuggestionService::monthsObserved('2027-01-01', '2026-10-20'));   // first date in the future: never 0 / negative
    }

    // ── account → category ────────────────────────────────────────────────────────────────

    public function testUnmappedAndOwnerPayAccountsAreNeverGuessed(): void
    {
        $this->assertSame('insurance', OverheadSuggestionService::categoryForAccount('6300', 'Business Insurance'));
        $this->assertSame('vehicle', OverheadSuggestionService::categoryForAccount('6100', 'Fuel & Vehicle'));
        $this->assertSame('insurance', OverheadSuggestionService::categoryForAccount('6110', 'Vehicle Insurance'));
        $this->assertSame('variable', OverheadSuggestionService::categoryForAccount('6250', 'PPE & Safety Supplies'));
        $this->assertNull(OverheadSuggestionService::categoryForAccount('6900', 'Miscellaneous Expenses'));  // no obvious home → reported, not guessed
        $this->assertNull(OverheadSuggestionService::categoryForAccount('6100', 'Owner Draw'));           // owner pay never overhead
        $this->assertNull(OverheadSuggestionService::categoryForAccount('6800', 'Payroll Wages'));
    }

    // ── item matching ─────────────────────────────────────────────────────────────────────

    public function testItemHitsMatchesLooseNamesAndSkipsInactive(): void
    {
        $items = [$this->item(1, 'admin', 'Phone Plan', 290.75), $this->item(2, 'vehicle', 'Phone Mount', 5.0), $this->item(3, 'admin', 'Phone Old', 9.0, 'monthly', 0)];
        $ids = array_column(OverheadSuggestionService::itemHits('Telus Mobility Phone', $items), 'id');
        $this->assertSame([1, 2], $ids);                                      // two hits = ambiguous; inactive item ignored
        $this->assertSame([], OverheadSuggestionService::itemHits('Home Depot', $items));
        $this->assertSame([], OverheadSuggestionService::itemHits('', $items));
    }

    // ── threshold ─────────────────────────────────────────────────────────────────────────

    public function testSmallDriftIsNotWorthTheOwnersClick(): void
    {
        $this->assertFalse(OverheadSuggestionService::worthSuggesting(75.0, 77.0));     // $2 < $5
        $this->assertFalse(OverheadSuggestionService::worthSuggesting(1000.0, 1050.0)); // $50 but 5% < 10%
        $this->assertTrue(OverheadSuggestionService::worthSuggesting(290.75, 340.0));   // 17%
        $this->assertTrue(OverheadSuggestionService::worthSuggesting(0.0, 20.0));       // nothing there yet
    }

    // ── item suggestions ──────────────────────────────────────────────────────────────────

    public function testUpdateSuggestionKeepsTheItemsOwnFrequency(): void
    {
        // Business License is annual $423 ($35.25/mo). Ledger shows $720 over 12 months = $60/mo.
        $items  = [$this->item(7, 'admin', 'Business License', 423.0, 'annual')];
        $ledger = [$this->row('admin', 'City Business License', 720.0, 1, 3)];
        $r = OverheadSuggestionService::suggestItems($ledger, $items, 12);
        $this->assertCount(1, $r['items']);
        $s = $r['items'][0];
        $this->assertSame('update', $s['kind']);
        $this->assertSame(7, $s['item_id']);
        $this->assertSame('annual', $s['frequency']);
        $this->assertEqualsWithDelta(35.25, $s['current_monthly'], 0.01);
        $this->assertEqualsWithDelta(60.0, $s['suggested_monthly'], 0.01);
        $this->assertEqualsWithDelta(720.0, $s['suggested_amount'], 0.01);   // back in the item's own unit: $/year
        $this->assertStringContainsString('12 months', $s['source']);
    }

    public function testYoungLedgerIsAveragedOverTheMonthsItHas(): void
    {
        // $2,400 of insurance over a ledger that only covers 8 months = $300/mo, not $200/mo.
        $items  = [$this->item(3, 'insurance', 'Commercial Insurance', 150.0)];
        $ledger = [$this->row('insurance', 'ICBC Insurance', 2400.0, 8, 8)];
        $r = OverheadSuggestionService::suggestItems($ledger, $items, 8);
        $this->assertEqualsWithDelta(300.0, $r['items'][0]['suggested_monthly'], 0.01);
    }

    public function testUnchangedFigureProducesNoSuggestion(): void
    {
        $items  = [$this->item(2, 'admin', 'Internet', 75.0)];
        $ledger = [$this->row('admin', 'Shaw Internet', 900.0)];   // $75/mo exactly
        $r = OverheadSuggestionService::suggestItems($ledger, $items, 12);
        $this->assertSame([], $r['items']);
        $this->assertSame([], $r['unmatched_items']);              // it IS supported by the ledger
    }

    public function testOneOffSpendIsNotRecurringOverhead(): void
    {
        $r = OverheadSuggestionService::suggestItems([$this->row('admin', 'Staples', 600.0, 1, 1)], [], 12);
        $this->assertSame([], $r['items']);                        // seen in 1 month < MIN_MONTHS_ACTIVE
    }

    public function testRecurringVendorOnNoItemIsANoteNeverAnAcceptableChange(): void
    {
        $r = OverheadSuggestionService::suggestItems([$this->row('admin', 'QuickBooks Online', 540.0, 12, 12)], [], 12);
        $this->assertSame([], $r['items']);                                    // nothing to one-click: could double-count a budget line
        $this->assertSame('QuickBooks Online', $r['new_vendors'][0]['vendor']);
        $this->assertEqualsWithDelta(45.0, $r['new_vendors'][0]['monthly'], 0.01);
    }

    public function testItemWithNoLedgerSupportIsReportedNotChanged(): void
    {
        $items = [$this->item(5, 'admin', 'Phone Plan', 290.75), $this->item(6, 'admin', 'Old Thing', 40.0, 'monthly', 0)];
        $r = OverheadSuggestionService::suggestItems([], $items, 12);
        $this->assertSame([], $r['items']);
        $this->assertCount(1, $r['unmatched_items']);              // inactive items are not reported
        $this->assertSame(5, $r['unmatched_items'][0]['item_id']);
        $this->assertEqualsWithDelta(290.75, $r['unmatched_items'][0]['current_monthly'], 0.001);
    }

    // ── vendor start dates and periodic (annual / quarterly) items ──────────────────────────

    public function testVendorThatStartedLateIsAveragedOverItsOwnMonths(): void
    {
        // Insurance began in month 2 of a 6-month ledger: 5 postings of $150 is $150/mo, not $125/mo.
        $items  = [$this->item(3, 'insurance', 'Commercial Insurance', 100.0)];
        $row    = $this->row('insurance', 'ICBC Insurance', 750.0, 5, 5) + ['span_months' => 5];
        $r = OverheadSuggestionService::suggestItems([$row], $items, 6);
        $this->assertEqualsWithDelta(150.0, $r['items'][0]['suggested_monthly'], 0.01);
    }

    public function testAnnualItemWithOnePostingIsSupportedButNotJudgedOnAPartialYear(): void
    {
        $items = [$this->item(8, 'admin', 'Business License', 423.0, 'annual')];
        $row   = $this->row('admin', 'City Business License', 450.0, 1, 1);
        $r = OverheadSuggestionService::suggestItems([$row], $items, 6);       // only 6 months of ledger
        $this->assertSame([], $r['items']);                                    // can't confirm a yearly amount yet
        $this->assertSame([], $r['unmatched_items']);                          // and it is not called unsupported
    }

    public function testAnnualItemWithNoPostingIsNotFlaggedUntilAFullYear(): void
    {
        $items = [$this->item(8, 'admin', 'Business License', 423.0, 'annual')];
        $this->assertSame([], OverheadSuggestionService::suggestItems([], $items, 6)['unmatched_items']);
        $flagged = OverheadSuggestionService::suggestItems([], $items, 12)['unmatched_items'];
        $this->assertSame([8], array_column($flagged, 'item_id'));             // a full year and still nothing: say so
    }

    public function testSingleOneOffPostingDoesNotSupportAMonthlyItem(): void
    {
        $items = [$this->item(2, 'admin', 'Office Supplies', 80.0)];
        $r = OverheadSuggestionService::suggestItems([$this->row('admin', 'Staples Office', 600.0, 1, 1)], $items, 12);
        $this->assertSame([], $r['items']);                                    // a one-off must not move a monthly figure
        $this->assertSame([2], array_column($r['unmatched_items'], 'item_id'));
    }

    // ── ambiguity, account-name matching, informational lists ───────────────────────────────

    public function testSpendThatFitsSeveralItemsIsAmbiguousNotFoldedOntoOne(): void
    {
        // Real case 2026-10-20: all "Vehicle Insurance" spend landed on one of five insurance items (+$567/mo wrong).
        $items = [$this->item(1, 'insurance', 'Mighty Truck Vehicle Insurance', 133.93), $this->item(2, 'insurance', 'RAM 3500 Insurance', 197.59)];
        $row   = ['account_name' => 'Vehicle Insurance'] + $this->row('insurance', '(no vendor) Vehicle Insurance', 8400.0, 37, 12);
        $r = OverheadSuggestionService::suggestItems([$row], $items, 12);
        $this->assertSame([], $r['items']);
        $this->assertSame('Vehicle Insurance', $r['ambiguous'][0]['label']);
        $this->assertEqualsWithDelta(700.0, $r['ambiguous'][0]['monthly'], 0.01);
        $this->assertCount(2, $r['ambiguous'][0]['candidates']);
        $this->assertSame([], $r['unmatched_items']);                          // both items ARE plausibly supported
    }

    public function testAmbiguityFromOneAccountIsOneLineHoweverManyVendors(): void
    {
        $items = [$this->item(1, 'insurance', 'Mighty Truck Vehicle Insurance', 100.0), $this->item(2, 'insurance', 'RAM 3500 Insurance', 100.0)];
        $mk = function ($vendor, $total) { return ['account_name' => 'Vehicle Insurance'] + $this->row('insurance', $vendor, $total, 12, 12); };
        $r = OverheadSuggestionService::suggestItems([$mk('ICBC', 1200.0), $mk('Sovereign', 600.0), $mk('(no vendor) Vehicle Insurance', 1200.0)], $items, 12);
        $this->assertCount(1, $r['ambiguous']);
        $this->assertSame('Vehicle Insurance', $r['ambiguous'][0]['label']);
        $this->assertEqualsWithDelta(250.0, $r['ambiguous'][0]['monthly'], 0.01);   // 100 + 50 + 100
        $this->assertSame(['ICBC', 'Sovereign'], $r['ambiguous'][0]['vendors']);
    }

    public function testNoVendorSpendMatchesAnItemThroughItsAccountName(): void
    {
        $items = [$this->item(4, 'admin', 'Bank Fees', 25.0)];
        $row   = ['account_name' => 'Bank Charges & Fees'] + $this->row('admin', '(no vendor) Bank Charges & Fees', 2088.0, 108, 12);
        $r = OverheadSuggestionService::suggestItems([$row], $items, 12);
        $this->assertSame(4, $r['items'][0]['item_id']);
        $this->assertEqualsWithDelta(174.0, $r['items'][0]['suggested_monthly'], 0.01);
    }

    public function testNoVendorSpendThatFitsNothingIsUnitemisedNotANewItem(): void
    {
        $row = ['account_name' => 'Office & Administration'] + $this->row('admin', '(no vendor) Office & Administration', 1560.0, 36, 10);
        $r = OverheadSuggestionService::suggestItems([$row], [], 12);
        $this->assertSame([], $r['items']);
        $this->assertSame([], $r['new_vendors']);
        $this->assertSame('Office & Administration', $r['unitemised'][0]['label']);
    }

    public function testVendorsAreNeverPairedWithAnItemByAmount(): void
    {
        // "Telus Mobility" vs "Phone Plan" share no word. A $340 vendor next to a $290 item is not proof: stay separate.
        $items = [$this->item(5, 'admin', 'Phone Plan', 290.75)];
        $r = OverheadSuggestionService::suggestItems([$this->row('admin', 'Telus Mobility', 4080.0, 12, 12)], $items, 12);
        $this->assertSame([], $r['items']);
        $this->assertSame('Telus Mobility', $r['new_vendors'][0]['vendor']);
        $this->assertSame([5], array_column($r['unmatched_items'], 'item_id'));
    }

    public function testSeveralHitsResolveToTheOneInTheSpendsOwnCategory(): void
    {
        // "Fuel & Vehicle" fits Fuel Budget (word "fuel") and a vehicle-insurance item (word "vehicle"); only one is vehicle spend.
        $items = [$this->item(1, 'vehicle', 'Fuel Budget', 400.0), $this->item(2, 'insurance', 'Mighty Truck Vehicle Insurance', 133.93)];
        $row   = ['account_name' => 'Fuel & Vehicle'] + $this->row('vehicle', 'Shell', 1200.0, 12, 12);
        $r = OverheadSuggestionService::suggestItems([$row], $items, 12);
        $this->assertSame(1, $r['items'][0]['item_id']);
        $this->assertEqualsWithDelta(100.0, $r['items'][0]['suggested_monthly'], 0.01);
        $this->assertSame([], $r['ambiguous']);
    }

    public function testVendorMatchBeatsAccountNameMatch(): void
    {
        $items = [$this->item(1, 'vehicle', 'Fuel Budget', 400.0), $this->item(2, 'vehicle', 'Vehicle Maintenance', 100.0)];
        $row   = ['account_name' => 'Fuel & Vehicle'] + $this->row('vehicle', 'Fuel Depot', 1200.0, 12, 12);   // vendor name hits one item
        $r = OverheadSuggestionService::suggestItems([$row], $items, 12);
        $this->assertSame(1, $r['items'][0]['item_id']);
        $this->assertSame([], $r['ambiguous']);
    }

    public function testEmptyLedgerAndEmptyItemsIsQuiet(): void
    {
        $this->assertSame(['items' => [], 'ambiguous' => [], 'new_vendors' => [], 'unitemised' => [], 'unmatched_items' => []], OverheadSuggestionService::suggestItems([], [], 12));
    }

    // ── settings suggestions ──────────────────────────────────────────────────────────────

    public function testSettingsAreMonthlyAveragesOfObservedTotals(): void
    {
        $obs = ['revenue' => 96000.0, 'completed_visits' => 960, 'job_minutes' => 60 * 1440.0];   // 12 months: $8k/mo, 80 visits/mo, 120 h/mo
        $cur = ['estimated_monthly_revenue' => 15000, 'estimated_jobs_per_month' => 120, 'estimated_billable_hours' => 160];
        $byKey = [];
        foreach (OverheadSuggestionService::suggestSettings($obs, $cur, 12) as $s) $byKey[$s['key']] = $s;
        $this->assertSame(8000.0, $byKey['estimated_monthly_revenue']['suggested']);
        $this->assertSame(15000.0, $byKey['estimated_monthly_revenue']['current']);
        $this->assertSame(80.0, $byKey['estimated_jobs_per_month']['suggested']);
        $this->assertSame(120.0, $byKey['estimated_billable_hours']['suggested']);
    }

    public function testNoDataIsNotASuggestionAndCloseFiguresAreSkipped(): void
    {
        $this->assertSame([], OverheadSuggestionService::suggestSettings(['revenue' => 0, 'completed_visits' => 0, 'job_minutes' => 0], [], 12));
        $obs = ['revenue' => 180000.0, 'completed_visits' => 0, 'job_minutes' => 0];            // $15,000/mo — same as current
        $this->assertSame([], OverheadSuggestionService::suggestSettings($obs, ['estimated_monthly_revenue' => 15000], 12));
    }
}
