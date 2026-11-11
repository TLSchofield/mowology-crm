<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for InvoiceFromVisitService::computeExtrasAmount — the single
 * source of the timed-extras dollar calc shared by completion + invoicing.
 *
 * Rule: billable time rounds UP to the next 5-minute block.
 *   blocks = ceil(minutes / 5);  amount = blocks * rate.
 */
class InvoiceFromVisitServiceTest extends TestCase
{
    /** Build a service whose ops_settings rate query returns $rate. */
    private function serviceWithRate(string $rate): InvoiceFromVisitService
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetch')->willReturn(['setting_value' => $rate]);

        $db = $this->createMock(PDO::class);
        $db->method('query')->willReturn($stmt);

        return new InvoiceFromVisitService($db);
    }

    /** @test */
    public function zero_minutes_is_zero_dollars(): void
    {
        $svc = $this->serviceWithRate('5.00');
        $r   = $svc->computeExtrasAmount(0);
        $this->assertSame(0, $r['blocks']);
        $this->assertSame(0.0, $r['amount']);
        $this->assertSame(5.0, $r['rate']);
    }

    /** @test */
    public function partial_block_rounds_up(): void
    {
        // 7 minutes → ceil(7/5)=2 blocks → 2 * 5.00 = 10.00
        $svc = $this->serviceWithRate('5.00');
        $r   = $svc->computeExtrasAmount(7);
        $this->assertSame(2, $r['blocks']);
        $this->assertSame(10.0, $r['amount']);
    }

    /** @test */
    public function exact_block_is_not_rounded_extra(): void
    {
        // 15 minutes → ceil(15/5)=3 blocks → 3 * 5.00 = 15.00
        $svc = $this->serviceWithRate('5.00');
        $r   = $svc->computeExtrasAmount(15);
        $this->assertSame(3, $r['blocks']);
        $this->assertSame(15.0, $r['amount']);
    }

    /** @test */
    public function custom_rate_is_applied(): void
    {
        // 11 minutes → ceil(11/5)=3 blocks → 3 * 7.50 = 22.50
        $svc = $this->serviceWithRate('7.50');
        $r   = $svc->computeExtrasAmount(11);
        $this->assertSame(3, $r['blocks']);
        $this->assertSame(22.5, $r['amount']);
        $this->assertSame(7.5, $r['rate']);
    }

    /** @test */
    public function one_minute_is_one_block(): void
    {
        $svc = $this->serviceWithRate('5.00');
        $r   = $svc->computeExtrasAmount(1);
        $this->assertSame(1, $r['blocks']);
        $this->assertSame(5.0, $r['amount']);
    }
}
