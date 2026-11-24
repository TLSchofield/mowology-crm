<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Desk items become tasks one-for-one, run twice creates nothing new, and leaving the desk's list closes the task. */
class AgentDetectorsTest extends TestCase
{
    private const NOW = '2026-10-28 09:00:00';

    public function testItemsMapToTasks(): void
    {
        $specs = AgentDetectors::tasksFromItems('invoice.overdue', [
            ['key' => 'arrears:12', 'kind' => 'arrears', 'text' => 'Acme Strata owes $2,400.00 on 3 invoices', 'value' => 2400.0, 'since' => '2026-07-01', 'url' => '/crm/x'],
            ['key' => '', 'text' => 'no key, skipped'],
            ['key' => 'arrears:13', 'name' => 'Small Client', 'value' => 80.0, 'days' => 65],
        ], self::NOW);
        $this->assertCount(2, $specs);
        $this->assertSame('penny', $specs[0]['owner_head']);
        $this->assertSame('invoice.overdue:arrears:12', $specs[0]['dedupe_key']);
        $this->assertSame('high', $specs[0]['priority'], 'large and old');
        $this->assertSame('high', $specs[1]['priority'], '65 days overdue');
        $this->assertStringStartsWith('Chase Small Client', $specs[1]['title']);
    }

    public function testKindsRouteToTheRightHead(): void
    {
        $this->assertSame('sam', AgentDetectors::tasksFromItems('quote.stale', [['key' => 'k']], self::NOW)[0]['owner_head']);
        $this->assertSame('yui', AgentDetectors::tasksFromItems('reply.unclaimed', [['key' => 'k']], self::NOW)[0]['owner_head']);
    }

    private function tasks(): TaskService
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)");
        $db->exec("CREATE TABLE agent_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT DEFAULT 1, kind TEXT, owner_head TEXT, origin_head TEXT DEFAULT 'system',
            entity_type TEXT DEFAULT '', entity_id TEXT DEFAULT '', title TEXT DEFAULT '', priority TEXT DEFAULT 'normal', state TEXT DEFAULT 'open',
            attempts INT DEFAULT 0, max_attempts INT DEFAULT 3, escalated INT DEFAULT 0, due_at TEXT, expires_at TEXT, claimed_at TEXT, completed_at TEXT,
            correlation_id TEXT, parent_task_id INT, depth INT DEFAULT 0, dedupe_key TEXT NOT NULL, payload_json TEXT, result_json TEXT, error TEXT,
            created_at TEXT, updated_at TEXT, UNIQUE (site_id, dedupe_key))");
        $db->exec("CREATE TABLE agent_task_events (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INT, event TEXT, from_state TEXT, to_state TEXT, head TEXT, note TEXT, created_at TEXT)");
        return new TaskService($db, fn() => self::NOW);
    }

    public function testRunningTwiceCreatesNothingNewAndLeavingTheListCloses(): void
    {
        $tasks = $this->tasks();
        $items = [['key' => 'q:1', 'name' => 'Ann', 'amount' => 500.0, 'days' => 6], ['key' => 'q:2', 'name' => 'Bob', 'amount' => 90.0, 'days' => 8]];

        $first = AgentDetectors::sync($tasks, 'quote.stale', $items, self::NOW);
        $this->assertSame(['seen' => 2, 'created' => 2, 'closed' => 0], $first);

        $second = AgentDetectors::sync($tasks, 'quote.stale', $items, self::NOW);
        $this->assertSame(['seen' => 2, 'created' => 0, 'closed' => 0], $second, 'a second run is a no-op');

        $third = AgentDetectors::sync($tasks, 'quote.stale', [$items[0]], self::NOW);   // Bob replied / was won
        $this->assertSame(['seen' => 1, 'created' => 0, 'closed' => 1], $third);
        $this->assertSame('cancelled', $tasks->byDedupe('quote.stale:q:2')['state']);
        $this->assertSame('open', $tasks->byDedupe('quote.stale:q:1')['state']);
    }
}
