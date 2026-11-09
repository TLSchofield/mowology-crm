<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The sale's date, not the first date-shaped thing on the paper (Fable review 2026-11-08). */
final class ReceiptDateChoiceTest extends TestCase
{
    public function testAReturnByDateDoesNotBeatTheSale(): void
    {
        $this->assertSame('2025-03-14', extractDate("HOME SHOP\n03/14/25 10:22\nTOTAL 12.00\nReturn by 2025-06-12"));
    }

    public function testALabelledDateBeatsAnEarlierOrderDate(): void
    {
        $this->assertSame('2026-01-05', extractDate("Order 2025-12-20\nInvoice Date: 2026-01-05\nTOTAL 80.00"));
    }

    public function testAnOrdinaryReceiptReadsAsBefore(): void
    {
        $this->assertSame('2026-02-13', extractDate("SHOP\nFeb 13, 2026\nTOTAL 9.00"));
        $this->assertSame('2026-02-13', extractDate("SHOP\n2026-02-13 08:01\nTOTAL 9.00"));
    }

    public function testOnlyAReturnDateIsStillADate(): void
    {
        $this->assertSame('2025-06-12', extractDate("SHOP\nTOTAL 12.00\nReturn by 2025-06-12"));
    }

    public function testYearsOnThePaper(): void
    {
        $this->assertSame([2025, 2026], receiptPaperYears("03/14/25\nReturn by 2026-01-12"));
        $this->assertSame([2025], receiptPaperYears("Mar 14, 2025 total 4.00"));
    }

    public function testProductCodesAreNotYears(): void
    {
        $this->assertSame([], receiptPaperYears("FERT 10-20-10\nFERT 16-24-12\nMIX 20-20-20"));
    }

    public function testTwoYearsAreFlagged(): void
    {
        $this->assertTrue(parseReceiptText("SHOP\n03/14/25\nTOTAL 12.00\nReturn by 2026-01-12")['date_two_years'] ?? false);
        $this->assertArrayNotHasKey('date_two_years', parseReceiptText("SHOP\n03/14/25\nTOTAL 12.00"));
    }
}
