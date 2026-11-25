<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TrackingSetupGateTest extends TestCase
{
    private const OK = ['location' => true, 'precise' => true, 'background' => true, 'batteryOptimizationIgnored' => false];

    public function test_ids_parse_from_a_loose_list(): void
    {
        $this->assertSame([6, 9], TrackingSetupGate::parseIds(' 6, 9;6 x 0'));
        $this->assertSame([], TrackingSetupGate::parseIds(null));
    }

    public function test_users_not_on_the_list_are_never_gated(): void
    {
        $this->assertNull(TrackingSetupGate::refusal('6', 8, null));
        $this->assertNull(TrackingSetupGate::refusal('', 6, null));
    }

    public function test_listed_user_with_full_settings_may_clock_in(): void
    {
        // Battery exemption is advisory — it does not decide whether a fix can be had.
        $this->assertNull(TrackingSetupGate::refusal('6', 6, self::OK));
    }

    public function test_listed_user_is_refused_for_each_missing_setting(): void
    {
        $this->assertStringContainsString('Mowology Crew app', TrackingSetupGate::refusal('6', 6, null));
        $this->assertStringContainsString('switched off', TrackingSetupGate::refusal('6', 6, ['gpsEnabled' => false] + self::OK));
        $this->assertStringContainsString('no location permission', TrackingSetupGate::refusal('6', 6, ['location' => false] + self::OK));
        $this->assertStringContainsString('Allow all the time', TrackingSetupGate::refusal('6', 6, ['background' => false] + self::OK));
        // Approximate location — the case that went unnoticed for ten days.
        $this->assertStringContainsString('precise', TrackingSetupGate::refusal('6', 6, ['precise' => false] + self::OK));
    }
}
