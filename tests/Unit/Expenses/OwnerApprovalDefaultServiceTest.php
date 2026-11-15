<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class OwnerApprovalDefaultServiceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, is_active INT, can_approve_own_expenses INT DEFAULT 0)");
    }

    private function user(int $id, string $role, int $active = 1): void
    {
        $this->db->prepare("INSERT INTO users (id, role, is_active) VALUES (?, ?, ?)")->execute([$id, $role, $active]);
    }

    private function flag(int $id): int
    {
        return (int)$this->db->query("SELECT can_approve_own_expenses FROM users WHERE id = $id")->fetchColumn();
    }

    public function test_grants_only_lowest_active_admin(): void
    {
        $this->user(1, 'admin', 0);
        $this->user(2, 'admin');
        $this->user(3, 'admin');
        $this->user(4, 'user');
        $svc = new OwnerApprovalDefaultService($this->db);
        $this->assertSame(2, $svc->grantToOwner());
        $this->assertSame([0, 1, 0, 0], [$this->flag(1), $this->flag(2), $this->flag(3), $this->flag(4)]);
        $this->assertSame(2, $svc->grantToOwner()); // idempotent
        $this->assertSame(1, $this->flag(2));
    }

    public function test_no_admin_changes_nothing(): void
    {
        $this->user(1, 'user');
        $this->assertSame(0, (new OwnerApprovalDefaultService($this->db))->grantToOwner());
        $this->assertSame(0, $this->flag(1));
    }

    public function test_new_user_default(): void
    {
        $svc = new OwnerApprovalDefaultService($this->db);
        $this->assertSame(1, $svc->defaultForNewUser('admin'));   // first admin
        $this->assertSame(0, $svc->defaultForNewUser('user'));
        $this->user(1, 'admin');
        $this->assertSame(0, $svc->defaultForNewUser('admin'));   // second admin stays off
        $this->assertSame(0, $svc->defaultForNewUser('manager'));
    }
}
