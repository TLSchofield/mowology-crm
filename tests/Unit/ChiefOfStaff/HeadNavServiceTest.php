<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class HeadNavServiceTest extends TestCase
{
    private function brief(int $needs = 3): array
    {
        $row = fn(string $s, int $o = 0, int $n = 0, string $r = '') => ['state' => $s, 'reason' => $r, 'open' => $o, 'needs_you' => $n];
        return ['heads' => [
            'charlie' => $row('approval', 5, $needs),
            'yui'     => $row('working', 2),
            'sam'     => $row('idle'),
            'penny'   => $row('approval', 4, 2),
            'otto'    => $row('working', 3),
            'mia'     => $row('unavailable', 0, 0, 'Not set up yet'),
            'ada'     => $row('blocked', 1, 0, 'Desk did not load'),
        ], 'items' => []];
    }

    private function today(): array
    {
        return ['say' => '  Three things need you.  ', 'status' => ['sentence' => 'Quiet day.'], 'heads' => [
            ['head' => 'yui', 'name' => 'Yui', 'role' => 'Comms', 'headline' => ' 2 replies drafted ', 'waiting' => 2, 'items' => []],
            ['head' => 'penny', 'name' => 'Penny', 'role' => 'Money', 'headline' => '4 receipts to file', 'waiting' => 4, 'items' => []],
        ]];
    }

    public function testOrderAndWords(): void
    {
        $s = HeadNavService::shape($this->brief(), $this->today());
        $this->assertSame(HeadNavService::HEADS, array_keys($s));
        $this->assertSame('3 need you', $s['charlie']['word']);
        $this->assertSame(3, $s['charlie']['needs_you']);
        $this->assertSame('Working', $s['yui']['word']);
        $this->assertSame('Idle', $s['sam']['word']);
        $this->assertSame('Needs approval', $s['penny']['word']);
        $this->assertSame('Not set up', $s['mia']['word']);
        $this->assertSame('Blocked', $s['ada']['word']);
        $this->assertSame('Desk did not load', $s['ada']['reason']);
        $this->assertSame(4, $s['penny']['open']);
    }

    public function testCharlieSingular(): void
    {
        $this->assertSame('1 needs you', HeadNavService::shape($this->brief(1), null)['charlie']['word']);
    }

    public function testLines(): void
    {
        $s = HeadNavService::shape($this->brief(), $this->today());
        $this->assertSame('Three things need you.', $s['charlie']['line']);
        $this->assertSame('2 replies drafted', $s['yui']['line']);
        $this->assertSame('', $s['otto']['line']);
        $t = $this->today();
        $t['say'] = '';
        $this->assertSame('Quiet day.', HeadNavService::shape($this->brief(), $t)['charlie']['line']);
        // The dashboard's saved copy keeps 'say' as lead / thing / after: the thing is the line.
        $t['say'] = ['lead' => 'Hey Tim, the one thing:', 'thing' => '1 overdue invoice', 'after' => 'After that, 3 more.'];
        $this->assertSame('1 overdue invoice', HeadNavService::shape($this->brief(), $t)['charlie']['line']);
    }

    public function testNothingKnownIsNeverGuessed(): void
    {
        foreach ([HeadNavService::shape(null, null), HeadNavService::shape(['items' => []], $this->today())] as $s) {
            foreach ($s as $row) {
                $this->assertSame('', $row['state']);
                $this->assertSame('', $row['word']);
            }
        }
    }

    public function testWorkspaceOnlyForCharlie(): void
    {
        foreach (HeadNavService::shape($this->brief(), $this->today()) as $slug => $row) {
            $this->assertSame($slug === 'charlie' ? '/crm/kaname_appstack.php' : null, $row['workspace']);
        }
    }
}
