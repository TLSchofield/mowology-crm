<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once APP_ROOT . '/Modules/Expenses/Services/ReceiptDateCheck.php';

/** Handwritten dates (Lawnboy, Oct 15 read as Apr 2): the bank line's date, or a flag — never a guess (2026-10-15). */
class ReceiptDateCheckTest extends TestCase
{
    public function testWhatIsSuspect(): void
    {
        $this->assertTrue(ReceiptDateCheck::suspect('2026-04-02', '2026-10-15 07:43:57'), 'Lawnboy');
        $this->assertFalse(ReceiptDateCheck::suspect('2026-10-14', '2026-10-15 07:43:57'));
        $this->assertFalse(ReceiptDateCheck::suspect('2026-09-20', '2026-10-15 07:43:57'), 'a few weeks late is normal');
        $this->assertTrue(ReceiptDateCheck::suspect('2026-11-30', '2026-10-15 07:43:57'), 'in the future');
        $this->assertFalse(ReceiptDateCheck::suspect('2026-10-16', '2026-10-15 23:43:57'), 'a day ahead (time zone) is allowed');
        $this->assertTrue(ReceiptDateCheck::suspect('2026-10-18', '2026-10-15 07:43:57'), 'days ahead is not');
        $this->assertTrue(ReceiptDateCheck::hasFlag('OTHER, DATE_UNREADABLE'), 'spaces after commas');
    }

    public function testFlagsAddOnceAndClear(): void
    {
        $this->assertSame('DUPLICATE_DAY,DATE_UNREADABLE', ReceiptDateCheck::withFlag('DUPLICATE_DAY', true));
        $this->assertSame('DUPLICATE_DAY,DATE_UNREADABLE', ReceiptDateCheck::withFlag('DUPLICATE_DAY,DATE_UNREADABLE', true));
        $this->assertSame('DUPLICATE_DAY', ReceiptDateCheck::withFlag('DUPLICATE_DAY,DATE_UNREADABLE', false));
        $this->assertSame('', ReceiptDateCheck::withFlag(null, false));
        $this->assertStringContainsString('Check the date', RiskExplainer::say('DATE_UNREADABLE', ['expense_date' => '2026-04-02', 'total' => 84]));
    }
}
