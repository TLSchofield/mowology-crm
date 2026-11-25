<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!function_exists('applyPolicyPresets')) {
    if (!function_exists('getDB')) { function getDB() { throw new RuntimeException('no db in this test'); } }
    require_once PUBLIC_ROOT . '/crm/modules/weather/weather-rules.php';
}

/** Weather presets apply over the ops defaults, never over a threshold the service set (Codex 2026-10-15). */
class WeatherPresetTest extends TestCase
{
    private function rules(string $policy): array
    {
        return ['weather_policy' => $policy, 'max_precip_chance_pct' => 50, 'max_precip_mm_per_hr' => 2.5, 'min_temp_c' => -5, 'max_wind_kph' => 50];
    }

    public function testPresetReplacesTheDefaults(): void
    {
        $r = applyPolicyPresets($this->rules('LIGHT_RAIN_OK'), []);
        $this->assertSame(70, $r['max_precip_chance_pct']);
        $this->assertSame(5.0, $r['max_precip_mm_per_hr']);
        $this->assertSame(30.0, applyPolicyPresets($this->rules('WIND_LIMITED'), [])['max_wind_kph']);
        $this->assertSame(0.0, applyPolicyPresets($this->rules('TEMP_LIMITED'), [])['min_temp_c']);
    }

    public function testTheServicesOwnThresholdWins(): void
    {
        $r = applyPolicyPresets($this->rules('WIND_LIMITED'), ['max_wind_kph' => true]);
        $this->assertSame(50, $r['max_wind_kph']);
    }
}
