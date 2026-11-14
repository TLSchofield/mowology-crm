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

    public function test_line_edits_and_rescans_use_the_same_rules(): void
    {
        // Codex nightly 2026-11-05: line edits skipped the approved check, and a rescan read any media id.
        $root = dirname(__DIR__, 3) . '/app/Modules/Expenses/Api/';
        $lines = file_get_contents($root . 'expense-line-items.php');
        $this->assertStringContainsString('ExpenseService::editBlockReason(', $lines, 'line edits follow the header edit rule');
        $intake = file_get_contents($root . 'receipt-intake.php');
        $this->assertStringContainsString('ExpenseService::currentUserCanSeeReceiptMedia($db, $rescanMediaId', $intake, 'a rescan only reads a photo you may open');
    }

    public function test_crew_cannot_delete_an_approved_receipt_but_can_delete_a_draft(): void
    {
        $crew = ['id' => 8, 'is_admin' => false];
        $this->assertNull(ExpenseService::deleteBlockReason(['created_by' => 8, 'status' => 'draft'], $crew));
        $this->assertStringContainsString('approved', (string)ExpenseService::deleteBlockReason(['created_by' => 8, 'status' => 'approved'], $crew));
        $this->assertNotNull(ExpenseService::deleteBlockReason(['created_by' => 1, 'status' => 'draft'], $crew));
        $office = ['id' => 1, 'is_admin' => true];
        $this->assertNull(ExpenseService::deleteBlockReason(['created_by' => 8, 'status' => 'approved'], $office));
        $this->assertNotNull(ExpenseService::deleteBlockReason(['created_by' => 8, 'status' => 'forwarded'], $office));
    }

    public function test_a_supplied_date_that_is_not_a_real_day_is_refused(): void
    {
        $this->assertNull(ExpenseService::suppliedDateProblem(null));
        $this->assertNull(ExpenseService::suppliedDateProblem(''));
        $this->assertNull(ExpenseService::suppliedDateProblem('2026-02-28'));
        $this->assertNull(ExpenseService::suppliedDateProblem('2026-02-28T10:00:00'));
        $this->assertNotNull(ExpenseService::suppliedDateProblem('2026-02-30'));
        $this->assertNotNull(ExpenseService::suppliedDateProblem('2026-1'));
    }
}
