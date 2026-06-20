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

    // ── bank-import row → journal entry (Step 6) ─────────────────────────────────

    private function lineByAccount(array $entry, int $accountId): array
    {
        foreach ($entry['lines'] as $l) {
            if ((int)$l['account_id'] === $accountId) { return $l; }
        }
        $this->fail("No line for account_id $accountId");
    }

    private function assertEntryBalances(array $entry): void
    {
        $d = 0.0; $c = 0.0;
        foreach ($entry['lines'] as $l) { $d += (float)($l['debit'] ?? 0); $c += (float)($l['credit'] ?? 0); }
        $this->assertEqualsWithDelta($d, $c, 0.005, 'bank entry does not balance');
    }

    // ids: ITC=10, GST collected=11, default bank=1
    /** @test */
    public function bank_expense_debits_category_and_itc_credits_bank(): void
    {
        $e = $this->service()->bankRowToEntryArgs([
            'id' => 50, 'transaction_date' => '2025-02-28', 'type' => 'expense',
            'account_id' => 5100, 'account_type' => 'expense', 'account_code' => '5100',
            'bank_account_id' => 1, 'amount' => 1062.37, 'gst_amount' => 0, 'pst_amount' => 0,
            'description' => 'ETRANSFER DEBIT (NIGEL CASEY)',
        ], 10, 11, 1, ['5100' => 7]);  // 5100 → Labour cost type id 7

        $this->assertSame('bank_import', $e['source_type']);
        $this->assertSame(50, $e['source_id']);
        $this->assertEqualsWithDelta(1062.37, $this->lineByAccount($e, 5100)['debit'],  0.001);
        $this->assertEqualsWithDelta(1062.37, $this->lineByAccount($e, 1)['credit'],    0.001); // bank
        $this->assertSame(7, $this->lineByAccount($e, 5100)['cost_type_id']);                    // GGOB tag
        $this->assertEntryBalances($e);
    }

    /** @test */
    public function bank_expense_with_gst_splits_out_itc(): void
    {
        $e = $this->service()->bankRowToEntryArgs([
            'id' => 51, 'transaction_date' => '2025-02-28', 'type' => 'expense',
            'account_id' => 6100, 'account_type' => 'expense', 'account_code' => '6100',
            'bank_account_id' => 1, 'amount' => 105.00, 'gst_amount' => 5.00, 'pst_amount' => 0,
        ], 10, 11, 1, []);
        $this->assertEqualsWithDelta(100.00, $this->lineByAccount($e, 6100)['debit'], 0.001); // net
        $this->assertEqualsWithDelta(5.00,   $this->lineByAccount($e, 10)['debit'],   0.001); // ITC
        $this->assertEqualsWithDelta(105.00, $this->lineByAccount($e, 1)['credit'],   0.001); // bank
        $this->assertEntryBalances($e);
    }

    /** @test */
    public function bank_transfer_debits_category_credits_bank(): void
    {
        // Vancity Visa bill payment: DR 2400 Credit Card Payable / CR 1010 Bank
        $e = $this->service()->bankRowToEntryArgs([
            'id' => 52, 'transaction_date' => '2025-02-28', 'type' => 'transfer',
            'account_id' => 2400, 'account_type' => 'liability', 'account_code' => '2400',
            'bank_account_id' => 1, 'amount' => 2700.00, 'gst_amount' => 0, 'pst_amount' => 0,
        ], 10, 11, 1, []);
        $this->assertEqualsWithDelta(2700.00, $this->lineByAccount($e, 2400)['debit'],  0.001);
        $this->assertEqualsWithDelta(2700.00, $this->lineByAccount($e, 1)['credit'],    0.001);
        $this->assertEntryBalances($e);
    }

    /** @test */
    public function bank_income_to_non_revenue_account_posts(): void
    {
        // Interest credited: DR Bank / CR (some income account, non-revenue type)
        $e = $this->service()->bankRowToEntryArgs([
            'id' => 53, 'transaction_date' => '2025-12-31', 'type' => 'income',
            'account_id' => 4950, 'account_type' => 'other_income', 'account_code' => '4950',
            'bank_account_id' => 1, 'amount' => 8.72, 'gst_amount' => 0, 'pst_amount' => 0,
        ], 10, 11, 1, []);
        $this->assertNotNull($e);
        $this->assertEqualsWithDelta(8.72, $this->lineByAccount($e, 1)['debit'],    0.001);
        $this->assertEqualsWithDelta(8.72, $this->lineByAccount($e, 4950)['credit'], 0.001);
        $this->assertEntryBalances($e);
    }

    /** @test */
    public function bank_income_to_revenue_account_is_skipped(): void
    {
        // Customer payment deposit hitting 4900 Revenue → already on invoice side → skip.
        $e = $this->service()->bankRowToEntryArgs([
            'id' => 54, 'transaction_date' => '2025-02-27', 'type' => 'income',
            'account_id' => 4900, 'account_type' => 'revenue', 'account_code' => '4900',
            'bank_account_id' => 1, 'amount' => 61.00, 'gst_amount' => 0, 'pst_amount' => 0,
        ], 10, 11, 1, []);
        $this->assertNull($e);
    }

    /** @test */
    public function null_bank_account_falls_back_to_default(): void
    {
        $e = $this->service()->bankRowToEntryArgs([
            'id' => 55, 'transaction_date' => '2025-02-28', 'type' => 'expense',
            'account_id' => 6800, 'account_type' => 'expense', 'account_code' => '6800',
            'bank_account_id' => null, 'amount' => 85.35, 'gst_amount' => 0, 'pst_amount' => 0,
        ], 10, 11, 99, []);  // default bank id = 99
        $this->assertEqualsWithDelta(85.35, $this->lineByAccount($e, 99)['credit'], 0.001);
        $this->assertEntryBalances($e);
    }
}
