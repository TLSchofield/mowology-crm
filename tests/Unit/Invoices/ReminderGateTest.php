<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ReminderGateSqlitePdo extends PDO
{
    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = [])
    {
        $query = str_replace(
            'ON DUPLICATE KEY UPDATE detail = VALUES(detail)',
            'ON CONFLICT(invoice_id, held_on) DO UPDATE SET detail = excluded.detail',
            $query
        );
        return parent::prepare($query, $options);
    }
}

final class ReminderGateTest extends TestCase
{
    private ReminderGateSqlitePdo $db;

    protected function setUp(): void
    {
        $this->db = new ReminderGateSqlitePdo('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE companies (id INTEGER PRIMARY KEY, company_name TEXT);
            CREATE TABLE contacts (id INTEGER PRIMARY KEY, company_id INT);
            CREATE TABLE properties (id INTEGER PRIMARY KEY, billing_company_id INT, property_manager_id INT, company_id INT);
            CREATE TABLE invoices (id INTEGER PRIMARY KEY, contact_id INT, company_id INT, property_id INT, status TEXT,
                balance_due REAL, total REAL, due_date TEXT, paid_at TEXT, issue_date TEXT, created_at TEXT);
            CREATE TABLE invoice_payment_allocations (id INTEGER PRIMARY KEY, invoice_id INT, transaction_id INT, amount REAL, payment_date TEXT);
            CREATE TABLE accounting_transactions (id INTEGER PRIMARY KEY, transaction_date TEXT, type TEXT, amount REAL,
                description TEXT, reference_type TEXT);
            CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT);
            CREATE TABLE invoice_reminder_holds (id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INT, reason TEXT, detail TEXT,
                mode TEXT, would_send_type TEXT, held_on TEXT, created_at TEXT, UNIQUE(invoice_id, held_on));");
        $this->db->exec("INSERT INTO contacts VALUES (10, NULL), (11, 2);
            INSERT INTO companies VALUES (1, 'One'), (2, 'Two');
            INSERT INTO properties VALUES (20, NULL, NULL, NULL), (21, 1, NULL, NULL);
            INSERT INTO invoices VALUES
              (1,10,NULL,20,'sent',100,100,'2026-10-20',NULL,'2026-10-01','2026-10-01'),
              (2,11,NULL,20,'sent',200,200,'2026-10-10',NULL,'2026-10-01','2026-10-01'),
              (3,10,NULL,21,'sent',300,300,'2026-10-10',NULL,'2026-10-01','2026-10-01');");
        $this->setMode('on');
    }

    public function testP75UsesNearestRankAndClampsEarlyPayments(): void
    {
        self::assertSame(3, ReminderGate::p75([-2, 2, 3]));
        self::assertSame(2, ReminderGate::p75([0, 1, 2, 3]));
        self::assertSame(3, ReminderGate::p75([0, 1, 2, 3, 4]));
        self::assertSame(4, ReminderGate::p75([0, 1, 2, 3, 4, 5]));
    }

    public function testContactCompanyFallbackUsesCompanyHistoryAndStatementGate(): void
    {
        foreach ([1, 2, 3] as $lag) {
            $id = 20 + $lag;
            $paid = sprintf('2026-0%d-%02d', $lag, 1 + $lag);
            $this->db->prepare("INSERT INTO invoices VALUES (?,11,NULL,20,'paid',0,200,?,?, '2026-01-01','2026-01-01')")
                ->execute([$id, sprintf('2026-0%d-01', $lag), $paid]);
        }

        $gate = new ReminderGate($this->db, '2026-11-02');
        self::assertSame(['key' => 'company:2', 'is_company' => true], $gate->payerKeyFor(2));
        $habit = $gate->habitFor('company:2');
        self::assertSame(3, $habit['n']);
        self::assertSame(3, $habit['p75']);

        $decision = $gate->decide($this->invoice(2), [['email_address' => 'person@x.com']]);
        self::assertSame('company:2', $decision['payer_key']);
        self::assertSame(7, $decision['grace_days']);
        self::assertSame('statement_stale', $decision['reason']);
    }

    public function testGraceUsesHabitAndCompanyOrIndividualDefaults(): void
    {
        foreach ([1, 2, 3] as $n) {
            $id = 10 + $n;
            $due = sprintf('2026-0%d-01', $n);
            $paid = sprintf('2026-0%d-%02d', $n, 5 + $n);
            $this->db->prepare("INSERT INTO invoices VALUES (?,10,NULL,20,'paid',0,100,?,?, '2026-01-01','2026-01-01')")
                ->execute([$id, $due, $paid]);
        }
        $gate = new ReminderGate($this->db, '2026-11-02');
        self::assertSame(['days' => 10, 'basis' => 'habit'], $gate->graceDaysFor('contact:10', false));
        self::assertSame(['days' => 14, 'basis' => 'default_company'], $gate->graceDaysFor('company:1', true));
        self::assertSame(['days' => 7, 'basis' => 'default_individual'], $gate->graceDaysFor('contact:999', false));
    }

    /** @dataProvider apAddresses */
    public function testApInboxExamples(string $email, bool $expected): void
    {
        self::assertSame($expected, ReminderGate::isApInbox($email));
    }

    public static function apAddresses(): array
    {
        return [
            ['invoices@dorsetrealty.com', true], ['vcfmoinvoices@vch.ca', true], ['ap@x.com', true],
            ['accounts.payable@x.com', true], ['leapap.55@app.condoworks.co', true],
            ['joann@dorsetrealty.com', false], ['tim@gmail.com', false], ['apple@x.com', false], ['info@x.com', false],
            ['billing@strata123.ca', true], ['someone@leapap.com', true], ['kim@app.condoworks.co', true],
            ['invoicing@pm.ca', true], ['arlene@x.com', false], ['apollo@x.com', false],
        ];
    }

    public function testApInboxHonoursRoleAndExtraDomains(): void
    {
        self::assertTrue(ReminderGate::isApInbox('jane@pmfirm.ca', 'accounting'));
        self::assertFalse(ReminderGate::isApInbox('jane@pmfirm.ca', 'property_manager'));
        self::assertTrue(ReminderGate::isApInbox('intake@portal.example.com', '', ['portal.example.com']));
        self::assertFalse(ReminderGate::isApInbox('intake@portal.example.com'));
    }

    public function testDecisionOrderApMixedGraceDepositStatementsAndOk(): void
    {
        $gate = new ReminderGate($this->db, '2026-11-02');
        $ap = [['email_address' => 'ap@x.com', 'source' => 'snapshot']];
        self::assertSame('ap_inbox', $gate->decide($this->invoice(1), $ap)['reason']);

        $mixed = array_merge($ap, [['email_address' => 'person@x.com', 'source' => 'snapshot']]);
        $mixedDecision = $gate->decide($this->invoice(1), $mixed);
        self::assertSame('ok', $mixedDecision['reason']);
        self::assertSame('person@x.com', $mixedDecision['recipients'][0]['email_address']);

        self::assertSame('before_grace', $gate->decide($this->invoice(1, ['due_date' => '2026-10-28']), $mixed)['reason']);

        $this->db->exec("INSERT INTO accounting_transactions VALUES (50,'2026-10-22','income',100,'EFT','bank_import')");
        self::assertSame('deposit_candidate', $gate->decide($this->invoice(1), $mixed)['reason']);
        $this->db->exec("INSERT INTO invoice_payment_allocations VALUES (1,99,50,100,'2026-10-22')");

        self::assertSame('statement_stale', $gate->decide($this->invoice(3), $mixed)['reason']);
        // Individuals do not wait for statements.
        self::assertSame('ok', $gate->decide($this->invoice(1), $mixed)['reason']);

        $report = ['accounts' => [['expected' => true, 'last_month' => ['status' => 'ok', 'through' => '2026-10-31']]]];
        $this->db->prepare("INSERT INTO ops_settings VALUES (?, ?)")->execute([StatementCoverageService::CACHE_KEY, json_encode($report)]);
        self::assertSame('ok', (new ReminderGate($this->db, '2026-11-02'))->decide($this->invoice(3), $mixed)['reason']);
    }

    public function testModesAndHoldRecording(): void
    {
        $invoice = $this->invoice(1);
        $ap = [['email_address' => 'ap@x.com']];

        $this->setMode('off');
        $gate = new ReminderGate($this->db, '2026-11-02');
        $d = $gate->decide($invoice, $ap);
        self::assertTrue($d['send']);
        $gate->recordHold($d, 1);
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM invoice_reminder_holds')->fetchColumn());

        $this->setMode('shadow');
        $gate = new ReminderGate($this->db, '2026-11-02');
        $d = $gate->decide($invoice, $ap);
        self::assertTrue($d['send']);
        $gate->recordHold($d, 1);
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM invoice_reminder_holds')->fetchColumn());

        $this->setMode('on');
        self::assertFalse((new ReminderGate($this->db, '2026-11-02'))->decide($invoice, $ap)['send']);
    }

    public function testMissingSettingDefaultsToShadowAndMissingHoldTableDoesNotThrow(): void
    {
        $this->db->exec("DELETE FROM ops_settings WHERE setting_key = 'reminder_grace_mode'; DROP TABLE invoice_reminder_holds");
        $gate = new ReminderGate($this->db, '2026-11-02');
        self::assertSame('shadow', $gate->mode());
        $d = $gate->decide($this->invoice(1), [['email_address' => 'ap@x.com']]);
        self::assertTrue($d['send']);
        $gate->recordHold($d, 1);
        self::assertTrue(true);
    }

    private function setMode(string $mode): void
    {
        $this->db->prepare("INSERT OR REPLACE INTO ops_settings VALUES ('reminder_grace_mode', ?)")->execute([$mode]);
    }

    private function invoice(int $id, array $changes = []): array
    {
        $stmt = $this->db->prepare('SELECT * FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        return array_merge($stmt->fetch(PDO::FETCH_ASSOC), $changes);
    }
}
