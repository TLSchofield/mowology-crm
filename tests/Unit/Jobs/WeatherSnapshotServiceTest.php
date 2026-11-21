<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Modules/Jobs/Services/WeatherSnapshotService.php';

class WeatherSnapshotServiceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE weather_forecast_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT, captured_at TEXT, run_label TEXT, source TEXT, target_date TEXT, low_c REAL, high_c REAL, condition_text TEXT, raw_json TEXT)');
        $this->db->exec('CREATE TABLE weather_observations (id INTEGER PRIMARY KEY AUTOINCREMENT, observed_at TEXT, captured_at TEXT, source TEXT, station TEXT, temp_c REAL, condition_text TEXT)');
    }

    private function ecJson(float $temp, string $ts): string
    {
        return json_encode(['features' => [['properties' => ['currentConditions' => [
            'temperature' => ['value' => ['en' => $temp]], 'timestamp' => ['en' => $ts],
            'station' => ['value' => ['en' => "Vancouver Int'l Airport"]], 'condition' => ['en' => 'Cloudy']]]]]]);
    }

    public function testParseEcCurrentReadsStationTempAndUtcTime(): void
    {
        $o = WeatherSnapshotService::parseEcCurrent($this->ecJson(-1.2, '2026-11-20T14:00:00Z'));
        $this->assertSame(-1.2, $o['temp_c']);
        $this->assertSame('2026-11-20 14:00:00', $o['observed_at']);
        $this->assertSame("Vancouver Int'l Airport", $o['station']);
        $this->assertNull(WeatherSnapshotService::parseEcCurrent('{}'));
        $this->assertNull(WeatherSnapshotService::parseEcCurrent(null));
    }

    public function testSameReadingIsKeptOnce(): void
    {
        $o = WeatherSnapshotService::parseEcCurrent($this->ecJson(3.0, '2026-11-20T14:00:00Z'));
        $this->assertSame(1, WeatherSnapshotService::storeObservation($this->db, $o, '2026-11-20 06:05:00'));
        $this->assertSame(0, WeatherSnapshotService::storeObservation($this->db, $o, '2026-11-20 06:35:00'));
    }

    public function testParseMetDailyUsesVancouverDaysAndDropsThinDays(): void
    {
        $rows = [];
        // 2026-11-20 08:00..15:00 UTC = 00:00..07:00 Vancouver on the 20th (8 readings), then one lone reading on the 21st
        for ($h = 8; $h <= 15; $h++) {
            $rows[] = ['time' => sprintf('2026-11-20T%02d:00:00Z', $h), 'data' => ['instant' => ['details' => ['air_temperature' => $h - 10]],
                       'next_6_hours' => ['summary' => ['symbol_code' => 'clearsky_night']]]];
        }
        $rows[] = ['time' => '2026-11-21T20:00:00Z', 'data' => ['instant' => ['details' => ['air_temperature' => 9]]]];
        $d = WeatherSnapshotService::parseMetDaily(json_encode(['properties' => ['timeseries' => $rows]]));
        $this->assertSame(['2026-11-20'], array_keys($d));
        $this->assertSame(-2.0, $d['2026-11-20']['temp_low']);
        $this->assertSame(5.0, $d['2026-11-20']['temp_high']);
        $this->assertSame('clearsky night', $d['2026-11-20']['condition']);
    }

    public function testCaptureRunStoresEverySourceSeparatelyAndSurvivesOneFailing(): void
    {
        $day = ['2026-11-21' => ['temp_low' => 1, 'temp_high' => 6, 'condition' => 'Cloudy']];
        $r = WeatherSnapshotService::captureRun($this->db, 'morning', true, [
            'ec_current' => fn() => $this->ecJson(2.5, '2026-11-20T18:00:00Z'),
            'ec_daily'   => fn() => $day,
            'om_daily'   => function () { throw new RuntimeException('om down'); },
            'met_raw'    => fn() => null,
        ], '2026-11-20 10:00:00');
        $this->assertSame(1, $r['observations']);
        $this->assertSame(1, $r['snapshots']);
        $this->assertSame(['2026-11-21'], array_keys($r['ec_days']));
        $this->assertCount(1, $r['errors']);
        $this->assertStringContainsString('Open-Meteo', $r['errors'][0]);
        $row = $this->db->query('SELECT source, run_label, target_date, low_c FROM weather_forecast_snapshots')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['source' => 'Environment Canada', 'run_label' => 'morning', 'target_date' => '2026-11-21', 'low_c' => 1.0], ['source' => $row['source'], 'run_label' => $row['run_label'], 'target_date' => $row['target_date'], 'low_c' => (float)$row['low_c']]);
    }

    public function testHourlyRunWithoutForecastOnlyStoresTheReading(): void
    {
        $r = WeatherSnapshotService::captureRun($this->db, 'hourly', false, [
            'ec_current' => fn() => $this->ecJson(0.5, '2026-11-20T11:00:00Z'),
            'ec_daily' => fn() => $this->fail('forecast fetched'), 'om_daily' => fn() => $this->fail('x'), 'met_raw' => fn() => $this->fail('x'),
        ], '2026-11-20 03:05:00');
        $this->assertSame([1, 0], [$r['observations'], $r['snapshots']]);
    }

    public function testDecisiveSnapshotIsLastEcOneBeforeTheCutoff(): void
    {
        $ins = $this->db->prepare('INSERT INTO weather_forecast_snapshots (captured_at, run_label, source, target_date, low_c) VALUES (?, ?, ?, ?, 0)');
        $ins->execute(['2026-11-19 18:00:00', 'evening', 'Environment Canada', '2026-11-20']);
        $ins->execute(['2026-11-20 10:00:00', 'morning', 'Environment Canada', '2026-11-20']);
        $ins->execute(['2026-11-20 10:00:00', 'morning', 'MET Norway', '2026-11-20']);
        $ins->execute(['2026-11-20 13:00:00', 'manual', 'Environment Canada', '2026-11-20']);
        $this->assertSame(2, WeatherSnapshotService::decisiveSnapshotId($this->db, '2026-11-20', '2026-11-20 12:00:00'));
        $this->assertNull(WeatherSnapshotService::decisiveSnapshotId($this->db, '2026-11-25', '2026-11-20 12:00:00'));
        // a recheck written at 10:00:30 must never link the 06:05 snapshot if this run stored none
        $this->assertNull(WeatherSnapshotService::decisiveSnapshotId($this->db, '2026-11-20', '2026-11-20 12:00:00', '2026-11-20 10:00:30'));
        $this->assertSame(2, WeatherSnapshotService::decisiveSnapshotId($this->db, '2026-11-20', '2026-11-20 12:00:00', '2026-11-20 10:00:00'));
    }

    public function testObservedLowWindowIsEveningBeforeToNoon(): void
    {
        $ins = $this->db->prepare("INSERT INTO weather_observations (observed_at, captured_at, source, station, temp_c) VALUES (?, '', 'Environment Canada', 'YVR', ?)");
        $ins->execute(['2026-11-20 01:00:00', -9]);   // 17:00 on the 19th Vancouver: before the window
        $ins->execute(['2026-11-20 06:00:00', -1]);   // 22:00 on the 19th: in
        $ins->execute(['2026-11-20 13:00:00', 0.5]);  // 05:00 on the 20th: in
        $ins->execute(['2026-11-20 21:00:00', -7]);   // 13:00 on the 20th: after noon
        $o = WeatherSnapshotService::observedLow($this->db, '2026-11-20');
        $this->assertSame(-1.0, $o['low']);
        $this->assertSame(2, $o['count']);
        $this->assertNull(WeatherSnapshotService::observedLow($this->db, '2026-11-25'));
    }
}
