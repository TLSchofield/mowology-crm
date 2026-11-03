<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Same vendor and total 4–90 days apart becomes one question on the newer receipt; Tim decides. */
final class RepeatAmountFinderTest extends TestCase
{
    private function r(int $id, string $date, float $total, string $vendor = 'Home Depot', string $status = 'approved'): array
    {
        return ['id' => $id, 'expense_date' => $date, 'total' => $total, 'vendor' => $vendor, 'status' => $status];
    }

    public function test_finds_a_repeat_between_four_and_ninety_days_and_skips_the_twin_range(): void
    {
        $f = RepeatAmountFinder::find([
            $this->r(1, '2026-08-01', 84.00), $this->r(2, '2026-08-03', 84.00),   // 2 days: TwinFinder's job
            $this->r(3, '2026-09-01', 84.00),                                     // 31 days after #1 / 29 after #2 → question
            $this->r(4, '2026-12-15', 84.00),                                     // > 90 days after everything → nothing
        ], []);
        self::assertCount(1, $f);
        self::assertSame([3, 2], [$f[0]['newer_id'], $f[0]['older_id']]);   // nearest earlier match
        self::assertSame(29, $f[0]['days']);
        self::assertStringContainsString('Home Depot $84.00 on 2026-09-01 matches $84.00 on 2026-08-03, 29 days earlier', RepeatAmountFinder::wording($f[0]));
    }

    public function test_vendor_and_cents_must_match_and_dismissed_pairs_stay_quiet(): void
    {
        $rows = [$this->r(1, '2026-08-01', 84.00), $this->r(2, '2026-09-01', 84.01), $this->r(3, '2026-09-02', 84.00, 'Rona'), $this->r(4, '2026-09-03', 84.00, 'HOME DEPOT #7')];
        $f = RepeatAmountFinder::find($rows, []);
        self::assertSame([[4, 1]], array_map(static fn($x) => [$x['newer_id'], $x['older_id']], $f));
        self::assertSame([], RepeatAmountFinder::find($rows, [[1, 4]]));
    }

    public function test_only_approved_or_forwarded_count_and_biggest_first(): void
    {
        $f = RepeatAmountFinder::find([
            $this->r(1, '2026-08-01', 50.00), $this->r(2, '2026-08-20', 50.00),
            $this->r(3, '2026-08-01', 900.00), $this->r(4, '2026-08-20', 900.00),
            $this->r(5, '2026-08-01', 70.00, 'Home Depot', 'rejected'), $this->r(6, '2026-08-20', 70.00),
        ], []);
        self::assertSame([900.0, 50.0], array_column($f, 'amount'));
    }

    public function test_rows_for_penny_questions(): void
    {
        $rows = TwinCheckService::repeatRows(RepeatAmountFinder::find([$this->r(1, '2026-08-01', 84.00), $this->r(2, '2026-09-01', 84.00)], []));
        self::assertSame('repeat_amount', $rows[0]['kind']);
        self::assertSame(2, $rows[0]['expense_id']);
        self::assertSame('with:1', $rows[0]['subject']);
        self::assertSame(['keep_id' => 1], array_intersect_key(PennyQuestionService::parseSubject('repeat_amount', 'with:1'), ['keep_id' => 1]));
    }
}
