<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * When has a crew really LEFT a job site — and at what moment? This decides recorded job
 * durations, so each test names the real situation it guards.
 */
class DepartureAutoStopServiceTest extends TestCase
{
    private const START = 1790000000;

    /** fixes: [secondsAfterStart, 'in'|'edge'|'away'] */
    private function fixes(array $spec): array
    {
        return array_map(static fn (array $f): array => [
            'ts'     => self::START + $f[0],
            'inside' => $f[1] === 'in',
            'away'   => $f[1] === 'away',
        ], $spec);
    }

    public function test_crew_working_on_site_is_not_stopped(): void
    {
        $fx = $this->fixes([[0, 'in'], [300, 'in'], [900, 'in'], [1500, 'in']]);
        $v  = DepartureAutoStopService::evaluate($fx, self::START, self::START + 1510);
        $this->assertFalse($v['departed']);
        $this->assertSame('still_on_site', $v['reason']);
    }

    public function test_drove_away_and_stayed_away_stops_at_the_last_moment_on_site(): void
    {
        // 40-minute lawn cut, then gone. The old system recorded this as ~36 hours.
        $fx = $this->fixes([[0, 'in'], [1200, 'in'], [2400, 'in'], [2460, 'away'], [2580, 'away'], [2720, 'away']]);
        $v  = DepartureAutoStopService::evaluate($fx, self::START, self::START + 2730);
        $this->assertTrue($v['departed']);
        $this->assertSame(self::START + 2400, $v['left_at'], 'back-dated to the last fix inside the fence, not to now');
    }

    public function test_gps_wobble_at_the_kerb_does_not_stop_the_job(): void
    {
        // One stray "away" fix, then back inside — multipath off a building.
        $fx = $this->fixes([[0, 'in'], [600, 'in'], [660, 'away'], [720, 'in'], [780, 'in']]);
        $this->assertFalse(DepartureAutoStopService::evaluate($fx, self::START, self::START + 790)['departed']);
    }

    public function test_just_left_is_not_long_enough(): void
    {
        $fx = $this->fixes([[0, 'in'], [600, 'in'], [660, 'away'], [720, 'away']]);
        $v  = DepartureAutoStopService::evaluate($fx, self::START, self::START + 730);
        $this->assertFalse($v['departed']);
        $this->assertSame('not_away_long_enough', $v['reason']);
    }

    public function test_hovering_at_the_edge_proves_nothing(): void
    {
        // Working the boulevard / loading the trailer on the street: outside the fence but
        // not clearly gone. Must not be read as departure.
        $fx = $this->fixes([[0, 'in'], [600, 'in'], [700, 'edge'], [900, 'edge'], [1100, 'edge']]);
        $this->assertFalse(DepartureAutoStopService::evaluate($fx, self::START, self::START + 1110)['departed']);
    }

    public function test_an_edge_fix_breaks_the_away_run(): void
    {
        $fx = $this->fixes([[0, 'in'], [600, 'away'], [700, 'away'], [800, 'edge'], [900, 'away'], [960, 'away']]);
        $this->assertFalse(DepartureAutoStopService::evaluate($fx, self::START, self::START + 970)['departed'],
            'only 60 s of unbroken "away" since the edge fix');
    }

    public function test_never_within_the_first_minutes_of_a_job(): void
    {
        $fx = $this->fixes([[0, 'away'], [60, 'away'], [120, 'away']]);
        $v  = DepartureAutoStopService::evaluate($fx, self::START, self::START + 130);
        $this->assertSame('too_soon', $v['reason']);
    }

    public function test_a_silent_phone_is_not_a_departure(): void
    {
        // Last fix 20 min ago (tunnel, dead battery). We know nothing about now — do nothing.
        $fx = $this->fixes([[0, 'in'], [300, 'away'], [600, 'away']]);
        $v  = DepartureAutoStopService::evaluate($fx, self::START, self::START + 1800);
        $this->assertFalse($v['departed']);
        $this->assertSame('stale', $v['reason']);
    }

    public function test_never_seen_inside_falls_back_to_the_first_away_fix(): void
    {
        $fx = $this->fixes([[10, 'edge'], [300, 'away'], [450, 'away'], [600, 'away']]);
        $v  = DepartureAutoStopService::evaluate($fx, self::START, self::START + 610);
        $this->assertTrue($v['departed']);
        $this->assertSame(self::START + 300, $v['left_at']);
    }

    public function test_left_at_is_never_before_the_timer_started(): void
    {
        $fx = $this->fixes([[-50, 'in'], [200, 'away'], [350, 'away'], [500, 'away']]);
        $v  = DepartureAutoStopService::evaluate($fx, self::START, self::START + 510);
        $this->assertTrue($v['departed']);
        $this->assertGreaterThanOrEqual(self::START, $v['left_at']);
    }

    public function test_fix_classification_respects_the_fix_error_circle(): void
    {
        $this->assertSame(['inside' => true,  'away' => false], DepartureAutoStopService::classify(120.0, 10.0, 150));
        $this->assertSame(['inside' => false, 'away' => false], DepartureAutoStopService::classify(200.0, 10.0, 150), 'just outside = edge');
        $this->assertSame(['inside' => false, 'away' => true],  DepartureAutoStopService::classify(400.0, 10.0, 150));
        // A vague fix needs to be further away before it counts as gone.
        $this->assertFalse(DepartureAutoStopService::classify(260.0, 80.0, 150)['away']);
        $this->assertTrue(DepartureAutoStopService::classify(260.0, 10.0, 150)['away']);
    }
}
