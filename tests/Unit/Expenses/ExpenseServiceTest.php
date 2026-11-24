<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for ExpenseService::update() — the ownership- and status-guarded field edit
 * shared by the JWT/mobile edit endpoint (expense-update.php). The load-bearing rules:
 * you can't edit an expense that doesn't exist, one you don't own (unless admin), or one
 * already forwarded to accounting; and a retyped vendor name unlinks vendor_id so the
 * edit actually shows.
 */
class ExpenseServiceTest extends TestCase
{
    private ?ExpenseGateSpy $gate = null;

    private function makeStmt(mixed $fetchReturn = false): PDOStatement
    {
        $s = $this->createMock(PDOStatement::class);
        $s->method('execute')->willReturn(true);
        $s->method('fetch')->willReturn($fetchReturn);
        return $s;
    }

    // ── delete ─────────────────────────────────────────────────────────

    public function test_delete_requires_expense_id(): void
    {
        $svc = new ExpenseService($this->createMock(PDO::class), new ExpenseGateSpy());
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Expense ID required');
        $svc->delete(0, ['id' => 1, 'is_admin' => true]);
    }

    public function test_delete_blocks_non_owner_non_admin(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt([
            'id' => 42, 'created_by' => 9, 'status' => 'draft', 'forwarded_to_accounting' => 0,
        ]));
        $svc = new ExpenseService($db, $this->gate = new ExpenseGateSpy());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('You can only delete your own expenses');
        $svc->delete(42, ['id' => 5, 'is_admin' => false]);
    }

    public function test_delete_blocks_already_sent_even_for_admin(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt([
            'id' => 42, 'created_by' => 5, 'status' => 'forwarded', 'forwarded_to_accounting' => 1,
        ]));
        $svc = new ExpenseService($db, $this->gate = new ExpenseGateSpy());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('can no longer be deleted');
        $svc->delete(42, ['id' => 1, 'is_admin' => true]);
    }

    public function test_delete_owner_draft_succeeds(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt([
            'id' => 42, 'created_by' => 5, 'status' => 'draft', 'forwarded_to_accounting' => 0,
        ]));
        $svc = new ExpenseService($db, $this->gate = new ExpenseGateSpy());

        $result = $svc->delete(42, ['id' => 5, 'is_admin' => false]);
        $this->assertTrue($result['success']);
        $this->assertSame(42, $result['expense_id']);
    }

    public function test_update_requires_expense_id(): void
    {
        $svc = new ExpenseService($this->createMock(PDO::class), new ExpenseGateSpy());
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Expense ID required');
        $svc->update(0, ['id' => 1, 'is_admin' => true], []);
    }

    public function test_update_throws_when_not_found(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt(false));
        $svc = new ExpenseService($db, $this->gate = new ExpenseGateSpy());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Expense not found');
        $svc->update(42, ['id' => 1, 'is_admin' => true], []);
    }

    public function test_update_blocks_non_owner_non_admin(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt([
            'id' => 42, 'created_by' => 9, 'status' => 'draft',
            'forwarded_to_accounting' => 0, 'vendor_id' => null, 'vendor_name_raw' => 'X',
        ]));
        $svc = new ExpenseService($db, $this->gate = new ExpenseGateSpy());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('You can only edit your own expenses');
        $svc->update(42, ['id' => 5, 'is_admin' => false], []);
    }

    public function test_update_blocks_already_sent(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt([
            'id' => 42, 'created_by' => 5, 'status' => 'forwarded',
            'forwarded_to_accounting' => 1, 'vendor_id' => null, 'vendor_name_raw' => 'X',
        ]));
        $svc = new ExpenseService($db, $this->gate = new ExpenseGateSpy());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('sent to accounting');
        $svc->update(42, ['id' => 5, 'is_admin' => true], ['total' => 10]);
    }

    public function test_owner_can_edit_own_draft(): void
    {
        $checkStmt  = $this->makeStmt([
            'id' => 42, 'created_by' => 5, 'status' => 'draft',
            'forwarded_to_accounting' => 0, 'vendor_id' => null, 'vendor_name_raw' => 'HOME DEPOT',
        ]);
        $updateStmt = $this->makeStmt();

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls($checkStmt, $updateStmt);
        $svc = new ExpenseService($db, $this->gate = new ExpenseGateSpy());

        $result = $svc->update(42, ['id' => 5, 'is_admin' => false], [
            'expense_date' => '2026-05-10', 'vendor_name_raw' => 'HOME DEPOT', 'total' => 42.50,
        ]);
        $this->assertTrue($result['success']);
        $this->assertSame(42, $result['expense_id']);
    }

    public function test_admin_can_edit_others_expense(): void
    {
        $checkStmt  = $this->makeStmt([
            'id' => 7, 'created_by' => 9, 'status' => 'approved',
            'forwarded_to_accounting' => 0, 'vendor_id' => 3, 'vendor_name_raw' => 'ESSO',
        ]);
        $updateStmt = $this->makeStmt();

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls($checkStmt, $updateStmt);
        $svc = new ExpenseService($db, $this->gate = new ExpenseGateSpy());

        $result = $svc->update(7, ['id' => 1, 'is_admin' => true], ['total' => 5]);
        $this->assertTrue($result['success']);
    }

    public function test_the_phone_edit_goes_through_the_gate(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt([
            'id' => 42, 'created_by' => 5, 'status' => 'draft', 'forwarded_to_accounting' => 0,
            'vendor_id' => 3, 'vendor_name_raw' => 'CHEVRON', 'raw_ocr_json' => 'CHEVRON DIESEL',
        ]));
        $svc = new ExpenseService($db, $gate = new ExpenseGateSpy());
        $svc->update(42, ['id' => 5, 'is_admin' => false], ['vendor_name_raw' => 'CHEVRON', 'total' => 88.10, 'gst_amount' => 4.20,
                                                           'accounting_category' => 'Fuel', 'expense_date' => '2026-10-07']);
        $this->assertCount(1, $gate->calls);
        $c = $gate->calls[0];
        $this->assertSame('ios_update', $c['source']);
        $this->assertSame(['id' => 5, 'kind' => 'user'], $c['actor']);
        $this->assertSame(88.10, $c['changes']['total']);
        $this->assertSame(3, $c['changes']['vendor_id'], 'unchanged vendor name keeps its link');
        $this->assertArrayNotHasKey('notes', $c['changes'], 'the phone never wipes desktop notes');
        $this->assertNotEmpty($c['opts']['learn_lines'], 'an OCR\'d receipt: line lessons from the save');
    }

    public function test_delete_goes_through_the_gate(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt(['id' => 42, 'created_by' => 5, 'status' => 'draft', 'forwarded_to_accounting' => 0]));
        $svc = new ExpenseService($db, $gate = new ExpenseGateSpy());
        $svc->delete(42, ['id' => 5, 'is_admin' => false, 'source' => 'ios_delete']);
        $this->assertSame([ExpenseGate::DELETE => true], $gate->calls[0]['changes']);
        $this->assertSame('ios_delete', $gate->calls[0]['source']);
    }
}
