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
            'source_station' => 'Vancouver', 'decision_at' => '2026-11-17 18:00:33', 'has_raw' => 1,
        ]);
        $this->assertStringContainsString('WEATHER AUTHORIZATION', $html);
        $this->assertStringContainsString('Thursday, November 19, 2026', $html);
        $this->assertStringContainsString('0 °C', $html);
        $this->assertStringContainsString('Yes — service authorized', $html);
        $this->assertStringContainsString('Environment Canada', $html);
        $this->assertStringNotContainsString('Supplementary', $html);
    }

    public function testThresholdNotMetIsStatedHonestly(): void
    {
        $html = InvoiceWeatherEvidence::html([
            'grade' => 'official', 'trigger_date' => '2026-11-19', 'overnight_low_c' => '4.00',
            'trigger_threshold_c' => '0.00', 'weather_condition' => null, 'data_source' => null,
            'source_station' => null, 'decision_at' => '2026-11-17 18:00:33', 'has_raw' => 0,
        ]);
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
}
