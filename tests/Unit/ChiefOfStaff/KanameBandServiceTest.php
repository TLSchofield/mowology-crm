<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** KanameBandService::build — her line beside the search, with only what is already urgent landing in the band. */
class KanameBandServiceTest extends TestCase
{
    private function heads(array $over = []): array
    {
        $blank = ['state' => '', 'word' => '', 'needs_you' => 0, 'open' => 0, 'line' => '', 'reason' => '', 'workspace' => null];
        $h = [];
        foreach (['charlie', 'yui', 'sam', 'penny', 'otto', 'mia', 'ada'] as $s) $h[$s] = ($over[$s] ?? []) + $blank;
        return $h;
    }

    private function blocks(): array
    {
        $b = [];
        foreach ([['charlie', 'Kaname', '/crm/kaname_appstack.php'], ['mia', 'Mia', '/crm/marketing_appstack.php'], ['sam', 'Sam', '/crm/quotes/'],
                  ['otto', 'Otto', '/crm/jobs/schedule.php'], ['yui', 'Yui', '/crm/messages_appstack.php'], ['penny', 'Penny', '/crm/invoices/'], ['ada', 'Ada', '/crm/settings.php']] as [$s, $n, $h]) {
            $b[] = ['slug' => $s, 'name' => $n, 'items' => [['key' => $s, 'label' => 'First', 'href' => $h]]];
        }
        return $b;
    }

    public function testHerLineCountAndTheQuietFallback(): void
    {
        $b = KanameBandService::build($this->heads(['charlie' => ['state' => 'approval', 'word' => '2 need you', 'needs_you' => 2, 'line' => '21 overdue invoices']]), [], $this->blocks(), 1_000_000);
        $this->assertSame(2, $b['count']);
        $this->assertSame('21 overdue invoices', $b['line']);
        $this->assertSame('approval', $b['state']);
        $this->assertTrue($b['quiet']);

        $q = KanameBandService::build($this->heads(), [], $this->blocks(), 1_000_000);
        $this->assertSame('Nothing is waiting on you.', $q['line'], 'no state, no line: she says so');
        $this->assertSame(0, $q['count']);
    }

    public function testAlertsAreCappedMappedAndAged(): void
    {
        $now = strtotime('2026-11-10 12:00:00');
        $rows = [
            ['kind' => 'payment', 'text' => 'Autopay failed: $612 for [client]', 'created_at' => '2026-11-10 11:30:00'],
            ['kind' => 'weather', 'text' => 'Rain stop: 3 visits today', 'created_at' => '2026-11-10 09:00:00'],
            ['kind' => 'mystery', 'text' => 'Something new', 'created_at' => '2026-11-09 13:00:00'],
            ['kind' => 'books', 'text' => 'Fourth, over the cap', 'created_at' => '2026-11-09 12:30:00'],
            ['kind' => 'books', 'text' => '', 'created_at' => '2026-11-09 12:00:00'],
        ];
        $b = KanameBandService::build($this->heads(), $rows, $this->blocks(), $now);
        $this->assertCount(3, $b['alerts']);
        $this->assertSame('/crm/invoices/', $b['alerts'][0]['url']);
        $this->assertSame('30 min', $b['alerts'][0]['age']);
        $this->assertSame('3 h', $b['alerts'][1]['age']);
        $this->assertSame(KanameBandService::BRIEF_URL, $b['alerts'][2]['url'], 'an unknown kind opens the brief');
        $this->assertFalse($b['quiet']);
        $this->assertSame('alert', $b['subject']['kind'], 'one subject at a time: the newest alert leads');
        $this->assertSame('Autopay failed: $612 for [client]', $b['subject']['text']);
        $this->assertSame(2, $b['more']);
    }

    public function testWithNoAlertHerLineIsTheSubject(): void
    {
        $b = KanameBandService::build($this->heads(['charlie' => ['state' => 'approval', 'word' => '2 need you', 'needs_you' => 2, 'line' => '21 overdue invoices']]), [], $this->blocks(), 1_000_000);
        $this->assertSame(['kind' => 'line', 'text' => '21 overdue invoices', 'url' => KanameBandService::BRIEF_URL, 'age' => ''], $b['subject']);
        $this->assertSame(0, $b['more']);
    }

    public function testOnlyApprovalOrBlockedHeadsGetAChipInPipelineOrder(): void
    {
        $b = KanameBandService::build($this->heads([
            'penny' => ['state' => 'approval', 'word' => 'Needs approval'],
            'otto'  => ['state' => 'blocked', 'word' => 'Blocked'],
            'sam'   => ['state' => 'working', 'word' => 'Working'],
            'charlie' => ['state' => 'approval', 'word' => '1 needs you', 'needs_you' => 1],
        ]), [], $this->blocks(), 1_000_000);
        $this->assertSame(['otto', 'penny'], array_column($b['heads'], 'slug'), 'blocks order, Kaname herself never a chip, Working never shown');
        $this->assertSame('/crm/jobs/schedule.php', $b['heads'][0]['url']);
        $this->assertSame('Needs approval', $b['heads'][1]['word']);
    }

    public function testAge(): void
    {
        $this->assertSame('just now', KanameBandService::age(30));
        $this->assertSame('5 min', KanameBandService::age(300));
        $this->assertSame('yesterday', KanameBandService::age(90000));
    }
}
