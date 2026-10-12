<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Review round 2 (2026-10-12): the transactions screen can't change a bank/invoice/expense line's
 * money, date or status, and the ledger cron reports a failed journal sync instead of 'success'.
 */
class AccountingEditGuardTest extends TestCase
{
    private array $bank = ['reference_type' => 'bank_import', 'amount' => '120.50', 'gst_amount' => '0.00',
                           'pst_amount' => null, 'transaction_date' => '2026-09-14', 'status' => 'cleared'];

    public function test_unchanged_values_from_the_modal_are_not_a_change(): void
    {
        $this->assertSame([], AccountingService::blockedChanges($this->bank, [
            'amount' => '120.5', 'gst_amount' => '', 'pst_amount' => '0', 'transaction_date' => '2026-09-14',
            'status' => 'cleared', 'description' => 'new words', 'notes' => 'x',
        ]));
    }

    public function test_bank_line_money_date_and_status_are_blocked(): void
    {
        $this->assertSame(['amount', 'transaction_date', 'status'], AccountingService::blockedChanges($this->bank, [
            'amount' => '99', 'transaction_date' => '2026-09-15', 'status' => 'pending',
        ]));
    }

    public function test_manual_lines_stay_editable(): void
    {
        $this->assertSame([], AccountingService::blockedChanges(['reference_type' => 'manual', 'amount' => '1'], ['amount' => '5']));
    }

    public function test_journal_problem(): void
    {
        $this->assertNull(AccountingService::journalProblem(null));
        $this->assertNull(AccountingService::journalProblem(['invoices' => [], 'bank_imports' => ['errors' => 0]]));
        $this->assertSame('error', AccountingService::journalProblem(['error' => 'Table x missing'])[0]);
        $this->assertSame('warning', AccountingService::journalProblem(['bank_imports' => ['errors' => 2]])[0]);
    }
}
