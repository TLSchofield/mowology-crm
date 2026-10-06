<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Penny splits a Stripe payout into its invoices and fee — only when it adds up exactly,
 * and without ever writing to Stripe.
 */
class StripePayoutServiceTest extends TestCase
{
    private function inv(): array
    {
        return [
            'ch_1' => ['invoice_id' => 421, 'number' => 'INV-2026-0421', 'counted' => true],
            'ch_2' => ['invoice_id' => 422, 'number' => 'INV-2026-0422', 'counted' => true],
        ];
    }

    private function tx(): array
    {
        return [
            ['type' => 'payout', 'amount' => -83987, 'fee' => 0, 'net' => -83987, 'source' => 'po_1'],
            ['type' => 'charge', 'amount' => 42525, 'fee' => 1263, 'net' => 41262, 'source' => 'ch_1'],
            ['type' => 'charge', 'amount' => 44032, 'fee' => 1307, 'net' => 42725, 'source' => 'ch_2'],
        ];
    }

    public function test_a_payout_that_adds_up_splits_into_its_invoices_and_fee(): void
    {
        $r = StripePayoutService::check(83987, $this->tx(), $this->inv());
        $this->assertTrue($r['ok']);
        $this->assertSame(['INV-2026-0421', 'INV-2026-0422'], array_column($r['invoices'], 'number'));
        $this->assertSame(25.70, $r['fees']);
        $this->assertSame(865.57, $r['gross']);
    }

    public function test_a_cent_off_is_refused(): void
    {
        $r = StripePayoutService::check(83988, $this->tx(), $this->inv());
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('bank shows', $r['reason']);
    }

    public function test_a_refund_inside_the_payout_is_left_for_the_owner(): void
    {
        $tx = $this->tx();
        $tx[] = ['type' => 'refund', 'amount' => -1000, 'fee' => 0, 'net' => -1000, 'source' => 're_1'];
        $r = StripePayoutService::check(82987, $tx, $this->inv());
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('refund', $r['reason']);
    }

    public function test_a_payment_not_on_any_invoice_is_refused(): void
    {
        $inv = $this->inv();
        unset($inv['ch_2']);
        $this->assertFalse(StripePayoutService::check(83987, $this->tx(), $inv)['ok']);
    }

    public function test_an_invoice_not_yet_counted_as_income_is_refused(): void
    {
        $inv = $this->inv();
        $inv['ch_2']['counted'] = false;
        $r = StripePayoutService::check(83987, $this->tx(), $inv);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('INV-2026-0422', $r['reason']);
    }

    public function test_it_never_writes_to_stripe(): void
    {
        $src = file_get_contents(__DIR__ . '/../../../app/Modules/Accounting/Services/StripePayoutService.php');
        preg_match_all('/stripe\(\)->(\w+)->(\w+)\(/', $src, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m);
        foreach ($m as $call) {
            $this->assertContains($call[2], ['all', 'retrieve'], 'Stripe write call found: ' . $call[0]);
        }
        $this->assertDoesNotMatchRegularExpression('/->(create|update|cancel|reverse|refunds|del|capture|confirm)\(/', $src);
    }
}
