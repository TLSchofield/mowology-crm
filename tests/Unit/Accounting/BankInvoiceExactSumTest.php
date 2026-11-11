<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A deposit no single invoice matches: one payer's open invoices that add up to it exactly
 * are pre-ticked on the bank card (Dorset $804.04 = INV-0360 + INV-0419, 2 × $402.02).
 */
class BankInvoiceExactSumTest extends TestCase
{
    private function inv(int $id, string $num, string $payer, float $bal, string $date): array
    {
        return ['invoice_id' => $id, 'invoice_number' => $num, 'payer' => $payer, 'balance_due' => $bal, 'invoice_date' => $date,
                'total' => $bal, 'confidence' => 53, 'reasons' => ['Deposit covers this invoice', 'Memo names ' . $payer]];
    }

    public function test_dorset_two_times_402_02(): void
    {
        $pool = [
            $this->inv(419, 'INV-2026-0419', 'Dorset Strata', 402.02, '2026-08-01'),
            $this->inv(360, 'INV-2026-0360', 'Dorset Strata', 402.02, '2026-07-01'),
            $this->inv(377, 'INV-2026-0377', 'Dorset Strata', 150.00, '2026-07-15'),
            $this->inv(381, 'INV-2026-0381', 'Dorset Strata', 252.02, '2026-07-20'),   // 402.02 + 150 + 252.02 also = 804.04
        ];
        $r = BankInvoiceMatchService::exactSpread($pool, 804.04);
        $this->assertSame(['INV-2026-0360', 'INV-2026-0419'], array_column($r['lines'], 'invoice_number'), 'same-amount multiple first, oldest first');
        $this->assertSame([402.02, 402.02], array_column($r['lines'], 'amount'));
        $this->assertSame(402.02, $r['each']);
        $this->assertSame('Dorset Strata', $r['payer']);
        $say = BankInvoiceMatchService::say(804.04, null, [], [], $pool, $r['lines'], $r);
        $this->assertSame("This \$804.04 adds up exactly to 2 × \$402.02 from Dorset Strata: INV-2026-0360 and INV-2026-0419. I've ticked them — check and record.", $say);
    }

    public function test_fewest_invoices_oldest_first_when_amounts_differ(): void
    {
        $pool = [
            $this->inv(3, 'C', 'Acme', 300.00, '2026-03-01'),
            $this->inv(1, 'A', 'Acme', 100.00, '2026-01-01'),
            $this->inv(2, 'B', 'Acme', 200.00, '2026-02-01'),
            $this->inv(4, 'D', 'Acme', 50.00, '2026-04-01'),
            $this->inv(5, 'E', 'Acme', 250.00, '2026-05-01'),
        ];
        // $350 = A+E or C+D: pairs are tried oldest first (A, B, C, ...), so A+E.
        $r = BankInvoiceMatchService::exactSpread($pool, 350.00);
        $this->assertSame(['A', 'E'], array_column($r['lines'], 'invoice_number'), 'two invoices, the oldest combination');
        $this->assertNull($r['each']);
    }

    public function test_bounds_at_most_four_in_a_set_and_twelve_looked_at(): void
    {
        $five = [];
        for ($i = 1; $i <= 5; $i++) $five[] = $this->inv($i, 'I' . $i, 'Acme', 10.00 + $i, '2026-01-0' . $i);
        $this->assertNull(BankInvoiceMatchService::exactSpread($five, 11 + 12 + 13 + 14 + 15), 'five invoices would be needed');

        $this->assertSame([0, 1], InvoiceReconciliationService::exactSubsetBounded([5, 5, 5, 5, 5], 10.0));
        $this->assertNull(InvoiceReconciliationService::exactSubsetBounded([5, 5, 5, 5, 5], 25.0), 'five × $5 is over the limit');
        $pool = array_fill(0, 12, 1.0);
        $pool[] = 7.77;   // the 13th invoice is never looked at
        $this->assertNull(InvoiceReconciliationService::exactSubsetBounded($pool, 8.77));
        $this->assertSame([0, 1, 2], InvoiceReconciliationService::exactSubsetBounded([1.10, 2.20, 3.30, 9.99], 6.60));
    }

    public function test_two_payers_adding_up_is_a_guess(): void
    {
        $pool = [
            $this->inv(1, 'A1', 'Acme', 100.00, '2026-01-01'),
            $this->inv(2, 'A2', 'Acme', 100.00, '2026-02-01'),
            $this->inv(3, 'B1', 'Birch', 100.00, '2026-01-01'),
            $this->inv(4, 'B2', 'Birch', 100.00, '2026-02-01'),
        ];
        $this->assertNull(BankInvoiceMatchService::exactSpread($pool, 200.00));
    }

    public function test_a_single_exact_invoice_wins_over_a_set(): void
    {
        $pool = [$this->inv(1, 'A1', 'Acme', 200.00, '2026-01-01'), $this->inv(2, 'A2', 'Acme', 100.00, '2026-02-01')];
        $this->assertTrue(BankInvoiceMatchService::hasExactSingle($pool, 200.00));
        $this->assertNull(BankInvoiceMatchService::exactSpread($pool, 200.00), 'an invoice equal to the deposit is never part of a set');
    }
}
