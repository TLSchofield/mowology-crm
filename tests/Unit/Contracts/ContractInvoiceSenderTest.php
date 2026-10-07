<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Invoices/Support/InvoicePdfTestKit.php';

/**
 * Dry run of the monthly contract billing send step (contract_billing.php → ContractInvoiceSender)
 * against an in-memory DB: one invoice goes out WITH its PDF, one is held because the PDF
 * could not be made, and the held one is retried on the next run.
 */
class ContractInvoiceSenderTest extends TestCase
{
    private string $dir;
    /** @var string|false */
    private $prevLog;
    private PDO $db;
    private object $gen;
    /** @var array<int, array{to: string, subject: string, attach: string}> */
    private array $sent = [];
    private array $after = [];
    /** invoice id → PDF the fake generator makes for it (null = generation fails) */
    private array $pdfFor = [];

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('formatCurrency')) {
            require_once __DIR__ . '/../../../app/Services/CrmFunctions.php';
        }
    }

    protected function setUp(): void
    {
        $this->dir = InvoicePdfTestKit::tmpDir();
        $this->prevLog = ini_set('error_log', $this->dir . '/php-error.log');
        $this->db = InvoicePdfTestKit::sqlite();
        $this->db->exec("CREATE TABLE contracts (id INTEGER PRIMARY KEY, contract_number TEXT)");
        $this->db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, mobile TEXT, receive_sms INT DEFAULT 0)");
        $this->db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, invoice_number TEXT, invoice_date TEXT, due_date TEXT,
                         total_amount REAL, access_token TEXT, contract_id INT NULL, contact_id INT NULL,
                         status TEXT DEFAULT 'draft', sent_at TEXT NULL)");
        $this->db->exec("CREATE TABLE invoice_contacts (id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INT, contact_id INT NULL,
                         contact_role TEXT, email_address TEXT, invoice_sent_at TEXT NULL)");

        $this->db->exec("INSERT INTO contracts VALUES (1, 'CTR-0001'), (2, 'CTR-0002')");
        $this->db->exec("INSERT INTO contacts VALUES (10, 'Jane', 'Rep', NULL, 0), (20, 'Sam', 'Council', NULL, 0)");
        $today = date('Y-m-d');
        $due = date('Y-m-t');
        $ins = $this->db->prepare("INSERT INTO invoices (id, invoice_number, invoice_date, due_date, total_amount, access_token, contract_id, contact_id)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([101, 'INV-T-0101', $today, $due, 525.00, 'tok101', 1, 10]);
        $ins->execute([102, 'INV-T-0102', $today, $due, 315.00, 'tok102', 2, 20]);
        $this->db->exec("INSERT INTO invoice_contacts (invoice_id, contact_id, contact_role, email_address) VALUES
                         (101, NULL, 'primary_recipient', 'accounts@pmfirm.example'),
                         (102, 20, 'primary_recipient', 'sam@strata.example')");

        $this->pdfFor = [101 => InvoicePdfTestKit::pdfFile($this->dir, 'INV-T-0101.pdf'), 102 => null];
        $this->gen = new class($this->pdfFor) {
            public array $map;
            public function __construct(array &$map) { $this->map = &$map; }
            public function generateInvoicePdf(int $id): array
            {
                return !empty($this->map[$id]) ? ['success' => true, 'path' => $this->map[$id]] : ['success' => false, 'error' => 'mPDF: out of memory'];
            }
            public function getPdfPath(string $type, int $id): ?string { return null; }
        };
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string)$this->prevLog);
        InvoicePdfTestKit::rmDir($this->dir);
    }

    private function sender(): ContractInvoiceSender
    {
        return new ContractInvoiceSender(
            $this->db,
            new InvoicePdfGate($this->db, $this->gen),
            function (string $to, string $subject, string $html, string $attach): bool {
                $this->sent[] = ['to' => $to, 'subject' => $subject, 'attach' => $attach];
                return true;
            },
            function (int $id, array $row): void { $this->after[] = $id; }
        );
    }

    private function invoiceState(int $id): array
    {
        return $this->db->query("SELECT status, sent_at FROM invoices WHERE id = {$id}")->fetch();
    }

    public function test_one_sent_with_pdf_one_held_then_retried_next_run(): void
    {
        $s = $this->sender();

        // ── Run 1 (the 1st of the month) ──
        $a = $s->send(101);
        $b = $s->send(102);

        $this->assertSame('sent', $a['status']);
        $this->assertCount(1, $this->sent, 'only the invoice with a PDF is emailed');
        $this->assertSame('accounts@pmfirm.example', $this->sent[0]['to']);
        $this->assertSame($this->pdfFor[101], $this->sent[0]['attach'], 'the PDF rides on the email');
        $this->assertStringContainsString('INV-T-0101', $this->sent[0]['subject']);
        $this->assertSame('sent', $this->invoiceState(101)['status']);
        $this->assertNotNull($this->invoiceState(101)['sent_at']);
        $this->assertSame([101], $this->after, 'autopay/SMS/log hooks run only for the sent one');

        $this->assertSame('held', $b['status']);
        $this->assertStringContainsString(InvoicePdfGate::NOT_SENT_MESSAGE, $b['message']);
        $this->assertSame(['status' => 'draft', 'sent_at' => null], $this->invoiceState(102), 'held invoice is NOT marked sent');
        $this->assertSame([102], $s->heldInvoiceIds(), 'held invoice is queued for the next run');

        // Charlie sees exactly one alert for it.
        $rows = $this->db->query("SELECT invoice_id, details, '' AS invoice_number FROM activity_log WHERE action = 'invoice_pdf_blocked'")->fetchAll();
        $this->assertSame(['urgent:invoice_pdf:102'], array_column(CharlieUrgentService::pdfBlockedAlerts($rows), 'key'));

        // ── Run 2: still no PDF → still held, nothing sent, still queued ──
        $this->assertSame('held', $s->send(102)['status']);
        $this->assertCount(1, $this->sent);
        $this->assertSame([102], $s->heldInvoiceIds());

        // ── Run 3: PDF works again → the retry sends it, with the PDF, and it leaves the queue ──
        $this->pdfFor[102] = InvoicePdfTestKit::pdfFile($this->dir, 'INV-T-0102.pdf');
        foreach ($s->heldInvoiceIds() as $id) {
            $this->assertSame('sent', $s->send($id)['status']);
        }
        $this->assertCount(2, $this->sent);
        $this->assertSame('sam@strata.example', $this->sent[1]['to']);
        $this->assertSame($this->pdfFor[102], $this->sent[1]['attach']);
        $this->assertSame('sent', $this->invoiceState(102)['status']);
        $this->assertSame([], $s->heldInvoiceIds());

        // A sent invoice is never re-sent by a later retry.
        $this->assertSame('failed', $s->send(101)['status']);
        $this->assertCount(2, $this->sent);
    }

    public function test_pdf_vanishing_between_gate_and_send_holds_the_invoice(): void
    {
        $s = new ContractInvoiceSender(
            $this->db,
            new class($this->db, $this->gen) extends InvoicePdfGate {
                public function isUsable(?string $path): bool { return false; }
            },
            function () { $this->sent[] = func_get_args(); return true; },
            function () {}
        );
        $this->assertSame('held', $s->send(101)['status']);
        $this->assertSame([], $this->sent);
        $this->assertSame('draft', $this->invoiceState(101)['status']);
    }

    public function test_held_drafts_from_other_contexts_or_already_sent_are_not_retried(): void
    {
        $this->db->exec("INSERT INTO activity_log (invoice_id, action, details) VALUES (101, 'invoice_pdf_blocked', '{\"context\":\"view_send\"}')");
        $this->assertSame([], $this->sender()->heldInvoiceIds());
    }
}
