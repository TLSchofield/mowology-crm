<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Visit-keyed GeofenceApi actions refuse a login that is neither the office nor crew on that visit. */
final class GeofenceApiVisitGuardTest extends TestCase
{
    public function testVisitKeyedActionsAreGuardedBeforeTheSwitch(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/app/Modules/Geofence/Api/GeofenceApi.php');
        $guard = strpos($src, "in_array(\$action, ['resolve', 'compute_session', 'get_session', 'get_zone_sessions', 'compute_zone_sessions']");
        $switch = strpos($src, 'switch ($action) {');
        $this->assertNotFalse($guard);
        $this->assertNotFalse($switch);
        $this->assertLessThan($switch, $guard, 'the guard must run before any action');
        $this->assertStringContainsString('userIsCrewOnVisit($guardVisitId, (int)$user[\'id\'])', $src);
    }
}
