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
}
