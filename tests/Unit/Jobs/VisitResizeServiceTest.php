<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class VisitResizeServiceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->sqliteCreateFunction('NOW', static fn () => '2026-11-10 08:00:00', 0);
        $this->db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, status TEXT, scheduled_date TEXT, scheduled_time_start TEXT,
            scheduled_time_end TEXT, assigned_crew_id INT, stop_id INT, duration_override_minutes INT, updated_at TEXT)");
        $this->db->exec("INSERT INTO job_visits (id, status, scheduled_date, scheduled_time_start, scheduled_time_end, assigned_crew_id) VALUES
            (1,'scheduled','2026-11-12','09:00:00','10:00:00',7),
            (2,'scheduled','2026-11-12','10:30:00','11:30:00',7),
            (3,'completed','2026-11-12','12:00:00','13:00:00',7),
            (4,'scheduled','2026-11-12','09:00:00','10:00:00',8)");
        $p = new ReflectionProperty(VisitResizeService::class, 'hasColumn');
        $p->setAccessible(true);
        $p->setValue(null, true);
    }

    protected function tearDown(): void
    {
        $p = new ReflectionProperty(VisitResizeService::class, 'hasColumn');
        $p->setAccessible(true);
        $p->setValue(null, null);
    }

    public function testNormaliseSnapsToQuarterHours(): void
    {
        $this->assertSame(['09:00:00', '11:15:00', 135], VisitResizeService::normalise('09:04', '11:12'));
    }

    /** @dataProvider badBlocks */
    public function testNormaliseRejects(string $s, string $e): void
    {
        $this->expectException(InvalidArgumentException::class);
        VisitResizeService::normalise($s, $e);
    }

    public static function badBlocks(): array
    {
        return [['09:00', '09:00'], ['10:00', '09:00'], ['09:00', '09:05'], ['06:00', '19:00'], ['9am', '10:00'], ['23:00', '24:00']];
    }

    public function testShiftedEndKeepsLength(): void
    {
        $this->assertSame('15:00:00', VisitResizeService::shiftedEnd('09:00:00', '10:00:00', '14:00:00'));
        $this->assertSame('23:59:00', VisitResizeService::shiftedEnd('09:00:00', '13:00:00', '22:00:00'));
        $this->assertNull(VisitResizeService::shiftedEnd('09:00:00', null, '14:00'));
    }

    public function testResizeWritesWindowAndOverrideAndReportsPrevious(): void
    {
        $r = (new VisitResizeService($this->db))->resize(1, '09:00', '10:15');
        $this->assertTrue($r['success']);
        $this->assertSame(75, $r['minutes']);
        $this->assertSame('09:00:00', $r['previous']['start']);
        $this->assertNull($r['previous']['override']);
        $row = $this->db->query("SELECT scheduled_time_end, duration_override_minutes FROM job_visits WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('10:15:00', $row['scheduled_time_end']);
        $this->assertSame(75, (int)$row['duration_override_minutes']);
    }

    public function testOverlapWithSameCrewWarnsAndWritesNothingUntilForced(): void
    {
        $svc = new VisitResizeService($this->db);
        $r = $svc->resize(1, '09:00', '11:00');
        $this->assertTrue($r['warning']);
        $this->assertSame(2, $r['conflicts'][0]['visit_id']);
        $this->assertSame('10:00:00', $this->db->query("SELECT scheduled_time_end FROM job_visits WHERE id = 1")->fetchColumn());
        $this->assertTrue($svc->resize(1, '09:00', '11:00', true)['success']);
    }

    public function testOtherCrewMemberAtTheSameTimeIsNotAConflict(): void
    {
        $this->assertTrue((new VisitResizeService($this->db))->resize(4, '09:00', '10:30')['success']);
    }

    public function testOnlyAScheduledVisitChanges(): void
    {
        $this->expectException(RuntimeException::class);
        (new VisitResizeService($this->db))->resize(3, '12:00', '14:00');
    }

    public function testUndoRestoresTimesAndClearsOverride(): void
    {
        $svc = new VisitResizeService($this->db);
        $r = $svc->resize(1, '09:00', '10:15');
        $svc->restore(1, $r['previous']['start'], $r['previous']['end'], $r['previous']['override']);
        $row = $this->db->query("SELECT scheduled_time_end, duration_override_minutes FROM job_visits WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('10:00:00', $row['scheduled_time_end']);
        $this->assertNull($row['duration_override_minutes']);
    }

    public function testResizeRefusesWhenTheColumnIsNotThereYet(): void
    {
        $p = new ReflectionProperty(VisitResizeService::class, 'hasColumn');
        $p->setAccessible(true);
        $p->setValue(null, false);
        $this->expectException(RuntimeException::class);
        (new VisitResizeService($this->db))->resize(1, '09:00', '10:15');
    }

    public function testUndoRefusesJunk(): void
    {
        $svc = new VisitResizeService($this->db);
        foreach ([['x', '10:00', null], ['10:00', '09:00', null], ['09:00', null, null], ['09:00', '10:00', -5], ['09:00', '10:00', 9999]] as $c) {
            try {
                $svc->restore(1, $c[0], $c[1], $c[2]);
                $this->fail('accepted ' . json_encode($c));
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame('10:00:00', $this->db->query("SELECT scheduled_time_end FROM job_visits WHERE id = 1")->fetchColumn());
    }
}
