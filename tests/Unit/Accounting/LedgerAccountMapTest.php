<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Which account income and spending post to, and moving what's already posted.
 */
class LedgerAccountMapTest extends TestCase
{
    public function test_slugs_and_labels_meet(): void
    {
        $this->assertSame('lawn care', LedgerAccountMap::serviceKey('lawn_care'));
        $this->assertSame('lawn care', LedgerAccountMap::serviceKey(' Lawn  Care '));
        $this->assertSame('4100', LedgerAccountMap::codeFor('Lawn Care', ['lawn care' => '4100']));
        $this->assertSame('4900', LedgerAccountMap::codeFor('Maintenance', ['lawn care' => '4100']), 'no account yet');
    }

    public function test_a_mixed_invoice_splits_and_sums_exactly(): void
    {
        $s = LedgerAccountMap::split([['amount' => 100, 'code' => '4100'], ['amount' => 50, 'code' => '4200'], ['amount' => 50.01, 'code' => '4100']], 199.99);
        $this->assertSame(['4100', '4200'], array_column($s, 0));
        $this->assertEqualsWithDelta(199.99, array_sum(array_column($s, 1)), 0.0001);
        $this->assertSame([['4700', 80.0]], LedgerAccountMap::split([['amount' => 0, 'code' => '4700']], 80));
    }

    public function test_invoice_entry_with_splits_balances(): void
    {
        $e = (new LedgerService($this->createMock(PDO::class)))->buildInvoiceEntry([
            'id' => 9, 'date' => '2026-09-01', 'net' => 150.0, 'gst' => 7.5, 'revenue_splits' => [['4100', 100.0], ['4200', 50.0]],
        ]);
        $this->assertEqualsWithDelta(array_sum(array_column($e['lines'], 'debit')), array_sum(array_column($e['lines'], 'credit')), 0.001);
        $this->assertSame(['1100', '4100', '4200', '2200'], array_column($e['lines'], 'account'));
    }

    public function test_repost_only_when_the_account_or_amount_differs(): void
    {
        $this->assertTrue(LedgerRepostService::same(['4100' => 100.0], ['4100' => 100.004]));
        $this->assertFalse(LedgerRepostService::same(['4900' => 100.0], ['4100' => 100.0]));
        $this->assertFalse(LedgerRepostService::same(['4100' => 100.0], ['4100' => 60.0, '4200' => 40.0]));
        $by = LedgerRepostService::byAccount([['from' => ['4900' => 100.0], 'to' => ['4100' => 100.0]]], ['4900' => 'Other Services', '4100' => 'Lawn Care']);
        $this->assertSame(-100.0, $by['4900']['net']);
        $this->assertSame(100.0, $by['4100']['net']);
    }
}
