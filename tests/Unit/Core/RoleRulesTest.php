<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Core/Auth/RoleRules.php';

final class RoleRulesTest extends TestCase
{
    public function testOfficeAndCrew(): void
    {
        foreach (['admin', 'manager', 'Admin', ' manager '] as $r) {
            $this->assertTrue(RoleRules::isOffice($r), $r);
            $this->assertFalse(RoleRules::isCrew($r), $r);
        }
        foreach (['user', 'team_member', 'staff', 'technician', '', null, 'viewer', 'weird'] as $r) {
            $this->assertFalse(RoleRules::isOffice($r), (string)$r);
            $this->assertTrue(RoleRules::isCrew($r), (string)$r);
        }
    }

    public function testRbacRoleFor(): void
    {
        $this->assertSame('admin', RoleRules::rbacRoleFor('admin'));
        $this->assertSame('manager', RoleRules::rbacRoleFor('manager'));
        foreach (['user', 'team_member', 'staff', 'technician', null, ''] as $r) {
            $this->assertSame('staff', RoleRules::rbacRoleFor($r));
        }
    }
}
