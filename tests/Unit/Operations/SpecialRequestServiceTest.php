<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SpecialRequestMatcherTest.php';

/**
 * SpecialRequestService + SpecialRequestGate against in-memory SQLite: flag off = inert,
 * propose → confirm (SMS to the leader, push to the crew, logged), the start/photo gate
 * (live tap blocked, offline-queued action accepted), ack, outcomes and extras folded once.
 */
final class SpecialRequestServiceTest extends TestCase
{
    private PDO $db;
    private array $sms = [];
    private array $push = [];
    private const NOW = '2026-10-08 07:30:00';

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        SpecialRequestGate::$serviceFactory = fn(PDO $db) => $this->svc();
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db = $db;

        $db->exec("CREATE TABLE ops_settings (id INTEGER PRIMARY KEY, setting_key TEXT UNIQUE, setting_value TEXT, description TEXT)");
        $db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, company_id INT)");
        $db->exec("CREATE TABLE companies (id INTEGER PRIMARY KEY, company_name TEXT)");
        $db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT, city TEXT, property_name TEXT, site_contact_id INT, company_id INT)");
        $db->exec("CREATE TABLE company_properties (id INTEGER PRIMARY KEY, company_id INT, property_id INT)");
        $db->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, plan_number TEXT, property_id INT, company_id INT, title TEXT, description TEXT, service_type TEXT, status TEXT)");
        $db->exec("CREATE TABLE calendar_stops (id INTEGER PRIMARY KEY, property_id INT, crew_id INT, stop_date TEXT)");
        $db->exec("CREATE TABLE calendar_stop_crew (id INTEGER PRIMARY KEY, stop_id INT, user_id INT)");
        $db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, visit_number TEXT, plan_id INT, stop_id INT, scheduled_date TEXT, status TEXT,
                   assigned_crew_id INT, invoice_id INT, extras_minutes INT, extras_amount REAL, extras_note TEXT)");
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT, phone TEXT)");
        $db->exec("CREATE TABLE visit_notes (id INTEGER PRIMARY KEY, visit_id INT, note_type TEXT, content TEXT, is_visible_to_customer INT, created_by INT, created_at TEXT)");
        $db->exec("CREATE TABLE sales_messages (id INTEGER PRIMARY KEY, message_key TEXT, direction TEXT, channel TEXT, contact_id INT, subject TEXT, snippet TEXT, sent_at TEXT)");
        // Migrations 1295–1298, SQLite flavour
        $db->exec("CREATE TABLE special_requests (id INTEGER PRIMARY KEY, status TEXT DEFAULT 'pending', head TEXT, source TEXT, source_message_key TEXT UNIQUE,
                   contact_id INT, company_id INT, summary TEXT, client_words TEXT, included_items TEXT, extra_items TEXT, proposal_json TEXT,
                   received_at TEXT, created_by INT, decided_by INT, decided_at TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE special_request_visits (id INTEGER PRIMARY KEY, request_id INT, visit_id INT, property_id INT, status TEXT DEFAULT 'attached',
                   included_items TEXT, extra_items TEXT, attached_at TEXT, arrival_notified_at TEXT, outcome_reason TEXT, extra_description TEXT,
                   extra_minutes INT, extra_amount REAL, extra_ref TEXT, extra_folded_at TEXT, outcome_by INT, outcome_at TEXT, created_at TEXT,
                   UNIQUE (request_id, visit_id))");
        $db->exec("CREATE TABLE special_request_acks (id INTEGER PRIMARY KEY, request_visit_id INT, user_id INT, acknowledged_at TEXT, client TEXT, UNIQUE (request_visit_id, user_id))");
        $db->exec("CREATE TABLE special_request_events (id INTEGER PRIMARY KEY, request_id INT, request_visit_id INT, visit_id INT, user_id INT, kind TEXT, ok INT, detail TEXT, body TEXT, created_at TEXT)");

        $db->exec("INSERT INTO ops_settings (setting_key, setting_value) VALUES ('special_requests_enabled', '1'), ('special_requests_user_ids', ''), ('extras_rate_per_5min', '5.00')");
        $db->exec("INSERT INTO companies VALUES (5, 'Pacific Spirit United Church')");
        $db->exec("INSERT INTO contacts VALUES (47, 'Michelle', 'Henry', 5)");
        $db->exec("INSERT INTO users VALUES (31, 'Nigel Lead', '604-555-0101'), (32, 'Sam Helper', '604-555-0102'), (33, 'Office Tim', '')");
        foreach (SpecialRequestMatcherTest::properties() as $p) {
            $db->prepare("INSERT INTO properties (id, address, city, property_name, company_id) VALUES (?, ?, ?, ?, ?)")
               ->execute([$p['id'], $p['address'], $p['city'], $p['property_name'], $p['id'] === 4 ? null : 5]);
        }
        foreach (SpecialRequestMatcherTest::plans() as $p) {
            $db->prepare("INSERT INTO job_plans (id, plan_number, property_id, company_id, title, description, service_type, status) VALUES (?, ?, ?, 5, ?, ?, ?, 'active')")
               ->execute([$p['id'], $p['plan_number'], $p['property_id'], $p['title'], $p['description'], $p['service_type']]);
        }
        $db->exec("INSERT INTO calendar_stops VALUES (500, 1, 31, '2026-10-08'), (501, 2, 31, '2026-10-08'), (502, 3, 31, '2026-10-08')");
        $db->exec("INSERT INTO calendar_stop_crew (stop_id, user_id) VALUES (500, 31), (500, 32), (501, 31), (501, 32), (502, 31)");
        foreach (SpecialRequestMatcherTest::visits() as $v) {
            $stop = [100 => 500, 101 => 501, 102 => 502][$v['visit_id']] ?? null;
            $db->prepare("INSERT INTO job_visits (id, visit_number, plan_id, stop_id, scheduled_date, status, assigned_crew_id) VALUES (?, ?, ?, ?, ?, ?, ?)")
               ->execute([$v['visit_id'], 'V' . $v['visit_id'], $v['plan_id'], $stop, $v['scheduled_date'], $v['status'], $stop ? 31 : null]);
        }
    }

    protected function tearDown(): void
    {
        SpecialRequestGate::$serviceFactory = null;
    }

    private function svc(): SpecialRequestService
    {
        return new SpecialRequestService(
            $this->db, self::NOW,
            function (string $phone, string $text): array { $this->sms[] = [$phone, $text]; return ['success' => true]; },
            function (array $ids, string $t, string $b, array $d): void { $this->push[] = [$ids, $t, $b, $d]; }
        );
    }

    private function flag(string $key, string $value): void
    {
        $this->db->prepare("UPDATE ops_settings SET setting_value = ? WHERE setting_key = ?")->execute([$value, $key]);
    }

    /** Propose Michelle's email and confirm it; returns srv ids by visit id. */
    private function attachMichelle(): array
    {
        $svc = $this->svc();
        $r = $svc->propose(['text' => SpecialRequestMatcherTest::MICHELLE, 'contact_id' => 47, 'source' => 'email', 'head' => 'yui', 'message_key' => 'mk-1']);
        $c = $svc->confirm($r['id'], 33);
        $this->assertTrue($c['ok'], json_encode($c));
        $rows = $this->db->query("SELECT visit_id, id FROM special_request_visits ORDER BY visit_id")->fetchAll(PDO::FETCH_KEY_PAIR);
        return array_map('intval', $rows);
    }

    public function testDryRunForContact47AttachesTodaysThreeVisits(): void
    {
        $p = $this->svc()->dryRun(SpecialRequestMatcherTest::MICHELLE, 47, '2026-10-08');
        $this->assertSame([100, 101, 102], array_map(fn($v) => (int)$v['visit_id'], $p['visits']));
        $this->assertSame(['2195 West 45th Avenue', '2205 W 45th Ave', '2267 W 45th Ave'], array_column($p['visits'], 'address'));
        $this->assertContains('Nigel Lead', $p['visits'][0]['crew']);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM special_requests")->fetchColumn(), 'dry run writes nothing');
    }

    public function testFlagOffIsInert(): void
    {
        $srv = $this->attachMichelle();
        $this->flag('special_requests_enabled', '0');
        $svc = $this->svc();

        $this->assertFalse($svc->enabled());
        $this->assertSame([], $svc->forVisits([100, 101, 102], 31));
        $this->assertTrue(SpecialRequestGate::check($this->db, 101, 31)['allow']);
        $this->assertFalse(SpecialRequestGate::blocksAutoStart($this->db, 101, 31));
        $this->assertFalse($svc->ack($srv[101], 31)['ok']);
        $r = $svc->propose(['text' => 'Please mow 2205 W 45th', 'contact_id' => 47, 'message_key' => 'mk-2']);
        $this->assertFalse($svc->confirm($r['id'], 33)['ok'], 'nothing is attached while off');
        $this->db->exec("INSERT INTO sales_messages VALUES (1, 'mk-3', 'inbound', 'email', 47, 'Lawns', 'Please mow 2205 W 45th today', '2026-10-08 07:00:00')");
        $this->assertSame(0, $svc->scanInbound());
    }

    public function testTestUserListLimitsTheCrewSide(): void
    {
        $this->attachMichelle();
        $this->flag('special_requests_user_ids', '32');
        $this->assertTrue(SpecialRequestGate::check($this->db, 101, 31)['allow'], 'user 31 is not in the test list');
        $this->assertFalse(SpecialRequestGate::check($this->db, 101, 32)['allow']);
    }

    public function testInboundScanProposesPendingAndNeverAttaches(): void
    {
        $this->db->prepare("INSERT INTO sales_messages VALUES (1, 'mk-9', 'inbound', 'email', 47, 'Lawns today', ?, '2026-10-08 06:55:00')")
            ->execute([SpecialRequestMatcherTest::MICHELLE]);
        $this->db->exec("INSERT INTO sales_messages VALUES (2, 'mk-10', 'inbound', 'email', 47, 'Invoice', 'Can you resend the September invoice?', '2026-10-08 06:56:00')");
        $svc = $this->svc();
        $this->assertSame(1, $svc->scanInbound());
        $this->assertSame(0, $svc->scanInbound(), 'one request per message');
        $this->assertSame('pending', $this->db->query("SELECT status FROM special_requests")->fetchColumn());
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM special_request_visits")->fetchColumn());
        $this->assertSame([], $this->sms);
        $office = $svc->office('yui');
        $this->assertCount(1, $office['pending']);
        $this->assertSame([100, 101, 102], array_column($office['pending'][0]['visits'], 'visit_id'));
    }

    public function testConfirmTextsTheLeaderOnceAndPushesEveryone(): void
    {
        $this->attachMichelle();
        // One SMS per attached visit to the leader (31); each within carrier rules.
        $this->assertCount(3, $this->sms);
        foreach ($this->sms as [$phone, $text]) {
            $this->assertSame('604-555-0101', $phone);
            $this->assertLessThanOrEqual(160, mb_strlen($text));
            $this->assertStringStartsWith('Mowology: special request at ', $text);
        }
        $this->assertStringContainsString('2205 W 45th today', $this->sms[1][1]);
        $this->assertStringContainsString('(extra', $this->sms[1][1]);
        // Pushes: visit 100 + 101 have two crew, 102 one.
        $this->assertSame([[31, 32], [31, 32], [31]], array_column($this->push, 0));
        $this->assertSame('special_request', $this->push[0][3]['type']);
        $this->assertSame('https://mowology.ca/crm/img/heads/yui.jpg', $this->push[0][3]['image_url'], 'the head\'s face on the push');
        $this->assertStringStartsWith('Yui: special request', $this->push[0][1]);
        $this->assertSame(100, $this->push[0][3]['visit_id']);
        $logged = $this->db->query("SELECT kind, COUNT(*) FROM special_request_events WHERE kind IN ('sms','push') GROUP BY kind")->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame(['push' => 5, 'sms' => 3], array_map('intval', $logged));
        $this->assertSame(3, (int)$this->db->query("SELECT COUNT(*) FROM visit_notes WHERE note_type = 'customer_request'")->fetchColumn());

        // Re-sending never repeats.
        $srvId = (int)$this->db->query("SELECT id FROM special_request_visits WHERE visit_id = 101")->fetchColumn();
        $this->svc()->notifyAttached($srvId);
        $this->assertCount(3, $this->sms);
        $this->assertCount(3, $this->push);
    }

    public function testLeaderWithoutPhoneIsLoggedNotSilent(): void
    {
        $this->db->exec("UPDATE users SET phone = '' WHERE id = 31");
        $this->attachMichelle();
        $this->assertSame([], $this->sms);
        $this->assertSame(3, (int)$this->db->query("SELECT COUNT(*) FROM special_request_events WHERE kind = 'sms' AND ok = 0")->fetchColumn());
    }

    public function testGateBlocksLiveTapUntilEachCrewMemberAcks(): void
    {
        $srv = $this->attachMichelle();
        $g = SpecialRequestGate::check($this->db, 101, 31);
        $this->assertFalse($g['allow']);
        $this->assertSame('blocked', $g['reason']);
        $this->assertSame($srv[101], $g['requests'][0]['request_visit_id']);
        $this->assertStringContainsString('Rake up the leaves', implode(' ', $g['requests'][0]['extra']));
        // The first attempt pushed the "before you start" reminder, once.
        $arrival = (int)$this->db->query("SELECT COUNT(*) FROM special_request_events WHERE kind = 'push' AND detail = 'arrival'")->fetchColumn();
        $this->assertSame(2, $arrival);
        SpecialRequestGate::check($this->db, 101, 31);
        $this->assertSame(2, (int)$this->db->query("SELECT COUNT(*) FROM special_request_events WHERE kind = 'push' AND detail = 'arrival'")->fetchColumn());

        $a = $this->svc()->ack($srv[101], 31);
        $this->assertTrue($a['ok']);
        $this->assertTrue($a['request']['acked_by_me']);
        $this->assertSame('Nigel Lead', $a['request']['acks'][0]['name']);
        $this->assertTrue(SpecialRequestGate::check($this->db, 101, 31)['allow']);
        $this->assertFalse(SpecialRequestGate::check($this->db, 101, 32)['allow'], 'every crew member reads it');
        $this->assertTrue($this->svc()->ack($srv[101], 31)['ok'], 'double tap is fine');
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM special_request_acks")->fetchColumn());
        $this->assertTrue(SpecialRequestGate::check($this->db, 104, 31)['allow'], 'other visits are not gated');
    }

    public function testServerAnswers409WithTheRequestUntilAcked(): void
    {
        $srv = $this->attachMichelle();
        $web = SpecialRequestGate::response($this->db, 101, 31, false, null, 'start', 'error');
        $this->assertSame(409, $web['status']);
        $this->assertSame('special_request_unacknowledged', $web['body']['code']);
        $this->assertFalse($web['body']['success']);
        $this->assertSame(SpecialRequestGate::MESSAGE, $web['body']['error']);
        $this->assertSame($srv[101], $web['body']['special_requests'][0]['request_visit_id']);
        $this->assertSame('Michelle Henry', $web['body']['special_requests'][0]['from_name']);
        $this->assertSame('/crm/img/heads/yui.jpg', $web['body']['special_requests'][0]['head_photo']);
        $this->assertSame('Comms', $web['body']['special_requests'][0]['head_role']);

        $ios = SpecialRequestGate::response($this->db, 101, 31, false, null, 'photo', 'message');
        $this->assertSame(SpecialRequestGate::MESSAGE, $ios['body']['message'], 'JWT clients read `message`');

        $this->assertNull(SpecialRequestGate::response($this->db, 101, 31, true, (string)(strtotime('2026-10-08 07:00:00') * 1000)), 'queued before → accepted');
        $this->svc()->ack($srv[101], 31);
        $this->assertNull(SpecialRequestGate::response($this->db, 101, 31), 'acked → accepted');
        $this->flag('special_requests_enabled', '0');
        $this->assertNull(SpecialRequestGate::response($this->db, 101, 32), 'flag off → accepted');
    }

    public function testOfflineQueuedActionsAreAcceptedAndFlagged(): void
    {
        $this->attachMichelle(); // attached_at = 07:30
        $before = SpecialRequestGate::check($this->db, 101, 31, true, (string)(strtotime('2026-10-08 07:10:00') * 1000));
        $this->assertTrue($before['allow']);
        $this->assertSame('queued_before', $before['reason']);
        $late = SpecialRequestGate::check($this->db, 101, 32, true, '2026-10-08 07:45:00');
        $this->assertTrue($late['allow'], 'never stuck in the queue');
        $this->assertSame('queued_late', $late['reason']);
        $kinds = $this->db->query("SELECT kind FROM special_request_events WHERE kind LIKE 'gate_%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['gate_queued', 'gate_late'], $kinds);
    }

    public function testDecideIsPure(): void
    {
        $open = [['request_visit_id' => 1, 'attached_at' => '2026-10-08 07:30:00', 'acked' => false]];
        $this->assertSame('off', SpecialRequestGate::decide($open, false, false, null)['reason']);
        $this->assertSame('none', SpecialRequestGate::decide([], true, false, null)['reason']);
        $this->assertFalse(SpecialRequestGate::decide($open, true, false, null)['allow']);
        $this->assertSame('queued_before', SpecialRequestGate::decide($open, true, true, '2026-10-08 07:00:00')['reason']);
        $this->assertSame('queued_late', SpecialRequestGate::decide($open, true, true, null)['reason']);
        $open[0]['acked'] = true;
        $this->assertSame('acked', SpecialRequestGate::decide($open, true, false, null)['reason']);
        $this->assertSame('2026-10-08 07:00:00', SpecialRequestGate::normaliseQueuedAt('2026-10-08 07:00:00'));
        $this->assertNull(SpecialRequestGate::normaliseQueuedAt('garbage'));
    }

    public function testAutoArrivalDoesNotStartPastAnUnreadRequest(): void
    {
        $srv = $this->attachMichelle();
        $this->assertTrue(SpecialRequestGate::blocksAutoStart($this->db, 102, 31));
        SpecialRequestGate::blocksAutoStart($this->db, 102, 31);
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM special_request_events WHERE kind = 'gate_block' AND detail = 'auto_start'")->fetchColumn(), 'logged once, not per ping');
        $this->svc()->ack($srv[102], 31);
        $this->assertFalse(SpecialRequestGate::blocksAutoStart($this->db, 102, 31));
    }

    public function testOutcomesAndExtraWorkFoldedOnceAfterCompletion(): void
    {
        $srv = $this->attachMichelle();
        $svc = $this->svc();
        $this->assertFalse($svc->outcome($srv[100], 31, 'not_done', '')['ok'], 'reason required');
        $this->assertTrue($svc->outcome($srv[100], 31, 'not_done', 'Lawn too wet')['ok']);
        $this->assertTrue($svc->outcome($srv[102], 31, 'done')['ok']);
        $this->assertFalse($svc->outcome($srv[101], 31, 'extra_done', '', 'Raked leaves', 0)['ok'], 'minutes required');
        $r = $svc->outcome($srv[101], 31, 'extra_done', '', 'Raked leaves NW corner into green bins', 25);
        $this->assertTrue($r['ok']);
        $this->assertSame(25, $r['request']['outcome']['extra_minutes']);
        $this->assertSame(25.0, $r['request']['outcome']['extra_amount'], '5 blocks x $5');

        // Not completed yet → nothing on the visit's extras.
        $this->assertNull($this->db->query("SELECT extras_minutes FROM job_visits WHERE id = 101")->fetchColumn());

        // Completion writes the sheet's extras (10 min), then folds the request's 25 on top — once.
        $this->db->exec("UPDATE job_visits SET status = 'completed', extras_minutes = 10, extras_amount = 10, extras_note = 'Edged walk' WHERE id = 101");
        SpecialRequestGate::afterCompletion($this->db, 101);
        SpecialRequestGate::afterCompletion($this->db, 101);
        $v = $this->db->query("SELECT extras_minutes, extras_amount, extras_note FROM job_visits WHERE id = 101")->fetch();
        $this->assertSame(35, (int)$v['extras_minutes']);
        $this->assertSame(35.0, (float)$v['extras_amount']);
        $this->assertSame('Edged walk; Special request: Raked leaves NW corner into green bins (25 min)', $v['extras_note']);
        $this->assertSame('visit_extras', $this->db->query("SELECT extra_ref FROM special_request_visits WHERE id = {$srv[101]}")->fetchColumn());

        // After billing, the answer can't be changed to something that un-bills it.
        $this->assertFalse($svc->outcome($srv[101], 31, 'done')['ok']);
        $this->assertSame('closed', $this->db->query("SELECT status FROM special_requests")->fetchColumn());
        $this->assertSame(3, (int)$this->db->query("SELECT COUNT(*) FROM visit_notes WHERE content LIKE 'Special request — %'")->fetchColumn());
    }

    public function testExtraOnAnInvoicedVisitIsFlaggedForTheOfficeNotBilledAgain(): void
    {
        $srv = $this->attachMichelle();
        $this->db->exec("UPDATE job_visits SET status = 'completed', invoice_id = 900, extras_minutes = 0 WHERE id = 101");
        $this->svc()->outcome($srv[101], 31, 'extra_done', '', 'Raked leaves', 20);
        $this->assertSame(0, (int)$this->db->query("SELECT extras_minutes FROM job_visits WHERE id = 101")->fetchColumn());
        $this->assertSame('needs_billing', $this->db->query("SELECT extra_ref FROM special_request_visits WHERE id = {$srv[101]}")->fetchColumn());
    }

    public function testManualRequestOnAVisitAttachesStraightAway(): void
    {
        $r = $this->svc()->createManual(['visit_ids' => [104], 'client_words' => 'Owner asked: trim the hedge by the gate, and pick up the branches by the shed'], 33);
        $this->assertTrue($r['ok'], json_encode($r));
        $req = $this->db->query("SELECT head, status, included_items, extra_items FROM special_requests")->fetch();
        $this->assertSame('otto', $req['head']);
        $p = $this->svc()->forVisits([104], 31)[104][0];
        $this->assertSame(['Otto', 'Operations', '/crm/img/heads/otto.jpg'], [$p['head_name'], $p['head_role'], $p['head_photo']]);
        $this->assertSame('attached', $req['status']);
        $this->assertStringContainsString('trim the hedge', strtolower($req['included_items']));
        $this->assertStringContainsString('pick up the branches', strtolower($req['extra_items']));
    }

    public function testSmsTextObeysCarrierRules(): void
    {
        $t = SpecialRequestService::smsText('2205 W 45th Ave', 'today',
            ['Run the mower over the front lawns of the Memorial Centre to remove the dandelions'],
            ['Rake up the leaves from the NW corner of the Memorial Centre on the boulevard into our green bins']);
        $this->assertLessThanOrEqual(160, mb_strlen($t));
        $this->assertDoesNotMatchRegularExpression('~https?://|www\.|\.(com|ca|net|org)\b|@~i', $t);
        $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $t, 'plain ASCII');
        $this->assertStringContainsString('2205 W 45th today', $t);
        $this->assertStringContainsString('Open the app', $t);

        $sneaky = SpecialRequestService::smsText('12 Main St', 'today', ['Mow — see photos at https://mowology.ca/x or mowology.ca'], []);
        $this->assertDoesNotMatchRegularExpression('~https?://|mowology\.ca~i', $sneaky);

        $short = SpecialRequestService::smsText('12 Main St', 'today', ['Mow the front'], []);
        $this->assertStringEndsWith('(778) 846-9273', $short, 'office number when there is room');

        $long = SpecialRequestService::smsText(str_repeat('Very Long Street Name ', 6), 'tomorrow', [str_repeat('word ', 80)], [str_repeat('more ', 80)]);
        $this->assertLessThanOrEqual(160, mb_strlen($long));
    }
}
