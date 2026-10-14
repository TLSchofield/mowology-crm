<?php
use PHPUnit\Framework\TestCase;

final class MonthCloseServiceTest extends TestCase
{
    public function testAMonthIsExactOnlyWhenEverythingIs(): void
    {
        $clean = ['statements_ok' => true, 'drift' => ['1010 Chequing' => 0.0, '1025 GST Reserves' => 0.004], 'receipts' => 0, 'bank_lines' => 0];
        $this->assertSame([], MonthCloseService::blockers($clean));

        // January 2026 on 2026-10-14: statements fine, but the books were off and receipts open.
        $jan = ['statements_ok' => true, 'drift' => ['1010 Chequing' => -48.88], 'receipts' => 1, 'receipts_amount' => 30.0, 'bank_lines' => 13];
        $b = MonthCloseService::blockers($jan);
        $this->assertSame(['1010 Chequing: books are $48.88 below the bank', '1 open receipt ($30.00)', '13 bank lines still to sort'], $b);
        $this->assertContains('a statement is missing or doesn\'t check out', MonthCloseService::blockers(['statements_ok' => false] + $clean));
    }
}
