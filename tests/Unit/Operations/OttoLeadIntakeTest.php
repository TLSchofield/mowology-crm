<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Operations/Services/LeadRouteDay.php';
require_once __DIR__ . '/../../../app/Modules/Operations/Services/OttoLeadService.php';

/**
 * Otto on a new lead (2026-11-03): the best working day to look at it from the crews' real route days,
 * and the catch-up that gives its property a pin and an arrival border without waiting six hours.
 */
class OttoLeadIntakeTest extends TestCase
{
    private const TODAY = '2026-11-03';   // Tuesday
    private const DEPOT = ['lat' => 49.2500, 'lng' => -123.1000];
    // The lead: West 14th Ave, Kitsilano.
    private const LAT = 49.2594;
    private const LNG = -123.1730;

    private function day(string $date, array $stops, int $crew = 6): array
    {
        return ['date' => $date, 'crew_id' => $crew, 'stops' => array_map(static fn($s) => ['lat' => $s[0], 'lng' => $s[1], 'property_id' => 1], $stops)];
    }

    public function test_the_day_the_crew_is_next_door_beats_a_day_across_town(): void
    {
        $days = [
            $this->day('2026-11-04', [[49.3000, -123.0300]]),                    // Wed: far east
            $this->day('2026-11-06', [[49.2596, -123.1735], [49.2600, -123.1700]]),  // Fri: two stops within 500 m
        ];
        $r = LeadRouteDay::rank(self::LAT, self::LNG, $days, [], [], self::DEPOT, 5, self::TODAY);
        $this->assertSame('2026-11-06', $r['best']['date']);
        $this->assertSame('Fri Nov 6', $r['best']['label']);
        $this->assertSame('route', $r['best']['basis']);
        $this->assertSame(2, $r['best']['neighbours']);
        $this->assertStringContainsString('2 stops within 500 m, adds ', $r['best']['text']);
        $this->assertSame('2026-11-04', $r['alternatives'][0]['date']);   // the far day is an alternative, with its number
        $this->assertGreaterThan($r['best']['minutes'], $r['alternatives'][0]['minutes']);
        $this->assertStringStartsWith('Best day to look: Fri Nov 6 (adds ', LeadRouteDay::line($r));
        $this->assertStringStartsWith('Best day to start: Fri Nov 6', LeadRouteDay::line($r, 'start'));
    }

    public function test_sundays_and_solid_days_are_blocked_and_a_wet_day_ranks_after_a_dry_one(): void
    {
        $days = [
            $this->day('2026-11-08', [[49.2596, -123.1735]]),   // Sunday
            $this->day('2026-11-05', [[49.2596, -123.1735]]),   // Thu: perfect, but booked solid
            $this->day('2026-11-06', [[49.2597, -123.1736]]),   // Fri: perfect, wet
            $this->day('2026-11-09', [[49.2700, -123.1500]]),   // Mon: ok, dry
        ];
        $loads = ['2026-11-05' => ['minutes' => 2390, 'visits' => 20, 'untimed' => 0, 'biggest' => ['plan_id' => 1, 'title' => '', 'minutes' => 120, 'crew' => 1]]];   // 2,400 capacity at 5 crew
        $flags = ['2026-11-06' => ['wet' => true]];
        $r = LeadRouteDay::rank(self::LAT, self::LNG, $days, $loads, $flags, self::DEPOT, 5, self::TODAY);
        $this->assertSame('Sunday or a rest day', $r['blocked']['2026-11-08']);
        $this->assertSame('booked solid', $r['blocked']['2026-11-05']);
        $this->assertSame('2026-11-09', $r['best']['date']);                         // dry beats the closer wet day
        $this->assertNotContains('2026-11-06', array_column($r['alternatives'], 'date'));   // dry round-trip days still rank ahead of a wet route day
        // When every day is flagged wet, the best one says so instead of hiding it.
        $allWet = [];
        for ($i = 1; $i <= 14; $i++) $allWet[date('Y-m-d', strtotime(self::TODAY . " +{$i} days"))] = ['wet' => true];
        $w = LeadRouteDay::rank(self::LAT, self::LNG, $days, $loads, $allWet, self::DEPOT, 5, self::TODAY);
        $this->assertSame('2026-11-06', $w['best']['date']);
        $this->assertStringContainsString('the weather guard flags it', $w['best']['text']);
    }

    public function test_a_day_with_no_route_is_a_round_trip_from_the_yard_and_no_yard_is_na(): void
    {
        $r = LeadRouteDay::rank(self::LAT, self::LNG, [], [], [], self::DEPOT, 5, self::TODAY);
        $this->assertSame('2026-11-04', $r['best']['date']);                          // nothing planned: first working day
        $this->assertSame('round_trip', $r['best']['basis']);
        $this->assertStringContainsString('no route that day; a trip from the yard is', $r['best']['text']);
        $this->assertStringContainsString('no route, ', LeadRouteDay::line($r));
        $none = LeadRouteDay::rank(self::LAT, self::LNG, [], [], [], null, 5, self::TODAY);
        $this->assertNull($none['best']);
        $this->assertSame('Best day to look: n/a, no day in the next 14 fits', LeadRouteDay::line($none));
        $this->assertCount(2, $r['alternatives']);
    }

    public function test_no_pin_is_stated_not_guessed_and_a_suspect_day_is_noted_not_trusted(): void
    {
        $r = LeadRouteDay::rank(0.0, 0.0, [], [], [], self::DEPOT, 5, self::TODAY);
        $this->assertNull($r['best']);
        $this->assertSame('no map pin yet', $r['none']);
        $loads = ['2026-11-04' => ['minutes' => 3200, 'visits' => 43, 'untimed' => 0, 'biggest' => ['plan_id' => 99, 'title' => 'WEEKLY LAWN CUT', 'minutes' => 1319, 'crew' => 1]]];
        $days = [$this->day('2026-11-04', [[49.2596, -123.1735]])];
        $r = LeadRouteDay::rank(self::LAT, self::LNG, $days, $loads, [], self::DEPOT, 5, self::TODAY);
        $this->assertSame('2026-11-04', $r['best']['date']);                          // 3,200 would be booked solid; without the 1,319-minute plan it is 78%
        $this->assertStringContainsString("plan #99's length looks wrong and is left out", $r['best']['text']);   // and says so
        // Still unbelievable after setting the odd plan aside: the load is unknown, not "booked solid".
        $loads = ['2026-11-04' => ['minutes' => 15392, 'visits' => 43, 'untimed' => 0, 'biggest' => ['plan_id' => 99, 'title' => '', 'minutes' => 400, 'crew' => 30]]];
        $r = LeadRouteDay::rank(self::LAT, self::LNG, $days, $loads, [], self::DEPOT, 5, self::TODAY);
        $this->assertStringContainsString('its load is unknown (a plan length looks wrong)', $r['best']['text']);
    }

    public function test_near_ties_go_to_the_earlier_day_and_a_close_stop_is_under_a_minute_not_zero(): void
    {
        // Two days with the crew about the same distance away: the earlier wins when the minutes round the same.
        $days = [$this->day('2026-11-12', [[49.2597, -123.1736]]), $this->day('2026-11-05', [[49.2598, -123.1737]])];
        $r = LeadRouteDay::rank(self::LAT, self::LNG, $days, [], [], self::DEPOT, 5, self::TODAY);
        $this->assertSame('2026-11-05', $r['best']['date']);
        $this->assertSame('under 1 min', LeadRouteDay::mins(0.2));
        $this->assertSame('1 min', LeadRouteDay::mins(0.6));
        $this->assertSame('4 min', LeadRouteDay::mins(4.4));
        $this->assertStringContainsString('adds under 1 min', $r['best']['text']);
    }

    public function test_a_service_visit_needs_its_own_length(): void
    {
        $loads = ['2026-11-04' => ['minutes' => 2000, 'visits' => 10, 'untimed' => 0, 'biggest' => ['plan_id' => 1, 'title' => '', 'minutes' => 100, 'crew' => 1]]];
        $days = [$this->day('2026-11-04', [[49.2596, -123.1735]])];
        $look = LeadRouteDay::rank(self::LAT, self::LNG, $days, $loads, [], self::DEPOT, 5, self::TODAY, 30);
        $service = LeadRouteDay::rank(self::LAT, self::LNG, $days, $loads, [], self::DEPOT, 5, self::TODAY, 600);
        $this->assertSame('2026-11-04', $look['best']['date']);                       // 2,030 of 2,400: fits
        $this->assertArrayHasKey('2026-11-04', $service['blocked']);                  // 2,600 of 2,400: does not
        $this->assertNotSame('2026-11-04', $service['best']['date'] ?? null);
    }

    // ── the catch-up ─────────────────────────────────────────────────────────

    public function test_due_needs_no_border_and_a_wait_after_a_failed_try(): void
    {
        $now = '2026-11-03 15:00:00';
        $this->assertFalse(OttoLeadService::due(true, null, $now));
        $this->assertTrue(OttoLeadService::due(false, null, $now));
        $this->assertFalse(OttoLeadService::due(false, '2026-11-03 13:00:00', $now));  // 2 h ago
        $this->assertTrue(OttoLeadService::due(false, '2026-11-03 11:45:00', $now));   // 3 h 15 min ago
    }

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT, city TEXT, province TEXT, postal_code TEXT, latitude REAL, longitude REAL, geocoded_at TEXT)");
        $db->exec("CREATE TABLE quote_requests (id INTEGER PRIMARY KEY, property_id INT, status TEXT, quote_id INT, created_at TEXT)");
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)");
        return $db;
    }

    public function test_open_leads_are_candidates_and_quoted_or_old_or_declined_ones_are_not(): void
    {
        $db = $this->db();
        foreach ([[1, '3036 W 14th Ave'], [2, '2945 W 12th Ave'], [3, '1949 Beach Ave'], [4, '3568 W 28th Ave'], [5, 'x']] as [$id, $a]) $db->exec("INSERT INTO properties (id, address, city) VALUES ({$id}, '{$a}', 'Vancouver')");
        $db->exec("INSERT INTO quote_requests VALUES (1, 1, 'new', NULL, '2026-11-02 10:00:00')");
        $db->exec("INSERT INTO quote_requests VALUES (2, 2, 'reviewing', NULL, '2026-10-20 10:00:00')");    // 14 days old: still a lead
        $db->exec("INSERT INTO quote_requests VALUES (3, 3, 'new', 77, '2026-11-02 10:00:00')");            // already quoted
        $db->exec("INSERT INTO quote_requests VALUES (4, 4, 'new', NULL, '2026-09-01 10:00:00')");          // older than 30 days
        $db->exec("INSERT INTO quote_requests VALUES (5, NULL, 'new', NULL, '2026-11-02 10:00:00')");       // no property (no address)
        $c = (new OttoAutoPlaceService($db, null, new ParcelLotService(), self::TODAY))->leadCandidates();
        $this->assertSame([1, 2], array_keys($c));
    }

    public function test_placing_a_new_lead_is_off_with_the_switch_and_says_so_for_no_address_or_a_street_match(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO properties (id, address, city) VALUES (1, '3036 W 14th Ave', 'Vancouver'), (2, '', 'Vancouver'), (3, '99 Nowhere Rd', 'Vancouver')");
        $mk = fn(callable $g) => new OttoAutoPlaceService($db, $g, new ParcelLotService(), self::TODAY);
        $street = static fn(string $a): array => ['lat' => 49.26, 'lng' => -123.17, 'by' => 'osm', 'exact' => false];   // an area match, not the building
        $off = $mk($street)->placeNewLead(1);
        $this->assertFalse($off['ok']);
        $this->assertStringContainsString('switched off', $off['message']);
        $db->exec("INSERT INTO ops_settings VALUES ('otto_auto_place', '1', '')");
        $this->assertSame('No address yet — ask for it.', $mk($street)->placeNewLead(2)['message']);
        $this->assertStringContainsString('did not match a building', $mk($street)->placeNewLead(3)['message']);
        $this->assertNull($db->query("SELECT latitude FROM properties WHERE id = 3")->fetchColumn() ?: null);   // an area match never becomes a pin
        $this->assertSame('That property no longer exists.', $mk($street)->placeNewLead(404)['message']);
    }
}
