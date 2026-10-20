<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Receipt ↔ bank line amount score uses Penny's read of the PRINTED total when she disagrees with the
 * saved one (Tim, 2026-10-19: a Chevron receipt printed $14.88, saved $14.69 — the $14.69 bank line was
 * called "exact" and the right $14.88 line "close"). BankImportService::amountScore.
 */
class ReceiptMatchPrintedTotalTest extends TestCase
{
    public function testThePrintedTotalIsTheExactMatch(): void
    {
        $this->assertSame(50, BankImportService::amountScore(14.69, 14.88, 14.88)[0]);
        $this->assertStringContainsString('printed total', BankImportService::amountScore(14.69, 14.88, 14.88)[1][0]);
        $misread = BankImportService::amountScore(14.69, 14.69, 14.88);
        $this->assertSame(35, $misread[0], 'matching only the misread total ranks below the printed one');
        $this->assertStringContainsString('$14.88', $misread[1][0]);
    }

    public function testWithoutPennysReadNothingChanges(): void
    {
        $this->assertSame([50, ['Exact amount match']], BankImportService::amountScore(14.69, 14.69));
        $this->assertSame([35, ['Amount close (within 2%)']], BankImportService::amountScore(14.69, 14.88));
        $this->assertNull(BankImportService::amountScore(14.69, 40.00));
        $this->assertSame([50, ['Exact amount match']], BankImportService::amountScore(14.88, 14.88, 14.88), 'she agrees: plain exact');
    }
}
