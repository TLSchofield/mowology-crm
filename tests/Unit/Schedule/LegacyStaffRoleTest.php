<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * M2 (Fable audit 2026-11): the legacy users.role value 'staff' is crew (RoleRules::CREW), so the
 * field endpoints must decide office with RoleRules::isOffice() and not a hand-kept list that
 * includes 'staff'. Guards against the list creeping back into these three endpoints.
 */
class LegacyStaffRoleTest extends TestCase
{
    /** @test */
    public function staff_is_crew_not_office(): void
    {
        $this->assertFalse(RoleRules::isOffice('staff'));
        $this->assertTrue(RoleRules::isCrew('staff'));
    }

    /** @test */
    public function field_endpoints_use_role_rules_and_not_a_list_with_staff(): void
    {
        $root = dirname(__DIR__, 3) . '/app/Modules/Schedule/Api/';
        foreach (['recommendation.php', 'job-photo.php', 'plant-id.php'] as $file) {
            $src = (string)file_get_contents($root . $file);
            $this->assertStringContainsString('RoleRules::isOffice(', $src, $file . ' should use RoleRules::isOffice');
            $this->assertDoesNotMatchRegularExpression(
                "/in_array\\([^)]*'staff'/",
                $src,
                $file . ' must not treat the legacy staff role as office'
            );
        }
    }
}
