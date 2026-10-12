<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Review round 2 (2026-10-12): one rule for who may change / send a receipt, shared by the iPhone
 * and the desktop (expenses.php edit, line items, batch forward, receipt-actions send).
 */
class ExpenseAccessRulesTest extends TestCase
{
    public function test_crew_edit_only_their_own_unapproved_receipts(): void
    {
        $crew = ['id' => 8, 'is_admin' => false];
        $this->assertNull(ExpenseService::editBlockReason(['created_by' => 8, 'status' => 'draft'], $crew));
        $this->assertSame('You can only edit your own expenses',
            ExpenseService::editBlockReason(['created_by' => 1, 'status' => 'draft'], $crew));
        $this->assertStringContainsString('approved', (string)ExpenseService::editBlockReason(['created_by' => 8, 'status' => 'approved'], $crew));
    }

    public function test_office_edits_any_but_never_a_sent_receipt(): void
    {
        $office = ['id' => 1, 'is_admin' => true];
        $this->assertNull(ExpenseService::editBlockReason(['created_by' => 6, 'status' => 'approved'], $office));
        $this->assertNotNull(ExpenseService::editBlockReason(['created_by' => 6, 'status' => 'approved', 'forwarded_to_accounting' => 1], $office));
    }

    public function test_only_approved_receipts_are_sent(): void
    {
        $this->assertNull(ExpenseService::sendBlockReason(['status' => 'approved', 'forwarded_to_accounting' => 0]));
        foreach (['draft', 'pending_approval', 'rejected'] as $st) {
            $this->assertNotNull(ExpenseService::sendBlockReason(['status' => $st]), $st);
        }
        $this->assertNotNull(ExpenseService::sendBlockReason(['status' => 'approved', 'forwarded_to_accounting' => 1]));
    }

    public function test_office_is_admin_manager_or_approver(): void
    {
        $this->assertTrue(ExpenseService::isOffice('admin', false));
        $this->assertTrue(ExpenseService::isOffice('manager', false));
        $this->assertTrue(ExpenseService::isOffice('staff', true));
        $this->assertFalse(ExpenseService::isOffice('staff', false));
        $this->assertFalse(ExpenseService::isOffice('user', false));
    }
}
