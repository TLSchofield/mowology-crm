<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A supplier price-list line as Sam's material (quote editor "Add from supplier lists", 2026-10-19):
 * its price per yard is the material, its supplier's pin is where the haul goes. Fake supplier.
 */
class MulchPricingListMaterialTest extends TestCase
{
    public function testAListLineBecomesTheMaterialAndTheHaulEnd(): void
    {
        $r = MulchPricingService::listMaterialFrom(['id' => 77, 'name' => 'Garden Soil', 'price' => '38.95', 'list_date' => '2026-07-01',
                                                     'supplier_account_id' => 7, 'supplier' => 'Fake Garden Supply', 'lat' => '49.19', 'lng' => '-122.99']);
        $this->assertSame(38.95, $r['material']['per_yard']);
        $this->assertTrue($r['material']['real']);
        $this->assertSame('Fake Garden Supply list (Jul 1, 2026)', $r['material']['source'], 'shown as the material basis on the hint');
        $this->assertSame(77, $r['material']['list_item_id']);
        $this->assertSame(['id' => 0, 'name' => 'Fake Garden Supply', 'lat' => 49.19, 'lng' => -122.99, 'basis' => 'the supplier list Sam picked'], $r['supplier']);
    }

    public function testThePstWePayIsAddedToTheListPrice(): void
    {
        $r = MulchPricingService::listMaterialFrom(['id' => 1, 'price' => '38.95', 'list_date' => '2026-07-01', 'supplier' => 'Fake Garden Supply', 'lat' => 1, 'lng' => 1], 7.0);
        $this->assertSame(41.68, $r['material']['per_yard'], '38.95 × 1.07');
        $this->assertSame('Fake Garden Supply list (Jul 1, 2026) + 7% PST', $r['material']['source']);
    }

    public function testNoPriceNoMaterialAndNoPinNoHaulEnd(): void
    {
        $this->assertNull(MulchPricingService::listMaterialFrom(['id' => 1, 'price' => null, 'list_date' => '2026-07-01', 'supplier' => 'X', 'lat' => 1, 'lng' => 1]));
        $r = MulchPricingService::listMaterialFrom(['id' => 2, 'price' => '40', 'list_date' => '2026-07-01', 'supplier' => 'X', 'lat' => null, 'lng' => null]);
        $this->assertNull($r['supplier'], 'no pin: the haul falls back like any priced line');
    }
}
