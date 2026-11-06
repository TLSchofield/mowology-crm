<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class SamRequestServiceTest extends TestCase
{
    private PDO $db;
    private SamRequestService $service;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) $this->markTestSkipped('pdo_sqlite not available');
        if (class_exists('TenantContext')) TenantContext::reset();
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec("CREATE TABLE sales_messages (message_key TEXT PRIMARY KEY, direction TEXT, contact_id INT, from_name TEXT, from_addr TEXT, subject TEXT, snippet TEXT, quote_id INT, sent_at TEXT, head TEXT)");
        $this->db->exec("CREATE TABLE sam_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, message_key TEXT UNIQUE, contact_id INT, property_id INT, service_key TEXT, level TEXT, evidence_json TEXT, precedent_json TEXT, draft_quote_id INT, status TEXT, dismiss_reason TEXT, outcome_json TEXT, decided_by INT, decided_at TEXT, created_at TEXT, updated_at TEXT)");
        $this->db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT)");
        $this->db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, site_contact_id INT, address TEXT)");
        $this->db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, contact_id INT, property_id INT)");
        $this->db->exec("CREATE TABLE jobber_invoices (id INTEGER PRIMARY KEY, contact_id INT, property_id INT, line_items TEXT, invoice_date TEXT)");
        $this->db->exec("CREATE TABLE quotes (id INTEGER PRIMARY KEY AUTOINCREMENT, contact_id INT, property_id INT, status TEXT, service_type TEXT, amount REAL, subtotal REAL, accepted_at TEXT, created_at TEXT)");
        $this->db->exec("CREATE TABLE quote_line_items (id INTEGER PRIMARY KEY AUTOINCREMENT, quote_id INT, description TEXT, service_type TEXT, quantity REAL, unit_price REAL, line_total REAL, sort_order INT)");
        $this->db->exec("CREATE TABLE head_lessons (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT DEFAULT 1, head TEXT, subject_kind TEXT, subject_key TEXT, field TEXT, said TEXT, correct TEXT, source TEXT, ref_type TEXT, ref_id INT, corrected_by INT, reason TEXT, created_at TEXT)");
        $this->db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)");
        $this->db->exec("CREATE TABLE agent_action_policy (id INTEGER PRIMARY KEY, head TEXT, action_key TEXT, level INT, enabled INT, limits_json TEXT)");
        $this->db->exec("CREATE TABLE agent_activity (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, head TEXT, skill TEXT, action_key TEXT, entity_type TEXT, entity_id TEXT, decision TEXT, level_required INT, approver_user_id INT, result TEXT, reversible INT, cost_usd REAL, correlation_id TEXT, shadow INT, created_at TEXT)");
        $this->db->exec("INSERT INTO contacts VALUES (7, 'Alex', 'Client')");
        $this->db->exec("INSERT INTO properties VALUES (41, NULL, '41 Cedar Way')");
        $this->db->exec("INSERT INTO invoices VALUES (1, 7, 41)");
        $this->service = new SamRequestService($this->db);
    }

    private function message(string $key, string $snippet, string $subject = 'Re: invoice'): void
    {
        $s = $this->db->prepare("INSERT INTO sales_messages VALUES (?, 'inbound', 7, 'Alex Client', 'alex@example.test', ?, ?, NULL, '2026-11-05 10:00:00', 'sam')");
        $s->execute([$key, $subject, $snippet]);
    }

    public function testTriggerUsesInvoicePropertyAndJobberPrecedent(): void
    {
        $this->message('request-1', 'Can we book you guys in for a fall cleanup?');
        $this->db->exec("INSERT INTO jobber_invoices VALUES (1, 7, 41, 'Fall cleanup (1, $425.00)', '2026-09-12')");
        $id = $this->service->propose('request-1');
        $this->assertNotNull($id);
        $row = $this->db->query("SELECT * FROM sam_requests")->fetch();
        $this->assertSame('sure', $row['level']);
        $this->assertSame(41, (int)$row['property_id']);
        $this->assertSame('invoices', json_decode($row['evidence_json'], true)['property_via']);
        $precedent = json_decode($row['precedent_json'], true);
        $this->assertSame('property_jobber', $precedent['source']);
        $this->assertSame(425.0, $precedent['price']);
    }

    public function testNotARequestCreatesNothing(): void
    {
        $this->message('thanks', 'Thanks for sending that over.', 'Re: invoice');
        $this->assertNull($this->service->propose('thanks'));
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM sam_requests")->fetchColumn());
    }

    public function testNegationIsNotSureAndOpenRowRefreshesWithoutDuplicate(): void
    {
        $this->message('refresh', 'Can we book a fall cleanup?');
        $id = $this->service->propose('refresh');
        $this->db->exec("UPDATE sales_messages SET snippet = 'We do not need the fall cleanup now' WHERE message_key = 'refresh'");
        $this->assertSame($id, $this->service->propose('refresh'));
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM sam_requests")->fetchColumn());
        $this->assertSame('not_sure', $this->db->query("SELECT level FROM sam_requests")->fetchColumn());
    }

    public function testSimilarAcceptedQuoteLinesUseMedian(): void
    {
        $this->message('similar', 'Can we book a fall cleanup?');
        foreach ([200, 400, 900] as $i => $price) {
            $qid = $i + 1;
            $this->db->prepare("INSERT INTO quotes (id, property_id, status, service_type, amount, subtotal, accepted_at, created_at) VALUES (?, 99, 'accepted', 'cleanup', ?, ?, '2026-10-01', '2026-09-01')")->execute([$qid, $price, $price]);
            $this->db->prepare("INSERT INTO quote_line_items (quote_id, description, service_type, quantity, unit_price, line_total, sort_order) VALUES (?, 'Fall cleanup', 'cleanup', 1, ?, ?, 0)")->execute([$qid, $price, $price]);
        }
        $this->service->propose('similar');
        $p = json_decode((string)$this->db->query("SELECT precedent_json FROM sam_requests")->fetchColumn(), true);
        $this->assertSame('similar', $p['source']);
        $this->assertSame(400.0, $p['price']);
    }

    public function testDraftLinesCarryPriceAndProvenance(): void
    {
        $lines = SamRequestService::draftLines(['source' => 'property_jobber', 'date' => '2026-09-12', 'lines' => [['name' => 'Cleanup', 'qty' => 2, 'amount' => 500]]], 'cleanup');
        $this->assertCount(1, $lines);
        $this->assertSame(250.0, $lines[0]['unit_price']);
        $this->assertSame('from Sep 2026', $lines[0]['note']);
        $none = SamRequestService::draftLines(['source' => 'none'], 'mow');
        $this->assertSame(0.0, $none[0]['line_total']);
        $this->assertSame('no price on record', $none[0]['note']);
    }

    public function testMakeDraftCreatesExactlyOneQuoteForRequesterAndIsIdempotent(): void
    {
        $this->message('draft', 'Can we book a fall cleanup?');
        $id = (int)$this->service->propose('draft');
        $created = [];
        $service = new class($this->db, $created) extends SamRequestService {
            private array $created;
            public function __construct(PDO $db, array &$created) { parent::__construct($db); $this->created =& $created; }
            protected function createQuote(array $data, array $lines, int $userId): int
            {
                $this->created[] = ['data' => $data, 'lines' => $lines, 'user_id' => $userId];
                return 123;
            }
        };

        $first = $service->makeDraft($id, ['id' => 9]);
        $second = $service->makeDraft($id, ['id' => 9]);

        $this->assertTrue($first['ok']);
        $this->assertSame($first, $second);
        $this->assertCount(1, $created);
        $this->assertSame(7, $created[0]['data']['contact_id']);
        $this->assertSame(41, $created[0]['data']['property_id']);
        $this->assertSame(9, $created[0]['user_id']);
        $row = $this->db->query("SELECT status, draft_quote_id FROM sam_requests WHERE id = " . $id)->fetch();
        $this->assertSame('drafted', $row['status']);
        $this->assertSame(123, (int)$row['draft_quote_id']);
    }

    public function testDismissRecordsLesson(): void
    {
        $this->message('dismiss', 'Can we book a fall cleanup?');
        $id = (int)$this->service->propose('dismiss');
        $result = $this->service->dismiss($id, 'not_request', ['id' => 9]);
        $this->assertTrue($result['ok']);
        $lesson = $this->db->query("SELECT * FROM head_lessons")->fetch();
        $this->assertSame('is_request', $lesson['field']);
        $this->assertSame('no', $lesson['correct']);
    }

    public function testOutcomeMarksChangedFieldsAndUnrelatedQuoteIsNoOp(): void
    {
        $this->message('outcome', 'Can we book a fall cleanup?');
        $id = (int)$this->service->propose('outcome');
        $this->db->exec("INSERT INTO quotes (id, property_id, status, service_type, amount, subtotal, created_at) VALUES (88, 77, 'sent', 'cleanup', 650, 650, '2026-11-05')");
        $this->db->exec("INSERT INTO quote_line_items (quote_id, description, service_type, quantity, unit_price, line_total, sort_order) VALUES (88, 'Cleanup', 'cleanup', 1, 650, 650, 0)");
        $this->db->exec("UPDATE sam_requests SET status = 'drafted', draft_quote_id = 88, decided_by = 9, precedent_json = '{\"source\":\"similar\",\"price\":400,\"count\":3,\"lines\":[]}' WHERE id = " . $id);
        $this->service->recordOutcome(999);
        $this->assertSame('drafted', $this->db->query("SELECT status FROM sam_requests")->fetchColumn());
        $this->service->recordOutcome(88);
        $row = $this->db->query("SELECT status, outcome_json FROM sam_requests")->fetch();
        $this->assertSame('sent', $row['status']);
        $out = json_decode($row['outcome_json'], true);
        $this->assertSame('changed', $out['property']);
        $this->assertSame('changed', $out['price']);
        $this->assertSame(400, $out['said']['price']);
        $this->assertSame(650, $out['sent']['price']);
        $this->assertSame(2, (int)$this->db->query("SELECT COUNT(*) FROM head_lessons")->fetchColumn());
    }

    public function testPrepareQuotePolicyDefaultIsTwo(): void
    {
        $this->assertSame(2, AgentPolicy::level($this->db, 'sam', 'sam.prepare_quote'));
    }

    public function testDraftLinesCarryTheBusinessGstAndTheNoteSaysWhereThePriceCameFrom(): void
    {
        $lines = SamRequestService::draftLines(['source' => 'similar', 'price' => 450.0, 'count' => 3], 'cleanup', 5.0);
        $this->assertSame(5.0, $lines[0]['tax_rate']);
        $this->assertSame(450.0, $lines[0]['unit_price']);
        $this->assertStringContainsString('3 similar recent quotes', SamRequestService::precedentNote(['source' => 'similar', 'count' => 3]));
        $this->assertStringContainsString('Nov 2025', SamRequestService::precedentNote(['source' => 'property_jobber', 'date' => '2025-11-12']));
        $this->assertStringContainsString('no price on record', SamRequestService::precedentNote([]));
    }
}
