<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Mia's campaigns: a proposal until Tim approves; only people with a consent record get it;
 * the words never promote watering during the restrictions, a price, or a reward for reviews.
 */
class MiaCampaignTest extends TestCase
{
    private PDO $db;
    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->today = new DateTimeImmutable('2026-10-06');
        $this->db = new class extends PDO {
            public function __construct()
            {
                parent::__construct('sqlite::memory:');
                $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                @$this->sqliteCreateFunction('NOW', fn() => '2026-10-06 09:00:00', 0);
            }
        };
        $d = $this->db;
        $d->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, is_active INT DEFAULT 1)");
        $d->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, site_contact_id INT, latitude REAL, longitude REAL)");
        $d->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, property_id INT, status TEXT)");
        $d->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, plan_id INT, status TEXT, scheduled_date TEXT)");
        $d->exec("CREATE TABLE consent_ledger (contact_id INT, channel TEXT, consent_type TEXT, source TEXT, proof_key TEXT, proof_text TEXT, granted_at TEXT, expires_at TEXT)");
        $d->exec("CREATE TABLE marketing_unsubscribes (email TEXT)");
        $d->exec("CREATE TABLE quotes (id INTEGER PRIMARY KEY, contact_id INT, property_id INT, status TEXT, valid_until TEXT)");
        $d->exec("CREATE TABLE quote_requests (id INTEGER PRIMARY KEY, contact_id INT, status TEXT, quote_id INT, created_at TEXT)");
        $d->exec("CREATE TABLE mia_mutes (subject_key TEXT PRIMARY KEY, until_date TEXT)");
        $d->exec("CREATE TABLE mia_campaigns (id INTEGER PRIMARY KEY, campaign_key TEXT UNIQUE, name TEXT, why TEXT, subject TEXT, body_text TEXT, photo_json TEXT, audience_json TEXT,
                  status TEXT DEFAULT 'proposed', marketing_campaign_id INT, recipients INT, decided_by INT, decided_at TEXT, created_at TEXT)");
        $d->exec("CREATE TABLE marketing_campaigns (id INTEGER PRIMARY KEY, name TEXT, segment_type TEXT, segment_rules TEXT, trigger_type TEXT, auto_send INT, subject_override TEXT,
                  body_override TEXT, status TEXT, recipient_count INT, created_by INT)");
        $d->exec("CREATE TABLE campaign_sends (id INTEGER PRIMARY KEY, campaign_id INT, contact_id INT, email TEXT, status TEXT, created_at TEXT)");

        // 1 client with consent · 2 client, no consent · 3 neighbour (≈150 m) with consent
        // 4 far away with consent · 5 client with an open quote (Sam's) · 6 neighbour muted for good
        foreach ([1, 2, 3, 4, 5, 6] as $i) $d->exec("INSERT INTO contacts (id, first_name, last_name, email) VALUES ($i, 'P$i', 'L', 'p$i@x.com')");
        $d->exec("INSERT INTO properties VALUES (1, 1, 49.2600, -123.1500), (2, 2, 49.2700, -123.1000), (3, 3, 49.2613, -123.1500),
                  (4, 4, 49.3500, -123.0000), (5, 5, 49.2000, -123.2000), (6, 6, 49.2601, -123.1510)");
        $d->exec("INSERT INTO job_plans VALUES (1, 1, 'active'), (2, 2, 'active'), (5, 5, 'completed')");
        $d->exec("INSERT INTO job_visits VALUES (1, 5, 'completed', '2026-06-01')");
        foreach ([1, 3, 4, 5, 6] as $i) {
            $d->exec("INSERT INTO consent_ledger VALUES ($i, 'email', 'implied', 'completed_visit', 'visit:$i', 'p', '2025-11-01', '2027-11-01')");
        }
        $d->exec("INSERT INTO quotes VALUES (1, 5, 5, 'sent', NULL)");
        $d->exec("INSERT INTO mia_mutes VALUES ('mia:contact:6', NULL)");
    }

    public function test_it_is_only_ever_proposed_in_season(): void
    {
        $c = new MiaCampaignService($this->db);
        $this->assertSame(0, $c->propose(new DateTimeImmutable('2026-09-20')), 'not before October');
        $this->assertSame(1, $c->propose($this->today));
        $this->assertSame(0, $c->propose($this->today), 'once');
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM campaign_sends")->fetchColumn(), 'proposing sends nothing');
        $c->propose(new DateTimeImmutable('2026-11-02'));
        $this->assertSame('expired', $this->db->query("SELECT status FROM mia_campaigns")->fetchColumn(), 'lapses with its season');
    }

    public function test_the_audience_is_clients_and_their_neighbours_with_consent(): void
    {
        $a = (new MiaCampaignService($this->db))->audience($this->today);
        $this->assertSame(['clients' => 3, 'neighbours' => 2, 'considered' => 3, 'consented' => 2], $a['counts']);
        sort($a['ids']);
        $this->assertSame([1, 3], $a['ids'], 'no consent, Sam\'s open quote and "never" are left out; far away is not a neighbour');
    }

    public function test_one_tap_approval_queues_only_the_consented(): void
    {
        $c = new MiaCampaignService($this->db);
        $c->propose($this->today);
        $id = (int)$this->db->query("SELECT id FROM mia_campaigns")->fetchColumn();
        $r = $c->approve($id, ['id' => 1], null, null, $this->today);
        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['recipients']);
        $this->assertSame([1, 3], array_map('intval', $this->db->query("SELECT contact_id FROM campaign_sends ORDER BY contact_id")->fetchAll(PDO::FETCH_COLUMN)));
        $mc = $this->db->query("SELECT * FROM marketing_campaigns")->fetch();
        $this->assertSame('sending', $mc['status']);
        $this->assertSame('custom_list', $mc['segment_type']);
        $this->assertStringContainsString('{{first_name}}', $mc['body_override']);
        $this->assertFalse($c->approve($id, ['id' => 1], null, null, $this->today)['ok'], 'approved once');
        $this->assertFalse($c->dismiss($id, ['id' => 1])['ok']);
    }

    public function test_tims_edit_with_a_discount_is_refused(): void
    {
        $c = new MiaCampaignService($this->db);
        $c->propose($this->today);
        $r = $c->approve(1, ['id' => 1], 'Lawn care', "Hi {{first_name}},\n\n20% off aeration this month.\n\nTim", $this->today);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('discount needs a campaign of its own', $r['error']);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM campaign_sends")->fetchColumn());
    }

    public function test_the_words_follow_the_rules(): void
    {
        foreach (MiaCampaignService::catalogue(2026) as $c) {
            $this->assertSame([], MiaCampaignService::problems($c['subject'], $c['body']));
            $this->assertStringContainsString('October 15', $c['body']);
            $this->assertStringEndsWith("Thanks,\nTim", $c['body']);
        }
        $this->assertNotEmpty(MiaCampaignService::problems('Hi', 'Water your lawn now so it recovers.'));
        $this->assertNotEmpty(MiaCampaignService::problems('Hi', 'Turn the sprinklers on this week.'));
        $this->assertNotEmpty(MiaCampaignService::problems('Hi', 'Leave a review and get a free cut.'));
        $this->assertNotEmpty(MiaCampaignService::problems('Hi!', 'Hello'));
        $this->assertSame([], MiaCampaignService::problems('Hi', 'Aeration starts at $95 and overseeding at $90.'), "Tim's own prices are fine");
        $this->assertNotEmpty(MiaCampaignService::problems('Hi', 'Save 20% off aeration this month.'));
        $this->assertNotEmpty(MiaCampaignService::problems('Hi', 'Get $20 off your first visit.'));
        $this->assertNotEmpty(MiaCampaignService::problems('Hi', 'Use the coupon FALL.'));
        $this->assertNotEmpty(MiaCampaignService::problems('Hi', 'Dear {{last_name}}'));
    }

    public function test_neighbours_are_within_400_metres(): void
    {
        $spots = [[49.2600, -123.1500]];
        $this->assertTrue(MiaCampaignService::near(49.2630, -123.1500, $spots, 400));   // ≈ 333 m
        $this->assertFalse(MiaCampaignService::near(49.2640, -123.1500, $spots, 400));  // ≈ 445 m
    }

    public function test_the_photo_is_tims_pair_with_no_address(): void
    {
        $h = MiaCampaignService::html("Hi {{first_name}},\n\nThanks,\nTim", ['before' => 'https://mowology.ca/b.jpg', 'after' => 'https://mowology.ca/a.jpg', 'alt_before' => 'Lawn before', 'alt_after' => 'Lawn after']);
        $this->assertStringContainsString('src="https://mowology.ca/b.jpg"', $h);
        $this->assertStringContainsString('Before and after, from our own work.', $h);
        $this->assertSame('https://mowology.ca/uploads/x.jpg', MiaCampaignService::absolute('/uploads/x.jpg'));
    }
}
