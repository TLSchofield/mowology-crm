<?php
/**
 * The expenses list's "linked to a bank line" column (2026-10-28): the per-receipt map built from the
 * main link (accounting_transactions.matched_expense_id) and the extra links (bank_line_receipts).
 */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Accounting/Services/BankLineReceipts.php';

final class BankLineLinksTest extends TestCase
{
    public function testMainLinkIsMappedByReceipt(): void
    {
        $map = BankLineReceipts::groupLinks([
            ['line' => 5416, 'expense_id' => 496, 'transaction_date' => '2025-04-15', 'description' => 'POINT OF SALE (EAGLE DISPOSAL INC RICHMOND BCCA)', 'amount' => '60.9'],
        ], []);
        $this->assertSame([496], array_keys($map));
        $this->assertSame(5416, $map[496]['line']);
        $this->assertSame('60.90', $map[496]['amount']);
        $this->assertFalse($map[496]['extra']);
    }

    public function testExtraLinkIsMarkedAndMainWinsWhenBothExist(): void
    {
        $map = BankLineReceipts::groupLinks(
            [['line' => 10, 'expense_id' => 1, 'transaction_date' => '2026-08-31', 'description' => 'CHAMPION', 'amount' => '184.40']],
            [
                ['line' => 10, 'expense_id' => 2, 'transaction_date' => '2026-08-31', 'description' => 'CHAMPION', 'amount' => '184.40'],
                ['line' => 99, 'expense_id' => 1, 'transaction_date' => '2026-01-01', 'description' => 'OTHER', 'amount' => '1.00'],
            ]
        );
        $this->assertTrue($map[2]['extra']);
        $this->assertFalse($map[1]['extra']);
        $this->assertSame(10, $map[1]['line']);
    }

    public function testNoLinksGivesEmptyMap(): void
    {
        $this->assertSame([], BankLineReceipts::groupLinks([], []));
    }
}
