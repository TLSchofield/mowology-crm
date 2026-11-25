<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Month-close proof: a statement is closed when it adds up, its lines are all in the
 * books, and it follows on from the previous statement.
 */
class StatementCloseServiceTest extends TestCase
{
    private function st(int $id, string $from, string $to, ?float $open, ?float $close, float $net, int $missing = 0): array
    {
        return ['id' => $id, 'date_from' => $from, 'date_to' => $to, 'balance_opening' => $open, 'balance_closing' => $close,
                'net' => $net, 'missing' => $missing, 'filename' => "s{$id}.pdf"];
    }

    public function test_statements_that_add_up_and_chain_are_closed(): void
    {
        $r = StatementCloseService::check([
            $this->st(1, '2026-07-01', '2026-07-31', 1000, 1200, 200),
            $this->st(2, '2026-08-01', '2026-08-31', 1200, 900, -300),
        ]);
        $this->assertSame(2, $r['closed']);
        $this->assertSame([], $r['gaps']);
    }

    public function test_breaks_are_named(): void
    {
        $r = StatementCloseService::check([
            $this->st(1, '2026-06-01', '2026-06-30', 1000, 1200, 200),
            $this->st(2, '2026-08-01', '2026-08-31', 1150, 900, -300, 2),
        ]);
        $this->assertSame(1, $r['open']);
        $p = $r['statements'][1]['problems'];
        $this->assertStringContainsString('lines add up to 850.00, not the closing 900.00', $p[0]);
        $this->assertStringContainsString('2 lines no longer in the books', $p[1]);
        $this->assertStringContainsString('opens at 1,150.00 but the last statement closed at 1,200.00', $p[2]);
        $this->assertSame(['2026-07'], $r['gaps']);
    }

    public function test_no_saved_balance_is_unproven_not_broken(): void
    {
        $r = StatementCloseService::check([$this->st(1, '2025-12-01', '2025-12-31', null, null, -50)]);
        $this->assertSame(0, $r['open']);
        $this->assertSame(1, $r['unproven']);
    }

    public function test_a_reimport_proven_at_import_adds_up_and_repeats_are_one_statement(): void
    {
        // 2026-10-13 re-imports of January: the importer checked the WHOLE statement (opening + every
        // line = closing, $0.00 out) but stored only the 10 lines that were new; another re-import
        // stored none. Both read "doesn't add up" and the second "opens at 2,679.92 after 9,766.72".
        $jan64 = $this->st(64, '2026-01-02', '2026-01-31', 2679.92, 9766.72, -1240.89) + ['reconciled' => 1, 'balance_discrepancy' => 0.00, 'duplicate_count' => 73];
        $jan78 = $this->st(78, '2026-01-01', '2026-01-31', 2679.92, 9766.72, 0.0) + ['reconciled' => 1, 'balance_discrepancy' => 0.00, 'duplicate_count' => 83];
        $feb77 = $this->st(77, '2026-02-01', '2026-02-28', 9766.72, 21919.50, 0.0) + ['reconciled' => 1, 'balance_discrepancy' => 0.00, 'duplicate_count' => 92];
        $r = StatementCloseService::check([$jan64, $jan78, $feb77]);
        foreach ($r['statements'] as $st) $this->assertSame([], $st['problems'], 'session ' . $st['id']);
        $this->assertSame(3, $r['closed']);

        // A re-import the importer could NOT reconcile is still checked from its lines.
        $bad = $this->st(90, '2026-03-01', '2026-03-31', 21919.50, 9000.00, 0.0) + ['reconciled' => 0, 'balance_discrepancy' => 12.5, 'duplicate_count' => 40];
        $this->assertNotSame([], StatementCloseService::check([$bad])['statements'][0]['problems']);
    }

    public function test_only_clean_past_months_can_be_locked(): void
    {
        $accounts = [StatementCloseService::check([
            $this->st(1, '2026-07-01', '2026-07-31', 1000, 1200, 200),
            $this->st(2, '2026-08-01', '2026-08-31', 1200, 900, -300, 3),
            $this->st(3, '2026-09-01', '2026-09-30', null, null, 10),
            $this->st(4, '2026-10-01', '2026-10-31', null, null, 10),
        ])];
        $this->assertSame(['2026-09'], StatementCloseService::lockable($accounts, ['2026-07'], '2026-10-05'),
            'July already locked, August has missing lines, October is the current month');
    }
}
