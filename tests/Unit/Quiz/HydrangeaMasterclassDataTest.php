<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Guards the Hydrangea Masterclass content and seeder.
 */
class HydrangeaMasterclassDataTest extends TestCase
{
    private function content(): array
    {
        return require __DIR__ . '/../../../app/Modules/Quiz/Data/hydrangea_masterclass.php';
    }

    public function test_shipped_content_is_valid(): void
    {
        $this->assertSame([], HydrangeaMasterclassSeeder::validate($this->content()));
    }

    public function test_every_written_module_has_at_least_eight_questions(): void
    {
        foreach ($this->content()['modules'] as $m) {
            if (!empty($m['questions'])) {
                $this->assertGreaterThanOrEqual(8, count($m['questions']), $m['name']);
            }
        }
    }

    public function test_validate_catches_bad_questions(): void
    {
        $c = $this->content();
        $c['modules'][0]['questions'][0][5] = ['A', 'A', 'B']; // 3 wrong but duplicates
        $problems = HydrangeaMasterclassSeeder::validate($c);
        $this->assertNotEmpty($problems);

        $c = $this->content();
        $c['modules'][0]['questions'][1][3] = ''; // no learn notes
        $this->assertNotEmpty(HydrangeaMasterclassSeeder::validate($c));

        $c = $this->content();
        $c['modules'][0]['questions'][2] = $c['modules'][0]['questions'][1]; // repeat
        $this->assertNotEmpty(HydrangeaMasterclassSeeder::validate($c));
    }

    public function test_seed_refuses_invalid_content_before_touching_db(): void
    {
        $db = $this->createMock(PDO::class);
        $db->expects($this->never())->method('beginTransaction');
        $c = $this->content();
        $c['modules'][0]['questions'][0][1] = 'impossible';

        $this->expectException(\RuntimeException::class);
        (new HydrangeaMasterclassSeeder($db))->seed($c, 1);
    }
}
