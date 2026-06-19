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
}
