<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Autonomy per action, the kill switch, and the activity ledger (Ittai audit 2026-10-27, stage 3).
 * The properties that matter: the kill switch always works; level rules only bite when enforcing;
 * a level-2 action needs a person; money actions cannot be raised past PREPARE; the ledger never
 * breaks the action it records.
 */
class AgentPolicyActivityTest extends TestCase
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
        $this->db->exec("CREATE TABLE agent_action_policy (id INTEGER PRIMARY KEY, head TEXT, action_key TEXT, level INT DEFAULT 2, enabled INT DEFAULT 1, limits_json TEXT, updated_by INT, updated_at TEXT)");
        $this->db->exec("CREATE TABLE agent_activity (id INTEGER PRIMARY KEY, site_id INT, head TEXT, skill TEXT, action_key TEXT, entity_type TEXT, entity_id TEXT,
            decision TEXT, level_required INT, approver_user_id INT, result TEXT, reversible INT, cost_usd REAL, correlation_id TEXT, shadow INT, created_at TEXT)");
    }

    private function set(string $k, string $v): void
    {
        $this->db->prepare("REPLACE INTO ops_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]);
    }

    private function policy(string $head, string $action, int $level, int $enabled = 1): void
    {
        $this->db->prepare("INSERT INTO agent_action_policy (head, action_key, level, enabled) VALUES (?, ?, ?, ?)")->execute([$head, $action, $level, $enabled]);
    }

    private function ledger(): array
    {
        return $this->db->query("SELECT * FROM agent_activity ORDER BY id")->fetchAll();
    }

    // ── levels ──────────────────────────────────────────────────────────────

    public function testDefaultsAreTodaysBehaviour(): void
    {
        $this->assertSame(2, AgentPolicy::level($this->db, 'yui', 'yui.send_reply'));
        $this->assertSame(3, AgentPolicy::level($this->db, 'penny', 'penny.bank_rule_apply'));
        $this->assertSame(2, AgentPolicy::level($this->db, 'anyone', 'unknown.action'), 'an unknown action is level 2, never higher');
    }

    public function testOverrideRowAppliesPerAction(): void
    {
        $this->policy('sam', 'sam.send_followup', 3);
        $this->assertSame(3, AgentPolicy::level($this->db, 'sam', 'sam.send_followup'));
        $this->assertSame(2, AgentPolicy::level($this->db, 'sam', 'sam.accept_quote'), 'authorising one action grants nothing else');
    }

    public function testMoneyActionsCannotBeRaisedPastPrepare(): void
    {
        $this->policy('penny', 'penny.approve_receipt', 4);
        $this->assertSame(2, AgentPolicy::level($this->db, 'penny', 'penny.approve_receipt'));
    }

    // ── check(): shadow vs enforce ──────────────────────────────────────────

    public function testLevelTwoWithoutApprovalIsShadowAllowedUntilEnforcing(): void
    {
        $r = AgentPolicy::check($this->db, 'yui', 'yui.send_reply', false);
        $this->assertTrue($r['allow']);
        $this->assertTrue($r['shadow']);
        $this->assertStringContainsString('needs a person', $r['reason']);

        $this->set('agents_policy_enforce', '1');
        $r = AgentPolicy::check($this->db, 'yui', 'yui.send_reply', false);
        $this->assertFalse($r['allow']);
        $this->assertFalse($r['shadow']);
    }

    public function testApprovedLevelTwoIsAllowedEvenWhenEnforcing(): void
    {
        $this->set('agents_policy_enforce', '1');
        $r = AgentPolicy::check($this->db, 'yui', 'yui.send_reply', true);
        $this->assertTrue($r['allow']);
        $this->assertSame('', $r['reason']);
    }

    public function testObserveAndRecommendCannotExecuteWhenEnforcing(): void
    {
        $this->set('agents_policy_enforce', '1');
        foreach ([0, 1] as $lvl) {
            $this->db->exec("DELETE FROM agent_action_policy");
            $this->policy('otto', 'otto.apply_suggestion', $lvl);
            $this->assertFalse(AgentPolicy::check($this->db, 'otto', 'otto.apply_suggestion', true)['allow'], "level $lvl");
        }
    }

    public function testDisabledActionBlocksWhenEnforcing(): void
    {
        $this->policy('mia', 'mia.approve_campaign', 2, 0);
        $this->assertTrue(AgentPolicy::check($this->db, 'mia', 'mia.approve_campaign', true)['allow'], 'shadow: nothing blocked yet');
        $this->set('agents_policy_enforce', '1');
        $this->assertFalse(AgentPolicy::check($this->db, 'mia', 'mia.approve_campaign', true)['allow']);
    }

    public function testBankRuleLevelThreeNeedsNoApproval(): void
    {
        $this->set('agents_policy_enforce', '1');
        $this->assertTrue(AgentPolicy::check($this->db, 'penny', 'penny.bank_rule_apply', false)['allow']);
    }

    // ── kill switch ─────────────────────────────────────────────────────────

    public function testKillSwitchAlwaysBlocksEvenWhenNotEnforcing(): void
    {
        $this->set('agents_kill_yui', '1');
        $this->assertFalse(AgentPolicy::check($this->db, 'yui', 'yui.send_reply', true)['allow']);
        $this->assertTrue(AgentPolicy::check($this->db, 'sam', 'sam.send_followup', true)['allow'], 'other heads unaffected');
        $this->set('agents_kill_all', '1');
        $this->assertFalse(AgentPolicy::check($this->db, 'sam', 'sam.send_followup', true)['allow']);
    }

    // ── run() and the ledger ────────────────────────────────────────────────

    private function meta(array $more = []): array
    {
        return $more + ['head' => 'yui', 'action' => 'yui.send_reply', 'skill' => 'send_reply', 'entity_type' => 'yui_item',
                        'entity_id' => 'k1', 'user_id' => 9, 'approved' => true, 'reversible' => false];
    }

    public function testRunRecordsAnExecutedActionWithItsApprover(): void
    {
        $res = AgentActivity::run($this->db, $this->meta(), fn() => ['ok' => true, 'message' => 'Sent']);
        $this->assertSame(['ok' => true, 'message' => 'Sent'], $res, 'the action\'s own result is returned untouched');
        $rows = $this->ledger();
        $this->assertCount(1, $rows);
        $this->assertSame('executed', $rows[0]['decision']);
        $this->assertSame(9, (int)$rows[0]['approver_user_id']);
        $this->assertSame('yui', $rows[0]['head']);
        $this->assertSame('k1', $rows[0]['entity_id']);
        $this->assertSame(0, (int)$rows[0]['reversible']);
        $this->assertSame(0, (int)$rows[0]['shadow']);
    }

    public function testRunRecordsFailureFromAnOkFalseResult(): void
    {
        AgentActivity::run($this->db, $this->meta(), fn() => ['ok' => false, 'message' => 'No email on file']);
        $row = $this->ledger()[0];
        $this->assertSame('failed', $row['decision']);
        $this->assertSame('No email on file', $row['result']);
    }

    public function testRunRecordsAndRethrowsAnException(): void
    {
        try {
            AgentActivity::run($this->db, $this->meta(), function () {
                throw new RuntimeException('smtp down');
            });
            $this->fail('should rethrow');
        } catch (RuntimeException $e) {
            $this->assertSame('smtp down', $e->getMessage());
        }
        $this->assertSame('failed', $this->ledger()[0]['decision']);
    }

    public function testKillSwitchStopsTheActionAndIsRecordedAsBlocked(): void
    {
        $this->set('agents_kill_all', '1');
        $ran = false;
        $res = AgentActivity::run($this->db, $this->meta(), function () use (&$ran) {
            $ran = true;
            return ['ok' => true];
        });
        $this->assertFalse($ran);
        $this->assertTrue($res['blocked']);
        $this->assertFalse($res['ok']);
        $this->assertSame('blocked', $this->ledger()[0]['decision']);
    }

    public function testShadowNoteIsRecordedButTheActionStillRuns(): void
    {
        $res = AgentActivity::run($this->db, $this->meta(['approved' => false]), fn() => ['ok' => true, 'message' => 'Sent']);
        $this->assertTrue($res['ok']);
        $row = $this->ledger()[0];
        $this->assertSame('executed', $row['decision']);
        $this->assertSame(1, (int)$row['shadow']);
        $this->assertStringContainsString('[shadow:', (string)$row['result']);
    }

    public function testEnforcedLevelBlockDoesNotRunTheAction(): void
    {
        $this->set('agents_policy_enforce', '1');
        $ran = false;
        $res = AgentActivity::run($this->db, $this->meta(['approved' => false]), function () use (&$ran) {
            $ran = true;
            return ['ok' => true];
        });
        $this->assertFalse($ran);
        $this->assertTrue($res['blocked']);
    }

    public function testLedgerOrPolicyTablesMissingNeverBreakTheAction(): void
    {
        $bare = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $res = AgentActivity::run($bare, $this->meta(), fn() => ['ok' => true, 'message' => 'Sent']);
        $this->assertSame(['ok' => true, 'message' => 'Sent'], $res);
        $this->assertNull(AgentActivity::record($bare, ['head' => 'x', 'action' => 'y']));
    }

    public function testRowsCarryTheConfiguredTenant(): void
    {
        $this->set('tenant_site_id', '5');
        AgentActivity::run($this->db, $this->meta(), fn() => ['ok' => true]);
        $this->assertSame(5, (int)$this->ledger()[0]['site_id']);
    }
}
