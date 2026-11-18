<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WebServiceCatalogTest extends TestCase
{
    public function testPriceLabelShowsWholeDollarsWithUnit(): void
    {
        $this->assertSame('From $65 per visit', WebServiceCatalog::priceLabel(
            ['show_web_price' => true, 'base_price' => 65.0, 'unit_type' => 'visit']));
        $this->assertSame('From $450 per month', WebServiceCatalog::priceLabel(
            ['show_web_price' => true, 'base_price' => 450.0, 'unit_type' => 'month']));
    }

    public function testPriceLabelKeepsCentsWhenPresent(): void
    {
        $this->assertSame('From $65.50 per visit', WebServiceCatalog::priceLabel(
            ['show_web_price' => true, 'base_price' => 65.5, 'unit_type' => 'visit']));
    }

    public function testPriceLabelIsEmptyWhenHiddenOrZero(): void
    {
        $this->assertSame('', WebServiceCatalog::priceLabel(['show_web_price' => false, 'base_price' => 65, 'unit_type' => 'visit']));
        $this->assertSame('', WebServiceCatalog::priceLabel(['show_web_price' => true, 'base_price' => 0, 'unit_type' => 'visit']));
    }

    public function testCleanBulletsTrimsDropsBlanksAndCaps(): void
    {
        $this->assertSame("One\nTwo", WebServiceCatalog::cleanBullets("  One \r\n\r\n Two  \n"));
        $many = implode("\n", array_map(static fn($i) => "item $i", range(1, 20)));
        $this->assertSame(12, substr_count(WebServiceCatalog::cleanBullets($many), "\n") + 1);
    }
}
