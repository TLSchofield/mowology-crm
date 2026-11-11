<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The rules that decide whether a GPS ping may start someone's job timer.
 * Each test names the real-world failure it guards against.
 */
class ProximityAutoStartServiceTest extends TestCase
{
    // ---- isOwnVisit: the drive-by-past-another-crew's-job bug ---------------

    public function test_another_crews_visit_is_never_mine(): void
    {
        $visit = ['assigned_crew_id' => 7, 'stop_crew_id' => 7, 'stop_id' => 50];
        $this->assertFalse(ProximityAutoStartService::isOwnVisit($visit, 9, []));
        $this->assertFalse(ProximityAutoStartService::isOwnVisit($visit, 9, [51, 52]));
    }

    public function test_assigned_crew_and_stop_lead_own_the_visit(): void
    {
        $this->assertTrue(ProximityAutoStartService::isOwnVisit(['assigned_crew_id' => 9], 9, []));
        $this->assertTrue(ProximityAutoStartService::isOwnVisit(['assigned_crew_id' => 7, 'stop_crew_id' => 9], 9, []));
    }

    public function test_secondary_crew_on_a_multi_crew_stop_own_the_visit(): void
    {
        $visit = ['assigned_crew_id' => 7, 'stop_crew_id' => 7, 'stop_id' => 50];
        $this->assertTrue(ProximityAutoStartService::isOwnVisit($visit, 9, [50]));
        $this->assertTrue(ProximityAutoStartService::isOwnVisit($visit, 9, ['50']), 'ids arrive as strings from PDO');
    }

    public function test_unassigned_visit_never_matches_user_zero(): void
    {
        $this->assertFalse(ProximityAutoStartService::isOwnVisit(['assigned_crew_id' => null, 'stop_id' => null], 0, []));
        $this->assertFalse(ProximityAutoStartService::isOwnVisit([], 0, [0]));
    }

    // ---- accuracyAcceptable -------------------------------------------------

    public function test_fix_error_circle_must_fit_inside_the_fence(): void
    {
        $this->assertTrue(ProximityAutoStartService::accuracyAcceptable(35.0, 150));
        $this->assertTrue(ProximityAutoStartService::accuracyAcceptable(150.0, 150));
        $this->assertFalse(ProximityAutoStartService::accuracyAcceptable(225.0, 150), 'old rule allowed 1.5x the radius');
    }

    public function test_invalid_accuracy_is_rejected(): void
    {
        $this->assertFalse(ProximityAutoStartService::accuracyAcceptable(0.0, 150));
        $this->assertFalse(ProximityAutoStartService::accuracyAcceptable(-1.0, 150), 'CoreLocation reports -1 for an invalid fix');
    }

    // ---- withinWindow: the 3pm-job-started-at-7am bug -----------------------

    public function test_timed_visit_does_not_start_hours_early(): void
    {
        $sevenAm = strtotime('2026-09-19 07:00:00');
        $this->assertFalse(ProximityAutoStartService::withinWindow('2026-09-19', '15:00:00', $sevenAm, 240));
    }

    public function test_timed_visit_starts_inside_the_lead_window_and_any_time_after(): void
    {
        $this->assertTrue(ProximityAutoStartService::withinWindow('2026-09-19', '15:00:00', strtotime('2026-09-19 11:00:00'), 240));
        $this->assertTrue(ProximityAutoStartService::withinWindow('2026-09-19', '15:00:00', strtotime('2026-09-19 17:30:00'), 240), 'running late is fine');
    }

    public function test_untimed_route_visits_are_always_in_window(): void
    {
        $now = strtotime('2026-09-19 06:00:00');
        $this->assertTrue(ProximityAutoStartService::withinWindow('2026-09-19', null, $now, 240));
        $this->assertTrue(ProximityAutoStartService::withinWindow('2026-09-19', '', $now, 240));
        $this->assertTrue(ProximityAutoStartService::withinWindow('2026-09-19', '00:00:00', $now, 240));
    }

    public function test_zero_lead_disables_the_window(): void
    {
        $this->assertTrue(ProximityAutoStartService::withinWindow('2026-09-19', '15:00:00', strtotime('2026-09-19 05:00:00'), 0));
    }

    // ---- rank: adjacent properties ------------------------------------------

    public function test_inside_a_drawn_border_beats_a_nearer_radius_match(): void
    {
        $ranked = ProximityAutoStartService::rank([
            ['visit' => ['id' => 1], 'distance' => 12.0, 'inside_border' => false],
            ['visit' => ['id' => 2], 'distance' => 0.0,  'inside_border' => true],
            ['visit' => ['id' => 3], 'distance' => 80.0, 'inside_border' => false],
        ]);
        $this->assertSame([2, 1, 3], array_map(static fn ($c) => $c['visit']['id'], $ranked));
    }

    // ---- hasDwell: the single-ping drive-by ---------------------------------

    public function test_a_single_ping_is_not_an_arrival(): void
    {
        $inside = static fn (float $lat, float $lng): bool => true;
        $this->assertFalse(ProximityAutoStartService::hasDwell([], $inside));
        // The ping that triggered the check is itself in history at age ~0.
        $this->assertFalse(ProximityAutoStartService::hasDwell([['lat' => 49.1, 'lng' => -123.1, 'age_seconds' => 1]], $inside));
    }

    public function test_an_earlier_fix_inside_the_same_fence_proves_arrival(): void
    {
        $inside = static fn (float $lat, float $lng): bool => $lat > 49.0;
        $fixes  = [
            ['lat' => 49.1, 'lng' => -123.1, 'age_seconds' => 2],
            ['lat' => 49.1, 'lng' => -123.1, 'age_seconds' => 95],
        ];
        $this->assertTrue(ProximityAutoStartService::hasDwell($fixes, $inside));
    }

    public function test_an_earlier_fix_outside_the_fence_is_a_drive_by(): void
    {
        $inside = static fn (float $lat, float $lng): bool => $lat > 49.0;
        $fixes  = [['lat' => 48.9, 'lng' => -123.1, 'age_seconds' => 60]];
        $this->assertFalse(ProximityAutoStartService::hasDwell($fixes, $inside));
    }

    public function test_a_stale_fix_proves_nothing_about_this_arrival(): void
    {
        $inside = static fn (float $lat, float $lng): bool => true;
        $this->assertFalse(ProximityAutoStartService::hasDwell([['lat' => 49.1, 'lng' => -123.1, 'age_seconds' => 900]], $inside));
    }
}
