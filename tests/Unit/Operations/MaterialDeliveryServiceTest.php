<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Lawnboy delivery vs trailer runs (owner, 2026-10-10). Numbers from the books: the trailer
 * carries 2 yd; Lawnboy dropped 6 yd for $73; clients pay $150 a delivery; 3 days' notice.
 */
class MaterialDeliveryServiceTest extends TestCase
{
    /** Monica's QUO-2026-0073 as approved (sprinkler declined). */
    private function monica(): array
    {
        return [
            ['service_type' => 'Black Composted Bark Mulch', 'quantity' => '4.00', 'unit_type' => ''],
            ['service_type' => 'Bulbs', 'quantity' => '1.00', 'unit_type' => 'each', 'description' => 'Tulips Daffodils'],
            ['service_type' => 'Flowerbed Soil', 'quantity' => '1.00', 'unit_type' => 'each'],
            ['service_type' => 'Extend Sprinkler and convert bed sprinklers to drip lines', 'quantity' => '1', 'client_declined' => 1],
        ];
    }

    public function test_finds_the_bulk_material_and_its_yards(): void
    {
        $b = MaterialDeliveryService::bulkLines($this->monica());
        $this->assertSame(['Black Composted Bark Mulch', 'Flowerbed Soil'], array_column($b, 'label'));
        $this->assertSame(5.0, MaterialDeliveryService::totalYards($b));
        $this->assertSame('about 4 yd Black Composted Bark Mulch, about 1 yd Flowerbed Soil', MaterialDeliveryService::describe($b));
    }

    public function test_more_than_one_trailer_load_is_delivered(): void
    {
        $d = MaterialDeliveryService::decide(5, 2, null, 73);
        $this->assertSame('delivery', $d['method']);
        $this->assertSame(3, $d['loads']);
        $this->assertStringContainsString('One drop ($73) instead of 3 trailer runs', $d['why']);
    }

    public function test_one_load_goes_by_trailer_unless_the_run_costs_more(): void
    {
        $this->assertSame('trailer', MaterialDeliveryService::decide(2, 2, 45, 73)['method']);
        $this->assertSame('trailer', MaterialDeliveryService::decide(1.5, 2, null, 73)['method']);
        $this->assertSame('delivery', MaterialDeliveryService::decide(2, 2, 90, 73)['method']);
    }

    public function test_bags_and_square_feet_are_not_bulk_yards(): void
    {
        $this->assertSame([], MaterialDeliveryService::bulkLines([['service_type' => 'Mulch', 'quantity' => 10, 'unit_type' => 'bag']]));
        $this->assertSame([], MaterialDeliveryService::bulkLines([['service_type' => 'Mulch delivery', 'quantity' => 1]]));
        $this->assertSame([['label' => 'Gravel', 'yards' => 3.0, 'approx' => false]],
            MaterialDeliveryService::bulkLines([['service_type' => 'Gravel', 'quantity' => 3, 'unit_type' => 'yd']]));
    }

    public function test_order_three_days_ahead(): void
    {
        $this->assertSame('2026-10-14', MaterialDeliveryService::orderBy('2026-10-17', 3));
    }

    public function test_sam_suggests_delivery_at_our_price(): void
    {
        $d = MaterialDeliveryService::decide(5, 2, null, 73) + ['what' => '5 yd mulch', 'vendor' => 'Lawnboy'];
        $s = TripLineSuggester::deliverySuggestion($d, 150);
        $this->assertSame('Delivery', $s['label']);
        $this->assertSame(150.0, $s['price']);
        $this->assertTrue($s['ready']);
        $this->assertStringContainsString('Lawnboy charges us $73; we charge $150', $s['basis']);
    }
}
