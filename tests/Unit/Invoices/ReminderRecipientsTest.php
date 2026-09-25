<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * resolveReminderRecipients() — who gets chased for payment.
 *
 * The bug this pins down: invoices.contact_id is the contract's counterparty
 * (for a strata, the council rep who signed), while the invoice itself is billed
 * to the management firm's accounts contact. Reminder senders that read
 * invoices.contact_id chased the rep for money invoiced to the property manager.
 *
 * Runs against in-memory SQLite with MySQL's FIELD() shimmed, so the role
 * precedence ORDER BY is exercised rather than mocked away.
 */
class ReminderRecipientsTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // MySQL FIELD(needle, a, b, c) → 1-based position, 0 when absent.
        $this->db->sqliteCreateFunction('FIELD', static function (...$args) {
            $needle = array_shift($args);
            $pos = array_search($needle, $args, true);
            return $pos === false ? 0 : $pos + 1;
        });

        $this->db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, contact_id INT, property_id INT)");
        $this->db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                         email TEXT, mobile TEXT, phone TEXT, receive_sms INT DEFAULT 0)");
        $this->db->exec("CREATE TABLE invoice_contacts (id INTEGER PRIMARY KEY, invoice_id INT, contact_id INT,
                         contact_role TEXT, email_address TEXT, bounced INT DEFAULT 0)");

        // The strata rep who signed the contract…
        $this->contact(1, 'Ron', 'Harvie', 'ron@brandonlodge.example', '6045550101', 1);
        // …and the management firm's accounts person, who is actually billed.
        $this->contact(2, 'Jodi', 'Peacock', 'invoices@vml.example', '6045550202', 0);
    }

    private function contact(int $id, string $first, string $last, string $email, string $mobile, int $sms): void
    {
        $this->db->prepare("INSERT INTO contacts (id, first_name, last_name, email, mobile, receive_sms) VALUES (?,?,?,?,?,?)")
                 ->execute([$id, $first, $last, $email, $mobile, $sms]);
    }

    private function invoice(int $id, ?int $contactId, ?int $propertyId = null): void
    {
        $this->db->prepare("INSERT INTO invoices (id, contact_id, property_id) VALUES (?,?,?)")
                 ->execute([$id, $contactId, $propertyId]);
    }

    private function recipientRow(int $invoiceId, ?int $contactId, string $role, string $email, int $bounced = 0): void
    {
        $this->db->prepare("INSERT INTO invoice_contacts (invoice_id, contact_id, contact_role, email_address, bounced) VALUES (?,?,?,?,?)")
                 ->execute([$invoiceId, $contactId, $role, $email, $bounced]);
    }

    /** The bug, pinned: the snapshot wins over invoices.contact_id. */
    public function testPrefersTheBilledPropertyManagerOverTheStrataRep(): void
    {
        $this->invoice(10, 1);                                    // contact_id = the rep
        $this->recipientRow(10, 2, 'primary_recipient', 'invoices@vml.example');

        $out = resolveReminderRecipients(10, $this->db);

        $this->assertCount(1, $out);
        $this->assertSame('invoices@vml.example', $out[0]['email_address']);
        $this->assertSame(2, $out[0]['contact_id']);
        $this->assertSame('invoice_contacts', $out[0]['source']);
    }

    /** cc/bcc received a copy; they are not the party being asked to pay. */
    public function testCcAndBccAreNotChasedForPayment(): void
    {
        $this->invoice(11, 1);
        $this->recipientRow(11, 2, 'billing_contact', 'invoices@vml.example');
        $this->recipientRow(11, 1, 'cc', 'ron@brandonlodge.example');
        $this->recipientRow(11, 1, 'bcc', 'ron@brandonlodge.example');

        $out = resolveReminderRecipients(11, $this->db);

        $this->assertSame(['invoices@vml.example'], array_column($out, 'email_address'));
    }

    public function testBillingContactOutranksOtherBillingRoles(): void
    {
        $this->invoice(12, 1);
        $this->recipientRow(12, 1, 'strata_manager', 'ron@brandonlodge.example');
        $this->recipientRow(12, 2, 'billing_contact', 'invoices@vml.example');

        $out = resolveReminderRecipients(12, $this->db);

        $this->assertSame('invoices@vml.example', $out[0]['email_address'], 'billing_contact should sort first');
        $this->assertCount(2, $out, 'both are billing-side recipients; order is what matters');
    }

    public function testBouncedRecipientIsSkipped(): void
    {
        $this->invoice(13, 1);
        $this->recipientRow(13, 2, 'billing_contact', 'invoices@vml.example', 1);

        // Nothing billable left and no property → falls through to the invoice contact.
        $out = resolveReminderRecipients(13, $this->db);

        $this->assertSame('ron@brandonlodge.example', $out[0]['email_address']);
        $this->assertSame('invoice_contact_id', $out[0]['source']);
    }

    /** An email-only PM inbox is a real recipient that can never be texted. */
    public function testContactlessInboxIsAddressableButNotTextable(): void
    {
        $this->invoice(14, 1);
        $this->recipientRow(14, null, 'billing_contact', 'ap@vml.example');

        $out = resolveReminderRecipients(14, $this->db);

        $this->assertSame('ap@vml.example', $out[0]['email_address']);
        $this->assertSame(0, $out[0]['contact_id']);
        $this->assertFalse($out[0]['receive_sms']);
        $this->assertNull($out[0]['phone']);
    }

    /** A plain client with no routing keeps the original behaviour. */
    public function testUnmanagedInvoiceStillUsesItsOwnContact(): void
    {
        $this->invoice(15, 1);

        $out = resolveReminderRecipients(15, $this->db);

        $this->assertSame('ron@brandonlodge.example', $out[0]['email_address']);
        $this->assertTrue($out[0]['receive_sms']);
        $this->assertSame('6045550101', $out[0]['phone']);
    }

    /** No recipient at all → empty, so the caller can skip instead of burning a reminder. */
    public function testNoResolvableRecipientReturnsEmpty(): void
    {
        $this->invoice(16, null);

        $this->assertSame([], resolveReminderRecipients(16, $this->db));
    }

    public function testUnknownInvoiceReturnsEmpty(): void
    {
        $this->assertSame([], resolveReminderRecipients(999, $this->db));
    }

    /** The same address reached twice under two roles is one email, not two. */
    public function testDuplicateEmailIsDeduped(): void
    {
        $this->invoice(17, 1);
        $this->recipientRow(17, 2, 'billing_contact', 'invoices@vml.example');
        $this->recipientRow(17, 2, 'accounting', 'INVOICES@vml.example');

        $out = resolveReminderRecipients(17, $this->db);

        $this->assertCount(1, $out);
    }
}
