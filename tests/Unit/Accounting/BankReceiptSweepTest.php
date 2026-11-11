<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Only clear bank-line ↔ receipt matches are linked in bulk.
 */
class BankReceiptSweepTest extends TestCase
{
    public function test_clear_means_strong_and_no_close_runner_up(): void
    {
        $this->assertTrue(BankReceiptSweep::isClear([['confidence' => 90]]));
        $this->assertTrue(BankReceiptSweep::isClear([['confidence' => 90], ['confidence' => 60]]));
        $this->assertFalse(BankReceiptSweep::isClear([['confidence' => 90], ['confidence' => 80]]), 'two receipts look alike');
        $this->assertFalse(BankReceiptSweep::isClear([['confidence' => 70]]), 'no vendor name on the statement');
    }

    public function test_each_receipt_goes_to_its_strongest_line_only(): void
    {
        $links = BankReceiptSweep::assign([
            ['line' => ['id' => 1], 'receipt' => ['expense_id' => 7, 'confidence' => 80]],
            ['line' => ['id' => 2], 'receipt' => ['expense_id' => 7, 'confidence' => 90]],
            ['line' => ['id' => 3], 'receipt' => ['expense_id' => 8, 'confidence' => 85]],
        ]);
        $this->assertSame([2, 3], array_map(fn($x) => $x['line']['id'], $links));
    }
}
