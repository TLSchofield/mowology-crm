<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../fixtures/TripTrailFixture.php';

/**
 * Ask Charlie, SQL first: counts and totals from Otto's truck log and cost facts, Penny's
 * receipts and the schedule are answered without Claude; anything unsure falls through.
 * The trail is the real 2026-10-07 one with Lawn Boy at 10:15, 11:29 and 12:32.
 */
class CharlieFactAnswererTest extends TestCase
{
    private const TODAY = '2026-10-07';   // a Wednesday
    private string $log = '';

    protected function setUp(): void
    {
        $this->log = (string)ini_get('error_log');
        ini_set('error_log', '/dev/null');   // schema probes that SQLite can't answer log — expected
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->log);
    }

    // ── intent ──────────────────────────────────────────────────────────────

    public function test_intent(): void
    {
        $this->assertSame('visits', CharlieFactAnswerer::intent('How many visits were there today to lawnboy'));
        $this->assertSame('visits', CharlieFactAnswerer::intent('how many times did Nigel go to Lawn Boy today'));
        $this->assertSame('visits', CharlieFactAnswerer::intent('how many visits today'));
        $this->assertSame('spend', CharlieFactAnswerer::intent('what did we spend at lawnboy this month'));
        $this->assertSame('receipts', CharlieFactAnswerer::intent('how many receipts from Lawn Boy?'));
        $this->assertSame('cost', CharlieFactAnswerer::intent('what does a dump run cost'));
        $this->assertSame('cost', CharlieFactAnswerer::intent('what did dump runs cost this month'));
        $this->assertSame('time', CharlieFactAnswerer::intent('how long at the dump today'));
        $this->assertSame('time', CharlieFactAnswerer::intent('average time at lawnboy'));
        $this->assertNull(CharlieFactAnswerer::intent('is INV-2026-0042 paid?'));
        $this->assertNull(CharlieFactAnswerer::intent('has the Lawnboy slip been filed?'));
        $this->assertSame('visits', CharlieFactAnswerer::intent('has Nigel been to lawnboy today'));
    }

    // ── periods ─────────────────────────────────────────────────────────────

    private function p(string $q): ?array
    {
        $p = CharlieFactAnswerer::period($q, self::TODAY);
        return $p === null ? null : [$p['from'], $p['to']];
    }

    public function test_period_parsing(): void
    {
        $this->assertSame(['2026-10-07', '2026-10-07'], $this->p('visits today'));
        $this->assertSame(['2026-10-06', '2026-10-06'], $this->p('yesterday'));
        $this->assertSame(['2026-10-05', '2026-10-07'], $this->p('this week'));
        $this->assertSame(['2026-09-28', '2026-10-04'], $this->p('last week'));
        $this->assertSame(['2026-10-01', '2026-10-07'], $this->p('this month'));
        $this->assertSame(['2026-09-01', '2026-09-30'], $this->p('last month'));
        $this->assertSame(['2026-01-01', '2026-10-07'], $this->p('this year'));
        $this->assertSame(['2026-10-03', '2026-10-03'], $this->p('on Oct 3'));
        $this->assertSame(['2026-10-03', '2026-10-03'], $this->p('on october 3rd'));
        $this->assertSame(['2026-09-15', '2026-09-15'], $this->p('on 2026-09-15'));
        $this->assertSame(['2026-09-15', '2026-09-15'], $this->p('15 sept'));
        $this->assertSame(['2025-12-25', '2025-12-25'], $this->p('on dec 25'));   // never in the future
        $this->assertSame(['2026-09-30', '2026-10-07'], $this->p('since sep 30'));
        $this->assertSame(['2026-09-01', '2026-09-30'], $this->p('in September'));
        $this->assertSame(['2026-10-05', '2026-10-05'], $this->p('on monday'));
        $this->assertSame(['2026-09-30', '2026-09-30'], $this->p('last wednesday'));
        $this->assertNull($this->p('how many visits to lawnboy'));
        $this->assertSame('on Sat Oct 3', CharlieFactAnswerer::period('on oct 3', self::TODAY)['label']);
    }

    // ── fuzzy names ─────────────────────────────────────────────────────────

    private function places(): array
    {
        return [
            ['id' => 1, 'name' => 'Vancouver Transfer Station', 'kind' => 'dump', 'vendor_match' => 'transfer station|city of vancouver|landfill'],
            ['id' => 2, 'name' => 'Yard', 'kind' => 'yard', 'vendor_match' => null],
            ['id' => 3, 'name' => 'LAWNBOY', 'kind' => 'supplier', 'vendor_match' => 'lawnboy|lawn boy'],
        ];
    }

    private function place(string $q): ?string
    {
        $m = CharlieFactAnswerer::matchPlaces(CharlieFactAnswerer::tokens(CharlieFactAnswerer::norm($q)), $this->places());
        return $m === null ? null : $m['label'];
    }

    public function test_fuzzy_place_match(): void
    {
        $this->assertSame('LAWNBOY', $this->place('how many visits to lawnboy today'));
        $this->assertSame('LAWNBOY', $this->place('how many times did Nigel go to Lawn Boy today'));
        $this->assertSame('LAWNBOY', $this->place("time at Lawn Boy's"));
        $this->assertSame('Vancouver Transfer Station', $this->place('how long at the dump today'));
        $this->assertSame('Vancouver Transfer Station', $this->place('trips to the transfer station'));
        $this->assertSame('Vancouver Transfer Station', $this->place('visits to vancouver transfer station'));
        $this->assertSame('LAWNBOY', $this->place('supply runs this week'));
        $this->assertSame('Yard', $this->place('when did the truck leave the yard'));
        $this->assertNull($this->place('mow the backyard at Linda'));
        $this->assertNull($this->place('lawn mowing visits today'));
    }

    public function test_fuzzy_vendor_and_crew_match(): void
    {
        $vendors = [['id' => 31, 'name' => 'Lawn Boy', 'aliases' => 'Lawnboy'], ['id' => 40, 'name' => 'The Home Depot #7023', 'aliases' => '']];
        $t = fn($q) => CharlieFactAnswerer::tokens(CharlieFactAnswerer::norm($q));
        $this->assertSame(['term' => 'lawnboy', 'name' => 'Lawn Boy'], CharlieFactAnswerer::matchVendor($t('what did we spend at LAWNBOY this month'), $vendors));
        $this->assertSame(['term' => 'lawnboy', 'name' => 'Lawn Boy'], CharlieFactAnswerer::matchVendor($t('spent at lawn boy'), $vendors));
        $this->assertSame('The Home Depot #7023', CharlieFactAnswerer::matchVendor($t('spend at home depot last month'), $vendors)['name']);
        $this->assertNull(CharlieFactAnswerer::matchVendor($t('what did we spend this month'), $vendors));
        $crew = CharlieFactAnswerer::matchCrew($t('how many visits did nigel do'), [6 => 'Nigel Smith', 7 => 'Tim Schofield']);
        $this->assertSame(['id' => 6, 'first' => 'Nigel', 'name' => 'Nigel Smith'], $crew);
    }

    // ── on SQLite ───────────────────────────────────────────────────────────

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE vehicle_location_pings (id INTEGER PRIMARY KEY, lat REAL, lng REAL, speed_kph REAL, recorded_at TEXT)');
        $db->exec('CREATE TABLE ops_places (id INTEGER PRIMARY KEY, name TEXT, kind TEXT, lat REAL, lng REAL, radius_m INTEGER, vendor_match TEXT, active INTEGER DEFAULT 1)');
        $db->exec('CREATE TABLE properties (id INTEGER PRIMARY KEY, latitude REAL, longitude REAL, address TEXT, city TEXT, property_name TEXT)');
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT, is_active INTEGER DEFAULT 1, hourly_rate REAL)');
        $db->exec('CREATE TABLE time_clock_entries (id INTEGER PRIMARY KEY, user_id INTEGER, clock_in TEXT, clock_out TEXT, status TEXT)');
        $db->exec('CREATE TABLE vendors (id INTEGER PRIMARY KEY, name TEXT, aliases TEXT)');
        $db->exec('CREATE TABLE expenses (id INTEGER PRIMARY KEY, expense_date TEXT, vendor_id INTEGER, vendor_name_raw TEXT, total REAL, status TEXT)');
        $db->exec('CREATE TABLE ops_trip_runs (id INTEGER PRIMARY KEY, run_date TEXT, place_id INTEGER, kind TEXT, user_id INTEGER, from_property_id INTEGER,
                   arrived_at TEXT, departed_at TEXT, drive_min REAL, onsite_min REAL, km REAL, total REAL)');
        $db->exec('CREATE TABLE ops_cost_facts (id INTEGER PRIMARY KEY, fact_key TEXT, label TEXT, kind TEXT, place_id INTEGER, sample_n INTEGER,
                   median_onsite_min REAL, median_round_trip_min REAL, median_km REAL, median_trip_cost REAL, median_receipt REAL, receipt_n INTEGER,
                   median_cost REAL, avg_cost REAL, last_run_date TEXT)');
        $db->exec('CREATE TABLE job_visits (id INTEGER PRIMARY KEY, plan_id INTEGER, stop_id INTEGER, scheduled_date TEXT, status TEXT, assigned_crew_id INTEGER)');
        $db->exec('CREATE TABLE calendar_stops (id INTEGER PRIMARY KEY, crew_id INTEGER)');
        $db->exec('CREATE TABLE calendar_stop_crew (id INTEGER PRIMARY KEY, stop_id INTEGER, user_id INTEGER)');
        // CharlieAskService's own tables
        $db->exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, phone TEXT)');
        $db->exec('CREATE TABLE companies (id INTEGER PRIMARY KEY, company_name TEXT)');
        $db->exec('CREATE TABLE charlie_asks (id INTEGER PRIMARY KEY AUTOINCREMENT, question TEXT, terms TEXT, matched TEXT, source TEXT, answer TEXT,
                   model TEXT, input_tokens INTEGER, output_tokens INTEGER, cost_usd REAL, error TEXT, requested_by INTEGER, created_at TEXT)');
        $db->exec('CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)');

        $ins = $db->prepare('INSERT INTO vehicle_location_pings (lat, lng, speed_kph, recorded_at) VALUES (?, ?, ?, ?)');
        foreach (TripTrailFixture::real1007Full() as $p) $ins->execute([$p['lat'], $p['lng'], $p['speed_kph'], date('Y-m-d H:i:s', $p['t'])]);
        $ins = $db->prepare('INSERT INTO ops_places (id, name, kind, lat, lng, radius_m, vendor_match) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach (TripTrailFixture::places() as $p) $ins->execute([$p['id'], $p['name'], $p['kind'], $p['lat'], $p['lng'], $p['radius_m'], $p['vendor_match']]);
        $ins->execute([3, 'LAWNBOY', 'supplier', TripTrailFixture::LAWNBOY_REAL[0], TripTrailFixture::LAWNBOY_REAL[1], 150, 'lawnboy|lawn boy']);
        $db->prepare('INSERT INTO properties (id, latitude, longitude, address, city, property_name) VALUES (41, ?, ?, ?, ?, ?)')
           ->execute([TripTrailFixture::OAKRIDGE[0], TripTrailFixture::OAKRIDGE[1], '5800 Oak St', 'Vancouver', 'Oakridge Gardens']);
        $db->exec("INSERT INTO users (id, full_name) VALUES (6, 'Nigel Smith'), (7, 'Tim Schofield')");
        $db->exec("INSERT INTO time_clock_entries (user_id, clock_in, clock_out, status) VALUES (6, '2026-10-07 06:00:00', NULL, 'active')");

        $db->exec("INSERT INTO vendors VALUES (31, 'Lawn Boy', 'Lawnboy'), (12, 'City of Vancouver Vancouver Landfill', '')");
        $db->exec("INSERT INTO expenses VALUES
            (412, '2026-10-07', 31, 'LAWN BOY LANDSCAPE SUPPLY', 128.10, 'approved'),
            (413, '2026-10-03', 31, 'LAWN BOY', 64.00, 'pending_approval'),
            (414, '2026-10-07', 31, 'LAWN BOY LANDSCAPE SUPPLY', 128.10, 'rejected'),
            (415, '2026-09-20', NULL, 'LAWN BOY LANDSCAPE SUPPLY', 20.00, 'draft'),
            (411, '2026-10-07', 12, 'City of Vancouver', 27.00, 'approved')");

        $db->exec("INSERT INTO ops_trip_runs (run_date, place_id, kind, user_id, from_property_id, arrived_at, departed_at, drive_min, onsite_min, km, total) VALUES
            ('2026-10-05', 3, 'supplier', 6, 41, '2026-10-05 10:00:00', '2026-10-05 10:12:00', 20, 12, 9.5, 18.40),
            ('2026-10-06', 1, 'dump', 6, 41, '2026-10-06 09:50:00', '2026-10-06 10:02:00', 24, 12, 11.0, 51.20),
            ('2026-10-02', 1, 'dump', 7, 41, '2026-10-02 14:00:00', '2026-10-02 14:15:00', 26, 15, 11.0, NULL),
            ('2026-09-29', 1, 'dump', 6, 41, '2026-09-29 09:00:00', '2026-09-29 09:10:00', 22, 10, 11.0, 44.00)");
        $db->exec("INSERT INTO ops_cost_facts (fact_key, label, kind, place_id, sample_n, median_onsite_min, median_round_trip_min, median_km, median_trip_cost,
                   median_receipt, receipt_n, median_cost) VALUES
            ('run:dump:any', 'Dump run — any place', 'dump', NULL, 5, 12, 35, 11, 22, 27, 4, 49),
            ('run:dump:place:1', 'Dump run — Vancouver Transfer Station', 'dump', 1, 5, 12, 35, 11, 22, 27, 4, 49),
            ('run:supplier:place:3', 'Supply run — LAWNBOY', 'supplier', 3, 2, 9, 30, 9.5, 18, NULL, 0, 18)");

        $db->exec("INSERT INTO calendar_stops VALUES (90, 6), (91, 7)");
        $db->exec("INSERT INTO calendar_stop_crew (stop_id, user_id) VALUES (90, 6), (91, 7)");
        $db->exec("INSERT INTO job_visits (plan_id, stop_id, scheduled_date, status, assigned_crew_id) VALUES
            (1, 90, '2026-10-07', 'completed', NULL), (2, 90, '2026-10-07', 'completed', 6), (3, 90, '2026-10-07', 'scheduled', NULL),
            (4, 91, '2026-10-07', 'in_progress', 7), (5, 91, '2026-10-07', 'cancelled', 7), (6, 91, '2026-10-06', 'completed', 7)");
        return $db;
    }

    private function say(PDO $db, string $q): ?string
    {
        $a = (new CharlieFactAnswerer($db, self::TODAY))->answer($q);
        return $a === null ? null : $a['answer'];
    }

    public function test_visits_to_lawnboy_today_from_the_live_trail(): void
    {
        $db = $this->db();
        $want = "3 visits to LAWNBOY today: 10:15–10:24 (9 min), 11:29–11:34 (5 min), 12:32–12:42 (10 min) — all Nigel from Oakridge Gardens.\n— from Otto's truck log";
        $this->assertSame($want, $this->say($db, 'How many visits were there today to lawnboy'));
        $this->assertSame($want, $this->say($db, 'how many visits to Lawn Boy today?'));
        $this->assertSame($want, $this->say($db, 'how many visits to lawnboy'));   // no period → today
        $this->assertSame("Nigel went to LAWNBOY 3 times today: 10:15–10:24 (9 min), 11:29–11:34 (5 min), 12:32–12:42 (10 min) — all from Oakridge Gardens.\n— from Otto's truck log",
            $this->say($db, 'how many times did Nigel go to Lawn Boy today'));
        $this->assertSame("Tim didn't go to LAWNBOY today.\n— from Otto's truck log", $this->say($db, 'how many times did Tim go to lawnboy today'));
    }

    public function test_visits_over_a_week_join_past_runs_and_today(): void
    {
        $a = $this->say($this->db(), 'how many trips to lawnboy this week');
        $this->assertStringStartsWith('4 visits to LAWNBOY this week: Mon Oct 5 10:00–10:12 (12 min), Wed Oct 7 10:15–10:24 (9 min)', (string)$a);
        $this->assertStringEndsWith("— from Otto's truck log", (string)$a);
        $this->assertSame("No visits to LAWNBOY yesterday.\n— from Otto's truck log", $this->say($this->db(), 'how many visits to lawnboy yesterday'));
    }

    public function test_time_at_the_dump_today_and_typical_time(): void
    {
        $db = $this->db();
        $this->assertSame("9 min on site at Vancouver Transfer Station today over 1 visit: 9:51–10:00 (9 min).\n— from Otto's truck log",
            $this->say($db, 'how long at the dump today'));
        $this->assertSame("Not enough runs yet to say how long LAWNBOY usually takes (2/3 one-man runs).\n— from Otto's cost facts",
            $this->say($db, 'average time at lawnboy'));
        $this->assertSame("Usually about 12 min on site at Vancouver Transfer Station and 35 min round trip (median of 5 one-man runs).\n— from Otto's cost facts",
            $this->say($db, 'how long does the dump usually take'));
    }

    public function test_run_costs(): void
    {
        $db = $this->db();
        $this->assertSame("A dump run to Vancouver Transfer Station costs us about $49 (median of 5 one-man runs): 35 min round trip, 12 min on site, 11 km, dump fee about $27.\n— from Otto's cost facts",
            $this->say($db, 'what does a dump run cost'));
        $this->assertSame("Dump runs to Vancouver Transfer Station this month: 2 runs, $51.20 in all (time, truck and dump fees), 1 not priced (no pay rate); today's runs are priced overnight.\n— from Otto's truck log",
            $this->say($db, 'what did dump runs cost this month'));
        $this->assertSame("Not enough runs yet to cost a supply run to LAWNBOY (2/3 one-man runs with a pay rate).\n— from Otto's cost facts",
            $this->say($db, 'what does a run to lawnboy cost'));
    }

    public function test_penny_receipts_by_vendor(): void
    {
        $db = $this->db();
        $this->assertSame("We spent $192.10 at Lawn Boy this month: $128.10 approved (1 receipt), $64 waiting for approval (1).\n— from Penny's receipts",
            $this->say($db, 'what did we spend at lawnboy this month'));
        $this->assertSame("3 receipts from Lawn Boy this year, $212.10 in all: $128.10 approved (1 receipt), $84 waiting for approval (2).\n— from Penny's receipts",
            $this->say($db, 'how many receipts from Lawn Boy'));
        $this->assertSame("No receipts from Lawn Boy last year.\n— from Penny's receipts", $this->say($db, 'what did we spend at lawn boy last year'));
        $this->assertNull($this->say($db, 'what did we spend at Zelda this month'));   // unknown vendor → Claude
    }

    public function test_schedule_visits_today(): void
    {
        $db = $this->db();
        $this->assertSame("There are 4 visits today: 2 completed, 1 in progress, 1 still to do.\n— from the schedule", $this->say($db, 'how many visits today'));
        $this->assertSame("Nigel has 3 visits today: 2 completed, 1 still to do.\n— from the schedule", $this->say($db, 'how many visits did Nigel do today'));
        $this->assertSame("There was 1 visit yesterday: 1 completed.\n— from the schedule", $this->say($db, 'How many visits yesterday?'));
    }

    public function test_falls_through_when_unsure(): void
    {
        $db = $this->db();
        $this->assertNull($this->say($db, "Has Linda's quote been sent?"));
        $this->assertNull($this->say($db, 'how many visits to Linda today'));        // a client, not a place
        $this->assertNull($this->say($db, 'what is overdue?'));
        $this->assertNull($this->say($db, 'how many visits to the yard last month')); // non-overhead place, too many days to re-read
        $this->assertNull((new CharlieFactAnswerer(new PDO('sqlite::memory:'), self::TODAY))->answer('how many visits to lawnboy today'));
    }

    public function test_summary_gives_claude_counts_not_rows(): void
    {
        $s = (new CharlieFactAnswerer($this->db(), self::TODAY))->summary('is lawnboy cheaper than going to the other place?');
        $this->assertSame(['otto', 'facts', 'penny'], $s['heads']);
        $this->assertStringContainsString('Truck log (Otto): LAWNBOY this month (2026-10-01 to 2026-10-07) — 4 visits, 36 min on site in all', $s['lines'][0]);
        $this->assertStringContainsString('too few runs', $s['lines'][1]);
        $this->assertStringContainsString('Receipts (Penny): Lawn Boy this month — approved $128.10 (1), waiting for approval $64 (1)', $s['lines'][2]);
        $this->assertNull((new CharlieFactAnswerer($this->db(), self::TODAY))->summary('has Zelda paid?'));
    }

    // ── through CharlieAskService ───────────────────────────────────────────

    public function test_ask_answers_facts_free_and_logs_them(): void
    {
        $db = $this->db();
        $calls = 0;
        $svc = new CharlieAskService($db, function () use (&$calls) { $calls++; return ['code' => 500, 'body' => '']; }, self::TODAY);
        $r = $svc->ask('How many visits were there today to lawnboy', 7);
        $this->assertTrue($r['ok']);
        $this->assertSame('facts', $r['source']);
        $this->assertSame(0, $calls);
        $this->assertSame(30, $r['left']);
        $this->assertSame(['ok', 'answer', 'source', 'left'], array_keys($r));
        $row = $db->query('SELECT source, terms, input_tokens, output_tokens, requested_by FROM charlie_asks')->fetch();
        $this->assertSame(['facts', 'LAWNBOY', 0, 0, 7], [$row['source'], $row['terms'], (int)$row['input_tokens'], (int)$row['output_tokens'], (int)$row['requested_by']]);
    }

    public function test_ask_gives_claude_the_summary_and_says_where_it_came_from(): void
    {
        $db = $this->db();
        $body = null;
        $svc = new CharlieAskService($db, function (array $b) use (&$body) {
            $body = $b;
            return ['code' => 200, 'body' => json_encode(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Lawnboy is close to Oakridge.']],
                                                          'usage' => ['input_tokens' => 900, 'output_tokens' => 40]])];
        }, self::TODAY);
        $r = $svc->ask('is lawnboy a good place for mulch?', 7);
        $this->assertSame('claude', $r['source']);
        $prompt = $body['messages'][0]['content'][0]['text'];
        $this->assertStringContainsString('"facts"', $prompt);
        $this->assertStringContainsString('Truck log (Otto): LAWNBOY', $prompt);
        $this->assertStringNotContainsString('10:15', $prompt);   // counts, not raw stops
        $this->assertSame("Lawnboy is close to Oakridge.\n— from Otto's truck log and Otto's cost facts and Penny's receipts", $r['answer']);
    }
}
