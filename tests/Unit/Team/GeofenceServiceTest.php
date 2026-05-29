<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class GeofenceServiceTest extends TestCase
{
    private function makeUserStmt(?array $home): PDOStatement
    {
        $s = $this->createMock(PDOStatement::class);
        $s->method('execute')->willReturn(true);
        $s->method('fetch')->willReturn($home ?? false);
        return $s;
    }

    private function makeColStmt(bool $truthy): PDOStatement
    {
        $s = $this->createMock(PDOStatement::class);
        $s->method('execute')->willReturn(true);
        $s->method('fetchColumn')->willReturn($truthy ? 1 : false);
        return $s;
    }

    /**
     * Returns a PDO mock whose `prepare` returns statements in the call order
     * inside shouldSuppressHomePing: home → isClockedIn → hasActiveVisit.
     */
    private function makeDb(?array $home, bool $hasActive = false): PDO
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls(
            $this->makeUserStmt($home),
            $this->makeColStmt($hasActive)
        );
        return $db;
    }

    // Tim's home for fixtures
    private const TIM_LAT = 49.2635;
    private const TIM_LNG = -123.1565;

    public function test_distance_meters_known_pair(): void
    {
        // ~1km north of Tim's home (0.009° latitude ≈ 1km)
        $d = GeofenceService::distanceMeters(self::TIM_LAT, self::TIM_LNG, self::TIM_LAT + 0.009, self::TIM_LNG);
        $this->assertGreaterThan(950, $d);
        $this->assertLessThan(1050, $d);
    }

    public function test_no_home_set_is_not_office(): void
    {
        $svc = new GeofenceService($this->makeDb(null));
        $this->assertFalse($svc->isOfficePing(1, self::TIM_LAT, self::TIM_LNG));
    }

    public function test_ping_inside_radius_no_visit_is_office(): void
    {
        $home = ['home_lat' => self::TIM_LAT, 'home_lng' => self::TIM_LNG, 'home_radius_meters' => 100];
        // ~10m from home, no active visit → office
        $svc  = new GeofenceService($this->makeDb($home, false));
        $this->assertTrue($svc->isOfficePing(1, self::TIM_LAT + 0.00009, self::TIM_LNG));
    }

    public function test_ping_inside_radius_with_active_visit_is_route(): void
    {
        $home = ['home_lat' => self::TIM_LAT, 'home_lng' => self::TIM_LNG, 'home_radius_meters' => 100];
        // a job is in progress → counts as route, not office
        $svc  = new GeofenceService($this->makeDb($home, true));
        $this->assertFalse($svc->isOfficePing(1, self::TIM_LAT + 0.00009, self::TIM_LNG));
    }

    public function test_ping_outside_radius_is_not_office(): void
    {
        $home = ['home_lat' => self::TIM_LAT, 'home_lng' => self::TIM_LNG, 'home_radius_meters' => 100];
        // 0.005° latitude ≈ 555m — well outside 100m radius
        $svc  = new GeofenceService($this->makeDb($home, false));
        $this->assertFalse($svc->isOfficePing(1, self::TIM_LAT + 0.005, self::TIM_LNG));
    }
}
