<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Technology/Services/AdaFixedMarks.php';

class AdaFixedMarksTest extends TestCase
{
    private function db(bool $table = true): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($table) {
            $db->exec("CREATE TABLE ada_fixed_marks (id INTEGER PRIMARY KEY AUTOINCREMENT, system_key TEXT, problem TEXT, evidence_at_fix TEXT,
                       fixed_at TEXT, fixed_by INT, outcome TEXT, outcome_at TEXT, outcome_evidence TEXT)");
        }
        return $db;
    }

    public function test_without_the_table_everything_is_quietly_off(): void
    {
        $m = new AdaFixedMarks($this->db(false));
        $this->assertFalse($m->ready());
        $this->assertFalse($m->mark('cron:x', 'slow', 'e', 1, '2026-10-26 10:00:00'));
        $this->assertSame([], $m->open());
        $this->assertSame([], $m->recent());
    }

    public function test_one_open_mark_per_system_and_outcome_closes_it(): void
    {
        $m = new AdaFixedMarks($this->db());
        $this->assertTrue($m->mark('cron:x', 'slow', 'took 49 s', 7, '2026-10-26 10:00:00'));
        $this->assertTrue($m->mark('cron:x', 'slow', 'took 49 s', 7, '2026-10-26 11:00:00'));
        $open = $m->open();
        $this->assertCount(1, $open);
        $this->assertSame('2026-10-26 11:00:00', $open['cron:x']['fixed_at']);

        $m->close($open['cron:x']['id'], 'cleared', 'worked', '2026-10-26 11:05:00');
        $this->assertSame([], $m->open());
        $r = $m->recent();
        $this->assertSame(['cron:x', 'cleared', 'worked'], [$r[0]['system'], $r[0]['outcome'], $r[0]['outcome_evidence']]);

        $m->close($open['cron:x']['id'], 'bogus', 'x', '2026-10-26 12:00:00');   // ignored, already closed / invalid
        $this->assertSame('cleared', $m->recent()[0]['outcome']);
    }
}
