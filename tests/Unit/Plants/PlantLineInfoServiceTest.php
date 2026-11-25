<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The plant's photo and "now is a good time to ..." note beside a Work Record line (2026-11-11). Plants, photos and cards are invented.
 */
class PlantLineInfoServiceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec("CREATE TABLE plant_records (id INTEGER PRIMARY KEY, property_id INT, status TEXT, common_name TEXT, scientific_name TEXT, media_ids TEXT)");
        $this->db->exec("CREATE TABLE media_variants (id INTEGER PRIMARY KEY AUTOINCREMENT, media_id INT, variant_type TEXT, format TEXT, file_path TEXT)");
        $this->db->exec("INSERT INTO plant_records VALUES (1, 7, 'confirmed', 'New England Aster', 'Symphyotrichum novae-angliae', '10,11,12'), (2, 7, 'suggested', '', 'Acer palmatum', '20'), (3, 7, 'confirmed', 'Hosta', 'Hosta sieboldiana', ''),
            (4, 8, 'confirmed', 'Other Property Rose', 'Rosa rugosa', '11'), (5, 7, 'removed', 'Gone Fern', 'Dryopteris', '')");
        $this->db->exec("INSERT INTO media_variants (media_id, variant_type, format, file_path) VALUES
            (11, 'thumb_square', 'jpeg', '/_media/thumbs/aster-b.jpg'), (12, 'thumb_square', 'jpeg', '/_media/thumbs/aster-c.jpg'),
            (11, 'thumb_square', 'webp', '/_media/thumbs/aster-b.webp'), (20, 'medium', 'jpeg', '/_media/m/maple.jpg')");
    }

    private function svc(array $cards): PlantLineInfoService
    {
        return new PlantLineInfoService($this->db, fn(string $sci): ?array => $cards[$sci] ?? null);
    }

    private function card(string $status, array $propagation): array
    {
        return ['id' => 5, 'status' => $status, 'card' => ['propagation' => $propagation]];
    }

    public function testDivisionInSeasonSaysDivideOrMoveAndOutOfSeasonSaysNothing(): void
    {
        $card = $this->card('approved', [['method' => 'Division', 'from' => 3, 'to' => 4]]);
        $this->assertSame('Now is a good time to divide or move it.', PlantLineInfoService::nowNote($card, 4));
        $this->assertNull(PlantLineInfoService::nowNote($card, 11));
    }

    public function testOtherMethodsInSeasonAreNamedAndOnlyThoseInSeason(): void
    {
        $card = $this->card('approved', [['method' => 'Semi-ripe cuttings', 'from' => 7, 'to' => 9], ['method' => 'Seed', 'from' => 9, 'to' => 11], ['method' => 'Softwood cuttings', 'from' => 6, 'to' => 7]]);
        $this->assertSame('Now is a good time to propagate it: semi-ripe cuttings, seed.', PlantLineInfoService::nowNote($card, 9));
        $this->assertSame('Now is a good time to propagate it: seed.', PlantLineInfoService::nowNote($card, 11));
        $this->assertNull(PlantLineInfoService::nowNote($card, 2));
    }

    public function testAWindowThatWrapsTheYearWorksAndBlankOrBrokenMethodsAreIgnored(): void
    {
        $card = $this->card('approved', [['method' => 'Division', 'from' => 11, 'to' => 2], ['method' => '', 'from' => 1, 'to' => 12], 'junk']);
        $this->assertNotNull(PlantLineInfoService::nowNote($card, 1));
        $this->assertNull(PlantLineInfoService::nowNote($this->card('approved', [['method' => '', 'from' => 1, 'to' => 12]]), 6));
        $this->assertNull(PlantLineInfoService::nowNote(['card' => []], 6));
    }

    public function testALineGetsTheNameTheOwnPhotoAndTheNoteFromAnApprovedCard(): void
    {
        $cards = ['Symphyotrichum novae-angliae' => $this->card('approved', [['method' => 'Division', 'from' => 3, 'to' => 4]])];
        $items = [['item' => 'Cut stems to the ground — New England Aster', 'checked' => false, 'note' => '', 'plant_ids' => [1]], ['item' => 'Sweep paths', 'checked' => false, 'note' => '']];
        $out = $this->svc($cards)->decorate($items, 4, 7);
        $this->assertSame(['id' => 1, 'name' => 'New England Aster', 'photo_url' => '/_media/thumbs/aster-b.jpg', 'now' => 'Now is a good time to divide or move it.'], $out[0]['plant']);
        $this->assertArrayNotHasKey('plant', $out[1], 'a line with no plant is untouched');
    }

    public function testADraftCardNeverSpeaksAndAPlantWithoutAThumbnailGetsNoPhoto(): void
    {
        $cards = ['Acer palmatum' => $this->card('draft', [['method' => 'Division', 'from' => 1, 'to' => 12]])];
        $out = $this->svc($cards)->decorate([['item' => 'Prune — Acer palmatum', 'plant_ids' => [2]], ['item' => 'Feed — Hosta', 'plant_ids' => [3]]], 6, 7);
        $this->assertNull($out[0]['plant']['now'], 'draft card');
        $this->assertNull($out[0]['plant']['photo_url'], 'only a medium variant exists');
        $this->assertSame('Acer palmatum', $out[0]['plant']['name'], 'scientific name when there is no common name');
        $this->assertNull($out[1]['plant']['photo_url'], 'no photos at all');
    }

    public function testAnUnknownPlantIdAndABrokenLookupLeaveTheListUnchanged(): void
    {
        $items = [['item' => 'Prune', 'plant_ids' => [999]]];
        $this->assertSame($items, $this->svc([])->decorate($items, 6, 7));
        $bad = new PlantLineInfoService($this->db, function (string $sci): ?array { throw new RuntimeException('boom'); });
        $this->assertSame([['item' => 'X', 'plant_ids' => [1]]], $bad->decorate([['item' => 'X', 'plant_ids' => [1]]], 6, 7));
    }

    public function testAPlantFromAnotherPropertyOrARemovedPlantIsNeverShown(): void
    {
        $items = [['item' => 'Prune — Rose', 'plant_ids' => [4]], ['item' => 'Cut back — Gone Fern', 'plant_ids' => [5]]];
        $out = $this->svc([])->decorate($items, 6, 7);
        $this->assertSame($items, $out, 'plant 4 is on property 8 and plant 5 is removed: neither is decorated');
        $this->assertSame($items, $this->svc([])->decorate($items, 6, 0), 'no property, no extras');
    }
}
