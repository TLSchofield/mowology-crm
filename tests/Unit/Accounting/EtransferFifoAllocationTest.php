<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for EtransferInboxService::suggestFifoAllocation() — when a transfer
 * amount exceeds what any single one of the sender's open invoices can
 * absorb, suggest applying it oldest-invoice-first (standard AR practice),
 * topping each one up to its balance before moving to the next.
 */
class EtransferFifoAllocationTest extends TestCase
{
    private function serviceWithInvoices(array $rows): EtransferInboxService
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn($rows);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        return new EtransferInboxService($pdo);
    }

    public function test_spreads_across_invoices_oldest_first(): void
    {
        // Query already orders by date ASC — oldest first, as the DB would return it.
        $svc = $this->serviceWithInvoices([
            ['id' => 10, 'invoice_number' => 'INV-2026-0001', 'balance_due' => 300.00, 'payer_name' => '1355183 B.C. LTD.', 'order_date' => '2026-05-01'],
            ['id' => 11, 'invoice_number' => 'INV-2026-0050', 'balance_due' => 500.00, 'payer_name' => '1355183 B.C. LTD.', 'order_date' => '2026-06-01'],
            ['id' => 12, 'invoice_number' => 'INV-2026-0099', 'balance_due' => 900.00, 'payer_name' => '1355183 B.C. LTD.', 'order_date' => '2026-07-01'],
        ]);

        $spread = $svc->suggestFifoAllocation('1355183 B.C. LTD.', 1050.95);

        $this->assertCount(3, $spread);
        $this->assertSame('INV-2026-0001', $spread[0]['invoice_number']);
        $this->assertSame(300.00, $spread[0]['apply_amount']);
        $this->assertSame('INV-2026-0050', $spread[1]['invoice_number']);
        $this->assertSame(500.00, $spread[1]['apply_amount']);
        $this->assertSame('INV-2026-0099', $spread[2]['invoice_number']);
        $this->assertSame(250.95, $spread[2]['apply_amount']);
    }

    public function test_matches_sender_name_ignoring_punctuation_and_case(): void
    {
        $svc = $this->serviceWithInvoices([
            ['id' => 20, 'invoice_number' => 'INV-2026-0200', 'balance_due' => 50.00, 'payer_name' => '1355183 BC Ltd', 'order_date' => '2026-05-01'],
        ]);

        $spread = $svc->suggestFifoAllocation('1355183 B.C. LTD.', 50.00);

        $this->assertCount(1, $spread);
        $this->assertSame('INV-2026-0200', $spread[0]['invoice_number']);
    }

    public function test_ignores_invoices_belonging_to_a_different_payer(): void
    {
        $svc = $this->serviceWithInvoices([
            ['id' => 30, 'invoice_number' => 'INV-2026-0300', 'balance_due' => 100.00, 'payer_name' => 'Some Other Company', 'order_date' => '2026-05-01'],
        ]);

        $spread = $svc->suggestFifoAllocation('1355183 B.C. LTD.', 100.00);

        $this->assertSame([], $spread);
    }

    public function test_stops_once_amount_is_exhausted_leaving_later_invoices_untouched(): void
    {
        $svc = $this->serviceWithInvoices([
            ['id' => 40, 'invoice_number' => 'INV-2026-0400', 'balance_due' => 100.00, 'payer_name' => 'ACME', 'order_date' => '2026-05-01'],
            ['id' => 41, 'invoice_number' => 'INV-2026-0401', 'balance_due' => 200.00, 'payer_name' => 'ACME', 'order_date' => '2026-06-01'],
        ]);

        $spread = $svc->suggestFifoAllocation('ACME', 100.00);

        $this->assertCount(1, $spread);
        $this->assertSame('INV-2026-0400', $spread[0]['invoice_number']);
        $this->assertSame(100.00, $spread[0]['apply_amount']);
    }

    public function test_no_sender_name_returns_empty(): void
    {
        $svc = $this->serviceWithInvoices([]);
        $this->assertSame([], $svc->suggestFifoAllocation(null, 100.00));
        $this->assertSame([], $svc->suggestFifoAllocation('', 100.00));
    }

    public function test_zero_or_negative_amount_returns_empty(): void
    {
        $svc = $this->serviceWithInvoices([]);
        $this->assertSame([], $svc->suggestFifoAllocation('ACME', 0));
        $this->assertSame([], $svc->suggestFifoAllocation('ACME', -50));
    }
}
