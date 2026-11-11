<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Where Charlie's items come from: every head through the shared contract, Penny through a
 * read-only adapter, and the unowned Work Queue as head 'house'. A missing or broken head
 * is absent, never fatal.
 */
class CharlieBriefSourcesTest extends TestCase
{
    private function pdo(): PDO
    {
        return new PDO('sqlite::memory:');
    }

    public function test_a_head_that_throws_is_reported_and_the_rest_still_brief(): void
    {
        $svc = new CharlieBriefService($this->pdo(), [
            'penny' => fn() => ['head' => 'penny', 'headline' => 'Books', 'items' => [['key' => 'penny:receipts_ready', 'text' => '3 receipts', 'priority' => 2]], 'count' => 1],
            'sam'   => function () { throw new RuntimeException('table missing'); },
            'otto'  => fn() => 'not an array',
            'mia'   => fn() => ['head' => 'mia', 'headline' => 'Reconnect', 'items' => [
                ['key' => 'mia:reconnect:4', 'text' => 'Call the Smiths', 'kind' => 'reconnect', 'value' => '1200', 'since' => '2026-09-01', 'url' => '/crm/clients.php?id=4'],
                ['key' => 'penny:receipts_ready', 'text' => 'duplicate key from another head'],
                ['text' => ''],
                'junk',
            ], 'count' => 9],
        ]);
        $c = $svc->collect('Tim');
        $this->assertSame(['penny', 'mia'], $c['ok']);
        $this->assertSame(['sam', 'otto'], array_keys($c['failed']));
        $this->assertSame(['penny:receipts_ready', 'mia:reconnect:4'], array_column($c['items'], 'key'));

        $mia = $c['heads']['mia'];
        $this->assertSame('Mia', $mia['name']);
        $this->assertSame(9, $mia['count'], 'a head that sent only its top items keeps its real count');
        $this->assertSame('mia:reconnect', $mia['items'][0]['kind']);
        $this->assertSame(1200.0, $mia['items'][0]['value']);
        $this->assertSame('2026-09-01', $mia['items'][0]['since']);
    }

    public function test_the_owner_first_name_is_passed_to_every_head(): void
    {
        $seen = [];
        $svc = new CharlieBriefService($this->pdo(), ['sam' => function (string $n) use (&$seen) { $seen[] = $n; return ['items' => []]; }]);
        $svc->collect('Tim');
        $this->assertSame(['Tim'], $seen);
    }

    public function test_penny_brief_from_her_numbers_and_questions(): void
    {
        $b = PennyBriefAdapter::fromStats(['ready' => 12, 'drafts' => 4, 'gst_stuck' => 310.4], [
            ['id' => 7, 'amount' => 250.0, 'question' => "Hey Tim — you picked up mulch (\$250.00) at Lawnboy on Oct 1 for the job at 12 Oak St, and I can't find it on any invoice."],
            ['id' => 8, 'amount' => 40.0, 'question' => 'Hey — you spent $40.00 at a supplier.'],
        ]);
        $this->assertSame('penny', $b['head']);
        $keys = array_column($b['items'], 'key');
        $this->assertSame(['penny:receipts_ready', 'penny:gst_stuck', 'penny:question:7', 'penny:question:8'], $keys);
        $this->assertSame(1, $b['items'][0]['priority'], 'a big backlog is priority 1');
        $this->assertSame(1, $b['items'][2]['priority'], 'a big unbilled question is priority 1');
        $this->assertSame(2, $b['items'][3]['priority']);
        $this->assertStringStartsWith('You picked up mulch', $b['items'][2]['text'], "Charlie greets, not Penny");
        $this->assertStringContainsString("Penny can't find it on any invoice", $b['items'][2]['text']);
        $this->assertSame('12 receipts ready, 2 billing questions', $b['headline']);
    }

    public function test_penny_drafts_show_only_when_nothing_is_ready_and_small_gst_is_left_out(): void
    {
        $b = PennyBriefAdapter::fromStats(['ready' => 0, 'drafts' => 1, 'gst_stuck' => 12.0], []);
        $this->assertSame(['penny:drafts'], array_column($b['items'], 'key'));
        $this->assertSame('1 draft receipt waiting in the backlog', $b['items'][0]['text']);
        $this->assertSame(['head' => 'penny', 'headline' => 'The books are up to date', 'items' => [], 'count' => 0], PennyBriefAdapter::fromStats(null, []));
    }

    public function test_house_takes_only_the_critical_lane_with_keys_that_survive_a_changing_count(): void
    {
        $rows = [
            ['category' => 'critical', 'title' => '3 Overdue Invoices', 'description' => '$4,210.50 outstanding past due date', 'link' => 'invoices/index.php?status=overdue', 'priority' => 1],
            ['category' => 'critical', 'title' => '1 Stuck Visit', 'description' => 'Past-date visits still marked scheduled or in progress', 'link' => 'schedule_appstack.php', 'priority' => 3],
            ['category' => 'data_quality', 'title' => '9 Contacts Missing Email', 'description' => '', 'link' => 'x.php', 'priority' => 2],
        ];
        $b = HouseBriefAdapter::fromWorkQueue($rows);
        $this->assertSame(['house:overdue_invoice', 'house:stuck_visit'], array_column($b['items'], 'key'));
        $this->assertSame('3 overdue invoices — $4,210.50 outstanding past due date', $b['items'][0]['text']);
        $this->assertSame(4210.5, $b['items'][0]['value']);
        $this->assertSame('/crm/invoices/index.php?status=overdue', $b['items'][0]['url']);
        $this->assertNull($b['items'][1]['value']);

        $rows[0]['title'] = '1 Overdue Invoice';
        $this->assertSame('house:overdue_invoice', HouseBriefAdapter::fromWorkQueue($rows)['items'][0]['key']);
    }

    public function testHouseBriefIsServedFromACopyWhenFresh(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        // The slow work-queue sweep must not run on every card load: a copy under CACHE_SECONDS old is served as is.
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec("CREATE TABLE head_brief_ready (head TEXT PRIMARY KEY, payload TEXT, built_at TEXT, build_ms INT)");
        $db->exec("CREATE TABLE charlie_items (item_key TEXT, resolved_at TEXT, dismissed_at TEXT, snoozed_until TEXT)");
        $kept = ['head' => 'house', 'headline' => '1 thing nobody else is watching', 'count' => 1, 'items' => [['key' => 'house:overdue_invoice', 'kind' => 'house:overdue_invoice', 'text' => 'x', 'priority' => 1]]];
        $db->prepare("INSERT INTO head_brief_ready VALUES ('house', ?, ?, 6000)")->execute([json_encode($kept), date('Y-m-d H:i:s', time() - 60)]);
        $sweeps = 0;
        $sweep = function () use (&$sweeps) { $sweeps++; return []; };

        $b = (new HouseBriefAdapter($db, $sweep))->brief();
        $this->assertSame('house:overdue_invoice', $b['items'][0]['key']);
        $this->assertSame(0, $sweeps, 'a fresh copy means the slow sweep does not run');

        // An old copy is rebuilt.
        $db->exec("UPDATE head_brief_ready SET built_at = '" . date('Y-m-d H:i:s', time() - 3600) . "'");
        $b2 = (new HouseBriefAdapter($db, $sweep))->brief();
        $this->assertSame(1, $sweeps);
        $this->assertSame([], $b2['items']);
        // (Storing the rebuilt copy uses MySQL's ON DUPLICATE KEY, so that half is covered by the live check, not this SQLite test.)
    }

    public function testHouseBriefStillWorksWithoutTheCacheTable(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $b = (new HouseBriefAdapter($db, fn() => []))->brief();
        $this->assertSame('house', $b['head']);
    }
}
