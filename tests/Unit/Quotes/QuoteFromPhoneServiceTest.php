<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class QuoteFromPhoneServiceTest extends TestCase
{
    public function test_totals_are_worked_out_here_not_taken_from_the_phone(): void
    {
        $lines = QuoteFromPhoneService::buildLines([
            ['description' => ' Fall cleanup ', 'quantity' => 2, 'unit_price' => 40.125, 'line_total' => 1, 'product_id' => 7],
        ], 5.0);
        $this->assertSame('Fall cleanup', $lines[0]['description']);
        $this->assertSame(80.25, $lines[0]['line_total']);
        $this->assertSame(5.0, $lines[0]['tax_rate']);
        $this->assertSame(7, $lines[0]['product_id']);
        $this->assertSame('each', $lines[0]['unit_type']);
    }

    public function test_a_phone_quote_uses_the_business_rate_and_leaves_the_recipient_to_the_resolver(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE properties (id INTEGER PRIMARY KEY)');
        $db->exec('CREATE TABLE quotes (id INTEGER PRIMARY KEY, quote_number TEXT)');
        $db->exec("INSERT INTO properties VALUES (9)");
        $db->exec("INSERT INTO quotes VALUES (1, 'QUO-2026-0001')");
        $svc = new class($db) extends QuoteFromPhoneService {
            public $data;
            protected function createQuote(array $data, array $lines, int $userId): int { $this->data = $data; return 1; }
        };
        $r = $svc->create(['property_id' => 9, 'contact_id' => 44, 'title' => 'Hedge', 'line_items' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 10]]], 2);
        $this->assertSame('QUO-2026-0001', $r['quote_number']);
        $this->assertArrayNotHasKey('contact_id', $svc->data);
        $this->assertSame(TaxEngine::gstRate($db), $svc->data['tax_fraction']);
    }

    public function test_bad_lines_say_why(): void
    {
        $this->assertSame('A quote needs at least one line.', QuoteFromPhoneService::buildLines([], 5.0));
        $this->assertSame('Every line needs a description.', QuoteFromPhoneService::buildLines([['description' => ' ', 'quantity' => 1, 'unit_price' => 1]], 5.0));
        $this->assertSame('Every line needs a quantity above zero.', QuoteFromPhoneService::buildLines([['description' => 'x', 'quantity' => 0, 'unit_price' => 1]], 5.0));
        $this->assertSame("A line can't have a negative price.", QuoteFromPhoneService::buildLines([['description' => 'x', 'quantity' => 1, 'unit_price' => -1]], 5.0));
    }

    public function test_invoice_list_is_only_visits_really_waiting_newest_first(): void
    {
        $base = ['is_invoiced' => 0, 'invoice_id' => null, 'invoice_status' => null, 'line_linked' => false, 'plan_invoiced' => false,
                 'is_recurring' => 1, 'actual_amount' => 80, 'contract_id' => null, 'billing_cycle' => null, 'contract_status' => null,
                 'contract_invoice_dates' => [], 'pricing_model' => 'per_visit', 'plan_title' => 'Lawn', 'plan_id' => 3];
        $rows = InvoiceFromPhoneService::waiting([
            $base + ['id' => 1, 'scheduled_date' => '2026-10-01'],
            $base + ['id' => 2, 'scheduled_date' => '2026-11-01'],
            ['actual_amount' => 0] + $base + ['id' => 3, 'scheduled_date' => '2026-11-02'],     // no charge
            ['invoice_id' => 9, 'invoice_status' => 'sent'] + $base + ['id' => 4, 'scheduled_date' => '2026-11-03'],  // already billed
        ], '2026-11-08');
        $this->assertSame([2, 1], array_column($rows, 'visit_id'));
        $this->assertSame(80.0, $rows[0]['amount']);
    }
}
