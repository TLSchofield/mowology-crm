<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The drill-down names the lines behind a month's drift (Tim, 2026-10-25). */
class MonthDrilldownServiceTest extends TestCase
{
    private function s(int $id, string $date, float $amt): array { return ['id' => $id, 'date' => $date, 'amount' => $amt]; }
    private function b(int $id, string $date, float $amt): array { return ['id' => $id, 'date' => $date, 'amount' => $amt, 'memo' => 'm']; }

    public function testEverythingPairedMeansNothingExplainsTheDrift(): void
    {
        $r = MonthDrilldownService::compare(
            [$this->s(1, '2026-01-05', -45.00), $this->s(2, '2026-01-06', 100.00)],
            [$this->b(10, '2026-01-05', -45.00), $this->b(11, '2026-01-06', 100.00)]
        );
        $this->assertSame(2, $r['matched']);
        $this->assertSame([], $r['statement_only']);
        $this->assertSame([], $r['book_only']);
        $this->assertSame(0.0, $r['explains']);
    }

    public function testAnExtraBookEntryMakesBooksHigher(): void
    {
        $r = MonthDrilldownService::compare([$this->s(1, '2026-01-05', -45.00)],
            [$this->b(10, '2026-01-05', -45.00), $this->b(11, '2026-01-20', 7.01)]);
        $this->assertCount(1, $r['book_only']);
        $this->assertSame(7.01, $r['explains'], 'books are $7.01 above the statement');
    }

    public function testAMissingBookEntryMakesBooksLower(): void
    {
        $r = MonthDrilldownService::compare([$this->s(1, '2026-01-05', -45.00), $this->s(2, '2026-01-09', -1255.00)],
            [$this->b(10, '2026-01-05', -45.00)]);
        $this->assertCount(1, $r['statement_only']);
        $this->assertSame(1255.00, $r['explains'], 'the statement paid out $1,255 the books never booked: books are $1,255 higher');
    }

    public function testTheSameAmountOnAnotherDateIsPairedAndFlagged(): void
    {
        $r = MonthDrilldownService::compare([$this->s(1, '2026-01-05', -45.00)], [$this->b(10, '2026-01-07', -45.00)]);
        $this->assertSame(1, $r['matched']);
        $this->assertCount(1, $r['date_differs']);
        $this->assertSame([], $r['statement_only']);
        $this->assertSame([], $r['book_only']);
        $this->assertSame(0.0, $r['explains']);
    }

    public function testTwoIdenticalLinesNeedTwoBookEntries(): void
    {
        $r = MonthDrilldownService::compare(
            [$this->s(1, '2026-01-05', -9.99), $this->s(2, '2026-01-05', -9.99)],
            [$this->b(10, '2026-01-05', -9.99)]
        );
        $this->assertCount(1, $r['statement_only'], 'one copy has no partner');
        $this->assertSame(9.99, $r['explains']);
    }

    public function testCentsAreComparedExactly(): void
    {
        $r = MonthDrilldownService::compare([$this->s(1, '2026-01-05', -45.00)], [$this->b(10, '2026-01-05', -45.01)]);
        $this->assertCount(1, $r['statement_only']);
        $this->assertCount(1, $r['book_only']);
        $this->assertSame(-0.01, $r['explains']);
    }

    public function testAnImpossibleMonthIsRefused(): void
    {
        $svc = new MonthDrilldownService($this->createMock(PDO::class));
        $this->assertArrayHasKey('error', $svc->month('1010', '2026-13'));
        $this->assertArrayHasKey('error', $svc->month('1010', 'garbage'));
    }
}
