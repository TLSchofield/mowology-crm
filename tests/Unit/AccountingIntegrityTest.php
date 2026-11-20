<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AccountingIntegrityTest extends TestCase
{
    public function testBankImportRequiresNumericNonZeroAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BankImportService::validateCommitAmounts(['amount' => 'no', 'gst_amount' => 0], 3);
    }

    public function testBankImportRejectsGstGreaterThanAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BankImportService::validateCommitAmounts(['amount' => 10, 'gst_amount' => 10.01]);
    }

    public function testBankImportAcceptsValidNegativeSignedAmount(): void
    {
        BankImportService::validateCommitAmounts(['amount' => -105, 'gst_amount' => 5]);
        $this->addToAssertionCount(1);
    }

    public function testManualTransactionRequiresPositiveAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AccountingService::validateManualTransaction(['amount' => 0, 'type' => 'expense', 'status' => 'cleared']);
    }

    public function testManualTransactionRejectsUnknownType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AccountingService::validateManualTransaction(['amount' => 10, 'type' => 'refund', 'status' => 'cleared']);
    }

    public function testManualTransactionRejectsUnknownStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AccountingService::validateManualTransaction(['amount' => 10, 'type' => 'income', 'status' => 'void']);
    }

    public function testVoidInvoiceCannotReceiveClientCredit(): void
    {
        self::assertFalse(ClientCreditService::shouldApplyOnSend('void', 100, 100));
    }

    public function testZeroDollarBankLineStillImportsButAWordDoesNot(): void
    {
        BankImportService::validateCommitAmounts(['amount' => 0, 'gst_amount' => 0]);
        $this->expectException(InvalidArgumentException::class);
        BankImportService::validateCommitAmounts(['amount' => 'abc']);
    }

    public function testReversalTakesThePostingLockOfItsSourceAndUndoIsOneTransaction(): void
    {
        $root = dirname(__DIR__, 2);
        $ledger = (string)file_get_contents($root . '/app/Modules/Accounting/Services/LedgerService.php');
        $i = strpos($ledger, 'public function reverseEntry');
        self::assertStringContainsString("'ledger_post_' . md5(", substr($ledger, $i, 1200));
        $sh = (string)file_get_contents($root . '/app/Modules/Accounting/Services/ShareholderAccountService.php');
        $j = strpos($sh, 'public function undoClearing');
        $body = substr($sh, $j, 1800);
        self::assertStringContainsString('beginTransaction', $body);
        self::assertStringContainsString('AND undone_at IS NULL', $body);
    }

    public function testAccountantExportUsesTheCanonicalCountedFilter(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Accounting/Api/reports.php');
        self::assertGreaterThanOrEqual(2, substr_count($src, "AccountingService::countedSql('t')"));
        self::assertStringContainsString("t.status NOT IN ('void', 'deleted')", $src);
    }
}
