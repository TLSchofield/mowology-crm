<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for LedgerService — the double-entry posting engine (Phase 1, Step 1).
 *
 * Covers the core integrity guarantee (validateEntry) and postEntry behaviour
 * (period-lock rejection, idempotency by source) with a mocked PDO so nothing
 * touches the database.
 */
class LedgerServiceTest extends TestCase
{
    /** A minimal balanced entry: DR 100 / CR 100. */
    private function balancedEntry(): array
    {
        return [
            'entry_date'  => '2026-03-15',
            'memo'        => 'Test entry',
            'source_type' => 'manual',
            'lines'       => [
                ['account_id' => 1, 'debit' => 100.00, 'credit' => 0],
                ['account_id' => 2, 'debit' => 0,      'credit' => 100.00],
            ],
        ];
    }

    /** A LedgerService with a PDO whose prepared statements return $fetchColumn. */
    private function serviceReturning($fetchColumn): LedgerService
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn($fetchColumn);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        return new LedgerService($pdo);
    }

    private function bareService(): LedgerService
    {
        return new LedgerService($this->createMock(PDO::class));
    }

    // ── validateEntry: the balancing invariant ─────────────────────────────────

    /** @test */
    public function balanced_entry_validates(): void
    {
        $this->bareService()->validateEntry($this->balancedEntry());
        $this->addToAssertionCount(1); // no exception thrown
    }

    /** @test */
    public function unbalanced_entry_is_rejected(): void
    {
        $entry = $this->balancedEntry();
        $entry['lines'][1]['credit'] = 90.00; // debits 100 != credits 90

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not balance/i');
        $this->bareService()->validateEntry($entry);
    }

    /** @test */
    public function line_with_both_debit_and_credit_is_rejected(): void
    {
        $entry = $this->balancedEntry();
        $entry['lines'][0] = ['account_id' => 1, 'debit' => 100.00, 'credit' => 100.00];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/both a debit and a credit/i');
        $this->bareService()->validateEntry($entry);
    }

    /** @test */
    public function line_with_neither_debit_nor_credit_is_rejected(): void
    {
        $entry = $this->balancedEntry();
        $entry['lines'][0] = ['account_id' => 1, 'debit' => 0, 'credit' => 0];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/either a debit or a credit/i');
        $this->bareService()->validateEntry($entry);
    }

    /** @test */
    public function negative_amount_is_rejected(): void
    {
        $entry = $this->balancedEntry();
        $entry['lines'][0]['debit'] = -100.00;

        $this->expectException(InvalidArgumentException::class);
        $this->bareService()->validateEntry($entry);
    }

    /** @test */
    public function fewer_than_two_lines_is_rejected(): void
    {
        $entry = ['lines' => [['account_id' => 1, 'debit' => 100, 'credit' => 0]]];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least 2 lines/i');
        $this->bareService()->validateEntry($entry);
    }

    /** @test */
    public function missing_account_id_is_rejected(): void
    {
        $entry = $this->balancedEntry();
        unset($entry['lines'][0]['account_id']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/account_id/i');
        $this->bareService()->validateEntry($entry);
    }

    /** @test */
    public function multi_line_entry_balances_within_tolerance(): void
    {
        // DR 33.33 + 33.33 + 33.34 = 100.00 ; CR 100.00
        $entry = [
            'source_type' => 'manual',
            'lines' => [
                ['account_id' => 1, 'debit' => 33.33, 'credit' => 0],
                ['account_id' => 2, 'debit' => 33.33, 'credit' => 0],
                ['account_id' => 3, 'debit' => 33.34, 'credit' => 0],
                ['account_id' => 4, 'debit' => 0,     'credit' => 100.00],
            ],
        ];
        $this->bareService()->validateEntry($entry);
        $this->addToAssertionCount(1);
    }

    // ── postEntry behaviour ─────────────────────────────────────────────────────

    /** @test */
    public function posting_into_a_locked_period_is_rejected(): void
    {
        // Manual source → skips idempotency lookup; period status lookup returns 'locked'.
        $svc = $this->serviceReturning('locked');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/locked/i');
        $svc->postEntry($this->balancedEntry());
    }

    /** @test */
    public function reposting_the_same_source_returns_existing_entry_id(): void
    {
        // Source lookup returns an existing entry id (99) → idempotent skip.
        $svc = $this->serviceReturning('99');

        $entry = $this->balancedEntry();
        $entry['source_type'] = 'invoice';
        $entry['source_id']   = 42;

        $this->assertSame(99, $svc->postEntry($entry));
    }

    // ── Recipe builders (pure, no DB) ───────────────────────────────────────────

    /** Find the single line for an account code. */
    private function lineFor(array $entry, string $code): array
    {
        foreach ($entry['lines'] as $l) {
            if (($l['account'] ?? null) === $code) {
                return $l;
            }
        }
        $this->fail("No line for account $code");
    }

    /** Assert the built entry balances (debits == credits). */
    private function assertBalances(array $entry): void
    {
        $d = 0.0; $c = 0.0;
        foreach ($entry['lines'] as $l) { $d += (float)($l['debit'] ?? 0); $c += (float)($l['credit'] ?? 0); }
        $this->assertEqualsWithDelta($d, $c, 0.005, 'entry does not balance');
    }

    /** Assign dummy account_ids by code and run the real validateEntry. */
    private function assertPassesValidation(array $entry): void
    {
        $ids = []; $next = 1;
        foreach ($entry['lines'] as &$l) {
            $code = (string)($l['account'] ?? $next);
            $ids[$code] = $ids[$code] ?? $next++;
            $l['account_id'] = $ids[$code];
        }
        unset($l);
        $this->bareService()->validateEntry($entry);
        $this->addToAssertionCount(1);
    }

    /** @test */
    public function invoice_entry_debits_AR_credits_revenue_and_gst(): void
    {
        $e = $this->bareService()->buildInvoiceEntry([
            'id' => 7, 'date' => '2026-03-01', 'net' => 100.00, 'gst' => 5.00,
            'service_type' => 'lawn_care', 'contact_id' => 3,
        ]);
        $this->assertEqualsWithDelta(105.00, $this->lineFor($e, '1100')['debit'],  0.001);
        $this->assertEqualsWithDelta(100.00, $this->lineFor($e, '4900')['credit'], 0.001);
        $this->assertEqualsWithDelta(5.00,   $this->lineFor($e, '2200')['credit'], 0.001);
        $this->assertSame('lawn_care', $this->lineFor($e, '4900')['service_type']);
        $this->assertSame('invoice', $e['source_type']);
        $this->assertSame(7, $e['source_id']);
        $this->assertBalances($e);
        $this->assertPassesValidation($e);
    }

    /** @test */
    public function invoice_without_gst_has_no_gst_line(): void
    {
        $e = $this->bareService()->buildInvoiceEntry(['id' => 8, 'date' => '2026-03-01', 'net' => 200.00]);
        $this->assertCount(2, $e['lines']);
        $this->assertEqualsWithDelta(200.00, $this->lineFor($e, '1100')['debit'],  0.001);
        $this->assertEqualsWithDelta(200.00, $this->lineFor($e, '4900')['credit'], 0.001);
        $this->assertBalances($e);
    }

    /** @test */
    public function invoice_uses_custom_revenue_account(): void
    {
        $e = $this->bareService()->buildInvoiceEntry([
            'id' => 9, 'date' => '2026-03-01', 'net' => 50.00, 'revenue_account' => '4100',
        ]);
        $this->assertEqualsWithDelta(50.00, $this->lineFor($e, '4100')['credit'], 0.001);
    }

    /** @test */
    public function payment_entry_debits_bank_credits_AR(): void
    {
        $e = $this->bareService()->buildPaymentEntry(['id' => 5, 'date' => '2026-03-02', 'amount' => 105.00]);
        $this->assertEqualsWithDelta(105.00, $this->lineFor($e, '1010')['debit'],  0.001);
        $this->assertEqualsWithDelta(105.00, $this->lineFor($e, '1100')['credit'], 0.001);
        $this->assertBalances($e);
        $this->assertPassesValidation($e);
    }

    /** @test */
    public function expense_entry_debits_expense_and_itc_credits_funding(): void
    {
        $e = $this->bareService()->buildExpenseEntry([
            'id' => 11, 'date' => '2026-03-03', 'net' => 200.00, 'gst' => 10.00,
            'expense_account' => '5200', 'cost_type_id' => 2, 'service_type' => 'lawn_care', 'job_id' => 44,
        ]);
        $this->assertEqualsWithDelta(200.00, $this->lineFor($e, '5200')['debit'], 0.001);
        $this->assertEqualsWithDelta(10.00,  $this->lineFor($e, '2210')['debit'], 0.001);  // ITC
        $this->assertEqualsWithDelta(210.00, $this->lineFor($e, '2400')['credit'], 0.001); // funding
        $this->assertSame(2, $this->lineFor($e, '5200')['cost_type_id']);
        $this->assertSame(44, $this->lineFor($e, '5200')['job_id']);
        $this->assertBalances($e);
        $this->assertPassesValidation($e);
    }

    /** @test */
    public function expense_pst_is_rolled_into_expense_cost_not_itc(): void
    {
        // net 100 + gst 5 + pst 7 = 112; PST is non-recoverable → part of expense.
        $e = $this->bareService()->buildExpenseEntry([
            'id' => 12, 'date' => '2026-03-03', 'net' => 100.00, 'gst' => 5.00, 'pst' => 7.00,
            'expense_account' => '6100',
        ]);
        $this->assertEqualsWithDelta(107.00, $this->lineFor($e, '6100')['debit'],  0.001); // net + pst
        $this->assertEqualsWithDelta(5.00,   $this->lineFor($e, '2210')['debit'],  0.001); // gst only
        $this->assertEqualsWithDelta(112.00, $this->lineFor($e, '2400')['credit'], 0.001);
        $this->assertBalances($e);
    }

    /** @test */
    public function owner_draw_debits_draw_credits_bank(): void
    {
        $e = $this->bareService()->buildOwnerDrawEntry(['date' => '2026-03-04', 'amount' => 500.00]);
        $this->assertEqualsWithDelta(500.00, $this->lineFor($e, '3300')['debit'],  0.001);
        $this->assertEqualsWithDelta(500.00, $this->lineFor($e, '1010')['credit'], 0.001);
        $this->assertBalances($e);
        $this->assertPassesValidation($e);
    }

    /** @test */
    public function transfer_debits_destination_credits_source(): void
    {
        $e = $this->bareService()->buildTransferEntry([
            'date' => '2026-03-05', 'amount' => 1000.00, 'from' => '1010', 'to' => '1020',
        ]);
        $this->assertEqualsWithDelta(1000.00, $this->lineFor($e, '1020')['debit'],  0.001);
        $this->assertEqualsWithDelta(1000.00, $this->lineFor($e, '1010')['credit'], 0.001);
        $this->assertBalances($e);
    }

    /** @test */
    public function opening_balances_plug_to_opening_equity_and_balance(): void
    {
        // Assets 1500 debit, no offsetting credit → plug 1500 credit to 3900.
        $e = $this->bareService()->buildOpeningBalanceEntry([
            'date' => '2025-01-01',
            'lines' => [
                ['account' => '1010', 'debit' => 1000.00],
                ['account' => '1100', 'debit' => 500.00],
            ],
        ]);
        $this->assertEqualsWithDelta(1500.00, $this->lineFor($e, '3900')['credit'], 0.001);
        $this->assertBalances($e);
        $this->assertPassesValidation($e);
    }
}
