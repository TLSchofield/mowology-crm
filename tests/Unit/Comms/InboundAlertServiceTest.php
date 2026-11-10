<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/IcloudDedupePdo.php';

/** One push per client email, never for a robot, and nothing overnight. */
class InboundAlertServiceTest extends TestCase
{
    private PDO $db;
    private array $sent = [];
    private InboundAlertService $svc;
    private const NOON = '2026-11-10 12:00:00';

    protected function setUp(): void
    {
        $this->db = new IcloudDedupePdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec("CREATE TABLE sales_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, message_key TEXT UNIQUE, direction TEXT DEFAULT 'inbound', channel TEXT DEFAULT 'email', from_addr TEXT, from_name TEXT, subject TEXT, snippet TEXT, sent_at TEXT, joined_by TEXT, handled_at TEXT)");
        $this->db->exec("CREATE TABLE inbound_alerts (message_key TEXT PRIMARY KEY, alerted_at TEXT)");
        $this->db->exec("CREATE TABLE ops_settings (setting_key TEXT, setting_value TEXT)");
        $this->db->exec("INSERT INTO ops_settings VALUES ('inbox_alerts', '1')");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, is_active INT)");
        $this->db->exec("INSERT INTO users VALUES (1, 'admin', 1), (2, 'manager', 1), (3, 'admin', 0)");
        $this->svc = new InboundAlertService($this->db, function (array $to, string $body): void { $this->sent[] = [$to, $body]; });
    }

    private function mail(string $from, string $name, string $subject, array $over = []): void
    {
        static $n = 0;
        $n++;
        $r = array_merge(['message_key' => '<a' . $n . '@x.test>', 'from_addr' => $from, 'from_name' => $name, 'subject' => $subject, 'snippet' => 'Hello there', 'sent_at' => '2026-11-10 11:50:00'], $over);
        $this->db->prepare('INSERT INTO sales_messages (' . implode(',', array_keys($r)) . ') VALUES (' . implode(',', array_fill(0, count($r), '?')) . ')')->execute(array_values($r));
    }

    public function test_a_client_email_alerts_only_the_owner(): void
    {
        $this->mail('pat@x.test', 'Pat Lee', 'Amount owing');
        $this->assertSame(1, $this->svc->run(self::NOON)['alerted']);
        $this->assertSame([[[1], 'Pat Lee: Amount owing']], $this->sent);
    }

    public function test_it_alerts_once_however_often_the_polls_run(): void
    {
        $this->mail('pat@x.test', 'Pat Lee', 'Amount owing');
        $this->svc->run(self::NOON);
        $this->svc->run(self::NOON);
        $this->assertCount(1, $this->sent);
    }

    public function test_several_at_once_are_one_push(): void
    {
        $this->mail('a@x.test', 'Ann', 'One');
        $this->mail('b@x.test', 'Bo', 'Two');
        $this->mail('c@x.test', 'Cy', 'Three');
        $this->assertSame(3, $this->svc->run(self::NOON)['alerted']);
        $this->assertSame([[[1], '3 client emails: Ann, Bo and 1 more']], $this->sent);
    }

    public function test_robots_filed_handled_and_old_mail_never_alert(): void
    {
        $this->mail('noreply@x.test', '', 'Your statement');
        $this->mail('pat@x.test', 'Pat', 'Out of office', ['snippet' => 'I am currently out of the office']);
        $this->mail('kim@x.test', 'Kim', 'Filed', ['joined_by' => 'confirmed']);
        $this->mail('lee@x.test', 'Lee', 'Handled', ['handled_at' => '2026-11-10 11:55:00']);
        $this->mail('old@x.test', 'Old', 'Old', ['sent_at' => '2026-11-08 11:00:00']);
        $this->mail('out@x.test', 'Our Reply', 'Sent', ['direction' => 'outbound']);
        $this->assertSame(0, $this->svc->run(self::NOON)['alerted']);
        $this->assertSame([], $this->sent);
    }

    public function test_overnight_mail_waits_for_the_morning(): void
    {
        $this->mail('pat@x.test', 'Pat Lee', 'Late one', ['sent_at' => '2026-11-10 23:30:00']);
        $this->assertTrue($this->svc->run('2026-11-10 23:45:00')['held']);
        $this->assertSame([], $this->sent);
        $this->assertSame(1, $this->svc->run('2026-11-11 07:05:00')['alerted']);
    }

    public function test_it_does_nothing_while_switched_off(): void
    {
        $this->db->exec("UPDATE ops_settings SET setting_value = '0'");
        $this->mail('pat@x.test', 'Pat Lee', 'Amount owing');
        $this->assertSame(0, $this->svc->run(self::NOON)['alerted']);
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM inbound_alerts')->fetchColumn());
    }

    public function test_a_missing_table_never_breaks_the_poll(): void
    {
        $this->db->exec('DROP TABLE inbound_alerts');
        $this->assertSame(0, $this->svc->run(self::NOON)['alerted']);
    }

    public function test_the_text_is_one_short_line(): void
    {
        $b = InboundAlertService::body([['from_name' => "\"Pat\nLee\"", 'from_addr' => 'p@x.test', 'subject' => str_repeat('long ', 60)]]);
        $this->assertStringNotContainsString("\n", $b);
        $this->assertStringStartsWith('Pat Lee: long', $b);
        $this->assertLessThanOrEqual(140, mb_strlen($b));
    }
}
