<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class TaskCreateServiceTest extends TestCase
{
    private PDO $db;
    private TaskCreateService $svc;
    private array $owner = ['id' => 1, 'role' => 'admin', 'name' => 'Owner'];
    private array $crew = ['id' => 2, 'role' => 'crew', 'name' => 'Crew'];

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE tasks (id INTEGER PRIMARY KEY, title TEXT, description TEXT, due_date TEXT, priority TEXT,
            assigned_to INT, contact_id INT, property_id INT, created_by INT, task_type TEXT)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT, is_active INT)");
        $this->db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY)");
        $this->db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY)");
        $this->db->exec("INSERT INTO users VALUES (1,'Sample Owner',1),(2,'Sample Crew',1),(3,'Gone',0)");
        $this->db->exec("INSERT INTO contacts VALUES (5)");
        $this->svc = new TaskCreateService($this->db);
    }

    private function row(int $id): array
    {
        return $this->db->query("SELECT * FROM tasks WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    }

    public function test_a_task_for_the_business_has_no_client_and_goes_to_its_maker(): void
    {
        $r = $this->svc->create(['title' => '  Pick up salt ', 'due_date' => '2026-11-09'], $this->crew);
        $this->assertTrue($r['success']);
        $t = $this->row($r['task_id']);
        $this->assertSame('Pick up salt', $t['title']);
        $this->assertNull($t['contact_id']);
        $this->assertSame(2, (int)$t['assigned_to']);
        $this->assertSame(2, (int)$t['created_by']);
        $this->assertSame('general', $t['task_type']);
        $this->assertSame('normal', $t['priority']);
    }

    public function test_a_task_for_a_client(): void
    {
        $r = $this->svc->create(['title' => 'Call about the hedge', 'contact_id' => 5, 'assigned_to' => 2], $this->owner);
        $t = $this->row($r['task_id']);
        $this->assertSame(5, (int)$t['contact_id']);
        $this->assertSame(2, (int)$t['assigned_to']);
    }

    public function test_refusals_say_why(): void
    {
        $this->assertSame('A task needs a title.', $this->svc->create(['title' => '  '], $this->owner)['error']);
        $this->assertSame('The due date could not be read.', $this->svc->create(['title' => 'x', 'due_date' => '2026-13-40'], $this->owner)['error']);
        $this->assertSame('That client could not be found.', $this->svc->create(['title' => 'x', 'contact_id' => 99], $this->owner)['error']);
        $crew = $this->svc->create(['title' => 'x', 'assigned_to' => 1], $this->crew);
        $this->assertSame(403, $crew['status']);
    }

    public function test_directory_lists_active_clients_with_their_properties(): void
    {
        $this->db->exec("DROP TABLE contacts");
        $this->db->exec("DROP TABLE properties");
        $this->db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, is_active INT)");
        $this->db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT, city TEXT, site_contact_id INT, status TEXT)");
        $this->db->exec("INSERT INTO contacts VALUES (5,'Sample','Client',1),(6,'Gone','Client',0)");
        $this->db->exec("INSERT INTO properties VALUES (9,'12 Oak St','Vancouver',5,'active'),(10,'1 Old Rd','',5,'inactive'),(11,'2 Elm','',6,'active')");
        $this->assertSame([['contact_id' => 5, 'name' => 'Sample Client', 'property_id' => 9, 'address' => '12 Oak St, Vancouver']], $this->svc->directory());
    }

    public function test_crew_are_offered_only_themselves(): void
    {
        $this->assertSame([['id' => 2, 'name' => 'Crew']], $this->svc->people($this->crew));
        $this->assertSame(['Sample Crew', 'Sample Owner'], array_column($this->svc->people($this->owner), 'name'));
    }
}
