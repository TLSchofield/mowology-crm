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

    public function testNegativeTotalIsNeverApproved(): void
    {
        $this->assertNotNull(ReceiptAmountRules::approvalError(0.0, 0.0, 0.0, -0.01));
        $this->assertNull(ReceiptAmountRules::approvalError(10.0, 0.5, 0.7, 11.2));
    }

    public function testUpdateLoadsTheWholeStoredRowSoOmittedFieldsKeepTheirValues(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/app/Modules/Expenses/Api/expenses.php');
        $i = strpos($src, 'function handleUpdate(');
        $this->assertStringContainsString('SELECT * FROM expenses', substr($src, $i, 1400));
    }

    public function testExemptVendorKeepsThePrintedTotal(): void
    {
        // No GST line: the parser estimated GST and derived subtotal = total / 1.05.
        $p = ReceiptAmountRules::exemptParsed(['total' => '105.00', 'subtotal' => '100.00', 'gst' => '5.00', 'gst_estimated' => true, 'pst' => null]);
        self::assertSame('105.00', $p['total']);
        self::assertSame('105.00', $p['subtotal']);
        self::assertSame('0.00', $p['gst']);
        self::assertTrue($p['gst_exempt']);
        self::assertFalse($p['gst_estimated']);
        // With PST: the amount is the total less PST.
        $p = ReceiptAmountRules::exemptParsed(['total' => '107.00', 'subtotal' => '100.00', 'gst' => '0.00', 'pst' => '7.00']);
        self::assertSame('107.00', $p['total']);
        self::assertSame('100.00', $p['subtotal']);
        // No total read: nothing invented.
        $p = ReceiptAmountRules::exemptParsed(['total' => null, 'subtotal' => '40.00']);
        self::assertNull($p['total']);
        self::assertSame('40.00', $p['subtotal']);
    }

    public function testCameraAndQueuePathsUseTheExemptRule(): void
    {
        $root = dirname(__DIR__, 3) . '/app/Modules/Expenses';
        foreach (['/Services/ReceiptIntakeService.php', '/Cron/process_ocr_queue.php'] as $f) {
            $src = (string)file_get_contents($root . $f);
            self::assertStringContainsString('ReceiptAmountRules::exemptParsed($parsed)', $src, $f);
            self::assertStringNotContainsString("\$parsed['total'] = \$parsed['subtotal']", $src, $f);
        }
    }
}
