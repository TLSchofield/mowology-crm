<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure helpers extracted into PlanHelpersService (Phase 2):
 * time<->minutes conversion and visit numbering. DB-free and deterministic.
 */
class PlanHelpersServiceTest extends TestCase
{
    // ---- planTimeStringToMinutes -------------------------------------------

    public function test_hh_mm_to_minutes(): void
    {
        $this->assertSame(0, PlanHelpersService::planTimeStringToMinutes('00:00'));
        $this->assertSame(90, PlanHelpersService::planTimeStringToMinutes('01:30'));
        $this->assertSame(545, PlanHelpersService::planTimeStringToMinutes('09:05'));
    }

    public function test_hh_mm_ss_ignores_seconds(): void
    {
        $this->assertSame(545, PlanHelpersService::planTimeStringToMinutes('09:05:59'));
    }

    public function test_empty_is_zero(): void
    {
        $this->assertSame(0, PlanHelpersService::planTimeStringToMinutes(''));
        $this->assertSame(0, PlanHelpersService::planTimeStringToMinutes('   '));
    }

    // ---- planMinutesToTimeString -------------------------------------------

    public function test_minutes_to_hh_mm(): void
    {
        $this->assertSame('00:00', PlanHelpersService::planMinutesToTimeString(0));
        $this->assertSame('01:30', PlanHelpersService::planMinutesToTimeString(90));
        $this->assertSame('09:05', PlanHelpersService::planMinutesToTimeString(545));
    }

    public function test_clamps_to_2359_past_midnight(): void
    {
        // 24:00 (1440) and beyond clamp the hour to 23
        $this->assertSame('23:00', PlanHelpersService::planMinutesToTimeString(1440));
        $this->assertSame('23:30', PlanHelpersService::planMinutesToTimeString(1470));
    }

    public function test_roundtrip_within_day(): void
    {
        foreach (['00:00', '07:15', '12:45', '23:59'] as $t) {
            $this->assertSame(
                $t,
                PlanHelpersService::planMinutesToTimeString(
                    PlanHelpersService::planTimeStringToMinutes($t)
                )
            );
        }
    }

    // ---- generateVisitNumber -----------------------------------------------

    public function test_visit_number_format(): void
    {
        $this->assertSame('PLN-2026-0001-V001', PlanHelpersService::generateVisitNumber('PLN-2026-0001', 1));
        $this->assertSame('PLN-2026-0001-V042', PlanHelpersService::generateVisitNumber('PLN-2026-0001', 42));
        $this->assertSame('PLN-2026-0001-V1000', PlanHelpersService::generateVisitNumber('PLN-2026-0001', 1000));
    }
}
