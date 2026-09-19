<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * When is a clocked-in employee's phone "silent", when do we alert again, and
 * what do we tell the office the likely cause is.
 */
class TrackingHealthServiceTest extends TestCase
{
    private const NOW = 1790000000;

    public function test_just_clocked_in_is_not_silent_yet(): void
    {
        $this->assertFalse(TrackingHealthService::isSilent(self::NOW - 300, null, self::NOW, 15));
    }

    public function test_no_fix_at_all_this_shift_is_silent_once_the_threshold_passes(): void
    {
        $this->assertTrue(TrackingHealthService::isSilent(self::NOW - 3600, null, self::NOW, 15));
    }

    public function test_recent_fix_is_not_silent(): void
    {
        $this->assertFalse(TrackingHealthService::isSilent(self::NOW - 3600, self::NOW - 120, self::NOW, 15));
    }

    public function test_old_fix_is_silent(): void
    {
        $this->assertTrue(TrackingHealthService::isSilent(self::NOW - 7200, self::NOW - 1800, self::NOW, 15));
    }

    public function test_a_fix_from_before_clock_in_says_nothing_about_this_shift(): void
    {
        // Yesterday's last ping must not make today's dead phone look alive — nor make a
        // shift that started 5 minutes ago look like it has been silent for a day.
        $this->assertFalse(TrackingHealthService::isSilent(self::NOW - 300, self::NOW - 86400, self::NOW, 15));
        $this->assertTrue(TrackingHealthService::isSilent(self::NOW - 3600, self::NOW - 86400, self::NOW, 15));
    }

    public function test_alert_cooldown(): void
    {
        $this->assertTrue(TrackingHealthService::shouldAlert(null, self::NOW, 60));
        $this->assertFalse(TrackingHealthService::shouldAlert(self::NOW - 600, self::NOW, 60));
        $this->assertTrue(TrackingHealthService::shouldAlert(self::NOW - 3700, self::NOW, 60));
    }

    public function test_likely_cause_prefers_the_most_actionable_explanation(): void
    {
        $this->assertStringContainsString('No device report', TrackingHealthService::likelyCause(null));
        $this->assertStringContainsString('turned off', TrackingHealthService::likelyCause(['location_permission' => 'denied']));
        $this->assertStringContainsString('Precise', TrackingHealthService::likelyCause(['location_permission' => 'always', 'precise_location' => 0]));
        $this->assertStringContainsString('While Using', TrackingHealthService::likelyCause(['location_permission' => 'when_in_use', 'precise_location' => 1]));
        $this->assertStringContainsString('Battery optimisation', TrackingHealthService::likelyCause(['location_permission' => 'always', 'battery_optimization_exempt' => 0]));
        $this->assertStringContainsString('Low Power', TrackingHealthService::likelyCause(['location_permission' => 'always', 'low_power_mode' => 1]));
        $this->assertStringContainsString('nearly flat', TrackingHealthService::likelyCause(['location_permission' => 'always', 'battery_percent' => 3]));
        $this->assertStringContainsString('closed', TrackingHealthService::likelyCause(['location_permission' => 'always', 'battery_percent' => 80]));
    }
}
