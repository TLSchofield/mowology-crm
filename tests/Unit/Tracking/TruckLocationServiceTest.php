<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for TruckLocationService.
 *
 * PDO is mocked. Cache file writes go to a per-test temp path.
 */
class TruckLocationServiceTest extends TestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cachePath = tempnam(sys_get_temp_dir(), 'truckloc_test_') . '.json';
        @unlink($this->cachePath);
    }

    protected function tearDown(): void
    {
        @unlink($this->cachePath);
        parent::tearDown();
    }

    public function testSavePingInsertsAndWritesCache(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
             ->method('execute')
             ->with($this->callback(function(array $params): bool {
                 // device_id, lat, lng, speed, heading, battery, source, recorded_at
                 return $params[0] === '372356216'
                     && $params[1] === 49.2827
                     && $params[2] === -123.1207
                     && $params[6] === 'poll'
                     && $params[7] === '2026-06-06 12:34:56';
             }));

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);
        $pdo->method('lastInsertId')->willReturn('57');

        $svc = new TruckLocationService($pdo, $this->cachePath);
        $id = $svc->savePing('372356216', [
            'lat' => 49.2827,
            'lng' => -123.1207,
            'recorded_at' => '2026-06-06 12:34:56',
        ]);

        $this->assertSame(57, $id);
        $this->assertFileExists($this->cachePath);
        $cached = json_decode((string)file_get_contents($this->cachePath), true);
        $this->assertSame('372356216', $cached['device_id']);
        $this->assertSame(49.2827, $cached['lat']);
    }

    public function testSavePingReturnsZeroOnDuplicate(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturnCallback(function() {
            $e = new PDOException('Duplicate entry for uq_device_recorded');
            // PDOException code is a string by convention (SQLSTATE).
            $codeProp = (new ReflectionClass($e))->getProperty('code');
            $codeProp->setValue($e, '23000');
            throw $e;
        });

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $svc = new TruckLocationService($pdo, $this->cachePath);
        $id = $svc->savePing('dev', [
            'lat' => 49.0, 'lng' => -123.0, 'recorded_at' => '2026-06-06 12:00:00',
        ]);

        $this->assertSame(0, $id, 'duplicate insert should return 0');
        $this->assertFileExists($this->cachePath, 'cache file should still refresh on duplicate');
    }

    public function testSavePingRejectsBadShape(): void
    {
        $pdo = $this->createMock(PDO::class);
        $svc = new TruckLocationService($pdo, $this->cachePath);

        $this->expectException(InvalidArgumentException::class);
        $svc->savePing('dev', ['lat' => 49.0]); // missing lng, recorded_at
    }

    public function testGetLastKnownPositionReadsCacheFile(): void
    {
        file_put_contents($this->cachePath, json_encode([
            'device_id'   => 'dev-1',
            'lat'         => 49.5,
            'lng'         => -123.5,
            'recorded_at' => date('Y-m-d H:i:s', time() - 30),
        ]));

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');

        $svc = new TruckLocationService($pdo, $this->cachePath);
        $pos = $svc->getLastKnownPosition('dev-1');

        $this->assertIsArray($pos);
        $this->assertSame(49.5, $pos['lat']);
        $this->assertArrayHasKey('age_seconds', $pos);
        $this->assertLessThan(120, $pos['age_seconds']);
    }

    public function testGetLastKnownPositionFallsBackToDbWhenCacheMissingForDevice(): void
    {
        // No cache file at all → must hit DB
        $row = [
            'device_id'   => 'dev-2',
            'lat'         => '50.0',
            'lng'         => '-124.0',
            'speed_kph'   => null,
            'heading'     => null,
            'battery_pct' => null,
            'source'      => 'poll',
            'recorded_at' => date('Y-m-d H:i:s', time() - 600),
        ];

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with(['dev-2']);
        $stmt->method('fetch')->willReturn($row);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('prepare')->willReturn($stmt);

        $svc = new TruckLocationService($pdo, $this->cachePath);
        $pos = $svc->getLastKnownPosition('dev-2');

        $this->assertIsArray($pos);
        $this->assertSame(50.0, $pos['lat']);
        $this->assertGreaterThanOrEqual(500, $pos['age_seconds']);
    }

    public function testGetLastKnownPositionFallsBackWhenCacheIsForDifferentDevice(): void
    {
        file_put_contents($this->cachePath, json_encode([
            'device_id' => 'other-device', 'lat' => 1.0, 'lng' => 1.0,
            'recorded_at' => date('Y-m-d H:i:s'),
        ]));

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('prepare')->willReturn($stmt);

        $svc = new TruckLocationService($pdo, $this->cachePath);
        $this->assertNull($svc->getLastKnownPosition('dev-target'));
    }

    public function testGetTrailForDateQueriesBoundsAndNormalizesNumbers(): void
    {
        $rows = [
            ['lat' => '49.1', 'lng' => '-123.1', 'speed_kph' => '30.0', 'heading' => '90',  'recorded_at' => '2026-06-06 08:00:00'],
            ['lat' => '49.2', 'lng' => '-123.2', 'speed_kph' => null,    'heading' => null,  'recorded_at' => '2026-06-06 09:00:00'],
        ];

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with([
            'dev-1', '2026-06-06 00:00:00', '2026-06-06 23:59:59',
        ]);
        $stmt->method('fetchAll')->willReturn($rows);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $svc = new TruckLocationService($pdo, $this->cachePath);
        $trail = $svc->getTrailForDate('dev-1', '2026-06-06');

        $this->assertCount(2, $trail);
        $this->assertSame(49.1, $trail[0]['lat']);
        $this->assertSame(30.0, $trail[0]['speed_kph']);
        $this->assertSame(90, $trail[0]['heading']);
        $this->assertNull($trail[1]['speed_kph']);
    }
}
