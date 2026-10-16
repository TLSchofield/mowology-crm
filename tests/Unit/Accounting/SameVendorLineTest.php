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
        $this->assertSame(10, $d['confidence']);
    }

    public function testAttachNeedsAgreeingTotals(): void
    {
        $this->assertTrue(BankImportService::amountsAgree(84.00, 84.00));
        $this->assertTrue(BankImportService::amountsAgree(100.00, 101.50), 'within 2%');
        $this->assertFalse(BankImportService::amountsAgree(134.40, 184.40));
        $this->assertTrue(BankImportService::vendorInDescription('Champion', 'Point of sale SQ CHAMPION LAWN MOWE'));
    }
}
