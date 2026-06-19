<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for LedgerSyncService — the pure mappers that turn invoice/expense rows
 * into LedgerService recipe args (Phase 1, Step 3). DB sync paths are exercised
 * indirectly; the accounting logic lives in these mappers and is tested directly.
 */
class LedgerSyncServiceTest extends TestCase
{
    private function service(): LedgerSyncService
    {
        $pdo = $this->createMock(PDO::class);
        return new LedgerSyncService($pdo, new LedgerService($pdo));
    }

    // ── invoice → invoice entry args ────────────────────────────────────────────

    /** @test */
    public function maps_invoice_row_to_accrual_args(): void
    {
        $args = $this->service()->mapInvoiceToInvoiceArgs([
            'id' => 12, 'subtotal' => 100.00, 'tax_amount' => 5.00, 'total' => 105.00,
            'amount_paid' => 0, 'contact_id' => 3, 'plan_id' => 8,
            'issue_date' => '2026-02-01', 'paid_at' => null, 'created_at' => '2026-02-01 10:00:00',
        ]);
        $this->assertSame(12, $args['id']);
        $this->assertSame('2026-02-01', $args['date']);
        $this->assertEqualsWithDelta(100.00, $args['net'], 0.001);
        $this->assertEqualsWithDelta(5.00,   $args['gst'], 0.001);
        $this->assertSame(8, $args['job_id']);
    }

    /** @test */
    public function unpaid_invoice_yields_no_payment_args(): void
    {
        $args = $this->service()->mapInvoiceToPaymentArgs([
            'id' => 12, 'amount_paid' => 0, 'paid_at' => null, 'created_at' => '2026-02-01', 'contact_id' => 3,
        ]);
        $this->assertNull($args);
    }

    /** @test */
    public function paid_invoice_yields_payment_args(): void
    {
        $args = $this->service()->mapInvoiceToPaymentArgs([
            'id' => 12, 'amount_paid' => 105.00, 'paid_at' => '2026-02-10', 'created_at' => '2026-02-01', 'contact_id' => 3,
        ]);
        $this->assertNotNull($args);
        $this->assertEqualsWithDelta(105.00, $args['amount'], 0.001);
        $this->assertSame('2026-02-10', $args['date']);
        $this->assertSame(12, $args['id']); // idempotency keyed on invoice id
    }

    // ── expense → expense entry args ────────────────────────────────────────────

    /** @test */
    public function maps_expense_gross_total_to_net_gst_pst(): void
    {
        // gross 112 = net 100 + gst 5 + pst 7
        $args = $this->service()->mapExpenseToExpenseArgs(
            [
                'id' => 4, 'expense_date' => '2026-03-03', 'total' => 112.00,
                'gst_amount' => 5.00, 'pst_amount' => 7.00, 'accounting_category' => 'Materials',
                'payment_method' => 'credit_card', 'vendor_id' => 9, 'job_id' => 44, 'contact_id' => null,
            ],
            ['materials' => '5200'],
            ['materials' => 2]
        );
        $this->assertEqualsWithDelta(100.00, $args['net'], 0.001);   // 112 − 5 − 7
        $this->assertEqualsWithDelta(5.00,   $args['gst'], 0.001);
        $this->assertEqualsWithDelta(7.00,   $args['pst'], 0.001);
        $this->assertSame('5200', $args['expense_account']);
        $this->assertSame('2400', $args['funding']);                 // credit card
        $this->assertSame(2, $args['cost_type_id']);
        $this->assertSame(44, $args['job_id']);
    }

    /** @test */
    public function unmapped_category_falls_back_to_misc_account(): void
    {
        $args = $this->service()->mapExpenseToExpenseArgs(
            ['id' => 5, 'expense_date' => '2026-03-03', 'total' => 50.00, 'gst_amount' => 0, 'pst_amount' => 0,
             'accounting_category' => 'Mystery', 'payment_method' => 'etransfer'],
            ['materials' => '5200'],
            []
        );
        $this->assertSame('6900', $args['expense_account']); // misc fallback
        $this->assertSame('1010', $args['funding']);          // non-card → bank
        $this->assertNull($args['cost_type_id']);
    }

    // ── funding account resolution ──────────────────────────────────────────────

    /** @test */
    public function funding_account_maps_card_to_credit_card_else_bank(): void
    {
        $s = $this->service();
        $this->assertSame('2400', $s->fundingAccountFor('credit_card'));
        $this->assertSame('2400', $s->fundingAccountFor('company_card'));
        $this->assertSame('1010', $s->fundingAccountFor('etransfer'));
        $this->assertSame('1010', $s->fundingAccountFor('cash'));
        $this->assertSame('1010', $s->fundingAccountFor(''));
    }
}
