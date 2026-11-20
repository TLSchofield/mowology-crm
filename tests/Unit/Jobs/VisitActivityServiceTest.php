<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class VisitActivityServiceTest extends TestCase
{
    public function testTotalMinutesIgnoresVoid(): void
    {
        $entries = [
            ['duration_minutes' => 30, 'status' => 'completed'],
            ['duration_minutes' => 45, 'status' => 'edited'],
            ['duration_minutes' => 99, 'status' => 'void'],
        ];
        $this->assertSame(75, VisitActivityService::totalMinutes($entries));
    }

    public function testWindowPadsEntryTimes(): void
    {
        $entries = [
            ['start_time' => '2026-11-19 10:00:00', 'end_time' => '2026-11-19 11:00:00', 'status' => 'completed'],
            ['start_time' => '2026-11-19 10:05:00', 'end_time' => '2026-11-19 11:30:00', 'status' => 'completed'],
        ];
        [$s, $e] = VisitActivityService::window($entries, null, null);
        $this->assertSame('2026-11-19 09:45:00', $s);
        $this->assertSame('2026-11-19 11:45:00', $e);
    }

    public function testWindowFallsBackToVisitTimes(): void
    {
        [$s, $e] = VisitActivityService::window([], '2026-11-19 14:00:00', '2026-11-19 14:40:00');
        $this->assertSame('2026-11-19 13:45:00', $s);
        $this->assertSame('2026-11-19 14:55:00', $e);
    }

    public function testWindowEmptyWhenNothingKnown(): void
    {
        $this->assertSame([null, null], VisitActivityService::window([], null, null));
    }

    public function testTruckPingsEmptyWithoutWindow(): void
    {
        $db = $this->createMock(PDO::class);
        $r = VisitActivityService::truckPings($db, null, null);
        $this->assertSame(0, $r['count']);
    }

    public function testTruckPingsSummarises(): void
    {
        $st = $this->createMock(PDOStatement::class);
        $st->method('execute')->willReturn(true);
        $st->method('fetchAll')->willReturn([
            ['lat' => '49.1', 'lng' => '-123.1', 'speed_kph' => '10.0', 'recorded_at' => '2026-11-19 10:00:00'],
            ['lat' => '49.1', 'lng' => '-123.1', 'speed_kph' => '42.5', 'recorded_at' => '2026-11-19 10:10:00'],
        ]);
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($st);
        $r = VisitActivityService::truckPings($db, '2026-11-19 09:45:00', '2026-11-19 11:00:00');
        $this->assertSame(2, $r['count']);
        $this->assertSame(42.5, $r['max_kph']);
        $this->assertSame('2026-11-19 10:10:00', $r['last']);
    }

    public function testQueriesFailSoft(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willThrowException(new PDOException('no table'));
        $this->assertSame([], VisitActivityService::timeEntries($db, 1));
        $this->assertNull(VisitActivityService::invoice($db, 1));
        $this->assertSame([], VisitActivityService::emails($db, 1));
        $this->assertSame(0, VisitActivityService::truckPings($db, 'a', 'b')['count']);
    }
}
