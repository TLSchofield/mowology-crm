<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The registries must describe the real code (no skill pointing at a missing service or a mislabelled cost line, no
 * cron that is not in the Crons tab), and the eval fixtures are the regression gate: every case must pass.
 */
class AgentRegistryAndEvalsTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testEverySkillBelongsToAKnownHeadAndNamesAreUnique(): void
    {
        $seen = [];
        foreach (SkillRegistry::all() as $k) {
            $this->assertTrue(AgentRegistry::exists($k['head']), "unknown head {$k['head']}");
            $key = $k['head'] . '.' . $k['name'];
            $this->assertArrayNotHasKey($key, $seen, "duplicate skill $key");
            $seen[$key] = true;
            $this->assertSame($k['llm'] === null, $k['deterministic']);
            $this->assertGreaterThanOrEqual(1, $k['version']);
        }
        $this->assertGreaterThan(15, count($seen));
    }

    public function testSkillActionsAreRealPolicyActions(): void
    {
        foreach (SkillRegistry::all() as $k) {
            if ($k['action'] !== null) {
                $this->assertArrayHasKey($k['action'], AgentPolicy::DEFAULTS, "{$k['head']}.{$k['name']} names an action AgentPolicy does not know");
            }
        }
    }

    public function testEveryDeclaredServiceExistsInTheSource(): void
    {
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root() . '/app', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'php') {
                $files[$f->getBasename('.php')] = true;
            }
        }
        foreach (SkillRegistry::all() as $k) {
            $this->assertArrayHasKey($k['service'], $files, "{$k['head']}.{$k['name']}: no file for service {$k['service']}");
        }
    }

    public function testEveryLlmSkillLabelIsReallyUsedWhereDeclared(): void
    {
        $src = '';
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root() . '/app', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'php' && $f->getBasename('.php') !== 'SkillRegistry') {
                $src .= file_get_contents($f->getPathname());
            }
        }
        foreach (SkillRegistry::all() as $k) {
            if ($k['llm'] !== null) {
                $this->assertStringContainsString("'skill' => '" . $k['llm'] . "'", $src, "no gateway call labelled {$k['llm']}: cost lines would not match this skill");
            }
        }
    }

    public function testEveryHeadCronIsInTheCronsTab(): void
    {
        $registry = (string)file_get_contents(self::root() . '/public/crm/database_appstack.php');
        foreach (AgentRegistry::heads() as $slug => $h) {
            foreach ($h['crons'] as $key) {
                $this->assertStringContainsString("'key'=>'$key'", $registry, "$slug: cron $key is not in the Crons tab");
            }
        }
    }

    public function testAllEvalCasesPass(): void
    {
        $results = AgentEvals::run();
        $this->assertGreaterThan(20, count($results));
        $failed = array_filter($results, fn($r) => !$r['pass']);
        $this->assertSame([], array_values(array_map(fn($r) => $r['skill'] . ': ' . $r['name'] . ' ' . $r['error'], $failed)));
    }

    public function testEvalSkillLabelsMatchTheRegistry(): void
    {
        $labels = array_unique(array_column(AgentEvals::cases(), 0));
        $declared = array_filter(array_column(SkillRegistry::all(), 'eval'));
        foreach ($declared as $e) {
            $this->assertContains($e, $labels, "skill declares eval $e but no case exists for it");
        }
    }

    public function testSummariseAndRecord(): void
    {
        $sum = AgentEvals::summarise([
            ['skill' => 'x', 'name' => 'a', 'pass' => true, 'error' => null],
            ['skill' => 'x', 'name' => 'b', 'pass' => false, 'error' => 'boom'],
        ]);
        $this->assertSame(50.0, $sum['x']['score']);
        $this->assertSame(['b (boom)'], $sum['x']['failures']);

        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec("CREATE TABLE agent_eval_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, skill TEXT, version INT, cases INT, passed INT, score REAL, failures_json TEXT, run_at TEXT)");
        $this->assertSame(1, AgentEvals::record($db, $sum));
        AgentEvals::record($db, ['x' => ['cases' => 2, 'passed' => 2, 'score' => 100.0, 'failures' => []]]);
        $this->assertSame(100.0, AgentEvals::latest($db)['x']['score'], 'latest run wins');
        $this->assertSame(0, AgentEvals::record(new PDO('sqlite::memory:'), $sum), 'a missing table never throws');
    }
}
