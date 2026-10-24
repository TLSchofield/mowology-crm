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
}
