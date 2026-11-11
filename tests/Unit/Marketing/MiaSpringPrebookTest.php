<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Customers who reply "spring" to one of Mia's campaigns become a question for Tim
 * ("Hold them a spring spot?"), once per reply; "Booked for spring" notes it on the client.
 */
class MiaSpringPrebookTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new class extends PDO {
            public function __construct()
            {
                parent::__construct('sqlite::memory:');
                $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                @$this->sqliteCreateFunction('NOW', fn() => '2026-10-20 09:00:00', 0);
            }
            #[\ReturnTypeWillChange]
            public function prepare($sql, $o = [])
            {
                return parent::prepare(str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $sql), $o);
            }
        };
        $d = $this->db;
        $d->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, has_reviewed INT DEFAULT 0, review_request_sent_at TEXT, review_request_opted_out INT DEFAULT 0, is_active INT DEFAULT 1)");
        $d->exec("CREATE TABLE mia_questions (id INTEGER PRIMARY KEY, kind TEXT, subject_key TEXT, question TEXT, status TEXT DEFAULT 'open', answer TEXT, answered_by INT, answered_at TEXT, UNIQUE (kind, subject_key))");
        $d->exec("CREATE TABLE mia_campaigns (id INTEGER PRIMARY KEY, name TEXT, status TEXT, marketing_campaign_id INT)");
        $d->exec("CREATE TABLE campaign_sends (id INTEGER PRIMARY KEY, campaign_id INT, contact_id INT, status TEXT, sent_at TEXT)");
        $d->exec("CREATE TABLE sales_messages (id INTEGER PRIMARY KEY, contact_id INT, direction TEXT, subject TEXT, snippet TEXT, sent_at TEXT)");
        $d->exec("CREATE TABLE activity_log (id INTEGER PRIMARY KEY, user_id INT, contact_id INT, action TEXT, details TEXT)");
        $d->exec("INSERT INTO contacts (id, first_name, last_name) VALUES (7, 'Jane', 'Doe'), (8, 'Raj', 'Patel'), (9, 'Ann', 'Lee')");
        $d->exec("INSERT INTO mia_campaigns VALUES (1, 'Post-drought lawn recovery (2026)', 'approved', 50)");
        $d->exec("INSERT INTO campaign_sends VALUES (1, 50, 7, 'sent', '2026-10-07 10:00:00'), (2, 50, 8, 'sent', '2026-10-07 10:00:00')");
        $d->exec("INSERT INTO sales_messages VALUES
            (101, 7, 'inbound', 'Re: Your lawn', 'Spring please, we have two big maples.', '2026-10-08 08:00:00'),
            (102, 8, 'inbound', 'Re: Your lawn', 'Yes, can you come after the 15th?', '2026-10-08 09:00:00'),
            (103, 9, 'inbound', 'Hello', 'Spring cleanup?', '2026-10-08 09:30:00'),
            (104, 7, 'inbound', 'Earlier', 'spring', '2026-10-01 09:00:00')");
    }

    public function test_only_spring_replies_after_a_campaign_reached_them_become_questions(): void
    {
        $q = new MiaQuestionService($this->db);
        $this->assertSame(1, $q->scan(new DateTimeImmutable('2026-10-20')));
        $row = $this->db->query("SELECT kind, subject_key, question FROM mia_questions")->fetch();
        $this->assertSame('spring_prebook', $row['kind']);
        $this->assertSame('mia:spring:101:7', $row['subject_key'], 'Raj said yes to fall; Ann never got the campaign; Jane\'s Oct 1 email came before it');
        $this->assertSame('Jane Doe replied to your "Post-drought lawn recovery (2026)" email on Oct 8: "Spring please, we have two big maples.". Hold them a spring spot?', $row['question']);
        $this->assertSame(0, $q->scan(new DateTimeImmutable('2026-10-21')), 'asked once per reply');
        $this->assertSame('/crm/clients_appstack.php?action=view_contact&id=7', MiaQuestionService::link($row['subject_key']));
    }

    public function test_booked_notes_it_on_the_client_and_not_a_prebook_does_not(): void
    {
        $q = new MiaQuestionService($this->db);
        $q->scan(new DateTimeImmutable('2026-10-20'));
        $id = (int)$this->db->query("SELECT id FROM mia_questions")->fetchColumn();
        $this->assertFalse($q->answer($id, 'yes', 1)['ok'], 'only booked / not_prebook');
        $this->assertTrue($q->answer($id, 'booked', 1)['ok']);
        $log = $this->db->query("SELECT contact_id, action FROM activity_log")->fetchAll();
        $this->assertSame([['contact_id' => 7, 'action' => 'Spring pre-booking']], $log);
    }

    public function test_spring_means_the_word(): void
    {
        $this->assertTrue(MiaQuestionService::saysSpring('SPRING'));
        $this->assertTrue(MiaQuestionService::saysSpring('Let\'s do it in spring.'));
        $this->assertFalse(MiaQuestionService::saysSpring('We live on Springfield Ave'));
    }
}
