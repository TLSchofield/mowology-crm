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
        // Invoices/receipts that failed to post are reported too (they used to count as "skipped").
        $p = AccountingService::journalProblem(['invoices' => ['errors' => 1, 'failures' => [['source' => 'invoice', 'id' => 7, 'reason' => 'Chart of accounts code not found: 4120']]],
                                                'expenses' => ['errors' => 2], 'bank_imports' => ['errors' => 0]]);
        $this->assertSame('warning', $p[0]);
        $this->assertStringContainsString('1 invoice, 2 receipts', $p[1]);
        $this->assertStringContainsString('invoice #7: Chart of accounts code not found: 4120', $p[1]);
        $this->assertNull(AccountingService::journalProblem(['invoices' => ['errors' => 0, 'skipped' => 40], 'expenses' => ['errors' => 0]]));
    }

    public function testClosedYearIsAnExpectedSkipEverythingElseIsAFailure(): void
    {
        require_once APP_ROOT . '/Modules/Accounting/Services/LedgerSyncService.php';
        $this->assertTrue(LedgerSyncService::isExpectedSkip(new RuntimeException('Closed year: invoice #5 is dated 2025-12-01, before 2026-01-01 — not posted')));
        $this->assertFalse(LedgerSyncService::isExpectedSkip(new RuntimeException('Accounting period 2026-03 is locked; cannot post.')));
        $this->assertFalse(LedgerSyncService::isExpectedSkip(new TypeError('Too few arguments')));
    }
}
