<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once APP_ROOT . '/Modules/Plants/Services/PlantIdService.php';

/** "What is this?" — Pl@ntNet is always a fake transport here; the real API is never called. */
class PlantIdServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mwplant' . bin2hex(random_bytes(4));
        mkdir($this->root . '/_media', 0777, true);
        file_put_contents($this->root . '/_media/a.jpg', 'jpegbytes');
        file_put_contents($this->root . '/_media/b.jpg', 'jpegbytes2');
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/_media/a.jpg'); @unlink($this->root . '/_media/b.jpg'); @rmdir($this->root . '/_media'); @rmdir($this->root);
    }

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec('CREATE TABLE properties (id INTEGER PRIMARY KEY)');
        $db->exec('INSERT INTO properties (id) VALUES (76)');
        $db->exec('CREATE TABLE media_assets (id INTEGER PRIMARY KEY, file_path TEXT, mime_type TEXT, gps_lat REAL, gps_lng REAL, gps_accuracy_m REAL)');
        $db->exec("INSERT INTO media_assets VALUES (1, '/_media/a.jpg', 'image/jpeg', 49.259075, -123.143400, 40), (2, '/_media/b.jpg', 'image/jpeg', 49.259076, -123.143401, 4), (3, '/_media/x.gif', 'image/gif', NULL, NULL, NULL)");
        $db->exec('CREATE TABLE media_variants (media_id INT, file_path TEXT, variant_type TEXT, format TEXT)');
        $db->exec("INSERT INTO media_variants VALUES (1, '/_media/t1.jpg', 'thumb_square', 'jpeg')");
        $db->exec('CREATE TABLE media_links (media_id INT, context_type TEXT, context_id INT)');
        $db->exec("INSERT INTO media_links VALUES (1, 'property', 76), (2, 'job_visit', 900), (3, 'property', 76), (4, 'property', 12)");
        $db->exec('CREATE TABLE job_visits (id INTEGER PRIMARY KEY, plan_id INT)');
        $db->exec('INSERT INTO job_visits VALUES (900, 5)');
        $db->exec('CREATE TABLE job_plans (id INTEGER PRIMARY KEY, property_id INT)');
        $db->exec('INSERT INTO job_plans VALUES (5, 76)');
        $db->exec("INSERT INTO media_assets VALUES (4, '/_media/a.jpg', 'image/jpeg', NULL, NULL, NULL)");
        $db->exec('CREATE TABLE property_measurements (id INTEGER PRIMARY KEY, property_id INT, measurement_name TEXT, measurement_type TEXT, polygon_coords TEXT)');
        $ring = json_encode([['lat' => 49.259086, 'lng' => -123.143434], ['lat' => 49.259086, 'lng' => -123.143350], ['lat' => 49.259063, 'lng' => -123.143350], ['lat' => 49.259063, 'lng' => -123.143434]]);
        $db->prepare("INSERT INTO property_measurements VALUES (10, 76, 'Garden Bed South West', 'garden_beds', ?)")->execute([$ring]);
        $db->exec("CREATE TABLE plant_records (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, property_id INT, measurement_id INT, bed_name TEXT, bed_how TEXT, media_ids TEXT,
                   lat REAL, lng REAL, accuracy_m REAL, heading_deg REAL, matches TEXT, scientific_name TEXT, common_name TEXT, score REAL, status TEXT DEFAULT 'suggested',
                   notes TEXT, created_by INT, created_at TEXT, confirmed_by INT, confirmed_at TEXT, updated_at TEXT)");
        $db->exec("CREATE TABLE head_lessons (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT DEFAULT 1, head TEXT, subject_kind TEXT, subject_key TEXT,
                   field TEXT, said TEXT, correct TEXT, source TEXT, ref_type TEXT, ref_id INT, corrected_by INT, reason TEXT, created_at TEXT)");
        return $db;
    }

    private static function answer(float $score): string
    {
        return json_encode(['results' => [
            ['score' => $score, 'species' => ['scientificNameWithoutAuthor' => 'Lavandula angustifolia', 'commonNames' => ['English lavender'], 'family' => ['scientificNameWithoutAuthor' => 'Lamiaceae']]],
            ['score' => 0.05, 'species' => ['scientificNameWithoutAuthor' => 'Lavandula stoechas', 'commonNames' => ['Spanish lavender']]],
        ]]);
    }

    private function transport(string $body, int &$calls, ?array &$images = null, int $code = 200): callable
    {
        return function (string $url, array $imgs) use ($body, &$calls, &$images, $code) { $calls++; $images = $imgs; return ['code' => $code, 'body' => $body]; };
    }

    public function testThreePhotosOneCallBestGpsAndTheBed(): void
    {
        $db = $this->db(); $calls = 0; $imgs = null;
        $svc = new PlantIdService($db, $this->transport(self::answer(0.82), $calls, $imgs), $this->root);
        $r = $svc->identify(76, [1, 2, 3], [], 7);
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $calls);
        $this->assertCount(2, $imgs, 'the GIF is not sent');
        $rec = $r['record'];
        $this->assertSame(['Lavandula angustifolia', 'English lavender', 'suggested'], [$rec['scientific_name'], $rec['common_name'], $rec['status']]);
        $this->assertSame('Garden Bed South West', $rec['bed_name'], 'photo 2 had the 4 m fix, inside the bed');
        $this->assertSame('inside', $rec['bed_how']);
        $this->assertSame(['/_media/t1.jpg'], $rec['thumbs']);
        $this->assertStringContainsString('English lavender', $r['message']);
    }

    public function testTheSamePhotosAgainAnswerWithTheSameRecord(): void
    {
        $db = $this->db(); $calls = 0;
        $svc = new PlantIdService($db, $this->transport(self::answer(0.82), $calls), $this->root);
        $first = $svc->identify(76, [1, 2], [], 7);
        $again = $svc->identify(76, [1, 2], [], 7);
        $this->assertSame(1, $calls, 'one Pl@ntNet call');
        $this->assertTrue($again['ok'] && $again['repeat']);
        $this->assertSame($first['record']['id'], $again['record']['id']);
        $this->assertSame(1, (int)$db->query('SELECT COUNT(*) FROM plant_records')->fetchColumn());
        $this->assertNotSame($first['record']['id'], $svc->identify(76, [2], [], 7)['record']['id'], 'other photos are another plant');
        $this->assertSame($first['record']['id'], $svc->identify(76, [2, 1], [], 7)['record']['id'], 'the same photos in another order');
        $this->assertSame(2, $calls, 'only the [2] plant called Pl@ntNet again');
        $bad = (new PlantIdService($db, $this->transport('<html>proxy</html>', $calls), $this->root))->identify(76, [1], [], 7);
        $this->assertFalse($bad['ok']);
        $this->assertTrue($bad['retry'], 'a garbled 200 is try-again, not "not recognised"');
    }

    public function testALowScoreIsNotSure(): void
    {
        $db = $this->db(); $calls = 0;
        $r = (new PlantIdService($db, $this->transport(self::answer(0.03), $calls), $this->root))->identify(76, [1], [], 7);
        $this->assertSame('unsure', $r['record']['status']);
        $this->assertNull($r['record']['scientific_name']);
        $this->assertCount(2, $r['record']['matches'], 'the guesses are still kept to pick from');
        $this->assertStringContainsString('closer photo', $r['message']);
    }

    public function testNotFoundAndErrors(): void
    {
        $db = $this->db(); $calls = 0;
        $r = (new PlantIdService($db, $this->transport('{"message":"Species not found"}', $calls, $x, 404), $this->root))->identify(76, [1], [], 7);
        $this->assertSame('unsure', $r['record']['status']);
        $r = (new PlantIdService($db, $this->transport('', $calls, $x, 500), $this->root))->identify(76, [2], [], 7);
        $this->assertFalse($r['ok']);
        $this->assertTrue($r['retry'], 'Pl@ntNet down is worth another try');
        $this->assertFalse((new PlantIdService($db, $this->transport('', $calls), $this->root))->identify(99, [1], [], 7)['ok'], 'no such property');
        $this->assertFalse((new PlantIdService($db, $this->transport('', $calls), $this->root))->identify(76, [3], [], 7)['ok'], 'GIF only');
    }

    public function testOnlyThisPropertysPhotos(): void
    {
        $db = $this->db(); $calls = 0;
        $r = (new PlantIdService($db, $this->transport(self::answer(0.82), $calls), $this->root))->identify(76, [4], [], 7);
        $this->assertFalse($r['ok'], "photo 4 belongs to property 12");
        $this->assertSame(0, $calls, 'never sent to Pl@ntNet');
    }

    public function testThePhoneFixAndHeadingWin(): void
    {
        $w = PlantIdService::where(['lat' => '49.2', 'lng' => '-123.1', 'accuracy' => '6', 'heading' => '181.5'], [1], []);
        $this->assertSame([49.2, -123.1, 6.0, 181.5], [$w['lat'], $w['lng'], $w['accuracy'], $w['heading']]);
        $this->assertNull(PlantIdService::where(['heading' => 400], [], [])['heading']);
    }

    public function testConfirmPicksTheBedAndLearns(): void
    {
        $db = $this->db(); $calls = 0;
        $svc = new PlantIdService($db, $this->transport(self::answer(0.82), $calls), $this->root);
        $id = (int)$svc->identify(76, [2], [], 7)['record']['id'];
        $c = $svc->confirm($id, ['scientific_name' => 'Lavandula stoechas', 'measurement_id' => 10], 7);
        $this->assertTrue($c['ok']);
        $this->assertSame(['confirmed', 'Spanish lavender', 0.05], [$c['record']['status'], $c['record']['common_name'], (float)$c['record']['score']]);
        $l = $db->query('SELECT said, correct FROM head_lessons')->fetch();
        $this->assertSame(['Lavandula angustifolia', 'Lavandula stoechas'], [$l['said'], $l['correct']]);
        $this->assertFalse($svc->confirm($id, ['scientific_name' => 'X', 'measurement_id' => 999], 7)['ok'], 'a bed from another property');
    }

    public function testTheBedIsFoundAgainAfterTheMeasurementsAreReSaved(): void
    {
        $db = $this->db(); $calls = 0;
        $svc = new PlantIdService($db, $this->transport(self::answer(0.82), $calls), $this->root);
        $svc->identify(76, [2], [], 7);
        $db->exec('UPDATE property_measurements SET id = 55');   // a re-save gives every bed a new id
        $this->assertSame(55, $svc->forProperty(76)[0]['bed']['id']);
        $this->assertTrue($svc->forProperty(76)[0]['bed']['sure']);
    }
}
