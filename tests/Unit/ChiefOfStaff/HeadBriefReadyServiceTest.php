<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HeadBriefReadyServiceTest extends TestCase
{
    public function testState(): void
    {
        $now = strtotime('2026-10-10 09:00:00');
        $this->assertSame('stale', HeadBriefReadyService::state(null, $now));
        $this->assertSame('fresh', HeadBriefReadyService::state('2026-10-10 08:59:00', $now));
        $this->assertSame('refresh', HeadBriefReadyService::state('2026-10-10 08:45:00', $now));
        $this->assertSame('refresh', HeadBriefReadyService::state('2026-10-10 08:00:01', $now));
        $this->assertSame('stale', HeadBriefReadyService::state('2026-10-10 07:59:00', $now));
        $this->assertSame('fresh', HeadBriefReadyService::state('2026-10-10 09:00:30', $now)); // clock skew
    }

    public function testWithout(): void
    {
        $b = ['waiting' => 5, 'items' => [['key' => 'a'], ['key' => 'b'], ['key' => 'c']]];
        $r = HeadBriefReadyService::without($b, ['b']);
        $this->assertSame(['a', 'c'], array_column($r['items'], 'key'));
        $this->assertSame(4, $r['waiting']);
        $this->assertSame($b, HeadBriefReadyService::without($b, []));
        $this->assertSame($b, HeadBriefReadyService::without($b, ['zz']));
        $r = HeadBriefReadyService::without(['waiting' => 1, 'items' => [['key' => 'a'], ['key' => 'b']]], ['a']);
        $this->assertSame(1, $r['waiting']);
    }

    public function testWithoutTodayTakesActedItemsOutEverywhere(): void
    {
        $p = ['ok' => true, 'one' => ['key' => 'a'], 'rest' => [['key' => 'b'], ['key' => 'c']],
              'heads' => [['head' => 'penny', 'items' => [['key' => 'a'], ['key' => 'd']]]]];
        $r = HeadBriefReadyService::withoutToday($p, ['a']);
        $this->assertSame('b', $r['one']['key'], 'the next item moves up into "the one thing"');
        $this->assertSame(['c'], array_column($r['rest'], 'key'));
        $this->assertSame(['d'], array_column($r['heads'][0]['items'], 'key'));
        $this->assertSame($p, HeadBriefReadyService::withoutToday($p, []));
    }

    public function testTodayIsIgnoredWhenStale(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE head_brief_ready (head TEXT PRIMARY KEY, payload TEXT, built_at TEXT, build_ms INTEGER)');
        $db->exec('CREATE TABLE charlie_items (item_key TEXT, resolved_at TEXT, dismissed_at TEXT, snoozed_until TEXT)');
        $svc = new HeadBriefReadyService($db);
        $payload = json_encode(['ok' => true, 'one' => ['key' => 'a'], 'rest' => [], 'heads' => []]);
        $db->prepare('INSERT INTO head_brief_ready VALUES (?, ?, ?, 0)')->execute([HeadBriefReadyService::TODAY_KEY, $payload, '2026-10-19 09:00:00']);
        $this->assertSame('a', $svc->today(strtotime('2026-10-19 09:10:00'))['one']['key']);
        $this->assertSame('2026-10-19 09:00:00', $svc->today(strtotime('2026-10-19 09:10:00'))['saved_at']);
        $this->assertNull($svc->today(strtotime('2026-10-19 09:31:00')), 'over 30 minutes old: the live answer only');
        $db->exec("INSERT INTO charlie_items VALUES ('a', '2026-10-19 09:05:00', NULL, NULL)");
        $this->assertNull($svc->today(strtotime('2026-10-19 09:10:00'))['one'], 'resolved since the save: gone');
    }
}
