<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class InvoiceLineItemsTest extends TestCase
{
    public function test_rows_are_cleaned_and_totalled(): void
    {
        $out = InvoiceLineItems::fromPost([
            'li_title'       => ['Clean Up', '', ''],
            'li_description' => ['Full Day - 7 hrs', 'Dump fee', ''],
            'li_quantity'    => ['2', '0', '0'],
            'li_unit_price'  => ['495', '40.505', '0'],
            'li_visit_id'    => ['12', '0', '0'],
            'li_service_date'=> ['2026-10-02', 'not-a-date', ''],
        ]);

        $this->assertCount(2, $out['items']);   // the empty third row is dropped
        $this->assertSame(990.0, $out['items'][0]['line_total']);
        $this->assertSame('Clean Up', $out['items'][0]['title']);
        $this->assertSame(12, $out['items'][0]['visit_id']);
        $this->assertSame('2026-10-02', $out['items'][0]['service_date']);
        // Zero quantity counts as one; bad dates are dropped.
        $this->assertSame(1.0, $out['items'][1]['quantity']);
        $this->assertNull($out['items'][1]['service_date']);
        $this->assertNull($out['items'][1]['title']);
        $this->assertSame(1030.51, $out['subtotal']);
    }

    public function test_a_titled_row_without_description_uses_the_title(): void
    {
        $out = InvoiceLineItems::fromPost(['li_title' => ['Lawn Cut'], 'li_unit_price' => ['55']]);
        $this->assertSame('Lawn Cut', $out['items'][0]['description']);
    }

    public function test_posted_detects_the_line_item_form(): void
    {
        $this->assertTrue(InvoiceLineItems::posted(['li_description' => []]));
        $this->assertFalse(InvoiceLineItems::posted(['subtotal' => '10']));
    }
}
