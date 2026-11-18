<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Sam's "<customer> just opened <quote>" push: who it's for, when it may fire, the text,
 * one push per quote, and 410 tokens retired. APNs is a fake sender here.
 */
class QuoteViewNotifierTest extends TestCase
{
    private const SENT = '2026-10-07 09:00:00';

    private array $sent = [];

    private function quote(array $extra = []): array
    {
        return $extra + ['status' => 'sent', 'sent_at' => self::SENT, 'viewed_at' => null];
    }

    private function at(string $when): int
    {
        return strtotime($when);
    }

    // ── decide() ────────────────────────────────────────────────────────────

    public function test_first_customer_view_after_the_window_pushes(): void
    {
        $d = QuoteViewNotifier::decide($this->quote(), false, $this->at('2026-10-07 14:00:00'));
        $this->assertTrue($d['notify']);
        $this->assertSame('first_view', $d['reason']);
    }

    public function test_a_logged_in_staff_viewer_never_pushes(): void
    {
        $d = QuoteViewNotifier::decide($this->quote(), true, $this->at('2026-10-08 10:00:00'));
        $this->assertFalse($d['notify']);
        $this->assertSame('staff_viewer', $d['reason']);
    }

    public function test_within_five_minutes_of_sending_is_tim_checking_the_link(): void
    {
        $d = QuoteViewNotifier::decide($this->quote(), false, $this->at('2026-10-07 09:04:59'));
        $this->assertFalse($d['notify']);
        $this->assertSame('self_check_window', $d['reason']);
        $this->assertTrue(QuoteViewNotifier::decide($this->quote(), false, $this->at('2026-10-07 09:05:00'))['notify']);
    }

    public function test_a_reopen_never_pushes(): void
    {
        $d = QuoteViewNotifier::decide($this->quote(['viewed_at' => '2026-10-07 11:00:00']), false, $this->at('2026-10-08 08:00:00'));
        $this->assertFalse($d['notify']);
        $this->assertSame('already_viewed', $d['reason']);
    }

    public function test_an_earlier_view_inside_the_self_check_window_does_not_use_up_the_push(): void
    {
        $d = QuoteViewNotifier::decide($this->quote(['viewed_at' => '2026-10-07 09:02:00']), false, $this->at('2026-10-07 18:00:00'));
        $this->assertTrue($d['notify']);
    }

    public function test_a_view_from_before_a_resend_counts_as_already_viewed(): void
    {
        $d = QuoteViewNotifier::decide($this->quote(['viewed_at' => '2026-10-01 10:00:00']), false, $this->at('2026-10-08 08:00:00'));
        $this->assertFalse($d['notify']);
    }

    public function test_only_quotes_out_with_the_customer(): void
    {
        foreach (['draft', 'accepted', 'declined', 'expired', ''] as $status) {
            $d = QuoteViewNotifier::decide($this->quote(['status' => $status]), false, $this->at('2026-10-08 08:00:00'));
            $this->assertFalse($d['notify'], $status);
            $this->assertSame('status', $d['reason']);
        }
        $this->assertTrue(QuoteViewNotifier::decide($this->quote(['status' => 'viewed']), false, $this->at('2026-10-08 08:00:00'))['notify']);
        $this->assertSame('not_sent', QuoteViewNotifier::decide($this->quote(['sent_at' => null]), false, time())['reason']);
    }

    // ── message() ───────────────────────────────────────────────────────────

    public function test_message_names_the_customer_quote_and_total(): void
    {
        $m = QuoteViewNotifier::message(['id' => 99, 'first_name' => 'Linda', 'last_name' => 'Nimmerrichter',
                                         'company_name' => 'Strata 2106', 'quote_number' => 'QUO-2026-0073', 'amount' => '1758.75']);
        $this->assertSame('Sam [Sales]', $m['title']);
        $this->assertSame('Linda Nimmerrichter just opened QUO-2026-0073 ($1,758.75)', $m['body']);
        $this->assertSame(['open' => 'team', 'head' => 'sam', 'quote_id' => 99], $m['data']);
    }

    public function test_message_falls_back_to_the_company_and_leaves_out_a_zero_total(): void
    {
        $m = QuoteViewNotifier::message(['id' => 5, 'first_name' => null, 'last_name' => '', 'company_name' => 'Peacock Holdings',
                                         'quote_number' => 'QUO-2026-0001', 'amount' => 0]);
        $this->assertSame('Peacock Holdings just opened QUO-2026-0001', $m['body']);
        $m = QuoteViewNotifier::message(['id' => 6]);
        $this->assertSame('A customer just opened their quote', $m['body']);
    }

    // ── notifyFirstView() / testPush() against a fake APNs ──────────────────

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, is_active INTEGER)");
        $db->exec("CREATE TABLE device_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, device_token TEXT, platform TEXT, is_active INTEGER)");
        $db->exec("CREATE TABLE activity_log (id INTEGER PRIMARY KEY AUTOINCREMENT, quote_id INTEGER, action TEXT, details TEXT, ip_address TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT)");
        $db->exec("CREATE TABLE companies (id INTEGER PRIMARY KEY, company_name TEXT, primary_contact_id INTEGER)");
        $db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, site_contact_id INTEGER)");
        $db->exec("CREATE TABLE quotes (id INTEGER PRIMARY KEY, quote_number TEXT, title TEXT, total_amount REAL, amount REAL,
                   contact_id INTEGER, property_id INTEGER, company_id INTEGER)");
        $db->exec("INSERT INTO users VALUES (1, 'admin', 1), (2, 'crew', 1), (3, 'admin', 0)");
        $db->exec("INSERT INTO device_tokens (user_id, device_token, platform, is_active) VALUES
                   (1, 'aaaaaaaaTIMPHONE', 'ios', 1), (1, 'bbbbbbbbOLDPHONE', 'ios', 1), (1, 'cccccccc', 'ios', 0),
                   (1, 'droid', 'android', 1), (2, 'crewphone', 'ios', 1), (3, 'gonephone', 'ios', 1)");
        $db->exec("INSERT INTO contacts VALUES (10, 'Linda', 'Nimmerrichter'), (11, 'Jodi', 'Accountant'), (12, 'Site', 'Person')");
        $db->exec("INSERT INTO companies VALUES (20, 'Strata 2106', 11)");
        $db->exec("INSERT INTO properties VALUES (30, 12)");
        $db->exec("INSERT INTO quotes VALUES (99, 'QUO-2026-0073', 'Fall cleanup', 1758.75, 1500, 10, 30, 20),
                                             (100, 'QUO-2026-0074', 'Hedges', 0, 420, NULL, 30, 20),
                                             (101, 'QUO-2026-0075', 'ZZTEST quote', 0, 10, 10, 30, 20)");
        return $db;
    }

    private function notifier(PDO $db, bool $configured = true): QuoteViewNotifier
    {
        $this->sent = [];
        return new QuoteViewNotifier($db, function (string $token, string $title, string $body, array $data): array {
            $this->sent[] = compact('token', 'title', 'body', 'data');
            if (strpos($token, 'OLDPHONE') !== false) {
                return ['success' => false, 'http_code' => 410, 'error' => 'Unregistered', 'unregistered' => true];
            }
            return ['success' => true, 'http_code' => 200, 'error' => null, 'unregistered' => false];
        }, fn() => $configured);
    }

    public function test_first_view_pushes_to_the_owners_active_iphones_once(): void
    {
        $db = $this->db();
        $n = $this->notifier($db);
        $r = $n->notifyFirstView(99);

        $this->assertSame(['sent' => 1, 'tokens' => 2, 'skipped' => null], $r);
        $this->assertSame(['aaaaaaaaTIMPHONE', 'bbbbbbbbOLDPHONE'], array_column($this->sent, 'token'));
        $this->assertSame('Linda Nimmerrichter just opened QUO-2026-0073 ($1,758.75)', $this->sent[0]['body']);
        // the 410 token is retired
        $this->assertSame(0, (int)$db->query("SELECT is_active FROM device_tokens WHERE device_token = 'bbbbbbbbOLDPHONE'")->fetchColumn());
        $log = $db->query("SELECT details FROM activity_log WHERE quote_id = 99 AND action = '" . QuoteViewNotifier::ACTION . "'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(1, $log);
        $this->assertStringContainsString('pushed to 1 of 2 devices', $log[0]);

        // never twice for the same quote
        $again = $n->notifyFirstView(99);
        $this->assertSame('already_notified', $again['skipped']);
        $this->assertCount(2, $this->sent);
    }

    public function test_customer_falls_back_to_the_site_contact_and_amount_to_amount(): void
    {
        $n = $this->notifier($this->db());
        $n->notifyFirstView(100);
        $this->assertSame('Site Person just opened QUO-2026-0074 ($420.00)', $this->sent[0]['body']);
    }

    public function test_test_records_and_unconfigured_apns_send_nothing(): void
    {
        $db = $this->db();
        $this->assertSame('test_record', $this->notifier($db)->notifyFirstView(101)['skipped']);
        $this->assertSame([], $this->sent);

        $r = $this->notifier($db, false)->notifyFirstView(99);
        $this->assertSame('apns_not_configured', $r['skipped']);
        $this->assertSame([], $this->sent);
        $this->assertTrue($this->notifier($db)->alreadyNotified(99), 'recorded so a later view never sends it late');
    }

    public function test_test_push_diagnoses_config_tokens_and_apple_answer(): void
    {
        $db = $this->db();
        $r = $this->notifier($db, false)->testPush(1);
        $this->assertFalse($r['ok']);
        $this->assertFalse($r['apns_configured']);
        $this->assertSame(2, $r['tokens']);
        $this->assertSame([], $this->sent);

        $r = $this->notifier($db)->testPush(1);
        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['tokens']);
        $this->assertSame([true, false], array_column($r['results'], 'success'));
        $this->assertSame([false, true], array_column($r['results'], 'deactivated'));
        $this->assertSame('aaaaaaaa…', $r['results'][0]['token']);
        $this->assertStringContainsString('1 of 2', $r['message']);

        $none = $this->notifier($db)->testPush(4);
        $this->assertFalse($none['ok']);
        $this->assertSame(0, $none['tokens']);
        $this->assertStringContainsString('No active iPhone', $none['message']);
    }
}
