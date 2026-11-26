<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class TaskScheduleServiceTest extends TestCase
{
    private PDO $db;
    private TaskScheduleService $svc;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE tasks (id INTEGER PRIMARY KEY, title TEXT, description TEXT, due_date TEXT, due_time TEXT,
            priority TEXT, status TEXT, assigned_to INT, contact_id INT, property_id INT, task_type TEXT)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT)");
        $this->db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, phone TEXT)");
        $this->db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT, city TEXT, latitude REAL, longitude REAL)");
        $this->db->exec("INSERT INTO users VALUES (1,'Owner'),(2,'Crew')");
        $this->db->exec("INSERT INTO contacts VALUES (5,'Ann','Lee','604-555-0100')");
        $this->db->exec("INSERT INTO properties VALUES (7,'1 Oak St','Gibsons',0,0)");
        $this->svc = new TaskScheduleService($this->db);
    }

    private function add(string $title, ?string $due, string $status = 'pending', int $to = 2, string $type = 'general', ?string $time = null, string $prio = 'normal', ?int $contact = null): void
    {
        $s = $this->db->prepare("INSERT INTO tasks (title, due_date, due_time, priority, status, assigned_to, contact_id, property_id, task_type) VALUES (?,?,?,?,?,?,?,?,?)");
        $s->execute([$title, $due, $time, $prio, $status, $to, $contact, $contact ? 7 : null, $type]);
    }

    private function titles(array $rows): array
    {
        return array_column($rows, 'title');
    }

    public function test_due_that_day_shows_and_other_days_do_not(): void
    {
        $this->add('today', '2026-11-26');
        $this->add('tomorrow', '2026-11-27');
        $this->assertSame(['today'], $this->titles($this->svc->forDay('2026-11-26', null, '2026-11-26')));
        $this->assertSame(['tomorrow'], $this->titles($this->svc->forDay('2026-11-27', null, '2026-11-26')));
    }

    public function test_overdue_shows_on_today_only_and_is_marked(): void
    {
        $this->add('late', '2026-11-23');
        $today = $this->svc->forDay('2026-11-26', null, '2026-11-26');
        $this->assertSame(['late'], $this->titles($today));
        $this->assertTrue($today[0]['overdue']);
        $this->assertSame(3, $today[0]['days_overdue']);
        $this->assertSame([], $this->svc->forDay('2026-11-27', null, '2026-11-26'));
        $this->assertSame(['late'], $this->titles($this->svc->forDay('2026-11-23', null, '2026-11-26')));
    }

    public function test_completed_undated_and_purchase_tasks_are_hidden(): void
    {
        $this->add('done', '2026-11-26', 'completed');
        $this->add('undated', null);
        $this->add('buy', '2026-11-26', 'pending', 2, 'purchase');
        $this->add('in progress', '2026-11-26', 'in_progress');
        $this->assertSame(['in progress'], $this->titles($this->svc->forDay('2026-11-26', null, '2026-11-26')));
    }

    public function test_people_filter(): void
    {
        $this->add('mine', '2026-11-26', 'pending', 2);
        $this->add('theirs', '2026-11-26', 'pending', 1);
        $this->assertSame(['mine'], $this->titles($this->svc->forDay('2026-11-26', [2], '2026-11-26')));
        $this->assertCount(2, $this->svc->forDay('2026-11-26', null, '2026-11-26'));
        $this->assertSame([], $this->svc->forDay('2026-11-26', [], '2026-11-26'));   // empty list = nobody
    }

    public function test_order_overdue_first_then_time_then_priority(): void
    {
        $this->add('late', '2026-11-20');
        $this->add('pm', '2026-11-26', 'pending', 2, 'general', '14:00:00');
        $this->add('am', '2026-11-26', 'pending', 2, 'general', '09:00:00');
        $this->add('untimed low', '2026-11-26', 'pending', 2, 'general', null, 'low');
        $this->add('untimed high', '2026-11-26', 'pending', 2, 'general', null, 'high');
        $this->assertSame(['late', 'am', 'pm', 'untimed high', 'untimed low'], $this->titles($this->svc->forDay('2026-11-26', null, '2026-11-26')));
    }

    public function test_client_and_address_and_failed_geocode(): void
    {
        $this->add('with client', '2026-11-26', 'pending', 2, 'general', '09:30:00', 'normal', 5);
        $t = $this->svc->forDay('2026-11-26', null, '2026-11-26')[0];
        $this->assertSame('Ann Lee', $t['contact_name']);
        $this->assertSame('1 Oak St', $t['property_address']);
        $this->assertSame('09:30', $t['due_time']);
        $this->assertNull($t['latitude']);   // 0,0 is no location
    }

    public function test_visibleOn_rule(): void
    {
        $this->assertTrue(TaskScheduleService::visibleOn('2026-11-26', 'pending', 'general', '2026-11-26', '2026-11-26'));
        $this->assertFalse(TaskScheduleService::visibleOn('2026-11-20', 'pending', 'general', '2026-11-27', '2026-11-26'));
        $this->assertFalse(TaskScheduleService::visibleOn('', 'pending', 'general', '2026-11-26', '2026-11-26'));
    }
}
