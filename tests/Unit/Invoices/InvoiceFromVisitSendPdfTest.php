<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/InvoicePdfTestKit.php';

/**
 * InvoiceFromVisitService::send() (crew/iOS "Invoice a Visit", and now invoice-create.php)
 * must attach the invoice PDF, and must send nothing — leaving the draft alone — without one.
 */
class InvoiceFromVisitSendPdfTest extends TestCase
{
    private string $dir;
    /** @var string|false */
    private $prevLog;
    private PDO $db;
    private FakeInvoicePdfGenerator $gen;
    private array $sent = [];
    private array $autopay = [];

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
        $this->gen = new FakeInvoicePdfGenerator();
        $this->db = InvoicePdfTestKit::sqlite();
        $this->db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT,
                         mobile TEXT, phone TEXT, receive_sms INT DEFAULT 0)");
        $this->db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, assigned_crew_id INT NULL, is_invoiced INT DEFAULT 0, invoice_id INT NULL)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT)");
        $this->db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, invoice_number TEXT, contact_id INT, visit_id INT NULL,
                         balance_due REAL, due_date TEXT, access_token TEXT, token_expires_at TEXT NULL,
                         status TEXT DEFAULT 'draft', sent_at TEXT NULL)");
        $this->db->exec("CREATE TABLE invoice_contacts (id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INT, contact_id INT NULL,
                         contact_role TEXT, email_address TEXT, invoice_sent_at TEXT NULL)");
        $this->db->exec("INSERT INTO users VALUES (1, 'admin')");
        $this->db->exec("INSERT INTO contacts (id, first_name, last_name, email) VALUES (5, 'Pat', 'Owner', 'pat@example.com')");
        $this->db->exec("INSERT INTO job_visits (id, assigned_crew_id) VALUES (77, 3)");
        $this->db->prepare("INSERT INTO invoices (id, invoice_number, contact_id, visit_id, balance_due, due_date, access_token, token_expires_at)
                            VALUES (55, 'INV-T-0055', 5, 77, 105.00, '2026-11-05', 'tok55', ?)")
                 ->execute([date('Y-m-d H:i:s', strtotime('+30 days'))]);
        $this->db->exec("INSERT INTO invoice_contacts (invoice_id, contact_id, contact_role, email_address) VALUES (55, 5, 'primary_recipient', 'pat@example.com')");
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string)$this->prevLog);
        InvoicePdfTestKit::rmDir($this->dir);
    }

    private function service(): InvoiceFromVisitService
    {
        return new InvoiceFromVisitService(
            $this->db,
            new InvoicePdfGate($this->db, $this->gen),
            function (string $to, string $subject, string $html, string $attach): bool {
                $this->sent[] = ['to' => $to, 'attach' => $attach];
                return true;
            },
            function (PDO $db, int $id): void { $this->autopay[] = $id; }
        );
    }

    private function invoiceState(): string
    {
        return (string)$this->db->query("SELECT status FROM invoices WHERE id = 55")->fetchColumn();
    }

    public function test_the_pdf_is_attached_and_the_invoice_marked_sent(): void
    {
        $pdf = InvoicePdfTestKit::pdfFile($this->dir, 'INV-T-0055.pdf');
        $this->gen->generate = ['success' => true, 'path' => $pdf];

        $r = @$this->service()->send(55, 1);   // @: the MySQL-only multi-table UPDATE logs and is skipped on SQLite

        $this->assertTrue($r['success']);
        $this->assertSame([['to' => 'pat@example.com', 'attach' => $pdf]], $this->sent);
        $this->assertSame('sent', $this->invoiceState());
        $this->assertSame([55], $this->autopay);
    }

    public function test_no_pdf_means_nothing_sent_and_still_a_draft(): void
    {
        $this->gen->generate = ['success' => false, 'error' => 'mPDF exploded'];

        $r = $this->service()->send(55, 1);

        $this->assertFalse($r['success']);
        $this->assertSame(InvoicePdfGate::NOT_SENT_MESSAGE, $r['error']);
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->autopay);
        $this->assertSame('draft', $this->invoiceState());
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM activity_log WHERE action = 'invoice_pdf_blocked' AND invoice_id = 55")->fetchColumn());
    }

    public function test_a_non_pdf_file_is_not_sent_either(): void
    {
        $this->gen->generate = ['success' => true, 'path' => InvoicePdfTestKit::pdfFile($this->dir, 'x.pdf', 'Warning: mPDF error')];

        $r = $this->service()->send(55, 1);

        $this->assertFalse($r['success']);
        $this->assertSame([], $this->sent);
        $this->assertSame('draft', $this->invoiceState());
    }
}
