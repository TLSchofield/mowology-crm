<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Pure detection of twins among receipts that have already reached the books. */
class TwinFinderTest extends TestCase
{
    private function receipt(int $id, $total = 20.00, ?int $lineId = null, string $vendor = 'Shell', string $date = '2026-10-10', string $status = 'approved'): array
    {
        return ['id' => $id, 'expense_date' => $date, 'status' => $status, 'total' => $total, 'vendor' => $vendor, 'line_id' => $lineId];
    }

    private function fact(?string $date = null, ?string $first = null, ?string $last = null, ?string $number = null): array
    {
        return ['printed_date' => $date, 'time_first' => $first, 'time_last' => $last, 'doc_number' => $number];
    }

    private function line(int $id, $amount, ?int $matched = null, string $date = '2026-10-10'): array
    {
        return ['id' => $id, 'transaction_date' => $date, 'amount' => $amount, 'matched_expense_id' => $matched];
    }

    public function test_same_printed_time_and_auth_number_is_grade_a_and_keeps_linked_receipt(): void
    {
        $found = TwinFinder::find(
            [$this->receipt(12, '200.00'), $this->receipt(8, 200, 91)],
            [8 => $this->fact('2026-10-10', '11:04', null, '396190'), 12 => $this->fact('2026-10-10', '11:04', null, '396190')], [], []
        );
        $this->assertSame('A', $found[0]['grade']);
        $this->assertSame('copy_unlinked', $found[0]['state']);
        $this->assertSame(8, $found[0]['keep_id']);
        $this->assertSame(12, $found[0]['copy_id']);
        $this->assertContains('same time 11:04', $found[0]['evidence']);
        $this->assertContains('same number 396190', $found[0]['evidence']);
    }

    public function test_scale_time_out_followed_by_card_slip_is_grade_b(): void
    {
        $found = TwinFinder::find(
            [$this->receipt(1, 64.50, null, 'Landfill'), $this->receipt(2, 64.50, null, 'Landfill')],
            [1 => $this->fact('2026-10-10', '08:08', '08:16'), 2 => $this->fact('2026-10-10', '08:17')], [], []
        );
        $this->assertSame('B', $found[0]['grade']);
        $this->assertSame(['slip 1 min after Time Out'], $found[0]['evidence']);
    }

    public function test_short_invoice_numbers_do_not_grade_c_but_long_one_character_misread_does(): void
    {
        $receipts = [$this->receipt(1, 51, null, 'Chevron'), $this->receipt(2, 51, null, 'Chevron')];
        $lines = [$this->line(1, 51), $this->line(2, 51)];
        $this->assertSame([], TwinFinder::find($receipts, [1 => $this->fact(null, null, null, '0027'), 2 => $this->fact(null, null, null, '0627')], $lines, []));
        $found = TwinFinder::find($receipts, [1 => $this->fact(null, null, null, '002701'), 2 => $this->fact(null, null, null, '002601')], $lines, []);
        $this->assertSame('C', $found[0]['grade']);
    }

    public function test_count_rule_finds_grade_d_only_when_receipts_outnumber_lines(): void
    {
        $receipts = [$this->receipt(1), $this->receipt(2)];
        $found = TwinFinder::find($receipts, [], [$this->line(10, 20)], []);
        $this->assertSame('D', $found[0]['grade']);
        $this->assertSame(['2 receipts vs 1 bank line(s)'], $found[0]['evidence']);
        $this->assertSame([], TwinFinder::find($receipts, [], [$this->line(10, 20), $this->line(11, 20)], []));
    }

    public function test_different_long_numbers_veto_matching_time_grade_a_evidence(): void
    {
        $facts = [1 => $this->fact(null, '10:00', null, '123456'), 2 => $this->fact(null, '10:00', null, '987654')];
        $this->assertSame([], TwinFinder::find([$this->receipt(1), $this->receipt(2)], $facts, [], []));
    }

    public function test_different_standalone_times_veto_matching_number_grade_a_evidence(): void
    {
        $facts = [1 => $this->fact(null, '10:00', null, '123456'), 2 => $this->fact(null, '10:30', null, '123456')];
        $this->assertSame([], TwinFinder::find([$this->receipt(1), $this->receipt(2)], $facts, [], []));
    }

    public function test_three_short_ticket_numbers_are_not_identity_evidence(): void
    {
        $receipts = [$this->receipt(1, 84, null, 'Lawnboy'), $this->receipt(2, 84, null, 'Lawnboy'), $this->receipt(3, 84, null, 'Lawnboy')];
        $facts = [1 => $this->fact(null, null, null, '003'), 2 => $this->fact(null, null, null, '006'), 3 => $this->fact(null, null, null, '006')];
        $lines = [$this->line(1, 84), $this->line(2, 84), $this->line(3, 84)];
        $this->assertSame([], TwinFinder::find($receipts, $facts, $lines, []));
    }

    public function test_person_dismissal_suppresses_but_system_dismissal_reopens(): void
    {
        $receipts = [$this->receipt(1), $this->receipt(2)];
        $facts = [1 => $this->fact(null, '09:00'), 2 => $this->fact(null, '09:00')];
        $this->assertSame([], TwinFinder::find($receipts, $facts, [], [['expense_a' => 2, 'expense_b' => 1, 'dismissed_by' => 7]]));
        $found = TwinFinder::find($receipts, $facts, [], [['expense_a' => 1, 'expense_b' => 2, 'dismissed_by' => null]]);
        $this->assertTrue($found[0]['reopens_system_dismissal']);
    }

    public function test_any_pair_where_both_receipts_are_linked_is_skipped(): void
    {
        $facts = [1 => $this->fact(null, '09:00'), 2 => $this->fact(null, '09:00')];
        $this->assertSame([], TwinFinder::find([$this->receipt(1, 20, 8), $this->receipt(2, 20, 9)], $facts, [], []));
        $this->assertSame([], TwinFinder::find([$this->receipt(1, 20, 8), $this->receipt(2, 20, 8)], $facts, [], []));
    }

    public function test_bad_link_finds_right_receipt_and_rejected_reason_takes_priority(): void
    {
        $receipts = [$this->receipt(1, 0, 50, 'Shell', '2026-10-10', 'rejected'), $this->receipt(2, 17.81)];
        $found = TwinFinder::find($receipts, [], [$this->line(50, 17.81, 1)], []);
        $this->assertSame('bad_link', $found[0]['kind']);
        $this->assertSame('linked receipt is rejected', $found[0]['reason']);
        $this->assertSame(2, $found[0]['right_id']);
        $this->assertEqualsWithDelta(17.81, $found[0]['difference'], 0.0001);

        $found = TwinFinder::find([$this->receipt(1, 0, 50)], [], [$this->line(50, 17.81, 1)], []);
        $this->assertSame('receipt total 0.00 vs line 17.81', $found[0]['reason']);
        $this->assertNull($found[0]['right_id']);
    }

    public function test_results_sort_by_amount_then_grade(): void
    {
        $receipts = [
            $this->receipt(1, 10, null, 'Shell'), $this->receipt(2, 10, null, 'Shell'),
            $this->receipt(3, 20, null, 'Chevron'), $this->receipt(4, 20, null, 'Chevron'),
            $this->receipt(5, 10, null, 'Lawnboy'), $this->receipt(6, 10, null, 'Lawnboy'),
        ];
        $facts = [
            1 => $this->fact(null, null, null, '123450'), 2 => $this->fact(null, null, null, '123451'),
            3 => $this->fact(null, '09:00'), 4 => $this->fact(null, '09:00'),
            5 => $this->fact(null, '08:00', '08:10'), 6 => $this->fact(null, '08:12'),
        ];
        $found = TwinFinder::find($receipts, $facts, [], []);
        $this->assertSame([20.0, 10.0, 10.0], array_column($found, 'amount'));
        $this->assertSame(['A', 'B', 'C'], array_column($found, 'grade'));
    }

    public function test_plain_array_input_is_not_modified_and_empty_input_is_empty(): void
    {
        $receipts = [$this->receipt(1), $this->receipt(2)];
        $before = $receipts;
        TwinFinder::find($receipts, [], [], []);
        $this->assertSame($before, $receipts);
        $this->assertSame([], TwinFinder::find([], [], [], []));
    }

    public function test_detector_has_no_constructor_and_needs_no_database_or_files(): void
    {
        $class = new ReflectionClass(TwinFinder::class);
        $this->assertNull($class->getConstructor());
        $this->assertSame([], TwinFinder::find([$this->receipt(1)], [], [], []));
    }
}
