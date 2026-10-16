<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Our own plant photos in the quiz (SitePlantQuizService): linking, captions, which photos, the cap, today first. */
class SitePlantQuizServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE quiz_questions (id INTEGER PRIMARY KEY, category_id INT, plant_key TEXT, question_text TEXT, learn_notes TEXT, difficulty TEXT,
                   question_type TEXT, learning_level INT, is_active INT DEFAULT 1, created_by INT)");
        $db->exec("CREATE TABLE quiz_options (id INTEGER PRIMARY KEY, question_id INT, option_text TEXT, is_correct INT, sort_order INT)");
        $db->exec("CREATE TABLE quiz_question_images (id INTEGER PRIMARY KEY, question_id INT, image_path TEXT, sort_order INT, caption TEXT,
                   source TEXT, plant_record_id INT, property_id INT, media_id INT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE TABLE plant_care_cards (scientific_name TEXT, common_name TEXT)");
        $db->exec("CREATE TABLE plant_records (id INTEGER PRIMARY KEY, property_id INT, media_ids TEXT, bed_name TEXT, scientific_name TEXT, common_name TEXT, status TEXT)");
        $db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT)");
        $db->exec("CREATE TABLE media_assets (id INTEGER PRIMARY KEY, file_path TEXT, mime_type TEXT)");
        $db->exec("INSERT INTO plant_care_cards VALUES ('Hydrangea macrophylla', 'Bigleaf hydrangea'), ('Poa annua', 'Annual bluegrass'), ('Lavandula angustifolia', 'English lavender')");
        $db->exec("INSERT INTO quiz_questions (id, category_id, question_text, is_active) VALUES (34, 2, 'Big mophead flowers…', 1), (14, 1, 'Pale green grass weed…', 1), (5, 1, 'Trifoliate leaves…', 1)");
        $db->exec("INSERT INTO quiz_options (question_id, option_text, is_correct) VALUES (34, 'Bigleaf Hydrangea', 1), (34, 'Lilac', 0), (14, 'Annual Bluegrass (Poa annua)', 1), (5, 'White Clover', 1)");
        $db->exec("INSERT INTO properties VALUES (1496, '1935 West 12th Avenue'), (73, '315 Midlothian Ave')");
        $db->exec("INSERT INTO media_assets VALUES (1, '/_media/a.jpg', 'image/jpeg'), (2, '/_media/b.jpg', 'image/jpeg'), (3, '/_media/c.jpg', 'image/jpeg')");
        return $db;
    }

    private function svc(PDO $db, array &$copied = []): SitePlantQuizService
    {
        return new SitePlantQuizService($db, function (string $src, string $dst) use (&$copied) { $copied[] = [$src, $dst]; return true; },
            fn() => ['common_name' => 'English lavender', 'weed' => false, 'notes' => 'Prune after flowering.', 'tasks' => [['task' => 'Prune', 'how' => 'cut back by a third']]], '/root');
    }

    public function test_names_match_the_care_library(): void
    {
        $cards = [['scientific_name' => 'Poa annua', 'common_name' => 'Annual bluegrass'], ['scientific_name' => 'Hydrangea macrophylla', 'common_name' => 'Bigleaf hydrangea']];
        $this->assertSame('poa annua', SitePlantQuizService::matchKey('Annual Bluegrass (Poa annua)', $cards));
        $this->assertSame('hydrangea macrophylla', SitePlantQuizService::matchKey('Bigleaf Hydrangea', $cards));
        $this->assertNull(SitePlantQuizService::matchKey('Lilac', $cards));
        $this->assertNull(SitePlantQuizService::matchKey('', $cards));
    }

    public function test_link_questions_fills_plant_key(): void
    {
        $db = $this->db();
        $this->assertSame(2, $this->svc($db)->linkQuestions());
        $this->assertSame(['poa annua', 'hydrangea macrophylla'],
            $db->query("SELECT plant_key FROM quiz_questions WHERE id IN (14, 34) ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
        $this->assertNull($db->query("SELECT plant_key FROM quiz_questions WHERE id = 5")->fetchColumn(), 'clover has no card');
    }

    public function test_caption_photos_and_cap(): void
    {
        $this->assertSame('Seen at 1935 West 12th Avenue · Front bed', SitePlantQuizService::caption(' 1935  West 12th Avenue', 'Front bed'));
        $this->assertSame('Seen at 315 Midlothian Ave', SitePlantQuizService::caption('315 Midlothian Ave', null));
        $this->assertSame('Seen on one of our sites', SitePlantQuizService::caption('', ''));
        $this->assertSame('Seen at 70 West 18th Avenue', SitePlantQuizService::caption('70 West 18th Avenue, Vancouver, BC, Canada', ''));
        $this->assertSame([12, 13], SitePlantQuizService::pickMedia(['11', '12', '13']), 'never the whole-plant shot');
        $this->assertSame([12], SitePlantQuizService::pickMedia([11, 12]));
        $this->assertSame([11], SitePlantQuizService::pickMedia([11]));

        $rows = [];
        foreach (range(1, 8) as $i) $rows[] = ['id' => $i, 'property_id' => $i <= 6 ? 1 : 2, 'created_at' => '2026-10-' . str_pad((string)(10 + $i), 2, '0', STR_PAD_LEFT)];
        $drop = SitePlantQuizService::toTrim($rows, 3);
        $this->assertCount(5, $drop);
        $this->assertNotContains(8, $drop, 'newest kept');
        $this->assertNotContains(6, $drop, 'the other property kept first');
    }

    public function test_today_first(): void
    {
        $this->assertSame([9, 1, 2, 3], SitePlantQuizService::mergeSiteFirst([1, 2, 3, 9], [9], 4));
        $this->assertSame([7, 8, 1], SitePlantQuizService::mergeSiteFirst([1, 2, 3], [7, 8, 9], 3));
        $this->assertSame([1, 2], SitePlantQuizService::mergeSiteFirst([1, 2], [], 5));
    }

    public function test_a_confirmed_plant_adds_its_closer_photos_to_its_question_once(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO plant_records VALUES (2, 1496, '1,2,3', 'Front bed', 'Hydrangea macrophylla', 'Bigleaf hydrangea', 'confirmed')");
        $copied = [];
        $svc = $this->svc($db, $copied);
        $r = $svc->fromConfirmed(2);
        $this->assertSame([true, 34, false, 2], [$r['ok'], $r['question_id'], $r['drafted'], $r['added']]);
        $this->assertSame([['/root/_media/b.jpg', '/root/uploads/quiz/site_2_2.jpg'], ['/root/_media/c.jpg', '/root/uploads/quiz/site_2_3.jpg']], $copied);
        $this->assertSame('Seen at 1935 West 12th Avenue · Front bed', $db->query("SELECT caption FROM quiz_question_images LIMIT 1")->fetchColumn());
        $this->assertSame(0, $svc->fromConfirmed(2)['added'], 'a second confirm adds nothing');
        $this->assertFalse($svc->fromConfirmed(99)['ok']);
    }

    public function test_an_unknown_plant_gets_an_inactive_draft_question(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO plant_records VALUES (3, 73, '1,2,3', NULL, 'Lavandula angustifolia', 'English lavender', 'confirmed')");
        $r = $this->svc($db)->fromConfirmed(3);
        $this->assertTrue($r['drafted']);
        $q = $db->query("SELECT category_id, plant_key, question_type, is_active, question_text, learn_notes FROM quiz_questions WHERE id = {$r['question_id']}")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame([2, 'lavandula angustifolia', 'photo_id', 0, 'What is this plant?'], [(int)$q['category_id'], $q['plant_key'], $q['question_type'], (int)$q['is_active'], $q['question_text']]);
        $this->assertStringContainsString('Prune', $q['learn_notes']);
        $opts = $db->query("SELECT option_text, is_correct FROM quiz_options WHERE question_id = {$r['question_id']}")->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame(1, (int)$opts['English lavender']);
        $wrong = array_keys(array_filter($opts, fn($v) => !(int)$v));
        sort($wrong);
        $this->assertSame(['Annual bluegrass', 'Bigleaf Hydrangea'], $wrong, 'other plant questions first, topped up from the care library, no duplicate of a name');
    }

    public function test_not_confirmed_does_nothing(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO plant_records VALUES (4, 1496, '1,2,3', NULL, 'Hydrangea macrophylla', NULL, 'suggested')");
        $this->assertFalse($this->svc($db)->fromConfirmed(4)['ok']);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM quiz_question_images")->fetchColumn());
    }

    public function test_reconfirmed_as_another_plant_moves_its_photos(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO plant_records VALUES (5, 1496, '1,2,3', 'Front bed', 'Hydrangea macrophylla', 'Bigleaf hydrangea', 'confirmed')");
        $svc = $this->svc($db);
        $svc->fromConfirmed(5);
        $db->exec("UPDATE plant_records SET scientific_name = 'Poa annua', common_name = 'Annual bluegrass', bed_name = 'Lawn' WHERE id = 5");
        $db->exec("UPDATE quiz_questions SET plant_key = 'poa annua' WHERE id = 14");
        $svc->fromConfirmed(5);
        $this->assertSame([14], array_map('intval', $db->query("SELECT DISTINCT question_id FROM quiz_question_images")->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame(['Seen at 1935 West 12th Avenue · Lawn'], $db->query("SELECT DISTINCT caption FROM quiz_question_images")->fetchAll(PDO::FETCH_COLUMN));
    }
}
