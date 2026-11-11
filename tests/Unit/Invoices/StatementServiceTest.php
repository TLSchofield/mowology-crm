<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for StatementService::buildLedger — the A/R statement maths
 * (opening balance, period filtering, running balance, closing balance).
 */
class StatementServiceTest extends TestCase
{
    private function service(): StatementService
    {
        return new StatementService($this->createMock(PDO::class));
    }

    private function events(): array
    {
        return [
            ['date' => '2025-01-10', 'type' => 'charge',  'desc' => 'Invoice A', 'amount' => 100.00, 'invoice_id' => 1],
            ['date' => '2025-01-20', 'type' => 'payment', 'desc' => 'Payment A', 'amount' => 100.00, 'invoice_id' => 1],
            ['date' => '2025-02-05', 'type' => 'charge',  'desc' => 'Invoice B', 'amount' => 50.00,  'invoice_id' => 2],
            ['date' => '2025-02-15', 'type' => 'payment', 'desc' => 'Payment B', 'amount' => 20.00,  'invoice_id' => 2],
            ['date' => '2025-03-01', 'type' => 'charge',  'desc' => 'Invoice C', 'amount' => 75.00,  'invoice_id' => 3],
        ];
    }

    /** @test */
    public function period_statement_folds_prior_activity_into_opening_balance(): void
    {
        $l = $this->service()->buildLedger($this->events(), '2025-02-01', '2025-02-28');

        // Jan activity nets to zero → opening 0
        $this->assertEqualsWithDelta(0.00, $l['opening'], 0.001);
        // Only the two February events appear
        $this->assertCount(2, $l['rows']);
        $this->assertEqualsWithDelta(50.00, $l['total_charged'], 0.001);
        $this->assertEqualsWithDelta(20.00, $l['total_paid'], 0.001);
        // closing = opening + charged − paid
        $this->assertEqualsWithDelta(30.00, $l['closing'], 0.001);
        // running balance on the last row
        $this->assertEqualsWithDelta(30.00, $l['rows'][1]['balance'], 0.001);
    }

    /** @test */
    public function opening_balance_carries_a_prior_unpaid_charge(): void
    {
        $events = [
            ['date' => '2024-12-01', 'type' => 'charge', 'desc' => 'Old invoice', 'amount' => 200.00, 'invoice_id' => 9],
            ['date' => '2025-02-10', 'type' => 'charge', 'desc' => 'Invoice B',   'amount' => 50.00,  'invoice_id' => 2],
        ];
        $l = $this->service()->buildLedger($events, '2025-02-01', '2025-02-28');
        $this->assertEqualsWithDelta(200.00, $l['opening'], 0.001);   // prior unpaid charge
        $this->assertEqualsWithDelta(250.00, $l['rows'][0]['balance'], 0.001); // opening + 50
        $this->assertEqualsWithDelta(250.00, $l['closing'], 0.001);
    }

    /** @test */
    public function all_outstanding_shows_full_history_and_total_owing(): void
    {
        $l = $this->service()->buildLedger($this->events(), null, null, true);

        $this->assertCount(5, $l['rows']);
        $this->assertEqualsWithDelta(0.00, $l['opening'], 0.001);
        $this->assertEqualsWithDelta(225.00, $l['total_charged'], 0.001); // 100+50+75
        $this->assertEqualsWithDelta(120.00, $l['total_paid'], 0.001);    // 100+20
        $this->assertEqualsWithDelta(105.00, $l['closing'], 0.001);       // amount still owing
    }

    /** @test */
    public function charges_sort_before_payments_on_the_same_day(): void
    {
        $events = [
            ['date' => '2025-02-10', 'type' => 'payment', 'desc' => 'Pay', 'amount' => 40.00, 'invoice_id' => 1],
            ['date' => '2025-02-10', 'type' => 'charge',  'desc' => 'Chg', 'amount' => 40.00, 'invoice_id' => 1],
        ];
        $l = $this->service()->buildLedger($events, '2025-02-01', '2025-02-28');
        $this->assertSame('charge',  $l['rows'][0]['type']); // charge first
        $this->assertSame('payment', $l['rows'][1]['type']);
        $this->assertEqualsWithDelta(0.00, $l['closing'], 0.001);
    }
}
