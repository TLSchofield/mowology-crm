<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Commercial vehicle trip inspections — the pure rules: trip state, what gets stored,
 * and above all when a vehicle may NOT be driven.
 */
class TripReportServiceTest extends TestCase
{
    private function allChecked(array $over = []): array
    {
        $in = ['safe_to_drive' => 1, 'odometer_start' => '152340'];
        foreach (array_keys(TripReportService::CHECKS) as $f) { $in[$f] = 1; }
        return array_merge($in, $over);
    }

    // ---- tripState ----------------------------------------------------------

    public function test_trip_state_machine(): void
    {
        $this->assertSame('none', TripReportService::tripState(null));
        $this->assertSame('none', TripReportService::tripState(['pre_trip_at' => null, 'post_trip_at' => null]));
        $this->assertSame('open', TripReportService::tripState(['pre_trip_at' => '2026-09-19 07:10:00', 'post_trip_at' => null]));
        $this->assertSame('closed', TripReportService::tripState(['pre_trip_at' => '2026-09-19 07:10:00', 'post_trip_at' => '2026-09-19 16:02:00']));
    }

    // ---- mayDrive: the rule that protects people ----------------------------

    public function test_clean_inspection_may_drive(): void
    {
        $this->assertTrue(TripReportService::mayDrive(TripReportService::sanitizePreTrip($this->allChecked())));
    }

    public function test_safe_box_unticked_may_not_drive(): void
    {
        $clean = TripReportService::sanitizePreTrip($this->allChecked(['safe_to_drive' => 0]));
        $this->assertFalse(TripReportService::mayDrive($clean));
    }

    public function test_a_recorded_critical_defect_overrides_a_ticked_safe_box(): void
    {
        $clean = TripReportService::sanitizePreTrip($this->allChecked(['defects_critical' => 'Brake line leaking at rear left']));
        $this->assertFalse(TripReportService::mayDrive($clean), 'both cannot be true; the cautious reading wins');
    }

    public function test_a_non_urgent_defect_does_not_ground_the_vehicle(): void
    {
        $clean = TripReportService::sanitizePreTrip($this->allChecked(['defects_non_urgent' => 'Small chip in windshield']));
        $this->assertTrue(TripReportService::mayDrive($clean));
    }

    // ---- sanitizePreTrip ----------------------------------------------------

    public function test_every_check_is_always_present_and_is_zero_or_one(): void
    {
        $clean = TripReportService::sanitizePreTrip(['chk_leaks' => 'yes', 'chk_mirrors' => 0]);
        $this->assertSame(array_keys(TripReportService::CHECKS), array_keys($clean['checks']));
        $this->assertSame(1, $clean['checks']['chk_leaks']);
        $this->assertSame(0, $clean['checks']['chk_mirrors']);
        $this->assertSame(0, $clean['checks']['chk_hitch'], 'absent = not checked, never assumed');
    }

    public function test_unchecked_items_are_listed_by_their_labels(): void
    {
        $clean = TripReportService::sanitizePreTrip($this->allChecked(['chk_tire_pressure' => 0, 'chk_trailer_lights' => 0]));
        $this->assertSame(['Trailer lights', 'Tire pressure'], TripReportService::uncheckedLabels($clean));
    }

    public function test_text_is_stripped_and_bounded_and_odometer_validated(): void
    {
        $clean = TripReportService::sanitizePreTrip([
            'defects_critical' => '  <script>x</script>Cracked hitch  ', 'odometer_start' => 'abc',
        ]);
        $this->assertSame('xCracked hitch', $clean['defects_critical']);
        $this->assertNull($clean['odometer_start']);
        $this->assertSame(152340, TripReportService::sanitizePreTrip(['odometer_start' => '152340'])['odometer_start']);
        $this->assertNull(TripReportService::sanitizePreTrip(['odometer_start' => -5])['odometer_start']);
    }

    // ---- odometerProblem ----------------------------------------------------

    public function test_end_below_start_is_flagged(): void
    {
        $this->assertNotNull(TripReportService::odometerProblem(152340, 152300));
        $this->assertNull(TripReportService::odometerProblem(152340, 152420));
        $this->assertNull(TripReportService::odometerProblem(null, 152420), 'no start reading — nothing to compare');
    }

    public function test_an_implausibly_long_trip_is_flagged(): void
    {
        $this->assertNotNull(TripReportService::odometerProblem(152340, 162340));
    }

    // ---- resolvePerformedAt: a queued offline inspection keeps its real time ----

    public function test_an_inspection_done_offline_is_filed_under_the_time_it_was_done(): void
    {
        $now  = 1790000000;
        $done = $now - 5400;                                   // 90 minutes ago, no signal at the yard
        $this->assertSame($done, TripReportService::resolvePerformedAt($done * 1000, $now), 'epoch ms from the phone');
        $this->assertSame($done, TripReportService::resolvePerformedAt($done, $now), 'epoch seconds');
    }

    public function test_no_device_time_means_now(): void
    {
        $now = 1790000000;
        $this->assertSame($now, TripReportService::resolvePerformedAt(null, $now));
        $this->assertSame($now, TripReportService::resolvePerformedAt('', $now));
        $this->assertSame($now, TripReportService::resolvePerformedAt('yesterday', $now));
    }

    public function test_a_wrong_phone_clock_cannot_write_a_wrong_legal_date(): void
    {
        $now = 1790000000;
        $this->assertSame($now, TripReportService::resolvePerformedAt($now + 86400, $now), 'a day in the future');
        $this->assertSame($now, TripReportService::resolvePerformedAt($now - 30 * 86400, $now), 'a month ago — beyond the offline window');
    }

    public function test_small_clock_skew_is_tolerated_but_never_stored_as_the_future(): void
    {
        $now = 1790000000;
        $this->assertSame($now, TripReportService::resolvePerformedAt($now + 120, $now));
    }

    // ---- parseVehicles ------------------------------------------------------

    public function test_fleet_setting_parses_ids_and_labels(): void
    {
        $v = TripReportService::parseVehicles('RAM3500-PF8865|Dodge Ram 3500; MIGHTE-1 | Might-E Truck ;; |orphan');
        $this->assertSame([
            ['id' => 'RAM3500-PF8865', 'label' => 'Dodge Ram 3500'],
            ['id' => 'MIGHTE-1', 'label' => 'Might-E Truck'],
        ], $v);
        $this->assertSame([], TripReportService::parseVehicles(''));
        $this->assertSame([['id' => 'TRUCK2', 'label' => 'TRUCK2']], TripReportService::parseVehicles('TRUCK2'));
    }
}
