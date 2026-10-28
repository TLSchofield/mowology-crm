<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The durable task layer (Ittai audit 2026-10-27, stage 4). What must hold: a task is never created twice,
 * claimed by one owner only, retried a bounded number of times, never delegated in a loop or too deep,
 * expired when old, and its whole history is kept — across a "restart" (a new TaskService on the same DB).
 */
class TaskServiceTest extends TestCase
{
    private PDO $db;
    private string $now = '2026-10-28 09:00:00';

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        TenantContext::reset();
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)");
        $this->db->exec("CREATE TABLE agent_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT DEFAULT 1, kind TEXT, owner_head TEXT, origin_head TEXT DEFAULT 'system',
            entity_type TEXT DEFAULT '', entity_id TEXT DEFAULT '', title TEXT DEFAULT '', priority TEXT DEFAULT 'normal', state TEXT DEFAULT 'open',
            attempts INT DEFAULT 0, max_attempts INT DEFAULT 3, escalated INT DEFAULT 0, due_at TEXT, expires_at TEXT, claimed_at TEXT, completed_at TEXT,
            correlation_id TEXT, parent_task_id INT, depth INT DEFAULT 0, dedupe_key TEXT NOT NULL UNIQUE, payload_json TEXT, result_json TEXT, error TEXT,
            created_at TEXT, updated_at TEXT)");
        $this->db->exec("CREATE TABLE agent_task_events (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INT, event TEXT, from_state TEXT, to_state TEXT, head TEXT, note TEXT, created_at TEXT)");
    }

    private function svc(): TaskService
    {
        return new TaskService($this->db, fn() => $this->now);
    }

    private function mk(array $more = []): array
    {
        $r = $this->svc()->create($more + ['kind' => 'invoice.overdue', 'owner_head' => 'penny', 'entity_type' => 'invoice', 'entity_id' => '42', 'title' => 'Chase INV-42']);
        $this->assertTrue($r['ok'], (string)($r['error'] ?? ''));
        return $r['task'];
    }

    public function testCreateIsIdempotent(): void
    {
        $a = $this->svc()->create(['kind' => 'invoice.overdue', 'owner_head' => 'penny', 'entity_type' => 'invoice', 'entity_id' => '42']);
        $b = $this->svc()->create(['kind' => 'invoice.overdue', 'owner_head' => 'penny', 'entity_type' => 'invoice', 'entity_id' => '42']);
        $this->assertTrue($a['created']);
        $this->assertFalse($b['created']);
        $this->assertSame($a['task']['id'], $b['task']['id']);
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM agent_tasks")->fetchColumn());
    }

    public function testRequiresKindAndOwner(): void
    {
        $this->assertFalse($this->svc()->create(['kind' => 'x'])['ok']);
        $this->assertFalse($this->svc()->create(['owner_head' => 'penny'])['ok']);
    }

    public function testClaimIsAtomicAndOwnerOnly(): void
    {
        $t = $this->mk();
        $this->assertFalse($this->svc()->claim((int)$t['id'], 'otto'), 'only the owner head claims');
        $this->assertTrue($this->svc()->claim((int)$t['id'], 'penny'));
        $this->assertFalse($this->svc()->claim((int)$t['id'], 'penny'), 'a second claim loses');
        $row = $this->svc()->get((int)$t['id']);
        $this->assertSame('working', $row['state']);
        $this->assertSame(1, (int)$row['attempts']);
    }

    public function testCompleteAndHistory(): void
    {
        $t = $this->mk();
        $this->svc()->claim((int)$t['id'], 'penny');
        $this->assertTrue($this->svc()->complete((int)$t['id'], 'penny', ['reminded' => true]));
        $this->assertFalse($this->svc()->complete((int)$t['id'], 'penny'), 'already done');
        $events = array_column($this->svc()->events((int)$t['id']), 'event');
        $this->assertSame(['created', 'claimed', 'completed'], $events);
        $this->assertSame('done', $this->svc()->get((int)$t['id'])['state']);
    }

    public function testRetriesAreBoundedThenEscalated(): void
    {
        $t = $this->mk(['max_attempts' => 2]);
        $id = (int)$t['id'];
        $this->svc()->claim($id, 'penny');
        $this->assertSame('open', $this->svc()->fail($id, 'penny', 'smtp down'), 'first failure: another try');
        $this->svc()->claim($id, 'penny');
        $this->assertSame('failed', $this->svc()->fail($id, 'penny', 'smtp down'), 'attempts used up');
        $row = $this->svc()->get($id);
        $this->assertSame(1, (int)$row['escalated']);
        $this->assertFalse($this->svc()->claim($id, 'penny'), 'a failed task is not claimed again');
        $this->assertContains($id, array_map('intval', array_column($this->svc()->escalations(), 'id')));
    }

    public function testExpireAndStuckReopen(): void
    {
        $t = $this->mk(['ttl_days' => 1]);
        $id = (int)$t['id'];
        $this->now = '2026-10-30 09:00:00';
        $this->assertSame(1, $this->svc()->expire());
        $this->assertSame('expired', $this->svc()->get($id)['state']);

        $this->now = '2026-10-28 09:00:00';
        $s = $this->mk(['entity_id' => '43']);
        $this->svc()->claim((int)$s['id'], 'penny');
        $this->now = '2026-10-28 09:45:00';
        $this->assertSame(1, $this->svc()->reapStuck());
        $this->assertSame('open', $this->svc()->get((int)$s['id'])['state'], 'a stuck task goes back for another attempt');
    }

    public function testCloseResolvedOnlyClosesOpenTasksNoLongerListed(): void
    {
        $a = $this->mk(['entity_id' => '1', 'dedupe_key' => 'invoice.overdue:1']);
        $b = $this->mk(['entity_id' => '2', 'dedupe_key' => 'invoice.overdue:2']);
        $c = $this->mk(['entity_id' => '3', 'dedupe_key' => 'invoice.overdue:3']);
        $this->svc()->claim((int)$c['id'], 'penny');   // being worked on
        $closed = $this->svc()->closeResolved('invoice.overdue', ['invoice.overdue:1']);
        $this->assertSame(1, $closed);
        $this->assertSame('open', $this->svc()->get((int)$a['id'])['state']);
        $this->assertSame('cancelled', $this->svc()->get((int)$b['id'])['state']);
        $this->assertSame('working', $this->svc()->get((int)$c['id'])['state'], 'work in progress is left alone');
    }

    public function testDelegationDepthAndLoopsAreRefused(): void
    {
        $root = $this->mk(['kind' => 'reply.unclaimed', 'owner_head' => 'yui', 'entity_id' => 'r1']);
        $d1 = $this->svc()->create(['kind' => 'schedule.check', 'owner_head' => 'otto', 'entity_id' => 'r1', 'parent_task_id' => (int)$root['id']]);
        $this->assertTrue($d1['ok']);
        $this->assertSame(1, (int)$d1['task']['depth']);
        $this->assertSame($root['correlation_id'], $d1['task']['correlation_id'], 'the chain shares one correlation id');

        // Handing it back to Yui for the same kind is a loop.
        $loop = $this->svc()->create(['kind' => 'reply.unclaimed', 'owner_head' => 'yui', 'entity_id' => 'r1b', 'parent_task_id' => (int)$d1['task']['id']]);
        $this->assertFalse($loop['ok']);
        $this->assertStringContainsString('loop', $loop['error']);

        $d2 = $this->svc()->create(['kind' => 'quote.draft', 'owner_head' => 'sam', 'entity_id' => 'r1', 'parent_task_id' => (int)$d1['task']['id']]);
        $d3 = $this->svc()->create(['kind' => 'invoice.draft', 'owner_head' => 'penny', 'entity_id' => 'r1', 'parent_task_id' => (int)$d2['task']['id']]);
        $this->assertTrue($d3['ok']);
        $d4 = $this->svc()->create(['kind' => 'note.add', 'owner_head' => 'mia', 'entity_id' => 'r1', 'parent_task_id' => (int)$d3['task']['id']]);
        $this->assertFalse($d4['ok'], 'depth 4 is too deep');
        $this->assertStringContainsString('too deep', $d4['error']);
    }

    public function testEscalateRaisesPriorityAndFlags(): void
    {
        $t = $this->mk();
        $this->assertTrue($this->svc()->escalate((int)$t['id'], 'penny', 'client disputes the amount'));
        $row = $this->svc()->get((int)$t['id']);
        $this->assertSame('high', $row['priority']);
        $this->assertSame(1, (int)$row['escalated']);
    }

    public function testSurvivesARestart(): void
    {
        $t = $this->mk();
        $this->svc()->claim((int)$t['id'], 'penny');
        $fresh = new TaskService($this->db, fn() => $this->now);   // a new process, same database
        $this->assertSame('working', $fresh->get((int)$t['id'])['state']);
        $this->assertCount(1, $fresh->openFor('penny'));
        $this->assertSame(['penny' => ['working' => 1]], $fresh->summary());
    }

    public function testOverdueOpenTasksAreEscalations(): void
    {
        $this->mk(['due_at' => '2026-10-27 09:00:00', 'entity_id' => 'late']);
        $this->mk(['due_at' => '2026-10-29 09:00:00', 'entity_id' => 'later']);
        $esc = $this->svc()->escalations();
        $this->assertCount(1, $esc);
        $this->assertSame('late', $esc[0]['entity_id']);
    }
}
