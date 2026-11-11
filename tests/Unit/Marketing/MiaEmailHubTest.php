<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MiaHubTestDb.php';

/**
 * Mia's email hub end to end on SQLite: the calendar proposes what's due (held in Stage 2/3),
 * approval schedules each send at the person's best time inside the window, the reminder goes
 * only to people who haven't answered or unsubscribed, and results are kept by year.
 */
class MiaEmailHubTest extends TestCase
{
    private MiaHubTestDb $db;

    protected function setUp(): void
    {
        $d = $this->db = new MiaHubTestDb();
        $d->now = '2026-09-10 09:00:00';
        $d->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, is_active INT DEFAULT 1)");
        $d->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, site_contact_id INT, latitude REAL, longitude REAL, property_type TEXT, property_manager_id INT)");
        $d->exec("CREATE TABLE companies (id INTEGER PRIMARY KEY, company_type TEXT, primary_contact_id INT, billing_contact_id INT)");
        $d->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, property_id INT, status TEXT, created_at TEXT, service_type TEXT, title TEXT)");
        $d->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, plan_id INT, status TEXT, scheduled_date TEXT)");
        $d->exec("CREATE TABLE consent_ledger (contact_id INT, channel TEXT, consent_type TEXT, source TEXT, proof_key TEXT, proof_text TEXT, granted_at TEXT, expires_at TEXT)");
        $d->exec("CREATE TABLE marketing_unsubscribes (email TEXT)");
        $d->exec("CREATE TABLE quotes (id INTEGER PRIMARY KEY, contact_id INT, property_id INT, status TEXT, valid_until TEXT, created_at TEXT, total_amount REAL, amount REAL)");
        $d->exec("CREATE TABLE quote_requests (id INTEGER PRIMARY KEY, contact_id INT, status TEXT, quote_id INT, created_at TEXT)");
        $d->exec("CREATE TABLE mia_mutes (subject_key TEXT PRIMARY KEY, until_date TEXT)");
        $d->exec("CREATE TABLE mia_questions (subject_key TEXT, kind TEXT, answer TEXT)");
        $d->exec("CREATE TABLE mia_campaigns (id INTEGER PRIMARY KEY, campaign_key TEXT UNIQUE, calendar_key TEXT, season_year INT, send_from TEXT, send_to TEXT,
                  name TEXT, why TEXT, subject TEXT, body_text TEXT, sequence_json TEXT, sequence_state_json TEXT, flags_json TEXT, photo_json TEXT, audience_json TEXT,
                  status TEXT DEFAULT 'proposed', marketing_campaign_id INT, recipients INT, decided_by INT, decided_at TEXT, created_at TEXT)");
        $d->exec("CREATE TABLE marketing_campaigns (id INTEGER PRIMARY KEY, name TEXT, segment_type TEXT, segment_rules TEXT, trigger_type TEXT, auto_send INT,
                  subject_override TEXT, body_override TEXT, status TEXT, recipient_count INT, created_by INT)");
        $d->exec("CREATE TABLE campaign_sends (id INTEGER PRIMARY KEY, campaign_id INT, contact_id INT, email TEXT, status TEXT, sent_at TEXT, opened_at TEXT, clicked_at TEXT,
                  created_at TEXT, step INT DEFAULT 0, parent_send_id INT, scheduled_at TEXT, replied_at TEXT, booked_at TEXT)");
        $d->exec("CREATE TABLE sales_messages (id INTEGER PRIMARY KEY, contact_id INT, direction TEXT, sent_at TEXT, snippet TEXT)");
        $d->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)");
        $d->exec("CREATE TABLE mia_send_times (contact_id INTEGER PRIMARY KEY, best_dow INT, best_hour INT, weight REAL, events INT, computed_at TEXT)");
        $d->exec("CREATE TABLE mia_calendar_results (id INTEGER PRIMARY KEY, calendar_key TEXT, season_year INT, mia_campaign_id INT, sent INT, clicked INT, replied INT,
                  quoted INT, booked INT, booked_amount REAL, updated_at TEXT, UNIQUE (calendar_key, season_year))");
        $d->exec("CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, service_type TEXT, base_price REAL, min_price REAL, active INT)");
        $d->exec("INSERT INTO products VALUES (1, 'Core aeration', 'aeration', 120, 95, 1), (2, 'Overseeding', 'overseed', 90, 0, 1), (3, 'Top-dressing', 'topdress', 60, 0, 1)");

        // 1–3 homeowners on plans (far apart: no neighbours), 4 a property manager.
        foreach ([1 => 'Ann', 2 => 'Ben', 3 => 'Cy', 4 => 'Pat'] as $i => $n) {
            $d->exec("INSERT INTO contacts (id, first_name, last_name, email) VALUES ($i, '$n', 'L', '" . strtolower($n) . "@x.com')");
            $d->exec("INSERT INTO consent_ledger VALUES ($i, 'email', 'implied', 'completed_visit', 'visit:$i', 'p', '2025-11-01', '2027-11-01')");
        }
        $d->exec("INSERT INTO properties VALUES (1, 1, 49.20, -123.10, 'single_family', NULL), (2, 2, 49.25, -123.00, 'single_family', NULL),
                  (3, 3, 49.30, -122.90, 'single_family', NULL), (4, NULL, 49.10, -122.80, 'commercial', 9)");
        $d->exec("INSERT INTO companies VALUES (9, 'property_manager', 4, NULL)");
        $d->exec("INSERT INTO job_plans (id, property_id, status) VALUES (1, 1, 'active'), (2, 2, 'active'), (3, 3, 'active'), (4, 4, 'active')");
    }

    private function stage(?int $stage, string $at = '2026-09-10 05:20:00'): void
    {
        $this->db->exec("DELETE FROM ops_settings WHERE setting_key = 'mia_water_restriction'");
        $v = json_encode(['stage' => $stage, 'checked_at' => $at, 'source' => 'metro', 'season_year' => 2026, 'season_max' => max(3, (int)$stage)]);
        $this->db->prepare("INSERT INTO ops_settings VALUES ('mia_water_restriction', ?, '')")->execute([$v]);
    }

    private function keys(): array
    {
        return $this->db->query("SELECT campaign_key FROM mia_campaigns ORDER BY campaign_key")->fetchAll(PDO::FETCH_COLUMN);
    }

    public function test_stage_2_holds_lawn_seeding_and_stage_1_lets_it_through(): void
    {
        $c = new MiaCampaignService($this->db);
        $this->assertTrue($c->hubReady());
        $this->stage(2);
        $c->propose(new DateTimeImmutable('2026-09-10'));
        $this->assertSame(['pm_snow_signup_2026'], $this->keys(), 'fall seeding held in Stage 2; snow sign-up goes ahead');

        $card = (new MiaEmailHubService($this->db))->card(new DateTimeImmutable('2026-09-10'));
        $this->assertSame('Stage 2 · checked today', $card['water']['label']);
        $states = array_column($card['next'], 'state', 'name');
        $this->assertStringStartsWith('Held: Stage 2', $states['October lawn renovation + bulbs']);
        $this->assertSame('Waiting for your OK', $states['Snow and salt — sign-up before October 1']);

        $this->stage(1);
        $this->assertSame(1, $c->propose(new DateTimeImmutable('2026-09-11')));
        $this->assertSame(['fall_lawn_main_2026', 'pm_snow_signup_2026'], $this->keys());
        $r = $this->db->query("SELECT * FROM mia_campaigns WHERE campaign_key = 'fall_lawn_main_2026'")->fetch();
        $this->assertSame('October lawn renovation + bulbs (2026)', $r['name']);
        $this->assertStringContainsString('Aeration starts at $95 and overseeding at $90.', $r['body_text'], 'real CRM prices');
        $this->assertSame(['clients' => 3, 'neighbours' => 0, 'considered' => 3, 'consented' => 3], json_decode($r['audience_json'], true),
            'all clients: the 3 homeowners, PM property has no site contact');
        $steps = json_decode($r['sequence_json'], true);
        $this->assertSame([1, 2], array_column($steps, 'step'));
        $this->assertSame([14, 23], array_column($steps, 'days'));
    }

    public function test_the_proposal_shows_the_whole_sequence_and_approval_schedules_best_times(): void
    {
        $this->stage(1);
        $c = new MiaCampaignService($this->db);
        $c->propose(new DateTimeImmutable('2026-09-10'));
        $this->db->exec("DELETE FROM mia_campaigns WHERE campaign_key <> 'fall_lawn_main_2026'");
        $cur = $c->current(new DateTimeImmutable('2026-09-10'));
        $this->assertSame('includes a reminder on Sep 29 and a last call Oct 8', $cur['sequence_note']);
        $this->assertSame('Sep 15 to Sep 24', $cur['send_window']);
        $this->assertSame([], $cur['flags']);

        // Ann has learned Thursday 8 pm; the others get the homeowner default (Tue 7:30 pm).
        $this->db->exec("INSERT INTO mia_send_times VALUES (1, 4, 20, 5, 2, '2026-09-01')");
        $id = (int)$cur['id'];
        $r = $c->approve($id, ['id' => 1], null, null, new DateTimeImmutable('2026-09-10'));
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $rows = $this->db->query("SELECT contact_id, step, scheduled_at, status FROM campaign_sends ORDER BY contact_id")->fetchAll();
        $this->assertSame([
            ['contact_id' => 1, 'step' => 0, 'scheduled_at' => '2026-09-17 20:00:00', 'status' => 'pending'],
            ['contact_id' => 2, 'step' => 0, 'scheduled_at' => '2026-09-15 19:30:00', 'status' => 'pending'],
            ['contact_id' => 3, 'step' => 0, 'scheduled_at' => '2026-09-15 19:30:00', 'status' => 'pending'],
        ], $rows);
    }

    public function test_approval_is_refused_when_stage_2_arrives_or_the_words_suggest_watering(): void
    {
        $this->stage(1);
        $c = new MiaCampaignService($this->db);
        $c->propose(new DateTimeImmutable('2026-09-10'));
        $id = (int)$this->db->query("SELECT id FROM mia_campaigns WHERE campaign_key = 'fall_lawn_main_2026'")->fetchColumn();
        $this->stage(2);
        $r = $c->approve($id, ['id' => 1], null, null, new DateTimeImmutable('2026-09-10'));
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Held: Stage 2', $r['error']);

        $snow = (int)$this->db->query("SELECT id FROM mia_campaigns WHERE campaign_key = 'pm_snow_signup_2026'")->fetchColumn();
        $r = $c->approve($snow, ['id' => 1], 'Snow', "Hi {{first_name}},\n\nWater the lawn on your day and the grass stays green.\n\nThanks,\nTim", new DateTimeImmutable('2026-09-10'));
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Stage 2 is on: no lawn may be watered', $r['error']);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM campaign_sends")->fetchColumn());
    }

    public function test_the_lawn_watering_check(): void
    {
        $no = ['The watering restrictions lift on October 15.', 'Late October is the time to blow out the sprinkler system before frost.',
               'Aeration opens up compacted soil so rain and air reach the roots.', 'Mulch holds in what moisture there is.'];
        foreach ($no as $t) $this->assertFalse(MiaCampaignService::mentionsLawnWatering($t), $t);
        $yes = ['Keep the new seed watered every morning.', 'Water your lawn once a week.', 'Run the sprinklers on the grass at night.', 'A lawn needs an inch of water a week.'];
        foreach ($yes as $t) $this->assertTrue(MiaCampaignService::mentionsLawnWatering($t), $t);
        $this->assertSame([], MiaCampaignService::problems('Hi', 'Keep the new seed watered every morning.', 1), 'Stage 1 allows lawn watering');
        $this->assertNotEmpty(MiaCampaignService::problems('Hi', 'Keep the new seed watered every morning.', 3));
    }

    public function test_the_reminder_goes_only_to_people_who_have_not_answered(): void
    {
        $this->stage(1);
        $c = new MiaCampaignService($this->db);
        $c->propose(new DateTimeImmutable('2026-09-10'));
        $id = (int)$this->db->query("SELECT id FROM mia_campaigns WHERE campaign_key = 'fall_lawn_main_2026'")->fetchColumn();
        $this->assertTrue($c->approve($id, ['id' => 1], null, null, new DateTimeImmutable('2026-09-10'))['ok']);
        $this->db->exec("UPDATE mia_campaigns SET decided_at = '2026-09-10 09:00:00'");
        $this->db->exec("UPDATE campaign_sends SET status = 'sent', sent_at = '2026-09-15 19:30:00'");
        $this->db->exec("INSERT INTO sales_messages VALUES (1, 1, 'inbound', '2026-09-16 08:00:00', 'Yes please')"); // Ann replied
        $this->db->exec("INSERT INTO marketing_unsubscribes VALUES ('ben@x.com')");                                   // Ben unsubscribed

        $seq = new MiaSequenceService($this->db);
        $this->assertSame(['queued' => 0, 'stamped' => 1], $seq->run(new DateTimeImmutable('2026-09-20 05:20:00')), 'not due yet; Ann\'s reply stamped');
        $r = $seq->run(new DateTimeImmutable('2026-09-29 05:20:00'));
        $this->assertSame(1, $r['queued']);
        $f = $this->db->query("SELECT cs.contact_id, cs.step, cs.parent_send_id, cs.scheduled_at, mc.subject_override, mc.status
                               FROM campaign_sends cs JOIN marketing_campaigns mc ON mc.id = cs.campaign_id WHERE cs.step = 1")->fetch();
        $this->assertSame(3, (int)$f['contact_id'], 'only Cy');
        $this->assertSame('Your October lawn timeslot', $f['subject_override']);
        $this->assertSame('sending', $f['status']);
        $this->assertSame('2026-09-29 19:30:00', $f['scheduled_at'], 'Tuesday evening');
        $this->assertSame(0, $seq->run(new DateTimeImmutable('2026-09-29 06:00:00'))['queued'], 'once');
        $this->assertNotNull($this->db->query("SELECT replied_at FROM campaign_sends WHERE contact_id = 1 AND step = 0")->fetchColumn());

        $this->db->exec("INSERT INTO quotes (id, contact_id, property_id, status, created_at) VALUES (5, 3, 3, 'accepted', '2026-10-01 10:00:00')");
        $this->assertSame(0, $seq->run(new DateTimeImmutable('2026-10-08 05:20:00'))['queued'], 'Cy booked: no last call');
        $summary = $seq->summary(new DateTimeImmutable('2026-10-06'));
        $this->assertSame('last call', $summary[0]['next_step']);
        $this->assertSame(1, $summary[0]['followups']);

        $hub = new MiaEmailHubService($this->db);
        $this->assertSame(1, $hub->snapshotResults());
        $res = $this->db->query("SELECT sent, replied, booked FROM mia_calendar_results WHERE calendar_key = 'fall_lawn_main' AND season_year = 2026")->fetch();
        $this->assertSame([3, 1, 1], [(int)$res['sent'], (int)$res['replied'], (int)$res['booked']]);
    }

    public function test_reminder_eligibility_rules(): void
    {
        $send = ['contact_id' => 5, 'email' => 'E@x.com', 'status' => 'sent', 'sent_at' => '2026-09-15 19:30:00'];
        $at = fn($d) => new DateTimeImmutable($d);
        $this->assertSame('not due yet', MiaSequenceService::eligible($send, 14, $at('2026-09-28 23:00'), [], [], [])['reason']);
        $this->assertTrue(MiaSequenceService::eligible($send, 14, $at('2026-09-29 00:10'), [], [], [])['ok']);
        $this->assertTrue(MiaSequenceService::eligible($send, 14, $at('2026-10-06 22:00'), [], [], [])['ok']);
        $this->assertSame('too late', MiaSequenceService::eligible($send, 14, $at('2026-10-07 00:01'), [], [], [])['reason']);
        $this->assertSame('responded', MiaSequenceService::eligible($send, 14, $at('2026-09-29'), [5 => true], [], [])['reason']);
        $this->assertSame('unsubscribed', MiaSequenceService::eligible($send, 14, $at('2026-09-29'), [], ['e@x.com' => true], [])['reason']);
        $this->assertSame('already has it', MiaSequenceService::eligible($send, 14, $at('2026-09-29'), [], [], [5 => true])['reason']);
        $this->assertSame('main email not sent', MiaSequenceService::eligible(['status' => 'skipped'] + $send, 14, $at('2026-09-29'), [], [], [])['reason']);

        $resp = MiaSequenceService::responses([$send],
            [['contact_id' => 5, 'sent_at' => '2026-09-01 10:00:00']],                       // before the email: not a reply to it
            [['contact_id' => 5, 'created_at' => '2026-09-20 10:00:00', 'status' => 'sent']],
            []);
        $this->assertSame(['replied_at' => null, 'quoted_at' => '2026-09-20 10:00:00', 'booked_at' => null], $resp[5]);
    }

    public function test_what_worked_reads_best_bookings_first(): void
    {
        $lines = MiaEmailHubService::whatWorked([
            ['calendar_key' => 'fall_lawn_main', 'season_year' => 2025, 'sent' => 212, 'booked' => 14, 'booked_amount' => 9800, 'replied' => 30],
            ['calendar_key' => 'hedge_before_nesting', 'season_year' => 2026, 'sent' => 180, 'booked' => 3, 'booked_amount' => 0, 'replied' => 9],
            ['calendar_key' => 'moss_lawn_care', 'season_year' => 2026, 'sent' => 0, 'booked' => 0, 'booked_amount' => 0, 'replied' => 0],
        ], MiaCalendar::defaults());
        $this->assertSame([
            'October lawn renovation + bulbs 2025: 14 booked ($9,800), 30 replied from 212 sent',
            'Hedges before nesting season 2026: 3 booked, 9 replied from 180 sent',
        ], $lines);
        $this->assertSame('In 2025 this booked 14 jobs ($9,800) from 212 emails · 30 replied, 41 clicked.',
            MiaCampaignService::lastYearLine(['season_year' => 2025, 'booked' => 14, 'booked_amount' => 9800, 'sent' => 212, 'replied' => 30, 'clicked' => 41]));
    }
}
