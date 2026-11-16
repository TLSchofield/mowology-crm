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

    public function test_grants_every_active_admin_only(): void
    {
        $this->user(1, 'admin', 0);
        $this->user(2, 'admin');
        $this->user(3, 'admin');
        $this->user(4, 'user');
        $this->user(5, 'manager');
        $this->user(6, 'accountant');
        $svc = new OwnerApprovalDefaultService($this->db);
        $svc->grantToAdmins();
        $this->assertSame([0, 1, 1, 0, 0, 0], array_map([$this, 'flag'], [1, 2, 3, 4, 5, 6]));
        $svc->grantToAdmins(); // idempotent
        $this->assertSame([0, 1, 1, 0, 0, 0], array_map([$this, 'flag'], [1, 2, 3, 4, 5, 6]));
    }

    public function test_new_user_default(): void
    {
        $svc = new OwnerApprovalDefaultService($this->db);
        $this->assertSame(1, $svc->defaultForNewUser('admin'));
        foreach (['manager', 'user', 'accountant'] as $r) {
            $this->assertSame(0, $svc->defaultForNewUser($r));
        }
    }
}
