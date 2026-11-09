<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * CatalogTaxonService — common name + photo per species from iNaturalist (2026-10-20). The HTTP layer is a fake,
 * shaped like the real answers checked on production names that day (the first hit can be the wrong rank).
 */
final class CatalogTaxonServiceTest extends TestCase
{
    private string $dir;
    /** @var string[] */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/taxa-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->dir);
    }

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function http(array $answers): callable
    {
        return function (string $url) use ($answers): array {
            $this->calls[] = $url;
            if (strpos($url, 'api.inaturalist.org') !== false) {
                parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
                $a = $answers[$q['q']] ?? ['results' => []];
                return is_int($a) ? ['code' => $a, 'body' => ''] : ['code' => 200, 'body' => json_encode($a)];
            }
            return ['code' => 200, 'body' => base64_decode(self::PNG)];
        };
    }

    private function maple(): array
    {
        return ['results' => [
            ['id' => 1, 'name' => 'Acer palmatum', 'rank' => 'complex', 'preferred_common_name' => 'Acer ser. Palmata',
             'default_photo' => ['license_code' => 'cc-by-sa', 'medium_url' => 'https://x.example/1.jpg', 'attribution' => '(c) A']],
            ['id' => 2, 'name' => 'Acer palmatum', 'rank' => 'species', 'preferred_common_name' => 'Japanese maple',
             'default_photo' => ['license_code' => 'cc-by', 'medium_url' => 'https://x.example/2.jpg', 'attribution' => '(c) Jane Doe, some rights reserved (CC BY)']],
            ['id' => 3, 'name' => 'Acer palmatum dissectum', 'rank' => 'variety', 'preferred_common_name' => 'Japanese laceleaf maple'],
        ]];
    }

    public function testKeysFromMatchKey(): void
    {
        $this->assertSame(['acer palmatum', 'acer'], CatalogTaxonService::keysFor('acer|palmatum dissectum|firecracker|'));
        $this->assertSame(['hosta'], CatalogTaxonService::keysFor('hosta||blue angel|'));
        $this->assertSame(['anemone hupehensis', 'anemone'], CatalogTaxonService::keysFor('anemone|hupehensis var japonica|pamina|'));
        $this->assertSame([], CatalogTaxonService::keysFor('assorted||hostas|'));
        $this->assertSame([], CatalogTaxonService::keysFor(''));
    }

    public function testPickIgnoresWrongRankAndReadsHybridSign(): void
    {
        $this->assertSame(2, CatalogTaxonService::pick($this->maple()['results'], 'acer palmatum')['id'], 'the complex is not the species');
        $hyb = [['id' => 9, 'name' => "Calamagrostis \u{00D7} acutiflora", 'rank' => 'hybrid']];
        $this->assertSame(9, CatalogTaxonService::pick($hyb, 'calamagrostis acutiflora')['id']);
        $this->assertNull(CatalogTaxonService::pick($this->maple()['results'], 'acer'), 'a genus key needs a genus');
        $genus = [['id' => 5, 'name' => 'Symphyotrichum', 'rank' => 'genus'], ['id' => 6, 'name' => 'Hosta', 'rank' => 'genus']];
        $this->assertSame(6, CatalogTaxonService::pick($genus, 'hosta')['id']);
    }

    public function testLicenceFilter(): void
    {
        $d = fn(?string $lic) => CatalogTaxonService::describe(['id' => 1, 'rank' => 'species', 'preferred_common_name' => 'hostas',
            'default_photo' => ['license_code' => $lic, 'medium_url' => 'https://x.example/p.jpg', 'attribution' => '(c) Z']]);
        foreach (['cc0', 'cc-by', 'cc-by-sa', 'cc-by-nc', 'cc-by-nc-sa'] as $ok) $this->assertNotNull($d($ok)['photo_url'], $ok);
        foreach ([null, '', 'cc-by-nd', 'cc-by-nc-nd'] as $no) $this->assertNull($d($no)['photo_url'], (string)$no);
        $this->assertSame('Hostas', $d('cc-by')['common_name'], 'title-cased for the screen');
        $http = CatalogTaxonService::describe(['id' => 1, 'default_photo' => ['license_code' => 'cc-by', 'medium_url' => 'http://x.example/p.jpg']]);
        $this->assertNull($http['photo_url'], 'https only');
    }

    public function testAddNameNeverOverwrites(): void
    {
        $this->assertSame('Japanese Maple', CatalogTaxonService::addName(null, 'Japanese Maple'));
        $this->assertSame('Smooth maple|Japanese Maple', CatalogTaxonService::addName('Smooth maple', 'Japanese Maple'));
        $this->assertSame('japanese maple', CatalogTaxonService::addName('japanese maple', 'Japanese Maple'), 'already there, any case');
    }

    public function testBatchFillsNamesPhotosAndKeysOneCallPerSpecies(): void
    {
        $db = PriceListFixtures::sqlite();
        $ins = $db->prepare("INSERT INTO catalog_products (family, canonical_name, match_key, common_names) VALUES ('plants', ?, ?, ?)");
        $ins->execute(["Acer palmatum 'Bloodgood'", 'acer|palmatum|bloodgood|', null]);
        $ins->execute(["Acer palmatum 'Red Dragon'", 'acer|palmatum|red dragon|', 'Dragon maple']);
        $ins->execute(["Hosta x 'Blue Angel'", 'hosta||blue angel|', null]);
        $ins->execute(["Assorted Hostas", 'assorted||hostas|', null]);
        $svc = new CatalogTaxonService($db, $this->http([
            'acer palmatum' => $this->maple(),
            'hosta' => ['results' => [['id' => 7, 'name' => 'Hosta', 'rank' => 'genus', 'preferred_common_name' => 'hostas']]],
        ]), $this->dir, fn() => null);

        $r = $svc->enrichBatch(40);
        $this->assertSame(2, $r['keys']);
        $this->assertSame(3, $r['products']);
        $api = array_values(array_filter($this->calls, fn($u) => strpos($u, 'api.inaturalist.org') !== false));
        $this->assertCount(2, $api, 'one call per species, not per cultivar');

        $rows = $db->query("SELECT canonical_name, species_key, common_names FROM catalog_products ORDER BY id")->fetchAll();
        $this->assertSame(['acer palmatum', 'Japanese Maple'], [$rows[0]['species_key'], $rows[0]['common_names']]);
        $this->assertSame('Dragon maple|Japanese Maple', $rows[1]['common_names'], 'a name already there is kept');
        $this->assertSame(['hosta', 'Hostas'], [$rows[2]['species_key'], $rows[2]['common_names']]);
        $this->assertSame('', $rows[3]['species_key'], 'not a plant name: never asked again');

        $t = $db->query("SELECT * FROM catalog_taxa WHERE species_key = 'acer palmatum'")->fetch();
        $this->assertSame(['found', 'cc-by', '(c) Jane Doe, some rights reserved (CC BY)'], [$t['status'], $t['photo_license'], $t['photo_credit']]);
        $this->assertFileExists($this->dir . '/' . $t['photo_file']);

        $this->calls = [];
        $this->assertSame(0, $svc->enrichBatch(40)['keys'], 'nothing left to look up');
        $this->assertSame([], $this->calls);
    }

    public function testSpeciesNotFoundFallsBackToGenus(): void
    {
        $db = PriceListFixtures::sqlite();
        $db->exec("INSERT INTO catalog_products (family, canonical_name, match_key) VALUES ('plants', 'Cornus alba X', 'cornus|alba|x|')");
        $svc = new CatalogTaxonService($db, $this->http([
            'cornus alba' => ['results' => []],
            'cornus' => ['results' => [['id' => 4, 'name' => 'Cornus', 'rank' => 'genus', 'preferred_common_name' => 'dogwoods']]],
        ]), $this->dir, fn() => null);
        $svc->enrichBatch(10);
        $p = $db->query("SELECT species_key, common_names FROM catalog_products")->fetch();
        $this->assertSame(['cornus', 'Dogwoods'], [$p['species_key'], $p['common_names']]);
        $this->assertSame('none', $db->query("SELECT status FROM catalog_taxa WHERE species_key = 'cornus alba'")->fetchColumn());
    }

    public function testRateLimitStopsTheBatchAndSavesNothingForThatKey(): void
    {
        $db = PriceListFixtures::sqlite();
        $db->exec("INSERT INTO catalog_products (family, canonical_name, match_key) VALUES ('plants', 'A', 'acer|palmatum|a|'), ('plants', 'B', 'hosta||b|')");
        $svc = new CatalogTaxonService($db, $this->http(['acer palmatum' => 429]), $this->dir, fn() => null);
        $r = $svc->enrichBatch(10);
        $this->assertNotNull($r['stopped']);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM catalog_taxa")->fetchColumn());
        $this->assertSame(2, (int)$db->query("SELECT COUNT(*) FROM catalog_products WHERE species_key IS NULL")->fetchColumn(), 'tried again next run');
    }

    public function testOneCallASecond(): void
    {
        $db = PriceListFixtures::sqlite();
        $db->exec("INSERT INTO catalog_products (family, canonical_name, match_key) VALUES ('plants', 'A', 'acer|palmatum|a|'), ('plants', 'B', 'hosta||b|'), ('plants', 'C', 'carex|morrowii|c|')");
        $slept = 0;
        $svc = new CatalogTaxonService($db, $this->http([]), $this->dir, function (int $us) use (&$slept) { $slept += $us; });
        $svc->enrichBatch(10);
        $this->assertGreaterThanOrEqual(3, $slept / 1000000, 'a pause between every API call');
    }

    public function testListLinesCarryNameAndPhotoAndSearchFindsTheCommonName(): void
    {
        $db = PriceListFixtures::sqlite();
        $lists = new SupplierPriceListService($db);
        $r = $lists->import(PriceListFixtures::xlsx(PriceListFixtures::availabilityRows([1001 => 8.95, 1002 => 12.00], 46307)),
                            ['supplier_account_id' => 8, 'format' => PriceListFixtures::inlineFormat(), 'file_name' => 'Wholesale_Test_Availability.xlsx']);
        $svc = new CatalogTaxonService($db, $this->http([
            'fakeia testii' => ['results' => [['id' => 2, 'name' => 'Fakeia testii', 'rank' => 'species', 'preferred_common_name' => 'blue moon plant',
                'default_photo' => ['license_code' => 'cc-by-nc', 'medium_url' => 'https://x.example/2.jpg', 'attribution' => '(c) Jane']]]],
        ]), $this->dir, fn() => null);
        $svc->enrichBatch(10);
        $items = $lists->items((int)$r['list_id']);
        $withPhoto = array_values(array_filter($items, fn($x) => !empty($x['photo_file'])));
        $this->assertNotEmpty($withPhoto, 'the Fakeia lines get the species photo');
        $this->assertSame('(c) Jane', $withPhoto[0]['photo_credit']);
        $this->assertStringContainsString('Blue Moon Plant', (string)$withPhoto[0]['common_names']);
        $this->assertNotEmpty($lists->items((int)$r['list_id'], ['q' => 'blue moon plant']), 'searching the common name finds the line');
    }
}
