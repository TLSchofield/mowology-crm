<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A new supplier's first list end to end on SQLite: read, imported, held out of pricing until confirmed.
 */
final class SupplierAutoFormatFlowTest extends TestCase
{
    private function file(): string
    {
        return PriceListFixtures::xlsx([
            1 => ['A' => 'Fake Growers wholesale list'],
            2 => ['A' => 'Code', 'B' => 'Plant', 'C' => 'Size', 'D' => 'Zone', 'E' => 'Trade Price', 'F' => 'Qty'],
            3 => ['A' => '501', 'B' => "Fakeia testii 'Blue Moon'", 'C' => 'No.1 Pot', 'D' => 4, 'E' => 8.95, 'F' => 120],
            4 => ['A' => '502', 'B' => "Fakeia testii 'Red Sun'", 'C' => 'No.2 Pot', 'D' => 4, 'E' => 14.0, 'F' => 40],
            5 => ['A' => '503', 'B' => "Mockus exampli 'Green'", 'C' => 'No.5 Pot', 'D' => 5, 'E' => 30.5, 'F' => 9],
        ]);
    }

    public function testFirstListIsReadImportedAndHeldOutOfPricingUntilConfirmed(): void
    {
        $db = PriceListFixtures::sqlite();
        $svc = new SupplierPriceListService($db);
        $this->assertTrue($svc->reviewReady());
        $this->assertNull($svc->formatForDomain('fakegrowers.example'));

        $inf = $svc->inferFromFile('FakeGrowers_2026-10-19.xlsx', $this->file());
        $this->assertTrue($inf['ok'], $inf['reason']);
        $this->assertSame('plants', SupplierPriceListService::guessFamily($inf), 'a zone column / botanical names = plants');

        $format = $svc->createAutoFormat(['id' => 8, 'name' => 'Fake Growers'], 'fakegrowers.example', $inf, ['fakegrowers.example']);
        $this->assertSame([1, 1], [(int)$format['needs_review'], (int)$format['auto_inferred']]);
        $this->assertSame($format['id'], $svc->createAutoFormat(['id' => 8], 'fakegrowers.example', $inf)['id'], 'one format per sender domain');
        $this->assertSame($format['id'], $svc->formatForDomain('mail.fakegrowers.example')['id'], 'subdomain finds it too');

        $r = $svc->importAttachment(['id' => 8, 'name' => 'Fake Growers'], $format, 'FakeGrowers_2026-10-19.xlsx', $this->file(), ['message_key' => 'm1', 'received_at' => '2026-10-19']);
        $this->assertSame('imported', $r['status']);
        $list = $svc->list((int)$r['list_id']);
        $this->assertSame(1, (int)$list['needs_review'], 'flagged until a person confirms');
        $this->assertSame(3, (int)$list['row_count']);

        $pid = (int)$db->query("SELECT catalog_product_id FROM supplier_price_items WHERE product_code = '501'")->fetchColumn();
        $this->assertCount(0, $svc->compare($pid), 'an unconfirmed list is out of price comparison / quote pricing');
        $q = $svc->reviewQueue();
        $this->assertCount(1, $q);
        $this->assertSame('Trade Price', $q[0]['columns']['price']);
        $this->assertCount(3, $q[0]['sample']);

        $svc->confirmFormat((int)$format['id']);
        $this->assertCount(1, $svc->compare($pid), 'confirmed: it prices quotes');
        $this->assertSame([], $svc->reviewQueue());
        $this->assertSame(0, (int)$svc->list((int)$r['list_id'])['needs_review']);
    }

    public function testFixColumnsAndRefusals(): void
    {
        $db = PriceListFixtures::sqlite();
        $svc = new SupplierPriceListService($db);
        $inf = $svc->inferFromFile('x.xlsx', $this->file());
        $f = $svc->createAutoFormat(['id' => 8, 'name' => 'Fake Growers'], 'fakegrowers.example', $inf);
        $svc->updateFormatColumns((int)$f['id'], ['available' => '', 'price' => 'Qty']);
        $spec = json_decode((string)$svc->formatForDomain('fakegrowers.example')['spec_json'], true);
        $this->assertArrayNotHasKey('available', $spec['columns']);
        $this->assertSame(['Qty'], $spec['columns']['price']);
        $this->expectException(InvalidArgumentException::class);
        $svc->updateFormatColumns((int)$f['id'], ['name' => '']);
    }

    public function testUnreadableFileIsRecordedOnceNotImported(): void
    {
        $svc = new SupplierPriceListService(PriceListFixtures::sqlite());
        $notes = PriceListFixtures::xlsx([1 => ['A' => 'Dear customer, we are closed Monday']]);
        $inf = $svc->inferFromFile('price-list.xlsx', $notes);
        $this->assertFalse($inf['ok']);
        $svc->recordSkip(8, 'a@fakegrowers.example', 'Price list', 'price-list.xlsx', $inf['reason'], 'm9');
        $svc->recordSkip(8, 'a@fakegrowers.example', 'Price list', 'price-list.xlsx', $inf['reason'], 'm9');
        $skips = $svc->openSkips();
        $this->assertCount(1, $skips, 'the same file is not recorded twice');
        $svc->dismissSkip((int)$skips[0]['id']);
        $this->assertSame([], $svc->openSkips());
        $this->assertNull($svc->createAutoFormat(['id' => 8], 'fakegrowers.example', $inf));
    }
}
