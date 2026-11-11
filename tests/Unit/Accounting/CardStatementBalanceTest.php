<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Vancity Visa PDFs label the closing figure "Statement balance" (2026-10-15: every card import showed "closing ?"). */
class CardStatementBalanceTest extends TestCase
{
    public function testStatementBalanceIsTheClosingBalance(): void
    {
        $text = "statement balance in full.\nPayment information\nStatement balance\t\$5,574.08\nMinimum payment\n\$741.31\n"
              . "Statement summary\nPrevious balance\t\$5,203.12\nPayments & credits\t-\t\$359.22\nPurchases & debits\t+\t\$618.07\n"
              . "Interest\t+\t\$87.11\nFees\t+\t\$25.00\nStatement balance\t\$5,574.08\n";
        $m = new ReflectionMethod(BankImportService::class, 'parsePdfText');
        $m->setAccessible(true);
        $r = $m->invoke((new ReflectionClass(BankImportService::class))->newInstanceWithoutConstructor(), $text);
        $this->assertSame(5203.12, $r['balance_meta']['opening']);
        $this->assertSame(5574.08, $r['balance_meta']['closing'], '"pay your statement balance in full" is not the amount');
    }
}
