<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Ask Charlie: names in the question are looked up with prepared statements, only matched
 * records go to Claude, no match is answered free, and the daily cap stops calls. The HTTP
 * call is always a fake transport — the real API is never called here.
 */
class CharlieAskServiceTest extends TestCase
{
    private const TODAY = '2026-10-07';

    // ── terms ───────────────────────────────────────────────────────────────

    public function test_terms_pick_the_name_out_of_a_question(): void
    {
        $this->assertSame(['Linda'], CharlieAskService::terms("Has Linda's quote been sent?"));
        $this->assertSame(['Linda'], CharlieAskService::terms("has Linda’s quote been sent"));
        $this->assertSame(['INV-2026-0042'], CharlieAskService::terms('is inv-2026-0042 paid yet?'));
        $this->assertSame(['2505', '8th'], CharlieAskService::terms('When is the next visit at 2505 W 8th?'));
        $this->assertSame(['nguyen'], CharlieAskService::terms('does nguyen owe us money'));
        $this->assertSame([], CharlieAskService::terms('what is overdue?'));
        $this->assertCount(CharlieAskService::MAX_TERMS, CharlieAskService::terms('Anna Bob Carl Dana Erin Fred'));
    }

    public function test_owner_filter_uses_placeholders_only(): void
    {
        [$w, $args] = CharlieAskService::ownerFilter([3, 3, 9], [], [12]);
        $this->assertSame('(#.contact_id IN (?,?) OR #.property_id IN (?))', $w);
        $this->assertSame([3, 9, 12], $args);
        $this->assertSame(['', []], CharlieAskService::ownerFilter([], [], []));
    }

    public function test_request_uses_sonnet_and_the_prompt_carries_question_and_records(): void
    {
        $r = CharlieAskService::buildRequest('x');
        $this->assertSame('claude-sonnet-5-5', $r['model']);
        $this->assertStringContainsString('Answer ONLY from the CRM records', $r['system'][0]['text']);
        $p = CharlieAskService::buildPrompt("Has Linda's quote been sent?", ['contacts' => [['id' => 1, 'first_name' => 'Linda', 'email' => '']], 'quotes' => []], self::TODAY);
        $this->assertStringContainsString('Today is 2026-10-07', $p);
        $this->assertStringContainsString("Has Linda's quote been sent?", $p);
        $this->assertStringContainsString('"first_name": "Linda"', $p);
        $this->assertStringNotContainsString('"email"', $p);      // empty fields are left out
        $this->assertStringNotContainsString('"quotes"', $p);
    }

    public function test_cost_and_cap_maths(): void
    {
        $this->assertEqualsWithDelta(0.0105, CharlieAskService::cost(2000, 300), 1e-9);
        $this->assertSame(0, CharlieAskService::capLeft(30, 31));
        $this->assertFalse(CharlieAskService::canCall(2, 2));
        $this->assertTrue(CharlieAskService::canCall(2, 1));
    }

    // ── the flow, on SQLite with a fake transport ──────────────────────────

    private function db(int $cap = 30): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, phone TEXT)");
        $db->exec("CREATE TABLE companies (id INTEGER PRIMARY KEY, company_name TEXT)");
        $db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT, city TEXT)");
        $db->exec("CREATE TABLE quotes (id INTEGER PRIMARY KEY, quote_number TEXT, title TEXT, status TEXT, total_amount REAL, amount REAL,
                   created_at TEXT, sent_at TEXT, viewed_at TEXT, accepted_at TEXT, valid_until TEXT, contact_id INTEGER, company_id INTEGER, property_id INTEGER)");
        $db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, invoice_number TEXT, status TEXT, total REAL, balance_due REAL, issue_date TEXT,
                   due_date TEXT, paid_at TEXT, contact_id INTEGER, company_id INTEGER, property_id INTEGER)");
        $db->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, property_id INTEGER, service_type TEXT)");
        $db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, plan_id INTEGER, scheduled_date TEXT, status TEXT)");
        $db->exec("CREATE TABLE charlie_asks (id INTEGER PRIMARY KEY AUTOINCREMENT, question TEXT, terms TEXT, matched TEXT, source TEXT, answer TEXT,
                   model TEXT, input_tokens INTEGER, output_tokens INTEGER, cost_usd REAL, error TEXT, requested_by INTEGER, created_at TEXT)");
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)");
        $db->prepare("INSERT INTO ops_settings VALUES (?, ?, '')")->execute([CharlieAskService::CAP_KEY, (string)$cap]);

        $db->exec("INSERT INTO contacts VALUES (1, 'Linda', 'Bee', 'linda@example.com', '604-555-0101'), (2, 'Mark', 'Ito', '', '')");
        $db->exec("INSERT INTO properties VALUES (10, '2505 W 8th Ave', 'Vancouver'), (11, '9 Oak St', 'Burnaby')");
        $db->exec("INSERT INTO quotes VALUES (100, 'QUO-2026-0100', 'Fall cleanup', 'sent', 640, 0, '2026-09-28', '2026-09-29 10:00:00', NULL, NULL, '2026-10-29', 1, NULL, 10),
                                             (101, 'QUO-2026-0101', 'Hedge', 'draft', 300, 0, '2026-10-01', NULL, NULL, NULL, NULL, 2, NULL, 11)");
        $db->exec("INSERT INTO invoices VALUES (200, 'INV-2026-0042', 'overdue', 420, 420, '2026-09-01', '2026-09-15', NULL, 1, NULL, 10)");
        $db->exec("INSERT INTO job_plans VALUES (50, 10, 'lawn_mowing')");
        $db->exec("INSERT INTO job_visits VALUES (500, 50, '2026-10-02', 'completed'), (501, 50, '2026-10-09', 'scheduled'), (502, 50, '2026-10-16', 'scheduled')");
        return $db;
    }

    private function transport(string $answer, int &$calls, ?array &$lastBody = null, int $code = 200): callable
    {
        return function (array $body) use ($answer, &$calls, &$lastBody, $code) {
            $calls++;
            $lastBody = $body;
            return ['code' => $code, 'body' => json_encode([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => $answer]],
                'usage' => ['input_tokens' => 2000, 'output_tokens' => 300],
            ])];
        };
    }

    public function test_context_finds_the_contact_their_quotes_invoices_and_visits(): void
    {
        $ctx = (new CharlieAskService($this->db(), null, self::TODAY))->context(['Linda']);
        $this->assertSame([1], array_map('intval', array_column($ctx['contacts'], 'id')));
        $this->assertSame(['QUO-2026-0100'], array_column($ctx['quotes'], 'quote_number'));
        $this->assertSame(['INV-2026-0042'], array_column($ctx['invoices'], 'invoice_number'));
        $this->assertSame(['2026-10-02', '2026-10-09', '2026-10-16'], array_column($ctx['visits'], 'scheduled_date'));
        $this->assertSame(['2505 W 8th Ave'], array_column($ctx['properties'], 'address'));   // the visits' property, named
    }

    public function test_context_by_document_number_and_street(): void
    {
        $svc = new CharlieAskService($this->db(), null, self::TODAY);
        $this->assertSame(['INV-2026-0042'], array_column($svc->context(['INV-2026-0042'])['invoices'], 'invoice_number'));
        $this->assertSame(['2505 W 8th Ave'], array_column($svc->context(['2505'])['properties'], 'address'));
        $this->assertSame([], $svc->context(['50'])['properties']);   // a bare number is a house number, not "%50%"
        $ctx = $svc->context(['Oak']);
        $this->assertSame(['QUO-2026-0101'], array_column($ctx['quotes'], 'quote_number'));
    }

    public function test_ask_sends_only_the_matched_records_and_logs_cost(): void
    {
        $db = $this->db();
        $calls = 0;
        $body = null;
        $svc = new CharlieAskService($db, $this->transport('Yes — I sent Linda the fall cleanup quote on Sep 29.', $calls, $body), self::TODAY);
        $r = $svc->ask("Has Linda's quote been sent?", 7);

        $this->assertTrue($r['ok']);
        $this->assertSame('claude', $r['source']);
        $this->assertStringContainsString('Sep 29', $r['answer']);
        $this->assertSame(29, $r['left']);
        $prompt = $body['messages'][0]['content'][0]['text'];
        $this->assertStringContainsString('QUO-2026-0100', $prompt);
        $this->assertStringNotContainsString('QUO-2026-0101', $prompt);   // Mark's quote isn't sent
        $row = $db->query("SELECT source, terms, input_tokens, output_tokens, cost_usd, requested_by FROM charlie_asks")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['claude', 'Linda', 2000, 300, 7], [$row['source'], $row['terms'], (int)$row['input_tokens'], (int)$row['output_tokens'], (int)$row['requested_by']]);
        $this->assertEqualsWithDelta(0.0105, (float)$row['cost_usd'], 1e-9);
    }

    public function test_nothing_matched_is_answered_free(): void
    {
        $db = $this->db();
        $calls = 0;
        $svc = new CharlieAskService($db, $this->transport('x', $calls), self::TODAY);
        $r = $svc->ask('Has Zelda paid?', 7);
        $this->assertTrue($r['ok']);
        $this->assertSame('none', $r['source']);
        $this->assertStringContainsString('"Zelda"', $r['answer']);
        $this->assertSame(0, $calls);
        $this->assertSame(30, $r['left']);
        $this->assertSame('none', $db->query("SELECT source FROM charlie_asks")->fetchColumn());
    }

    public function test_cap_reached_means_no_call(): void
    {
        $calls = 0;
        $svc = new CharlieAskService($this->db(1), $this->transport('ok', $calls), self::TODAY);
        $this->assertTrue($svc->ask('Has Linda paid?', 7)['ok']);
        $r = $svc->ask('Has Mark accepted?', 7);
        $this->assertFalse($r['ok']);
        $this->assertTrue($r['capped']);
        $this->assertSame(0, $r['left']);
        $this->assertSame(1, $calls);
        $this->assertSame(['ready' => true, 'cap' => 1, 'used' => 1, 'left' => 0], $svc->status());
    }

    public function test_an_api_error_is_logged_counted_and_not_shown_as_an_answer(): void
    {
        $db = $this->db();
        $calls = 0;
        $svc = new CharlieAskService($db, $this->transport('', $calls, $b, 529), self::TODAY);
        $r = $svc->ask('Has Linda paid?', 7);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('HTTP 529', $r['message']);
        $this->assertSame(29, $r['left']);
        $this->assertNull($db->query("SELECT answer FROM charlie_asks")->fetchColumn());
    }

    public function test_without_the_table_it_says_which_migration(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $svc = new CharlieAskService($db, fn() => ['code' => 200, 'body' => '{}'], self::TODAY);
        $this->assertStringContainsString('1215', $svc->ask('Has Linda paid?', 7)['message']);
        $this->assertFalse($svc->status()['ready']);
    }
}
