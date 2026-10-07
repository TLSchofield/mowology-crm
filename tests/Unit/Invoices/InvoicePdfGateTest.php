<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/InvoicePdfTestKit.php';

/**
 * InvoicePdfGate — owner rule 2026-10-06: every invoice email MUST carry the invoice PDF.
 * ensurePdf(): fresh render → cached copy → the file exists, is non-empty and starts "%PDF".
 */
class InvoicePdfGateTest extends TestCase
{
    private string $dir;
    /** @var string|false */
    private $prevLog;
    private FakeInvoicePdfGenerator $gen;

    protected function setUp(): void
    {
        $this->dir = InvoicePdfTestKit::tmpDir();
        $this->prevLog = ini_set('error_log', $this->dir . '/php-error.log');
        $this->gen = new FakeInvoicePdfGenerator();
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string)$this->prevLog);
        InvoicePdfTestKit::rmDir($this->dir);
    }

    private function gate(?PDO $db = null): InvoicePdfGate
    {
        return new InvoicePdfGate($db, $this->gen);
    }

    public function test_generated_pdf_is_returned(): void
    {
        $fresh = InvoicePdfTestKit::pdfFile($this->dir, 'fresh.pdf');
        $this->gen->generate = ['success' => true, 'path' => $fresh];
        $this->gen->cached = InvoicePdfTestKit::pdfFile($this->dir, 'old.pdf');

        $gate = $this->gate();
        $this->assertSame($fresh, $gate->ensurePdf(7));
        $this->assertNull($gate->lastError());
    }

    public function test_generate_fails_but_cached_copy_is_used(): void
    {
        $cached = InvoicePdfTestKit::pdfFile($this->dir, 'cached.pdf');
        $this->gen->generate = ['success' => false, 'error' => 'mPDF exploded'];
        $this->gen->cached = $cached;

        $this->assertSame($cached, $this->gate()->ensurePdf(7));
    }

    public function test_generate_throwing_still_falls_back_to_cache(): void
    {
        $cached = InvoicePdfTestKit::pdfFile($this->dir, 'cached.pdf');
        $this->gen->generate = new RuntimeException('vendor missing');
        $this->gen->cached = $cached;

        $this->assertSame($cached, $this->gate()->ensurePdf(7));
    }

    public function test_both_fail_returns_null_with_the_reasons(): void
    {
        $this->gen->generate = ['success' => false, 'error' => 'mPDF exploded'];
        $this->gen->cached = null;

        $gate = $this->gate();
        $this->assertNull($gate->ensurePdf(7));
        $this->assertStringContainsString('mPDF exploded', (string)$gate->lastError());
        $this->assertStringContainsString('no cached PDF', (string)$gate->lastError());
    }

    public function test_a_non_pdf_file_is_rejected_everywhere(): void
    {
        $html = InvoicePdfTestKit::pdfFile($this->dir, 'error.pdf', '<html>Fatal error</html>');
        $this->gen->generate = ['success' => true, 'path' => $html];
        $this->gen->cached = $html;

        $gate = $this->gate();
        $this->assertNull($gate->ensurePdf(7));
        $this->assertStringContainsString('not a PDF', (string)$gate->lastError());
    }

    public function test_empty_and_missing_files_are_rejected(): void
    {
        $empty = InvoicePdfTestKit::pdfFile($this->dir, 'empty.pdf', '');
        $this->gen->generate = ['success' => true, 'path' => $empty];
        $this->gen->cached = $this->dir . '/gone.pdf';

        $gate = $this->gate();
        $this->assertNull($gate->ensurePdf(7));
        $this->assertStringContainsString('empty', (string)$gate->lastError());
        $this->assertStringContainsString('missing', (string)$gate->lastError());
    }

    public function test_is_usable_notices_a_file_that_vanished_after_the_gate(): void
    {
        $fresh = InvoicePdfTestKit::pdfFile($this->dir, 'fresh.pdf');
        $this->gen->generate = ['success' => true, 'path' => $fresh];
        $gate = $this->gate();
        $path = $gate->ensurePdf(7);

        $this->assertTrue($gate->isUsable($path));
        unlink($fresh);
        $this->assertFalse($gate->isUsable($path));
        $this->assertFalse($gate->isUsable(null));
    }

    public function test_record_blocked_writes_the_row_charlie_and_the_retry_read(): void
    {
        $db = InvoicePdfTestKit::sqlite();
        $this->gen->generate = ['success' => false, 'error' => 'mPDF exploded'];
        $gate = $this->gate($db);
        $this->assertNull($gate->ensurePdf(42));
        $gate->recordBlocked(42, 'contract_billing');

        $row = $db->query("SELECT invoice_id, action, details FROM activity_log")->fetch();
        $this->assertSame(42, (int)$row['invoice_id']);
        $this->assertSame(InvoicePdfGate::BLOCKED_ACTION, $row['action']);
        $d = json_decode($row['details'], true);
        $this->assertSame('contract_billing', $d['context']);
        $this->assertStringContainsString('mPDF exploded', $d['reason']);
        $this->assertStringContainsString('"context":"contract_billing"', $row['details']); // the retry query's LIKE
    }

    public function test_record_blocked_never_throws_without_a_table(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->gate($db)->recordBlocked(1, 'view_send', 'x');
        $this->assertTrue(true);
    }

    public function test_charlie_turns_blocked_rows_into_one_alert_per_invoice(): void
    {
        $alerts = CharlieUrgentService::pdfBlockedAlerts([
            ['invoice_id' => 9, 'invoice_number' => 'CTR-2026-0009', 'details' => '{"context":"contract_billing","reason":"x"}'],
            ['invoice_id' => 9, 'invoice_number' => 'CTR-2026-0009', 'details' => '{"context":"contract_billing","reason":"y"}'],
            ['invoice_id' => 4, 'invoice_number' => 'INV-2026-0004', 'details' => '{"context":"reminder","reason":"z"}'],
        ]);
        $this->assertSame(['urgent:invoice_pdf:9', 'urgent:invoice_pdf:4'], array_column($alerts, 'key'));
        $this->assertStringStartsWith('Monthly contract invoice CTR-2026-0009 not sent', $alerts[0]['text']);
        $this->assertStringContainsString('PDF could not be made', $alerts[0]['text']);
        $this->assertStringStartsWith('Payment reminder for invoice INV-2026-0004 held', $alerts[1]['text']);
        $this->assertSame('/crm/invoices/view.php?id=9', $alerts[0]['url']);
    }
}
