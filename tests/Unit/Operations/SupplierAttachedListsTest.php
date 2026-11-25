<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Supplier lists that arrive ATTACHED (owner, 2026-10-19): Specimen Trees' monthly .xls availability and
 * SW Garden Supply's contractor PDF. Formats come straight from migration 1374 (proves the seed parses).
 * Fixtures are fake plants in Specimen's layout (generated with xlwt — small enough to sit in the
 * compound file's mini stream); the real files hold the owner's trade prices and stay out of git.
 */
class SupplierAttachedListsTest extends TestCase
{
    private static function format(string $code): array
    {
        $sql = (string)file_get_contents(__DIR__ . '/../../../database/migrations/1374_supplier_formats_specimen_swgarden.sql');
        if (!preg_match("/\\('" . preg_quote($code, '/') . "', '([^']*)', '([^']*)', '([^']*)', NULL, '([^']*)',\\s*'((?:[^']|'')*)'\\)/s", $sql, $m)) {
            throw new RuntimeException("format $code not found in migration 1374");
        }
        $spec = str_replace(["''", '\\\\'], ["'", '\\'], $m[5]);
        return ['id' => $code === 'specimen-trees' ? 2 : 3, 'code' => $code, 'name' => $m[1], 'family' => $m[2], 'sender_domains' => $m[3],
                'link_hosts' => null, 'arrives' => $m[4], 'spec_json' => $spec, 'is_active' => 1];
    }

    private static function fixture(string $name): string
    {
        return (string)file_get_contents(__DIR__ . '/../../fixtures/supplier-lists/' . $name);
    }

    public function testXlsReaderReadsAnOldExcelFile(): void
    {
        $cells = SheetReader::read(self::fixture('specimen-fake-2026-10.xls'), 'AvailabilityOrderForm.xls');
        $this->assertSame('AVAILABILITY LIST AS OF', $cells[1]['E']);
        $this->assertSame('October 15, 2026', $cells[1]['I']);
        $this->assertSame('strProductID', $cells[15]['C']);
        $this->assertSame(['FAKEIA TESTII', '2FTE202P', 355, 92], [$cells[16]['B'], $cells[16]['C'], $cells[16]['G'], $cells[16]['H']]);
        $this->assertSame(6.75, $cells[17]['H']);
        $this->assertSame('xls', SheetReader::typeOf('list.bin', self::fixture('specimen-fake-2026-10.xls')), 'known by its bytes too');
    }

    public function testSpecimenMonthlyListImportsAndDiffs(): void
    {
        $db = PriceListFixtures::sqlite();
        $svc = new SupplierPriceListService($db);
        $f = self::format('specimen-trees');
        $this->assertTrue(SupplierPriceListService::listAttachment('AvailabilityOrderForm CanAll 101526.xls', $f));
        $this->assertFalse(SupplierPriceListService::listAttachment('logo.png', $f));
        $this->assertFalse(SupplierPriceListService::listAttachment('price.pdf', $f), 'this format reads spreadsheets only');

        $sup = ['id' => 2, 'name' => 'Specimen Trees'];
        $oct = $svc->importAttachment($sup, $f, 'AvailabilityOrderForm CanAll 101526.xls', self::fixture('specimen-fake-2026-10.xls'), ['message_key' => 'm1', 'received_at' => '2026-10-19']);
        $this->assertSame('imported', $oct['status']);
        $this->assertSame('duplicate', $svc->importAttachment($sup, $f, 'AvailabilityOrderForm CanAll 101526.xls', self::fixture('specimen-fake-2026-10.xls'),
                                                             ['message_key' => 'm1'])['status'], 'the same attachment from the same email imports once');
        $list = $svc->list($oct['list_id']);
        $this->assertSame(['2026-10-15', 'availability', 3], [$list['list_date'], $list['kind'], (int)$list['row_count']]);

        $nov = $svc->importAttachment($sup, $f, 'AvailabilityOrderForm CanAll 111526.xls', self::fixture('specimen-fake-2026-11.xls'), ['message_key' => 'm2', 'received_at' => '2026-11-16']);
        $d = $svc->diff($nov['list_id']);
        $this->assertSame([1, 1, 0, 1], [$d['new'], $d['up'], $d['down'], $d['same']], 'Fakeia 92 → 95, Pseudo same, Notrealis new');
        $this->assertCount(1, $d['gone'], 'Madeupia dropped off the list');
        $row = $db->query("SELECT price, prev_price, available, comment, zone, category FROM supplier_price_items WHERE list_id = {$nov['list_id']} AND product_code = '2FTE202P'")->fetch();
        $this->assertSame([95.0, 92.0, 300, 'Test Fir', '4', 'CONIFERS'],
                          [(float)$row['price'], (float)$row['prev_price'], (int)$row['available'], $row['comment'], (string)$row['zone'], $row['category']]);
    }

    public function testPdfPriceTableBecomesRows(): void
    {
        $f = self::format('sw-garden-contractor');
        $spec = SupplierListParser::spec($f['spec_json']);
        $text = "FAKE SUPPLY - Bulk Material Prices July 2026\nPRODUCT\tNOTES\tLANDSCAPER/\nCONTRACTOR PER\nYARD\nCUBIC YARD\nRETAIL\nHALF CUBIC\nYARD\nAvg Dry MT\nPer Yard (~)\n"
              . "Test Sand \tPlay Grade\t85.5 90 45 1.05\n"
              . "Fake Gravel\t99.75 105 55 1.23\n"
              . "Long Name Mulch (Wrapped\nOnto Two Lines)\n"
              . "Aged, Dark\t51.3 54 28 0.32\n"
              . "****Prices subject to change****\n0\n1\n";
        $cells = PdfTableReader::fromText($text, (array)$spec['pdf']);
        $p = SupplierListParser::parse($cells, $spec, ['file_name' => 'Price List - Landscaper: Contractor 07:2026.pdf']);
        $this->assertSame('2026-07-01', $p['list_date'], 'from the title "… July 2026"');
        $this->assertCount(3, $p['rows'], 'the footer is not a product');
        $this->assertSame(['Test Sand', 85.5, 90.0, 'Play Grade · Half yard retail 45 · t per yard 1.05'],
                          [$p['rows'][0]['name'], $p['rows'][0]['price'], $p['rows'][0]['list_price'], $p['rows'][0]['comment']]);
        $this->assertSame('Fake Gravel', $p['rows'][1]['name']);
        $this->assertSame('Long Name Mulch (Wrapped Onto Two Lines) Aged, Dark', $p['rows'][2]['name'], 'a wrapped row is one product');
        $this->assertTrue(SupplierPriceListService::listAttachment('Price List - Landscaper: Contractor 07:2026.pdf', $f));
        $this->assertFalse(SupplierPriceListService::listAttachment('ea03b8de.jpeg', $f));
    }

    public function testImapFilesListsEveryNamedAttachment(): void
    {
        $part = static function (int $type, string $sub, ?string $name, array $parts = []) {
            $p = new stdClass();
            $p->type = $type; $p->subtype = $sub; $p->encoding = 3; $p->bytes = 100;
            $p->ifdparameters = $name !== null ? 1 : 0;
            $p->dparameters = $name !== null ? [(object)['attribute' => 'filename', 'value' => $name]] : [];
            $p->ifparameters = 0; $p->parameters = [];
            if ($parts) $p->parts = $parts;
            return $p;
        };
        $mail = $part(1, 'MIXED', null, [
            $part(1, 'RELATED', null, [$part(0, 'HTML', null), $part(5, 'PNG', 'image001.png')]),
            $part(3, 'VND.MS-EXCEL', 'AvailabilityOrderForm CanAll 101526.xls'),
        ]);
        $files = ImapReader::files($mail);
        $this->assertSame(['image001.png', 'AvailabilityOrderForm CanAll 101526.xls'], array_column($files, 'filename'));
        $this->assertSame('2', $files[1]['pn']);
        $this->assertSame([], ImapReader::attachments($part(3, 'VND.MS-EXCEL', 'x.xls')), 'the old PDF/image list never saw spreadsheets');
    }
}
