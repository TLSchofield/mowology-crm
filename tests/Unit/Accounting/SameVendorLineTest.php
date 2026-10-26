<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Same-vendor bank lines at any amount, and attach only when totals agree (Champion $184.40 vs $134.40, 2026-10-15). */
class SameVendorLineTest extends TestCase
{
    public function testAMisreadTotalIsShownWithWhatToCheck(): void
    {
        $c = BankImportService::vendorLine(['expense_date' => '2026-09-01', 'total' => 134.40],
            ['id' => 36605, 'transaction_date' => '2026-08-31', 'description' => 'Point of sale SQ CHAMPION LAWN MOWE', 'amount' => 184.40]);
        $this->assertTrue($c['vendor_only']);
        $this->assertSame(29, $c['confidence'], 'low — always below an amount match');
        $this->assertSame(['Same vendor', 'Different amount ($184.40 vs $134.40) — check the total'], $c['reasons']);
        $d = BankImportService::vendorLine(['expense_date' => '2026-04-02', 'total' => 84.0],
            ['id' => 73059, 'transaction_date' => '2026-04-28', 'description' => 'LAWNBOY', 'amount' => 84.0]);
        $this->assertStringContainsString('check the date', $d['reasons'][1]);
        $this->assertSame(25, $d['confidence'], 'same amount is a real lead (only the date is off): never tied with a different amount');
    }

    public function testSameAmountOutranksADifferentAmountEvenWhenFurtherAway(): void
    {
        $expense = ['expense_date' => '2026-06-22', 'total' => 48.13];
        $far  = BankImportService::vendorLine($expense, ['id' => 1, 'transaction_date' => '2026-05-27', 'description' => 'DUNBAR LUMBER', 'amount' => 48.13]);
        $near = BankImportService::vendorLine($expense, ['id' => 2, 'transaction_date' => '2026-06-01', 'description' => 'DUNBAR LUMBER', 'amount' => 634.86]);
        $this->assertGreaterThan($near['confidence'], $far['confidence']);
        $this->assertTrue($far['same_amount']);
        $this->assertFalse($near['same_amount']);
    }

    public function testThePrintedReceiptDateCountsAsAnAnchor(): void
    {
        $c = BankImportService::vendorLine(['expense_date' => '2026-06-22', 'printed_date' => '2026-05-27', 'total' => 48.13],
            ['id' => 1, 'transaction_date' => '2026-05-27', 'description' => 'DUNBAR LUMBER', 'amount' => 48.13]);
        $this->assertSame(45, $c['confidence'], '0 days from the printed date');
        $this->assertStringContainsString('0 days apart', $c['reasons'][1]);
    }

    public function testAttachNeedsAgreeingTotals(): void
    {
        $this->assertTrue(BankImportService::amountsAgree(84.00, 84.00));
        $this->assertTrue(BankImportService::amountsAgree(100.00, 101.50), 'within 2%');
        $this->assertFalse(BankImportService::amountsAgree(134.40, 184.40));
        $this->assertTrue(BankImportService::vendorInDescription('Champion', 'Point of sale SQ CHAMPION LAWN MOWE'));
    }

    public function testATipOnAMealIsSuggestedAndTheTaxIsLeftAlone(): void
    {
        // Moxies 2026-02-28: receipt $90.69 (85.25 + 4.26 GST + 1.18 PST), bank charged $107.01.
        $e = ['expense_date' => '2026-02-28', 'total' => 90.69, 'amount' => 85.25, 'gst_amount' => 4.26, 'pst_amount' => 1.18, 'accounting_category' => 'Meals'];
        $t = BankImportService::tipSuggestion($e, 107.01, '2026-02-28');
        $this->assertSame(16.32, $t['tip']);
        $this->assertSame(101.57, $t['amount'], 'pre-tax amount takes the tip');
        $this->assertSame(107.01, $t['total']);
        $this->assertEqualsWithDelta(101.57 + 4.26 + 1.18, 107.01, 0.001, 'amount + the unchanged tax = the bank charge');
        $this->assertEqualsWithDelta(19.1, $t['pct'], 0.1);
    }

    public function testNoTipWhenItIsNotAMealOrNotPlausible(): void
    {
        $e = ['expense_date' => '2026-02-28', 'total' => 90.69, 'amount' => 85.25, 'gst_amount' => 4.26, 'pst_amount' => 1.18, 'accounting_category' => 'Meals'];
        $this->assertNull(BankImportService::tipSuggestion(['accounting_category' => 'Fuel'] + $e, 107.01, '2026-02-28'), 'not a meal');
        $this->assertNull(BankImportService::tipSuggestion($e, 90.69, '2026-02-28'), 'no difference');
        $this->assertNull(BankImportService::tipSuggestion($e, 91.20, '2026-02-28'), 'under a dollar');
        $this->assertNull(BankImportService::tipSuggestion($e, 190.69, '2026-02-28'), 'a 117% "tip" is a different purchase');
        $this->assertNull(BankImportService::tipSuggestion($e, 107.01, '2026-03-20'), 'weeks away');
        $this->assertNull(BankImportService::tipSuggestion($e, 80.00, '2026-02-28'), 'the bank charged less');
    }

    public function testAVendorLineCarriesTheTipAndRanksAboveAPlainDifferentAmount(): void
    {
        $e = ['expense_date' => '2026-02-27', 'total' => 22.52, 'amount' => 21.45, 'gst_amount' => 1.07, 'pst_amount' => 0.0, 'accounting_category' => 'Meals'];
        $tipped = BankImportService::vendorLine($e, ['id' => 1, 'transaction_date' => '2026-02-27', 'description' => 'AH LONG SUSHI', 'amount' => 27.02]);
        $plain  = BankImportService::vendorLine($e, ['id' => 2, 'transaction_date' => '2026-02-27', 'description' => 'AH LONG SUSHI', 'amount' => 60.00]);
        $this->assertSame(4.5, $tipped['tip']['tip']);
        $this->assertStringContainsString('Adds a $4.50 tip', $tipped['reasons'][1]);
        $this->assertNull($plain['tip']);
        $this->assertGreaterThan($plain['confidence'], $tipped['confidence']);
        $this->assertFalse($tipped['same_amount']);
    }

    public function testAReceiptThatDoesNotAddUpGetsNoTipGuess(): void
    {
        // pre-tax 100 + GST 5 = 105, but the saved total is 120 (a tip is already in it): leave it alone.
        $e = ['expense_date' => '2026-02-28', 'total' => 120.0, 'amount' => 100.0, 'gst_amount' => 5.0, 'pst_amount' => 0.0, 'accounting_category' => 'Meals'];
        $this->assertNull(BankImportService::tipSuggestion($e, 130.0, '2026-02-28'));
    }

    public function testOnlyTheMealsCategoryAndCalendarDaysCount(): void
    {
        $e = ['expense_date' => '2026-10-31', 'total' => 105.0, 'amount' => 100.0, 'gst_amount' => 5.0, 'pst_amount' => 0.0, 'accounting_category' => 'Meals'];
        $this->assertNotNull(BankImportService::tipSuggestion($e, 120.0, '2026-11-03'), 'three calendar days across the clock change');
        $this->assertNull(BankImportService::tipSuggestion(['accounting_category' => 'Meal preparation supplies'] + $e, 120.0, '2026-10-31'));
        $this->assertNotNull(BankImportService::tipSuggestion(['accounting_category' => 'Meals & Entertainment'] + $e, 120.0, '2026-10-31'));
    }

    private function sameVendorLinesFor(array $expense, array $rows): array
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn($rows);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);
        $m = new ReflectionMethod(BankImportService::class, 'sameVendorLines');
        $m->setAccessible(true);
        return $m->invoke(new BankImportService($pdo), $expense, []);
    }

    public function testTwoPossibleTipChargesAreNotGuessedBetween(): void
    {
        $e = ['expense_date' => '2026-10-10', 'total' => 21.0, 'amount' => 20.0, 'gst_amount' => 1.0, 'pst_amount' => 0.0, 'accounting_category' => 'Meals', 'vendor_name_raw' => 'Moxies', 'vendor_name' => null];
        $row = fn(int $id, string $d, float $a) => ['id' => $id, 'transaction_date' => $d, 'description' => 'POINTOFSALE(MOXIES014VANCOUVERBCCA)', 'amount' => $a, 'bank_account' => 'Visa'];
        $one = $this->sameVendorLinesFor($e, [$row(1, '2026-10-10', 25.0)]);
        $this->assertSame(4.0, $one[0]['tip']['tip'], 'a single charge is offered as the tip');
        $two = $this->sameVendorLinesFor($e, [$row(1, '2026-10-10', 25.0), $row(2, '2026-10-11', 26.0)]);
        $this->assertCount(2, $two);
        foreach ($two as $l) {
            $this->assertNull($l['tip'], 'two visits: no guess');
            $this->assertStringContainsString('Different amount', $l['reasons'][1]);
        }
    }
}

