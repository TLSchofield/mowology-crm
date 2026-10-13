<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The shared xlsx/csv reader and the generic list parser (migration 1331). Synthetic files only:
 * the Inline-shaped one is read with the format row migration 1331 seeds; a second, made-up
 * supplier format (csv, different headers) proves nothing in the parser is Inline-specific.
 */
final class SupplierListParserTest extends TestCase
{
    public function testXlsxReaderSharedAndInlineStrings(): void
    {
        $rows = [1 => ['A' => 'Hello', 'B' => 3, 'C' => 2.5], 2 => ['B' => 'World', 'AA' => 'far']];
        foreach ([false, true] as $inline) {
            $cells = XlsxReader::firstSheet(PriceListFixtures::xlsx($rows, $inline));
            $this->assertSame('Hello', $cells[1]['A']);
            $this->assertSame(3, $cells[1]['B']);
            $this->assertSame(2.5, $cells[1]['C']);
            $this->assertSame('World', $cells[2]['B'], 'rich-text runs joined');
            $this->assertSame('far', $cells[2]['AA']);
        }
        $this->assertSame('2026-10-12', XlsxReader::excelDate(46307));
        $this->assertSame(27, XlsxReader::colIndex('AA'));
        $this->expectException(RuntimeException::class);
        XlsxReader::firstSheet('not a zip');
    }

    public function testInlineAvailabilityWithSeededFormat(): void
    {
        $f = PriceListFixtures::inlineFormat();
        $spec = SupplierListParser::spec($f['spec_json']);
        $rows = PriceListFixtures::availabilityRows([1001 => 8.9499999999999993, 1002 => 12, 1003 => 21.5, 1004 => 99.99]);
        $cells = SheetReader::read(PriceListFixtures::xlsx($rows), 'Wholesale_Test_Availability_2026_10_12.xlsx');
        $p = SupplierListParser::parse($cells, $spec, ['file_name' => 'Wholesale_Test_Availability_2026_10_12.xlsx', 'body' => 'shipping by October 30th']);
        $this->assertSame('availability', $p['kind']);
        $this->assertSame('2026-10-12', $p['list_date']);
        $this->assertNull($p['valid_until'], 'a weekly availability has no end date');
        $this->assertSame(['Order Minimum $500.00', '11cm Round - 10/tray'], $p['rules']);
        $this->assertCount(4, $p['rows']);
        $r = $p['rows'][0];
        $this->assertSame(['1001', "Fakeia testii 'Blue Moon'", '11cm Round', '4', 'Perennials', 8.95, 25],
                          [$r['product_code'], $r['name'], $r['size'], $r['zone'], $r['category'], $r['price'], $r['available']]);
    }

    public function testInlineSpecialsMonthColumnAndValidUntil(): void
    {
        $spec = SupplierListParser::spec(PriceListFixtures::inlineFormat()['spec_json']);
        $rows = [
            3 => ['B' => '2026 November Specials'],
            6 => ['B' => 'As part of a minimum order, include the following. Offer valid on orders placed and shipping in November'],
            14 => ['A' => 'ProductID', 'B' => 'Plant', 'C' => 'Size', 'D' => 'Zone', 'E' => 'Category', 'F' => 'List Price', 'G' => 'November', 'H' => 'Available', 'I' => 'Order', 'J' => 'Comments'],
            15 => ['A' => 1001, 'B' => "Fakeia testii 'Blue Moon'", 'C' => '11cm Round', 'D' => 4, 'E' => 'Perennials', 'F' => 8.95, 'G' => 6.4999999999, 'H' => 40, 'J' => 'Minimum 10'],
        ];
        $p = SupplierListParser::parse(SheetReader::read(PriceListFixtures::xlsx($rows), 'Test_Specials_List.xlsx'), $spec, ['file_name' => 'Test_Specials_List.xlsx']);
        $this->assertSame('specials', $p['kind']);
        $this->assertSame('2026-11-01', $p['list_date']);
        $this->assertSame('2026-11-30', $p['valid_until']);
        $r = $p['rows'][0];
        $this->assertSame([8.95, 8.95, 6.5, 'Minimum 10'], [$r['price'], $r['list_price'], $r['special_price'], $r['comment']]);

        $p2 = SupplierListParser::parse(SheetReader::read(PriceListFixtures::xlsx($rows), 'x.xlsx'), $spec,
                                        ['file_name' => 'Test_Specials_List.xlsx', 'body' => 'Specials valid on all orders shipping by November 20th.']);
        $this->assertSame('2026-11-20', $p2['valid_until'], 'the email\'s date wins over the sheet\'s month');
    }

    public function testSecondFormatCsvProvesParserIsGeneric(): void
    {
        $spec = [
            'header_anchor' => 'Botanical Name',
            'columns' => ['name' => ['Botanical Name'], 'size' => ['Pot'], 'price' => ['Net'], 'available' => ['Qty'],
                          'product_code' => ['Sku'], 'category' => ['Section']],
            'date_cell' => 'A1', 'title_cell' => 'A1', 'rules_cells' => ['A2'],
            'kinds' => [['kind' => 'availability', 'file' => 'stock']],
        ];
        $csv = "\"Stock as of October 5, 2026\";;;\nMinimum order 20 plants;;;\n;;;\nSku;Botanical Name;Pot;Net;Qty;Section\n"
             . "A-1;Fakeia testii Blue Moon;#1;\$9.10;12;Shrubs\nA-2;Mockus 'Vivid Dawn';1 gal;10;0;Shrubs\n;Section heading only;;;;\n";
        $p = SupplierListParser::parse(SheetReader::read($csv, 'weekly-stock.csv'), $spec, ['file_name' => 'weekly-stock.csv']);
        $this->assertSame('2026-10-05', $p['list_date']);
        $this->assertSame(['Minimum order 20 plants'], $p['rules']);
        $this->assertCount(2, $p['rows']);
        $this->assertSame(1, $p['skipped']);
        $this->assertSame(['A-1', 9.1, 12, 'no1'], [$p['rows'][0]['product_code'], $p['rows'][0]['price'], $p['rows'][0]['available'],
                                                     PlantNameNormalizer::size($p['rows'][0]['size'])['code']]);
        $this->assertSame(PlantNameNormalizer::parse("Fakeia testii 'Blue Moon'")['key'], PlantNameNormalizer::parse($p['rows'][0]['name'])['key']);
    }

    public function testHeaderMissingThrows(): void
    {
        $this->expectException(RuntimeException::class);
        SupplierListParser::parse([1 => ['A' => 'Nothing here']], ['header_anchor' => 'ProductID', 'columns' => ['name' => ['Item']]]);
    }

    public function testValidUntilRollsToNextYear(): void
    {
        $this->assertSame('2027-01-15', SupplierListParser::validUntil(['shipping by ([a-z]+[.]? [0-9]{1,2})'], 'shipping by Jan. 15th', '2026-12-20'));
        $this->assertNull(SupplierListParser::validUntil(['shipping by ([a-z]+)'], 'nothing', '2026-12-20'));
    }
}
