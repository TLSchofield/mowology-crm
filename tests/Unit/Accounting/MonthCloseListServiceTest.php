<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Penny's monthly close list: once a month, after the grace, never a lock (2026-10-14). */
class MonthCloseListServiceTest extends TestCase
{
    public function test_due_after_the_grace_once_a_month(): void
    {
        $this->assertFalse(MonthCloseListService::isDue('2026-09', '2026-10-14', null), 'September waits until Oct 15');
        $this->assertTrue(MonthCloseListService::isDue('2026-09', '2026-10-15', null));
        $this->assertFalse(MonthCloseListService::isDue('2026-01', '2026-10-20', '2026-10 sent for 2026-01'), 'one list a month');
        $this->assertTrue(MonthCloseListService::isDue('2026-01', '2026-11-16', '2026-10 sent for 2026-01'));
    }

    public function test_the_list_names_each_thing_and_links_to_where_it_is_settled(): void
    {
        $items = [
            'month' => ['month' => '2026-01', 'blockers' => ['1010 Chequing Account: books are $7.01 above the bank', '1 open receipt ($30.00)', '2 bank lines still to sort']],
            'receipts' => [['id' => 33, 'expense_date' => '2026-01-09', 'total' => 30.0, 'vendor' => 'Safeway', 'linked' => 0]],
            'lines' => [
                ['date' => '2026-01-05', 'description' => 'HOME DEPOT #7054', 'amount' => -84.5, 'suggestion' => ['code' => '5200', 'name' => 'Materials'], 'client_payment' => false],
                ['date' => '2026-01-20', 'description' => 'E-TRANSFER CREDIT', 'amount' => 65.0, 'suggestion' => null, 'client_payment' => true],
            ],
        ];
        $m = MonthCloseListService::email($items, 'Tim', 'https://mowology.ca/');
        $this->assertSame('January 2026 close: 3 things left', $m['subject']);
        $this->assertStringContainsString('1010 Chequing Account: books are $7.01 above the bank', $m['body']);
        $this->assertStringContainsString('https://mowology.ca/crm/expenses_appstack.php?edit=33', $m['body']);
        $this->assertStringContainsString('Paid personally (owner)', $m['body']);
        $this->assertStringContainsString('my guess: 5200 Materials', $m['body']);
        $this->assertStringContainsString('a client payment — which invoice?', $m['body']);
        $this->assertStringNotContainsString('lock_month', $m['body'], 'nothing in the email changes the books');
    }

    public function test_an_exact_month_asks_for_the_lock_press(): void
    {
        $m = MonthCloseListService::email(['month' => ['month' => '2026-02', 'blockers' => []], 'receipts' => [], 'lines' => []], 'Tim', 'https://mowology.ca');
        $this->assertSame('February 2026 is exact — ready to lock', $m['subject']);
        $this->assertStringContainsString('Lock February 2026', $m['body']);
    }
}
