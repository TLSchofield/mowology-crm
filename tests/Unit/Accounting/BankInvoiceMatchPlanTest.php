<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Bank import commit, deposit auto-matched to an invoice: the invoice is never marked
 * paid directly any more — the payment goes through the allocation ledger, or the
 * already-recorded payment is linked, or the deposit is just a transfer.
 */
class BankInvoiceMatchPlanTest extends TestCase
{
    private function open(float $balance = 500.0): array
    {
        return ['status' => 'partial', 'balance_due' => $balance];
    }

    public function test_processor_matches_are_transfer_only(): void
    {
        foreach (['reference', 'amount', ''] as $m) {
            $this->assertSame('transfer_only', BankImportService::invoiceMatchPlan($m, $this->open(), 500, [])['action']);
        }
    }

    public function test_no_invoice_is_transfer_only(): void
    {
        $this->assertSame('transfer_only', BankImportService::invoiceMatchPlan('etransfer', null, 500, [])['action']);
    }

    public function test_open_invoice_records_the_payment_through_allocation(): void
    {
        $p = BankImportService::invoiceMatchPlan('etransfer', $this->open(), 500.004, []);
        $this->assertSame('allocate', $p['action']);
        $this->assertSame(500.0, $p['amount']);
    }

    public function test_already_recorded_payment_is_linked_not_credited_again(): void
    {
        $unlinked = [['id' => 7, 'amount' => '200.00'], ['id' => 9, 'amount' => '300.00']];
        $p = BankImportService::invoiceMatchPlan('etransfer', $this->open(), 500, $unlinked);
        $this->assertSame('link_recorded', $p['action']);
        $this->assertSame([7, 9], $p['allocation_ids']);

        $p = BankImportService::invoiceMatchPlan('etransfer', $this->open(), 300, $unlinked);
        $this->assertSame([9], $p['allocation_ids']);
    }

    public function test_unlinked_payments_that_do_not_add_up_fall_through(): void
    {
        $p = BankImportService::invoiceMatchPlan('etransfer', $this->open(), 450, [['id' => 7, 'amount' => 200]]);
        $this->assertSame('allocate', $p['action']);
    }

    public function test_paid_or_cancelled_invoice_is_transfer_only(): void
    {
        $this->assertSame('transfer_only', BankImportService::invoiceMatchPlan('etransfer', ['status' => 'paid', 'balance_due' => 0], 500, [])['action']);
        $this->assertSame('transfer_only', BankImportService::invoiceMatchPlan('etransfer', ['status' => 'cancelled', 'balance_due' => 500], 500, [])['action']);
        $this->assertSame('transfer_only', BankImportService::invoiceMatchPlan('etransfer', ['status' => 'sent', 'balance_due' => 0], 500, [])['action']);
    }

    public function test_paid_invoice_with_its_recorded_payment_still_links_it(): void
    {
        $p = BankImportService::invoiceMatchPlan('etransfer', ['status' => 'paid', 'balance_due' => 0], 500, [['id' => 3, 'amount' => 500]]);
        $this->assertSame('link_recorded', $p['action']);
        $this->assertSame([3], $p['allocation_ids']);
    }
}
