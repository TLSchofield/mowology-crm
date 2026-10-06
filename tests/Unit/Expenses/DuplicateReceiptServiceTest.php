<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Possible duplicate receipts — found by ExpenseLookupService::findDuplicates (the
 * receipts page's rule), paired up and held back from Penny's approval line.
 */
class DuplicateReceiptServiceTest extends TestCase
{
    private function r(int $id, string $status = 'draft'): array
    {
        return ['id' => $id, 'status' => $status, 'expense_date' => '2026-09-28', 'total' => 48.38];
    }

    public function test_pairs_each_waiting_receipt_with_its_twin_once(): void
    {
        $mine = [$this->r(7), $this->r(9, 'pending_approval')];
        $cands = [7 => [$this->r(9, 'pending_approval')], 9 => [$this->r(7)]];
        $p = DuplicateReceiptService::pairUp($mine, $cands);
        $this->assertCount(1, $p, 'the same pair seen from both sides is one pair');
        $this->assertSame(7, $p[0]['a']['id'], 'older waiting receipt on the left');
    }

    public function test_a_waiting_copy_of_an_approved_receipt_is_caught_with_the_waiting_one_first(): void
    {
        $p = DuplicateReceiptService::pairUp([$this->r(9)], [9 => [$this->r(3, 'forwarded')]]);
        $this->assertSame(9, $p[0]['a']['id']);
        $this->assertSame([9], DuplicateReceiptService::heldIds($p), 'only the waiting one is held');
    }

    public function test_rejected_and_dismissed_twins_are_ignored(): void
    {
        $this->assertSame([], DuplicateReceiptService::pairUp([$this->r(9)], [9 => [$this->r(3, 'rejected')]]));
        $this->assertSame([], DuplicateReceiptService::pairUp([$this->r(9)], [9 => [$this->r(3)]], ['3-9' => true]));
    }

    public function test_copies_linked_by_any_pair_are_one_group(): void
    {
        $pairs = [['a' => $this->r(1), 'b' => $this->r(4)], ['a' => $this->r(4), 'b' => $this->r(7, 'forwarded')], ['a' => $this->r(24), 'b' => $this->r(290)]];
        $g = DuplicateReceiptService::groups($pairs);
        $this->assertCount(2, $g);
        $this->assertSame([1, 4, 7], array_map(fn($m) => (int)$m['id'], $g[0]['members']));
        $this->assertCount(2, $g[0]['pairs']);
    }
}
