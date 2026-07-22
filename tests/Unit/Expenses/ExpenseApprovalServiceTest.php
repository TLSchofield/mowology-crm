<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for ExpenseApprovalService — the audited approve/reject transitions shared by
 * expenses.php (session, web) and receipt-actions.php (JWT, iOS). The self-approval
 * check and the required-reason check are the load-bearing rules here: they're what
 * app/Modules/Expenses/Api/expenses.php's action:update path used to be able to bypass
 * before both clients were routed through this service.
 */
class ExpenseApprovalServiceTest extends TestCase
{
    private function makeStmt(mixed $fetchReturn = false): PDOStatement
    {
        $s = $this->createMock(PDOStatement::class);
        $s->method('execute')->willReturn(true);
        $s->method('fetch')->willReturn($fetchReturn);
        return $s;
    }

    // ── approve ──────────────────────────────────────────────────────────

    public function test_approve_requires_expense_id(): void
    {
        $db = $this->createMock(PDO::class);
        $svc = new ExpenseApprovalService($db);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Expense ID required');
        $svc->approve(0, ['id' => 5]);
    }

    public function test_approve_throws_when_expense_not_found(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt(false));
        $svc = new ExpenseApprovalService($db);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Expense not found');
        $svc->approve(42, ['id' => 5]);
    }

    public function test_approve_blocks_self_approval(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt(['id' => 42, 'status' => 'draft', 'created_by' => 5]));
        $svc = new ExpenseApprovalService($db);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Cannot approve your own expense');
        $svc->approve(42, ['id' => 5]);
    }

    public function test_approve_succeeds_for_a_different_approver(): void
    {
        $checkStmt = $this->makeStmt(['id' => 42, 'status' => 'draft', 'created_by' => 5]);
        $updateStmt = $this->makeStmt();

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls($checkStmt, $updateStmt);

        $svc = new ExpenseApprovalService($db);
        $result = $svc->approve(42, ['id' => 9]);

        $this->assertTrue($result['success']);
        $this->assertSame('Expense approved', $result['message']);
    }

    private function makeColumnStmt(mixed $columnReturn): PDOStatement
    {
        $s = $this->createMock(PDOStatement::class);
        $s->method('execute')->willReturn(true);
        $s->method('fetchColumn')->willReturn($columnReturn);
        return $s;
    }

    public function test_approve_allows_self_approval_when_flag_enabled(): void
    {
        $checkStmt  = $this->makeStmt(['id' => 42, 'status' => 'draft', 'created_by' => 5]);
        $flagStmt   = $this->makeColumnStmt(1);
        $updateStmt = $this->makeStmt();

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls($checkStmt, $flagStmt, $updateStmt);

        $svc = new ExpenseApprovalService($db);
        $result = $svc->approve(42, ['id' => 5]); // same user created + approves

        $this->assertTrue($result['success']);
        $this->assertSame('Expense approved', $result['message']);
    }

    public function test_approve_still_blocks_self_approval_when_flag_disabled(): void
    {
        $checkStmt = $this->makeStmt(['id' => 42, 'status' => 'draft', 'created_by' => 5]);
        $flagStmt  = $this->makeColumnStmt(0);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls($checkStmt, $flagStmt);

        $svc = new ExpenseApprovalService($db);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Cannot approve your own expense');
        $svc->approve(42, ['id' => 5]);
    }

    // ── reject ───────────────────────────────────────────────────────────

    public function test_reject_requires_expense_id(): void
    {
        $db = $this->createMock(PDO::class);
        $svc = new ExpenseApprovalService($db);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Expense ID required');
        $svc->reject(0, ['id' => 5], 'not needed');
    }

    public function test_reject_requires_a_non_empty_reason(): void
    {
        $db = $this->createMock(PDO::class);
        $svc = new ExpenseApprovalService($db);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Rejection reason is required');
        $svc->reject(42, ['id' => 5], '   ');
    }

    public function test_reject_throws_when_expense_not_found(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt(false));
        $svc = new ExpenseApprovalService($db);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Expense not found');
        $svc->reject(42, ['id' => 5], 'wrong category');
    }

    public function test_reject_succeeds_with_a_reason(): void
    {
        $checkStmt = $this->makeStmt(['id' => 42, 'created_by' => 5]);
        $updateStmt = $this->makeStmt();
        $activityLogStmt = $this->makeStmt();

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls($checkStmt, $updateStmt, $activityLogStmt);

        $svc = new ExpenseApprovalService($db);
        $result = $svc->reject(42, ['id' => 9], 'Missing GST breakdown');

        $this->assertTrue($result['success']);
        $this->assertSame('Expense rejected', $result['message']);
    }

    public function test_reject_still_succeeds_if_activity_log_insert_fails(): void
    {
        $checkStmt = $this->makeStmt(['id' => 42, 'created_by' => 5]);
        $updateStmt = $this->makeStmt();
        $callCount = 0;

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnCallback(function () use (&$callCount, $checkStmt, $updateStmt) {
            $callCount++;
            if ($callCount === 1) return $checkStmt;
            if ($callCount === 2) return $updateStmt;
            throw new PDOException('activity_log missing');
        });

        $svc = new ExpenseApprovalService($db);
        $result = $svc->reject(42, ['id' => 9], 'Missing GST breakdown');

        $this->assertTrue($result['success']);
    }

    // ── approveBatch / rejectBatch ──────────────────────────────────────

    public function test_approveBatch_requires_expense_ids(): void
    {
        $db = $this->createMock(PDO::class);
        $svc = new ExpenseApprovalService($db);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('expense_ids array is required');
        $svc->approveBatch([], ['id' => 5]);
    }

    public function test_approveBatch_caps_at_fifty(): void
    {
        $db = $this->createMock(PDO::class);
        $svc = new ExpenseApprovalService($db);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Maximum 50 expenses per batch');
        $svc->approveBatch(range(1, 51), ['id' => 5]);
    }

    public function test_approveBatch_continues_past_a_failed_item(): void
    {
        // id 42: created by someone else — approves cleanly (check + update).
        // id 43: created by the same user who's approving — self-approval, fails at
        // the check + own-flag lookup (flag disabled, so it's still blocked).
        $check42  = $this->makeStmt(['id' => 42, 'status' => 'draft', 'created_by' => 5]);
        $update42 = $this->makeStmt();
        $check43  = $this->makeStmt(['id' => 43, 'status' => 'draft', 'created_by' => 9]);
        $flag43   = $this->makeColumnStmt(0);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls($check42, $update42, $check43, $flag43);

        $svc = new ExpenseApprovalService($db);
        $result = $svc->approveBatch([42, 43], ['id' => 9]);

        $this->assertTrue($result['success']);
        $this->assertSame([42], $result['approved']);
        $this->assertCount(1, $result['failed']);
        $this->assertSame(43, $result['failed'][0]['id']);
        $this->assertSame('Cannot approve your own expense', $result['failed'][0]['error']);
    }

    public function test_approveBatch_dedupes_and_ignores_invalid_ids(): void
    {
        $check42  = $this->makeStmt(['id' => 42, 'status' => 'draft', 'created_by' => 5]);
        $update42 = $this->makeStmt();

        $db = $this->createMock(PDO::class);
        // Duplicate 42s and a zero/negative id collapse to a single real lookup pair.
        $db->method('prepare')->willReturnOnConsecutiveCalls($check42, $update42);

        $svc = new ExpenseApprovalService($db);
        $result = $svc->approveBatch([42, 42, 0, -1], ['id' => 9]);

        $this->assertSame([42], $result['approved']);
        $this->assertSame([], $result['failed']);
    }

    public function test_rejectBatch_requires_expense_ids(): void
    {
        $db = $this->createMock(PDO::class);
        $svc = new ExpenseApprovalService($db);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('expense_ids array is required');
        $svc->rejectBatch([], ['id' => 5], 'reason');
    }

    public function test_rejectBatch_isolates_a_missing_reason_per_item(): void
    {
        // reject() validates the reason before touching the DB, so an empty reason
        // fails every item in the batch without any prepare() calls happening.
        $db = $this->createMock(PDO::class);
        $svc = new ExpenseApprovalService($db);

        $result = $svc->rejectBatch([1, 2], ['id' => 9], '   ');

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['rejected']);
        $this->assertCount(2, $result['failed']);
        $this->assertSame('Rejection reason is required', $result['failed'][0]['error']);
    }

    public function test_rejectBatch_succeeds_for_valid_ids(): void
    {
        $check1        = $this->makeStmt(['id' => 1, 'created_by' => 5]);
        $update1       = $this->makeStmt();
        $activityLog1  = $this->makeStmt();

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls($check1, $update1, $activityLog1);

        $svc = new ExpenseApprovalService($db);
        $result = $svc->rejectBatch([1], ['id' => 9], 'Bad receipt');

        $this->assertTrue($result['success']);
        $this->assertSame([1], $result['rejected']);
        $this->assertSame([], $result['failed']);
    }
}
