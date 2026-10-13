<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Accounting/Services/StripeEventLedgerService.php';
require_once __DIR__ . '/../../../app/Modules/ChiefOfStaff/Services/CharlieUrgentService.php';

/** LedgerService caches code → id in a static shared by every test; read this test's chart instead. */
class UncachedLedger extends LedgerService
{
    private PDO $pdo;
    public function __construct(PDO $db) { parent::__construct($db); $this->pdo = $db; }
    public function accountId(string $code): int
    {
        $s = $this->pdo->prepare("SELECT id FROM chart_of_accounts WHERE code = ?");
        $s->execute([$code]);
        return (int)$s->fetchColumn();
    }
}

/**
 * Security review #21 (2026-10-12): Stripe disputes and refunds reach the books, once per event id.
 * Fixture events below have the shape Stripe sends (data.object of charge.dispute.* / charge.refunded).
 */
class StripeEventLedgerServiceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $now = static fn() => '2026-10-12 09:00:00';
        if (method_exists($db, 'createFunction')) $db->createFunction('NOW', $now, 0); else $db->sqliteCreateFunction('NOW', $now, 0);
        $db->exec("CREATE TABLE chart_of_accounts (id INTEGER PRIMARY KEY, code TEXT, name TEXT)");
        $db->exec("INSERT INTO chart_of_accounts VALUES (1, '1010', 'Chequing'), (2, '1100', 'AR'), (3, '2160', 'Customer Deposits')");
        $db->exec("CREATE TABLE accounting_periods (id INTEGER PRIMARY KEY, year INTEGER, month INTEGER, status TEXT)");
        $db->exec("CREATE TABLE journal_entries (id INTEGER PRIMARY KEY, entry_date TEXT, memo TEXT, source_type TEXT, source_id INTEGER,
                   period_id INTEGER, status TEXT, is_adjusting INTEGER, created_by INTEGER, reversed_by_entry_id INTEGER)");
        $db->exec("CREATE TABLE journal_lines (id INTEGER PRIMARY KEY, entry_id INTEGER, account_id INTEGER, debit REAL, credit REAL,
                   gst_amount REAL, pst_amount REAL, description TEXT, job_id INTEGER, contact_id INTEGER, vendor_id INTEGER,
                   crew_user_id INTEGER, cost_type_id INTEGER, service_type TEXT)");
        $db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, invoice_number TEXT, contact_id INTEGER, status TEXT,
                   stripe_payment_intent_id TEXT, stripe_charge_id TEXT, dispute_status TEXT, disputed_at TEXT)");
        $db->exec("INSERT INTO invoices VALUES (10, 'INV-2026-0100', 44, 'paid', 'pi_1', 'ch_1', NULL, NULL)");
        $db->exec("CREATE TABLE stripe_payments (id INTEGER PRIMARY KEY, invoice_id INTEGER, payment_intent_id TEXT UNIQUE, status TEXT)");
        $db->exec("INSERT INTO stripe_payments (invoice_id, payment_intent_id, status) VALUES (10, 'pi_1', 'succeeded')");
        $db->exec("CREATE TABLE activity_log (id INTEGER PRIMARY KEY, user_id INTEGER, action TEXT, details TEXT, invoice_id INTEGER, created_at TEXT)");
        $db->exec("CREATE TABLE stripe_webhook_events (id INTEGER PRIMARY KEY, event_id TEXT UNIQUE, event_type TEXT, object_id TEXT,
                   amount_cents INTEGER, processed_at TEXT)");
        $this->db = $db;
    }

    private function lines(string $source): array
    {
        return $this->db->query("SELECT a.code, l.debit, l.credit FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id
                                 JOIN chart_of_accounts a ON a.id = l.account_id WHERE e.source_type = '{$source}' ORDER BY l.id")->fetchAll();
    }

    /** As public/api/stripe/webhook.php maps a charge.dispute.* event. */
    private function dispute(string $eventId, string $status = 'needs_response'): array
    {
        $object = ['id' => 'dp_1', 'object' => 'dispute', 'charge' => 'ch_1', 'payment_intent' => 'pi_1',
                   'amount' => 12500, 'reason' => 'fraudulent', 'status' => $status];
        return ['event_id' => $eventId, 'dispute_id' => $object['id'], 'charge_id' => $object['charge'],
                'payment_intent_id' => $object['payment_intent'], 'amount_cents' => $object['amount'],
                'reason' => $object['reason'], 'status' => $object['status']];
    }

    public function test_dispute_flags_invoice_posts_reversal_and_alerts_once(): void
    {
        $svc = new StripeEventLedgerService($this->db, new UncachedLedger($this->db));
        $r = $svc->disputeCreated($this->dispute('evt_d1'));
        $this->assertSame('done', $r['status']);
        $this->assertSame('disputed', $this->db->query("SELECT dispute_status FROM invoices WHERE id = 10")->fetchColumn());
        $this->assertEquals([['code' => '1100', 'debit' => 125.0, 'credit' => 0.0], ['code' => '1010', 'debit' => 0.0, 'credit' => 125.0]],
                            $this->lines('stripe_dispute'));

        $this->assertSame('duplicate', $svc->disputeCreated($this->dispute('evt_d1'))['status'], 'redelivered event');
        $this->assertCount(2, $this->lines('stripe_dispute'));

        $row = $this->db->query("SELECT id, action, invoice_id, details FROM activity_log WHERE action = 'stripe_dispute'")->fetch();
        $alert = CharlieUrgentService::disputeAlert($row + ['invoice_number' => 'INV-2026-0100']);
        $this->assertStringContainsString('$125.00', $alert['text']);
        $this->assertStringContainsString('INV-2026-0100', $alert['text']);
    }

    public function test_won_dispute_reverses_the_entry(): void
    {
        $svc = new StripeEventLedgerService($this->db, new UncachedLedger($this->db));
        $svc->disputeCreated($this->dispute('evt_d1'));
        $svc->disputeClosed($this->dispute('evt_d2', 'won'));
        $this->assertSame('won', $this->db->query("SELECT dispute_status FROM invoices WHERE id = 10")->fetchColumn());
        $this->assertNotNull($this->db->query("SELECT reversed_by_entry_id FROM journal_entries WHERE source_type = 'stripe_dispute'")->fetchColumn());
    }

    public function test_refunds_go_through_customer_deposits_and_only_the_new_part_is_booked(): void
    {
        $svc = new StripeEventLedgerService($this->db, new UncachedLedger($this->db));
        // charge.refunded carries the running total: $40 then $100 in all.
        $svc->refunded(['event_id' => 'evt_r1', 'charge_id' => 'ch_1', 'payment_intent_id' => 'pi_1', 'amount_refunded_cents' => 4000]);
        $svc->refunded(['event_id' => 'evt_r2', 'charge_id' => 'ch_1', 'payment_intent_id' => 'pi_1', 'amount_refunded_cents' => 10000]);
        $this->assertSame('duplicate', $svc->refunded(['event_id' => 'evt_r2', 'charge_id' => 'ch_1', 'payment_intent_id' => 'pi_1', 'amount_refunded_cents' => 10000])['status']);
        $this->assertEquals([
            ['code' => '2160', 'debit' => 40.0, 'credit' => 0.0], ['code' => '1010', 'debit' => 0.0, 'credit' => 40.0],
            ['code' => '2160', 'debit' => 60.0, 'credit' => 0.0], ['code' => '1010', 'debit' => 0.0, 'credit' => 60.0],
        ], $this->lines('stripe_refund'));
        $this->assertSame('paid', $this->db->query("SELECT status FROM invoices WHERE id = 10")->fetchColumn(), 'invoice left for a person');
    }

    public function test_payment_failed_never_overwrites_a_succeeded_payment(): void
    {
        $sql = StripeEventLedgerService::paymentFailedUpsertSql();
        $this->assertMatchesRegularExpression("/status\s*=\s*IF\(status = 'succeeded', status, 'failed'\)\s*$/", $sql, 'status assigned last so earlier IFs see the old value');
        $webhook = (string)file_get_contents(__DIR__ . '/../../../public/api/stripe/webhook.php');
        $this->assertStringContainsString('paymentFailedUpsertSql()', $webhook);
        $this->assertStringContainsString("=== 'succeeded'", $webhook);
        foreach (['charge.dispute.created', 'charge.dispute.closed', 'charge.refunded'] as $t) $this->assertStringContainsString("'{$t}'", $webhook);
    }
}
