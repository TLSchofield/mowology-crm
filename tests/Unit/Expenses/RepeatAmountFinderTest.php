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
            $this->r(3, '2026-09-01', 84.00),                                     // 31 days after #1 / 29 after #2 → question… but see habit rule below
            $this->r(5, '2026-08-01', 61.00), $this->r(6, '2026-09-01', 61.00),   // 31 days → question
            $this->r(4, '2026-12-15', 61.00),                                     // > 90 days after #6 → nothing (and makes $61 a trio, so…)
        ], []);
        // $84 appears 3 times in the window → habitual, never asked; $61 appears 3 times too → also habitual
        self::assertSame([], $f);
        $f = RepeatAmountFinder::find([$this->r(5, '2026-08-01', 61.00), $this->r(6, '2026-08-03', 61.00), $this->r(7, '2026-09-01', 61.00, 'Rona')], []);
        self::assertSame([], $f);                                                 // #5/#6 are a twin (2 days); Rona is another vendor
        $f = RepeatAmountFinder::find([$this->r(5, '2026-08-01', 61.00), $this->r(6, '2026-09-01', 61.00)], []);
        self::assertCount(1, $f);
        self::assertSame([6, 5], [$f[0]['newer_id'], $f[0]['older_id']]);
        self::assertSame(31, $f[0]['days']);
        self::assertStringContainsString('Home Depot $61.00 on 2026-09-01 matches $61.00 on 2026-08-01, 31 days earlier', RepeatAmountFinder::wording($f[0]));
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
            $this->r(5, '2026-08-01', 70.00, 'Home Depot', 'rejected'), $this->r(6, '2026-08-20', 70.00),   // a rejected one does not count
        ], []);
        self::assertSame([900.0, 50.0], array_column($f, 'amount'));
    }

    public function test_habitual_amounts_and_small_amounts_are_not_asked_about(): void
    {
        // Three $200 fuel prepays in the window: a pattern, not a duplicate
        $f = RepeatAmountFinder::find([$this->r(1, '2026-08-01', 200.00, 'Shell'), $this->r(2, '2026-08-20', 200.00, 'Shell'), $this->r(3, '2026-09-15', 200.00, 'Shell')], []);
        self::assertSame([], $f);
        // Two is still a question
        self::assertCount(1, RepeatAmountFinder::find([$this->r(1, '2026-08-01', 200.00, 'Shell'), $this->r(2, '2026-08-20', 200.00, 'Shell')], []));
        // Under $25 never asks
        self::assertSame([], RepeatAmountFinder::find([$this->r(1, '2026-08-01', 20.00, 'Chevron'), $this->r(2, '2026-08-20', 20.00, 'Chevron')], []));
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
