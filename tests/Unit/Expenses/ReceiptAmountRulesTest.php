<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Modules/Expenses/Services/ReceiptAmountRules.php';

final class ReceiptAmountRulesTest extends TestCase
{
    public function testZeroedGstRecomputesAmountAndNeverGoesBelowZero(): void
    {
        self::assertSame(95.0, ReceiptAmountRules::amountFromTotal(100.0, 0.0, 5.0));
        self::assertSame(0.0, ReceiptAmountRules::amountFromTotal(2.0, 0.0, 5.0));
    }

    public function testApprovalRequiresNonNegativeFootedAmounts(): void
    {
        self::assertNull(ReceiptAmountRules::approvalError(95.0, 5.0, 0.0, 100.01));
        self::assertStringContainsString('amount -1.00', ReceiptAmountRules::approvalError(-1.0, 6.0, 0.0, 5.0));
        self::assertStringContainsString('GST 25.00', ReceiptAmountRules::approvalError(90.0, 25.0, 0.0, 100.0));
    }

    public function testOmittedInputKeepsStoredValueButExplicitNullClearsIt(): void
    {
        self::assertSame(42.5, ReceiptAmountRules::inputValue([], ['amount' => 42.5], 'amount'));
        self::assertNull(ReceiptAmountRules::inputValue(['amount' => null], ['amount' => 42.5], 'amount'));
    }

    public function testMergedAmountsMustFootWithinTwoCents(): void
    {
        self::assertSame(0.02, ReceiptAmountRules::footingError(90.0, 5.0, 5.0, 100.02));
        self::assertGreaterThan(0.02, ReceiptAmountRules::footingError(90.0, 8.0, 5.0, 100.0));
        self::assertNull(ReceiptAmountRules::footingErrorMessage(90.0, 5.0, 5.0, 100.01));
        self::assertStringContainsString('amount 90.00', ReceiptAmountRules::footingErrorMessage(90.0, 8.0, 5.0, 100.0));
    }

    public function testBudgetChecksCriticalBeforeWarning(): void
    {
        self::assertSame('critical', ReceiptAmountRules::budgetCrossingLevel('ok', 125.0, 80.0, 100.0));
        self::assertSame('warning', ReceiptAmountRules::budgetCrossingLevel('ok', 85.0, 80.0, 100.0));
    }

    public function testBaselineMonthComesFromExpenseDate(): void
    {
        self::assertSame(2, ReceiptAmountRules::monthNumber('2025-02-14'));
    }

    public function testCalculatedGstUsesProvidedRateAndHonoursExemption(): void
    {
        self::assertSame(7.0, ReceiptAmountRules::calculatedGst(100.0, 0.07, false));
        self::assertSame(0.0, ReceiptAmountRules::calculatedGst(100.0, 0.07, true));
    }

    public function testOnlyWinningArchiveUpdateMayUnlink(): void
    {
        self::assertTrue(ReceiptAmountRules::updateWonArchiveClaim(1));
        self::assertFalse(ReceiptAmountRules::updateWonArchiveClaim(0));
    }

    public function testAppstackUsesLocalDatesAndRecyclingTaxInGstFix(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/public/crm/expenses_appstack.php');
        self::assertSame(1, substr_count($source, 'window.localDateYmd = function'));
        self::assertStringNotContainsString("new Date().toISOString().slice(0, 10)", $source);
        self::assertStringContainsString('total - amt - pst - recyclingTax', $source);
    }
}
