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
}
