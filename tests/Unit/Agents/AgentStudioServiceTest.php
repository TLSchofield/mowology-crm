<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The Studio reads the stage tables and never fails: empty before the migrations, correct after. */
class AgentStudioServiceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        TenantContext::reset();
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)");
    }

    public function testEverythingIsEmptyNotBrokenBeforeTheMigrations(): void
    {
        $s = new AgentStudioService($this->db);
        $this->assertSame(array_fill_keys(['Cost log (1400)', 'Activity ledger (1401)', 'Tasks (1402)', 'Eval results (1403)'], false), $s->readiness());
        $o = $s->overview();
        $this->assertCount(7, $o);
        $this->assertSame(0, $o['penny']['open_tasks']);
        $this->assertSame([], $s->activity('penny'));
        $this->assertSame([], $s->cost('penny'));
        $this->assertSame([], $s->costByDay());
        $this->assertSame([], $s->evals());
        $this->assertSame([], $s->openTasks('penny'));
        $this->assertFalse($s->switches()['kill_all']);
    }

    public function testSwitchesAndAutonomyRows(): void
    {
        $this->db->exec("INSERT INTO ops_settings (setting_key, setting_value) VALUES ('agents_kill_yui', '1'), ('agents_policy_enforce', '1')");
        $s = new AgentStudioService($this->db);
        $sw = $s->switches();
        $this->assertTrue($sw['enforcing']);
        $this->assertSame(['yui'], $sw['killed_heads']);
        $rows = $s->autonomy('penny');
        $byAction = array_column($rows, null, 'action');
        $this->assertSame(2, $byAction['penny.approve_receipt']['level']);
        $this->assertSame(3, $byAction['penny.bank_rule_apply']['level']);
        $this->assertSame([], $s->autonomy('ada'), 'Ada has no actions that change anything');
    }

    public function testCostActivityTasksAndEvalsAreReadBack(): void
    {
        $this->db->exec("CREATE TABLE llm_calls (id INTEGER PRIMARY KEY, site_id INT, head TEXT, skill TEXT, model TEXT, input_tokens INT, output_tokens INT, cache_read_tokens INT, cache_write_tokens INT, cost_usd REAL, http_code INT, outcome TEXT, attempts INT, duration_ms INT, error TEXT, created_at TEXT)");
        $now = date('Y-m-d H:i:s');
        $ins = $this->db->prepare("INSERT INTO llm_calls (head, skill, model, input_tokens, output_tokens, cost_usd, outcome, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute(['penny', 'bank_guidance', 'claude-sonnet-5-5', 1000, 200, 0.006, 'ok', $now]);
        $ins->execute(['penny', 'bank_guidance', 'claude-sonnet-5-5', 1000, 200, 0.006, 'error', $now]);
        $ins->execute(['charlie', 'ask', 'claude-sonnet-5-5', 500, 100, 0.003, 'ok', $now]);
        $this->db->exec("CREATE TABLE agent_eval_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, skill TEXT, version INT, cases INT, passed INT, score REAL, failures_json TEXT, run_at TEXT)");
        AgentEvals::record($this->db, ['yui.classify' => ['cases' => 7, 'passed' => 7, 'score' => 100.0, 'failures' => []]]);

        $s = new AgentStudioService($this->db);
        $cost = $s->cost('penny');
        $this->assertCount(1, $cost);
        $this->assertSame(2, (int)$cost[0]['calls']);
        $this->assertSame(1, (int)$cost[0]['problems']);
        $this->assertEqualsWithDelta(0.012, (float)$cost[0]['usd'], 1e-9);
        $o = $s->overview();
        $this->assertSame(2, $o['penny']['calls']);
        $this->assertSame(1, $o['charlie']['calls']);
        $this->assertEqualsWithDelta(0.012, $o['penny']['cost_usd'], 1e-9);
        $this->assertSame(100.0, $s->evals()['yui.classify']['score']);
        $this->assertSame(array_sum($s->costByDay()), 0.015);
    }
}
