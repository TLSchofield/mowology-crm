<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Modules/Invoices/Services/InvoiceWeatherEvidence.php';

class InvoiceWeatherEvidenceTest extends TestCase
{
    public function testNoEvidenceRendersNothing(): void
    {
        $this->assertSame('', InvoiceWeatherEvidence::html(null));
    }

    public function testOfficialPanelShowsForecastFacts(): void
    {
        $html = InvoiceWeatherEvidence::html([
            'grade' => 'official', 'trigger_date' => '2026-11-19', 'overnight_low_c' => '0.00',
            'trigger_threshold_c' => '2.00', 'weather_condition' => 'Clear', 'data_source' => 'Environment Canada',
            'source_station' => 'Vancouver', 'decision_at' => '2026-11-17 10:00:33', 'has_raw' => 1, 'record_id' => 33,
        ]);
        $this->assertStringContainsString('WEATHER AUTHORIZATION', $html);
        $this->assertStringContainsString('Thursday, November 19, 2026', $html);
        $this->assertStringContainsString('Forecast overnight low', $html);
        $this->assertStringContainsString('0 °C', $html);
        $this->assertStringContainsString('≤ 2 °C', $html);
        $this->assertStringContainsString('Yes — service authorized', $html);
        $this->assertStringContainsString('Environment Canada', $html);
        $this->assertStringContainsString('Nov 17, 2026', $html);   // capture shows the date only
        $this->assertStringNotContainsString('10:00', $html);
        $this->assertStringNotContainsString('UTC', $html);
        $this->assertStringContainsString('WX-00033', $html);
        $this->assertStringNotContainsString('Supplementary', $html);
    }

    public function testThresholdNotMetIsStatedHonestly(): void
    {
        $html = InvoiceWeatherEvidence::html([
            'grade' => 'official', 'trigger_date' => '2026-11-19', 'overnight_low_c' => '4.00',
            'trigger_threshold_c' => '0.00', 'weather_condition' => null, 'data_source' => null,
            'source_station' => null, 'decision_at' => '2026-11-17 18:00:33', 'has_raw' => 0,
        ]);
        $this->assertStringContainsString('Threshold met', $html);
        $this->assertStringNotContainsString('service authorized', $html);
    }

    public function testPhotoFallbackIsLabelledSupplementaryNotOfficial(): void
    {
        $html = InvoiceWeatherEvidence::html(['grade' => 'supplementary', 'file' => '/tmp/x.jpg', 'label' => 'CTV Vancouver <b>x</b>']);
        $this->assertStringContainsString('Supplementary record', $html);
        $this->assertStringContainsString('not the automated Environment Canada record', $html);
        $this->assertStringContainsString('CTV Vancouver &lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('WEATHER AUTHORIZATION', $html);
    }

    private function db(bool $withRecheckColumns): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->exec("CREATE TABLE salt_weather_decisions (id INTEGER PRIMARY KEY, visit_id INT, trigger_date TEXT, overnight_low_c REAL,
            trigger_threshold_c REAL, weather_condition TEXT, data_source TEXT, source_station TEXT, source_url TEXT, decision_at TEXT, raw_api_response TEXT"
            . ($withRecheckColumns ? ", recheck_at TEXT, recheck_low_c REAL, recheck_condition TEXT" : "") . ")");
        $db->exec("INSERT INTO salt_weather_decisions (id, visit_id, trigger_date, overnight_low_c, trigger_threshold_c, weather_condition, data_source, source_station, decision_at, raw_api_response)
            VALUES (7, 55, '2026-11-19', 0, 0, 'Clear', 'Environment Canada', 'bc-74', '2026-11-17 10:00:33', '{}')");
        return $db;
    }

    public function testRecheckReplacesTheCaptureDateAndLow(): void
    {
        $db = $this->db(true);
        $db->exec("UPDATE salt_weather_decisions SET recheck_at='2026-11-19 10:00:29', recheck_low_c=-1, recheck_condition='Cloudy' WHERE id=7");
        $ev = InvoiceWeatherEvidence::forInvoice($db, ['id' => 1, 'visit_id' => 55]);
        $this->assertSame('official', $ev['grade']);
        $html = InvoiceWeatherEvidence::html($ev);
        $this->assertStringContainsString('Forecast confirmed', $html);
        $this->assertStringContainsString('Nov 19, 2026', $html);
        $this->assertStringContainsString('-1 °C', $html);
        $this->assertStringNotContainsString('Nov 17', $html);
    }

    public function testNoRecheckKeepsTheOriginalCapture(): void
    {
        $ev = InvoiceWeatherEvidence::forInvoice($this->db(true), ['id' => 1, 'visit_id' => 55]);
        $html = InvoiceWeatherEvidence::html($ev);
        $this->assertStringContainsString('Forecast captured', $html);
        $this->assertStringContainsString('Nov 17, 2026', $html);
    }

    public function testBeforeTheMigrationTheOriginalCaptureStillShows(): void
    {
        $ev = InvoiceWeatherEvidence::forInvoice($this->db(false), ['id' => 1, 'visit_id' => 55]);
        $this->assertSame('official', $ev['grade']);
        $this->assertStringContainsString('Nov 17, 2026', InvoiceWeatherEvidence::html($ev));
    }

    public function testOfficialRowsFeedThePortalCard(): void
    {
        $f = InvoiceWeatherEvidence::official([
            'trigger_date' => '2026-11-19', 'overnight_low_c' => '0.00', 'trigger_threshold_c' => '0.00', 'weather_condition' => 'Clear',
            'data_source' => 'Environment Canada', 'source_station' => 'Vancouver / BC — station bc-74',
            'decision_at' => '2026-11-19 10:00:29', 'rechecked' => true, 'record_id' => 27,
        ]);
        $this->assertSame('Thursday, November 19, 2026', $f['date']);
        $this->assertTrue($f['met']);
        $labels = array_column($f['rows'], 0);
        $this->assertContains('Forecast confirmed', $labels);
        $this->assertContains('Record reference', $labels);
    }

    public function testStationRecordedLowShowsOnlyWhenWeHaveIt(): void
    {
        $base = ['grade' => 'official', 'trigger_date' => '2026-11-21', 'overnight_low_c' => '0.00', 'trigger_threshold_c' => '0.00',
                 'weather_condition' => 'Clear', 'data_source' => 'Environment Canada', 'source_station' => 'Vancouver',
                 'decision_at' => '2026-11-21 10:00:00', 'has_raw' => 1, 'record_id' => 40];
        $without = InvoiceWeatherEvidence::html($base);
        $this->assertStringNotContainsString('Station recorded low', $without);
        $with = InvoiceWeatherEvidence::html($base + ['observed_low_c' => -1.0, 'observed_count' => 9]);
        $this->assertStringContainsString('Station recorded low', $with);
        $this->assertStringContainsString('-1 °C · 9 readings', $with);
    }
}
