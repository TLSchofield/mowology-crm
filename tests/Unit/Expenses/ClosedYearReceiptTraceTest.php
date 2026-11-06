<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** A 2025 receipt traces itself to its bank line only when the line is certain (Tim, 2026-11-06). */
final class ClosedYearReceiptTraceTest extends TestCase
{
    public function testOneExactLineIsPicked(): void
    {
        $c = [['transaction_id' => 5360, 'amount' => 59.90, 'confidence' => 90], ['transaction_id' => 9, 'amount' => 59.40, 'confidence' => 60]];
        $this->assertSame(5360, ClosedYearReceiptTrace::pick($c, 59.90)['transaction_id']);
    }

    public function testNoExactLineIsNoPick(): void
    {
        // Within the candidate window but not the exact amount (a misread total): a person decides.
        $this->assertNull(ClosedYearReceiptTrace::pick([['transaction_id' => 5377, 'amount' => 41.00, 'confidence' => 90]], 39.05));
        $this->assertNull(ClosedYearReceiptTrace::pick([], 10.04));
    }

    public function testTwoExactLinesAreLeftToAPerson(): void
    {
        $two = [['transaction_id' => 1, 'amount' => 12.00, 'confidence' => 90], ['transaction_id' => 2, 'amount' => 12.00, 'confidence' => 40]];
        $this->assertNull(ClosedYearReceiptTrace::pick($two, 12.00));
    }

    public function testOnlyOpenClosedYearReceiptsApply(): void
    {
        $this->assertTrue(ClosedYearReceiptTrace::applies(['status' => 'pending_approval', 'expense_date' => '2025-04-02', 'total' => '59.90']));
        $this->assertTrue(ClosedYearReceiptTrace::applies(['status' => 'draft', 'expense_date' => '2025-12-31', 'total' => '1.00']));
        $this->assertFalse(ClosedYearReceiptTrace::applies(['status' => 'pending_approval', 'expense_date' => '2026-01-01', 'total' => '9.00']));
        $this->assertFalse(ClosedYearReceiptTrace::applies(['status' => 'approved', 'expense_date' => '2025-04-02', 'total' => '9.00']));
        $this->assertFalse(ClosedYearReceiptTrace::applies(['status' => 'rejected', 'expense_date' => '2025-04-02', 'total' => '9.00']));
        $this->assertFalse(ClosedYearReceiptTrace::applies(['status' => 'draft', 'expense_date' => '2025-04-02', 'total' => '0']));
        $this->assertFalse(ClosedYearReceiptTrace::applies(['status' => 'draft', 'expense_date' => '2025-04-02', 'total' => '9.00', 'payment_method' => 'owner_paid']));
    }
}
