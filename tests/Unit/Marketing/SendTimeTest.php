<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MiaHubTestDb.php';

/**
 * Send-time learning: replies and clicks teach, Apple's privacy proxy opens don't, a contact
 * without enough evidence gets their group's default, and the slot never leaves the window.
 */
class SendTimeTest extends TestCase
{
    public function test_apple_proxy_opens_are_not_believed(): void
    {
        $this->assertTrue(SendTimeService::isProxyOpen('Mozilla/5.0', '2026-09-15 19:40:00', '2026-09-15 08:00:00'), "the proxy's bare user agent");
        $this->assertTrue(SendTimeService::isProxyOpen('', '2026-09-15 19:40:00', null));
        $this->assertTrue(SendTimeService::isProxyOpen('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)', '2026-09-15 19:30:45', '2026-09-15 19:30:00'), 'within 2 minutes of the send');
        $this->assertFalse(SendTimeService::isProxyOpen('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)', '2026-09-15 21:10:00', '2026-09-15 19:30:00'));
    }

    public function test_replies_and_clicks_teach_the_hour_and_day(): void
    {
        // Tuesday evenings, consistently.
        $l = SendTimeService::learn([
            ['kind' => 'reply', 'at' => '2026-09-15 20:12:00'],  // Tue
            ['kind' => 'click', 'at' => '2026-09-22 20:40:00'],  // Tue
            ['kind' => 'open',  'at' => '2026-09-16 07:01:00', 'ua' => 'Mozilla/5.0', 'sent_at' => '2026-09-16 07:00:00'], // proxy, ignored
        ]);
        $this->assertSame(['dow' => 2, 'hour' => 20, 'weight' => 5.0, 'events' => 2], $l);
    }

    public function test_opens_alone_are_weak_and_proxy_opens_count_for_nothing(): void
    {
        $proxy = [];
        for ($i = 0; $i < 30; $i++) $proxy[] = ['kind' => 'open', 'at' => '2026-09-15 08:00:30', 'ua' => 'Mozilla/5.0', 'sent_at' => '2026-09-15 08:00:00'];
        $this->assertNull(SendTimeService::learn($proxy), '30 proxy opens teach nothing');
        $real = array_fill(0, 4, ['kind' => 'open', 'at' => '2026-09-19 09:20:00', 'ua' => 'Mozilla/5.0 (Macintosh)', 'sent_at' => '2026-09-18 19:30:00']);
        $this->assertNull(SendTimeService::learn($real), '4 real opens = 2.0 < 3.0');
        $real[] = ['kind' => 'click', 'at' => '2026-09-19 09:25:00'];
        $this->assertSame(6, SendTimeService::learn($real)['dow'], 'Saturday mornings');
    }

    public function test_default_slots_parse(): void
    {
        $this->assertSame([[2, '09:30'], [3, '09:30'], [4, '09:30']], SendTimeService::parseSlots(SendTimeService::DEFAULT_PM));
        $this->assertSame([[2, '19:30'], [3, '19:30'], [6, '09:00']], SendTimeService::parseSlots(SendTimeService::DEFAULT_HOME));
        $this->assertSame([[1, '07:05']], SendTimeService::parseSlots('1@7:05;9@10:00;junk'));
    }

    public function test_the_next_slot_stays_inside_the_window(): void
    {
        $home = SendTimeService::parseSlots(SendTimeService::DEFAULT_HOME);
        $pm = SendTimeService::parseSlots(SendTimeService::DEFAULT_PM);
        $mon = new DateTimeImmutable('2026-09-14 10:00:00'); // Monday
        // Homeowner, no learned time: Tuesday 7:30 pm.
        $this->assertSame('2026-09-15 19:30', SendTimeService::nextSlot($mon, [], $home, '2026-09-15', '2026-09-24')->format('Y-m-d H:i'));
        // PM: Tuesday 9:30.
        $this->assertSame('2026-09-15 09:30', SendTimeService::nextSlot($mon, [], $pm, '2026-09-15', '2026-09-24')->format('Y-m-d H:i'));
        // Learned Thursday 8 pm wins over the default.
        $this->assertSame('2026-09-17 20:00', SendTimeService::nextSlot($mon, [[4, '20:00']], $home, '2026-09-15', '2026-09-24')->format('Y-m-d H:i'));
        // Learned Sunday doesn't fit a Tue–Thu window: the default does.
        $this->assertSame('2026-09-15 19:30', SendTimeService::nextSlot($mon, [[7, '10:00']], $home, '2026-09-15', '2026-09-17')->format('Y-m-d H:i'));
        // Nothing fits (a Friday-only window for a PM): the window's start, never later.
        $this->assertSame('2026-09-18 00:00', SendTimeService::nextSlot($mon, [], $pm, '2026-09-18', '2026-09-18')->format('Y-m-d H:i'));
        // Already inside the window, past today's slot: next one.
        $wed = new DateTimeImmutable('2026-09-16 20:00:00');
        $this->assertSame('2026-09-19 09:00', SendTimeService::nextSlot($wed, [], $home, '2026-09-15', '2026-09-24')->format('Y-m-d H:i'));
    }

    public function test_recompute_and_schedule_from_the_database(): void
    {
        $db = new MiaHubTestDb();
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)");
        $db->exec("CREATE TABLE campaign_sends (id INTEGER PRIMARY KEY, contact_id INT, sent_at TEXT, clicked_at TEXT)");
        $db->exec("CREATE TABLE campaign_events (id INTEGER PRIMARY KEY, send_id INT, kind TEXT, at TEXT, user_agent TEXT)");
        $db->exec("CREATE TABLE sales_messages (id INTEGER PRIMARY KEY, contact_id INT, direction TEXT, sent_at TEXT)");
        $db->exec("CREATE TABLE mia_send_times (contact_id INTEGER PRIMARY KEY, best_dow INT, best_hour INT, weight REAL, events INT, computed_at TEXT)");
        $db->exec("INSERT INTO campaign_sends VALUES (1, 7, '2026-09-15 19:30:00', NULL), (2, 8, '2026-09-15 19:30:00', NULL)");
        $db->exec("INSERT INTO campaign_events VALUES (1, 1, 'click', '2026-09-17 06:45:00', 'Mozilla/5.0 (iPhone)'), (2, 2, 'open', '2026-09-15 19:30:20', 'Mozilla/5.0')");
        $db->exec("INSERT INTO sales_messages VALUES (1, 7, 'inbound', '2026-09-17 07:10:00')");
        $svc = new SendTimeService($db);
        $this->assertSame(1, $svc->recompute(new DateTimeImmutable('2026-10-06 03:00:00')), 'contact 8 only has a proxy open');
        $this->assertSame(1, $svc->coverage());
        $row = $db->query("SELECT best_dow, best_hour FROM mia_send_times WHERE contact_id = 7")->fetch();
        $this->assertSame([4, 7], [(int)$row['best_dow'], (int)$row['best_hour']], 'Thursday, 7 am');

        $db->exec("INSERT INTO ops_settings VALUES ('mia_send_default_home', '6@08:00', '')");
        $slots = $svc->schedule([7, 8, 9], [9], new DateTimeImmutable('2026-10-12 00:00:00'), '2026-10-12', '2026-10-25');
        $this->assertSame(['2026-10-15 07:00:00', '2026-10-17 08:00:00', '2026-10-13 09:30:00'], [$slots[7], $slots[8], $slots[9]],
            'learned / configured homeowner default / property manager default');
    }
}
