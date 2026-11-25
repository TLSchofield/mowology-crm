<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Lines added again by an overlapping statement import are found; real repeats aren't.
 */
class BankDuplicateCleanupTest extends TestCase
{
    private function r(int $id, int $s, string $desc = 'Preauthorized payment TD ON-LINE LOANS SYSTEM', float $amt = 363.08, bool $linked = false): array
    {
        return ['id' => $id, 'transaction_date' => '2026-08-21', 'amount' => $amt, 'description' => $desc, 'import_session_id' => $s, 'linked' => $linked];
    }

    public function test_four_imports_of_one_payment_keep_one(): void
    {
        $p = BankDuplicateCleanup::findExtras([$this->r(10, 37), $this->r(20, 38), $this->r(30, 39), $this->r(40, 40)]);
        $this->assertSame(3, $p['extras']);
        $this->assertSame(10, $p['groups'][0]['keep'][0]['id']);
    }

    public function test_two_real_coffees_in_one_import_stay_two(): void
    {
        $coffee = fn($id, $s) => $this->r($id, $s, 'Point of sale TIM HORTONS', 2.45);
        $p = BankDuplicateCleanup::findExtras([$coffee(1, 37), $coffee(2, 37), $coffee(3, 38), $coffee(4, 38)]);
        $this->assertSame(2, $p['extras'], 'import 37 had two, so two are kept');
        $this->assertSame([], BankDuplicateCleanup::findExtras([$coffee(1, 37), $coffee(2, 37)])['groups'], 'same import only: real');
    }

    public function test_the_linked_copy_is_kept(): void
    {
        $p = BankDuplicateCleanup::findExtras([$this->r(10, 37), $this->r(20, 38, linked: true)]);
        $this->assertSame(20, $p['groups'][0]['keep'][0]['id']);
        $p = BankDuplicateCleanup::findExtras([$this->r(10, 37, linked: true), $this->r(20, 38, linked: true)]);
        $this->assertCount(1, $p['skipped'], 'two linked copies: a human decides');
    }
}
