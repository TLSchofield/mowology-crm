<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * GST that can't be right never reaches the books (receipt #114 — a $2.61 coffee read as $5.00 GST; owner
 * 2026-10-19: "surely if the gst is obviously wrong the receipt shouldnt save").
 */
class ExpenseTaxesBlockTest extends TestCase
{
    public function testWhatIsPossible(): void
    {
        $this->assertSame('impossible', ExpenseGate::taxCheck(['total' => 2.61, 'gst_amount' => 5.00])['level'], 'more than the total');
        $this->assertStringContainsString('about $0.12', (string)ExpenseGate::taxCheck(['total' => 2.61, 'gst_amount' => 5.00])['message']);
        $this->assertSame('impossible', ExpenseGate::taxCheck(['total' => 100.0, 'gst_amount' => 20.0])['level'], 'above any Canadian rate');
        $this->assertSame('high', ExpenseGate::taxCheck(['total' => 113.0, 'gst_amount' => 13.0])['level'], 'Ontario HST: possible, flagged');
        $this->assertNull(ExpenseGate::taxCheck(['total' => 2.61, 'gst_amount' => 0.12])['level']);
        $this->assertNull(ExpenseGate::taxCheck(['total' => 1800.95, 'gst_amount' => 80.40, 'pst_amount' => 112.56])['level'], 'the mower');
        $this->assertNull(ExpenseGate::taxCheck(['total' => 50.0, 'gst_amount' => 0.50])['level'], 'less is normal (zero-rated groceries)');
        $this->assertNull(ExpenseGate::taxCheck(['total' => 10.52, 'gst_amount' => 0.52])['level'], 'rounding');
        $this->assertSame('A,GST_WRONG', ExpenseGate::withTaxFlag('A,GST_CHECK', 'impossible'));
        $this->assertSame('A', ExpenseGate::withTaxFlag('a,gst_wrong', null));
    }
}
