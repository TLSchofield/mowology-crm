<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Team/Services/EmployeeAccountService.php';

/** #3 and #11 (Fable audit 2026-10-12). */
final class EmployeeAccountServiceTest extends TestCase
{
    private const CREW = ['email' => 'crew@mowology.ca', 'role' => 'user'];

    private function refusal(array $input, bool $targetAdmin = false, bool $self = false, bool $usersManage = false, bool $callerAdmin = false, array $before = self::CREW): ?string
    {
        return EmployeeAccountService::updateRefusal($before, $input, $targetAdmin, $self, true, $usersManage, $callerAdmin);
    }

    public function testManagerEditsCrewButNotAdmins(): void
    {
        $this->assertNull($this->refusal(['phone' => '604']));
        $this->assertNotNull($this->refusal(['phone' => '604'], true));            // RBAC/legacy admin target
        $this->assertNotNull($this->refusal(['role' => 'admin']));
        $this->assertNull($this->refusal(['role' => 'manager']));
        $this->assertNull($this->refusal(['phone' => '604'], true, false, true, true));
    }

    public function testEmailOfAnotherUserNeedsUsersManage(): void
    {
        $this->assertNotNull($this->refusal(['email' => 'attacker@evil.test']));
        $this->assertNull($this->refusal(['email' => 'CREW@mowology.ca']));        // unchanged
        $this->assertNull($this->refusal(['email' => 'me@new.ca'], false, true));  // own
        $this->assertNull($this->refusal(['email' => 'x@y.ca'], false, false, true));
    }

    public function testPasswordsFollowPasswordChangeRules(): void
    {
        $this->assertNotNull($this->refusal(['password' => 'short8ch']));
        $this->assertNull($this->refusal(['password' => 'twelve chars!']));
        $this->assertNotNull($this->refusal(['password' => 'twelve chars!', 'password_confirm' => 'nope']));
        // Admin target: only an admin caller, even with users.manage
        $this->assertNotNull($this->refusal(['password' => 'twelve chars!'], true, false, true, false));
        $this->assertNull($this->refusal(['password' => 'twelve chars!'], true, false, true, true));
    }

    public function testNoTeamEditNoChange(): void
    {
        $this->assertNotNull(EmployeeAccountService::updateRefusal(self::CREW, ['phone' => '1'], false, false, false, false, false));
    }

    public function testChangeLogBeforeAfter(): void
    {
        $lines = EmployeeAccountService::changeLog(
            ['role' => 'user', 'is_active' => 1, 'email' => 'a@b.ca'],
            ['role' => 'manager', 'is_active' => '0', 'email' => 'A@B.ca'],
            true
        );
        $this->assertSame(['role: user → manager', 'is_active: 1 → 0', 'password: changed'], $lines);
        $this->assertSame([], EmployeeAccountService::changeLog(['role' => 'user'], ['role' => 'user'], false));
    }

    public function testEndpointWiring(): void
    {
        $src = file_get_contents(__DIR__ . '/../../../app/Modules/Team/Api/employees.php');
        $this->assertStringContainsString('EmployeeAccountService::assignRbacRole($db, $newId, $role)', $src);
        $this->assertStringContainsString('EmployeeAccountService::updateRefusal(', $src);
        $this->assertStringContainsString('EmployeeAccountService::isAdminTarget($db, $id)', $src);
        $this->assertStringContainsString('EmployeeAccountService::changeLog(', $src);
        $this->assertStringNotContainsString("strlen(\$input['password']) >= 8", $src);
    }

    public function testLegacyCrewFallbackHasNoBilling(): void
    {
        $src = file_get_contents(__DIR__ . '/../../../app/Core/Auth/authz.php');
        $start = strpos($src, "case 'staff':");
        $end = strpos($src, '];', $start);
        $crew = substr($src, $start, $end - $start);
        $this->assertStringContainsString("case 'user':", $crew);
        $this->assertStringContainsString('default:', $crew);
        foreach (['billing.', 'team.edit', 'users.manage', 'expenses.approve', 'settings.', 'marketing.', 'products.edit', 'clients.edit'] as $office) {
            $this->assertStringNotContainsString($office, $crew, $office);
        }
    }
}
