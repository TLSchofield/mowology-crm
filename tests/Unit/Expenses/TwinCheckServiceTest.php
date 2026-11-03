<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class TwinCheckServiceTest extends TestCase
{
    private array $receipts = [
        102 => ['id' => 102, 'vendor' => 'Shell', 'total' => 200, 'expense_date' => '2026-05-06', 'status' => 'approved'],
        106 => ['id' => 106, 'vendor' => 'Shell', 'total' => 200, 'expense_date' => '2026-05-06', 'status' => 'approved'],
        159 => ['id' => 159, 'vendor' => 'Store', 'total' => 0, 'expense_date' => '2026-05-05', 'status' => 'rejected'],
        212 => ['id' => 212, 'vendor' => 'Store', 'total' => 17.81, 'expense_date' => '2026-05-05', 'status' => 'approved'],
    ];

    public function test_rows_cover_linked_unlinked_and_bad_link_shapes(): void
    {
        $rows = TwinCheckService::rows([
            ['kind' => 'twin', 'copy_id' => 106, 'keep_id' => 102, 'pair_ids' => [102, 106], 'grade' => 'A', 'amount' => 200, 'vendor' => 'Shell', 'date' => '2026-05-06', 'state' => 'copy_unlinked', 'evidence' => ['same time 11:04']],
            ['kind' => 'twin', 'copy_id' => null, 'keep_id' => 102, 'pair_ids' => [102, 106], 'grade' => 'D', 'amount' => 200, 'vendor' => 'Shell', 'date' => '2026-05-06', 'state' => 'both_unlinked', 'evidence' => ['2 receipts vs 1 bank line(s)']],
            ['kind' => 'bad_link', 'wrong_id' => 159, 'right_id' => 212, 'line_id' => 50, 'amount' => 17.81, 'date' => '2026-05-05'],
        ], $this->receipts);
        $this->assertSame(['possible_twin', 'possible_twin', 'bad_link'], array_column($rows, 'kind'));
        $this->assertSame([106, 102, 159], array_column($rows, 'expense_id'));
        $this->assertSame(['keep:102', 'with:106', 'line:50;right:212'], array_column($rows, 'subject'));
        $this->assertSame(['A', 'D', 'bad_link'], array_column($rows, 'category'));
    }

    public function test_wording_for_twin_evidence_and_both_unlinked(): void
    {
        $a = ['kind' => 'twin', 'copy_id' => 106, 'keep_id' => 102, 'pair_ids' => [102, 106], 'grade' => 'A', 'amount' => 200,
            'date' => '2026-05-06', 'state' => 'copy_unlinked', 'evidence' => ['same time 11:04', 'same number 396190']];
        $this->assertSame('Is #106 (Shell $200.00, 6 May) the same purchase as #102? Same printed time 11:04, same number 396190, and the bank shows one payment. If it is, the books count it twice.', TwinCheckService::wording($a, $this->receipts));
        $d = $a + [];
        $d['grade'] = 'D'; $d['evidence'] = ['3 receipts vs 1 bank line(s)'];
        $this->assertStringContainsString('No usable printed time or number to compare; 3 receipts but 1 bank line(s)', TwinCheckService::wording($d, $this->receipts));
        $both = $a; $both['copy_id'] = null; $both['state'] = 'both_unlinked';
        $this->assertStringContainsString('neither receipt is linked to a bank line yet', TwinCheckService::wording($both, $this->receipts));
    }

    public function test_bad_link_wording_handles_rejected_and_missing_right_receipt(): void
    {
        $find = ['kind' => 'bad_link', 'wrong_id' => 159, 'right_id' => 212, 'line_id' => 50, 'amount' => 17.81, 'date' => '2026-05-05'];
        $this->assertSame('The bank line of $17.81 on 5 May is linked to #159, which was rejected. #212 looks like the right receipt.', TwinCheckService::wording($find, $this->receipts));
        $find['right_id'] = null;
        $this->assertSame('The bank line of $17.81 on 5 May is linked to #159, which was rejected.', TwinCheckService::wording($find, $this->receipts));
    }

    public function test_plan_inserts_updates_closes_reopens_only_resolved_rows(): void
    {
        $existing = [
            ['id' => 1, 'kind' => 'possible_twin', 'expense_id' => 1, 'status' => 'open', 'answer' => null, 'amount' => 10, 'question' => 'old'],
            ['id' => 2, 'kind' => 'possible_twin', 'expense_id' => 2, 'status' => 'open', 'answer' => null, 'amount' => 10, 'question' => 'gone'],
            ['id' => 3, 'kind' => 'bad_link', 'expense_id' => 3, 'status' => 'answered', 'answer' => 'resolved', 'amount' => 5, 'question' => 'back'],
            ['id' => 4, 'kind' => 'bad_link', 'expense_id' => 4, 'status' => 'answered', 'answer' => 'seen', 'amount' => 5, 'question' => 'person'],
        ];
        $base = ['vendor_name' => '', 'category' => 'A', 'subject' => 'keep:9'];
        $rows = [
            $base + ['kind' => 'possible_twin', 'expense_id' => 1, 'amount' => 11.0, 'question' => 'changed'],
            $base + ['kind' => 'bad_link', 'expense_id' => 3, 'amount' => 5.0, 'question' => 'back'],
            $base + ['kind' => 'bad_link', 'expense_id' => 4, 'amount' => 8.0, 'question' => 'do not touch'],
            $base + ['kind' => 'possible_twin', 'expense_id' => 5, 'amount' => 9.0, 'question' => 'new'],
        ];
        $plan = TwinCheckService::plan($existing, $rows);
        $this->assertSame([5], array_column($plan['insert'], 'expense_id'));
        $this->assertSame([1], array_column($plan['update'], 'id'));
        $this->assertSame([2], array_column($plan['close'], 'id'));
        $this->assertSame([3], array_column($plan['reopen'], 'id'));
        $this->assertNotContains(4, array_merge(array_column($plan['update'], 'id'), array_column($plan['reopen'], 'id'), array_column($plan['close'], 'id')));
    }

    public function test_plan_keeps_one_question_when_multiple_pairs_share_an_expense(): void
    {
        $receipts = [
            ['id' => 1, 'vendor' => 'Store', 'total' => 20, 'expense_date' => '2026-05-06', 'status' => 'approved', 'line_id' => null],
            ['id' => 2, 'vendor' => 'Store', 'total' => 20, 'expense_date' => '2026-05-06', 'status' => 'approved', 'line_id' => null],
            ['id' => 3, 'vendor' => 'Store', 'total' => 20, 'expense_date' => '2026-05-06', 'status' => 'approved', 'line_id' => null],
        ];
        $byId = [];
        foreach ($receipts as $receipt) $byId[$receipt['id']] = $receipt;
        $finds = TwinFinder::find($receipts, [], [], []);
        $rows = TwinCheckService::rows($finds, $byId);

        $this->assertSame([1, 1, 2], array_column($rows, 'expense_id'));
        $plan = TwinCheckService::plan([], $rows);

        $this->assertSame([1, 2], array_column($plan['insert'], 'expense_id'));
        $this->assertSame('with:2', $plan['insert'][0]['subject']);
        $this->assertCount(2, $plan['insert']);
    }

    public function test_service_has_no_write_statement_against_book_tables(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Modules/Expenses/Services/TwinCheckService.php');
        $this->assertDoesNotMatchRegularExpression('/\b(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+(?:expenses|accounting_transactions|journal_\w+|invoices|bank_\w+)/i', $source);
    }
}
