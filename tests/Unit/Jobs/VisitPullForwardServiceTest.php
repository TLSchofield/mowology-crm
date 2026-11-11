<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * VisitPullForwardService against in-memory SQLite, modelled on Oakridge Gardens (property 73,
 * 6015 Tisdall) on Mon 2026-10-05: Monday's own visit done, #2160 booked Tue Oct 6 (Lawn Cut),
 * #2159 booked Wed Oct 7 (Garden Care).
 */
final class VisitPullForwardServiceTest extends TestCase
{
    private PDO $db;
    private const TODAY = '2026-10-05';

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT, property_name TEXT, latitude REAL, longitude REAL)");
        $this->db->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, property_id INT, status TEXT, title TEXT, service_type TEXT, plan_number TEXT,
                         is_recurring INT, default_crew_id INT, contract_id INT, recurrence_day_of_week TEXT, plan_start_date TEXT, visits_generated_through TEXT)");
        $this->db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, visit_number TEXT, plan_id INT, stop_id INT, scheduled_date TEXT, status TEXT,
                         started_at TEXT, completed_at TEXT, assigned_crew_id INT, original_scheduled_date TEXT, sequence_index INT)");
        $this->db->exec("CREATE TABLE calendar_stops (id INTEGER PRIMARY KEY, property_id INT, stop_date TEXT, crew_id INT, route_order INT, status TEXT)");
        $this->db->exec("CREATE TABLE calendar_stop_crew (stop_id INT, user_id INT)");
        $this->db->exec("CREATE TABLE visit_moves (id INTEGER PRIMARY KEY, visit_id INT, plan_id INT, property_id INT, from_date TEXT, to_date TEXT,
                         from_stop_id INT, to_stop_id INT, from_stop_deleted INT, reason TEXT, moved_by INT, request_key TEXT UNIQUE, created_at TEXT)");

        $this->db->exec("INSERT INTO properties VALUES (73, '6015 Tisdall St', 'Oakridge Gardens', 49.2290, -123.1160)");
        $this->db->exec("INSERT INTO properties VALUES (80, '6978 Fremlin St', NULL, 49.2240, -123.1290)");
        // Plan 1 Lawn Cut weekly (Mon start), plan 2 Garden Care, plan 3 Hedges (nothing booked soon).
        $this->db->exec("INSERT INTO job_plans VALUES (1, 73, 'active', 'Oakridge lawn', 'Lawn Cut', 'PLN-1', 1, 10, NULL, '2', '2026-04-07', '2026-11-15')");
        $this->db->exec("INSERT INTO job_plans VALUES (2, 73, 'active', 'Oakridge beds', 'Garden Care', 'PLN-2', 1, 10, NULL, '3', '2026-04-08', '2026-11-15')");
        $this->db->exec("INSERT INTO job_plans VALUES (3, 73, 'active', 'Oakridge hedges', 'Hedge Trimming', 'PLN-3', 0, 10, NULL, NULL, '2026-11-20', NULL)");
        // Stops: Mon (done), Tue, Wed — crew 10.
        $this->db->exec("INSERT INTO calendar_stops VALUES (500, 73, '2026-10-05', 10, 1, 'scheduled')");
        $this->db->exec("INSERT INTO calendar_stops VALUES (501, 73, '2026-10-06', 10, 1, 'scheduled')");
        $this->db->exec("INSERT INTO calendar_stops VALUES (502, 73, '2026-10-07', 10, 2, 'scheduled')");
        $this->db->exec("INSERT INTO job_visits VALUES (2150, 'PLN-1-V20', 1, 500, '2026-10-05', 'completed', '2026-10-05 08:00', '2026-10-05 09:00', 10, NULL, 20)");
        $this->db->exec("INSERT INTO job_visits VALUES (2160, 'PLN-1-V21', 1, 501, '2026-10-06', 'scheduled', NULL, NULL, 10, NULL, 21)");
        $this->db->exec("INSERT INTO job_visits VALUES (2159, 'PLN-2-V9',  2, 502, '2026-10-07', 'scheduled', NULL, NULL, 10, NULL, 9)");
        $this->db->exec("INSERT INTO job_visits VALUES (2170, 'PLN-1-V22', 1, NULL, '2026-10-13', 'scheduled', NULL, NULL, 10, NULL, 22)");
    }

    private function svc(): VisitPullForwardService
    {
        return new VisitPullForwardService($this->db, self::TODAY);
    }

    private function row(string $sql): ?array
    {
        $r = $this->db->query($sql)->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    // ── switch (off by default since the 2026-10-08 incident) ─────────────────

    public function testOffUnlessSwitchedOnForEveryoneOrForThisUser(): void
    {
        $this->assertFalse(VisitPullForwardService::isEnabledFor(null, null, 22));
        $this->assertFalse(VisitPullForwardService::isEnabledFor('0', '', 22));
        $this->assertFalse(VisitPullForwardService::isEnabledFor('0', '12, 34', 22));
        $this->assertTrue(VisitPullForwardService::isEnabledFor('0', '12, 22,34', 22));
        $this->assertTrue(VisitPullForwardService::isEnabledFor('1', '', 22));
        $this->assertFalse(VisitPullForwardService::isEnabledFor('0', '0', 0));

        // No ops_settings table / rows → off, never an exception.
        $this->assertFalse(VisitPullForwardService::enabledFor($this->db, 22));
        $this->db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)");
        $this->assertFalse(VisitPullForwardService::enabledFor($this->db, 22));
        $this->db->exec("INSERT INTO ops_settings VALUES ('pull_forward_enabled', '0'), ('pull_forward_user_ids', '22')");
        $this->assertTrue(VisitPullForwardService::enabledFor($this->db, 22));
        $this->assertFalse(VisitPullForwardService::enabledFor($this->db, 10));
    }

    // ── ordering ──────────────────────────────────────────────────────────────

    public function testOverdueBeforeUpcomingWithinSevenDaysAndNeverSkipped(): void
    {
        $ranked = VisitPullForwardService::rankCandidates([
            ['id' => 1, 'scheduled_date' => '2026-10-07', 'status' => 'scheduled'],
            ['id' => 2, 'scheduled_date' => '2026-10-02', 'status' => 'scheduled'],
            ['id' => 3, 'scheduled_date' => '2026-09-29', 'status' => 'scheduled'],
            ['id' => 4, 'scheduled_date' => '2026-10-06', 'status' => 'scheduled'],
            ['id' => 5, 'scheduled_date' => '2026-10-13', 'status' => 'scheduled'], // +8: out
            ['id' => 6, 'scheduled_date' => '2026-09-27', 'status' => 'scheduled'], // -8: out
            ['id' => 7, 'scheduled_date' => '2026-10-06', 'status' => 'skipped'],
            ['id' => 8, 'scheduled_date' => '2026-10-06', 'status' => 'cancelled'],
            ['id' => 9, 'scheduled_date' => '2026-10-06', 'status' => 'weather'],
            ['id' => 10, 'scheduled_date' => '2026-10-05', 'status' => 'scheduled'], // today: not an offer
            ['id' => 11, 'scheduled_date' => '2026-10-04', 'status' => 'completed'],
        ], self::TODAY);

        $this->assertSame([3, 2, 4, 1], array_map(static fn ($r) => (int)$r['id'], $ranked));
        $this->assertTrue($ranked[0]['overdue']);
        $this->assertSame(-6, $ranked[0]['days_from_today']);
        $this->assertFalse($ranked[2]['overdue']);
    }

    public function testOakridgeOffersTuesdaysCutThenOtherPlans(): void
    {
        $offer = $this->svc()->offerForProperty(73);

        $this->assertNull($offer['suppressed']);
        $this->assertSame(2160, $offer['primary']['visit_id']);
        $this->assertSame('Lawn Cut for Oakridge Gardens is booked Tue Oct 6. Doing it now?', $offer['headline']);
        $this->assertSame([2159], array_column($offer['others'], 'visit_id'));
        $this->assertSame([3], array_column($offer['plans_without_visit'], 'plan_id'));
    }

    public function testNoOfferWhileTodaysVisitIsStillOpen(): void
    {
        $this->db->exec("UPDATE job_visits SET status = 'scheduled', started_at = NULL, completed_at = NULL WHERE id = 2150");
        $offer = $this->svc()->offerForProperty(73);
        $this->assertSame('open_visit_today', $offer['suppressed']);
        $this->assertNull($offer['primary']);
    }

    public function testSkippedVisitIsNeverOffered(): void
    {
        $this->db->exec("UPDATE job_visits SET status = 'skipped' WHERE id = 2160");
        $offer = $this->svc()->offerForProperty(73);
        $this->assertSame(2159, $offer['primary']['visit_id']);
        $this->assertNotContains(2160, array_column($offer['others'], 'visit_id'));
    }

    public function testOverdueWording(): void
    {
        $this->db->exec("INSERT INTO job_visits VALUES (2140, 'PLN-2-V8', 2, NULL, '2026-10-01', 'scheduled', NULL, NULL, 10, NULL, 8)");
        $offer = $this->svc()->offerForProperty(73);
        $this->assertSame(2140, $offer['primary']['visit_id']);
        $this->assertTrue($offer['primary']['overdue']);
        $this->assertStringContainsString('was due Thu Oct 1', $offer['headline']);
    }

    public function testPhoneAtOakridgeGetsTheOfferNotFremlin(): void
    {
        $res = $this->svc()->offerAt(49.2291, -123.1161, 150);
        $this->assertSame('offer', $res['reason']);
        $this->assertSame(73, $res['offer']['property_id']);

        $nowhere = $this->svc()->offerAt(49.30, -123.00, 150);
        $this->assertNull($nowhere['offer']);
        $this->assertSame('not_at_a_property', $nowhere['reason']);
    }

    // ── moving ────────────────────────────────────────────────────────────────

    public function testPullForwardMovesToTodayAndRemovesTheEmptyStop(): void
    {
        $r = $this->svc()->pullForward(2160, 22, 'key-1', 73);

        $this->assertTrue($r['success']);
        $this->assertFalse($r['already']);
        $this->assertSame('2026-10-06', $r['from_date']);
        $this->assertSame(500, $r['stop_id'], "joins Monday's existing stop");
        $this->assertTrue($r['old_stop_deleted']);
        $this->assertNull($this->row("SELECT * FROM calendar_stops WHERE id = 501"), 'Tuesday stop left empty → removed');

        $v = $this->row("SELECT * FROM job_visits WHERE id = 2160");
        $this->assertSame('2026-10-05', $v['scheduled_date']);
        $this->assertSame('2026-10-06', $v['original_scheduled_date']);
        $this->assertSame(500, (int)$v['stop_id']);

        // The person on site (22, not the assigned crew 10) is crewed on today's stop for the timer path.
        $this->assertNotNull($this->row("SELECT * FROM calendar_stop_crew WHERE stop_id = 500 AND user_id = 22"));

        $m = $this->row("SELECT * FROM visit_moves WHERE request_key = 'key-1'");
        $this->assertSame('pulled_forward_on_site', $m['reason']);
        $this->assertSame(22, (int)$m['moved_by']);
        $this->assertSame('2026-10-06', $m['from_date']);
        $this->assertSame('2026-10-05', $m['to_date']);
        $this->assertSame(1, (int)$m['from_stop_deleted']);
    }

    public function testStopWithOtherWorkIsKept(): void
    {
        $this->db->exec("INSERT INTO job_visits VALUES (2161, 'PLN-3-V1', 3, 501, '2026-10-06', 'scheduled', NULL, NULL, 10, NULL, 1)");
        $r = $this->svc()->pullForward(2160, 22, 'key-2');
        $this->assertFalse($r['old_stop_deleted']);
        $this->assertNotNull($this->row("SELECT * FROM calendar_stops WHERE id = 501"));
    }

    public function testCreatesTodaysStopWhenThereIsNone(): void
    {
        $this->db->exec("DELETE FROM job_visits WHERE id = 2150");
        $this->db->exec("DELETE FROM calendar_stops WHERE id = 500");
        $r = $this->svc()->pullForward(2159, 10, 'key-3');
        $stop = $this->row("SELECT * FROM calendar_stops WHERE id = " . (int)$r['stop_id']);
        $this->assertSame('2026-10-05', $stop['stop_date']);
        $this->assertSame(73, (int)$stop['property_id']);
        $this->assertSame(10, (int)$stop['crew_id']);
        $this->assertNull($this->row("SELECT * FROM calendar_stop_crew WHERE user_id = 10"), 'assigned crew needs no extra row');
    }

    public function testIdempotentByKeyAndByAlreadyToday(): void
    {
        $first  = $this->svc()->pullForward(2160, 22, 'same-key');
        $second = $this->svc()->pullForward(2160, 22, 'same-key');
        $third  = $this->svc()->pullForward(2160, 22, '');

        $this->assertFalse($first['already']);
        $this->assertTrue($second['already']);
        $this->assertSame($first['stop_id'], $second['stop_id']);
        $this->assertTrue($third['already']);
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM visit_moves")->fetchColumn());
    }

    public function testRefusesSkippedOutOfWindowAndWrongProperty(): void
    {
        $this->db->exec("UPDATE job_visits SET status = 'skipped' WHERE id = 2159");
        $this->assertFalse($this->svc()->pullForward(2159, 22)['success']);
        $this->assertFalse($this->svc()->pullForward(2170, 22)['success'], '+8 days is outside the window');
        $this->assertSame(409, $this->svc()->pullForward(2160, 22, '', 80)['status']);
        $this->assertSame('2026-10-06', $this->row("SELECT scheduled_date FROM job_visits WHERE id = 2160")['scheduled_date']);
    }

    public function testRecurringSeriesIsNotShifted(): void
    {
        $planBefore = $this->row("SELECT * FROM job_plans WHERE id = 1");
        $this->svc()->pullForward(2160, 22, 'k');

        $this->assertSame($planBefore, $this->row("SELECT * FROM job_plans WHERE id = 1"), 'plan untouched');
        $this->assertSame('2026-10-13', $this->row("SELECT scheduled_date FROM job_visits WHERE id = 2170")['scheduled_date'],
            "next week's visit stays on its day");

        // Generation is calendar-anchored (weekday from plan_start_date), not "N days after the last
        // completion": the Tuesday series still lands on Tuesdays after a visit is done on a Monday.
        $dates = VisitGenerationService::calculateRecurrenceDates(
            ['recurrence_pattern' => 'weekly', 'recurrence_interval' => 1, 'recurrence_interval_unit' => 'weeks',
             'recurrence_day_of_week' => '2', 'plan_start_date' => '2026-04-07', 'blackout_dates' => null],
            '2026-10-08', '2026-10-28'
        );
        $this->assertSame(['2026-10-13', '2026-10-20', '2026-10-27'], $dates);
    }

    public function testContractPlanBehavesTheSame(): void
    {
        $this->db->exec("INSERT INTO properties VALUES (90, '100 Strata Way', 'Strata Court', 49.25, -123.10)");
        $this->db->exec("INSERT INTO job_plans VALUES (9, 90, 'active', 'Contract grounds', 'Lawn Cut', 'PLN-9', 1, 10, 4, '4', '2026-04-02', '2026-11-15')");
        $this->db->exec("INSERT INTO calendar_stops VALUES (600, 90, '2026-10-08', 10, 1, 'scheduled')");
        $this->db->exec("INSERT INTO job_visits VALUES (3000, 'PLN-9-V1', 9, 600, '2026-10-08', 'scheduled', NULL, NULL, 10, NULL, 1)");

        $offer = $this->svc()->offerForProperty(90);
        $this->assertSame(3000, $offer['primary']['visit_id']);
        $r = $this->svc()->pullForward(3000, 22, 'c-1', 90);
        $this->assertTrue($r['success']);
        $this->assertTrue($r['old_stop_deleted']);
    }

    // ── dry run (historical) ──────────────────────────────────────────────────

    public function testDryRunReconstructsWhatOct5WouldHaveOffered(): void
    {
        // What actually happened: both visits were timed and completed on Oct 5, still dated later.
        $this->db->exec("UPDATE job_visits SET status = 'completed', started_at = '2026-10-05 10:00', completed_at = '2026-10-05 11:00' WHERE id IN (2160, 2159)");
        $later = new VisitPullForwardService($this->db, '2026-10-08');

        $dry = $later->dryRun(73, '2026-10-05');
        $this->assertTrue($dry['read_only']);
        $this->assertSame(2160, $dry['offer']['primary']['visit_id']);
        $this->assertSame([2159], array_column($dry['offer']['others'], 'visit_id'));
        // Nothing written.
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM visit_moves")->fetchColumn());
    }

    // ── stop tidy (shared with moveVisit + auto_rollover) ─────────────────────

    public function testStopTidyKeepsSkippedHistoryAndReleasesCancelled(): void
    {
        $this->db->exec("INSERT INTO calendar_stops VALUES (700, 73, '2026-10-09', 10, 1, 'scheduled')");
        $this->db->exec("INSERT INTO job_visits VALUES (4000, 'X1', 1, 700, '2026-10-09', 'skipped', NULL, NULL, 10, NULL, 99)");
        $this->assertFalse(CalendarStopTidyService::deleteIfEmpty($this->db, 700));

        $this->db->exec("UPDATE job_visits SET status = 'cancelled' WHERE id = 4000");
        $this->db->exec("INSERT INTO calendar_stop_crew VALUES (700, 22)");
        $this->assertTrue(CalendarStopTidyService::deleteIfEmpty($this->db, 700));
        $this->assertNull($this->row("SELECT * FROM calendar_stop_crew WHERE stop_id = 700"));
        $this->assertNull($this->row("SELECT stop_id FROM job_visits WHERE id = 4000")['stop_id']);
        $this->assertFalse(CalendarStopTidyService::deleteIfEmpty($this->db, null));
    }
}
