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
}
