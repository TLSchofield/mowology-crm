<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Reminders to an accounts-payable inbox carry the invoice PDF, and a client's "invoice not accepted" notice is recognised, recorded on
 * the invoice, and raised to the owner instead of being filed as an expense (2026-10-28: LeapAP rejected the INV-2026-0431 reminder, "No
 * PDF or image attachments found"; the notice became draft expense #492 and nobody was told). Addresses and numbers are invented.
 */
class InvoiceReminderPdfTest extends TestCase
{
    /** @dataProvider apAddresses */
    public function testAccountsPayableAddressesNeedThePdf(string $email, string $role): void
    {
        $this->assertTrue(InvoiceReminderRules::needsPdf($email, $role), $email);
    }

    public static function apAddresses(): array
    {
        return [
            'LeapAP / Condoworks intake'  => ['vr00001450.32095.70cb9b866bda@app.condoworks.co', 'primary_recipient'],
            'invoices mailbox'            => ['invoices@example-realty.test', 'primary_recipient'],
            'inv. prefix'                 => ['inv.intake@example-pm.test', 'billing_contact'],
            'ap mailbox'                  => ['ap@example-strata.test', 'property_manager'],
            'accounts payable'            => ['accounts-payable@example.test', 'billing_contact'],
            'billing'                     => ['billing@example.test', 'billing_contact'],
            'any address in the accounting role' => ['dana.lee@example.test', 'accounting'],
            'a known intake system'       => ['intake@acme.bill.com', 'primary_recipient'],
        ];
    }

    /** @dataProvider peopleAddresses */
    public function testPeopleStayLinkOnly(string $email, string $role): void
    {
        $this->assertFalse(InvoiceReminderRules::needsPdf($email, $role), $email);
    }

    public static function peopleAddresses(): array
    {
        return [
            'a homeowner'        => ['pat@example.test', 'primary_recipient'],
            'a property manager' => ['dana.lee@example.test', 'property_manager'],
            'a strata member'    => ['alena.r@example.test', 'strata_manager'],
            'looks like inv but is a name' => ['invernizzi.paul@example.test', 'primary_recipient'],
            'empty'              => ['', 'primary_recipient'],
            'not an address'     => ['nope', 'accounting'],
        ];
    }

    // ── The notice ──────────────────────────────────────────────────────────

    private const LEAPAP = "MOWOLOGY LAWNS & LANDSCAPES LTD. emailed invoice was not accepted\n\nPlease do not reply to or forward this email\n\nReason: No PDF or image attachments found. Resubmit your invoice by email or web link. Maximum file size is 5 MB\n\nInvoice details:\nClient: VR 1450\nClient Address: 998 W 19th Ave, Vancouver\nSender: no-reply@mowology.ca\nDate: Wed, 28 Oct 2026 06:00:09 -0700\nSubject: Invoice INV-2026-0431: \$456.84 due October 31, 2026\n\nIf you have any questions, please contact VR 1450.";

    public function testTheRealNoticeIsRecognised(): void
    {
        $n = InvoiceRejectionNotice::parse('An invoice emailed to VR 1450 was not accepted', self::LEAPAP);
        $this->assertSame('INV-2026-0431', $n['invoice_number']);
        $this->assertSame('No PDF or image attachments found.', $n['reason'], 'just the reason, not the boilerplate');
        $this->assertSame('VR 1450', $n['client']);
    }

    public function testOrdinaryMailIsNotANotice(): void
    {
        $this->assertNull(InvoiceRejectionNotice::parse('Your receipt from RONA', "Thank you for your order. Invoice INV-2026-0001 total \$40."));
        $this->assertNull(InvoiceRejectionNotice::parse('Invoice INV-2026-0002 received', 'We received your invoice and will pay it Friday.'));
        $this->assertNull(InvoiceRejectionNotice::parse('Invoice rejected', 'This invoice was rejected.'), 'no invoice number of ours: nothing to act on');
    }

    private function db(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, invoice_number TEXT, balance_due REAL)");
        $db->exec("CREATE TABLE activity_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, invoice_id INT, action TEXT, details TEXT, created_at TEXT)");
        $db->exec("INSERT INTO invoices VALUES (441, 'INV-2026-0431', 456.84), (442, 'INV-2026-0432', 0)");
        return $db;
    }

    public function testItIsRecordedOnceAndRaisedToTheOwner(): void
    {
        $db = $this->db();
        $n = InvoiceRejectionNotice::parse('An invoice emailed to VR 1450 was not accepted', self::LEAPAP);
        $this->assertTrue(InvoiceRejectionNotice::record($db, $n, '<msg-1@leapap>'));
        $this->assertFalse(InvoiceRejectionNotice::record($db, $n, '<msg-1@leapap>'), 'the same message twice is one record');
        $alerts = InvoiceRejectionWatch::urgent($db);
        $this->assertCount(1, $alerts);
        $this->assertSame('urgent:invoice_rejected:1', $alerts[0]['key']);
        $this->assertStringContainsString("VR 1450's accounts system did not accept INV-2026-0431 (No PDF or image attachments found). It is still unpaid.", $alerts[0]['text']);
        $this->assertSame('/crm/invoices/view.php?id=441', $alerts[0]['url']);
    }

    public function testNoAlertOnceTheInvoiceIsPaidOrForAnUnknownInvoice(): void
    {
        $db = $this->db();
        $this->assertFalse(InvoiceRejectionNotice::record($db, ['invoice_number' => 'INV-2026-9999', 'reason' => 'x', 'client' => ''], 'm'), 'not one of our invoices');
        InvoiceRejectionNotice::record($db, ['invoice_number' => 'INV-2026-0432', 'reason' => 'x', 'client' => ''], 'm2');
        $this->assertSame([], InvoiceRejectionWatch::urgent($db), 'already paid: nothing to chase');
    }

    public function testANoticeThatDoesNotMentionUsIsIgnored(): void
    {
        $other = str_ireplace('mowology', 'Acme', self::LEAPAP);
        $this->assertNull(InvoiceRejectionNotice::parse('An invoice emailed to VR 1450 was not accepted', $other));
    }

    public function testTheSameRefusalWithANewMessageIdIsOneAlert(): void
    {
        $db = $this->db();
        $n = InvoiceRejectionNotice::parse('An invoice emailed to VR 1450 was not accepted', self::LEAPAP);
        $this->assertTrue(InvoiceRejectionNotice::record($db, $n, '<first@leapap>'));
        $this->assertFalse(InvoiceRejectionNotice::record($db, $n, '<second@leapap>'), 'a resend with a new Message-ID is not a new problem');
        $this->assertCount(1, InvoiceRejectionWatch::urgent($db));
    }

    public function testADatabaseErrorIsNotSwallowedSoTheInboxRetries(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->expectException(PDOException::class);
        InvoiceRejectionNotice::record($db, ['invoice_number' => 'INV-2026-0431', 'reason' => 'x', 'client' => ''], 'm');
    }
}
