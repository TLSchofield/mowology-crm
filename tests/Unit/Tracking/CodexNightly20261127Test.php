<?php
use PHPUnit\Framework\TestCase;

final class CodexNightly20261127Test extends TestCase
{
    public function testWrappedLocationsShapeIsRead(): void
    {
        $rows = [['lat' => 1.0, 'lng' => 2.0]];
        $this->assertSame($rows, TrackimoService::locationList(['locations' => $rows]));
        $this->assertSame($rows, TrackimoService::locationList($rows));
        $this->assertSame([], TrackimoService::locationList([]));
    }

    public function testOnlyTheDedupKeyCountsAsDuplicate(): void
    {
        $dup = $this->sqlstate(new PDOException('Duplicate entry for key uq_device_recorded'));
        $dup->errorInfo = ['23000', 1062, "Duplicate entry 'x' for key 'uq_device_recorded'"];
        $this->assertTrue(TruckLocationService::isDuplicatePing($dup));

        $fk = $this->sqlstate(new PDOException('FK fails'));
        $fk->errorInfo = ['23000', 1452, 'Cannot add or update a child row'];
        $this->assertFalse(TruckLocationService::isDuplicatePing($fk));
    }

    public function testTrailUpperBoundIsNextMidnight(): void
    {
        $this->assertSame('2026-11-28 00:00:00', TruckLocationService::nextDayStart('2026-11-27'));
        $this->assertSame('2027-01-01 00:00:00', TruckLocationService::nextDayStart('2026-12-31'));
    }

    private function sqlstate(PDOException $e): PDOException
    {
        (new ReflectionClass($e))->getProperty('code')->setValue($e, '23000');
        return $e;
    }
}
