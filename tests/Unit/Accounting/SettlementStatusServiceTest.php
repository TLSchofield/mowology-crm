<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Five settlement states for a month's Stripe payouts, read-only, plugin names with our meanings. */
final class SettlementStatusServiceTest extends TestCase
{
    private const TODAY = '2026-11-03';

    private function po(string $id, int $cents, string $arrival, string $status = 'paid'): array
    {
        return ['id' => $id, 'amount' => $cents, 'arrival' => $arrival, 'status' => $status];
    }

    private function line(int $id, string $date, int $cents, ?string $ref = null, bool $booked = false): array
    {
        return ['id' => $id, 'date' => $date, 'cents' => $cents, 'payout_ref' => $ref, 'booked' => $booked || $ref !== null];
    }

    public function testEachStateOnce(): void
    {
        $r = SettlementStatusService::classify([
            $this->po('po_booked', 10000, '2026-10-03'),
            $this->po('po_inbank', 20000, '2026-10-10'),          // same cents line 1 day later, not booked
            $this->po('po_late', 30000, '2026-10-15'),            // same cents line 4 days later
            $this->po('po_missing', 40000, '2026-10-20'),         // no line at all
            $this->po('po_fresh', 50000, '2026-11-02'),           // arrived yesterday: nothing to check yet
            $this->po('po_pending', 60000, '2026-10-25', 'in_transit'),
        ], [
            $this->line(1, '2026-10-03', 10000, 'po_booked'),
            $this->line(2, '2026-10-11', 20000),
            $this->line(3, '2026-10-19', 30000),
            $this->line(4, '2026-10-22', 77700),                  // nobody's payout
            $this->line(5, '2026-10-23', 12345, null, true),      // booked at import to an invoice: not an orphan
            $this->line(6, '2026-11-01', 55500),                  // a margin line: November's payout, not October's orphan
        ], self::TODAY, '2026-10-01', '2026-10-31');
        $by = array_column(array_filter($r['rows'], fn($x) => $x['payout'] !== null), 'status', 'payout');
        $this->assertSame(['po_booked' => 'reconciled', 'po_inbank' => 'unreconciled_in_ledger', 'po_late' => 'date_mismatch',
                           'po_missing' => 'missing_in_ledger', 'po_fresh' => 'in_transit', 'po_pending' => 'in_transit'], $by);
        $this->assertSame(1, $r['counts']['unmatched_deposit']);
        $orphan = array_values(array_filter($r['rows'], fn($x) => $x['status'] === 'unmatched_deposit'))[0];
        $this->assertSame([4, 777.0], [$orphan['line_id'], $orphan['amount']]);
        $this->assertSame(4, array_values(array_filter($r['rows'], fn($x) => $x['payout'] === 'po_late'))[0]['days_off']);
    }

    public function testASingleChargePayoutBookedAtImportIsReconciled(): void
    {
        // import matched the deposit straight to its invoice: type transfer, matched_invoice_id set, no po_ reference
        $r = SettlementStatusService::classify([$this->po('po_one', 4558, '2026-08-25')], [$this->line(36626, '2026-08-26', 4558, null, true)], self::TODAY);
        $this->assertSame('reconciled', $r['rows'][0]['status']);
        $this->assertSame(36626, $r['rows'][0]['line_id']);
        $this->assertSame(0, $r['counts']['unmatched_deposit']);
    }

    public function testAmountsMatchToTheCentAndEachLineIsUsedOnce(): void
    {
        $r = SettlementStatusService::classify([$this->po('a', 10000, '2026-10-03'), $this->po('b', 10000, '2026-10-04')],
                                                [$this->line(1, '2026-10-04', 10000), $this->line(2, '2026-10-04', 10001)], self::TODAY);
        $by = array_column(array_filter($r['rows'], fn($x) => $x['payout'] !== null), 'status', 'payout');
        $this->assertSame('unreconciled_in_ledger', $by['a']);
        $this->assertSame('missing_in_ledger', $by['b']);          // line 1 already taken, line 2 is a cent off
        $this->assertSame(1, $r['counts']['unmatched_deposit']);    // the 100.01 line
    }

    public function testBlockerWordingOnlyWhenSomeoneIsNeeded(): void
    {
        $this->assertSame([], SettlementStatusService::blockers(['reconciled' => 9, 'in_transit' => 1]));
        $b = SettlementStatusService::blockers(['unreconciled_in_ledger' => 1, 'date_mismatch' => 1, 'missing_in_ledger' => 1, 'unmatched_deposit' => 2]);
        $this->assertSame(['2 Stripe payouts in the bank but not booked yet (Penny\'s bank card books them)',
                           '1 Stripe payout not on any bank line — a statement may be missing',
                           '2 Stripe-looking deposits with no payout behind them'], $b);
    }

    public function testReportUsesTheInjectedSourceAndNamesAStripeFailure(): void
    {
        $db = new PDO('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE accounting_transactions (id INTEGER PRIMARY KEY, transaction_date TEXT, amount REAL, type TEXT, payment_reference TEXT, matched_invoice_id INT, reference_type TEXT, description TEXT, status TEXT);
                   INSERT INTO accounting_transactions VALUES (1,'2026-10-04',100,'income',NULL,NULL,'bank_import','STRIPE TRANSFER',NULL)");
        $svc = new SettlementStatusService($db, fn($from, $to) => [$this->po('po_x', 10000, '2026-10-03')]);
        $r = $svc->report('2026-10', true, self::TODAY);     // no ops_settings table: cache silently skipped
        $this->assertNull($r['error']);
        $this->assertSame(1, $r['counts']['unreconciled_in_ledger']);
        $bad = new SettlementStatusService($db, function () { throw new RuntimeException('rate limited'); });
        $r = $bad->report('2026-10', true, self::TODAY);
        $this->assertStringContainsString('rate limited', $r['error']);
        $this->assertSame([], $r['counts']);
    }
}
