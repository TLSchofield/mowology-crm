<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * InvoiceMoveService on in-memory SQLite (the SQL runs, nothing is mocked except the PM-routing lookup).
 *
 * Fixture: invoice 10 (sent, property 1) sits on plan 100 / visit 1000. Plan 200 (same property) has a free
 * completed visit 2000 and one already invoiced (2001). Plan 300 is at property 2 (managed). Plan 400 is
 * contract-billed, plan 500 cancelled.
 */
class InvoiceMoveServiceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT, city TEXT, province TEXT, postal_code TEXT, property_manager_id INT)");
        $db->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, plan_number TEXT, title TEXT, status TEXT DEFAULT 'active',
                   property_id INT, company_id INT, contract_id INT)");
        $db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, visit_number TEXT, plan_id INT, status TEXT, scheduled_date TEXT,
                   is_invoiced INT DEFAULT 0, invoice_id INT)");
        $db->exec("CREATE TABLE contracts (id INTEGER PRIMARY KEY, status TEXT, billing_cycle TEXT)");
        $db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, invoice_number TEXT, status TEXT, amount_paid REAL DEFAULT 0,
                   stripe_payment_intent_id TEXT, plan_id INT, visit_id INT, property_id INT, company_id INT, contract_id INT,
                   service_address TEXT, service_city TEXT, service_province TEXT, service_postal_code TEXT,
                   pdf_path TEXT, pdf_version INT DEFAULT 0, pdf_generated_at TEXT)");
        $db->exec("CREATE TABLE invoice_line_items (id INTEGER PRIMARY KEY, invoice_id INT, description TEXT, visit_id INT, service_date TEXT)");
        $db->exec("CREATE TABLE invoice_contacts (id INTEGER PRIMARY KEY, invoice_id INT, contact_id INT, contact_role TEXT, email_address TEXT)");
        $this->db = $db;

        $db->exec("INSERT INTO properties VALUES (1, '1 Oak St', 'Squamish', 'BC', 'V8B 0A1', NULL), (2, '9 Elm Ave', 'Whistler', 'BC', 'V8E 0B2', 7)");
        $db->exec("INSERT INTO contracts VALUES (60, 'active', 'monthly')");
        $db->exec("INSERT INTO job_plans (id, plan_number, title, status, property_id, company_id, contract_id) VALUES
                   (100, 'PLN-100', 'Fall Service', 'active', 1, 5, NULL),
                   (200, 'PLN-200', 'Yew trimming', 'active', 1, 5, NULL),
                   (300, 'PLN-300', 'Hedge work', 'active', 2, 7, NULL),
                   (400, 'PLN-400', 'Monthly care', 'active', 1, 5, 60),
                   (500, 'PLN-500', 'Old', 'cancelled', 1, 5, NULL)");
        $db->exec("INSERT INTO job_visits (id, visit_number, plan_id, status, scheduled_date, is_invoiced, invoice_id) VALUES
                   (1000, 'V1000', 100, 'completed', '2026-10-01', 1, 10),
                   (1001, 'V1001', 100, 'completed', '2026-10-08', 1, 11),
                   (2000, 'V2000', 200, 'completed', '2026-10-20', 0, NULL),
                   (2001, 'V2001', 200, 'completed', '2026-10-22', 1, 12),
                   (2002, 'V2002', 200, 'scheduled', '2026-11-20', 0, NULL),
                   (3000, 'V3000', 300, 'completed', '2026-10-25', 0, NULL),
                   (4000, 'V4000', 400, 'completed', '2026-10-25', 0, NULL)");
        $this->invoice(10, 'sent');
        $db->exec("INSERT INTO invoice_line_items (invoice_id, description, visit_id, service_date) VALUES
                   (10, 'Fall service', 1000, '2026-10-01'), (10, 'Loose line', NULL, NULL), (10, 'Other visit', 1001, '2026-10-08')");
        $db->exec("INSERT INTO invoice_contacts (invoice_id, contact_id, contact_role, email_address) VALUES (10, 1, 'primary_recipient', 'owner@example.com')");
    }

    private function invoice(int $id, string $status, float $paid = 0.0, ?string $stripe = null): void
    {
        $this->db->prepare("INSERT INTO invoices (id, invoice_number, status, amount_paid, stripe_payment_intent_id, plan_id, visit_id, property_id, company_id,
                            service_address, pdf_path, pdf_version) VALUES (?,?,?,?,?,100,1000,1,5,'1 Oak St','/pdf/x.pdf',3)")
            ->execute([$id, 'INV-' . $id, $status, $paid, $stripe]);
    }

    private function svc(): InvoiceMoveService
    {
        return new InvoiceMoveService($this->db, function (int $pid): array {
            return $pid === 2 ? [['contact_id' => 9, 'contact_role' => 'billing_contact', 'email_address' => 'ap@manager.example']] : [];
        });
    }

    private function row(string $sql, int $id): array
    {
        $s = $this->db->prepare($sql);
        $s->execute([$id]);
        return $s->fetch();
    }

    public function testMoveSwapsVisitLinksAndLinesFollowOnlyTheOldVisit(): void
    {
        $r = $this->svc()->move(10, 200, 2000, 1);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertNull($this->row("SELECT invoice_id FROM job_visits WHERE id = ?", 1000)['invoice_id']);
        $this->assertSame(0, (int)$this->row("SELECT is_invoiced FROM job_visits WHERE id = ?", 1000)['is_invoiced']);
        $v = $this->row("SELECT invoice_id, is_invoiced FROM job_visits WHERE id = ?", 2000);
        $this->assertSame([10, 1], [(int)$v['invoice_id'], (int)$v['is_invoiced']]);

        $lines = $this->db->query("SELECT description, visit_id, service_date FROM invoice_line_items ORDER BY id")->fetchAll();
        $this->assertSame([2000, '2026-10-20'], [(int)$lines[0]['visit_id'], $lines[0]['service_date']]);
        $this->assertSame(2000, (int)$lines[1]['visit_id']);            // loose line follows too
        $this->assertSame([1001, '2026-10-08'], [(int)$lines[2]['visit_id'], $lines[2]['service_date']]); // other visit untouched

        $inv = $this->row("SELECT * FROM invoices WHERE id = ?", 10);
        $this->assertSame([200, 2000, 1], [(int)$inv['plan_id'], (int)$inv['visit_id'], (int)$inv['property_id']]);
        $this->assertNull($inv['pdf_path']);
        $this->assertSame(0, (int)$inv['pdf_version']);
        $this->assertSame('1 Oak St', $inv['service_address']);          // same property: address kept
    }

    public function testLinkJobOnlyFreesOldVisit(): void
    {
        $r = $this->svc()->move(10, 200, null, 1);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertNull($this->row("SELECT invoice_id FROM job_visits WHERE id = ?", 1000)['invoice_id']);
        $inv = $this->row("SELECT plan_id, visit_id FROM invoices WHERE id = ?", 10);
        $this->assertSame(200, (int)$inv['plan_id']);
        $this->assertNull($inv['visit_id']);
        $this->assertNull($this->db->query("SELECT visit_id FROM invoice_line_items WHERE id = 1")->fetchColumn());
    }

    public function testRefusesPaidPartPaidAndCancelled(): void
    {
        $this->invoice(21, 'paid', 100.0);
        $this->invoice(22, 'partial', 20.0);
        $this->invoice(23, 'cancelled');
        $this->invoice(24, 'void');
        foreach ([21, 22, 23, 24] as $id) {
            $r = $this->svc()->move($id, 200, null, 1);
            $this->assertFalse($r['ok'], "invoice $id");
            $this->assertStringContainsString('refund or cancel', $r['error']);
        }
    }

    public function testRefusesSentInvoiceWithPaymentInFlight(): void
    {
        $this->invoice(25, 'sent', 0.0, 'pi_123');
        $this->assertFalse($this->svc()->move(25, 200, null, 1)['ok']);
        $this->invoice(26, 'draft', 0.0, 'pi_456');
        $this->assertTrue($this->svc()->move(26, 200, null, 1)['ok']);
    }

    public function testRefusesBadTargets(): void
    {
        $this->assertFalse($this->svc()->move(10, 200, 2001, 1)['ok']);   // already invoiced
        $this->assertFalse($this->svc()->move(10, 200, 2002, 1)['ok']);   // not completed
        $this->assertFalse($this->svc()->move(10, 200, 3000, 1)['ok']);   // visit of another plan
        $this->assertFalse($this->svc()->move(10, 500, null, 1)['ok']);   // cancelled plan
        $this->assertFalse($this->svc()->move(10, 999, null, 1)['ok']);   // no such plan
        $this->assertFalse($this->svc()->move(10, 100, 1000, 1)['ok']);   // where it already is
        $this->assertSame(1000, (int)$this->row("SELECT visit_id FROM invoices WHERE id = ?", 10)['visit_id']);
    }

    public function testRefusesContractBilledTarget(): void
    {
        $r = $this->svc()->move(10, 400, 4000, 1);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('contract', $r['error']);
        $this->assertSame(10, (int)$this->row("SELECT invoice_id FROM job_visits WHERE id = ?", 1000)['invoice_id']);
    }

    public function testDraftRecipientsReplacedOnPropertyChange(): void
    {
        $this->db->exec("UPDATE invoices SET status = 'draft' WHERE id = 10");
        $p = $this->svc()->preview(10, 300, 3000);
        $this->assertTrue($p['ok']);
        $this->assertTrue($p['property_changes']);
        $this->assertSame(['owner@example.com'], $p['recipients_before']);
        $this->assertSame(['ap@manager.example'], $p['recipients_after']);

        $this->assertTrue($this->svc()->move(10, 300, 3000, 1)['ok']);
        $emails = $this->db->query("SELECT email_address FROM invoice_contacts WHERE invoice_id = 10")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['ap@manager.example'], $emails);
        $inv = $this->row("SELECT * FROM invoices WHERE id = ?", 10);
        $this->assertSame([2, 7, '9 Elm Ave'], [(int)$inv['property_id'], (int)$inv['company_id'], $inv['service_address']]);
    }

    public function testSentRecipientsUntouchedAndWarned(): void
    {
        $p = $this->svc()->preview(10, 300, 3000);
        $this->assertSame(['owner@example.com'], $p['recipients_after']);
        $this->assertStringContainsString('resend', implode(' ', $p['warnings']));
        $this->assertTrue($this->svc()->move(10, 300, 3000, 1)['ok']);
        $emails = $this->db->query("SELECT email_address FROM invoice_contacts WHERE invoice_id = 10")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['owner@example.com'], $emails);
    }

    public function testDraftKeepsRecipientsWhenNewPropertyHasNoManager(): void
    {
        $this->db->exec("UPDATE invoices SET status = 'draft', property_id = 2 WHERE id = 10");
        $this->assertTrue($this->svc()->move(10, 200, null, 1)['ok']);   // property 2 -> 1: no PM rows
        $emails = $this->db->query("SELECT email_address FROM invoice_contacts WHERE invoice_id = 10")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['owner@example.com'], $emails);
    }

    public function testCandidatesListSamePropertyFirstAndOnlyFreeCompletedVisits(): void
    {
        $c = $this->svc()->candidates(10);
        $ids = array_column($c, 'plan_id');
        $this->assertNotContains(100, $ids);
        $this->assertNotContains(500, $ids);
        $this->assertSame(400, $ids[0]);                                   // same property, newest first
        $this->assertTrue($c[0]['same_property']);
        $byPlan = array_column($c, null, 'plan_id');
        $this->assertSame([2000], array_column($byPlan[200]['visits'], 'visit_id'));

        $found = $this->svc()->candidates(10, 'Hedge');
        $this->assertSame([300], array_column($found, 'plan_id'));
        $this->assertSame([], $this->svc()->candidates(999));
    }

    /** @test 2026-11-06: the API never loaded functions.php, so every real move skipped the activity log. */
    public function the_move_api_loads_the_activity_logger(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/public/crm/api/invoice-move.php');
        $this->assertStringContainsString("/crm/includes/functions.php'", $src);
    }
}
