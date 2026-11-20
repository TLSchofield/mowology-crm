<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/IcloudDedupePdo.php';

/**
 * An email that reached office@ AND iCloud is stored once — in every store the router feeds —
 * and the lead / vendor stores keep what they should.
 */
class IcloudDedupeTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new IcloudDedupePdo('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, email TEXT)");
        $this->db->exec("INSERT INTO contacts (id, email) VALUES (7, 'jodi@vanmgmt.ca')");
        $this->db->exec("CREATE TABLE sales_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, mailbox TEXT, message_key TEXT UNIQUE, direction TEXT,
            channel TEXT, contact_id INT, from_addr TEXT, to_addr TEXT, subject TEXT, snippet TEXT, signature TEXT, sent_at TEXT)");
        $this->db->exec("CREATE TABLE vendor_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, message_key TEXT UNIQUE, mailbox TEXT, vendor_id INT,
            direction TEXT, from_addr TEXT, to_addr TEXT, subject TEXT, snippet TEXT, sent_at TEXT)");
        $this->db->exec("CREATE TABLE email_lead_candidates (id INTEGER PRIMARY KEY AUTOINCREMENT, message_key TEXT UNIQUE, mailbox TEXT,
            from_name TEXT, from_addr TEXT, subject TEXT, snippet TEXT, address TEXT, services TEXT, strength TEXT, score INT,
            status TEXT DEFAULT 'maybe', quote_request_id INT, contact_id INT, received_at TEXT, decided_by INT, decided_at TEXT)");
        $this->db->exec("CREATE TABLE receipt_inbox_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, dedup_key TEXT UNIQUE, sender_email TEXT,
            subject TEXT, email_date TEXT, attachment_name TEXT, media_id INT, expense_id INT, outcome TEXT, match_confidence INT, note TEXT)");
    }

    public function test_a_customer_email_in_both_mailboxes_is_stored_once(): void
    {
        $svc = new SalesInboxService($this->db);
        $m = ['message_id' => '<CAF=abc123@mail.gmail.com>', 'from' => 'Jodi <jodi@vanmgmt.ca>',
              'to' => 'office@mowology.ca, mowology@icloud.com', 'subject' => 'Re: quote', 'body' => '', 'date' => 'Tue, 6 Oct 2026 10:00:00 -0700'];
        $this->assertSame('stored', $svc->ingest($m + ['mailbox' => 'office@ INBOX']));
        $this->assertSame('dupe', $svc->ingest($m + ['mailbox' => 'icloud INBOX', 'ours' => ['mowology@icloud.com']]));
        $this->assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM sales_messages')->fetchColumn());
        $this->assertSame('office@ INBOX', $this->db->query('SELECT mailbox FROM sales_messages')->fetchColumn());
    }

    public function test_tims_icloud_address_counts_as_us(): void
    {
        $svc = new SalesInboxService($this->db);
        $r = $svc->ingest(['mailbox' => 'icloud Sent', 'message_id' => '<x1@icloud.com>', 'from' => 'Tim <mowology@icloud.com>',
                           'to' => 'jodi@vanmgmt.ca', 'subject' => 'Your quote', 'body' => '', 'date' => 'now', 'ours' => ['mowology@icloud.com']]);
        $this->assertSame('stored', $r);
        $this->assertSame('outbound', $this->db->query('SELECT direction FROM sales_messages')->fetchColumn());
    }

    public function test_vendor_mail_is_stored_once(): void
    {
        $svc = new VendorMessageService($this->db);
        $m = ['message_key' => '<d1@lawnboy.ca>', 'mailbox' => 'icloud INBOX', 'vendor_id' => 9, 'direction' => 'inbound',
              'from' => 'mike@lawnboy.ca', 'to' => 'mowology@icloud.com', 'subject' => 'Re: Thursday delivery',
              'snippet' => 'We can drop the mulch Thursday.', 'sent_at' => '2026-10-06 09:00:00'];
        $this->assertSame('stored', $svc->store($m));
        $this->assertSame('dupe', $svc->store($m));
        $this->assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM vendor_messages')->fetchColumn());
    }

    public function test_a_weak_enquiry_waits_as_a_maybe_and_can_be_dismissed(): void
    {
        $svc = new EmailLeadService($this->db);
        $score = EmailLeadService::score('question', 'Hey, do you do hedges?');
        $m = ['message_key' => '<q1@outlook.com>', 'mailbox' => 'icloud INBOX', 'from_name' => 'Dave K', 'from_addr' => 'Dave.K@outlook.com',
              'subject' => 'question', 'snippet' => 'Hey, do you do hedges?', 'received_at' => date('Y-m-d H:i:s')];
        $this->assertSame('maybe', $svc->record($m, $score));
        $this->assertSame('dupe', $svc->record($m, $score));
        $row = $this->db->query('SELECT * FROM email_lead_candidates')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('dave.k@outlook.com', $row['from_addr']);
        $this->assertSame('hedge', $row['services']);
        $this->assertTrue($svc->dismiss((int)$row['id'], 1));
        $this->assertFalse($svc->dismiss((int)$row['id'], 1));
    }

    public function test_old_enquiries_are_not_kept(): void
    {
        $svc = new EmailLeadService($this->db);
        $score = EmailLeadService::score('Quote', 'Can you quote my lawn at 4512 Oak St?');
        $this->assertSame('strong', $score['strength']);
        $m = ['message_key' => '<old@x.ca>', 'mailbox' => 'icloud INBOX', 'from_name' => '', 'from_addr' => 'a@x.ca',
              'subject' => 'Quote', 'snippet' => '', 'received_at' => date('Y-m-d H:i:s', strtotime('-100 days'))];
        $this->assertSame('stale', $svc->record($m, $score));
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM email_lead_candidates')->fetchColumn());
    }

    public function test_the_same_receipt_file_under_another_message_id_is_not_read_twice(): void
    {
        $svc = new ReceiptInboxService($this->db);
        $bytes = 'not-really-a-receipt';
        $a = $svc->ingestAttachment(['message_id' => '<a@office>', 'sender_email' => 'orders@lawnboy.ca', 'subject' => 'Invoice', 'email_date' => null],
                                    $bytes, 'notes.txt', 'text/plain', 1);
        $this->assertSame('unsupported', $a['status']);
        $again = $svc->ingestAttachment(['message_id' => '<a@office>', 'sender_email' => 'orders@lawnboy.ca', 'subject' => 'Invoice', 'email_date' => null],
                                        $bytes, 'notes.txt', 'text/plain', 1);
        $this->assertSame('duplicate', $again['status']);
        $fwd = $svc->ingestAttachment(['message_id' => '<b@icloud>', 'sender_email' => 'mowology@icloud.com', 'subject' => 'Fwd: Invoice', 'email_date' => null],
                                      $bytes, 'notes.txt', 'text/plain', 1);
        $this->assertSame('duplicate', $fwd['status']);
        $this->assertSame('same file as an earlier email', $fwd['note']);
    }
}
