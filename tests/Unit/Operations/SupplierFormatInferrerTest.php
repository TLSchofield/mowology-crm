<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SupplierFormatInferrer — reads an unknown supplier's sheet without a hand-written format row
 * (Tim, 2026-10-20). Fake plants and prices only: real trade lists stay out of git.
 */
final class SupplierFormatInferrerTest extends TestCase
{
    private function sheet(array $rows): array
    {
        $cells = [];
        foreach ($rows as $i => $row) {
            foreach ($row as $c => $v) $cells[$i + 1][chr(65 + $c)] = $v;
        }
        return $cells;
    }

    public function testNurseryShapedListWithTitleRowsAndMonthSpecial(): void
    {
        $cells = $this->sheet([
            ['Acme Nursery wholesale availability'],
            ['Item prices valid to Oct 30'],
            [],
            ['Code', 'Plant', 'Size', 'Zone', 'Category', 'Trade Price', 'October', 'Available'],
            ['1001', "Acer palmatum 'Test Red'", 'No.5 Pot', 5, 'Trees', 69.95, null, 49],
            ['1002', "Hosta x 'Fake Blue'", 'No.1 Pot', 3, 'Perennials', '$6.45', 4.95, 300],
            ['1003', "Carex 'Made Up'", 'No.2 Pot', 5, 'Grasses', 10.25, null, 12],
        ]);
        $r = SupplierFormatInferrer::infer($cells, 'availability-2026-10-12.xlsx');
        $this->assertTrue($r['ok'], $r['reason']);
        $this->assertSame(4, $r['header_row']);
        $this->assertSame(['product_code', 'name', 'size', 'zone', 'category', 'price', 'available'], array_keys($r['mapped']));
        $this->assertSame(3, $r['rows']);
        $this->assertContains('@month', $r['spec']['columns']['special_price']);
        $this->assertSame(6.45, $r['sample'][1]['price']);
        $this->assertSame(4.95, $r['sample'][1]['special_price']);
        $this->assertGreaterThan(0.7, $r['confidence']);
    }

    public function testDifferentWordingStillReads(): void
    {
        $cells = $this->sheet([
            ['Botanical Name', 'Pot Size', 'Wholesale', 'Qty Avail'],
            ["Fake plantus 'One'", '#2', 12.5, 40],
            ["Fake plantus 'Two'", '#5', 29, 0],
        ]);
        $r = SupplierFormatInferrer::infer($cells);
        $this->assertTrue($r['ok'], $r['reason']);
        $this->assertSame(['name', 'size', 'price', 'available'], array_keys($r['mapped']));
        $this->assertSame(2, $r['rows']);
    }

    public function testHardscapeListWithListAndContractorPrice(): void
    {
        $cells = $this->sheet([
            ['Item No', 'Description', 'Unit', 'Contractor', 'Retail Price'],
            ['SO-1', 'Garden soil (fake)', 'yd', 38.0, 52.0],
            ['MU-2', 'Hemlock mulch (fake)', 'yd', 31.5, 44.0],
        ]);
        $r = SupplierFormatInferrer::infer($cells);
        $this->assertTrue($r['ok'], $r['reason']);
        $this->assertSame('Contractor', $r['mapped']['price']);
        $this->assertSame('Retail Price', $r['mapped']['list_price']);
        $this->assertSame(38.0, $r['sample'][0]['price']);
    }

    public function testSpecialsFileNameMakesASpecialsList(): void
    {
        $cells = $this->sheet([['Item', 'Price'], ['Fake A', 3.5], ['Fake B', 4.5]]);
        $r = SupplierFormatInferrer::infer($cells, 'October Specials.xlsx');
        $this->assertTrue($r['ok'], $r['reason']);
        $this->assertSame('specials', SupplierListParser::kind($r['spec'], 'October Specials.xlsx', ''));
        $this->assertSame('availability', SupplierListParser::kind($r['spec'], 'weekly.xlsx', ''));
    }

    public function testRefusesAFileThatIsNotAPriceList(): void
    {
        $this->assertFalse(SupplierFormatInferrer::infer([])['ok']);
        $notes = $this->sheet([['Dear customer'], ['Our office is closed on the holiday'], ['Thanks']]);
        $this->assertFalse(SupplierFormatInferrer::infer($notes)['ok']);
        // A name column but no price or quantity anywhere.
        $nameOnly = $this->sheet([['Item', 'Colour'], ['Fake A', 'red'], ['Fake B', 'blue'], ['Fake C', 'green']]);
        $this->assertFalse(SupplierFormatInferrer::infer($nameOnly)['ok']);
    }

    public function testHeaderWordsWithoutPricedRowsAreNotAHeader(): void
    {
        // A "Price" heading over text, not numbers — e.g. a terms table — must not import.
        $cells = $this->sheet([['Item', 'Price'], ['Fake A', 'call us'], ['Fake B', 'call us'], ['Fake C', 'ask']]);
        $this->assertFalse(SupplierFormatInferrer::infer($cells)['ok']);
    }

    public function testInferredSpecRoundTripsThroughTheParser(): void
    {
        $cells = $this->sheet([['SKU', 'Name', 'Price', 'Stock'], ['1', 'Fake Tree', 10.0, 5], ['2', 'Fake Shrub', 5.0, 9]]);
        $r = SupplierFormatInferrer::infer($cells);
        $spec = SupplierListParser::spec(json_encode($r['spec']));
        $p = SupplierListParser::parse($cells, $spec);
        $this->assertSame(2, count($p['rows']));
        $this->assertSame('Fake Shrub', $p['rows'][1]['name']);
        $this->assertSame(9, $p['rows'][1]['available']);
    }

    public function testClassify(): void
    {
        $this->assertSame('price', SupplierFormatInferrer::classify('trade price'));
        $this->assertSame('list_price', SupplierFormatInferrer::classify('list price'));
        $this->assertSame('special_price', SupplierFormatInferrer::classify('sale price'));
        $this->assertSame('available', SupplierFormatInferrer::classify('qty available'));
        $this->assertSame('product_code', SupplierFormatInferrer::classify('item no'));
        $this->assertSame('name', SupplierFormatInferrer::classify('item'));
        $this->assertNull(SupplierFormatInferrer::classify('colour'));
    }
}
