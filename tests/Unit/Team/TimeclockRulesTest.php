<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Modules/Team/Services/TimeclockRules.php';

/** Clock/timer rules from the 2026-10-12 review. */
final class TimeclockRulesTest extends TestCase
{
    public function testOvernightShiftIsNotStaleJustBecauseTheDateChanged(): void
    {
        // Clocked in 22:00, app opened 00:30 → 2.5 h elapsed: not stale with a 10 h limit.
        $this->assertFalse(TimeclockRules::isStaleEntry(9000, 10));
        $this->assertTrue(TimeclockRules::isStaleEntry(10 * 3600, 10));
    }

    public function testFallBackNightCountsTheExtraHour(): void
    {
        // 2025-11-02 (historic, in every tzdata). Production's tzdata has BC on permanent UTC-7 from
        // 2026-11-01 (no fall-back that night) — the rule follows whatever the zone data says.
        $this->assertSame(420, TimeclockRules::minutesBetween('2025-11-02 00:00:00', '2025-11-02 06:00:00'));
        $this->assertSame(360, TimeclockRules::wallMinutes('2025-11-02 00:00:00', '2025-11-02 06:00:00'));
        $this->assertTrue(TimeclockRules::spansDstChange('2025-11-02 00:00:00', '2025-11-02 06:00:00'));
    }

    public function testSpringForwardLosesAnHour(): void
    {
        $this->assertSame(300, TimeclockRules::minutesBetween('2026-03-08 00:00:00', '2026-03-08 06:00:00'));
        $this->assertFalse(TimeclockRules::spansDstChange('2026-10-12 07:00:00', '2026-10-12 17:00:00'));
        $this->assertSame(600, TimeclockRules::minutesBetween('2026-10-12 07:00:00', '2026-10-12 17:00:00'));
    }

    public function testOverlap(): void
    {
        $this->assertTrue(TimeclockRules::intervalsOverlap('2026-10-12 08:00', '2026-10-12 12:00', '2026-10-12 11:00', '2026-10-12 15:00'));
        $this->assertFalse(TimeclockRules::intervalsOverlap('2026-10-12 08:00', '2026-10-12 12:00', '2026-10-12 12:00', '2026-10-12 15:00'));
        $this->assertTrue(TimeclockRules::intervalsOverlap('2026-10-12 08:00', null, '2026-10-12 15:00', '2026-10-12 16:00'));
    }

    public function testOnlyAnAdminEditsTheirOwnTime(): void
    {
        $this->assertTrue(TimeclockRules::canEditTimeOf('admin', 1, 1));
        $this->assertFalse(TimeclockRules::canEditTimeOf('manager', 2, 2));
        $this->assertTrue(TimeclockRules::canEditTimeOf('manager', 2, 3));
        $this->assertFalse(TimeclockRules::canEditTimeOf('user', 3, 4));
    }

    public function testWeekStartsOfAMovedEntry(): void
    {
        // Sunday 2026-10-11 → week of Oct 5; moved to Monday Oct 12 → week of Oct 12: both recalculated.
        $this->assertSame(['2026-10-05', '2026-10-12'], TimeclockRules::weekStarts(['2026-10-11 20:00:00', '2026-10-12 06:00:00', null]));
    }

    public function testApprovedWeekIsNeverRecalculated(): void
    {
        $this->assertFalse(TimeclockRules::mayRecalculateWeek('approved'));
        $this->assertTrue(TimeclockRules::mayRecalculateWeek('pending'));
        $this->assertTrue(TimeclockRules::mayRecalculateWeek('submitted'));
        $this->assertTrue(TimeclockRules::mayRecalculateWeek(null));
    }

    public function testWeekWithOpenWorkCannotBeApproved(): void
    {
        $this->assertNull(TimeclockRules::approveBlockedMessage(0, 0));
        $this->assertStringContainsString('a shift still clocked in', (string)TimeclockRules::approveBlockedMessage(1, 0));
        $this->assertStringContainsString('2 job timers still running', (string)TimeclockRules::approveBlockedMessage(0, 2));
        $this->assertStringContainsString(' and ', (string)TimeclockRules::approveBlockedMessage(1, 1));
    }

    public function testCronClosedTimerIsCappedAtTheShiftOrTwelveHours(): void
    {
        $this->assertSame(480, TimeclockRules::cappedTimerMinutes(1300, 480));      // shift 8 h
        $this->assertSame(720, TimeclockRules::cappedTimerMinutes(1300, null));     // no shift → 12 h
        $this->assertSame(90, TimeclockRules::cappedTimerMinutes(90, 480));         // under the cap
        $this->assertSame(0, TimeclockRules::cappedTimerMinutes(-5, null));
    }
}
