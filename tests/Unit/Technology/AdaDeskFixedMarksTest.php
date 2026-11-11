<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Technology/Services/AdaRules.php';
require_once __DIR__ . '/../../../app/Modules/Technology/Services/AdaDeskService.php';

/** "Mark fixed" end to end through AdaDeskService::checks() on sqlite: wait, then clear or come back. */
class AdaDeskFixedMarksTest extends TestCase
{
    private string $root;
    private PDO $db;

    protected function setUp(): void
    {
        ini_set('error_log', '/dev/null');   // the other check groups have no tables here and log that
        $this->root = sys_get_temp_dir() . '/ada-desk-' . uniqid();
        mkdir($this->root . '/crm', 0777, true);
        mkdir($this->root . '/app/Modules/X/Cron', 0777, true);
        file_put_contents($this->root . '/crm/database_appstack.php',
            "<?php\n\$crons = [\n    ['key'=>'cms_x','title'=>'Cms X','cat'=>'cms','human'=>'Every minute','expr'=>'* * * * *','app'=>'/app/Modules/X/Cron/cms_x.php','run'=>null,'abbr'=>'CX','desc'=>'x'],\n];\n");
        file_put_contents($this->root . '/app/Modules/X/Cron/cms_x.php', "<?php\nrecordCronRun('cms_x', 'success', '', 1);\n");
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE cron_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, cron_key TEXT, status TEXT, ran_at TEXT, duration_ms INT, summary TEXT, error_message TEXT)");
        $this->db->exec("CREATE TABLE ada_fixed_marks (id INTEGER PRIMARY KEY AUTOINCREMENT, system_key TEXT, problem TEXT, evidence_at_fix TEXT,
                         fixed_at TEXT, fixed_by INT, outcome TEXT, outcome_at TEXT, outcome_evidence TEXT)");
    }

    private function run_(string $at, int $ms): void
    {
        $this->db->prepare("INSERT INTO cron_runs (cron_key, status, ran_at, duration_ms, summary) VALUES ('cms_x', 'success', ?, ?, 'ok')")->execute([$at, $ms]);
    }

    private function check(string $now): array
    {
        foreach ((new AdaDeskService($this->db, $now, $this->root))->checks() as $c) {
            if ($c['system'] === 'cron:cms_x') return $c;
        }
        $this->fail('cron:cms_x check missing');
    }

    public function test_slow_then_marked_fixed_waits_then_clears_on_a_fast_run(): void
    {
        $this->run_('2026-10-26 09:00:00', 49000);
        $this->run_('2026-10-26 09:59:00', 900);
        $c = $this->check('2026-10-26 10:00:00');
        $this->assertSame(['amber', 'slow', true], [$c['state'], $c['problem'], $c['fixable']]);
        $this->assertStringContainsString('slowest 49 s', $c['basis']);

        $desk = new AdaDeskService($this->db, '2026-10-26 10:00:00', $this->root);
        $this->assertTrue($desk->marks()->mark('cron:cms_x', 'slow', $c['evidence'], 1, '2026-10-26 10:00:00'));

        $c = $this->check('2026-10-26 10:00:30');
        $this->assertSame(['info', 'awaiting-run'], [$c['state'], $c['problem']]);

        $this->run_('2026-10-26 10:01:00', 800);
        $c = $this->check('2026-10-26 10:01:30');
        $this->assertSame('green', $c['state']);
        $this->assertStringContainsString('1 run since it was marked fixed', $c['basis']);
        $r = (new AdaFixedMarks($this->db))->recent();
        $this->assertSame('cleared', $r[0]['outcome']);
        $this->assertSame([], (new AdaFixedMarks($this->db))->open());
    }

    public function test_a_slow_run_after_the_mark_comes_back_and_is_recorded(): void
    {
        $this->run_('2026-10-26 09:59:00', 49000);
        $desk = new AdaDeskService($this->db, '2026-10-26 10:00:00', $this->root);
        $desk->marks()->mark('cron:cms_x', 'slow', 'e', 1, '2026-10-26 10:00:00');
        $this->run_('2026-10-26 10:01:00', 50000);
        $c = $this->check('2026-10-26 10:01:30');
        $this->assertSame(['amber', 'slow'], [$c['state'], $c['problem']]);
        $this->assertSame('came_back', (new AdaFixedMarks($this->db))->recent()[0]['outcome']);
    }

    public function test_an_old_spike_ages_out_after_three_days_without_any_mark(): void
    {
        $this->run_('2026-10-20 09:00:00', 49000);
        $this->run_('2026-10-26 09:59:00', 900);
        $this->assertSame('green', $this->check('2026-10-26 10:00:00')['state']);
    }

    public function test_a_never_ran_job_marked_fixed_waits_and_then_is_missed_again(): void
    {
        $desk = new AdaDeskService($this->db, '2026-10-26 10:00:00', $this->root);
        $this->assertSame('never-ran', $this->check('2026-10-26 10:00:00')['problem']);
        $desk->marks()->mark('cron:cms_x', 'never-ran', 'e', 1, '2026-10-26 10:00:00');
        $this->assertSame('awaiting-run', $this->check('2026-10-26 10:01:00')['problem']);
        $this->assertSame('never-ran', $this->check('2026-10-26 10:30:00')['problem']);   // a full cycle with no run: judged again
    }
}
