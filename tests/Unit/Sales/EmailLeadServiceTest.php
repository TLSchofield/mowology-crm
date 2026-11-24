<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Comms/IcloudDedupePdo.php';

/** Sam's leads from email: the free rules, and a strong enquiry becoming a real lead. */
class EmailLeadServiceTest extends TestCase
{
    public function test_robots_are_spotted(): void
    {
        $this->assertTrue(EmailLeadService::isAutomated('noreply@squarespace.com', ''));
        $this->assertTrue(EmailLeadService::isAutomated('do-not-reply@bchydro.com', ''));
        $this->assertTrue(EmailLeadService::isAutomated('hello@shop.ca', "List-Unsubscribe: <mailto:u@shop.ca>\r\n"));
        $this->assertTrue(EmailLeadService::isAutomated('team@app.io', "Precedence: bulk\r\n"));
        $this->assertTrue(EmailLeadService::isAutomated('me@x.ca', "Auto-Submitted: auto-replied\r\n"));
        $this->assertFalse(EmailLeadService::isAutomated('me@x.ca', "Auto-Submitted: no\r\n"));
        $this->assertFalse(EmailLeadService::isAutomated('sarah.chen88@gmail.com', "Subject: Lawn\r\n"));
    }

    public function test_addresses_are_found(): void
    {
        $this->assertSame('123 W 4th', EmailLeadService::findAddress('quote my front lawn at 123 W 4th? thanks'));
        $this->assertSame('88 East 12th Avenue', EmailLeadService::findAddress('We are at 88 East 12th Avenue, Vancouver'));
        $this->assertSame('4512 Oak St', EmailLeadService::findAddress('house at 4512 Oak St.'));
        $this->assertNull(EmailLeadService::findAddress('I have 3 kids and 2 dogs'));
    }

    public function test_strength(): void
    {
        $this->assertSame('strong', EmailLeadService::score('Snow removal', 'How much for snow clearing and salting our strata driveway?')['strength']);
        $this->assertSame('weak', EmailLeadService::score('', 'Are you available to look at my garden?')['strength']);
        $this->assertNull(EmailLeadService::score('Lunch?', 'Are you free for lunch Thursday?')['strength']);
        $this->assertNull(EmailLeadService::score('Photos', 'Here are the photos from the weekend')['strength']);
    }

    public function test_names_split_from_the_sender(): void
    {
        $this->assertSame(['Sarah', 'Chen'], EmailLeadService::splitName('"Sarah Chen"', 'sarah.chen88@gmail.com'));
        $this->assertSame(['Dave', 'K'], EmailLeadService::splitName('', 'dave.k@outlook.com'));
    }

    public function test_a_fresh_strong_enquiry_becomes_a_lead_on_an_existing_contact(): void
    {
        $db = new IcloudDedupePdo('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, email TEXT)");
        $db->exec("INSERT INTO contacts (id, email) VALUES (31, 'Sarah.Chen88@gmail.com')");
        $db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT)");
        $db->exec("CREATE TABLE quote_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, contact_id INT, property_id INT, service_types TEXT,
            urgency TEXT, project_description TEXT, status TEXT, source TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE email_lead_candidates (id INTEGER PRIMARY KEY AUTOINCREMENT, message_key TEXT UNIQUE, mailbox TEXT,
            from_name TEXT, from_addr TEXT, subject TEXT, snippet TEXT, address TEXT, services TEXT, strength TEXT, score INT,
            status TEXT DEFAULT 'maybe', quote_request_id INT, contact_id INT, received_at TEXT, decided_by INT, decided_at TEXT)");

        $body = 'Can you quote my front lawn at 123 W 4th? It needs mowing every two weeks.';
        $res = (new EmailLeadService($db))->record([
            'message_key' => '<lead1@gmail.com>', 'mailbox' => 'icloud INBOX', 'from_name' => 'Sarah Chen', 'from_addr' => 'sarah.chen88@gmail.com',
            'subject' => 'Lawn', 'snippet' => $body, 'received_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ], EmailLeadService::score('Lawn', $body));
        $this->assertSame('lead', $res);
        $qr = $db->query('SELECT * FROM quote_requests')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(31, (int)$qr['contact_id']);
        $this->assertSame('new', $qr['status']);
        $this->assertSame('email', $qr['source']);
        $this->assertStringContainsString('lawn', $qr['service_types']);
        $this->assertStringContainsString('123 W 4th', $qr['project_description']);
        $c = $db->query('SELECT status, quote_request_id FROM email_lead_candidates')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('created', $c['status']);
        $this->assertSame((int)$qr['id'], (int)$c['quote_request_id']);
    }
}
